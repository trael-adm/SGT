-- Coluna nova: atraso_distribuicao_registros.num_serie
-- ------------------------------------------------------------------------------
-- A partir de hoje, boletimSincronizarAtrasoSqlServer() (includes/boletim-atraso.php)
-- deixou de agrupar a extração por "lote" (mesma referência/pedido/prazo) e passou a
-- gravar uma linha por Número de Série — igual ao Painel por Setor já fazia via
-- carregarPlanilhaProducaoFluxo(). O agrupamento por lote era só uma escolha de
-- exibição da extração antiga: cada id_ProgProdPCP/OF já corresponde 1:1 a um NS físico
-- (Quantidade=1 sempre), então "resumir" vários NS numa linha só, escolhendo UM deles
-- como representante pra classificar a célula de gargalo do lote inteiro, podia divergir
-- do Painel por Setor sempre que os NS do lote estivessem em estágios diferentes —
-- foi exatamente o caso reportado em 2026-09-08 (NS 859852: Painel por Setor via
-- Bobinagem/BT pendente, Gargalo Real via "Laser" — o representante do lote não era o
-- mesmo NS que travava lá).
--
-- Efeito colateral bom: "Averiguar" (peças com as 10 células já OK, só faltando
-- material/compra) foi de 21 pra 73 peças no primeiro re-sync — o método por lote
-- escondia peças já prontas atrás do representante do lote que ainda não tinha chegado lá.
--
-- Já é aplicada automaticamente por boletimGarantirTabelasAtraso() (auto-detecta a
-- coluna via information_schema e roda o ALTER se faltar) — este arquivo é só o
-- registro formal da mudança de schema, pro caso de alguém preferir rodar manual.
--
-- Idempotente.
ALTER TABLE atraso_distribuicao_registros
    ADD COLUMN IF NOT EXISTS num_serie INT NULL,
    ADD INDEX IF NOT EXISTS idx_num_serie (num_serie);
