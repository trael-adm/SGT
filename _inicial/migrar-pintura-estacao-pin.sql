-- =============================================================================
-- Adiciona a estação `PIN` (Pintura) ao ENUM de `producao_etapas.estacao`,
-- ao lado de `IQF`/`LAB`/`GER` já existentes. Usada pelas reprovações feitas
-- direto no Paint Check (ver pages/qualidade/paint-check.php, modal "Nova
-- Reprovação") — a peça reprovada entra em `aguardando_retorno` na estação
-- PIN, com sua própria fila de retornos (pages/pintura/retornos.php),
-- backend isolado em api/pintura-retornos-acao.php (não reaproveita/altera
-- a lógica já existente de LAB/IQF em api/retrabalho-acao.php).
--
-- Rodar manualmente no LOCAL e na PROD (convenção deste projeto — migrações
-- não são automáticas, ver CLAUDE.md).
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE `producao_etapas`
  MODIFY COLUMN `estacao` ENUM('IQF','LAB','GER','PIN') NOT NULL;
