<?php

declare(strict_types=1);

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config\Settings $config
 * @var \Auth\IsLoggedIn $is_logged_in
 * @var \Security\CSRFProtectaroo $csrfProtect
 * @var int $admin_count
 */

if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

// Check if user has set up a passphrase
$matrix_config = new \Matrix\UserConfig($pdo);
$has_config = $matrix_config->getCount($user_id) > 0;

$page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Decision Matrix - Meiso Gambare");

$inner_page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);

if (!$has_config) {
    $inner_page->setTemplate("matrix/empty.tpl.php");
} else {
    // Load encrypted matrix data for client-side decryption
    $problems = new \Matrix\Problems($pdo)->getProblems($user_id);
    $opposites = new \Matrix\Opposites($pdo);
    $evidence = new \Matrix\Evidence($pdo);

    // Base64-encode blobs for JSON transport
    foreach ($problems as &$p) {
        $p['problem'] = base64_encode(\Field::string($p, 'problem'));

        $opp = $opposites->getOpposite(\Field::int($p, 'dm_mp'), $user_id);
        $p['opposite'] = $opp !== null ? ['dm_mo' => \Field::int($opp, 'dm_mo'), 'opposite' => base64_encode(\Field::string($opp, 'opposite'))] : null;

        if ($opp !== null) {
            $evs = $evidence->getEvidence(\Field::int($opp, 'dm_mo'), $user_id);
            foreach ($evs as &$e) {
                $e['evidence'] = base64_encode(\Field::string($e, 'evidence'));
            }
            unset($e);
            $p['evidence'] = $evs;
        } else {
            $p['evidence'] = [];
        }
    }
    unset($p);

    // Get passphrase verify blob
    $verify_blob = base64_encode($matrix_config->passphraseVerifyOf($user_id) ?? '');

    $inner_page->setTemplate("matrix/view.tpl.php");
    $inner_page->set("problems_json", json_encode($problems));
    $inner_page->set("verify_blob", $verify_blob);
}

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
