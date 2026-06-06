CREATE TABLE IF NOT EXISTS job_fiscal_arquivamentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    motivo VARCHAR(500) NOT NULL,
    payload_resumo JSON NULL,
    nota_fiscal_id BIGINT UNSIGNED NULL,
    os_id VARCHAR(60) NULL,
    usuario_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_job_fiscal_arquivamentos_job (job_id),
    KEY idx_job_fiscal_arquivamentos_nota (nota_fiscal_id),
    KEY idx_job_fiscal_arquivamentos_os (os_id),
    KEY idx_job_fiscal_arquivamentos_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
