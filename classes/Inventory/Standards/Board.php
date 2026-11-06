<?php

declare(strict_types=1);

namespace Inventory\Standards;

use Db\Rows;
use Db\RowValue;

/**
 * The queries behind the standards page. It reads the rows and hands them to
 * SiteReport, which holds the rules.
 *
 * Issues are read straight from the tracker's tables rather than through
 * project membership: these pages are for the account's owner only
 * (Inventory\AdminPage::guard), who owns every project a site points at.
 */
final class Board
{
    /** @var array<int, list<array<mixed>>> live steps by std_standard_id */
    private array $steps = [];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Every standard with its sites and its untried ideas.
     *
     * @return list<array{slug: string, name: string, proven: int, sites: list<array{shortcode: string, project_id: ?int, autonomy: string, deployed_branch: string, last_checked_utc: ?string, skipped_reason: ?string, report: SiteReport}>, ideas: list<array{track: string, title: string}>}>
     */
    public function standards(): array
    {
        $standards = [];
        foreach ($this->rows('SELECT std_standard_id, slug, name FROM std_standards ORDER BY name') as $standard) {
            $standardId = RowValue::int($standard, 'std_standard_id');
            $ideas = [];
            foreach ($this->stepsOf($standardId) as $step) {
                if (RowValue::string($step, 'status') === StepStatus::Idea->value) {
                    $ideas[] = [
                        'track' => RowValue::string($step, 'track_name'),
                        'title' => RowValue::string($step, 'title'),
                    ];
                }
            }
            $sites = [];
            $rows = $this->rows('SELECT * FROM std_sites WHERE std_standard_id = ? ORDER BY shortcode', [$standardId]);
            foreach ($rows as $site) {
                $sites[] = $this->site($site);
            }
            $standards[] = [
                'slug' => RowValue::string($standard, 'slug'),
                'name' => RowValue::string($standard, 'name'),
                'proven' => count($this->provenOf($standardId)),
                'sites' => $sites,
                'ideas' => $ideas,
            ];
        }
        return $standards;
    }

    /**
     * The sites a DreamHost domain carries: usually one, sometimes one per path.
     *
     * @return list<array{shortcode: string, path_prefix: string, report: SiteReport}>
     */
    public function sitesOnDomain(int $dhDomainId): array
    {
        $sites = [];
        $rows = $this->rows(
            'SELECT s.*, l.path_prefix
               FROM dh_site_domains l JOIN std_sites s ON s.std_site_id = l.std_site_id
              WHERE l.dh_domain_id = ?
              ORDER BY l.path_prefix, s.shortcode',
            [$dhDomainId],
        );
        foreach ($rows as $site) {
            $sites[] = [
                'shortcode' => RowValue::string($site, 'shortcode'),
                'path_prefix' => RowValue::string($site, 'path_prefix'),
                'report' => $this->site($site)['report'],
            ];
        }
        return $sites;
    }

