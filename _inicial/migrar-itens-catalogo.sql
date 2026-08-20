-- =============================================================================
-- Trael — SGT
-- Migração: Catálogo de itens (código/descrição/unidade) usado pela busca de
-- "Materiais utilizados" na Triagem do Retrabalho. Populado a partir de
-- "PLANILHA QUE ATUALIZA/Item.csv" pelo script _inicial/importar-itens-catalogo.php
-- — este arquivo só cria a estrutura, não grava dados.
-- =============================================================================
-- Idempotente: pode ser rodado mais de uma vez sem erro (mesmo já aplicada).
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-itens-catalogo.sql
--   php _inicial/importar-itens-catalogo.php --commit   (popula a tabela)
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS itens_catalogo (
    codigo     VARCHAR(20)  NOT NULL PRIMARY KEY,
    descricao  VARCHAR(255) NOT NULL,
    unidade    VARCHAR(10)  NOT NULL,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_itens_catalogo_descricao (descricao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Fim da migração. Confira com:
--   DESCRIBE itens_catalogo;
--   SELECT COUNT(*) FROM itens_catalogo;   -- 0 até rodar o importador
-- =============================================================================
