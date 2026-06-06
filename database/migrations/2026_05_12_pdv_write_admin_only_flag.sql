INSERT INTO configuracoes (chave, valor, updated_at) VALUES
    ('pdv_write_admin_only', '1', NOW())
ON DUPLICATE KEY UPDATE chave = chave;
