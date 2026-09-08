-- Migração: colunas de classificação REAL de gargalo por OF pendente em
-- atraso_distribuicao_registros — calculadas na sincronização (boletimSincronizarAtrasoSqlServer)
-- via decomposição de sub-OFs (dbo.RlcProgramacao), substituindo o antigo palpite por
-- prefixo de cd_referencia da OF-mãe (que nunca carrega prefixo de célula).
--
-- setor_real   = nome da 1ª célula real de produção (na ordem do fluxo fabril) ainda
--                pendente, quando tipo_bloqueio = 'CELULA'; ou 'Aguardando Material/Compra'
--                quando tipo_bloqueio = 'MATERIAL' (fábrica já concluiu todas as células,
--                só falta item comprado/semiacabado pra fechar a OF-mãe).
-- tipo_bloqueio = 'CELULA' | 'MATERIAL' | NULL (não classificado — ex.: SQL Server
--                indisponível no momento da sincronização, ou sem sub-OFs rastreáveis).
--
-- Rodar manualmente no LOCAL e na PROD (Railway) — ver CLAUDE.md / PROJETO-SGT.md.
-- Data: 2026-09-03

ALTER TABLE `atraso_distribuicao_registros`
    ADD COLUMN `setor_real` VARCHAR(60) NULL AFTER `tipo_construtivo`,
    ADD COLUMN `tipo_bloqueio` VARCHAR(20) NULL AFTER `setor_real`;
