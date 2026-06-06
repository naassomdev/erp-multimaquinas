SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_balcao' AND COLUMN_NAME = 'os_id'),
        'SELECT ''vendas_balcao.os_id exists''',
        'ALTER TABLE vendas_balcao ADD COLUMN os_id VARCHAR(24) NULL AFTER cliente_id'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_balcao' AND COLUMN_NAME = 'origem_tipo'),
        'SELECT ''vendas_balcao.origem_tipo exists''',
        'ALTER TABLE vendas_balcao ADD COLUMN origem_tipo VARCHAR(30) NULL AFTER os_id'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_balcao' AND COLUMN_NAME = 'lancamento_receber_id'),
        'SELECT ''vendas_balcao.lancamento_receber_id exists''',
        'ALTER TABLE vendas_balcao ADD COLUMN lancamento_receber_id INT UNSIGNED NULL AFTER origem_tipo'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_balcao' AND COLUMN_NAME = 'status_venda'),
        'SELECT ''vendas_balcao.status_venda exists''',
        'ALTER TABLE vendas_balcao ADD COLUMN status_venda VARCHAR(30) NULL AFTER lancamento_receber_id'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_balcao' AND COLUMN_NAME = 'status_fiscal'),
        'SELECT ''vendas_balcao.status_fiscal exists''',
        'ALTER TABLE vendas_balcao ADD COLUMN status_fiscal VARCHAR(30) NULL AFTER status_venda'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_balcao' AND COLUMN_NAME = 'total_bruto'),
        'SELECT ''vendas_balcao.total_bruto exists''',
        'ALTER TABLE vendas_balcao ADD COLUMN total_bruto DECIMAL(10,2) NULL AFTER status_fiscal'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_balcao' AND COLUMN_NAME = 'total_desconto'),
        'SELECT ''vendas_balcao.total_desconto exists''',
        'ALTER TABLE vendas_balcao ADD COLUMN total_desconto DECIMAL(10,2) NULL AFTER total_bruto'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_balcao' AND COLUMN_NAME = 'total_acrescimo'),
        'SELECT ''vendas_balcao.total_acrescimo exists''',
        'ALTER TABLE vendas_balcao ADD COLUMN total_acrescimo DECIMAL(10,2) NULL AFTER total_desconto'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_balcao' AND COLUMN_NAME = 'total_liquido'),
        'SELECT ''vendas_balcao.total_liquido exists''',
        'ALTER TABLE vendas_balcao ADD COLUMN total_liquido DECIMAL(10,2) NULL AFTER total_acrescimo'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_balcao' AND COLUMN_NAME = 'observacoes'),
        'SELECT ''vendas_balcao.observacoes exists''',
        'ALTER TABLE vendas_balcao ADD COLUMN observacoes TEXT NULL AFTER total_liquido'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_balcao' AND COLUMN_NAME = 'created_by'),
        'SELECT ''vendas_balcao.created_by exists''',
        'ALTER TABLE vendas_balcao ADD COLUMN created_by INT UNSIGNED NULL AFTER observacoes'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_balcao' AND COLUMN_NAME = 'updated_by'),
        'SELECT ''vendas_balcao.updated_by exists''',
        'ALTER TABLE vendas_balcao ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_balcao' AND COLUMN_NAME = 'cancelled_at'),
        'SELECT ''vendas_balcao.cancelled_at exists''',
        'ALTER TABLE vendas_balcao ADD COLUMN cancelled_at DATETIME NULL AFTER updated_by'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_balcao' AND COLUMN_NAME = 'cancel_reason'),
        'SELECT ''vendas_balcao.cancel_reason exists''',
        'ALTER TABLE vendas_balcao ADD COLUMN cancel_reason VARCHAR(500) NULL AFTER cancelled_at'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_itens' AND COLUMN_NAME = 'tecnico_item_id'),
        'SELECT ''vendas_itens.tecnico_item_id exists''',
        'ALTER TABLE vendas_itens ADD COLUMN tecnico_item_id INT UNSIGNED NULL AFTER produto_id'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_itens' AND COLUMN_NAME = 'orcamento_item_id'),
        'SELECT ''vendas_itens.orcamento_item_id exists''',
        'ALTER TABLE vendas_itens ADD COLUMN orcamento_item_id INT UNSIGNED NULL AFTER tecnico_item_id'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_itens' AND COLUMN_NAME = 'origem_tipo'),
        'SELECT ''vendas_itens.origem_tipo exists''',
        'ALTER TABLE vendas_itens ADD COLUMN origem_tipo VARCHAR(30) NULL AFTER orcamento_item_id'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_itens' AND COLUMN_NAME = 'origem_id'),
        'SELECT ''vendas_itens.origem_id exists''',
        'ALTER TABLE vendas_itens ADD COLUMN origem_id VARCHAR(50) NULL AFTER origem_tipo'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_itens' AND COLUMN_NAME = 'desconto'),
        'SELECT ''vendas_itens.desconto exists''',
        'ALTER TABLE vendas_itens ADD COLUMN desconto DECIMAL(10,2) NULL AFTER subtotal'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_itens' AND COLUMN_NAME = 'acrescimo'),
        'SELECT ''vendas_itens.acrescimo exists''',
        'ALTER TABLE vendas_itens ADD COLUMN acrescimo DECIMAL(10,2) NULL AFTER desconto'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_itens' AND COLUMN_NAME = 'total_liquido'),
        'SELECT ''vendas_itens.total_liquido exists''',
        'ALTER TABLE vendas_itens ADD COLUMN total_liquido DECIMAL(10,2) NULL AFTER acrescimo'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas_itens' AND COLUMN_NAME = 'estoque_movimentacao_id'),
        'SELECT ''vendas_itens.estoque_movimentacao_id exists''',
        'ALTER TABLE vendas_itens ADD COLUMN estoque_movimentacao_id BIGINT UNSIGNED NULL AFTER total_liquido'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS venda_pagamentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    venda_id INT NOT NULL,
    forma_pagamento VARCHAR(30) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pendente',
    valor DECIMAL(10,2) NOT NULL,
    parcelas SMALLINT UNSIGNED NULL,
    referencia_externa VARCHAR(100) NULL,
    payload_json JSON NULL,
    pago_em DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_venda_pagamentos_venda (venda_id),
    KEY idx_venda_pagamentos_forma (forma_pagamento),
    KEY idx_venda_pagamentos_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS venda_documentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    venda_id INT NOT NULL,
    categoria VARCHAR(20) NOT NULL,
    tipo_documento VARCHAR(40) NOT NULL,
    status VARCHAR(30) NOT NULL,
    numero VARCHAR(30) NULL,
    serie VARCHAR(10) NULL,
    chave_acesso VARCHAR(60) NULL,
    protocolo VARCHAR(100) NULL,
    xml_path VARCHAR(500) NULL,
    pdf_path VARCHAR(500) NULL,
    payload_json JSON NULL,
    mensagem_retorno TEXT NULL,
    data_emissao DATETIME NULL,
    data_cancelamento DATETIME NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_venda_documentos_venda (venda_id),
    KEY idx_venda_documentos_categoria (categoria),
    KEY idx_venda_documentos_tipo (tipo_documento),
    KEY idx_venda_documentos_status (status),
    KEY idx_venda_documentos_chave (chave_acesso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
