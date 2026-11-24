<?php

declare(strict_types=1);

namespace Inbox;

/**
 * The inbox_visibility table: which actor may read or send to which other
 * actor's inbox. A row whose inbox_peer_aiu_id is NULL covers every actor;
 * with can_read = 1 it makes its owner a supervisor.
 */
class Visibility
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Whether an actor may read every inbox, which is what lets it manage
     * agents.
     */
    public function isSupervisor(int $aiu_id): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM inbox_visibility
             WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id IS NULL AND can_read = 1"
        );
        $stmt->execute([$aiu_id]);
        return $stmt->fetch() !== false;
    }

    /**
     * Lets a new actor read its own messages.
     */
    public function addOwnRow(int $aiu_id): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO inbox_visibility (inbox_user_aiu_id, inbox_peer_aiu_id, can_read, can_send) VALUES (?, ?, 1, 0)"
        );
        $stmt->execute([$aiu_id, $aiu_id]);
    }

    /**
     * Lets an actor send to a peer, without reading the peer's inbox.
     */
    public function addSendRow(int $aiu_id, int $peer_aiu_id): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO inbox_visibility (inbox_user_aiu_id, inbox_peer_aiu_id, can_read, can_send) VALUES (?, ?, 0, 1)"
        );
        $stmt->execute([$aiu_id, $peer_aiu_id]);
    }

    /**
     * Whether a row exists from an actor to a peer, in that direction.
     */
    public function hasRow(int $aiu_id, int $peer_aiu_id): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM inbox_visibility WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id = ?"
        );
        $stmt->execute([$aiu_id, $peer_aiu_id]);
        return $stmt->fetch() !== false;
    }

    /**
     * Sets the two bits of the row from an actor to a peer.
     */
    public function updateRow(int $aiu_id, int $peer_aiu_id, int $can_read, int $can_send): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE inbox_visibility SET can_read = ?, can_send = ?
             WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id = ?"
        );
        $stmt->execute([$can_read, $can_send, $aiu_id, $peer_aiu_id]);
    }

    /**
     * Adds a row from an actor to a peer with its two bits.
     */
    public function addRow(int $aiu_id, int $peer_aiu_id, int $can_read, int $can_send): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO inbox_visibility (inbox_user_aiu_id, inbox_peer_aiu_id, can_read, can_send)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$aiu_id, $peer_aiu_id, $can_read, $can_send]);
    }
}
