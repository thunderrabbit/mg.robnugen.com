<?php

declare(strict_types=1);

namespace Journal;

/**
 * Journal files mirrored from Lemur 13 (table journal_files), and Rob's
 * edits waiting to go back.
 *
 * Lemur 13 starts every exchange; mg only stores and answers. The rule that
 * matters most: an edit of Rob's is never dropped. A waiting edit survives
 * uploads, removals and conflicts until Lemur 13 reports it written, or Rob
 * discards it himself.
 *
 * Plain SQL that SQLite also runs, so it is unit-tested in memory.
 */
final class Files
{
    public const STATUS_SYNCED = 'synced';
    public const STATUS_WAITING = 'edit_waiting';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_REFUSED = 'refused';

    public const GIT_STATES = ['untracked', 'modified', 'committed'];

    /** Largest file accepted either way. Journal entries are a few KB. */
    public const MAX_BYTES = 1048576;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Every file for the list page, newest path first.
     *
     * @return list<array<string, mixed>>
     */
    public function all(int $user_id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM journal_files WHERE user_id = ? ORDER BY path DESC"
        );
        $stmt->execute([$user_id]);
        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function find(int $user_id, int $jf_id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM journal_files WHERE jf_id = ? AND user_id = ?");
        $stmt->execute([$jf_id, $user_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        /** @var array<string, mixed>|null */
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findByPath(int $user_id, string $path): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM journal_files WHERE path = ? AND user_id = ?");
        $stmt->execute([$path, $user_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        /** @var array<string, mixed>|null */
        return is_array($row) ? $row : null;
    }

    /**
     * Whether another file already is, or is about to become, $path.
     */
    public function pathTaken(int $user_id, string $path, int $except_jf_id): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM journal_files
             WHERE user_id = ? AND jf_id <> ? AND (path = ? OR edit_path = ?)"
        );
        $stmt->execute([$user_id, $except_jf_id, $path, $path]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Rob's save. Replaces any edit still waiting, and clears a refusal or
     * conflict note: this is his newest word on the file.
     */
    public function saveEdit(int $user_id, int $jf_id, string $content, string $edit_path, string $now_utc): void
    {
        $this->pdo->prepare(
            "UPDATE journal_files
             SET edit_content = ?, edit_path = ?, edited_at_utc = ?, status = ?, status_note = NULL
             WHERE jf_id = ? AND user_id = ?"
        )->execute([$content, $edit_path, $now_utc, self::STATUS_WAITING, $jf_id, $user_id]);
    }

    /** Rob gives up his waiting edit; the file goes back to what Lemur 13 has. */
    public function discardEdit(int $user_id, int $jf_id): void
    {
        $this->pdo->prepare(
            "UPDATE journal_files
             SET edit_content = NULL, edit_path = NULL, edited_at_utc = NULL, status = ?, status_note = NULL
             WHERE jf_id = ? AND user_id = ?"
        )->execute([self::STATUS_SYNCED, $jf_id, $user_id]);
    }

    /**
     * What Lemur 13 needs to decide what to upload and remove.
     *
     * @return list<array{path: string, base_hash: string, git_state: string, status: string}>
     */
    public function manifest(int $user_id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT path, base_hash, git_state, status FROM journal_files WHERE user_id = ? ORDER BY path"
        );
        $stmt->execute([$user_id]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'path' => \Field::string($row, 'path'),
                'base_hash' => \Field::string($row, 'base_hash'),
                'git_state' => \Field::string($row, 'git_state'),
                'status' => \Field::string($row, 'status'),
            ];
        }
        return $out;
    }

    /**
     * Edits Lemur 13 should write, and conflicts it should check on.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(int $user_id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT path, edit_path, edit_content, edited_at_utc, base_hash, git_state, status
             FROM journal_files
             WHERE user_id = ? AND edit_content IS NOT NULL AND status IN (?, ?)
             ORDER BY path"
        );
        $stmt->execute([$user_id, self::STATUS_WAITING, self::STATUS_CONFLICT]);
        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Lemur 13's current copy of a file.
     *
     * While an edit of Rob's waits, the base is left alone: Lemur 13 decides
     * whether to write the edit by comparing its file against the base the
     * edit was made from. Moving the base forward here would let the edit
     * overwrite whatever changed on Lemur 13 in between.
     */
    public function upload(int $user_id, string $path, string $content, string $git_state, string $now_utc): void
    {
        $hash = hash('sha256', $content);
        $row = $this->findByPath($user_id, $path);
        if ($row === null) {
            $this->pdo->prepare(
                "INSERT INTO journal_files (user_id, path, base_content, base_hash, git_state, uploaded_at_utc, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([$user_id, $path, $content, $hash, $git_state, $now_utc, self::STATUS_SYNCED]);
            return;
        }
        if ($row['edit_content'] !== null) {
            $this->pdo->prepare("UPDATE journal_files SET git_state = ? WHERE jf_id = ?")
                ->execute([$git_state, $row['jf_id']]);
            return;
        }
        $this->pdo->prepare(
            "UPDATE journal_files
             SET base_content = ?, base_hash = ?, git_state = ?, uploaded_at_utc = ?, status = ?, status_note = NULL
             WHERE jf_id = ?"
        )->execute([$content, $hash, $git_state, $now_utc, self::STATUS_SYNCED, $row['jf_id']]);
    }

    /**
     * Lemur 13 no longer offers the file: it settled in git, or was deleted.
     * A waiting edit keeps the row, flagged, so it is never lost.
     */
    public function remove(int $user_id, string $path, bool $deleted): void
    {
        $row = $this->findByPath($user_id, $path);
        if ($row === null) {
            return;
        }
        if ($row['edit_content'] === null) {
            $this->pdo->prepare("DELETE FROM journal_files WHERE jf_id = ?")->execute([$row['jf_id']]);
            return;
        }
        if ($deleted) {
            $this->pdo->prepare("UPDATE journal_files SET status = ?, status_note = ? WHERE jf_id = ?")->execute([
                self::STATUS_CONFLICT,
                'The file was deleted on Lemur 13 after you edited it. Your edit is still here.',
                $row['jf_id'],
            ]);
        }
    }

    /**
     * What Lemur 13 did with an edit.
     *
     * applied   it wrote the edit (at $applied_path) and that is its file now.
     * conflict  its own copy had changed; it kept it and saved the edit beside it.
     * refused   its own check refused the edit.
     * resolved  the conflict's .from-mg file is gone: Rob merged by hand.
     *
     * $edited_at is the edit Lemur 13 acted on. If Rob saved again since,
     * his newer edit stays waiting; an applied result still moves the base,
     * so the newer edit applies on top of what was written.
     */
    public function result(
        int $user_id,
        string $path,
        string $outcome,
        string $edited_at,
        string $note,
        ?string $applied_path,
        ?string $applied_content,
        string $now_utc,
    ): bool {
        $row = $this->findByPath($user_id, $path);
        if ($row === null) {
            return false;
        }
        $id = \Field::int($row, 'jf_id');
        $newer = \Field::string($row, 'edited_at_utc') !== $edited_at;

        if ($outcome === 'applied' && $applied_path !== null && $applied_content !== null) {
            $this->pdo->prepare(
                "UPDATE journal_files
                 SET path = ?, base_content = ?, base_hash = ?, uploaded_at_utc = ?
                 WHERE jf_id = ?"
            )->execute([$applied_path, $applied_content, hash('sha256', $applied_content), $now_utc, $id]);
            if (!$newer) {
                $this->discardEdit($user_id, $id);
            } elseif (\Field::string($row, 'edit_path') === $path) {
                $this->pdo->prepare("UPDATE journal_files SET edit_path = ? WHERE jf_id = ?")
                    ->execute([$applied_path, $id]);
            }
            return true;
        }
        if ($newer) {
            return true;
        }
        if ($outcome === 'resolved') {
            $this->discardEdit($user_id, $id);
            return true;
        }
        $status = $outcome === 'conflict' ? self::STATUS_CONFLICT : self::STATUS_REFUSED;
        $this->pdo->prepare("UPDATE journal_files SET status = ?, status_note = ? WHERE jf_id = ?")
            ->execute([$status, mb_substr($note, 0, 1000), $id]);
        return true;
    }

    public function markSynced(int $user_id, string $now_utc): void
    {
        $stmt = $this->pdo->prepare("UPDATE journal_sync_runs SET synced_at_utc = ? WHERE user_id = ?");
        $stmt->execute([$now_utc, $user_id]);
        if ($stmt->rowCount() === 0) {
            $this->pdo->prepare("INSERT INTO journal_sync_runs (user_id, synced_at_utc) VALUES (?, ?)")
                ->execute([$user_id, $now_utc]);
        }
    }

    public function lastSynced(int $user_id): ?string
    {
        $stmt = $this->pdo->prepare("SELECT synced_at_utc FROM journal_sync_runs WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $v = $stmt->fetchColumn();
        return is_string($v) ? $v : null;
    }

    /** The current UTC time as stored in the DATETIME(6) columns. */
    public static function nowUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
