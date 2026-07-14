-- =============================================================================
-- Migração: perfis 3 -> 5 (alinha o banco ao PROJETO-SGT.md)
-- =============================================================================
-- Antes:  1 Administrador · 2 Supervisor · 3 Operador
-- Depois: 1 Administrador · 2 Planejador · 3 Executor · 4 Dashboard · 5 Cliente Interno
--
-- Renomeia os perfis 2 e 3 e adiciona 4 e 5. Idempotente (pode rodar de novo).
-- Seguro: no momento da migração nenhum usuário usa os perfis 2/3
-- (os IDs são fixos no código; usuários seguem pelos mesmos id_perfil).
--
-- Rodar manualmente (local e produção Railway):
--   mysql -u root trael_db < _inicial/migrar-perfis-5.sql
-- =============================================================================

USE trael_db;

INSERT INTO perfis (id, nome) VALUES
    (1, 'Administrador'),
    (2, 'Planejador'),
    (3, 'Executor'),
    (4, 'Dashboard'),
    (5, 'Cliente Interno')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);
