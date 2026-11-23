<?php

declare(strict_types=1);

namespace Issues\Images;

use Database\Row\IssueImageRow;
use Database\Rows;
use Database\RowValue;
use Issues\Caller;

/**
 * ImageStore on mg's MySQL connection. The SQL is plain enough to run on
 * SQLite too, which is what the unit tests use.
 */
final readonly class PdoImageStore implements ImageStore
{
    private const string STRIP_ORDER = 'im.uploaded_at_utc DESC, im.filename ASC, im.issue_image_id ASC';

    public function __construct(private \PDO $pdo)
    {
    }

    #[\Override]
    public function find(int $issueImageId): ?IssueImageRow
    {
        $row = $this->one('SELECT * FROM issue_images WHERE issue_image_id = ?', [$issueImageId]);
        return $row === null ? null : IssueImageRow::fromRow($row);
    }

    #[\Override]
    public function findAtHome(int $homeIssueId, int $homeYear, string $filename): ?IssueImageRow
    {
        // The filename is compared in PHP as well: MySQL's default collation
        // would call A.png and a.png the same name, and the disk does not.
        $rows = $this->all(
            'SELECT * FROM issue_images WHERE home_issue_id = ? AND home_year = ? AND filename = ?',
            [$homeIssueId, $homeYear, $filename],
        );
        foreach ($rows as $row) {
            $image = IssueImageRow::fromRow($row);
            if ($image->filename === $filename) {
                return $image;
            }
        }
        return null;
    }

    #[\Override]
    public function atHome(int $homeIssueId, int $homeYear): array
    {
        return $this->images(
            'SELECT im.* FROM issue_images im
             WHERE im.home_issue_id = ? AND im.home_year = ?
             ORDER BY im.filename, im.issue_image_id',
            [$homeIssueId, $homeYear],
        );
    }

    #[\Override]
    public function insert(
        int $homeProjectId,
        int $homeIssueId,
        int $homeYear,
        CheckedImage $image,
        string $filename,
        int $aiu,
        \DateTimeImmutable $at,
    ): int {
        $this->run(
            'INSERT INTO issue_images
                (home_project_id, home_issue_id, home_year, filename, mime_type, byte_size,
                 width, height, sha256, uploaded_by_aiu, uploaded_at_utc)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $homeProjectId,
                $homeIssueId,
                $homeYear,
                $filename,
                $image->type->value,
                $image->byteSize,
                $image->width,
                $image->height,
                $image->sha256,
                $aiu,
                self::utc($at),
            ],
        );
        return (int) $this->pdo->lastInsertId();
    }

    #[\Override]
    public function link(int $issueImageId, int $issueId, int $aiu, \DateTimeImmutable $at): bool
    {
        $linked = $this->one(
            'SELECT 1 AS linked FROM issue_image_links WHERE issue_image_id = ? AND issue_id = ?',
            [$issueImageId, $issueId],
        );
        if ($linked !== null) {
            return false;
        }
        $this->run(
            'INSERT INTO issue_image_links (issue_image_id, issue_id, linked_by_aiu, linked_at_utc)
             VALUES (?, ?, ?, ?)',
            [$issueImageId, $issueId, $aiu, self::utc($at)],
        );
        return true;
    }

    #[\Override]
    public function unlink(int $issueImageId, int $issueId): bool
    {
        return $this->run(
            'DELETE FROM issue_image_links WHERE issue_image_id = ? AND issue_id = ?',
            [$issueImageId, $issueId],
        ) > 0;
    }

    #[\Override]
    public function linkedIssueIds(int $issueImageId): array
    {
        $rows = $this->all(
            'SELECT issue_id FROM issue_image_links WHERE issue_image_id = ? ORDER BY issue_id',
            [$issueImageId],
        );
        return array_map(static fn (array $row): int => RowValue::int($row, 'issue_id'), $rows);
    }

    #[\Override]
    public function forIssue(int $issueId): array
    {
        return $this->images(
            'SELECT im.*
             FROM issue_images im
             JOIN issue_image_links l ON l.issue_image_id = im.issue_image_id
             WHERE l.issue_id = ?
             ORDER BY ' . self::STRIP_ORDER,
            [$issueId],
        );
    }

    #[\Override]
    public function forProject(int $projectId, int $limit): array
    {
        return $this->images(
            'SELECT im.*
             FROM issue_images im
             WHERE EXISTS (
                 SELECT 1
                 FROM issue_image_links l
                 JOIN issues i ON i.issue_id = l.issue_id
                 WHERE l.issue_image_id = im.issue_image_id AND i.project_id = ?
             )
             ORDER BY ' . self::STRIP_ORDER . '
             LIMIT ' . max(1, $limit),
            [$projectId],
        );
    }

    #[\Override]
    public function unlinked(): array
    {
        return $this->images(
            'SELECT im.*
             FROM issue_images im
             WHERE NOT EXISTS (
                 SELECT 1 FROM issue_image_links l WHERE l.issue_image_id = im.issue_image_id
             )
             ORDER BY im.issue_image_id',
            [],
        );
    }

    #[\Override]
    public function delete(int $issueImageId): void
    {
        $this->run('DELETE FROM issue_image_links WHERE issue_image_id = ?', [$issueImageId]);
        $this->run('DELETE FROM issue_images WHERE issue_image_id = ?', [$issueImageId]);
    }

    #[\Override]
    public function accountOfIssue(int $issueId): ?int
    {
        $row = $this->one(
            'SELECT p.user_id
             FROM issues i
             JOIN projects p ON p.project_id = i.project_id
             WHERE i.issue_id = ?',
            [$issueId],
        );
        return $row === null ? null : RowValue::int($row, 'user_id');
    }

    #[\Override]
    public function callerForAiu(int $aiu): ?Caller
    {
        $row = $this->one('SELECT user_id, actor_type FROM agent_inbox_user WHERE aiu_id = ?', [$aiu]);
        return $row === null
            ? null
            : new Caller(
                userId: RowValue::int($row, 'user_id'),
                aiu: $aiu,
                isHuman: RowValue::string($row, 'actor_type') === 'human',
            );
    }

    #[\Override]
    public function projectName(int $projectId): ?string
    {
        $row = $this->one('SELECT name FROM projects WHERE project_id = ?', [$projectId]);
        return $row === null ? null : RowValue::string($row, 'name');
    }

    #[\Override]
    public function transaction(\Closure $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format(RowValue::DATETIME_FORMAT);
    }

    /**
     * @param list<int|string> $params
     * @return list<IssueImageRow>
     */
    private function images(string $sql, array $params): array
    {
        return array_map(IssueImageRow::fromRow(...), $this->all($sql, $params));
    }

    /**
     * @param list<int|string> $params
     * @return array<mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return Rows::one($statement->fetch(\PDO::FETCH_ASSOC));
    }

    /**
     * @param list<int|string> $params
     * @return list<array<mixed>>
     */
    private function all(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return Rows::all($statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param list<int|string> $params
     */
    private function run(string $sql, array $params): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }
}
