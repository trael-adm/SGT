-- Renomeia o rótulo do bloqueio 'MATERIAL' em atraso_distribuicao_registros.setor_real
-- de "Aguardando Material/Compra" para "Averiguar", acompanhando o badge da tabela de
-- status-pecas.php e a nova barra "Averiguar" no gráfico "Gargalo Real por Célula de
-- Produção" (boletimClassificarBloqueioReal() em includes/boletim-atraso.php).
--
-- Rodar manualmente no LOCAL e na PROD (Railway). Idempotente: pode ser executado
-- múltiplas vezes sem erro (na segunda vez, WHERE não casa mais nada).
UPDATE atraso_distribuicao_registros
SET setor_real = 'Averiguar'
WHERE setor_real = 'Aguardando Material/Compra';
