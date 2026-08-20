-- Migração: admin-v2
-- Atualiza a tabela setores para suportar as novas propriedades da interface de Administração

ALTER TABLE setores 
ADD COLUMN cod VARCHAR(10) NULL AFTER id,
ADD COLUMN resp VARCHAR(100) NULL AFTER nome,
ADD COLUMN cc VARCHAR(20) NULL AFTER resp,
ADD COLUMN turnos JSON NULL AFTER cc,
ADD COLUMN perfil VARCHAR(20) NULL AFTER turnos,
ADD COLUMN status VARCHAR(20) DEFAULT 'ativo' AFTER perfil,
ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;

-- Atualizar setores existentes para um cod padrão (apenas os 3 primeiros caracteres do nome)
UPDATE setores SET cod = UPPER(SUBSTRING(nome, 1, 3)) WHERE cod IS NULL;
