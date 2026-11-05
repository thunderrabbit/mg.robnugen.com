<?php

/**
 * Run on mg's server, once: apply the journal migration if it is pending,
 * make the "Journal Sync" actor (journal_sync = 1, no other permission), and
 * print a fresh API key for it on STDOUT.
 *
 *     ssh mg 'cd mg.robnugen.com && /usr/local/php85/bin/php tools/journal-sync/make_sync_key.php --user-id=1' \
 *         > ~/.config/journal-sync/mg.key      # with umask 077
 *
 * The server's plain `php` is 8.2; mg needs 8.5, hence the full path.
 *
 * Only the key goes to STDOUT, so it can be redirected straight into the key
 * file on Lemur 13 without being shown anywhere; progress goes to STDERR.
 * The key belongs to the admin account given (required when there are several). Running it again mints another
 * key (mg allows two active per actor); revoke the old one in Settings.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../../classes/Mlaphp/Autoloader.php';
spl_autoload_register([new \Mlaphp\Autoloader(), 'load']);

$say = static function (string $line): void {
    fwrite(STDERR, "$line\n");
};

$config = new \Config();
$pdo = \Database\Base::getPDO($config);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$schema = new \Database\DBExistaroo($config, $pdo);
foreach ($schema->getPendingMigrations() as $migration) {
    if (str_starts_with($migration, '44_journal_files/')) {
        $schema->applyMigration($migration);
        $say("applied $migration");
    }
}

$options = getopt('', ['user-id:']);
$admins = $pdo->query("SELECT user_id FROM users WHERE role = 'admin' ORDER BY user_id");
$admin_ids = array_map(\Field::toInt(...), $admins === false ? [] : $admins->fetchAll(\PDO::FETCH_COLUMN));
$wanted = is_array($options) ? \Field::int($options, 'user-id') : 0;
if ($wanted !== 0) {
    if (!in_array($wanted, $admin_ids, true)) {
        $say("REFUSED: user $wanted is not an admin account");
        exit(1);
    }
    $user_id = $wanted;
} elseif (count($admin_ids) === 1) {
    $user_id = $admin_ids[0];
} else {
    $say('REFUSED: ' . count($admin_ids) . ' admin accounts; name one with --user-id=N');
    exit(1);
}

$find = $pdo->prepare("SELECT aiu_id FROM agent_inbox_user WHERE user_id = ? AND name = 'Journal Sync'");
$find->execute([$user_id]);
$aiu_id = \Field::toInt($find->fetchColumn());
if ($aiu_id === 0) {
    $pdo->prepare(
        "INSERT INTO agent_inbox_user (user_id, name, description, actor_type, journal_sync)
         VALUES (?, 'Journal Sync', 'Lemur 13: mirrors recent journal entries for editing at /journal', 'agent', 1)"
    )->execute([$user_id]);
    $aiu_id = \Field::toInt($pdo->lastInsertId());
    $say("made actor Journal Sync (aiu $aiu_id)");
} else {
    $pdo->prepare("UPDATE agent_inbox_user SET journal_sync = 1 WHERE aiu_id = ?")->execute([$aiu_id]);
    $say("actor Journal Sync exists (aiu $aiu_id)");
}

echo (new \Auth\ApiKey($pdo))->generateKey($user_id, 'Lemur 13 journal sync', $aiu_id), "\n";
$say('minted a key; it is on STDOUT');
