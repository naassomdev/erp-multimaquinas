-- Migration: os_equipamento — ADD fabricante, modelo
-- Etapa 9E-1 do alinhamento operacional Multimáquinas
-- Data: 2026-05-21
--
-- Objetivo:
--   Adicionar campos formais de fabricante e modelo em os_equipamento.
--   Atualmente a marca está embutida no campo `nome` como texto livre,
--   ex: "MARTELETE MAKITA HR5210.C 10KG". Esta migration cria estrutura
--   para separar essa informação sem tocar nos dados existentes.
--
-- Campos adicionados:
--   fabricante — marca/fabricante do equipamento (ex: MAKITA, BOSCH, DEWALT)
--   modelo     — modelo comercial/técnico (ex: HR5210.C, GWS 22-230)
--
-- Comportamento para dados históricos:
--   Ambos os campos iniciam com DEFAULT '' (string vazia).
--   Nenhuma linha existente é preenchida automaticamente.
--   O preenchimento será feito pelos operadores via formulários,
--   a partir das etapas 9E-2 (formulário de abertura de OS) e 9E-3
--   (edição no detalhe da OS).
--
-- Observações:
--   - NOT NULL DEFAULT '' — sem valores NULL; string vazia indica "não preenchido"
--   - Sem FK, sem tabela fabricantes, sem índice (desnecessário nesta etapa)
--   - Collation herdada da tabela (utf8mb4_unicode_ci)
--   - Impacto em tempo de execução: ALTER instantâneo no MySQL 8+ (online DDL)
--
-- Rollback (se necessário):
--   ALTER TABLE os_equipamento
--     DROP COLUMN fabricante,
--     DROP COLUMN modelo;

ALTER TABLE os_equipamento
  ADD COLUMN fabricante VARCHAR(120) NOT NULL DEFAULT ''
    COMMENT 'Fabricante/marca do equipamento, ex: MAKITA, BOSCH, DEWALT'
    AFTER nome,
  ADD COLUMN modelo VARCHAR(120) NOT NULL DEFAULT ''
    COMMENT 'Modelo do equipamento, ex: HR5210.C, GWS 22-230'
    AFTER fabricante;
