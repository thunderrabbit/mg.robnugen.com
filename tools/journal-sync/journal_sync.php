<?php

/**
 * Sync recent journal entries between Lemur 13 and mg.robnugen.com/journal.
 *
 * Run by Carrie's run.sh right after she finishes, from the pinned copy that
 * install.sh puts in ~/work/carrie/journal-sync/ — never from the mg checkout,
 * which is often on a feature branch. Design:
 * docs/superpowers/specs/2026-11-05-journal-editing-design.md.
 *
 * Settings, all optional:
 *   JOURNAL_SYNC_DIR       the journal repo   (~/work/rob/robnugen.com/journal/journal)
 *   JOURNAL_SYNC_KEY_FILE  mg API key, 0600   (~/.config/journal-sync/mg.key)
 *   JOURNAL_SYNC_BASE      mg API base URL    (https://mg.robnugen.com/api/v1)
 *
 * Exit 0 on success; 1 on any failure, logged, so the next run retries.
 * It only reads git; it never stages, commits or pushes.
 */

declare(strict_types=1);

$lib = is_dir(__DIR__ . '/lib') ? __DIR__ . '/lib' : __DIR__ . '/../../classes/Journal';
foreach (['EntryPath', 'EntryCheck', 'Files', 'Listing', 'Sync'] as $class) {
    require_once "$lib/$class.php";
}

$home = (string) getenv('HOME');
$env = static function (string $name, string $default): string {
    $v = getenv($name);
    return is_string($v) && $v !== '' ? $v : $default;
};
$dir = rtrim($env('JOURNAL_SYNC_DIR', "$home/work/rob/robnugen.com/journal/journal"), '/');
$key_file = $env('JOURNAL_SYNC_KEY_FILE', "$home/.config/journal-sync/mg.key");
$base = rtrim($env('JOURNAL_SYNC_BASE', 'https://mg.robnugen.com/api/v1'), '/');
$git = is_executable('/usr/bin/git') ? '/usr/bin/git' : 'git';

$say = static function (string $line): void {
    fwrite(STDOUT, gmdate('Y-m-d\TH:i:s\Z') . " journal-sync: $line\n");
};

try {
    if (!is_dir("$dir/.git")) {
        throw new RuntimeException("$dir is not the journal repo");
    }
    if (!is_readable($key_file)) {
        throw new RuntimeException("no mg key at $key_file");
    }
    if ((fileperms($key_file) & 0077) !== 0) {
        throw new RuntimeException("$key_file must be readable by its owner only (chmod 600)");
    }
    $key = trim((string) file_get_contents($key_file));

    $api = static function (string $method, string $route, ?array $body) use ($base, $key): array {
        $headers = ["X-API-Key: $key", 'Accept: application/json'];
        $options = ['method' => $method, 'timeout' => 30, 'ignore_errors' => true];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $options['content'] = (string) json_encode($body);
        }
        $options['header'] = implode("\r\n", $headers);
        $reply = @file_get_contents($base . $route, false, stream_context_create(['http' => $options]));
        $status_line = $http_response_header[0] ?? '';
        if ($reply === false || preg_match('#^HTTP/\S+ (\d{3})#', $status_line, $m) !== 1) {
            throw new RuntimeException("$method $route: no answer from mg");
        }
        $decoded = json_decode($reply, true);
        if ((int) $m[1] >= 300) {
            $error = is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : $status_line;
            throw new RuntimeException("$method $route: $error");
        }
        return is_array($decoded) ? $decoded : [];
    };

    $git_status = static function () use ($git, $dir): array {
        $out = shell_exec(escapeshellarg($git) . ' -C ' . escapeshellarg($dir)
            . ' status --porcelain=v1 -z -uall 2>/dev/null');
        if (!is_string($out)) {
            throw new RuntimeException('git status failed');
        }
        $states = [];
        $parts = explode("\0", $out);
        for ($i = 0, $n = count($parts); $i < $n; $i++) {
            $entry = $parts[$i];
            if (strlen($entry) < 4) {
                continue;
            }
            $code = substr($entry, 0, 2);
            $path = substr($entry, 3);
            if ($code[0] === 'R' || $code[0] === 'C') {
                $i++; // the old name follows a rename or copy
            }
            if (str_contains($code, 'D')) {
                continue;
            }
            $states[$path] = $code === '??' ? 'untracked' : 'modified';
        }
        return $states;
    };

    $is_tracked = static function (string $path) use ($git, $dir): bool {
        exec(escapeshellarg($git) . ' -C ' . escapeshellarg($dir) . ' ls-files --error-unmatch -- '
            . escapeshellarg($path) . ' >/dev/null 2>&1', $ignored, $code);
        return $code === 0;
    };

    $today = trim((string) shell_exec('date +%F')); // the laptop's zone, not PHP's default
    $sync = new \Journal\Sync(
        $dir,
        Closure::fromCallable($api),
        Closure::fromCallable($git_status),
        Closure::fromCallable($is_tracked),
        $today,
    );
    $lines = $sync->run();
    foreach ($lines as $line) {
        $say($line);
    }
    $say('done' . ($lines === [] ? ', nothing to do' : ''));
    exit(0);
} catch (Throwable $e) {
    $say('FAILED: ' . $e->getMessage());
    exit(1);
}
