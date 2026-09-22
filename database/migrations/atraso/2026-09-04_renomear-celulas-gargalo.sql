-- Renomeia os rótulos de célula real gravados em atraso_distribuicao_registros.setor_real
-- para acompanhar a mudança de nomenclatura de ATRASO_CELULAS_REAIS_DISTRIB
-- (includes/boletim-atraso.php), usada no gráfico "Gargalo Real por Célula de Produção"
-- (pages/producao/status-pecas.php) e nos badges de célula da mesma tela.
--
-- Sem este UPDATE, as linhas já sincronizadas continuam com o rótulo antigo (setor_real
-- é gravado verbatim pelo sincronizador local em api/sync-boletim.php, não recalculado
-- a cada leitura) e o gráfico mostra as duas nomenclaturas ao mesmo tempo — a antiga com
-- os dados, a nova zerada — até a próxima sincronização.
--
-- Rodar manualmente no LOCAL e na PROD (Railway). Idempotente: pode ser executado
-- múltiplas vezes sem erro (na segunda vez, WHERE não casa mais nada).
UPDATE atraso_distribuicao_registros
SET setor_real = CASE setor_real
    WHEN 'Armadura'       THEN 'Chassis'
    WHEN 'Fundo'          THEN 'Laser'
    WHEN 'Tanque'         THEN 'Pintura'
    WHEN 'Tampa'          THEN 'Solda'
    WHEN 'Enrolamento BT' THEN 'BT'
    WHEN 'Enrolamento AT' THEN 'AT'
    ELSE setor_real
END
WHERE setor_real IN ('Armadura', 'Fundo', 'Tanque', 'Tampa', 'Enrolamento BT', 'Enrolamento AT');
