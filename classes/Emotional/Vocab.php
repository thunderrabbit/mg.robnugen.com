<?php

declare(strict_types=1);

namespace Emotional;

/**
 * The my_ids_for_my_users_state table: an API key's private vocabulary of
 * states. `my_id` is the key's own random handle for a state, `mifmus_id` the
 * table's row id, and `state` is stored encrypted: this class takes and
 * returns the ciphertext, and Ledger does the encrypting.
 */
class Vocab
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Adds a state under the handle the caller chose. A handle the key
     * already uses throws the PDOException of the unique index (code 23000),
     * which the caller answers by trying another handle.
     */
    public function addEntry(int $api_key_id, int $my_id, string $encrypted_state): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO my_ids_for_my_users_state (api_key_id, my_id, state) VALUES (?, ?, ?)'
        );
        $stmt->execute([$api_key_id, $my_id, $encrypted_state]);
    }

    /**
     * Every state of a key, as `my_id` and the still encrypted `state`.
     *
     * @return list<array<mixed>>
     */
    public function getEntries(int $api_key_id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT my_id, state FROM my_ids_for_my_users_state WHERE api_key_id = ?'
        );
        $stmt->execute([$api_key_id]);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Replaces the state behind a handle. False when the key has no such
     * handle.
     */
    public function updateState(int $api_key_id, int $my_id, string $encrypted_state): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE my_ids_for_my_users_state SET state = ? WHERE api_key_id = ? AND my_id = ?'
        );
        $stmt->execute([$encrypted_state, $api_key_id, $my_id]);
        return $stmt->rowCount() !== 0;
    }

    /**
     * The row id behind a key's handle, or null when the key has no such
     * handle.
     */
    public function mifmusIdOf(int $api_key_id, int $my_id): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT mifmus_id FROM my_ids_for_my_users_state WHERE api_key_id = ? AND my_id = ?'
        );
        $stmt->execute([$api_key_id, $my_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return \Field::int($row, 'mifmus_id');
    }

    /**
     * Deletes one state by its row id. The foreign key on interaction_events
     * (ON DELETE SET NULL) untags the events that carried it.
     */
    public function deleteEntry(int $mifmus_id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM my_ids_for_my_users_state WHERE mifmus_id = ?');
        $stmt->execute([$mifmus_id]);
    }

    /**
     * How many states a key has.
     */
    public function getCount(int $api_key_id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM my_ids_for_my_users_state WHERE api_key_id = ?');
        $stmt->execute([$api_key_id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Deletes every state of a key.
     */
    public function deleteAll(int $api_key_id): void
    {
        $this->pdo->prepare('DELETE FROM my_ids_for_my_users_state WHERE api_key_id = ?')->execute([$api_key_id]);
    }
}
