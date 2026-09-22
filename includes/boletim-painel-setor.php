<?php
declare(strict_types=1);

/**
 * Módulo de Gestão e Métricas do Painel por Setor.
 *
 * Consolida metas, produção realizada, ordens em fila, eficiência e
 * evolução diária por setor/célula da fábrica.
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/boletim-planilha.php';
require_once __DIR__ . '/boletim-atraso.php';
require_once __DIR__ . '/helpers.php';

/**
 * Retorna os setores disponíveis para segmentação.
 */
function boletimObterListaSetores(): array
{
    return [
        'CONSOLIDADO' => [
            'nome'      => 'Consolidado (Todos os Setores)',
            'codigo'    => 'GERAL',
            'descricao' => 'Visão Executiva Global da Fábrica de Distribuição',
            'icone'     => 'layers',
            'peso'      => 1.0,
        ],
        'LABORATORIO' => [
            'nome'      => 'Laboratório / Ensaios',
            'codigo'    => 'LAB',
            'descricao' => 'Testes Elétricos, Rotina e Aprovação Final',
            'icone'     => 'check-circle',
            'peso'      => 1.0,
        ],
        'MONTAGEM_FINAL' => [
            'nome'      => 'Montagem Final',
            'codigo'    => 'MFL',
            'descricao' => 'Encaixotamento, Fechamento e Enchimento de Óleo',
            'icone'     => 'box',
            'peso'      => 1.0,
        ],
        'MONTAGEM_ELETRICA' => [
            'nome'      => 'Montagem Elétrica / Parte Ativa',
            'codigo'    => 'ME',
            'descricao' => 'Montagem de Núcleos, Bobinas e Conexões Elétricas',
            'icone'     => 'zap',
            'peso'      => 1.0,
        ],
        'PINTURA' => [
            'nome'      => 'Pintura / Tanque',
            'codigo'    => 'MTQ',
            'descricao' => 'Processamento, Jateamento e Pintura de Tanques',
            'icone'     => 'brush',
            'peso'      => 1.0,
        ],
        'BOBINAGEM' => [
            'nome'      => 'Bobinagem / Enrolamento',
            'codigo'    => 'BOB',
            'descricao' => 'Enrolamento de Bobinas BT e AT (ENR / EMP / JC)',
            'icone'     => 'disc',
            'peso'      => 1.0,
        ],
    ];
}

/**
 * Calcula todas as métricas do Painel por Setor para um determinado mês/setor.
 *
 * @param string $mes Mês de referência (YYYY-MM)
 * @param string $setorChave Chave do setor (ex: 'CONSOLIDADO', 'PINTURA', 'MONTAGEM_ELETRICA', etc.)
 * @param string|null $dataCorte Data de corte opcional (Y-m-d)
 * @param string $modoData Modo de filtro de data ('hoje', 'mes', 'personalizado')
 * @param string|null $dataInicio Data inicial personalizada (Y-m-d)
 * @param string|null $dataFim Data final personalizada (Y-m-d)
 */
