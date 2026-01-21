<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?? 'Meiso Gambare - Meditation Timer' ?></title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            line-height: 1.6;
        }
        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #7f8c8d;
            margin-top: 0;
            font-size: 1.1em;
        }
        .description {
            background: #f8f9fa;
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
            background: #3498db;
            color: white;
        }
        .btn-primary:hover {
            background: #2980b9;
        }
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        .btn-secondary:hover {
            background: #7f8c8d;
        }
        /* Form styles */
        .form-container {
            background: #f8f9fa;
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
            color: #2c3e50;
            font-weight: 500;
        }
        .form-row input[type="text"],
        .form-row input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1em;
            box-sizing: border-box;
        }
        .form-row input[type="text"]:focus,
        .form-row input[type="password"]:focus {
            outline: none;
            border-color: #3498db;
        }
        .form-row input[type="submit"] {
            background: #3498db;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .form-row input[type="submit"]:hover {
            background: #2980b9;
        }
    </style>
</head>
<body>
    <h1>🧘 Meiso Gambare</h1>
    <p class="subtitle">Your simple meditation timer</p>

    <?= $page_content ?>

    <p style="color: #7f8c8d; font-size: 0.9em; margin-top: 40px;">Free to get started • No email or credit card required</p>
</body>
</html>
