<?php

/**
 * Bootstrap an aiu's web-login password (issue #122).
 *
 * Usage: php scripts/set_aiu_password.php <aiu_id>
 *
 * Prompts twice (no echo), enforces min length, hashes with Argon2id, writes
 * to agent_inbox_user.password_hash. Run on the DreamHost server by Rob.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

if ($argc !== 2 || !ctype_digit($argv[1])) {
    fwrite(STDERR, "Usage: php scripts/set_aiu_password.php <aiu_id>\n");
    exit(1);
}
$aiu_id = (int)$argv[1];

require_once __DIR__ . '/../classes/Mlaphp/Autoloader.php';
$autoloader = new \Mlaphp\Autoloader();
spl_autoload_register([$autoloader, 'load']);

$config = new \Config();
$pdo = \Database\Base::getPDO($config);

$stmt = $pdo->prepare("SELECT user_id, name, actor_type FROM agent_inbox_user WHERE aiu_id = ? LIMIT 1");
$stmt->execute([$aiu_id]);
$row = $stmt->fetch();
if (!$row) {
    fwrite(STDERR, "No agent_inbox_user row with aiu_id={$aiu_id}.\n");
    exit(1);
}

echo "Setting web-login password for:\n";
echo "  aiu_id     : {$aiu_id}\n";
echo "  name       : {$row['name']}\n";
echo "  user_id    : {$row['user_id']}\n";
echo "  actor_type : {$row['actor_type']}\n";

$min = \Auth\IsLoggedIn::AIU_MIN_PASSWORD_LENGTH;
echo "\nPassword must be at least {$min} characters.\n";

function prompt_no_echo(string $msg): string {
    echo $msg;
    system('stty -echo');
    $val = fgets(STDIN);
    system('stty echo');
    echo "\n";
    return $val === false ? '' : rtrim($val, "\r\n");
}

$pw1 = prompt_no_echo("Password: ");
if (strlen($pw1) < $min) {
    fwrite(STDERR, "Password too short (got " . strlen($pw1) . ", need >= {$min}).\n");
    exit(1);
}
$pw2 = prompt_no_echo("Confirm: ");
if (!hash_equals($pw1, $pw2)) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$hash = password_hash($pw1, PASSWORD_ARGON2ID);
if ($hash === false) {
    fwrite(STDERR, "password_hash() failed.\n");
    exit(1);
}

$upd = $pdo->prepare("UPDATE agent_inbox_user SET password_hash = ? WHERE aiu_id = ?");
$upd->execute([$hash, $aiu_id]);

echo "Updated agent_inbox_user.password_hash for aiu_id={$aiu_id}. Hash length: " . strlen($hash) . "\n";