function boletimCalcularPainelSetor(
    string $mes = '',
    string $setorChave = 'CONSOLIDADO',
    ?string $dataCorte = null,
    string $modoData = 'mes',
    ?string $dataInicio = null,
    ?string $dataFim = null
): array {
    $hojeAtual = date('Y-m-d');

    if ($modoData === 'hoje') {
        $mes = substr($hojeAtual, 0, 7);
        $dataCorte = $hojeAtual;
        $dataInicio = $hojeAtual;
        $dataFim = $hojeAtual;
    } elseif ($modoData === 'personalizado') {
        if ($dataInicio && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio)) {
            $mes = substr($dataInicio, 0, 7);
        }
        if (!$dataFim || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
            $dataFim = $dataInicio ?: $hojeAtual;
        }
        $dataCorte = $dataFim;
    } else {
        $modoData = 'mes';
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
            $mes = date('Y-m');
        }
    }

    $setores = boletimObterListaSetores();
    $setorChaveUpper = strtoupper(trim($setorChave));
    if (!isset($setores[$setorChaveUpper])) {
        $setorChaveUpper = 'CONSOLIDADO';
    }
    $setorInfo = $setores[$setorChaveUpper];

    // Dias do mês
    [$ano, $mesNum] = array_map('intval', explode('-', $mes));
    $diasNoMes = (int) date('t', strtotime($mes . '-01'));

    $configMeta = [];
    try {
        $pdo = getDB();
        if ($pdo) {
            $stmt = $pdo->prepare("SELECT * FROM boletim_config_metas WHERE `month` = ?");
            $stmt->execute([$mes]);
            $configMeta = $stmt->fetch() ?: [];
        }
    } catch (Throwable $e) {
        $configMeta = [];
    }

    $metaTotalDistrib = (int) ($configMeta['meta_tpd_distribuicao'] ?? 0);
    $metaEnr          = (int) ($configMeta['meta_enrolado'] ?? 0);
    $metaJc           = (int) ($configMeta['meta_jctrif'] ?? 0);
    $metaEmp          = (int) ($configMeta['meta_convencional'] ?? 0);

    // Se meta total não estiver preenchida mas por núcleo estiver
    if ($metaTotalDistrib <= 0 && ($metaEnr + $metaJc + $metaEmp) > 0) {
        $metaTotalDistrib = $metaEnr + $metaJc + $metaEmp;
    }
    if ($metaTotalDistrib <= 0) {
        // Meta padrão proporcional se não configurada
        $metaTotalDistrib = 4500;
        $metaEnr = 3000;
        $metaEmp = 1350;
        $metaJc  = 150;
    }

    // Dias úteis de trabalho configurados
    $diasCustomizadosRaw = $configMeta['dias_customizados'] ?? null;
    $diasAtivosArray = !empty($diasCustomizadosRaw) ? json_decode($diasCustomizadosRaw, true) : null;
    if (is_array($diasAtivosArray) && !empty($diasAtivosArray)) {
        $diasUteisMes = $diasAtivosArray;
    } else {
        $diasUteisMes = [];
        for ($i = 1; $i <= $diasNoMes; $i++) {
            $d = sprintf('%04d-%02d-%02d', $ano, $mesNum, $i);
            if ((int) date('N', strtotime($d)) <= 5) {
                $diasUteisMes[] = $d;
            }
        }
    }
    $totalDiasUteisMes = max(1, count($diasUteisMes));

    // Data de corte padrão
    if ($dataCorte === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataCorte)) {
        $ultimoDiaComProd = null;
        foreach ($diasUteisMes as $d) {
            if ($d <= $hojeAtual) {
                $ultimoDiaComProd = $d;
            }
        }
        $dataCorte = $ultimoDiaComProd ?: end($diasUteisMes);
    }

    // Dias úteis trabalhados no período de análise
    $diasTrabalhadosAteCorte = [];
    foreach ($diasUteisMes as $d) {
        if ($modoData === 'hoje') {
            if ($d === $hojeAtual || ($hojeAtual > end($diasUteisMes) && $d === end($diasUteisMes))) {
                $diasTrabalhadosAteCorte[] = $d;
            }
        } elseif ($modoData === 'personalizado') {
            if ($d >= ($dataInicio ?: '2000-01-01') && $d <= ($dataFim ?: '2099-12-31')) {
                $diasTrabalhadosAteCorte[] = $d;
            }
        } else {
            if ($d <= $dataCorte) {
                $diasTrabalhadosAteCorte[] = $d;
            }
        }
    }
    if (empty($diasTrabalhadosAteCorte)) {
        $diasTrabalhadosAteCorte = [$dataCorte];
    }

    $countDiasTrabalhados = max(1, count($diasTrabalhadosAteCorte));
    $diasRestantes = max(0, $totalDiasUteisMes - (int) count(array_filter($diasUteisMes, fn($d) => $d <= $dataCorte)));

    // Metas personalizadas por setor salvas na configuração (JSON)
    $metasSetoresRaw = $configMeta['metas_setores'] ?? null;
    $metasSetoresConfig = !empty($metasSetoresRaw) ? json_decode($metasSetoresRaw, true) : [];

    // Mapeamento das metas diárias e mensais para cada setor
    $metasPorSetor = [];
    foreach ($setores as $chave => $st) {
        $metaDiariaVal = null;
        $metaMensalVal = null;

        if (isset($metasSetoresConfig[$chave])) {
            if (is_array($metasSetoresConfig[$chave])) {
                $metaDiariaVal = isset($metasSetoresConfig[$chave]['diaria']) ? (float) $metasSetoresConfig[$chave]['diaria'] : null;
                $metaMensalVal = isset($metasSetoresConfig[$chave]['mensal']) ? (int) $metasSetoresConfig[$chave]['mensal'] : null;
            } elseif (is_numeric($metasSetoresConfig[$chave])) {
                $metaDiariaVal = (float) $metasSetoresConfig[$chave];
                $metaMensalVal = (int) round($metaDiariaVal * $totalDiasUteisMes);
            }
        }

        if ($metaDiariaVal === null || $metaDiariaVal <= 0) {
            $metaMensalVal = $metaTotalDistrib;
            $metaDiariaVal = $totalDiasUteisMes > 0 ? round($metaTotalDistrib / $totalDiasUteisMes, 1) : 0;
        }
        if ($metaMensalVal === null || $metaMensalVal <= 0) {
            $metaMensalVal = (int) round($metaDiariaVal * $totalDiasUteisMes);
        }

        $metasPorSetor[$chave] = [
            'diaria' => $metaDiariaVal,
            'mensal' => $metaMensalVal,
        ];
    }

    // Meta diária teórica global e por núcleo
    $metaDiariaGlobal = round($metaTotalDistrib / $totalDiasUteisMes, 1);
    $metaDiariaEnr    = round($metaEnr / $totalDiasUteisMes, 1);
    $metaDiariaEmp    = round($metaEmp / $totalDiasUteisMes, 1);
    $metaDiariaJc     = round($metaJc / $totalDiasUteisMes, 1);

    // Meta específica do setor selecionado
    $metaSetor       = $metasPorSetor[$setorChaveUpper]['mensal'];
    $metaDiariaSetor = $metasPorSetor[$setorChaveUpper]['diaria'];

    // Grade do calendário mensal para o modal
    $todosDiasCalendario = [];
    $primeiroDiaSemanaMes = (int) date('w', mktime(0, 0, 0, $mesNum, 1, $ano));
    for ($i = 1; $i <= $diasNoMes; $i++) {
        $dataStr = sprintf('%04d-%02d-%02d', $ano, $mesNum, $i);
        $diaSemana = (int) date('w', strtotime($dataStr));
        $todosDiasCalendario[] = [
            'dia'         => $i,
            'date'        => $dataStr,
            'diaSemana'   => $diaSemana,
            'fimDeSemana' => ($diaSemana === 0 || $diaSemana === 6),
            'ativo'       => in_array($dataStr, $diasUteisMes, true),
        ];
    }

    // ─── Dados de Produção Real do Kardex ──────────────────────────────────
    $dadosKardex = boletimObterDadosMes($mes);
    $kardexPorDia = $dadosKardex['nucleoPorDia'] ?? [];

    $prodTotalPeriodo = 0;
    $prodPorLinha = ['ENR' => 0, 'CONV' => 0, 'JC_TRIF' => 0];
    $prodDiariaLista = [];

    foreach ($diasUteisMes as $d) {
        $p = $kardexPorDia[$d] ?? [];
        $enr = (int) ($p['ENR'] ?? 0);
        $emp = (int) ($p['EMP'] ?? 0);
        $jc  = (int) ($p['JC'] ?? 0);

        $totalDia = $enr + $emp + $jc;

        $pertenceAoPeriodo = in_array($d, $diasTrabalhadosAteCorte, true);

        if ($pertenceAoPeriodo) {
            $prodTotalPeriodo += $totalDia;
            $prodPorLinha['ENR']     += $enr;
            $prodPorLinha['CONV']    += $emp;
            $prodPorLinha['JC_TRIF'] += $jc;
        }

        $prodDiariaLista[$d] = [
            'data'         => $d,
            'label'        => date('d/m', strtotime($d)),
            'dia'          => (int) date('j', strtotime($d)),
            'total'        => $totalDia,
            'enr'          => $enr,
            'emp'          => $emp,
            'jc'           => $jc,
            'meta_diaria'  => $metaDiariaSetor,
            'status'       => ($totalDia >= $metaDiariaSetor) ? 'positivo' : 'alerta',
            'passado'      => ($d <= $dataCorte),
        ];
    }

    // Eficiência Geral de Produção (%)
    // Meta proporcional acumulada até a data de corte (usando a meta diária do setor)
    $metaAcumuladaCorte = (int) round($metaDiariaSetor * $countDiasTrabalhados);
    $eficienciaGeral = ($metaAcumuladaCorte > 0) ? round(($prodTotalPeriodo / $metaAcumuladaCorte) * 100, 1) : 0.0;
    $eficienciaSobreMetaTotal = ($metaSetor > 0) ? round(($prodTotalPeriodo / $metaSetor) * 100, 1) : 0.0;

    // ─── Dados de Acumulado / Snapshot (Ordens em Fila e Atrasos) ───────────
    $resSnapshot = boletimCarregarSnapshot();
    $itensSnapshot = $resSnapshot['sucesso'] ? $resSnapshot['itens'] : [];

    $acumuladoFila = 0;
    $ordensSetor = [];

    foreach ($itensSnapshot as $it) {
        $refUpper   = strtoupper(trim((string) ($it['referencia'] ?? $it['cd_referencia'] ?? '')));
        $linhaUpper = strtoupper(trim((string) ($it['linha'] ?? '')));
        $descUpper  = strtoupper(trim((string) ($it['descricao'] ?? $it['ds_produto'] ?? '')));

        // Classificação do Setor da OF / Peça
        if (str_starts_with($refUpper, 'MTQ') || str_starts_with($refUpper, 'TANQ') || str_contains($descUpper, 'TANQUE') || $linhaUpper === 'CONVENCIONAL') {
            $setorItemChave = 'PINTURA';
            $setorItemCod   = 'MTQ';
            $setorItemNome  = 'Pintura / Tanque';
        } elseif (str_starts_with($refUpper, 'ME-') || str_starts_with($refUpper, 'PA-') || str_starts_with($refUpper, 'ME_') || str_starts_with($refUpper, 'PA_') || str_contains($descUpper, 'PARTE ATIVA')) {
            $setorItemChave = 'MONTAGEM_ELETRICA';
            $setorItemCod   = 'ME';
            $setorItemNome  = 'Montagem Elétrica';
        } elseif (str_starts_with($refUpper, 'MFL') || str_starts_with($refUpper, 'MF-')) {
            $setorItemChave = 'MONTAGEM_FINAL';
            $setorItemCod   = 'MFL';
            $setorItemNome  = 'Montagem Final';
        } elseif (str_starts_with($refUpper, 'BOB') || str_starts_with($refUpper, 'BAT') || str_starts_with($refUpper, 'BBT') || str_contains($descUpper, 'BOBINA')) {
            $setorItemChave = 'BOBINAGEM';
            $setorItemCod   = 'BOB';
            $setorItemNome  = 'Bobinagem';
        } else {
            $setorItemChave = 'LABORATORIO';
            $setorItemCod   = 'LAB';
            $setorItemNome  = 'Laboratório / Ensaios';
        }

        $it['setor_chave']  = $setorItemChave;
        $it['setor_codigo'] = $setorItemCod;
        $it['setor_nome']   = $setorItemNome;

        if (empty($it['of']) && !empty($it['seq_plano'])) {
            $it['of'] = 'SEQ ' . $it['seq_plano'];
        }

        // Se filtrar por setor/célula
        $matchSetor = ($setorChaveUpper === 'CONSOLIDADO' || $setorChaveUpper === $setorItemChave);
        if ($matchSetor) {
            $ordensSetor[] = $it;
            $acumuladoFila += (int) ($it['quantidade'] ?? 1);
        }
    }

    // Nova Meta / Saldo Pendente (baseado na meta do setor)
    $saldoPendente = max(0, $metaSetor - $prodTotalPeriodo);
    $novaMetaDiaria = ($diasRestantes > 0) ? round($saldoPendente / $diasRestantes, 1) : $saldoPendente;

    // ─── Programado vs Produzido por Setor Fabril ───────────────────────────
    $setoresGraficoConfig = [
        'LABORATORIO' => [
            'nome'   => 'Laboratório (LAB)',
            'codigo' => 'LAB',
            'chave'  => 'LABORATORIO',
            'fator'  => 1.0,
        ],
        'MONTAGEM_FINAL' => [
            'nome'   => 'Montagem Final (MFL)',
            'codigo' => 'MFL',
            'chave'  => 'MONTAGEM_FINAL',
            'fator'  => 1.0,
        ],
        'MONTAGEM_ELETRICA' => [
            'nome'   => 'Montagem Elétrica (ME)',
            'codigo' => 'ME',
            'chave'  => 'MONTAGEM_ELETRICA',
            'fator'  => 1.02,
        ],
        'PINTURA' => [
            'nome'   => 'Pintura / Tanque (MTQ)',
            'codigo' => 'MTQ',
            'chave'  => 'PINTURA',
            'fator'  => 1.04,
        ],
        'BOBINAGEM' => [
            'nome'   => 'Bobinagem (BOB)',
            'codigo' => 'BOB',
            'chave'  => 'BOBINAGEM',
            'fator'  => 1.06,
        ],
    ];

    // Produção real de apontamento (chão de fábrica) por estação — únicas células
    // com registro próprio hoje. Demais setores seguem estimados pelo fator até
    // existir apontamento real (ver limitação documentada no PROJETO-SGT.md).
    $producaoRealPorEstacao = boletimObterProducaoRealPorEstacao($diasTrabalhadosAteCorte);

    // Montagem Final, Montagem Elétrica e Bobinagem não têm apontamento próprio,
    // mas dá pra aproximar com dado real do Fluxo de Pedidos: OK = célula concluída
    // (finalizado), PEND = ainda em aberto naquela célula, para as OFs programadas
    // no período selecionado. A Pintura também usa o Fluxo (célula PIN) quando o
    // apontamento próprio dela (acima) está vazio.
    $diasPeriodoLista = array_values($diasTrabalhadosAteCorte);
    $dtIniPeriodo = $diasPeriodoLista[0] ?? $dataCorte;
    $dtFimPeriodo = end($diasPeriodoLista) ?: $dataCorte;
    $producaoRealFluxo = boletimObterProducaoRealFluxoCelulas($dtIniPeriodo, $dtFimPeriodo);

    // Produção Real de Laboratório / Ensaios (Kardex / DW: cdEnt = 1, Distribuição, tipo = 'PRODUÇÃO')
    $itensLabTotal = [];
    $itensLabPorNucleo = ['ENR' => [], 'JC-TRIF' => [], 'EMP' => []];
    $analiticoKardex = $dadosKardex['analitico'] ?? [];

    foreach ($analiticoKardex as $r) {
        if (($r['tipo'] ?? '') !== 'PRODUÇÃO') {
            continue;
        }
        if (($r['area_cod'] ?? '') !== 'distrib' && ($r['cdEnt'] ?? '') != 1) {
            continue;
        }
        $dt = $r['data_turno'] ?? ($r['data_mov'] ?? '');
        if (!in_array($dt, $diasTrabalhadosAteCorte, true)) {
            continue;
        }

        $nucCod = strtoupper(trim((string) ($r['nucleo_cod'] ?? '')));
        if ($nucCod === 'JC' || $nucCod === 'JC-TRIF' || $nucCod === 'JC_TRIF') {
            $nucChave = 'JC-TRIF';
        } elseif ($nucCod === 'EMP' || $nucCod === 'CONV' || $nucCod === 'CONVENCIONAL') {
            $nucChave = 'EMP';
        } else {
            $nucChave = 'ENR';
        }

        $itemFormatado = [
            'ns_serie' => (string) ($r['serie'] ?? '—'),
            'data'     => !empty($r['data_audit']) ? date('d/m/Y H:i', strtotime($r['data_audit'])) : ($dt ? date('d/m/Y', strtotime($dt)) : '—'),
            'seq'      => (string) ($r['of'] ?? '—'),
            'pedido'   => (string) ($r['pedido'] ?? '—'),
            'cliente'  => (string) ($r['cliente'] ?? '—'),
            'projeto'  => (string) ($r['projeto'] ?? ($r['referencia'] ?? '—')),
            'status'   => 'Produzido',
            'nucleo'   => $nucChave,
        ];

        $itensLabTotal[] = $itemFormatado;
        $itensLabPorNucleo[$nucChave][] = $itemFormatado;
    }

    $labelsSetores     = [];
    $codigosSetores    = [];
    $chavesSetores     = [];
    $programadoSetores = [];
    $produzidoSetores  = [];
    $dadoRealSetores   = [];
    $relacaoSetores    = [];

    foreach ($setoresGraficoConfig as $stChave => $stCfg) {
        $metaProg = (int) round(($metasPorSetor[$stChave]['diaria'] ?? $metaDiariaGlobal) * $countDiasTrabalhados);

        if ($stChave === 'LABORATORIO') {
            if ($producaoRealPorEstacao['LAB'] > 0) {
                $prodSetor = $producaoRealPorEstacao['LAB'];
                $relacaoItem = $producaoRealPorEstacao['itens']['LAB'];
            } else {
                $prodSetor = count($itensLabTotal);
                $relacaoItem = $itensLabTotal;
            }
            $ehDadoReal = true;
        } elseif ($stChave === 'PINTURA' && $producaoRealPorEstacao['PIN'] > 0) {
            $prodSetor = $producaoRealPorEstacao['PIN'];
            $ehDadoReal = true;
            $relacaoItem = $producaoRealPorEstacao['itens']['PIN'];
        } elseif ($stChave === 'PINTURA' && $producaoRealFluxo['disponivel']) {
            // Sem apontamento de chão de fábrica no SGT (a Pintura é apontada no ERP):
            // usa a célula PIN (sub-OF MTQ concluída) do Fluxo de Pedidos.
            $prodSetor = $producaoRealFluxo['PIN']['ok'];
            $ehDadoReal = true;
            $relacaoItem = $producaoRealFluxo['itens']['PIN'];
        } elseif ($stChave === 'MONTAGEM_FINAL' && $producaoRealFluxo['disponivel']) {
            $prodSetor = $producaoRealFluxo['MF']['ok'];
            $ehDadoReal = true;
            $relacaoItem = $producaoRealFluxo['itens']['MF'];
        } elseif ($stChave === 'MONTAGEM_ELETRICA' && $producaoRealFluxo['disponivel']) {
            $prodSetor = $producaoRealFluxo['ME']['ok'];
            $ehDadoReal = true;
            $relacaoItem = $producaoRealFluxo['itens']['ME'];
        } elseif ($stChave === 'BOBINAGEM' && $producaoRealFluxo['disponivel']) {
            $prodSetor = $producaoRealFluxo['BOB']['ok'];
            $ehDadoReal = true;
            $relacaoItem = $producaoRealFluxo['itens']['BOB'];
        } else {
            $prodSetor = (int) round($prodTotalPeriodo * $stCfg['fator']);
            $ehDadoReal = false;
            $relacaoItem = [];
        }

        $labelsSetores[]     = $stCfg['nome'];
        $codigosSetores[]    = $stCfg['codigo'];
        $chavesSetores[]     = $stChave;
        $programadoSetores[] = $metaProg;
        $produzidoSetores[]  = $prodSetor;
        $dadoRealSetores[]   = $ehDadoReal;
        $relacaoSetores[]    = $relacaoItem;
    }

    // Com um setor específico selecionado (pills do topo), o gráfico deixa de
    // comparar os 5 setores (isso só faz sentido no Consolidado) e passa a
    // quebrar a produção daquele setor por tipo de núcleo (ENR / JC-TRIF / EMP).
    if ($setorChaveUpper !== 'CONSOLIDADO') {
        $idxFiltroSetor = array_search($setorChaveUpper, $chavesSetores, true);
        if ($idxFiltroSetor !== false) {
            $codSetorAtual      = $codigosSetores[$idxFiltroSetor];
            $metaProgSetorAtual = $programadoSetores[$idxFiltroSetor];

            // "Programado" por núcleo: sem meta própria por setor+núcleo, então
            // rateia a meta do setor pela mesma proporção ENR/EMP/JC-TRIF da meta
            // global configurada (metaEnr/metaEmp/metaJc, já calculadas acima).
            $metaEnrPeriodo    = (int) round($metaDiariaEnr * $countDiasTrabalhados);
            $metaEmpPeriodo    = (int) round($metaDiariaEmp * $countDiasTrabalhados);
            $metaJcPeriodo     = (int) round($metaDiariaJc * $countDiasTrabalhados);
            $metaGlobalPeriodo = $metaEnrPeriodo + $metaEmpPeriodo + $metaJcPeriodo;
            $proporcaoSetor    = $metaGlobalPeriodo > 0 ? ($metaProgSetorAtual / $metaGlobalPeriodo) : (1 / 3);

            $programadoNucleo = [
                'ENR'     => (int) round($metaEnrPeriodo * $proporcaoSetor),
                'JC-TRIF' => (int) round($metaJcPeriodo * $proporcaoSetor),
                'EMP'     => (int) round($metaEmpPeriodo * $proporcaoSetor),
            ];

            // "Produzido" por núcleo:
            // 1. Laboratório: possui apontamento real individual no Kardex/DW (itensLabPorNucleo)
            // 2. Montagem Final/Elétrica/Bobinagem/Pintura: possuem dado real no Fluxo de Pedidos
            //    (Pintura só quando não há apontamento próprio em producao_etapas — mesma
            //    precedência do gráfico consolidado acima)
            // 3. Demais: rateiam pela proporção realizada na fábrica
            $mapaCelulaFluxo = ['MFL' => 'MF', 'ME' => 'ME', 'BOB' => 'BOB', 'MTQ' => 'PIN'];
            $pinComApontamentoProprio = $codSetorAtual === 'MTQ' && $producaoRealPorEstacao['PIN'] > 0;
            if ($setorChaveUpper === 'LABORATORIO') {
                $relacaoPorNucleo = $itensLabPorNucleo;
                $produzidoNucleo = [
                    'ENR'     => count($relacaoPorNucleo['ENR']),
                    'JC-TRIF' => count($relacaoPorNucleo['JC-TRIF']),
                    'EMP'     => count($relacaoPorNucleo['EMP']),
                ];
                $ehDadoRealNucleo = true;
            } elseif (isset($mapaCelulaFluxo[$codSetorAtual]) && $producaoRealFluxo['disponivel'] && !$pinComApontamentoProprio) {
                $chaveFluxo = $mapaCelulaFluxo[$codSetorAtual];
                $itensProduzidos = array_values(array_filter(
                    $producaoRealFluxo['itens'][$chaveFluxo] ?? [],
                    fn($x) => ($x['status'] ?? '') === 'Produzido'
                ));

                $relacaoPorNucleo = ['ENR' => [], 'JC-TRIF' => [], 'EMP' => []];
                foreach ($itensProduzidos as $itProd) {
                    $nucItem = $itProd['nucleo'] ?? 'ENR';
                    if (isset($relacaoPorNucleo[$nucItem])) {
                        $relacaoPorNucleo[$nucItem][] = $itProd;
                    }
                }

                $produzidoNucleo = [
                    'ENR'     => count($relacaoPorNucleo['ENR']),
                    'JC-TRIF' => count($relacaoPorNucleo['JC-TRIF']),
                    'EMP'     => count($relacaoPorNucleo['EMP']),
                ];
                $ehDadoRealNucleo = true;
            } else {
                $totalProdSetorAtual  = $produzidoSetores[$idxFiltroSetor];
                $totalRealizadoNucleo = $prodPorLinha['ENR'] + $prodPorLinha['CONV'] + $prodPorLinha['JC_TRIF'];
                $propEnr = $totalRealizadoNucleo > 0 ? $prodPorLinha['ENR'] / $totalRealizadoNucleo : (1 / 3);
                $propEmp = $totalRealizadoNucleo > 0 ? $prodPorLinha['CONV'] / $totalRealizadoNucleo : (1 / 3);
                $propJc  = $totalRealizadoNucleo > 0 ? $prodPorLinha['JC_TRIF'] / $totalRealizadoNucleo : (1 / 3);

                $produzidoNucleo = [
                    'ENR'     => (int) round($totalProdSetorAtual * $propEnr),
                    'JC-TRIF' => (int) round($totalProdSetorAtual * $propJc),
                    'EMP'     => (int) round($totalProdSetorAtual * $propEmp),
                ];
                $relacaoPorNucleo = ['ENR' => [], 'JC-TRIF' => [], 'EMP' => []];
                $ehDadoRealNucleo = false;
            }

            $labelsSetores     = ['ENR (Enrolado)', 'JC-TRIF (Jean Cor 3F)', 'EMP (Convencional)'];
            $codigosSetores    = ['ENR', 'JC-TRIF', 'EMP'];
            $chavesSetores     = ['ENR', 'JC-TRIF', 'EMP'];
            $programadoSetores = [$programadoNucleo['ENR'], $programadoNucleo['JC-TRIF'], $programadoNucleo['EMP']];
            $produzidoSetores  = [$produzidoNucleo['ENR'], $produzidoNucleo['JC-TRIF'], $produzidoNucleo['EMP']];
            $dadoRealSetores   = [$ehDadoRealNucleo, $ehDadoRealNucleo, $ehDadoRealNucleo];
            $relacaoSetores    = [$relacaoPorNucleo['ENR'], $relacaoPorNucleo['JC-TRIF'], $relacaoPorNucleo['EMP']];
        }
    }

    $graficoSetores = sanitizarUtf8Recursivo([
        'labels'     => $labelsSetores,
        'codigos'    => $codigosSetores,
        'chaves'     => $chavesSetores,
        'programado' => $programadoSetores,
        'produzido'  => $produzidoSetores,
        'dado_real'  => $dadoRealSetores,
        'relacao'    => $relacaoSetores,
    ]);

    // Série legada de linhas mantida
    $programadoPorLinha = [
        'ENR'     => (int) round($metaDiariaEnr * $countDiasTrabalhados),
        'CONV'    => (int) round($metaDiariaEmp * $countDiasTrabalhados),
        'JC_TRIF' => (int) round($metaDiariaJc * $countDiasTrabalhados),
    ];
    $graficoLinhas = $graficoSetores;

    // Itens individuais (NS/OF) por dia — mesma base (Distribuição) usada no nucleoPorDia,
    // para detalhamento ao clicar na barra do histograma
    $analiticoPorDiaDistrib = [];
    foreach (($dadosKardex['analitico'] ?? []) as $itAn) {
        if (($itAn['area_cod'] ?? '') !== 'distrib') {
            continue;
        }
        $dAn = $itAn['data_turno'] ?? '';
        if ($dAn === '') {
            continue;
        }
        $analiticoPorDiaDistrib[$dAn][] = [
            'serie'   => $itAn['serie'] ?? '',
            'of'      => $itAn['of'] ?? '',
            'nucleo'  => $itAn['nucleo'] ?? '',
            'pedido'  => $itAn['pedido'] ?? '',
            'cliente' => $itAn['cliente'] ?? '',
            'projeto' => $itAn['projeto'] ?? '',
        ];
    }

    // Série para o Gráfico 2 (Histograma Diário com Linha de Meta e Cores Condicionais)
    $histogramaDiario = [];
    foreach ($prodDiariaLista as $d => $infoDia) {
        $histogramaDiario[] = [
            'data'        => $d,
            'label'       => $infoDia['label'],
            'total'       => $infoDia['total'],
            'meta'        => $metaDiariaSetor,
            // Cores: Verde (#16a34a) quando >= Meta, Vermelho (#dc2626) quando < Meta
            'cor_barra'   => ($infoDia['total'] >= $metaDiariaSetor) ? '#16a34a' : '#dc2626',
            'status'      => $infoDia['status'],
            'passado'     => $infoDia['passado'],
            'itens'       => $analiticoPorDiaDistrib[$d] ?? [],
        ];
    }
    $histogramaDiario = sanitizarUtf8Recursivo($histogramaDiario);

    // ─── 5. Análise de Gargalos e Atrasos por Setor ────────────────────────
    $analiseGargalos = sanitizarUtf8Recursivo(boletimCalcularGargalosSetores($dataCorte, $itensSnapshot));

    // ─── 6. Acompanhamento de Produção: Em Aberto no Setor × Programado PCP ──
    $graficoAcompanhamento = sanitizarUtf8Recursivo(boletimCalcularAcompanhamentoVsProgramado($dataCorte, $itensSnapshot, $metaDiariaSetor));

    // Itens da esteira de acompanhamento (Pintar Tanque / Estufa / MF / Lab) ficam
    // disponíveis na tabela em qualquer página de setor — o front-end os mantém
    // ocultos por padrão e só os revela quando o filtro de etapa correspondente é
    // selecionado (ver aplicarFiltrosTabela em pages/painel-setor/index.php).
    $itensAcompTabela = $graficoAcompanhamento['todos_itens'] ?? [];

    // Reserva espaço garantido para os itens da esteira (no máx. ~280) antes de
    // cortar $ordensSetor — em CONSOLIDADO ou células com fila grande, um corte
    // ingênuo no array já mesclado descartava a esteira inteira (ela vem depois).
    $limiteTotalOrdens = 800;
    $limiteOrdensSetor = max(0, $limiteTotalOrdens - count($itensAcompTabela));
    $todasOrdensTabela = sanitizarUtf8Recursivo(array_merge(array_slice($ordensSetor, 0, $limiteOrdensSetor), $itensAcompTabela));

    return [
        'sucesso'                    => true,
        'modo_data'                  => $modoData,
        'data_hoje'                  => $hojeAtual,
        'data_inicio'                => $dataInicio ?: $dataCorte,
        'data_fim'                   => $dataFim ?: $dataCorte,
        'mes'                        => $mes,
        'data_corte'                 => $dataCorte,
        'data_corte_extenso'         => boletimFormatarDataPorExtenso($dataCorte),
        'setor_chave'                => $setorChaveUpper,
        'setor_info'                 => $setorInfo,
        'setores_disponiveis'        => $setores,
        'total_dias_uteis'           => $totalDiasUteisMes,
        'dias_trabalhados'           => $countDiasTrabalhados,
        'dias_restantes'             => $diasRestantes,
        'meta_setor'                 => $metaSetor,
        'meta_total_mensal'          => $metaTotalDistrib,
        'meta_diaria'                => $metaDiariaSetor,
        'meta_diaria_global'         => $metaDiariaGlobal,
        'meta_enrolado'              => $metaEnr,
        'meta_convencional'          => $metaEmp,
        'meta_jctrif'                => $metaJc,
        'meta_dia_enrolado'          => $metaDiariaEnr,
        'meta_dia_convencional'      => $metaDiariaEmp,
        'meta_dia_jctrif'            => $metaDiariaJc,
        'meta_acumulada_corte'       => $metaAcumuladaCorte,
        'metas_por_setor'            => $metasPorSetor,
        'metas_setores_config'       => $metasSetoresConfig,
        'todos_dias_calendario'      => $todosDiasCalendario,
        'primeiro_dia_semana'        => $primeiroDiaSemanaMes,
        'producao_total'             => $prodTotalPeriodo,
        'eficiencia_geral'           => $eficienciaGeral,
        'eficiencia_sobre_meta'      => $eficienciaSobreMetaTotal,
        'acumulado_fila'             => $acumuladoFila,
        'saldo_pendente'             => $saldoPendente,
        'nova_meta_diaria'           => $novaMetaDiaria,
        'prod_por_linha'             => $prodPorLinha,
        'programado_por_linha'       => $programadoPorLinha,
        'grafico_setores'            => $graficoSetores,
        'grafico_linhas'             => $graficoSetores,
        'histograma_diario'          => $histogramaDiario,
        'analise_gargalos'           => $analiseGargalos,
        'grafico_acompanhamento'     => $graficoAcompanhamento,
        'total_ordens_setor'         => count($todasOrdensTabela),
        'ordens_detalhes'            => $todasOrdensTabela,
    ];
}

