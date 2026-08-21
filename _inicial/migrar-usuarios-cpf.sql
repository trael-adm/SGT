-- Migração: Adiciona campo CPF na tabela de usuários
-- Idempotente

ALTER TABLE usuarios 
ADD COLUMN IF NOT EXISTS cpf VARCHAR(14) NULL DEFAULT NULL AFTER email;
