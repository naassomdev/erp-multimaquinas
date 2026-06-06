<?php
$env = parse_ini_file('/www/wwwroot/multimaquinas.site/.env');
$pdo = new PDO("mysql:host={$env['DB_HOST']};dbname={$env['DB_DATABASE']}", $env['DB_USERNAME'], $env['DB_PASSWORD']);
$stmt = $pdo->query("SELECT * FROM lancamentos_receber WHERE id = 2");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
