<?php

/**
 * Pictures on issues, from the command line. Run on the server, normally by
 * the scripts in tools/issue-images/ on Lemur 13:
 *
 *     issue_images.php import --issue=<id> --dir=<directory> [--as=<aiu_id>] [--dry-run]
 *     issue_images.php link   --image=<id> --issue=<id> [--as=<aiu_id>]
 *     issue_images.php unlink --image=<id> --issue=<id> [--as=<aiu_id>]
 *     issue_images.php purge  [--dry-run]
 *
 * import stores every picture directly in the directory on the issue and
 * prints a line per file, then the markdown line of each picture. It never
 * deletes the directory or anything in it. purge is the only thing that ever
 * deletes a stored picture: those no issue shows any more.
 *
 * The rules are Issues\Images\ImageCommand and ImageService. Exit 0 done,
 * 1 refused (reason on stderr, or a file of an import was), 2 bad arguments.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../classes/Mlaphp/Autoloader.php';
spl_autoload_register([new \Mlaphp\Autoloader(), 'load']);

$config = new \Config();
$pdo = \Database\Base::getPDO($config);
$command = new \Issues\Images\ImageCommand(
    \Issues\Images\ImageService::forConfig($config, $pdo),
    new \Issues\Images\PdoImageStore($pdo),
    new \Issues\PdoIssueStore($pdo),
    static fn (): \DateTimeImmutable => new \DateTimeImmutable(),
);

$arguments = [];
foreach (array_slice(\Field::array($_SERVER, 'argv'), 1) as $argument) {
    if (is_string($argument)) {
        $arguments[] = $argument;
    }
}
$result = $command->run($arguments);

foreach ($result->out as $line) {
    echo $line, "\n";
}
foreach ($result->err as $line) {
    fwrite(STDERR, $line . "\n");
}
exit($result->exit);
