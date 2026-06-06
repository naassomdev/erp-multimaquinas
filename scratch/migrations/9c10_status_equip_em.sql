-- Etapa 9C-10: data granular de mudança de status por equipamento
-- Arquivo: scratch/migrations/9c10_status_equip_em.sql
-- Criado:  2026-05-18

-- Campo nullable; dados históricos ficam NULL (fallback usa data_conclusao/updated_at da OS).
-- Índice para ORDER BY dias_aguardando no AlertaRetiradaService.
ALTER TABLE os_equipamento
    ADD COLUMN status_equip_em DATETIME NULL DEFAULT NULL
        COMMENT 'Data/hora em que status_equip foi alterado para o valor atual'
        AFTER status_equip,
    ADD INDEX idx_status_equip_em (status_equip_em);
