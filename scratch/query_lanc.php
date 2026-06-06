<?php
$env = parse_ini_file('/www/wwwroot/multimaquinas.site/.env');
$pdo = new PDO("mysql:host={$env['DB_HOST']};dbname={$env['DB_DATABASE']}", $env['DB_USERNAME'], $env['DB_PASSWORD']);
$stmt = $pdo->query("SELECT id, status, forma_pagamento, valor, valor_pago, criado_em, data_pagamento FROM lancamentos_receber WHERE os_id = '3BUFTP-36'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
