<?php

declare(strict_types=1);

namespace Inbox;

/**
 * The agent_inbox table: messages between the actors of an account. A null
 * recipient_aiu is a broadcast to all of them.
 *
 * Who may change a message is part of each query: the sender, the recipient,
 * or a supervisor (an actor with an all-actors inbox_visibility row that can
 * read). Editing is narrower: the sender or a supervisor only. Each method
 * that changes a message takes the caller's aiu_id for that test and returns
 * how many rows changed, so 0 covers "no such message" and "not yours" alike.
 */
class Messages
{
    /** What the list may be ordered by; anything else is `newest`. */
    private const array ORDERS = [
        'newest' => 'i.created_at_utc DESC, i.message_id DESC',
        'urgent' => "FIELD(i.priority, 'high', 'normal', 'low'), i.created_at_utc DESC, i.message_id DESC",
        'oldest' => 'i.created_at_utc ASC, i.message_id ASC',
    ];

    /** The columns updateMessage() may change, in the order it writes them. */
    private const array CHANGEABLE = ['message', 'priority', 'show_date', 'recipient_aiu'];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * One page of an account's messages and how many there are in all.
     *
     * `$readable_aiu_ids` is whose inbox the caller may read: null for a
     * supervisor, who sees the whole account; otherwise the caller sees
     * messages to those actors and broadcasts, plus, when `$also_sent_by` is
     * the caller's own aiu_id, what the caller sent. `$sender_aiu` above 0
     * keeps one sender's messages. `$created_after` (inclusive) and
     * `$created_before` (exclusive) bound created_at_utc. `$status` is
     * pending, seen, done or archived; anything else is every status.
     * `$order` is newest, urgent or oldest; anything else is newest.
     *
     * @param list<int>|null $readable_aiu_ids
     * @return array{messages: list<array<mixed>>, total: int}
     */
    public function getMessages(
        int $user_id,
        ?array $readable_aiu_ids,
        ?int $also_sent_by,
        int $sender_aiu,
        ?string $created_after,
        ?string $created_before,
        bool $include_archived,
        bool $include_future,
        string $status,
        string $order,
        int $limit,
        int $offset
    ): array {
        $where = ['i.user_id = ?'];
        $params = [$user_id];

        if ($readable_aiu_ids !== null) {
            // Scoped: see messages addressed to readable peers + broadcasts.
            if ($readable_aiu_ids === []) {
                // No visibility rows with can_read — see only broadcasts
                $vis_filter = 'i.recipient_aiu IS NULL';
            } else {
                $placeholders = implode(',', array_fill(0, count($readable_aiu_ids), '?'));
                $vis_filter = "(i.recipient_aiu IN ({$placeholders}) OR i.recipient_aiu IS NULL)";
                $params = array_merge($params, $readable_aiu_ids);
            }
            // Optional: include messages the caller sent
            if ($also_sent_by !== null) {
                $vis_filter = "({$vis_filter} OR i.sender_aiu = ?)";
                $params[] = $also_sent_by;
            }
            $where[] = $vis_filter;
        }
        // Supervisors skip visibility filter — they see everything

        if ($sender_aiu > 0) {
            $where[] = 'i.sender_aiu = ?';
            $params[] = $sender_aiu;
        }

        // Half-open UTC window on when the message was made: [after, before)
        if ($created_after !== null) {
            $where[] = 'i.created_at_utc >= ?';
            $params[] = $created_after;
        }
        if ($created_before !== null) {
            $where[] = 'i.created_at_utc < ?';
            $params[] = $created_before;
        }

        if (!$include_archived) {
            $where[] = 'i.archived_at IS NULL';
        }

        if (!$include_future) {
            $where[] = '(i.show_date IS NULL OR i.show_date <= NOW())';
        }

        if ($status === 'pending') {
            $where[] = 'i.seen_at IS NULL AND i.done_at IS NULL';
        } elseif ($status === 'seen') {
            $where[] = 'i.seen_at IS NOT NULL AND i.done_at IS NULL';
        } elseif ($status === 'done') {
            $where[] = 'i.done_at IS NOT NULL';
        } elseif ($status === 'archived') {
            $where[] = 'i.archived_at IS NOT NULL';
        }

        $where_sql = implode(' AND ', $where);
        $order_clause = self::ORDERS[$order] ?? self::ORDERS['newest'];

        $stmt = $this->pdo->prepare(
            "SELECT message_id, message, priority, show_date, sender_timezone, sender_aiu, recipient_aiu, seen_at, done_at, archived_at, response, created_at_utc, updated_at_utc
             FROM agent_inbox i
             WHERE {$where_sql}
             ORDER BY {$order_clause}
             LIMIT ? OFFSET ?"
        );
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);

