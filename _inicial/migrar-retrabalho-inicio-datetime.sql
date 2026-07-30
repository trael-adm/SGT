-- =============================================================================
-- Trael — SGT
-- Migração: "Data de início do retrabalho" passa a registrar também o horário
-- (preenchido via leitura de QR Code na tela de Triagem, não mais manual)
-- =============================================================================
-- Idempotente: MODIFY COLUMN pode ser rodado mais de uma vez sem erro.
-- Datas já gravadas (só o dia, sem hora) continuam válidas — viram meia-noite.
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-retrabalho-inicio-datetime.sql
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE retrabalhos MODIFY data_inicio DATETIME NULL;

-- =============================================================================
-- Fim da migração. Confira com:
--   DESCRIBE retrabalhos;   -- data_inicio deve estar como DATETIME
-- =============================================================================
