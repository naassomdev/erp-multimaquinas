<?php
declare(strict_types=1);

// O .env já deve ter sido carregado pelo autoload antes deste arquivo.
// (worker.php e public/index.php fazem isso via Dotenv antes de incluir config/database.php)
// Se por algum motivo não foi, tenta carregar aqui como fallback.
if (class_exists(Dotenv\Dotenv::class) && empty($_ENV['DB_HOST'])) {
    $envFile = dirname(__DIR__) . '/.env';
    if (file_exists($envFile)) {
        Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    }
}

// Usa as mesmas variáveis do ERP atual (ERP_DB_*) para apontar para o mesmo banco.
// Assim o erp-nfse lê exatamente o mesmo banco de dados que o outputs,/ já usa.
$dbHost = $_ENV['ERP_DB_HOST'] ?? getenv('ERP_DB_HOST') ?: '127.0.0.1';
$dbPort = '3306';
$dbName = $_ENV['ERP_DB_NAME'] ?? getenv('ERP_DB_NAME') ?: 'erp_os';
$dbUser = $_ENV['ERP_DB_USER'] ?? getenv('ERP_DB_USER') ?: 'root';
$dbPass = $_ENV['ERP_DB_PASS'] ?? getenv('ERP_DB_PASS') ?: '';

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

$pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
]);
$pdo->exec("SET time_zone = '-03:00'");