    /**
     * @param array<mixed> $site a std_sites row
     * @return array{shortcode: string, project_id: ?int, autonomy: string, deployed_branch: string, last_checked_utc: ?string, skipped_reason: ?string, report: SiteReport}
     */
    private function site(array $site): array
    {
        $siteId = RowValue::int($site, 'std_site_id');
        $standardId = RowValue::int($site, 'std_standard_id');
        $shortcode = RowValue::string($site, 'shortcode');
        $projectId = RowValue::intOrNull($site, 'project_id');

        $domains = [];
        $rows = $this->rows(
            'SELECT d.domain, st.php_version, u.username, u.ssh_alias, u.login_confirmed_on
               FROM dh_site_domains l
               JOIN dh_domains d ON d.dh_domain_id = l.dh_domain_id
               LEFT JOIN dh_domain_states st ON st.dh_domain_id = d.dh_domain_id AND st.until_sweep_id IS NULL
               LEFT JOIN dh_users u ON u.dh_user_id = st.dh_user_id
              WHERE l.std_site_id = ?
              ORDER BY d.domain, l.path_prefix',
            [$siteId],
        );
        foreach ($rows as $row) {
            $domains[] = [
                'domain' => RowValue::string($row, 'domain'),
                'php_version' => RowValue::stringOrNull($row, 'php_version'),
                'username' => RowValue::stringOrNull($row, 'username'),
                'ssh_alias' => RowValue::stringOrNull($row, 'ssh_alias'),
                'login_confirmed_on' => RowValue::stringOrNull($row, 'login_confirmed_on'),
            ];
        }
        $facts = new SiteFacts(
            $domains,
            RowValue::stringOrNull($site, 'checkout_path'),
            RowValue::stringOrNull($site, 'github_repo'),
        );

        $openStates = [];
        $rows = $this->rows(
            'SELECT s.slug, st.outcome, st.detail
               FROM std_step_states st JOIN std_steps s ON s.std_step_id = st.std_step_id
              WHERE st.std_site_id = ? AND st.until_scan_id IS NULL',
            [$siteId],
        );
        foreach ($rows as $row) {
            $openStates[RowValue::string($row, 'slug')] = [
                'outcome' => RowValue::string($row, 'outcome'),
                'detail' => RowValue::string($row, 'detail'),
            ];
        }

        $issues = [];
        $shortcodesInProject = [$shortcode];
        if ($projectId !== null) {
            // Only a title with a dot in it can be a site note.
            $rows = $this->rows(
                "SELECT i.issue_id, i.title, s.slug, s.label
                   FROM issues i JOIN issue_statuses s ON s.status_id = i.status_id
                  WHERE i.project_id = ? AND i.title LIKE '%.%'
                  ORDER BY i.issue_id",
                [$projectId],
            );
            foreach ($rows as $row) {
                $issues[] = new NoteIssue(
                    RowValue::int($row, 'issue_id'),
                    RowValue::string($row, 'title'),
                    RowValue::string($row, 'slug'),
                    RowValue::string($row, 'label'),
                );
            }
            $shortcodesInProject = [];
            foreach ($this->rows('SELECT shortcode FROM std_sites WHERE project_id = ?', [$projectId]) as $row) {
                $shortcodesInProject[] = RowValue::string($row, 'shortcode');
            }
        }

        $knownSteps = [];
        foreach ($this->stepsOf($standardId) as $step) {
            $knownSteps[] = RowValue::string($step, 'slug');
        }

        $latest = $this->rows(
            'SELECT ss.skipped_reason
               FROM std_scan_sites ss JOIN std_scans sc ON sc.std_scan_id = ss.std_scan_id
              WHERE ss.std_site_id = ?
              ORDER BY sc.scanned_at_utc DESC LIMIT 1',
            [$siteId],
        );
        $checked = $this->rows(
            'SELECT sc.scanned_at_utc
               FROM std_scan_sites ss JOIN std_scans sc ON sc.std_scan_id = ss.std_scan_id
              WHERE ss.std_site_id = ? AND ss.skipped_reason IS NULL
              ORDER BY sc.scanned_at_utc DESC LIMIT 1',
            [$siteId],
        );

        return [
            'shortcode' => $shortcode,
            'project_id' => $projectId,
            'autonomy' => RowValue::string($site, 'autonomy'),
            'deployed_branch' => RowValue::string($site, 'deployed_branch'),
            'last_checked_utc' => $checked === []
                ? null
                : RowValue::dateTime($checked[0], 'scanned_at_utc')->format('Y-m-d H:i'),
            'skipped_reason' => $latest === [] ? null : RowValue::stringOrNull($latest[0], 'skipped_reason'),
            'report' => SiteReport::build(
                $shortcode,
                $facts,
                $this->provenOf($standardId),
                $openStates,
                $issues,
                $shortcodesInProject,
                $knownSteps,
            ),
        ];
    }

    /**
     * A standard's steps that the files still have, in display order.
     *
     * @return list<array<mixed>>
     */
    private function stepsOf(int $standardId): array
    {
        return $this->steps[$standardId] ??= $this->rows(
            'SELECT s.slug, t.name AS track_name, s.title, s.whose_move, s.check_name, s.check_param,
                    s.explanation, s.how_to, s.status
               FROM std_steps s JOIN std_tracks t ON t.std_track_id = s.std_track_id
              WHERE t.std_standard_id = ? AND s.retired_on IS NULL
              ORDER BY t.position, t.slug, s.position, s.slug',
            [$standardId],
        );
    }

    /**
     * @return list<array<mixed>>
     */
    private function provenOf(int $standardId): array
    {
        return array_values(array_filter(
            $this->stepsOf($standardId),
            static fn (array $step): bool => RowValue::string($step, 'status') === StepStatus::Proven->value,
        ));
    }

    /**
     * @param list<int|string|null> $params
     * @return list<array<mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return Rows::all($statement->fetchAll(\PDO::FETCH_ASSOC));
    }
}
