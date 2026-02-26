<?php

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Authenticate
$auth = \Emotional\ApiAuth::authenticate($mla_database);
if (!$auth) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or inactive API key']);
    exit;
}
['api_key_id' => $api_key_id, 'raw_key' => $rawKey] = $auth;

// Require confirmation string
$body = json_decode(file_get_contents('php://input'), true);
if (($body['confirm'] ?? '') !== 'delete everything') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or incorrect confirm field. Send {"confirm": "delete everything"}']);
    exit;
}

try {
    $mla_database->beginTransaction();

    // Count before deleting (for the response)
    $stmt = $mla_database->prepare('SELECT COUNT(*) FROM interaction_events WHERE api_key_id = ?');
    $stmt->execute([$api_key_id]);
    $event_count = (int) $stmt->fetchColumn();

    $stmt = $mla_database->prepare('SELECT COUNT(*) FROM interaction_sessions WHERE api_key_id = ?');
    $stmt->execute([$api_key_id]);
    $session_count = (int) $stmt->fetchColumn();

    $stmt = $mla_database->prepare('SELECT COUNT(*) FROM my_ids_for_my_users_state WHERE api_key_id = ?');
    $stmt->execute([$api_key_id]);
    $vocab_count = (int) $stmt->fetchColumn();

    // Delete in FK-safe order: events first, then sessions, then vocab
    $stmt = $mla_database->prepare('DELETE FROM interaction_events WHERE api_key_id = ?');
    $stmt->execute([$api_key_id]);

    $stmt = $mla_database->prepare('DELETE FROM interaction_sessions WHERE api_key_id = ?');
    $stmt->execute([$api_key_id]);

    $stmt = $mla_database->prepare('DELETE FROM my_ids_for_my_users_state WHERE api_key_id = ?');
    $stmt->execute([$api_key_id]);

    $mla_database->commit();

    echo json_encode([
        'deleted' => [
            'events'       => $event_count,
            'sessions'     => $session_count,
            'vocab_entries' => $vocab_count,
        ],
    ]);

} catch (\PDOException $e) {
    $mla_database->rollBack();
    http_response_code(500);
    print_roblog('emotional/everything DELETE failed: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Deletion failed']);
}
