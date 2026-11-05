<?php

declare(strict_types=1);

namespace Journal;

/**
 * One sync run on Lemur 13: write Rob's edits from mg into the journal,
 * then upload what is recent and remove what has settled.
 *
 * Edits come first, so each one is judged against the copy it was made from
 * before an upload moves that copy forward.
 *
 * Runs on Lemur 13 (PHP 8.1, no curl extension), from tools/journal-sync/.
 * mg itself never runs it. The API and git are passed in, so every branch is
 * unit-tested against a temp folder with no network and no git.
 */
final class Sync
{
    public const SUFFIX_FROM_MG = '.from-mg';

    /** @var list<string> */
    private array $log = [];

    /**
     * @param string   $dir        The journal repo root.
     * @param \Closure $api        fn(string $method, string $route, ?array $body): array — throws on failure.
     * @param \Closure $gitStatus  fn(): array<string, string> — path => 'untracked'|'modified', for
     *                             entry files with uncommitted changes (deleted files left out).
     * @param \Closure $isTracked  fn(string $path): bool — whether git has ever tracked the path.
     * @param string   $today      YYYY-MM-DD, in the laptop's zone.
     */
    public function __construct(
        private string $dir,
        private \Closure $api,
        private \Closure $gitStatus,
        private \Closure $isTracked,
        private string $today,
    ) {
    }

    /** @return list<string> what the run did, one line each */
    public function run(): array
    {
        $this->applyEdits();
        $this->mirror();
        $this->call('POST', '/journal-sync/done', []);
        return $this->log;
    }

    private function applyEdits(): void
    {
        $edits = $this->call('GET', '/journal-sync/edits', null)['edits'] ?? [];
        foreach (is_array($edits) ? $edits : [] as $edit) {
            if (is_array($edit)) {
                $this->applyEdit($edit);
            }
        }
    }

    /**
     * @param array<mixed> $edit
     */
    private function applyEdit(array $edit): void
    {
        $path = self::str($edit, 'path');
        $target = self::str($edit, 'edit_path') !== '' ? self::str($edit, 'edit_path') : $path;
        $edited_at = self::str($edit, 'edited_at');
        $file = $this->file($path);
        $beside = $file . self::SUFFIX_FROM_MG;

        if (EntryPath::parse($path) === null || EntryPath::parse($target) === null) {
            $this->report($path, 'refused', $edited_at, 'Lemur 13 does not take that path.');
            return;
        }

        if (self::str($edit, 'status') === Files::STATUS_CONFLICT) {
            if (!file_exists($beside)) {
                $this->report($path, 'resolved', $edited_at, '');
                $this->log[] = "resolved $path";
            }
            return;
        }

        $content = base64_decode(self::str($edit, 'content'), true);
        if ($content === false) {
            $this->report($path, 'refused', $edited_at, 'The edit did not arrive intact.');
            return;
        }

        $current = is_file($file) ? (string) file_get_contents($file) : null;
        $problems = EntryCheck::problems($content, $target, $current);
        if ($problems !== []) {
            $this->report($path, 'refused', $edited_at, implode(' ', $problems));
            $this->log[] = "refused $path: " . implode(' ', $problems);
            return;
        }

        if ($current === null || hash('sha256', $current) !== self::str($edit, 'base_hash')) {
            $this->write($beside, $content);
            $why = $current === null ? 'was gone' : 'changed after you edited it';
            $this->report(
                $path,
                'conflict',
                $edited_at,
                "Lemur 13's copy $why. Your edit is saved beside it as " . basename($beside)
                . '; merge them on Lemur 13.'
            );
            $this->log[] = "conflict $path";
            return;
        }

        if ($target !== $path) {
            $problem = $this->renameProblem($path, $target);
            if ($problem !== null) {
                $this->report($path, 'refused', $edited_at, $problem);
                $this->log[] = "refused rename $path: $problem";
                return;
            }
            $target_file = $this->file($target);
            if (!is_dir(dirname($target_file)) && !mkdir(dirname($target_file), 0775, true)) {
                throw new \RuntimeException("could not make the folder for $target");
            }
            if (!rename($file, $target_file)) {
                throw new \RuntimeException("could not rename $path to $target");
            }
        }

        $this->write($this->file($target), $content);
        $this->call('POST', '/journal-sync/results', [
            'path' => $path,
            'outcome' => 'applied',
            'edited_at' => $edited_at,
            'applied_path' => $target,
            'content' => base64_encode($content),
        ]);
        $this->log[] = $target === $path ? "wrote $path" : "wrote $path as $target";
    }

