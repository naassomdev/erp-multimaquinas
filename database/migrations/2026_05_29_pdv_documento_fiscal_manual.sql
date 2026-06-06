-- Ponte fiscal manual do PDV: registro de documentos emitidos externamente.
-- Aditiva e idempotente; não emite, não transmite e não altera documentos existentes.

SET @schema_name = DATABASE();

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE venda_documentos ADD COLUMN modelo VARCHAR(10) NULL AFTER tipo_documento',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'venda_documentos'
      AND COLUMN_NAME = 'modelo'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE venda_documentos ADD COLUMN os_id VARCHAR(24) NULL AFTER venda_id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'venda_documentos'
      AND COLUMN_NAME = 'os_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE venda_documentos ADD COLUMN orcamento_id INT UNSIGNED NULL AFTER os_id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'venda_documentos'
      AND COLUMN_NAME = 'orcamento_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE venda_documentos ADD COLUMN valor DECIMAL(10,2) NULL AFTER protocolo',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'venda_documentos'
      AND COLUMN_NAME = 'valor'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE venda_documentos ADD COLUMN link_consulta VARCHAR(500) NULL AFTER valor',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'venda_documentos'
      AND COLUMN_NAME = 'link_consulta'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE venda_documentos ADD COLUMN emitido_externamente TINYINT(1) NOT NULL DEFAULT 1 AFTER link_consulta',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'venda_documentos'
      AND COLUMN_NAME = 'emitido_externamente'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE venda_documentos ADD COLUMN observacoes TEXT NULL AFTER emitido_externamente',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'venda_documentos'
      AND COLUMN_NAME = 'observacoes'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'CREATE INDEX idx_venda_documentos_os ON venda_documentos (os_id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'venda_documentos'
      AND INDEX_NAME = 'idx_venda_documentos_os'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'CREATE INDEX idx_venda_documentos_orcamento ON venda_documentos (orcamento_id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'venda_documentos'
      AND INDEX_NAME = 'idx_venda_documentos_orcamento'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'CREATE INDEX idx_venda_documentos_modelo ON venda_documentos (modelo)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'venda_documentos'
      AND INDEX_NAME = 'idx_venda_documentos_modelo'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'CREATE UNIQUE INDEX uq_venda_documentos_chave_acesso ON venda_documentos (chave_acesso)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'venda_documentos'
      AND INDEX_NAME = 'uq_venda_documentos_chave_acesso'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
