<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * The step files under tools/site-standards/steps/, read into a tree:
 *
 *     <standard>/_standard.md          frontmatter: name
 *     <standard>/<track>/_track.md     frontmatter: name, position,
 *                                      model (optional)
 *     <standard>/<track>/<number>_<step>.md
 *                                      frontmatter: title, status, whose_move,
 *                                      check, param (optional), model (optional),
 *                                      deploys (optional)
 *
 * A step's position is the number its filename starts with (0 to 65535.9999,
 * up to four decimal places) and its name is what follows the underscore, as
 * Names::stepFile() splits them. A step file has no "position" line.
 *
 * Frontmatter is a leading block between two "---" lines of flat
 * "key: value" pairs. In a step file the text up to a line reading
 * "## How to do it" is the explanation shown on the page, and the rest is the
 * how-to an agent follows.
 *
 * A step's model is its own "model" line, else its track's. It names the
 * model an agent does the step on (StepModel). An agent's step with neither
 * is refused: there is no default. Rob's step needs none.
 *
 * A step's "deploys: agent" line says the session that does the step also
 * deploys it, where every other step waits for Rob's deploy. Only an agent's
 * step may carry it. The line is checked here and read by the next-step
 * script from the step file itself, as "model" is; nothing stores it.
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
            } else {
                $named = Names::stepFile($name);
                if ($named === null) {
                    throw new StandardsFormatException(
                        "{$path}: a step file is named <number>_<name>.md, like 030_autoloader.md"
                    );
                }
                $stepFiles[$standard][$track][] = ['path' => (string) $path] + $named;
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
                    ['model'],
                )['fields'];
                $trackModel = self::model($trackPath, $trackFields['model'] ?? null);

                $steps = [];
                foreach ($stepFiles[$standard][$track] ?? [] as $stepFile) {
                    ['path' => $path, 'name' => $name, 'position' => $position] = $stepFile;
                    if (isset($stepNames[$name])) {
                        throw new StandardsFormatException(
                            "{$path}: the step name \"{$name}\" is already used by {$stepNames[$name]}"
                        );
                    }
                    $stepNames[$name] = $path;
                    $steps[] = self::step($name, $position, $path, $files[$path], $trackModel);
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

    private static function step(
        string $name,
        float $position,
        string $path,
        string $content,
        ?StepModel $trackModel,
    ): StepDef {
        $parsed = self::frontmatter(
            $path,
            $content,
            ['title', 'status', 'whose_move', 'check'],
            ['param', 'model', 'deploys'],
        );
        $fields = $parsed['fields'];

        $status = StepStatus::tryFrom($fields['status'])
            ?? throw new StandardsFormatException("{$path}: status must be idea or proven");
        $whoseMove = WhoseMove::tryFrom($fields['whose_move'])
            ?? throw new StandardsFormatException("{$path}: whose_move must be rob or agent");
        $model = self::model($path, $fields['model'] ?? null) ?? $trackModel;
        if ($whoseMove === WhoseMove::Agent && $model === null) {
            throw new StandardsFormatException(
                "{$path}: an agent step needs a model: add a \"model\" line here or to its track's _track.md"
            );
        }

        if (isset($fields['deploys'])) {
            if ($fields['deploys'] !== 'agent') {
                throw new StandardsFormatException("{$path}: deploys must be agent, or the line left out");
            }
            if ($whoseMove !== WhoseMove::Agent) {
                throw new StandardsFormatException("{$path}: only an agent's step may say \"deploys: agent\"");
            }
        }

        $lines = explode("\n", $parsed['body']);
        $heading = array_search(self::HOW_TO_HEADING, array_map(rtrim(...), $lines), true);
        $explanation = $heading === false ? $lines : array_slice($lines, 0, (int) $heading);
        $howTo = $heading === false ? [] : array_slice($lines, (int) $heading + 1);

        return new StepDef(
            $name,
            $position,
            $fields['title'],
            trim(implode("\n", $explanation)),
            trim(implode("\n", $howTo)),
            $status,
            $whoseMove,
            $fields['check'],
            $fields['param'] ?? null,
            $model,
        );
    }

    /**
     * A track's position, from its _track.md: a number from 1 to 65535 with up
     * to four decimal places. A step's position comes from its filename.
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
     * A "model" line's value as a StepModel; null when the file has no such line.
     */
    private static function model(string $path, ?string $value): ?StepModel
    {
        if ($value === null) {
            return null;
        }
        return StepModel::tryFrom($value)
            ?? throw new StandardsFormatException("{$path}: model must be haiku, sonnet, opus or fable");
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
