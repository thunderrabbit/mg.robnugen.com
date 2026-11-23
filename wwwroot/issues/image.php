<?php

/**
 * Serves one issue picture.
 *
 * Reached only through the rewrite in wwwroot/.htaccess, for URLs shaped like
 *   /images/projects/<project_id>[-name]/<yyyy>/issues/<issue_id>/<filename>
 * The files are outside the web root; this sends one only to someone who can
 * read an issue the picture is linked to (Issues\Images\ImageService).
 *
 * Everything else is the same bare 404: not logged in, not a picture URL, no
 * such picture, no access, feature off. Nothing here says whether a file exists.
 */

declare(strict_types=1);

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 */

$not_found = static function (): never {
    http_response_code(404);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    $not_found();
}
if (!$is_logged_in->isLoggedIn() || (!$is_logged_in->isAdmin() && !$is_logged_in->isPaid())) {
    $not_found();
}

$user_id = $is_logged_in->loggedInID();
// The page that shows a strip asks for every picture at once. Let go of the
// session so those requests don't queue behind each other's lock.
session_write_close();

$url_path = parse_url(\Field::string($_SERVER, 'REQUEST_URI'), PHP_URL_PATH);
$path = is_string($url_path) ? \Issues\Images\ImagePath::fromUrlPath($url_path) : null;
if ($path === null) {
    $not_found();
}

$pdo      = \Database\Base::getPDO($config);
$caller   = new \Issues\PdoIssueStore($pdo)->humanCaller($user_id);
$pictures = \Issues\Images\ImageService::at($config->image_store_path, $pdo);
$image    = $caller === null ? null : $pictures->readable($caller, $path);
$type     = $image === null ? null : \Issues\Images\ImageType::fromMime($image->mimeType);
if ($image === null || $type === null) {
    $not_found();
}

$file = $pictures->absolutePath($image);
if (!is_file($file) || !is_readable($file)) {
    $not_found();
}

// Only people who may read the issue get this far, so their browser may keep
// it; shared caches may not. The session's own no-cache headers come off.
header_remove('Pragma');
header_remove('Expires');
header('Cache-Control: private, max-age=86400');
header('Content-Type: ' . $type->value);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: inline; filename="' . $image->filename . '"');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    readfile($file);
}
