-- =============================================================================
-- Adiciona `paint_check_validacoes.status` — distingue validações normais
-- (checklist por campo, ver api/paint-check-salvar-validacao.php) de
-- reprovações registradas direto na tela do Paint Check (novo modal "Nova
-- Reprovação", ver pages/qualidade/paint-check.php). O histórico
-- (pages/qualidade/paint-check-historico.php) usa essa coluna pra separar os
-- 3 estados: Confirmado, Manual e Reprovado.
--
-- Rodar manualmente no LOCAL e na PROD (convenção deste projeto — migrações
-- não são automáticas, ver CLAUDE.md).
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE `paint_check_validacoes`
  ADD COLUMN `status` ENUM('validado','reprovado') NOT NULL DEFAULT 'validado' AFTER `todos_campos_ok`;
