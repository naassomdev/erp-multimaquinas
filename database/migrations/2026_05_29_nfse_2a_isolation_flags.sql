-- NFS-e 2A: flags de isolamento da integração fiscal antiga.
-- Aditiva/idempotente. Nao remove SDK antigo, nao transmite e nao altera documentos.

INSERT INTO configuracoes (chave, valor) VALUES
('danfse_external_download_enabled', '0')
ON DUPLICATE KEY UPDATE valor = valor;
