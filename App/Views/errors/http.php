<?php
declare(strict_types=1);

use App\Core\View;

/** @var int $statusCode */
/** @var string $title */
/** @var string $message */
/** @var string $backUrl */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title) ?></title>
    <style>
        :root {
            color-scheme: light;
            --ink: #17202a;
            --muted: #5b6773;
            --border: #d7dee6;
            --soft: #f6f8fb;
            --accent: #1c6b4a;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: linear-gradient(180deg, #f8fafc 0%, #eef3f8 100%);
            color: var(--ink);
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
        }
        .error-card {
            width: min(100%, 520px);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
            padding: 28px;
        }
        .error-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 78px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(28, 107, 74, 0.1);
            color: var(--accent);
            font-weight: 700;
            letter-spacing: .04em;
            margin-bottom: 18px;
        }
        h1 {
            margin: 0 0 10px;
            font-size: 1.65rem;
            line-height: 1.2;
        }
        p {
            margin: 0;
            color: var(--muted);
            line-height: 1.55;
        }
        .error-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 24px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 16px;
            border-radius: 10px;
            border: 1px solid var(--border);
            text-decoration: none;
            color: var(--ink);
            background: var(--soft);
            font-weight: 600;
        }
        .btn-primary {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }
    </style>
</head>
<body>
    <main class="error-card">
        <div class="error-badge">Erro <?= View::e((string)$statusCode) ?></div>
        <h1><?= View::e($title) ?></h1>
        <p><?= View::e($message) ?></p>
        <div class="error-actions">
            <a href="<?= View::e($backUrl) ?>" class="btn btn-primary">Voltar</a>
            <a href="/dashboard" class="btn">Ir para o painel</a>
        </div>
    </main>
</body>
</html>
