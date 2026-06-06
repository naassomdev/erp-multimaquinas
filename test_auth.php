<?php
define('BASE_PATH', __DIR__);
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Database;
try {
    $pdo = Database::pdo();
    $st = $pdo->query("DESCRIBE usuarios");
    $cols = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        if ($col['Field'] === 'nivel_acesso') {
            echo "nivel_acesso type: " . $col['Type'] . "\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
