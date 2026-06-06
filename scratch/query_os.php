<?php
$env = parse_ini_file('/www/wwwroot/multimaquinas.site/.env');
$pdo = new PDO("mysql:host={$env['DB_HOST']};dbname={$env['DB_DATABASE']}", $env['DB_USERNAME'], $env['DB_PASSWORD']);

echo "=== OS ===\n";
$stmt = $pdo->query("SELECT id, status, forma_pagamento, data_retirada FROM ordem_servico WHERE id = '3BUFTP-36'");
print_r($stmt->fetch(PDO::FETCH_ASSOC));

echo "\n=== EQUIPAMENTOS ===\n";
$stmt = $pdo->query("SELECT ordem_idx, status_equip, status_equip_em FROM os_equipamento WHERE os_id = '3BUFTP-36'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
