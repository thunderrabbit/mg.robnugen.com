<?php

declare(strict_types=1);

namespace Issues\Images;

use Issues\AccessException;
use Issues\Caller;
use Issues\IssueStore;
use Issues\NotFoundException;

/**
 * The command line's way in, run on the server by tools/issue_images.php and
 * from Lemur 13 by the scripts in tools/issue-images/:
 *
 *   import --issue=<id> --dir=<directory> [--as=<aiu_id>] [--dry-run]
 *   link   --image=<id> --issue=<id> [--as=<aiu_id>]
 *   unlink --image=<id> --issue=<id> [--as=<aiu_id>]
 *   purge  [--dry-run]
 *
 * It acts as --as, or as the human of the account that owns the issue.
 * Whoever can run this already has the server, so --as is believed about who
 * is acting; that actor still needs the access ImageService asks of anyone.
 *
 * Exit 0 done, 1 refused (or a file of an import was), 2 bad arguments.
 */
final readonly class ImageCommand
{
    private const string USAGE = <<<'TXT'
        usage: issue_images.php import --issue=<id> --dir=<directory> [--as=<aiu_id>] [--dry-run]
               issue_images.php link   --image=<id> --issue=<id> [--as=<aiu_id>]
               issue_images.php unlink --image=<id> --issue=<id> [--as=<aiu_id>]
               issue_images.php purge  [--dry-run]
        TXT;

    /**
     * @param \Closure(): \DateTimeImmutable $now
     */
    public function __construct(
        private ImageService $pictures,
        private ImageStore $store,
        private IssueStore $issues,
        private \Closure $now,
    ) {
    }

    /**
     * @param list<string> $args the command and its options, without the script name
     */
    public function run(array $args): CommandResult
    {
        $command = array_shift($args);
        $options = self::options($args);
        if ($options === null) {
            return self::usage();
        }
        $dryRun = isset($options['dry-run']);
        $issueId = self::id($options['issue'] ?? null);
        $imageId = self::id($options['image'] ?? null);
        $dir = $options['dir'] ?? '';
        $ready = match ($command) {
            'import' => $issueId !== null && $dir !== '' && $dir !== true,
            'link', 'unlink' => $issueId !== null && $imageId !== null,
            'purge' => true,
            default => false,
        };
        if (!$ready || (isset($options['as']) && self::id($options['as']) === null)) {
            return self::usage();
        }
        if (!$this->pictures->enabled()) {
            return self::refused('issue images are off: image_store_path is not set to a directory');
        }

        try {
            if ($command === 'purge') {
                return $this->purge($dryRun);
            }
            $caller = $this->caller((int) $issueId, self::id($options['as'] ?? null));
            return match ($command) {
                'import' => $this->import($caller, (int) $issueId, (string) $dir, $dryRun),
                'link' => $this->link($caller, (int) $imageId, (int) $issueId),
                default => $this->unlink($caller, (int) $imageId, (int) $issueId),
            };
        } catch (ImageRefused | NotFoundException | AccessException $e) {
            return self::refused($e->getMessage());
        }
    }

    private function import(Caller $caller, int $issueId, string $dir, bool $dryRun): CommandResult
    {
        if (!is_dir($dir)) {
            throw new ImageRefused("{$dir} is not a directory");
        }
        $names = array_values(array_filter(
            scandir($dir) ?: [],
            static fn (string $name): bool => is_file("{$dir}/{$name}"),
        ));
        sort($names, SORT_STRING);
        $files = array_map(
            static fn (string $name): array => ['path' => "{$dir}/{$name}", 'name' => $name],
            $names,
        );

        $results = $this->pictures->upload($caller, $issueId, $files, ($this->now)(), $dryRun);

        $out = $dryRun ? ['DRY RUN (nothing stored)'] : [];
        $markdown = [];
        $refused = false;
        foreach ($results as $result) {
            $line = sprintf('%-13s  %s', $result->outcome->value, $result->originalName);
            if ($result->image !== null && $result->image->filename !== $result->originalName) {
                $line .= " -> {$result->image->filename}";
            }
            if ($result->reason !== '') {
                $line .= "  ({$result->reason})";
            }
            $out[] = $line;
            $refused = $refused || $result->outcome === UploadOutcome::Refused;
            if ($result->image !== null && !$dryRun) {
                $markdown[] = $this->pictures->view($result->image)->markdown;
            }
        }
        if ($markdown !== []) {
            $out = [...$out, '', ...array_values(array_unique($markdown))];
        }
        return new CommandResult($refused ? 1 : 0, $out);
    }

    private function link(Caller $caller, int $imageId, int $issueId): CommandResult
    {
        $linked = $this->pictures->link($caller, $imageId, $issueId, ($this->now)());

        return new CommandResult(0, [$linked
            ? "linked picture {$imageId} to issue {$issueId}"
            : "picture {$imageId} was already on issue {$issueId}"]);
    }

    private function unlink(Caller $caller, int $imageId, int $issueId): CommandResult
    {
        $removed = $this->pictures->unlink($caller, $imageId, $issueId);

        return new CommandResult(0, [$removed
            ? "took picture {$imageId} off issue {$issueId}"
            : "picture {$imageId} was not on issue {$issueId}"]);
    }

    private function purge(bool $dryRun): CommandResult
    {
        $purged = $this->pictures->purge($dryRun);
        if ($purged === []) {
            return new CommandResult(0, ['nothing to purge']);
        }
        $out = $dryRun ? ['DRY RUN (nothing deleted)'] : [];
        foreach ($purged as $image) {
            $out[] = ($dryRun ? 'would purge  ' : 'purged  ') . ImagePath::ofRow($image)->relative();
        }
        return new CommandResult(0, $out);
    }

    /**
     * Who is acting: the named actor, or the human of the issue's account.
     */
    private function caller(int $issueId, ?int $as): Caller
    {
        if ($as !== null) {
            return $this->store->callerForAiu($as) ?? throw new ImageRefused("no actor {$as}");
        }
        $account = $this->store->accountOfIssue($issueId) ?? throw new ImageRefused("no issue {$issueId}");

        return $this->issues->humanCaller($account)
            ?? throw new ImageRefused("the account that owns issue {$issueId} has no human actor");
    }

    /**
     * --name=value and --flag arguments as name => value|true; null when
     * anything else is among them.
     *
     * @param list<string> $args
     * @return array<string, string|true>|null
     */
    private static function options(array $args): ?array
    {
        $options = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m) !== 1) {
                return null;
            }
            $options[$m[1]] = $m[2] ?? true;
        }
        return $options;
    }

    private static function id(string|bool|null $value): ?int
    {
        return is_string($value) && preg_match('/^[1-9]\d{0,9}$/', $value) === 1 ? (int) $value : null;
    }

    private static function usage(): CommandResult
    {
        return new CommandResult(2, err: explode("\n", self::USAGE));
    }

    private static function refused(string $reason): CommandResult
    {
        return new CommandResult(1, err: ["REFUSED: {$reason}"]);
    }
}
