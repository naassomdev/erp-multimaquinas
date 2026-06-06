<?php
define('BASE_PATH', __DIR__);
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Database;
try {
    $pdo = Database::pdo();
    $st = $pdo->query("DESCRIBE ordem_servico");
    $cols = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c) {
        if ($c['Field'] === 'status') {
            echo "OS Status: " . $c['Type'] . "\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
