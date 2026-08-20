-- Unificar 'aprovado' para 'finalizado' na tabela de retrabalhos
-- O status 'aprovado' foi deprecado para unificar com 'finalizado' na Relação do Histórico

UPDATE `retrabalhos`
SET `status` = 'finalizado'
WHERE `status` = 'aprovado';
