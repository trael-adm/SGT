-- Investigação exploratória — VSAT (SQL Server, Database=VsatTrael)
-- Objetivo: achar a tabela real por trás da grid "Materiais do Processo"
-- da tela Areco 92514 (Controle de Projetos PCP - Multi-Projetos), que hoje
-- NÃO é usada por scripts/sincronizar_vsat_pcp.ps1 (o script usa um catálogo
-- de peças hardcoded, com dimensões fixas iguais para todo TPD).
-- Rodar no mesmo SQL Server/credenciais já usados em sincronizar_vsat_pcp.ps1.
-- Data: 2026-08-27

-- 1. Tabelas com FK para ControleProjetoPCP (já usada na sincronização atual
--    para status/revisão do projeto — deve aparecer aqui a tabela filha de
--    "Processos" e a de "Materiais do Processo")
SELECT
    fk.name        AS FK,
    tp.name        AS TabelaFilha,
    tr.name        AS TabelaPai
FROM sys.foreign_keys fk
JOIN sys.tables tp ON fk.parent_object_id = tp.object_id
JOIN sys.tables tr ON fk.referenced_object_id = tr.object_id
WHERE tr.name = 'ControleProjetoPCP';

-- 2. Confirmação direta: procurar pelos códigos de referência reais vistos
--    na grid "Materiais do Processo" do Proj. 151903 (CMI-281-MI-1757 PA -
--    TPD-438925) — se uma tabela tiver esses 5 códigos juntos, é ela.
--    Primeiro descobre em quais tabelas/colunas procurar:
SELECT TABLE_NAME, COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE COLUMN_NAME LIKE '%Referencia%' OR COLUMN_NAME LIKE '%cd_Ref%';

-- Depois, para cada tabela candidata retornada acima, testar (trocar <tabela>
-- e <coluna_referencia> pelos nomes reais encontrados):
-- SELECT * FROM <tabela>
-- WHERE <coluna_referencia> IN (438376, 439324, 439410, 439316, 421477);

-- 3. Uma vez achada a tabela certa (ex.: algo como ControleProjetoPCPMaterial),
--    conferir o schema completo:
-- SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_NAME = '<nome_achado>';

-- 4. Idem para a aba "Processos" (máquina/célula/operação), usando os mesmos
--    passos 1-3 se a tabela não ficar óbvia a partir do resultado da consulta 1.

-- 5. Referência cruzada — view já citada (mas não usada) em
--    sincronizar_vsat_pcp.ps1, útil para validar o que já foi cortado de fato
--    (não substitui o BOM cadastrado, é só conferência):
SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'vw_apontamento_celula_cte_serie';
