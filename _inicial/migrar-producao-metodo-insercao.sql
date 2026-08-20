-- Migração: Adiciona coluna metodo_insercao na tabela producao_etapas
-- Para registrar se o apontamento no Produção foi feito via Scanner ou Manualmente

ALTER TABLE producao_etapas 
ADD COLUMN metodo_insercao ENUM('scanner', 'manual') NOT NULL DEFAULT 'scanner' AFTER estacao;
