<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?? 'Meiso Gambare - Meditation Timer' ?></title>
    <script>
    (function() {
        var saved = localStorage.getItem('theme');
        if (saved === 'dark' || saved === 'light') {
            document.documentElement.setAttribute('data-theme', saved);
        }
        document.addEventListener('DOMContentLoaded', function() {
            var btn = document.getElementById('theme-toggle');
            if (!btn) return;
            function updateIcon() {
                btn.textContent = document.documentElement.getAttribute('data-theme') === 'dark' ? '\u2600\uFE0F' : '\uD83C\uDF19';
            }
            updateIcon();
            btn.addEventListener('click', function() {
                var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                var next = isDark ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', next);
                localStorage.setItem('theme', next);
                updateIcon();
            });
        });
    })();
    </script>
    <link rel="stylesheet" href="/css/theme.css">
    <link rel="stylesheet" href="/css/styles.css">
    <link rel="stylesheet" href="/css/buttons.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            line-height: 1.6;
        }
        h1 {
            color: var(--text-secondary);
            margin-bottom: 10px;
        }
        .subtitle {
            color: var(--text-muted);
            margin-top: 0;
            font-size: 1.1em;
        }
        .description {
            background: var(--bg-panel);
            padding: 20px;
            border-radius: 8px;
            margin: 30px 0;
        }
        .features {
            margin: 20px 0;
        }
        .features li {
            margin: 10px 0;
        }
        .cta {
            margin: 30px 0;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            margin: 10px 10px 10px 0;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn-primary {
            background: var(--info);
            color: var(--text-on-accent);
        }
        .btn-primary:hover {
            background: var(--info-hover);
        }
        .btn-secondary {
            background: var(--neutral);
            color: var(--text-on-accent);
        }
        .btn-secondary:hover {
            background: var(--neutral-hover);
        }
        /* Form styles */
        .form-container {
            background: var(--bg-panel);
            padding: 30px;
            border-radius: 8px;
            margin: 30px 0;
        }
        .form-row {
            margin-bottom: 20px;
        }
        .form-row label {
            display: block;
            margin-bottom: 5px;
            color: var(--text-secondary);
            font-weight: 500;
        }
        .form-row input[type="text"],
        .form-row input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-input);
            border-radius: 4px;
            font-size: 1em;
            box-sizing: border-box;
        }
        .form-row input[type="text"]:focus,
        .form-row input[type="password"]:focus {
            outline: none;
            border-color: var(--input-border-focus);
        }
        .form-row input[type="submit"] {
            background: var(--info);
            color: var(--text-on-accent);
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .form-row input[type="submit"]:hover {
            background: var(--info-hover);
        }
        .form-row input[type="submit"]:hover {
            background: var(--info-hover);
        }

    </style>
    <link rel="stylesheet" href="/css/menu.css">
</head>
<body>
    <?php include __DIR__ . '/../partials/menu.tpl.php'; ?>
    <h1>🧘 <?= $is_logged_in->isLoggedIn() ? htmlspecialchars($is_logged_in->getSiteTitle()) : 'Meiso Gambare' ?></h1>
    <p class="subtitle"><?= $is_logged_in->isLoggedIn() ? htmlspecialchars($is_logged_in->getSiteSubtitle()) : 'Your simple meditation timer' ?> v.<?= SEMVER ?></p>

    <?= $page_content ?>

    <?php if (!$is_logged_in->isLoggedIn()): ?>
        <p style="color: var(--text-muted); font-size: 0.9em; margin-top: 40px;">Free to get started • No email or credit card required</p>
    <?php elseif (!$is_logged_in->isPaid() && !$is_logged_in->isAdmin()): ?>
        <p style="color: var(--text-muted); font-size: 0.9em; margin-top: 40px;">Upgrade for multiple timers, recurring todos, and more!</p>
    <?php endif; ?>
</body>
</html>
