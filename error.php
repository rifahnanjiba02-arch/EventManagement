<?php
require_once 'session_bootstrap.php';

$message = $_GET['msg'] ?? 'Something went wrong.';
$message = trim($message) !== '' ? $message : 'Something went wrong.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Error</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f7fb;
            --panel: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --accent: #2563eb;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: Arial, sans-serif;
            background: linear-gradient(180deg, #eef2ff 0%, var(--bg) 100%);
            color: var(--text);
        }

        .card {
            width: min(100%, 480px);
            background: var(--panel);
            border-radius: 18px;
            padding: 32px;
            box-shadow: 0 18px 45px rgba(37, 99, 235, 0.12);
            text-align: center;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 1.9rem;
        }

        p {
            margin: 0 0 24px;
            color: var(--muted);
            line-height: 1.6;
        }

        a {
            display: inline-block;
            padding: 12px 18px;
            border-radius: 999px;
            text-decoration: none;
            color: #fff;
            background: var(--accent);
            font-weight: 700;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>We hit a snag</h1>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <a href="javascript:history.back()">Go Back</a>
    </main>
</body>
</html>
