-- Etapa 4: Adicionar 'montagem' ao ENUM status_equip de os_equipamento
-- O código PHP (TecnicoService, OsEquipamentoRepository) já usava 'montagem',
-- mas o banco aceitava apenas: aberta, andamento, pronto, cancelado.
-- Com MySQL em STRICT_TRANS_TABLES, gravar 'montagem' resultava em erro fatal.
--
-- Preserva: default 'aberta', NOT NULL, COLLATE, ordem dos valores anteriores.
-- Não adiciona 'retirado' nesta etapa (requer análise do fluxo de retirada).

ALTER TABLE os_equipamento
    MODIFY COLUMN status_equip
        ENUM('aberta','andamento','montagem','pronto','cancelado')
        COLLATE utf8mb4_unicode_ci
        NOT NULL
        DEFAULT 'aberta';