    private function renameProblem(string $path, string $target): ?string
    {
        $problem = EntryPath::renameProblem($target);
        if ($problem !== null) {
            return $problem;
        }
        if (($this->isTracked)($path)) {
            return 'The file was committed before the rename arrived, so it keeps its name.';
        }
        if (file_exists($this->file($target))) {
            return 'Lemur 13 already has a file named ' . $target . '.';
        }
        return null;
    }

    private function mirror(): void
    {
        $eligible = $this->eligible();
        $held = [];
        $files = $this->call('GET', '/journal-sync/files', null)['files'] ?? [];
        foreach (is_array($files) ? $files : [] as $row) {
            if (is_array($row)) {
                $held[self::str($row, 'path')] = $row;
            }
        }

        foreach ($eligible as $path => $git_state) {
            $content = (string) file_get_contents($this->file($path));
            $have = $held[$path] ?? null;
            if (
                $have !== null && self::str($have, 'base_hash') === hash('sha256', $content)
                && self::str($have, 'git_state') === $git_state
            ) {
                continue;
            }
            $this->call('POST', '/journal-sync/files', [
                'path' => $path,
                'git_state' => $git_state,
                'content' => base64_encode($content),
            ]);
            $this->log[] = "uploaded $path ($git_state)";
        }

        foreach (array_keys($held) as $path) {
            if (isset($eligible[$path])) {
                continue;
            }
            $deleted = !is_file($this->file($path));
            $this->call('POST', '/journal-sync/files/remove', ['path' => $path, 'deleted' => $deleted]);
            $this->log[] = ($deleted ? 'gone ' : 'settled ') . $path;
        }
    }

    /**
     * Entry files that belong on mg: uncommitted, or dated in the last
     * Listing::KEEP_DAYS days.
     *
     * @return array<string, string> path => git_state
     */
    public function eligible(): array
    {
        $out = [];
        $status = ($this->gitStatus)();
        foreach (is_array($status) ? $status : [] as $path => $state) {
            if (is_string($path) && EntryPath::parse($path) !== null && is_file($this->file($path))) {
                $out[$path] = $state === 'untracked' ? 'untracked' : 'modified';
            }
        }
        $day = new \DateTimeImmutable($this->today);
        for ($i = 0; $i <= Listing::KEEP_DAYS; $i++) {
            $d = $day->modify("-$i days");
            foreach (glob($this->dir . '/' . $d->format('Y/m/d') . '*.md') ?: [] as $file) {
                $path = substr($file, strlen($this->dir) + 1);
                if (!isset($out[$path]) && EntryPath::parse($path) !== null && is_file($file)) {
                    $out[$path] = 'committed';
                }
            }
        }
        ksort($out);
        return $out;
    }

    private function report(string $path, string $outcome, string $edited_at, string $note): void
    {
        $this->call('POST', '/journal-sync/results', [
            'path' => $path,
            'outcome' => $outcome,
            'edited_at' => $edited_at,
            'note' => $note,
        ]);
    }

    /**
     * @param array<mixed>|null $body
     * @return array<mixed>
     */
    private function call(string $method, string $route, ?array $body): array
    {
        $out = ($this->api)($method, $route, $body);
        return is_array($out) ? $out : [];
    }

    private function file(string $path): string
    {
        return $this->dir . '/' . $path;
    }

    /** Written beside, then renamed over, so a half-written file is never left. */
    private function write(string $file, string $content): void
    {
        $tmp = $file . '.tmp-sync';
        if (file_put_contents($tmp, $content) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("could not write $file");
        }
    }

    /**
     * @param array<mixed> $from
     */
    private static function str(array $from, string $key): string
    {
        $v = $from[$key] ?? '';
        return is_string($v) ? $v : '';
    }
}
