-- Etapa 6: adiciona 'retirado' ao ENUM status_equip de os_equipamento
-- Executado em: 2026-05-14
-- Contexto: na retirada da OS, os equipamentos devem receber status 'retirado'
--           para diferenciar de 'pronto' (aguardando retirada) e 'cancelado'.

ALTER TABLE os_equipamento
  MODIFY COLUMN status_equip
    ENUM('aberta','andamento','montagem','pronto','cancelado','retirado')
    NOT NULL DEFAULT 'aberta';
