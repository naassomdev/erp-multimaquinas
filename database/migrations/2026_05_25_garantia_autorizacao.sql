-- ETAPA 9J-2: Número de autorização/protocolo/RMA do fabricante em os_equipamento
-- Data: 2026-05-25
-- Execução:
--   ALTER TABLE os_equipamento
--     ADD COLUMN garantia_autorizacao VARCHAR(50) NULL
--     COMMENT 'Número de autorização, protocolo ou RMA do fabricante'
--     AFTER tipo_garantia;
--
-- ROLLBACK:
--   ALTER TABLE os_equipamento DROP COLUMN garantia_autorizacao;
--
-- Observações:
--   - Campo opcional (nullable), não obrigatório
--   - Salvo em UPPERCASE (mb_strtoupper) como os demais campos técnicos (serie, voltagem, etc.)
--   - Exibido apenas quando em_garantia=1 e tipo_garantia='fabricante'
--   - Não bloqueia aprovação nem dispara alertas no fluxo comercial

ALTER TABLE os_equipamento
  ADD COLUMN garantia_autorizacao VARCHAR(50) NULL
  COMMENT 'Número de autorização, protocolo ou RMA do fabricante'
  AFTER tipo_garantia;
