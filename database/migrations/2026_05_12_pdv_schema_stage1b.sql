SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'estoque_movimentacoes'
              AND COLUMN_NAME = 'venda_id'
        ),
        'SELECT ''estoque_movimentacoes.venda_id exists''',
        'ALTER TABLE estoque_movimentacoes ADD COLUMN venda_id INT NULL'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'estoque_movimentacoes'
              AND COLUMN_NAME = 'venda_item_id'
        ),
        'SELECT ''estoque_movimentacoes.venda_item_id exists''',
        'ALTER TABLE estoque_movimentacoes ADD COLUMN venda_item_id INT NULL'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'estoque_movimentacoes'
              AND COLUMN_NAME = 'origem_tipo'
        ),
        'SELECT ''estoque_movimentacoes.origem_tipo exists''',
        'ALTER TABLE estoque_movimentacoes ADD COLUMN origem_tipo VARCHAR(30) NULL'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'estoque_movimentacoes'
              AND COLUMN_NAME = 'origem_id'
        ),
        'SELECT ''estoque_movimentacoes.origem_id exists''',
        'ALTER TABLE estoque_movimentacoes ADD COLUMN origem_id VARCHAR(50) NULL'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
