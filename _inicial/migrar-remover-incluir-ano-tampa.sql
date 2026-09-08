-- =============================================================================
-- Remove `concessionaria_regras.incluir_ano_tampa` — regra incorreta: a tampa
-- do transformador NÃO leva ano de fabricação junto do N° de série (foi
-- cadastrada por engano pra Neoenergia em migrar-concessionaria-regras.sql;
-- confirmado pelo usuário que essa exigência não existe de verdade).
--
-- Rodar manualmente no LOCAL e na PROD (convenção deste projeto — migrações
-- não são automáticas, ver CLAUDE.md).
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE `concessionaria_regras` DROP COLUMN `incluir_ano_tampa`;
