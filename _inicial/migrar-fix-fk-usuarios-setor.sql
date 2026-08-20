-- Correção da Foreign Key de Setor na tabela usuarios
-- Aponta para a tabela oficial 'setores' (em vez de 'admin_setores')

ALTER TABLE usuarios DROP FOREIGN KEY fk_usuarios_setor;
ALTER TABLE usuarios ADD CONSTRAINT fk_usuarios_setor FOREIGN KEY (id_setor) REFERENCES setores(id) ON DELETE SET NULL;
