<?php
define('BASE_PATH', __DIR__);
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Database;
try {
    $pdo = Database::pdo();
    $st = $pdo->query("SHOW TRIGGERS LIKE 'os_equipamento'");
    $triggers = $st->fetchAll(PDO::FETCH_ASSOC);
    if (empty($triggers)) echo "No triggers found.\n";
    foreach ($triggers as $t) {
        echo "Trigger: " . $t['Trigger'] . "\n";
        echo "Statement: " . $t['Statement'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