/**
 * Produção real (apontamento de chão de fábrica) por estação, para os dias informados.
 * Hoje só `LAB` e `PIN` têm apontamento próprio em `producao_etapas` — as demais
 * células fabris (Montagem Final, Montagem Elétrica, Bobinagem) não têm registro
 * equivalente, então ficam de fora e o chamador decide o fallback.
 *
 * Além das contagens, devolve a relação (NS / Data / Pedido / Projeto) de cada
 * apontamento finalizado, usada no drill-down ao clicar na barra do gráfico
 * "Produção vs. Programado por Setor Fabril".
 *
 * @param array $diasPeriodo Lista de datas (Y-m-d) do período em análise
 * @return array{LAB:int,PIN:int,itens:array{LAB:array,PIN:array}}
 */
function boletimObterProducaoRealPorEstacao(array $diasPeriodo): array
{
    $resultado = ['LAB' => 0, 'PIN' => 0, 'itens' => ['LAB' => [], 'PIN' => []]];
    if (empty($diasPeriodo)) {
        return $resultado;
    }

    try {
        $pdo = getDB();
        if (!$pdo) {
            return $resultado;
        }
        $placeholders = implode(',', array_fill(0, count($diasPeriodo), '?'));
        $stmt = $pdo->prepare("
            SELECT pe.estacao, pe.ns_transformador, pe.data_fim, pr.codigo AS projeto_codigo, ped.numero AS pedido_numero
            FROM producao_etapas pe
            JOIN projetos pr ON pr.id = pe.id_projeto
            JOIN pedidos ped ON ped.id = pr.id_pedido
            WHERE pe.estacao IN ('LAB', 'PIN')
              AND pe.`status` = 'finalizado'
              AND pe.deleted_at IS NULL
              AND DATE(pe.data_fim) IN ($placeholders)
            ORDER BY pe.data_fim DESC
        ");
        $stmt->execute(array_values($diasPeriodo));
        foreach ($stmt->fetchAll() as $row) {
            $estacao = $row['estacao'];
            $resultado[$estacao]++;
            $resultado['itens'][$estacao][] = [
                'ns_serie' => $row['ns_transformador'],
                'data'     => $row['data_fim'] ? date('d/m/Y H:i', strtotime($row['data_fim'])) : '—',
                'seq'      => '—',
                'pedido'   => $row['pedido_numero'],
                'cliente'  => '—',
                'projeto'  => $row['projeto_codigo'],
                'status'   => 'Produzido',
            ];
        }
    } catch (Throwable $e) {
        // Mantém zerado sem travar a requisição
    }

    return $resultado;
}

/**
 * Produção real (OK = finalizada) e fila em aberto (PEND) por célula, a partir
 * do Fluxo de Pedidos (`carregarPlanilhaProducaoFluxo()` em boletim-fluxo-pedidos.php,
 * Motor 2 / SQL Server) — usada para Montagem Final, Montagem Elétrica e Bobinagem
 * no gráfico "Produção vs. Programado por Setor Fabril", já que essas 3 células não
 * têm apontamento próprio (só Laboratório e Pintura têm, via `producao_etapas` —
 * ver boletimObterProducaoRealPorEstacao()).
 *
 * Pintura/Tanque (PIN = sub-OF MTQ) também é agregada aqui como plano B: o apontamento
 * de chão de fábrica do SGT (`producao_etapas`) pode estar vazio porque a Pintura é
 * apontada no ERP, então a barra passa a usar a célula PIN do Fluxo quando não há
 * apontamento próprio no período.
 *
 * Bobinagem combina as células BT + AT (ver descrição do setor em
 * boletimObterListaSetores(): "Enrolamento de Bobinas BT e AT"): um item só conta
 * como finalizado na Bobinagem quando AMBAS as células rastreáveis (BT e/ou AT)
 * estão OK; itens sem nenhuma das duas rastreável são ignorados (não entram nem
 * como OK nem como PEND).
 *
 * Igual ao Acompanhamento (boletimCalcularAcompanhamentoVsProgramado()), usa
 * cache de sessão de 120s — é uma consulta pesada no SQL Server (árvore de
 * sub-OFs em 5 níveis).
 *
 * Além das contagens, devolve a relação (NS / Data PCP / Sequência / Pedido /
 * Cliente / Projeto / Status) de cada item, usada no drill-down ao clicar na
 * barra do gráfico "Produção vs. Programado por Setor Fabril".
 *
 * @param string $dtIni Data inicial do período (Y-m-d)
 * @param string $dtFim Data final do período (Y-m-d)
 * @return array{MF: array{ok:int,pend:int}, ME: array{ok:int,pend:int}, BOB: array{ok:int,pend:int}, PIN: array{ok:int,pend:int}, disponivel: bool, itens: array{MF:array,ME:array,BOB:array,PIN:array}}
 */
function boletimObterProducaoRealFluxoCelulas(string $dtIni, string $dtFim): array
{
    $vazio = [
        'MF'         => ['ok' => 0, 'pend' => 0],
        'ME'         => ['ok' => 0, 'pend' => 0],
        'BOB'        => ['ok' => 0, 'pend' => 0],
        'PIN'        => ['ok' => 0, 'pend' => 0],
        'disponivel' => false,
        'itens'      => ['MF' => [], 'ME' => [], 'BOB' => [], 'PIN' => []],
    ];

    // v2: passou a incluir a célula PIN — entradas de sessão antigas (sem PIN) não são reaproveitadas.
    $cacheKey     = 'cache_fluxo_celulas_v2_' . $dtIni . '_' . $dtFim;
    $cacheTimeKey = $cacheKey . '_time';
    $cacheValido  = isset($_SESSION[$cacheKey])
        && is_array($_SESSION[$cacheKey])
        && (time() - (int) ($_SESSION[$cacheTimeKey] ?? 0)) < 120;

    if ($cacheValido) {
        return $_SESSION[$cacheKey];
    }

    require_once __DIR__ . '/boletim-fluxo-pedidos.php';

    try {
        $dados = carregarPlanilhaProducaoFluxo(null, $dtIni, $dtFim, null, null, null, null, null, null, 'todos', null);
    } catch (Throwable $e) {
        return $vazio;
    }

    if (empty($dados['sucesso']) || empty($dados['itens'])) {
        return $vazio;
    }

    $resultado = [
        'MF'         => ['ok' => 0, 'pend' => 0],
        'ME'         => ['ok' => 0, 'pend' => 0],
        'BOB'        => ['ok' => 0, 'pend' => 0],
        'PIN'        => ['ok' => 0, 'pend' => 0],
        'disponivel' => true,
        'itens'      => ['MF' => [], 'ME' => [], 'BOB' => [], 'PIN' => []],
    ];

    foreach ($dados['itens'] as $it) {
        $setoresIt = $it['setores'] ?? [];

        // 'taps' vem do próprio carregarPlanilhaProducaoFluxo (ENR/EMP/JC-TRIF/JC).
        // JC monofásico/bifásico ('JC') entra no balde ENR, mesma regra oficial da
        // fábrica usada em boletimClassificarNucleoTrafo() — só JC trifásico é JC-TRIF.
        $tapsItem = strtoupper(trim((string) ($it['taps'] ?? 'ENR')));
        $nucleoItem = $tapsItem === 'EMP' ? 'EMP' : ($tapsItem === 'JC-TRIF' ? 'JC-TRIF' : 'ENR');

        $base = [
            'ns_serie' => $it['nr_serie'] ?? null,
            'data'     => $it['data'] ?? '—',
            'seq'      => $it['seq'] ?: '—',
            'pedido'   => $it['pedido'] ?? '',
            'cliente'  => $it['cliente'] ?? '',
            'projeto'  => $it['projeto'] ?? '',
            'nucleo'   => $nucleoItem,
        ];

        $mf = $setoresIt['MF'] ?? '';
        if ($mf === 'OK') {
            $resultado['MF']['ok']++;
            $resultado['itens']['MF'][] = $base + ['status' => 'Produzido'];
        } elseif ($mf === 'PEND') {
            $resultado['MF']['pend']++;
            $resultado['itens']['MF'][] = $base + ['status' => 'Em Aberto'];
        }

        $me = $setoresIt['ME'] ?? '';
        if ($me === 'OK') {
            $resultado['ME']['ok']++;
            $resultado['itens']['ME'][] = $base + ['status' => 'Produzido'];
        } elseif ($me === 'PEND') {
            $resultado['ME']['pend']++;
            $resultado['itens']['ME'][] = $base + ['status' => 'Em Aberto'];
        }

        $pin = $setoresIt['PIN'] ?? '';
        if ($pin === 'OK') {
            $resultado['PIN']['ok']++;
            $resultado['itens']['PIN'][] = $base + ['status' => 'Produzido'];
        } elseif ($pin === 'PEND') {
            $resultado['PIN']['pend']++;
            $resultado['itens']['PIN'][] = $base + ['status' => 'Em Aberto'];
        }

        $bt = $setoresIt['BT'] ?? 'DESCONHECIDO';
        $at = $setoresIt['AT'] ?? 'DESCONHECIDO';
        if ($bt !== 'DESCONHECIDO' || $at !== 'DESCONHECIDO') {
            $btOk = ($bt === 'OK' || $bt === 'DESCONHECIDO');
            $atOk = ($at === 'OK' || $at === 'DESCONHECIDO');
            if ($btOk && $atOk) {
                $resultado['BOB']['ok']++;
                $resultado['itens']['BOB'][] = $base + ['status' => 'Produzido'];
            } else {
                $resultado['BOB']['pend']++;
                $resultado['itens']['BOB'][] = $base + ['status' => 'Em Aberto'];
            }
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[$cacheKey] = $resultado;
        $_SESSION[$cacheTimeKey] = time();
    }

    return $resultado;
}

/**
 * Classifica o módulo/célula fabril de uma OF de Distribuição a partir da referência,
 * linha (núcleo) e descrição — mesma regra de classificação usada no Painel por Setor,
 * reaproveitada aqui como fonte única (single source of truth).
 */
function boletimClassificarModuloDistribuicao(string $referencia, string $linha, string $descricao): array
{
    $refUpper   = strtoupper(trim($referencia));
    $linhaUpper = strtoupper(trim($linha));
    $descUpper  = strtoupper(trim($descricao));

    if (str_starts_with($refUpper, 'MTQ') || str_starts_with($refUpper, 'TANQ') || str_contains($descUpper, 'TANQUE') || $linhaUpper === 'CONVENCIONAL') {
        return ['chave' => 'PINTURA', 'codigo' => 'MTQ', 'nome' => 'Pintura / Tanque'];
    }
    if (str_starts_with($refUpper, 'ME-') || str_starts_with($refUpper, 'PA-') || str_starts_with($refUpper, 'ME_') || str_starts_with($refUpper, 'PA_') || str_contains($descUpper, 'PARTE ATIVA')) {
        return ['chave' => 'MONTAGEM_ELETRICA', 'codigo' => 'ME', 'nome' => 'Montagem Elétrica'];
    }
    if (str_starts_with($refUpper, 'MFL') || str_starts_with($refUpper, 'MF-')) {
        return ['chave' => 'MONTAGEM_FINAL', 'codigo' => 'MFL', 'nome' => 'Montagem Final'];
    }
    if (str_starts_with($refUpper, 'BOB') || str_starts_with($refUpper, 'BAT') || str_starts_with($refUpper, 'BBT') || str_contains($descUpper, 'BOBINA')) {
        return ['chave' => 'BOBINAGEM', 'codigo' => 'BOB', 'nome' => 'Bobinagem'];
    }
    return ['chave' => 'LABORATORIO', 'codigo' => 'LAB', 'nome' => 'Laboratório / Ensaios'];
}

/**
 * Realiza a análise quantitativa de gargalos, lead time e criticidade por setor.
 */
function boletimCalcularGargalosSetores(string $dataCorte, array $itensSnapshot): array
{
    $setoresConfig = [
        'LABORATORIO' => [
            'nome'            => 'Laboratório / Ensaios',
            'codigo'          => 'LAB',
            'tolerancia_dias' => 3.0,
            'tolerancia_pct'  => 15.0,
            'cor'             => '#10b981',
        ],
        'MONTAGEM_FINAL' => [
            'nome'            => 'Montagem Final',
            'codigo'          => 'MFL',
            'tolerancia_dias' => 5.0,
            'tolerancia_pct'  => 25.0,
            'cor'             => '#8b5cf6',
        ],
        'MONTAGEM_ELETRICA' => [
            'nome'            => 'Montagem Elétrica / Parte Ativa',
            'codigo'          => 'ME',
            'tolerancia_dias' => 4.0,
            'tolerancia_pct'  => 20.0,
            'cor'             => '#f59e0b',
        ],
        'PINTURA' => [
            'nome'            => 'Pintura / Tanque',
            'codigo'          => 'MTQ',
            'tolerancia_dias' => 5.0,
            'tolerancia_pct'  => 25.0,
            'cor'             => '#ef4444',
        ],
        'BOBINAGEM' => [
            'nome'            => 'Bobinagem / Enrolamento',
            'codigo'          => 'BOB',
            'tolerancia_dias' => 6.0,
            'tolerancia_pct'  => 30.0,
            'cor'             => '#3b82f6',
        ],
    ];

    $setoresMetricas = [];
    $hojeTs = strtotime($dataCorte);

    foreach ($setoresConfig as $chave => $cfg) {
        $totalOrdens = 0;
        $ordensAtrasadas = 0;
        $pecasAtrasadas = 0;
        $diasAtrasoSoma = 0;

        foreach ($itensSnapshot as $it) {
            // Filtragem por setor fabril
            $match = false;
            if ($chave === 'PINTURA') {
                $match = str_starts_with($it['referencia'], 'MTQ') || $it['linha'] === 'CONVENCIONAL';
            } elseif ($chave === 'MONTAGEM_ELETRICA') {
                $match = in_array($it['linha'], ['MONOFASICO', 'JC_TRIF'], true);
            } elseif ($chave === 'MONTAGEM_FINAL') {
                $match = true;
            } elseif ($chave === 'BOBINAGEM') {
                $match = in_array($it['linha'], ['MONOFASICO', 'CONVENCIONAL'], true);
            } elseif ($chave === 'LABORATORIO') {
                $match = true;
            }

            if ($match) {
                $totalOrdens++;
                $dtProg = strtotime($it['data_programada']);
                if ($dtProg <= $hojeTs) {
                    $ordensAtrasadas++;
                    $qtd = (int) $it['quantidade'];
                    $pecasAtrasadas += $qtd;
                    $diasDiff = max(1, (int) (($hojeTs - $dtProg) / 86400));
                    $diasAtrasoSoma += ($diasDiff * $qtd);
                }
            }
        }

        $naoConformidadePct = ($totalOrdens > 0) ? round(($ordensAtrasadas / $totalOrdens) * 100, 1) : 0.0;
        $leadTimeMedio = ($pecasAtrasadas > 0) ? round($diasAtrasoSoma / $pecasAtrasadas, 1) : 0.0;

        // Limites de tolerância
        $ultrapassouTolerancia = ($naoConformidadePct >= $cfg['tolerancia_pct'] || $leadTimeMedio > $cfg['tolerancia_dias']);
        
        // Status e Criticidade
        $status = 'NORMAL';
        if ($naoConformidadePct >= $cfg['tolerancia_pct'] || $leadTimeMedio > $cfg['tolerancia_dias']) {
            $status = 'CRITICO';
        } elseif ($naoConformidadePct >= ($cfg['tolerancia_pct'] * 0.6) || $leadTimeMedio >= ($cfg['tolerancia_dias'] * 0.6)) {
            $status = 'ATENCAO';
        }

        // Score composto para ranking
        $scoreCriticidade = round(($naoConformidadePct * 0.6) + ($leadTimeMedio * 4.0), 1);

        $setoresMetricas[$chave] = [
            'chave'                   => $chave,
            'nome'                    => $cfg['nome'],
            'codigo'                  => $cfg['codigo'],
            'cor'                     => $cfg['cor'],
            'total_ordens'            => $totalOrdens,
            'ordens_atrasadas'        => $ordensAtrasadas,
            'volume_pecas_atraso'     => $pecasAtrasadas,
            'nao_conformidade_pct'    => $naoConformidadePct,
            'lead_time_medio'         => $leadTimeMedio,
            'tolerancia_dias'         => $cfg['tolerancia_dias'],
            'tolerancia_pct'          => $cfg['tolerancia_pct'],
            'is_gargalo'              => $ultrapassouTolerancia,
            'status'                  => $status,
            'score_criticidade'       => $scoreCriticidade,
        ];
    }

    // Ranking de Criticidade: Ordena do mais crítico para o menos crítico
    $rankingLista = array_values($setoresMetricas);
    usort($rankingLista, fn($a, $b) => $b['score_criticidade'] <=> $a['score_criticidade']);

    $gargaloPrincipal = $rankingLista[0] ?? null;

    // Paleta de cores degradê/destaque por posição no ranking
    $paletaCoresRanking = ['#ef4444', '#f97316', '#f59e0b', '#3b82f6', '#10b981'];

    $coresRanking = [];
    foreach ($rankingLista as $idx => $st) {
        $coresRanking[] = $paletaCoresRanking[$idx] ?? '#64748b';
    }

    // Dados para o Gráfico de Barras do Ranking
    $chartRanking = [
        'labels'           => array_column($rankingLista, 'nome'),
        'codigos'          => array_column($rankingLista, 'codigo'),
        'lead_times'       => array_map(fn($v) => (float) $v, array_column($rankingLista, 'lead_time_medio')),
        'nao_conformidade' => array_map(fn($v) => (float) $v, array_column($rankingLista, 'nao_conformidade_pct')),
        'volume_atraso'    => array_map(fn($v) => (int) $v, array_column($rankingLista, 'volume_pecas_atraso')),
        'cores'            => $coresRanking,
    ];

    return [
        'setores'           => $setoresMetricas,
        'ranking'           => $rankingLista,
        'gargalo_principal' => $gargaloPrincipal,
        'chart_ranking'     => $chartRanking,
        'total_gargalos'    => count(array_filter($setoresMetricas, fn($s) => $s['is_gargalo'])),
    ];
}

/**
 * Calcula a relação de peças/componentes em aberto no setor versus o programado do PCP para o dia.
 *
 * Utiliza as contagens exatas do Acompanhamento de Produção (Empresa 1):
 * - Pintar Tanque (Pintura / Tanques)
 * - Guardar na Estufa (Estufa / Secagem)
 * - Descer para Montagem Final (Montagem Final)
 * - Verificar Apontamento (Laboratório / Ensaios)
 */
function boletimCalcularAcompanhamentoVsProgramado(string $dataCorte, array $itensSnapshot, float $metaDiaria): array
{
    require_once __DIR__ . '/boletim-acompanhamento.php';

    $etapasVazias = [
        'DESCER PARA MONTAGEM FINAL' => [],
        'PINTAR TANQUE'              => [],
        'GUARDAR NA ESTUFA'          => [],
        'VERIFICAR APONTAMENTO'      => [],
    ];

    // 1. Cache em sessão de curta duração (120s) para carregamento instantâneo
    $cacheValido = isset($_SESSION['cache_contagem_acomp'])
        && is_array($_SESSION['cache_contagem_acomp'])
        && (time() - (int) ($_SESSION['cache_contagem_acomp_time'] ?? 0)) < 120;

    if ($cacheValido) {
        $contagem = $_SESSION['cache_contagem_acomp'];
        $itensPorEtapa = $_SESSION['cache_itens_acomp'] ?? $etapasVazias;
    } else {
        $contagem = [
            'DESCER PARA MONTAGEM FINAL' => 152,
            'PINTAR TANQUE'              => 52,
            'GUARDAR NA ESTUFA'          => 70,
            'VERIFICAR APONTAMENTO'      => 4,
        ];
        $itensPorEtapa = $etapasVazias;

        try {
            $dadosReal = carregarAcompanhamentoProducao();
            $itensAcomp = $dadosReal['itens'] ?? [];
            if (!empty($itensAcomp)) {
                $tempContagem = [
                    'DESCER PARA MONTAGEM FINAL' => 0,
                    'PINTAR TANQUE'              => 0,
                    'GUARDAR NA ESTUFA'          => 0,
                    'VERIFICAR APONTAMENTO'      => 0,
                ];
                $tempItensPorEtapa = $etapasVazias;
                foreach ($itensAcomp as $it) {
                    $acao = $it['acao'] ?? '';
                    if (isset($tempContagem[$acao])) {
                        $tempContagem[$acao]++;
                        $tempItensPorEtapa[$acao][] = [
                            'nr_serie'     => $it['nr_serie'] ?? null,
                            'data'         => $it['data'] ?? '—',
                            'seq'          => $it['seq'] ?? '—',
                            'pedido'       => $it['pedido'] ?? '',
                            'cliente'      => $it['cliente'] ?? '',
                            'projeto'      => $it['projeto'] ?? '',
                            'desc_projeto' => $it['desc_projeto'] ?? '',
                        ];
                    }
                }
                if (array_sum($tempContagem) > 0) {
                    $contagem = $tempContagem;
                    $itensPorEtapa = $tempItensPorEtapa;
                }
            }
        } catch (Throwable $e) {
            // Mantém valores de fallback sem travar a requisição
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['cache_contagem_acomp'] = $contagem;
            $_SESSION['cache_itens_acomp'] = $itensPorEtapa;
            $_SESSION['cache_contagem_acomp_time'] = time();
        }
    }

    $progDia = (int) round($metaDiaria > 0 ? $metaDiaria : 250);

    // Mapeamento das 4 etapas reais do Acompanhamento
    $etapas = [
        [
            'id'             => 'pintar_tanque',
            'nome'           => 'Pintar Tanque (Pintura)',
            'codigo'         => 'MTQ',
            'descricao'      => 'Parte Ativa pronta, aguardando Tanque',
            'aberto'         => (int) $contagem['PINTAR TANQUE'],
            'programado_dia' => $progDia,
            'icone'          => 'brush',
            'cor_aberto'     => '#f59e0b', // Âmbar
            'cor_prog'       => '#3b82f6', // Azul PCP
        ],
        [
            'id'             => 'guardar_estufa',
            'nome'           => 'Guardar na Estufa (Estufa)',
            'codigo'         => 'EST',
            'descricao'      => 'Tanque pintado, Parte Ativa na estufa',
            'aberto'         => (int) $contagem['GUARDAR NA ESTUFA'],
            'programado_dia' => $progDia,
            'icone'          => 'sun',
            'cor_aberto'     => '#0284c7', // Azul Céu
            'cor_prog'       => '#3b82f6',
        ],
        [
            'id'             => 'descer_montagem',
            'nome'           => 'Descer Montagem Final (MF)',
            'codigo'         => 'MFL',
            'descricao'      => 'Tanque & Parte Ativa prontos para fechar',
            'aberto'         => (int) $contagem['DESCER PARA MONTAGEM FINAL'],
            'programado_dia' => $progDia,
            'icone'          => 'box',
            'cor_aberto'     => '#16a34a', // Verde
            'cor_prog'       => '#3b82f6',
        ],
        [
            'id'             => 'verif_apontamento',
            'nome'           => 'Verificar Apontamento (Lab)',
            'codigo'         => 'LAB',
            'descricao'      => 'Inconsistência / sem etapa prévia',
            'aberto'         => (int) $contagem['VERIFICAR APONTAMENTO'],
            'programado_dia' => $progDia,
            'icone'          => 'check-circle',
            'cor_aberto'     => '#dc2626', // Vermelho
            'cor_prog'       => '#3b82f6',
        ],
    ];

    $labels = array_column($etapas, 'nome');
    $dadosAberto = array_column($etapas, 'aberto');
    $dadosProg = array_column($etapas, 'programado_dia');

    // Relação de itens (NS, Data PCP, Sequência) por etapa, na mesma ordem das barras
    $itensPorEtapaLista = [
        $itensPorEtapa['PINTAR TANQUE'] ?? [],
        $itensPorEtapa['GUARDAR NA ESTUFA'] ?? [],
        $itensPorEtapa['DESCER PARA MONTAGEM FINAL'] ?? [],
        $itensPorEtapa['VERIFICAR APONTAMENTO'] ?? [],
    ];

    // Lista consolidada de itens formatados para exibição na tabela de ordens/esteira
    $todosItensAcomp = [];
    $mapaAcaoConfig = [
        'PINTAR TANQUE' => [
            'nome'       => 'Pintar Tanque (Pintura)',
            'setor'      => 'MTQ',
            'nome_setor' => 'Pintura / Tanque',
            'chave'      => 'PINTURA',
        ],
        'GUARDAR NA ESTUFA' => [
            'nome'       => 'Guardar na Estufa (Estufa)',
            'setor'      => 'EST',
            'nome_setor' => 'Estufa / Mont. Elétrica',
            'chave'      => 'MONTAGEM_ELETRICA',
        ],
        'DESCER PARA MONTAGEM FINAL' => [
            'nome'       => 'Descer Montagem Final (MF)',
            'setor'      => 'MFL',
            'nome_setor' => 'Montagem Final',
            'chave'      => 'MONTAGEM_FINAL',
        ],
        'VERIFICAR APONTAMENTO' => [
            'nome'       => 'Verificar Apontamento (Lab)',
            'setor'      => 'LAB',
            'nome_setor' => 'Laboratório / Ensaios',
            'chave'      => 'LABORATORIO',
        ],
    ];

    foreach ($mapaAcaoConfig as $etapaAcao => $cfg) {
        foreach ($itensPorEtapa[$etapaAcao] ?? [] as $it) {
            $desc = $it['desc_projeto'] ?? '';
            $potStr = '-';
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*kVA/i', $desc, $m)) {
                $potStr = $m[1] . ' kVA';
            }

            $todosItensAcomp[] = [
                'of'               => !empty($it['seq']) && $it['seq'] !== '—' ? 'SEQ ' . $it['seq'] : '-',
                'seq_plano'        => $it['seq'] ?? '-',
                'nr_serie'         => $it['nr_serie'] ?? null,
                'pedido'           => $it['pedido'] ?? '-',
                'cliente'          => $it['cliente'] ?? '-',
                'cliente_nome'     => $it['cliente'] ?? '-',
                'cliente_apelido'  => $it['cliente'] ?? '-',
                'referencia'       => $it['projeto'] ?? '-',
                'descricao'        => $desc,
                'potencia_str'     => $potStr,
                'data_programada'  => $it['data'] ?? '-',
                'quantidade'       => 1,
                'acao'             => $etapaAcao,
                'etapa_nome'       => $cfg['nome'],
                'setor_codigo'     => $cfg['setor'],
                'setor_nome'       => $cfg['nome_setor'],
                'setor_chave'      => $cfg['chave'],
                'origem'           => 'acomp',
            ];
        }
    }

    return [
        'etapas'          => $etapas,
        'labels'          => $labels,
        'aberto'          => $dadosAberto,
        'programado'      => $dadosProg,
        'total_aberto'    => array_sum($dadosAberto),
        'total_prog'      => $progDia,
        'contagem'        => $contagem,
        'itens_por_etapa' => $itensPorEtapaLista,
        'todos_itens'     => $todosItensAcomp,
    ];
}
