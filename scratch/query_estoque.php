<?php
$env = parse_ini_file('/www/wwwroot/multimaquinas.site/.env');
$pdo = new PDO("mysql:host={$env['DB_HOST']};dbname={$env['DB_DATABASE']}", $env['DB_USERNAME'], $env['DB_PASSWORD']);
$stmt = $pdo->query("SELECT * FROM estoque_movimentacoes WHERE os_id = '3BUFTP-36'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
