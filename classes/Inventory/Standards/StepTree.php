<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * The step files under tools/site-standards/steps/, read into a tree:
 *
 *     <standard>/_standard.md          frontmatter: name
 *     <standard>/<track>/_track.md     frontmatter: name, position
 *     <standard>/<track>/<step>.md     frontmatter: title, position, status,
 *                                      whose_move, check, param (optional)
 *
 * Frontmatter is a leading block between two "---" lines of flat
 * "key: value" pairs. In a step file the text up to a line reading
 * "## How to do it" is the explanation shown on the page, and the rest is the
 * how-to an agent follows.
 *
 * Anything else is refused, so a typo in a file cannot quietly change what
 * the page says about a site.
 */
final readonly class StepTree
{
    private const string HOW_TO_HEADING = '## How to do it';

    /**
     * @param list<StandardDef> $standards by slug
     */
    private function __construct(public array $standards)
    {
    }

    /**
     * @param array<string, string> $files path relative to the steps directory => contents
     */
    public static function fromFiles(array $files): self
    {
        $standardFiles = [];
        $trackFiles = [];
        $stepFiles = [];
        foreach ($files as $path => $content) {
            $parts = explode('/', (string) $path);
            $file = array_pop($parts);
            if (!str_ends_with($file, '.md') || $parts === [] || count($parts) > 2) {
                throw new StandardsFormatException("{$path}: not where a step file belongs");
            }
            foreach ($parts as $dir) {
                if (!Names::isStepName($dir)) {
                    throw new StandardsFormatException("{$path}: \"{$dir}\" is not a valid directory name");
                }
            }
            $name = substr($file, 0, -3);
            $standard = $parts[0];
            $track = $parts[1] ?? null;
            if ($track === null) {
                if ($name !== '_standard') {
                    throw new StandardsFormatException("{$path}: not where a step file belongs");
                }
                $standardFiles[$standard] = $content;
            } elseif ($name === '_track') {
                $trackFiles[$standard][$track] = $content;
            } elseif (Names::isStepName($name)) {
                $stepFiles[$standard][$track][$name] = (string) $path;
            } else {
                throw new StandardsFormatException("{$path}: \"{$name}\" is not a valid step name");
            }
        }

        $standards = [];
        $seen = array_unique(array_map(
            strval(...),
            [...array_keys($standardFiles), ...array_keys($trackFiles), ...array_keys($stepFiles)],
        ));
        sort($seen);
        foreach ($seen as $standard) {
            $standardPath = "{$standard}/_standard.md";
            if (!isset($standardFiles[$standard])) {
                throw new StandardsFormatException("{$standardPath} is missing");
            }
            $fields = self::frontmatter($standardPath, $standardFiles[$standard], ['name'], [])['fields'];

            $trackSlugs = array_unique(array_map(
                strval(...),
                [...array_keys($trackFiles[$standard] ?? []), ...array_keys($stepFiles[$standard] ?? [])],
            ));
            $tracks = [];
            $stepNames = [];
            foreach ($trackSlugs as $track) {
                $trackPath = "{$standard}/{$track}/_track.md";
                if (!isset($trackFiles[$standard][$track])) {
                    throw new StandardsFormatException("{$trackPath} is missing");
                }
                $trackFields = self::frontmatter(
                    $trackPath,
                    $trackFiles[$standard][$track],
                    ['name', 'position'],
                    [],
                )['fields'];

                $steps = [];
                foreach ($stepFiles[$standard][$track] ?? [] as $name => $path) {
                    $name = (string) $name;
                    if (isset($stepNames[$name])) {
                        throw new StandardsFormatException(
                            "{$path}: the step name \"{$name}\" is already used by {$stepNames[$name]}"
                        );
                    }
                    $stepNames[$name] = $path;
                    $steps[] = self::step($name, $path, $files[$path]);
                }
                usort($steps, static fn (StepDef $a, StepDef $b): int => [$a->position, $a->slug] <=> [$b->position, $b->slug]);
                $tracks[] = new TrackDef(
                    $track,
                    $trackFields['name'],
                    self::position($trackPath, $trackFields['position']),
                    $steps,
                );
            }
            usort($tracks, static fn (TrackDef $a, TrackDef $b): int => [$a->position, $a->slug] <=> [$b->position, $b->slug]);
            $standards[] = new StandardDef($standard, $fields['name'], $tracks);
        }

        return new self($standards);
    }

    private static function step(string $name, string $path, string $content): StepDef
    {
        $parsed = self::frontmatter(
            $path,
            $content,
            ['title', 'position', 'status', 'whose_move', 'check'],
            ['param'],
        );
        $fields = $parsed['fields'];

        $status = StepStatus::tryFrom($fields['status'])
            ?? throw new StandardsFormatException("{$path}: status must be idea or proven");
        $whoseMove = WhoseMove::tryFrom($fields['whose_move'])
            ?? throw new StandardsFormatException("{$path}: whose_move must be rob or agent");

        $lines = explode("\n", $parsed['body']);
        $heading = array_search(self::HOW_TO_HEADING, array_map(rtrim(...), $lines), true);
        $explanation = $heading === false ? $lines : array_slice($lines, 0, (int) $heading);
        $howTo = $heading === false ? [] : array_slice($lines, (int) $heading + 1);

        return new StepDef(
            $name,
            self::position($path, $fields['position']),
            $fields['title'],
            trim(implode("\n", $explanation)),
            trim(implode("\n", $howTo)),
            $status,
            $whoseMove,
            $fields['check'],
            $fields['param'] ?? null,
        );
    }

    /**
     * A position is a number from 1 to 65535 with up to four decimal places,
     * so a new step can go between two others (39.0001 comes after 39 and
     * before 40) without renumbering any file.
     */
    private static function position(string $path, string $value): float
    {
        if (preg_match('/^\d+(\.\d{1,4})?$/', $value) !== 1 || (float) $value < 1 || (float) $value >= 65536) {
            throw new StandardsFormatException(
                "{$path}: position must be a number from 1 to 65535, with at most four decimal places"
            );
        }
        return (float) $value;
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     * @return array{fields: array<string, string>, body: string}
     */
    private static function frontmatter(string $path, string $content, array $required, array $optional): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        if (rtrim($lines[0]) !== '---') {
            throw new StandardsFormatException("{$path}: must start with a frontmatter block");
        }
        $fields = [];
        $end = null;
        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            $line = rtrim($lines[$i]);
            if ($line === '---') {
                $end = $i;
                break;
            }
            if ($line === '') {
                continue;
            }
            if (preg_match('/\A([a-z_]+):\s*(.*)\z/', $line, $m) !== 1) {
                throw new StandardsFormatException("{$path}: cannot read the frontmatter line \"{$line}\"");
            }
            [, $key, $value] = $m;
            if (!in_array($key, $required, true) && !in_array($key, $optional, true)) {
                throw new StandardsFormatException("{$path}: unknown frontmatter key \"{$key}\"");
            }
            if (isset($fields[$key])) {
                throw new StandardsFormatException("{$path}: frontmatter key \"{$key}\" appears twice");
            }
            if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            }
            if ($value === '') {
                throw new StandardsFormatException("{$path}: frontmatter key \"{$key}\" is empty");
            }
            $fields[$key] = $value;
        }
        if ($end === null) {
            throw new StandardsFormatException("{$path}: the frontmatter block is never closed");
        }
        foreach ($required as $key) {
            if (!isset($fields[$key])) {
                throw new StandardsFormatException("{$path}: frontmatter key \"{$key}\" is missing");
            }
        }

        return ['fields' => $fields, 'body' => implode("\n", array_slice($lines, $end + 1))];
    }
}
