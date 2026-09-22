-- ==============================================================================
-- MIGRAÇÃO: tabela `feriados` (não existia localmente — usada por isFeriado()
-- em includes/helpers.php pro cálculo de HE, e agora também pelas telas de
-- Evolução Diária pra tirar feriado da lista de dias úteis).
-- ------------------------------------------------------------------------------
-- Feriados fixos (movel=0): o ANO em `data` não importa pra esses — isFeriado()
-- casa só por mês/dia (DATE_FORMAT(data,'%m-%d')), então funciona pra qualquer
-- ano com uma linha só.
--
-- NÃO inclui feriados móveis (Carnaval, Sexta-Santa, Corpus Christi) — variam
-- de ano pra ano (dependem da Páscoa) e nem sempre são ponto facultativo/parada
-- de fábrica em todo lugar; ficam de fora até confirmar o que a Trael observa.
--
-- Idempotente (INSERT ... ON DUPLICATE KEY não dá pra usar sem UNIQUE key —
-- roda um DELETE dos fixos antes pra evitar duplicar se rodar 2x).
-- ==============================================================================

DELETE FROM feriados WHERE movel = 0;

INSERT INTO feriados (nome, data, movel) VALUES
('Confraternização Universal', '2026-01-01', 0),
('Tiradentes', '2026-04-21', 0),
('Dia do Trabalho', '2026-05-01', 0),
('Independência do Brasil', '2026-09-07', 0),
('Nossa Senhora Aparecida', '2026-10-12', 0),
('Finados', '2026-11-02', 0),
('Proclamação da República', '2026-11-15', 0),
('Dia Nacional de Zumbi e da Consciência Negra', '2026-11-20', 0),
('Natal', '2026-12-25', 0);
