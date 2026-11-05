<?php

declare(strict_types=1);

namespace Journal;

/**
 * Whether a journal file may be saved: its frontmatter is intact and its
 * date agrees with its path.
 *
 * mg runs this on Save and Lemur 13 runs it again before writing, from the
 * same file, so the two can never disagree. It understands only the YAML the
 * journal actually uses: `key: value` lines whose values are quoted or bare
 * scalars, or one-line `[ ... ]` lists. Anything else is refused as not
 * parsing, which is the safe direction for a file about to overwrite one on
 * Lemur 13.
 *
 * Pure: no files, no DB. Runs on Lemur 13 under PHP 8.1.
 */
final class EntryCheck
{
    private const KEY = '/^([A-Za-z_][A-Za-z0-9_-]*):(.*)$/';
    private const DATE_TIME = '/^(\d{4}-\d{2}-\d{2})T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';
    private const DATE_ONLY = '/^(\d{4}-\d{2}-\d{2})$/';

    /**
     * Older entries may already lack these. A save keeps such a file as it
     * was rather than refusing it; only breakage the edit introduces is
     * refused.
     */
    private const TOLERATED_IF_ALREADY = ['date_without_time', 'tags_missing'];

    /**
     * Problems that refuse the save, as messages for Rob. Empty means it may
     * be saved.
     *
     * @param string      $content The whole file as it would be written.
     * @param string      $path    Where it would be written.
     * @param string|null $base    The file as last uploaded, when there is
     *                             one: problems it already had are tolerated
     *                             (see TOLERATED_IF_ALREADY).
     * @return list<string>
     */
    public static function problems(string $content, string $path, ?string $base = null): array
    {
        $found = self::coded($content, $path);
        if ($base !== null) {
            $before = self::coded($base, $path);
            foreach (self::TOLERATED_IF_ALREADY as $code) {
                if (isset($found[$code], $before[$code])) {
                    unset($found[$code]);
                }
            }
        }
        return array_values($found);
    }

    /**
     * Problems that do not refuse the save but deserve a look.
     *
     * @return list<string>
     */
    public static function warnings(string $new_path, string $old_path): array
    {
        $new = EntryPath::parse($new_path);
        $old = EntryPath::parse($old_path);
        if ($new !== null && $old !== null && $old->isDream() && !$new->isDream()) {
            return ['The new filename has no "dream" in it, so dreams.robnugen.com will not see it as a dream.'];
        }
        return [];
    }

    /** Line endings as the journal stores them: a browser posts CRLF. */
    public static function normalize(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    /**
     * The frontmatter as key => raw value, or null when it is missing or
     * does not parse.
     *
     * @return array<string, string|list<string>>|null
     */
    public static function frontmatter(string $content): ?array
    {
        $lines = explode("\n", self::normalize($content));
        if ($lines[0] !== '---') {
            return null;
        }
        $fields = [];
        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            $line = rtrim($lines[$i]);
            if ($line === '---') {
                return $fields;
            }
            if ($line === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (preg_match(self::KEY, $line, $m) !== 1 || array_key_exists($m[1], $fields)) {
                return null;
            }
            $value = self::value(trim($m[2]));
            if ($value === null) {
                return null;
            }
            $fields[$m[1]] = $value;
        }
        return null;
    }

    /**
     * @return array<string, string> code => message
     */
    private static function coded(string $content, string $path): array
    {
        $content = self::normalize($content);
        $lines = explode("\n", $content);
        if ($lines[0] !== '---') {
            return ['no_start' => 'The file must start with a line that is just ---.'];
        }
        if (!in_array('---', array_map('rtrim', array_slice($lines, 1)), true)) {
            return ['no_end' => 'The frontmatter needs a closing line that is just ---.'];
        }
        $fields = self::frontmatter($content);
        if ($fields === null) {
            return ['unparsed' => 'The frontmatter does not parse. Each line must be key: value, '
                . 'with quotes and [ ] lists closed, and no key twice.'];
        }

        $problems = [];
        $title = $fields['title'] ?? null;
        if (!is_string($title) || $title === '') {
            $problems['title_missing'] = 'The frontmatter needs a title.';
        }
        if (!isset($fields['tags'])) {
            $problems['tags_missing'] = 'The frontmatter needs tags.';
        } elseif (!is_array($fields['tags'])) {
            $problems['tags_not_list'] = 'tags must be a [ ] list.';
        }

        $date = $fields['date'] ?? null;
        $day = null;
        if (!is_string($date) || $date === '') {
            $problems['date_missing'] = 'The frontmatter needs a date.';
        } elseif (preg_match(self::DATE_TIME, $date, $m) === 1) {
            $day = $m[1];
        } elseif (preg_match(self::DATE_ONLY, $date, $m) === 1) {
            $day = $m[1];
            $problems['date_without_time'] = 'date must look like 2026-11-05T05:44:00+11:00.';
        } else {
            $problems['date_shape'] = 'date must look like 2026-11-05T05:44:00+11:00.';
        }

        $entry = EntryPath::parse($path);
        if ($entry === null) {
            $problems['path_shape'] = 'The filename must look like YYYY/MM/DDslug.md, with a real date.';
        } elseif ($day !== null && $day !== $entry->date) {
            $problems['date_mismatch'] = "The frontmatter date ($day) must match the date in the filename "
                . "({$entry->date}).";
        }
        return $problems;
    }

    /**
     * One value: a quoted string, a one-line [ ] list, or a bare scalar.
     * Null when a quote or bracket is left open.
     *
     * @return string|list<string>|null
     */
    private static function value(string $raw): string|array|null
    {
        if ($raw === '') {
            return '';
        }
        if ($raw[0] === '[') {
            if (substr($raw, -1) !== ']') {
                return null;
            }
            return self::items(trim(substr($raw, 1, -1)));
        }
        if ($raw[0] === '"' || $raw[0] === "'") {
            [$text, $rest] = self::quoted($raw) ?? [null, null];
            if ($text === null || trim((string) $rest) !== '') {
                return null;
            }
            return $text;
        }
        return $raw;
    }

    /**
     * @return list<string>|null
     */
    private static function items(string $inner): ?array
    {
        $items = [];
        while ($inner !== '') {
            if ($inner[0] === '"' || $inner[0] === "'") {
                $q = self::quoted($inner);
                if ($q === null) {
                    return null;
                }
                [$item, $inner] = $q;
            } else {
                $comma = strpos($inner, ',');
                $item = trim($comma === false ? $inner : substr($inner, 0, $comma));
                $inner = $comma === false ? '' : substr($inner, $comma);
                if ($item === '' || strpbrk($item, '[]"\'') !== false) {
                    return null;
                }
            }
            $items[] = $item;
            $inner = ltrim($inner);
            if ($inner === '') {
                break;
            }
            if ($inner[0] !== ',') {
                return null;
            }
            $inner = ltrim(substr($inner, 1));
            if ($inner === '') {
                return null;
            }
        }
        return $items;
    }

    /**
     * A quoted string at the start of $s, and what follows it.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function quoted(string $s): ?array
    {
        $quote = $s[0];
        $text = '';
        for ($i = 1, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($quote === '"' && $c === '\\' && $i + 1 < $n) {
                $text .= $s[++$i];
                continue;
            }
            if ($c === $quote) {
                if ($quote === "'" && ($s[$i + 1] ?? '') === "'") {
                    $text .= "'";
                    $i++;
                    continue;
                }
                return [$text, substr($s, $i + 1)];
            }
            $text .= $c;
        }
        return null;
    }
}
