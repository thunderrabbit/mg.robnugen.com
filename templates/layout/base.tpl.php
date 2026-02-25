<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content=""/>
    <title><?= htmlspecialchars($page_title ?? ($is_logged_in->isLoggedIn() ? $is_logged_in->getSiteTitle() : 'Meiso Gambare')) ?></title>
    <link rel="stylesheet" href="/css/styles.css">
    <link rel="stylesheet" href="/css/menu.css">
    <link rel="stylesheet" href="/css/buttons.css">
    <style>
        .arrow-older {
            background-color: <?= $is_logged_in->getArrowColorOlder() ?>;
            color: <?= Utilities::getContrastColor($is_logged_in->getArrowColorOlder()) ?> !important;
        }
        .arrow-newer {
            background-color: <?= $is_logged_in->getArrowColorNewer() ?>;
            color: <?= Utilities::getContrastColor($is_logged_in->getArrowColorNewer()) ?> !important;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../partials/menu.tpl.php'; ?>
    <div class="PageWrapper">
        <?= $page_content ?>
    </div>
</body>
</html>

