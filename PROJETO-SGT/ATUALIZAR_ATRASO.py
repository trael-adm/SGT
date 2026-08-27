import os
import pandas as pd
from datetime import datetime
import urllib
from sqlalchemy import create_engine
import pyodbc

PASTA_DESTINO = r"\\tra-cba-srv-doc\11.PCP_DIST\INDICADORES\ATRASO DE FÁBRICA\DISTRIBUIÇÃO\BDDIAS"
SERVER = 'vsat.trael.local'
DATABASE = 'vsattrael'
USERNAME = 'bi_consulta'
PASSWORD = 'YtowDn2zp5CvuhNO1vtM'

if not os.path.exists(PASTA_DESTINO):
    os.makedirs(PASTA_DESTINO)

query = """
SELECT CAST(Sum(PP.Quantidade) AS INT) AS Quantidade
, PP.DATAHORAPRODUCAOAUX AS DataHoraProducaoAux
, M.cd_Referencia AS cd_Referencia
, M.ds_Prod AS ds_Prod
, CAST(IPE.qtdItem AS INT) AS qtdItem
, IPE.dt_LimiteEntrega AS dt_LimiteEntrega
, Cli.cdEnt AS cdEnt
, Cli.Nome AS Nome
, Cli.Apelido AS Apelido
, Ped.dt_Pedido AS dt_Pedido
, Ped.cdPedido AS cdPedido
, PT.ds_potencia AS ds_potencia
, PT.PotenciaKVA AS PotenciaKVA
, ET.nrofasesTrafo AS nrofasesTrafo
, CTT.ds_classeTensaoTrafo AS ds_classeTensaoTrafo
, PCP.dt_criacao AS dt_criacao
, PCP.NroRevisao AS NroRevisao
, PCP.dt_Revisao AS dt_Revisao
, TP.ds_TensaoTrafo AS ds_TensaoTrafo
, TS.ds_TensaoTrafo AS ds_TensaoTrafoSec
, TT.ds_TapsTrafo AS ds_TapsTrafo
, UF.cd_SglEstado AS cd_SglEstado
, CHOOSE(DATEPART(WEEKDAY, PP.DataHoraProducaoAux), 'DOM', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SAB') AS DiaSemana
, DATEPART(WEEK,PP.DataHoraProducaoAux) AS SemanaAno
, DATEPART(YEAR,PP.DataHoraProducaoAux) AS Ano
, DATEPART(MONTH,PP.DataHoraProducaoAux) AS Mes
, TEN.ds_TpEnrolamentoNucleo AS ds_TpEnrolamentoNucleo
, TCT.Ds_tpConstrTrafo AS Ds_tpConstrTrafo
, DATEPART(DAY,PP.DataHoraProducaoAux) AS Dia
, TEN.cd_TpEnrolamentoNucleo AS cd_TpEnrolamentoNucleo
, TensoesTrafoDespacho.ds_TensaoTrafo AS ds_TensaoTrafoDesp
, CAST(Sum(ORDF.QtdProduzida) AS INT) AS QtdProduzida
, CAST(CASE WHEN TEN.ds_TpEnrolamentoNucleo = 'JC' THEN SUM(PP.Quantidade) ELSE 0 END AS INT) AS TotalJC
, CAST(CASE WHEN TEN.ds_TpEnrolamentoNucleo = 'AM' THEN SUM(PP.Quantidade) ELSE 0 END AS INT) AS TotalAM
, CAST(CASE WHEN TEN.ds_TpEnrolamentoNucleo = 'EMP' THEN SUM(PP.Quantidade) ELSE 0 END AS INT) AS TotalEMP
, CAST(CASE WHEN TEN.ds_TpEnrolamentoNucleo = 'EMP-LM' THEN SUM(PP.Quantidade) ELSE 0 END AS INT) AS TotalEMPLM
, CAST(CASE WHEN TEN.ds_TpEnrolamentoNucleo = 'ENR' THEN SUM(PP.Quantidade) ELSE 0 END AS INT) AS TotalENR
, CAST(CASE WHEN TEN.ds_TpEnrolamentoNucleo = 'SL' THEN SUM(PP.Quantidade) ELSE 0 END AS INT) AS TotalSL
, EmpDestino.cdEnt AS cdEntEmpDesti
, CtrlItemPedidoPCP.SeqPlano AS SeqPlano
, CAST(IPE.qtdItem - Calculo.QtdOF AS INT) AS QtdAproduzirTotal
, CAST(Sum(PP.Quantidade) - Sum(ORDF.QtdProduzida) AS INT) AS QtdAproduzir
 FROM ProgramacaoProducao AS PP  WITH(NOLOCK) 
INNER JOIN Materiais AS M  WITH(NOLOCK) ON (M.id_Produto = PP.id_Produto)
INNER JOIN SubGrupoProduto AS SG  WITH(NOLOCK) ON (SG.id_SubGrupoPrd = M.id_SubGrupoPrd)
INNER JOIN GrupoProduto AS G  WITH(NOLOCK) ON (G.id_grpProd = SG.id_grpProd)
INNER JOIN CatGrupo AS CG  WITH(NOLOCK) ON (CG.id_catGrupo = G.id_catGrupo)
LEFT JOIN RlcProgramacaoItPedido AS RPP  WITH(NOLOCK) ON (RPP.id_ProgProdPCP = PP.id_ProgProdPCP AND RPP.PierSitReg = 'ATV')
LEFT JOIN It_Pedido AS IPE  WITH(NOLOCK) ON (IPE.id_it_pedido = RPP.id_it_pedido AND IPE.PierSitReg = 'ATV')
LEFT JOIN Pedidos AS Ped  WITH(NOLOCK) ON (Ped.id_Ped = IPE.id_Ped)
LEFT JOIN Entidade AS Cli  WITH(NOLOCK) ON (Cli.Id_Ent = Ped.id_Cliente)
LEFT JOIN EspecTrafo AS ET  WITH(NOLOCK) ON (ET.id_Produto = M.id_Produto)
LEFT JOIN Potencia AS PT  WITH(NOLOCK) ON (PT.id_potencia = ET.id_potencia)
LEFT JOIN ClasseTensaoTrafo AS CTT  WITH(NOLOCK) ON (CTT.id_classeTensaoTrafo = ET.id_classeTensaoTrafo)
LEFT JOIN TensoesTrafo AS TP  WITH(NOLOCK) ON (TP.id_TensaoTrafo = ET.id_TensaoTrafo)
LEFT JOIN TensoesTrafo AS TS  WITH(NOLOCK) ON (TS.id_TensaoTrafo = ET.id_tensaoTrafoSec)
LEFT JOIN TapsTrafo AS TT  WITH(NOLOCK) ON (TT.id_TapsTrafo = ET.id_TapsTrafo)
INNER JOIN ControleProjetoPCP AS PCP  WITH(NOLOCK) ON (PCP.id_Produto = M.id_Produto AND PCP.PierSitReg = 'ATV')
LEFT JOIN Enderecos_entid AS EE  WITH(NOLOCK) ON (EE.Id_End = Ped.IDEndEntrega)
LEFT JOIN Unid_Federacao AS UF  WITH(NOLOCK) ON (UF.id_SglEstado = EE.id_SglEstado)
LEFT JOIN TipoEnrolamentoNucleo AS TEN  WITH(NOLOCK) ON (TEN.id_TpEnrolamentoNucleo = ET.id_TpEnrolamentoNucleo)
LEFT JOIN TipoConstrutivoTrafo AS TCT  WITH(NOLOCK) ON (TCT.id_tpConstrTrafo = ET.id_tpConstrTrafo)
LEFT JOIN Clientes AS CLICMPL  WITH(NOLOCK) ON (CLICMPL.Id_Ent = Cli.Id_Ent AND CLICMPL.PierSitReg = 'ATV')
LEFT JOIN GrupoDeClientes AS GrpCli  WITH(NOLOCK) ON (GrpCli.IDGrupoCliente = CLICMPL.IDGrupoCliente AND GrpCli.PierSitReg = 'ATV')
LEFT JOIN OrdemFabricacao AS ORDF  WITH(NOLOCK) ON (ORDF.id_of = PP.id_of AND ORDF.PierSitReg = 'ATV')
LEFT JOIN TensoesTrafo AS TensoesTrafoDespacho  WITH(NOLOCK) ON (TensoesTrafoDespacho.id_TensaoTrafo = ET.id_tensaoDespacho)
LEFT JOIN Entidade AS EmpDestino  WITH(NOLOCK) ON (EmpDestino.Id_Ent = PP.id_Empresa AND EmpDestino.PierSitReg = 'ATV')
LEFT JOIN RlcCtrlItemPedidoPCPProgProd AS RlcCtrlItemPedidoPCPProgProd  WITH(NOLOCK) ON (RlcCtrlItemPedidoPCPProgProd.id_ProgProdPCP = PP.id_ProgProdPCP AND RlcCtrlItemPedidoPCPProgProd.PierSitReg = 'ATV')
LEFT JOIN CtrlItemPedidoPCP AS CtrlItemPedidoPCP  WITH(NOLOCK) ON (CtrlItemPedidoPCP.IDCtrlItPedidoPCP = RlcCtrlItemPedidoPCPProgProd.IDCtrlItPedidoPCP AND CtrlItemPedidoPCP.PierSitReg = 'ATV')
OUTER APPLY (SELECT SUM(PP2.Quantidade) QtdPP, ISNULL(SUM(OF2.QtdProduzida),0) QtdOF FROM ProgramacaoProducao PP2 INNER JOIN RlcProgramacaoItPedido RLC WITH(NOLOCK) ON  RLC.id_ProgProdPCP = PP2.id_ProgProdPCP AND RLC.PierSitReg = 'ATV' INNER JOIN It_Pedido IT WITH(NOLOCK) ON IT.id_it_pedido = RLC.id_it_pedido AND IT.PierSitReg = 'ATV' LEFT JOIN OrdemFabricacao OF2 WITH(NOLOCK) ON OF2.id_of = PP2.id_of AND OF2.PierSitReg = 'ATV' WHERE PP2.PierSitReg = 'ATV' AND IT.id_it_pedido = IPE.id_it_pedido) AS Calculo
WHERE (((PP.id_of > 0)) OR (PP.id_of = 0))
 AND (PP.PierSitReg = 'ATV')
 AND (CG.cd_CatGrupo = 40)
 AND (PP.DataHoraProducaoAux BETWEEN dateadd(month,-12, getdate()) AND DATEADD(day, -1, GETDATE()) )
 AND (PCP.statusProjeto = 'PRD' OR (PCP.statusProjeto = 'DES' AND NOT EXISTS (SELECT 1 FROM ControleProjetoPCP AS PCP2 WHERE PCP2.id_Produto = M.id_Produto AND PCP2.PierSitReg = 'ATV' AND PCP2.statusProjeto = 'PRD')))
 AND EmpDestino.cdEnt = '1'
 AND ORDF.StatusOF IN ('AGU', 'RES')
GROUP BY (PP.DATAHORAPRODUCAOAUX)
, M.cd_Referencia
, M.ds_Prod
, IPE.qtdItem
, IPE.dt_LimiteEntrega
, Cli.cdEnt
, Cli.Nome
, Cli.Apelido
, Ped.dt_Pedido
, Ped.cdPedido
, PT.ds_potencia
, PT.PotenciaKVA
, ET.nrofasesTrafo
, CTT.ds_classeTensaoTrafo
, PCP.dt_criacao
, PCP.NroRevisao
, PCP.dt_Revisao
, TP.ds_TensaoTrafo
, TS.ds_TensaoTrafo
, TT.ds_TapsTrafo
, UF.cd_SglEstado
, TEN.ds_TpEnrolamentoNucleo
, TCT.Ds_tpConstrTrafo
, (DATEPART(DAY,PP.DataHoraProducaoAux))
, TEN.cd_TpEnrolamentoNucleo
, TensoesTrafoDespacho.ds_TensaoTrafo
, EmpDestino.cdEnt
, CtrlItemPedidoPCP.SeqPlano
, (IPE.qtdItem - Calculo.QtdOF);
"""

try:
    print('Conectando ao banco de dados...')
    conn_str = f'DRIVER={{SQL Server}};SERVER={SERVER};DATABASE={DATABASE};UID={USERNAME};PWD={PASSWORD};Trusted_Connection=no;'
    params = urllib.parse.quote_plus(conn_str)
    engine = create_engine(f'mssql+pyodbc:///?odbc_connect={params}')

    print('Executando consulta do Plano Mestre...')
    with engine.connect() as conn:
        df = pd.read_sql(query, conn)

    data_hoje = datetime.now().strftime('%Y-%m-%d')
    df['Data_Snapshot'] = data_hoje
    nome_arquivo = f'snapshot_{data_hoje}.csv'
    caminho_final = os.path.join(PASTA_DESTINO, nome_arquivo)

    print(f'Exportando {len(df)} linhas para o CSV referente a {data_hoje}...')
    df.to_csv(caminho_final, index=False, sep=';', encoding='utf-8-sig')
    print(f'Sucesso! Arquivo salvo em: {caminho_final}')

except Exception as e:
    print(f'\n[ERRO] Ocorreu um problema na execução: {e}')

input('\nPressione Enter para fechar...')
