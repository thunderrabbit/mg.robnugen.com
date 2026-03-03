<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content=""/>
    <title><?= htmlspecialchars($page_title ?? ($is_logged_in->isLoggedIn() ? $is_logged_in->getSiteTitle() . ' Admin' : 'Meiso Gambare Admin')) ?></title>
    <link rel="stylesheet" href="/css/theme.css">
    <link rel="stylesheet" href="/css/styles.css">
    <link rel="stylesheet" href="/css/menu.css">
</head>
<body>
    <?php include __DIR__ . '/../partials/menu.tpl.php'; ?>
    <div class="PageWrapper">
        <?= $page_content ?>
    </div>
</body>
</html>
