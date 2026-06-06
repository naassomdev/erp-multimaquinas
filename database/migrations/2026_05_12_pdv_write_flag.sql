INSERT INTO configuracoes (chave, valor, updated_at) VALUES
    ('pdv_write_enabled', '0', NOW())
ON DUPLICATE KEY UPDATE chave = chave;