        $count_stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM agent_inbox i WHERE {$where_sql}"
        );
        $count_stmt->execute(array_slice($params, 0, -2));
        $total = (int) $count_stmt->fetchColumn();

        return [
            'messages' => \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC)),
            'total' => $total,
        ];
    }

    /**
     * Adds a message and returns its message_id. A null recipient is a
     * broadcast.
     */
    public function addMessage(
        int $user_id,
        string $message,
        string $priority,
        ?string $show_date,
        ?string $sender_timezone,
        int $sender_aiu,
        ?int $recipient_aiu
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO agent_inbox (user_id, message, priority, show_date, sender_timezone, sender_aiu, recipient_aiu)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$user_id, $message, $priority, $show_date, $sender_timezone, $sender_aiu, $recipient_aiu]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Marks an unseen message as seen now.
     */
    public function markSeen(int $message_id, int $user_id, int $caller_aiu): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE agent_inbox SET seen_at = NOW()
             WHERE message_id = ? AND user_id = ? AND seen_at IS NULL
               AND (sender_aiu = ? OR recipient_aiu = ? OR EXISTS (
                    SELECT 1 FROM inbox_visibility
                    WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id IS NULL AND can_read = 1))"
        );
        $stmt->execute([$message_id, $user_id, $caller_aiu, $caller_aiu, $caller_aiu]);
        return $stmt->rowCount();
    }

    /**
     * Marks a message that is not done as done now, and as seen now if it
     * was not seen. A `$response` that is not empty is stored with it.
     */
    public function markDone(int $message_id, int $user_id, int $caller_aiu, string $response): int
    {
        $sql = "UPDATE agent_inbox SET done_at = NOW(), seen_at = COALESCE(seen_at, NOW())";
        $params = [];
        if ($response !== '') {
            $sql .= ", response = ?";
            $params[] = $response;
        }
        $sql .= " WHERE message_id = ? AND user_id = ? AND done_at IS NULL
                  AND (sender_aiu = ? OR recipient_aiu = ? OR EXISTS (
                       SELECT 1 FROM inbox_visibility
                       WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id IS NULL AND can_read = 1))";
        $params[] = $message_id;
        $params[] = $user_id;
        $params[] = $caller_aiu;
        $params[] = $caller_aiu;
        $params[] = $caller_aiu;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Marks every unseen message among these ids as seen now.
     *
     * @param array<int> $message_ids at least one
     */
    public function markSeenBulk(array $message_ids, int $user_id, int $caller_aiu): int
    {
        $placeholders = implode(',', array_fill(0, count($message_ids), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE agent_inbox SET seen_at = NOW()
             WHERE message_id IN ({$placeholders}) AND user_id = ? AND seen_at IS NULL
               AND (sender_aiu = ? OR recipient_aiu = ? OR EXISTS (
                    SELECT 1 FROM inbox_visibility
                    WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id IS NULL AND can_read = 1))"
        );
        $stmt->execute([...$message_ids, $user_id, $caller_aiu, $caller_aiu, $caller_aiu]);
        return $stmt->rowCount();
    }

    /**
     * Changes a message's text, priority, show date and/or recipient. Only
     * the sender or a supervisor may: otherwise any actor that can write to
     * the inbox could rewrite a message that still shows someone else as its
     * sender. A null `show_date` clears the date and a null `recipient_aiu`
     * makes the message a broadcast.
     *
     * @param array<string, string|int|null> $changes only `message`, `priority`, `show_date`, `recipient_aiu`
     * @throws \InvalidArgumentException for no changes, or for any other column
     */
    public function updateMessage(int $message_id, int $user_id, int $caller_aiu, array $changes): int
    {
        $unknown = array_diff(array_keys($changes), self::CHANGEABLE);
        if ($changes === [] || $unknown !== []) {
            throw new \InvalidArgumentException(
                'A message update takes message, priority, show_date and/or recipient_aiu.'
            );
        }

        $sets = [];
        $params = [];
        foreach (self::CHANGEABLE as $column) {
            if (!array_key_exists($column, $changes)) {
                continue;
            }
            if ($changes[$column] === null) {
                $sets[] = $column . ' = NULL';
            } else {
                $sets[] = $column . ' = ?';
                $params[] = $changes[$column];
            }
        }

        $set_sql = implode(', ', $sets);
        $params[] = $message_id;
        $params[] = $user_id;
        $params[] = $caller_aiu;
        $params[] = $caller_aiu;

        $stmt = $this->pdo->prepare(
            "UPDATE agent_inbox SET {$set_sql}
             WHERE message_id = ? AND user_id = ?
               AND (sender_aiu = ? OR EXISTS (
                    SELECT 1 FROM inbox_visibility
                    WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id IS NULL AND can_read = 1))"
        );
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Archives a message that is not archived yet.
     */
    public function archive(int $message_id, int $user_id, int $caller_aiu): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE agent_inbox SET archived_at = NOW()
             WHERE message_id = ? AND user_id = ? AND archived_at IS NULL
               AND (sender_aiu = ? OR recipient_aiu = ? OR EXISTS (
                    SELECT 1 FROM inbox_visibility
                    WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id IS NULL AND can_read = 1))"
        );
        $stmt->execute([$message_id, $user_id, $caller_aiu, $caller_aiu, $caller_aiu]);
        return $stmt->rowCount();
    }

    /**
     * Deletes a message for good.
     */
    public function delete(int $message_id, int $user_id, int $caller_aiu): int
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM agent_inbox
             WHERE message_id = ? AND user_id = ?
               AND (sender_aiu = ? OR recipient_aiu = ? OR EXISTS (
                    SELECT 1 FROM inbox_visibility
                    WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id IS NULL AND can_read = 1))"
        );
        $stmt->execute([$message_id, $user_id, $caller_aiu, $caller_aiu, $caller_aiu]);
        return $stmt->rowCount();
    }

    /**
     * Puts a seen message that is not done back to unseen.
     */
    public function markUnseen(int $message_id, int $user_id, int $caller_aiu): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE agent_inbox SET seen_at = NULL
             WHERE message_id = ? AND user_id = ? AND done_at IS NULL
               AND (sender_aiu = ? OR recipient_aiu = ? OR EXISTS (
                    SELECT 1 FROM inbox_visibility
                    WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id IS NULL AND can_read = 1))"
        );
        $stmt->execute([$message_id, $user_id, $caller_aiu, $caller_aiu, $caller_aiu]);
        return $stmt->rowCount();
    }
}
