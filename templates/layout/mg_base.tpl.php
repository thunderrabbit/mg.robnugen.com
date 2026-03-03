<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'Meditation Timer - Meiso Gambare') ?></title>

    <link rel="stylesheet" href="/css/theme.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flipclock@0.7.8/compiled/flipclock.min.css">
    <link rel="stylesheet" href="/mg/css/meisogambare.css">
    <link rel="stylesheet" href="/css/menu.css">
</head>
<body class="body">
    <?php include __DIR__ . '/../partials/menu.tpl.php'; ?>

    <?= $page_content ?>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flipclock@0.7.8/compiled/flipclock.min.js"></script>
    <script src="/mg/javascript/<?= SEMVER ?>/meisoprefs.js"></script>
    <script src="/mg/javascript/<?= SEMVER ?>/meisogambare.js"></script>
</body>
</html>
