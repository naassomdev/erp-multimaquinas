-- Fluxo de diagnostico concluido no painel tecnico.
-- Backup antes da aplicacao:
-- storage/backups/diag_fluxo_os_equip_notificacoes_20260601_2250.sql

ALTER TABLE os_equipamento
    ADD COLUMN diagnostico_concluido_em DATETIME NULL AFTER status_equip_em,
    ADD COLUMN diagnostico_concluido_por INT UNSIGNED NULL AFTER diagnostico_concluido_em;

ALTER TABLE notificacoes_tecnico
    MODIFY COLUMN tipo ENUM('aprovado','cancelado','pronto','info','descarte','diagnostico') NOT NULL DEFAULT 'info',
    ADD COLUMN destino ENUM('oficina','recepcao') NOT NULL DEFAULT 'oficina' AFTER tipo;
