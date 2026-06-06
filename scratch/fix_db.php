<?php
$host = '127.0.0.1';
$db   = 'multimaquinas_erp';
$user = 'root';
$pass = '';

$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // (a) migration os_id
    $pdo->exec("ALTER TABLE lancamentos_receber MODIFY os_id VARCHAR(24) DEFAULT NULL");
    echo "lancamentos_receber updated\n";
    
    $pdo->exec("ALTER TABLE notas_fiscais MODIFY os_id VARCHAR(24) NOT NULL");
    echo "notas_fiscais updated\n";

    $pdo->exec("ALTER TABLE relatorio_faturamento_os MODIFY os_id VARCHAR(24) NOT NULL");
    echo "relatorio_faturamento_os updated\n";

    // (b) colunas do concluir
    $stmt = $pdo->query("SHOW COLUMNS FROM ordem_servico LIKE 'data_conclusao'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE ordem_servico ADD data_conclusao DATETIME DEFAULT NULL");
        echo "data_conclusao added\n";
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM ordem_servico LIKE 'operador_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE ordem_servico ADD operador_id INT UNSIGNED DEFAULT NULL");
        echo "operador_id added\n";
    }

    echo "Done.\n";
} catch (\PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
