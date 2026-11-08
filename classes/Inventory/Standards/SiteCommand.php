<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * The commands of tools/site_standards.php: the few facts about a site that
 * are kept by hand.
 *
 *     sites
 *     test-candidates
 *     site-add    --shortcode= --standard= [--project=]
 *     site-set    --shortcode= [--project=] [--checkout=] [--server=] [--test=] [--github=] [--autonomy=]
 *                 [--upgrade=]
 *     site-link   --shortcode= --domain= [--path=]
 *     site-unlink --shortcode= --domain= [--path=]
 *     ec2-server-add --alias= [--label=]
 *     ec2-domain-add --alias= --domain= --directory=
 *     ec2-servers
 *
 * An option given with an empty value (--github=) clears that field.
 *
 * --domain= on site-link and site-unlink is a DreamHost domain or an EC2
 * hostname; a site's links are all of one kind. ec2-server-add enters a
 * server by its ssh alias on Lemur 13, ec2-domain-add a hostname it serves
 * with the absolute directory behind it, and ec2-servers prints them all as
 * JSON for tools/ec2-sweep/sweep.sh.
 *
 * --server= names the domain the scanner asks what is deployed, as
 * example.test or example.test/blog; it is needed only when a site's domains
 * have different checkouts.
 *
 * --test= names the site's test domain in the same way; clear it with --test=.
 * A site with no test domain has no steps that need one.
 *
 * --upgrade= names the branch the site is preparing to replace what its
 * server runs. The scanner then checks the site at that branch's tip instead
 * of skipping it for not being at the server's commit. Clear it with
 * --upgrade= when the line is promoted.
 */
final class SiteCommand
{
    /** @var array<string, array{required: list<string>, optional: list<string>}> */
    private const array COMMANDS = [
        'sites' => ['required' => [], 'optional' => []],
        'test-candidates' => ['required' => [], 'optional' => []],
        'site-add' => ['required' => ['shortcode', 'standard'], 'optional' => ['project']],
        'site-set' => [
            'required' => ['shortcode'],
            'optional' => ['project', 'checkout', 'server', 'test', 'github', 'autonomy', 'upgrade'],
        ],
        'site-link' => ['required' => ['shortcode', 'domain'], 'optional' => ['path']],
        'site-unlink' => ['required' => ['shortcode', 'domain'], 'optional' => ['path']],
        'ec2-server-add' => ['required' => ['alias'], 'optional' => ['label']],
        'ec2-domain-add' => ['required' => ['alias', 'domain', 'directory'], 'optional' => []],
        'ec2-servers' => ['required' => [], 'optional' => []],
    ];

    /**
     * @param list<string> $args everything after the script's name
     * @return array{command: string, options: array<string, string>}
     */
    public static function parse(array $args): array
    {
        $command = array_shift($args)
            ?? throw new \InvalidArgumentException('Give a command: ' . implode(', ', array_keys(self::COMMANDS)) . '.');
        $shape = self::COMMANDS[$command]
            ?? throw new \InvalidArgumentException(
                "Unknown command: {$command}. Use one of: " . implode(', ', array_keys(self::COMMANDS)) . '.'
            );

        $options = [];
        foreach ($args as $arg) {
            if (preg_match('/\A--([a-z]+)=(.*)\z/s', $arg, $m) !== 1) {
                throw new \InvalidArgumentException("Cannot read \"{$arg}\". Options look like --name=value.");
            }
            [, $name, $value] = $m;
            if (!in_array($name, $shape['required'], true) && !in_array($name, $shape['optional'], true)) {
                throw new \InvalidArgumentException("{$command} does not take --{$name}.");
            }
            if (array_key_exists($name, $options)) {
                throw new \InvalidArgumentException("--{$name} is given twice.");
            }
            $options[$name] = $value;
        }
        foreach ($shape['required'] as $name) {
            if (($options[$name] ?? '') === '') {
                throw new \InvalidArgumentException("{$command} needs --{$name}=.");
            }
        }

        return ['command' => $command, 'options' => $options];
    }

    /**
     * Does what the arguments ask and answers the line to print.
     *
     * @param list<string> $args
     * @throws \InvalidArgumentException with a sentence for the person who typed it
     */
    public static function run(array $args, SiteStore $store): string
    {
        ['command' => $command, 'options' => $o] = self::parse($args);
        $shortcode = $o['shortcode'] ?? '';
        $path = $o['path'] ?? '';

        switch ($command) {
            case 'sites':
                return json_encode($store->forScanner(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            case 'test-candidates':
                return json_encode($store->testCandidates(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            case 'site-add':
                $store->add($shortcode, $o['standard'] ?? '', self::projectId($o['project'] ?? ''));
                return "Added site {$shortcode}.";
            case 'site-set':
                $changes = [];
                if (array_key_exists('project', $o)) {
                    $changes['project_id'] = self::projectId($o['project']);
                }
                if (array_key_exists('checkout', $o)) {
                    $changes['checkout_path'] = $o['checkout'] === '' ? null : $o['checkout'];
                }
                if (array_key_exists('server', $o)) {
                    $changes['server'] = $o['server'];
                }
                if (array_key_exists('test', $o)) {
                    $changes['test'] = $o['test'];
                }
                if (array_key_exists('github', $o)) {
                    $changes['github_repo'] = $o['github'] === '' ? null : $o['github'];
                }
                if (array_key_exists('autonomy', $o)) {
                    $changes['autonomy'] = $o['autonomy'];
                }
                if (array_key_exists('upgrade', $o)) {
                    $changes['upgrade_branch'] = $o['upgrade'] === '' ? null : $o['upgrade'];
                }
                if ($changes === []) {
                    throw new \InvalidArgumentException('site-set needs at least one thing to change.');
                }
                $store->set($shortcode, $changes);
                return "Updated site {$shortcode}.";
            case 'site-link':
                $store->link($shortcode, $o['domain'] ?? '', $path);
                return "Linked {$o['domain']}{$path} to site {$shortcode}.";
            case 'ec2-server-add':
                $store->addServer($o['alias'] ?? '', $o['label'] ?? '');
                return "Added EC2 server {$o['alias']}.";
            case 'ec2-domain-add':
                $store->addDomain($o['alias'] ?? '', $o['domain'] ?? '', $o['directory'] ?? '');
                return "Added {$o['domain']} on {$o['alias']}, served from {$o['directory']}.";
            case 'ec2-servers':
                return json_encode($store->serversForSweep(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            default:
                $store->unlink($shortcode, $o['domain'] ?? '', $path);
                return "Unlinked {$o['domain']}{$path} from site {$shortcode}.";
        }
    }

    private static function projectId(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException('--project= takes a project number.');
        }
        return (int) $value;
    }
}
