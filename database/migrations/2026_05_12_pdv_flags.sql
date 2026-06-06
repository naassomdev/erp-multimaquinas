INSERT INTO configuracoes (chave, valor, updated_at) VALUES
    ('pdv_enabled', '0', NOW()),
    ('pdv_mode', 'off', NOW()),
    ('pdv_fiscal_enabled', '0', NOW()),
    ('pdv_recibo_enabled', '1', NOW())
ON DUPLICATE KEY UPDATE chave = chave;
