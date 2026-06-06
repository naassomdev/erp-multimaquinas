-- NFS-e Nacional: configuracao segura, rascunho/conferencia e armazenamento futuro.
-- Aditiva/idempotente. Nao transmite, nao autoriza nota real e nao altera estoque/financeiro/OS.

SET @schema_name = DATABASE();

SET @sql = (
    SELECT IF(
        COLUMN_TYPE NOT LIKE "%'rascunho'%",
        "ALTER TABLE notas_fiscais MODIFY status ENUM('rascunho','pendente','autorizada','rejeitada','cancelada','substituida','erro') NOT NULL DEFAULT 'rascunho'",
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'notas_fiscais'
      AND COLUMN_NAME = 'status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        IS_NULLABLE = 'NO',
        'ALTER TABLE notas_fiscais MODIFY lancamento_id INT UNSIGNED NULL COMMENT ''FK lancamentos_receber.id''',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'notas_fiscais'
      AND COLUMN_NAME = 'lancamento_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN orcamento_id INT UNSIGNED NULL AFTER lancamento_id', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'orcamento_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN venda_id INT UNSIGNED NULL AFTER orcamento_id', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'venda_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN cliente_id INT UNSIGNED NULL AFTER venda_id', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'cliente_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, "ALTER TABLE notas_fiscais ADD COLUMN tipo_documento VARCHAR(20) NOT NULL DEFAULT 'nfse' AFTER cliente_id", 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'tipo_documento'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, "ALTER TABLE notas_fiscais ADD COLUMN ambiente ENUM('homologacao','producao') NOT NULL DEFAULT 'homologacao' AFTER tipo_documento", 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'ambiente'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN valor_total DECIMAL(10,2) NULL AFTER ambiente', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'valor_total'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN descricao_servico TEXT NULL AFTER valor_total', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'descricao_servico'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN chave_acesso VARCHAR(60) NULL AFTER descricao_servico', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'chave_acesso'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN numero_dps VARCHAR(30) NULL AFTER numero', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'numero_dps'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN serie_dps VARCHAR(10) NULL AFTER numero_dps', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'serie_dps'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN competencia DATE NULL AFTER serie_dps', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'competencia'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN emitida_em DATETIME NULL AFTER competencia', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'emitida_em'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN xml_dps_path VARCHAR(500) NULL AFTER xml_retorno', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'xml_dps_path'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN xml_autorizado_path VARCHAR(500) NULL AFTER xml_dps_path', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'xml_autorizado_path'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN pdf_danfse_path VARCHAR(500) NULL AFTER xml_autorizado_path', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'pdf_danfse_path'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN erro_codigo VARCHAR(60) NULL AFTER protocolo', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'erro_codigo'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN erro_mensagem TEXT NULL AFTER erro_codigo', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'erro_mensagem'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN created_by INT UNSIGNED NULL AFTER erro_mensagem', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'created_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE notas_fiscais ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'updated_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'CREATE INDEX idx_nfse_orcamento ON notas_fiscais (orcamento_id)', 'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND INDEX_NAME = 'idx_nfse_orcamento'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0, 'CREATE INDEX idx_nfse_cliente ON notas_fiscais (cliente_id)', 'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'notas_fiscais' AND INDEX_NAME = 'idx_nfse_cliente'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS nota_fiscal_eventos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nota_fiscal_id INT UNSIGNED NOT NULL,
  tipo_evento VARCHAR(50) NOT NULL,
  status_anterior VARCHAR(30) NULL,
  status_novo VARCHAR(30) NULL,
  payload_path VARCHAR(500) NULL,
  retorno_json JSON NULL,
  mensagem TEXT NULL,
  usuario_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_nfev_nota (nota_fiscal_id),
  KEY idx_nfev_tipo (tipo_evento),
  KEY idx_nfev_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Eventos/auditoria técnica da NFS-e/DPS/DANFSe';

INSERT INTO configuracoes (chave, valor) VALUES
('nfse_enabled', '0'),
('nfse_ambiente', 'homologacao'),
('nfse_write_enabled', '0'),
('nfse_admin_only', '1'),
('nfse_contador_aprova_total_os', '0'),
('nfse_exigir_conferencia_manual', '1'),
('danfse_enabled', '0'),
('danfse_shadow_mode', '1'),
('danfse_admin_only', '1'),
('nfse_send_whatsapp_enabled', '0'),
('nfse_send_email_enabled', '0')
ON DUPLICATE KEY UPDATE valor = valor;
