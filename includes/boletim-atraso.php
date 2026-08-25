<?php
declare(strict_types=1);

/**
 * Módulo de Análise e Métricas de Atraso da Distribuição.
 *
 * Cruza as ordens do Plano Mestre sem apontamento no laboratório (snapshot)
 * com os apontamentos diários reais de produção (Kardex / SQL Server).
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/boletim-planilha.php';
require_once __DIR__ . '/helpers.php';

define('BOLETIM_SNAPSHOT_DIR', is_dir(__DIR__ . '/../PLANILHA QUE ATUALIZA') ? __DIR__ . '/../PLANILHA QUE ATUALIZA' : __DIR__ . '/../PLANILHA Q ATUALIZA');

/**
 * Retorna a lista de snapshots disponíveis nas pastas de planilhas.
 */
function boletimListarSnapshotsDisponiveis(): array
{
    $dirs = [
        __DIR__ . '/../PLANILHA QUE ATUALIZA',
        __DIR__ . '/../PLANILHA Q ATUALIZA',
        __DIR__ . '/../planilhas',
        __DIR__ . '/../storage/snapshots',
    ];

    $arquivos = [];
    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            $encontrados = glob($dir . '/snapshot_*.csv') ?: [];
            foreach ($encontrados as $e) {
                $arquivos[] = $e;
            }
        }
    }

    if (empty($arquivos)) {
        return [];
    }

    $snapshots = [];
    foreach ($arquivos as $arq) {
        $basename = basename($arq);
        if (preg_match('/snapshot_(\d{4}-\d{2}-\d{2})\.csv$/i', $basename, $m)) {
            $dataSnap = $m[1];
            if (!isset($snapshots[$dataSnap]) || filemtime($arq) > ($snapshots[$dataSnap]['mtime'] ?? 0)) {
                $snapshots[$dataSnap] = [
                    'data'     => $dataSnap,
                    'arquivo'  => $arq,
                    'basename' => $basename,
                    'mtime'    => filemtime($arq),
                    'tamanho'  => filesize($arq),
                ];
            }
        }
    }
    krsort($snapshots);
    return $snapshots;
}

/**
 * Carrega e processa os dados de um arquivo de snapshot.
 */
function boletimCarregarSnapshot(?string $dataSnapshot = null): array
{
    $disponiveis = boletimListarSnapshotsDisponiveis();
    if (empty($disponiveis)) {
        return ['sucesso' => false, 'erro' => 'Nenhum arquivo de snapshot encontrado na pasta PLANILHA Q ATUALIZA.', 'itens' => []];
    }

    $escolhido = null;
    if ($dataSnapshot !== null && isset($disponiveis[$dataSnapshot])) {
        $escolhido = $disponiveis[$dataSnapshot];
    } else {
        // Pega o mais recente
        $escolhido = reset($disponiveis);
    }

    $caminho = $escolhido['arquivo'];
    if (!is_file($caminho) || !is_readable($caminho)) {
        return ['sucesso' => false, 'erro' => "Arquivo não acessível: {$caminho}", 'itens' => []];
    }

    $fp = @fopen($caminho, 'r');
    if (!$fp) {
        return ['sucesso' => false, 'erro' => "Falha ao abrir {$caminho}", 'itens' => []];
    }

    $header = fgetcsv($fp, 0, ';', '"', '\\');
    if (!$header) {
        fclose($fp);
        return ['sucesso' => false, 'erro' => 'Arquivo CSV de snapshot está vazio ou inválido.', 'itens' => []];
    }

    // Remove UTF-8 BOM se presente
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    $header = array_map('trim', $header);

    $itens = [];
    while (($row = fgetcsv($fp, 0, ';', '"', '\\')) !== false) {
        if (count($row) !== count($header)) {
            continue;
        }
        $r = array_combine($header, $row);

        $qtd = (float) str_replace(',', '.', (string) ($r['Quantidade'] ?? 0));
        if ($qtd <= 0) {
            $qtd = (float) str_replace(',', '.', (string) ($r['QtdAproduzir'] ?? 0));
        }
        if ($qtd <= 0) {
            $qtd = 1.0;
        }

        $dtProgRaw = trim((string) ($r['DataHoraProducaoAux'] ?? ''));
        $dtProg    = substr($dtProgRaw, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dtProg)) {
            $dtProg = $escolhido['data'];
        }

        $nuc   = strtoupper(trim((string) ($r['ds_TpEnrolamentoNucleo'] ?? '')));
        $fase  = strtoupper(trim((string) ($r['nrofasesTrafo'] ?? '')));
        $ref   = trim((string) ($r['cd_Referencia'] ?? ''));
        $prod  = trim((string) ($r['ds_Prod'] ?? ''));
        $kva   = (float) str_replace(',', '.', (string) ($r['PotenciaKVA'] ?? 0));

        // Classificação da Linha para o Atraso da Distribuição:
        // 1. JC-TRIF: Núcleo JC + Trifásico (TRI ou 3F)
        // 2. MONOFASICO: MON, 1F, BIF, 2F, ENR, ou JC Monofásico/Bifásico
        // 3. CONVENCIONAL: EMP, EMP-LM e outros trifásicos de distribuição
        $linha = 'CONVENCIONAL';
        if ($nuc === 'JC' && ($fase === 'TRI' || $fase === '3F' || preg_match('/\b3F\b/i', $prod))) {
            $linha = 'JC_TRIF';
        } elseif ($fase === 'MON' || $fase === '1F' || $fase === 'BIF' || $fase === '2F' || $nuc === 'ENR' || ($nuc === 'JC' && $fase !== 'TRI')) {
            $linha = 'MONOFASICO';
        } else {
            $linha = 'CONVENCIONAL';
        }

        // Mês e Ano de programação formatado (ex: AGOSTO/26, JULHO/26)
        $mesNum = (int) date('n', strtotime($dtProg));
        $anoDois = date('y', strtotime($dtProg));
        $mesAnoKey = date('Y-m', strtotime($dtProg));
        $mesesNomes = [
            1 => 'JANEIRO', 2 => 'FEVEREIRO', 3 => 'MARÇO', 4 => 'ABRIL',
            5 => 'MAIO', 6 => 'JUNHO', 7 => 'JULHO', 8 => 'AGOSTO',
            9 => 'SETEMBRO', 10 => 'OUTUBRO', 11 => 'NOVEMBRO', 12 => 'DEZEMBRO'
        ];
        $mesAnoRotulo = ($mesesNomes[$mesNum] ?? 'MÊS') . '/' . $anoDois;

        $itens[] = [
            'quantidade'        => $qtd,
            'data_programada'   => $dtProg,
            'mes_ano_key'       => $mesAnoKey,
            'mes_ano_rotulo'    => $mesAnoRotulo,
            'referencia'        => $ref,
            'descricao'         => $prod,
            'cliente'           => trim((string) ($r['Nome'] ?? $r['Apelido'] ?? '')),
            'cliente_apelido'   => trim((string) ($r['Apelido'] ?? '')),
            'pedido'            => trim((string) ($r['cdPedido'] ?? '')),
            'potencia_str'      => trim((string) ($r['ds_potencia'] ?? '')),
            'potencia_kva'      => $kva,
            'fases'             => $fase,
            'nucleo'            => $nuc,
            'linha'             => $linha,
            'seq_plano'         => (float) str_replace(',', '.', (string) ($r['SeqPlano'] ?? 0)),
            'dt_limite_entrega' => trim((string) ($r['dt_LimiteEntrega'] ?? '')),
            'estado'            => trim((string) ($r['cd_SglEstado'] ?? '')),
        ];
    }
    fclose($fp);

    return [
        'sucesso'        => true,
        'snapshot_info'  => $escolhido,
        'data_snapshot'  => $escolhido['data'],
        'total_itens'    => count($itens),
        'itens'          => $itens,
    ];
}

/**
 * Calcula todas as métricas consolidadas de atraso para uma data de corte e filtros de meses.
 *
 * @param string $dataCorte Data selecionada (Y-m-d), ex: '2026-08-24'
 * @param array $mesesFiltro Array de meses a incluir (ex: ['2026-07', '2026-08']) ou vazio para todos
 * @param string|null $dataSnapshot Data do arquivo de snapshot a usar
 */
function boletimCalcularMetricasAtraso(string $dataCorte, array $mesesFiltro = [], ?string $dataSnapshot = null): array
{
    $resSnap = boletimCarregarSnapshot($dataSnapshot);
    if (!$resSnap['sucesso']) {
        return ['sucesso' => false, 'erro' => $resSnap['erro']];
    }

    $itens = $resSnap['itens'];
    $mesRef = substr($dataCorte, 0, 7); // Ex: '2026-08'

    // 1. Obtém dados de produção diária do Kardex para o mês de referência
    $dadosKardex = boletimObterDadosMes($mesRef);
    $kardexPorDia = $dadosKardex['nucleoPorDia'] ?? [];

    // 2. Determina os dias úteis trabalhados até a data de corte
    $diasUteisTrabalhados = [];
    foreach ($kardexPorDia as $diaYmd => $prodDia) {
        if ($diaYmd <= $dataCorte) {
            $diasUteisTrabalhados[] = $diaYmd;
        }
    }
    sort($diasUteisTrabalhados);
    $totalDiasUteis = count($diasUteisTrabalhados);

    // 3. Produção diária acumulada no mês até a data de corte
    $prodAcumulada = [
        'MONOFASICO'   => 0,
        'CONVENCIONAL' => 0,
        'JC_TRIF'      => 0,
    ];
    foreach ($diasUteisTrabalhados as $diaYmd) {
        $p = $kardexPorDia[$diaYmd] ?? [];
        $enr = (int) ($p['ENR'] ?? 0);
        $emp = (int) ($p['EMP'] ?? 0);
        $jc  = (int) ($p['JC'] ?? 0);

        $prodAcumulada['MONOFASICO']   += $enr;
        $prodAcumulada['CONVENCIONAL'] += $emp;
        $prodAcumulada['JC_TRIF']      += $jc;
    }

    // 4. Médias diárias de produção (peças/dia)
    $mediaDiaria = [
        'MONOFASICO'   => ($totalDiasUteis > 0) ? ($prodAcumulada['MONOFASICO'] / $totalDiasUteis) : 0.0,
        'CONVENCIONAL' => ($totalDiasUteis > 0) ? ($prodAcumulada['CONVENCIONAL'] / $totalDiasUteis) : 0.0,
        'JC_TRIF'      => ($totalDiasUteis > 0) ? ($prodAcumulada['JC_TRIF'] / $totalDiasUteis) : 0.0,
    ];

    // 5. Filtra itens em atraso (programados até a data de corte e dentro dos meses selecionados)
    $itensAtrasados = [];
    $pecasPorLinha = [
        'MONOFASICO'   => 0.0,
        'CONVENCIONAL' => 0.0,
        'JC_TRIF'      => 0.0,
    ];
    $pecasPorMes = []; // ['2026-08' => ['rotulo' => 'AGOSTO/26', 'qtd' => 2408]]
    $mesesDisponiveis = [];

    foreach ($itens as $item) {
        $mKey = $item['mes_ano_key'];
        $mRotulo = $item['mes_ano_rotulo'];

        if (!isset($mesesDisponiveis[$mKey])) {
            $mesesDisponiveis[$mKey] = $mRotulo;
        }

        // Condição de atraso: Data de programação planejada <= Data de corte
        if ($item['data_programada'] > $dataCorte) {
            continue;
        }

        // Se houver filtro de meses, verifica se o mês está selecionado
        if (!empty($mesesFiltro) && !in_array($mKey, $mesesFiltro, true)) {
            continue;
        }

        $qtd = $item['quantidade'];
        $linha = $item['linha'];

        $pecasPorLinha[$linha] = ($pecasPorLinha[$linha] ?? 0.0) + $qtd;

        if (!isset($pecasPorMes[$mKey])) {
            $pecasPorMes[$mKey] = [
                'chave'  => $mKey,
                'rotulo' => $mRotulo,
                'qtd'    => 0.0,
            ];
        }
        $pecasPorMes[$mKey]['qtd'] += $qtd;

        // Calcula dias de atraso individual da ordem
        $diasAtrasoOrdem = (int) max(0, (strtotime($dataCorte) - strtotime($item['data_programada'])) / 86400);
        $item['dias_atraso_individual'] = $diasAtrasoOrdem;

        $itensAtrasados[] = $item;
    }

    // Ordena meses decrescente para o gráfico de barras
    krsort($pecasPorMes);
    ksort($mesesDisponiveis);

    // 6. Cálculo dos Indicadores de Atraso em Dias
    $atrasoDias = [
        'MONOFASICO'   => ($mediaDiaria['MONOFASICO'] > 0) ? ($pecasPorLinha['MONOFASICO'] / $mediaDiaria['MONOFASICO']) : 0.0,
        'CONVENCIONAL' => ($mediaDiaria['CONVENCIONAL'] > 0) ? ($pecasPorLinha['CONVENCIONAL'] / $mediaDiaria['CONVENCIONAL']) : 0.0,
        'JC_TRIF'      => ($mediaDiaria['JC_TRIF'] > 0) ? ($pecasPorLinha['JC_TRIF'] / $mediaDiaria['JC_TRIF']) : 0.0,
    ];

    // Média Geral Simples (média dos 3 indicadores de atraso)
    $mediaGeralDias = ($atrasoDias['MONOFASICO'] + $atrasoDias['CONVENCIONAL'] + $atrasoDias['JC_TRIF']) / 3.0;

    $totalPecasAtrasadas = $pecasPorLinha['MONOFASICO'] + $pecasPorLinha['CONVENCIONAL'] + $pecasPorLinha['JC_TRIF'];

    // 7. Série Temporal para o Gráfico "Média de dias em atraso"
    // Calcula para cada dia útil trabalhado do mês a evolução dos dias de atraso
    $serieEvolucao = [];
    $diasNoMes = (int) date('t', strtotime($mesRef . '-01'));

    // Agrupa snapshot por data de programação
    $snapPorDataProg = [];
    foreach ($itens as $item) {
        $mKey = $item['mes_ano_key'];
        if (!empty($mesesFiltro) && !in_array($mKey, $mesesFiltro, true)) {
            continue;
        }
        $dProg = $item['data_programada'];
        $l = $item['linha'];
        if (!isset($snapPorDataProg[$dProg])) {
            $snapPorDataProg[$dProg] = ['MONOFASICO' => 0.0, 'CONVENCIONAL' => 0.0, 'JC_TRIF' => 0.0];
        }
        $snapPorDataProg[$dProg][$l] += $item['quantidade'];
    }

    $prodCumSim = ['MONOFASICO' => 0, 'CONVENCIONAL' => 0, 'JC_TRIF' => 0];
    $countDiasSim = 0;

    foreach ($diasUteisTrabalhados as $diaSim) {
        $countDiasSim++;
        $p = $kardexPorDia[$diaSim] ?? [];
        $prodCumSim['MONOFASICO']   += (int) ($p['ENR'] ?? 0);
        $prodCumSim['CONVENCIONAL'] += (int) ($p['EMP'] ?? 0);
        $prodCumSim['JC_TRIF']      += (int) ($p['JC'] ?? 0);

        $avgMonoSim = ($countDiasSim > 0) ? ($prodCumSim['MONOFASICO'] / $countDiasSim) : 0.0;
        $avgConvSim = ($countDiasSim > 0) ? ($prodCumSim['CONVENCIONAL'] / $countDiasSim) : 0.0;
        $avgJCSim   = ($countDiasSim > 0) ? ($prodCumSim['JC_TRIF'] / $countDiasSim) : 0.0;

        // Peças acumuladas em atraso até diaSim
        $pecasMonoSim = 0.0; $pecasConvSim = 0.0; $pecasJCSim = 0.0;
        foreach ($snapPorDataProg as $dProg => $linhasQtd) {
            if ($dProg <= $diaSim) {
                $pecasMonoSim += $linhasQtd['MONOFASICO'];
                $pecasConvSim += $linhasQtd['CONVENCIONAL'];
                $pecasJCSim   += $linhasQtd['JC_TRIF'];
            }
        }

        $diasMonoSim = ($avgMonoSim > 0) ? ($pecasMonoSim / $avgMonoSim) : 0.0;
        $diasConvSim = ($avgConvSim > 0) ? ($pecasConvSim / $avgConvSim) : 0.0;
        $diasJCSim   = ($avgJCSim > 0) ? ($pecasJCSim / $avgJCSim) : 0.0;

        $mediaGeralSim = ($diasMonoSim + $diasConvSim + $diasJCSim) / 3.0;

        $serieEvolucao[] = [
            'data'         => $diaSim,
            'label'        => date('d/m/Y', strtotime($diaSim)),
            'media_geral'  => round($mediaGeralSim, 1),
            'dias_mono'    => round($diasMonoSim, 2),
            'dias_conv'    => round($diasConvSim, 2),
            'dias_jc'      => round($diasJCSim, 2),
            'pecas_total'  => (int) ($pecasMonoSim + $pecasConvSim + $pecasJCSim),
        ];
    }

    return boletimUtf8Safe([
        'sucesso'               => true,
        'data_corte'            => $dataCorte,
        'data_corte_formatada'  => boletimFormatarDataPorExtenso($dataCorte),
        'mes_referencia'        => $mesRef,
        'snapshot_info'         => $resSnap['snapshot_info'],
        'meses_disponiveis'     => $mesesDisponiveis,
        'meses_filtro_ativos'   => $mesesFiltro,
        'dias_trabalhados'      => $totalDiasUteis,
        'prod_acumulada'        => $prodAcumulada,
        'media_diaria'          => [
            'MONOFASICO'   => round($mediaDiaria['MONOFASICO'], 2),
            'CONVENCIONAL' => round($mediaDiaria['CONVENCIONAL'], 2),
            'JC_TRIF'      => round($mediaDiaria['JC_TRIF'], 2),
        ],
        'pecas_atraso'          => [
            'MONOFASICO'   => (int) round($pecasPorLinha['MONOFASICO']),
            'CONVENCIONAL' => (int) round($pecasPorLinha['CONVENCIONAL']),
            'JC_TRIF'      => (int) round($pecasPorLinha['JC_TRIF']),
            'TOTAL'        => (int) round($totalPecasAtrasadas),
        ],
        'atraso_dias'           => [
            'MONOFASICO'   => round($atrasoDias['MONOFASICO'], 2),
            'CONVENCIONAL' => round($atrasoDias['CONVENCIONAL'], 2),
            'JC_TRIF'      => round($atrasoDias['JC_TRIF'], 2),
            'MEDIA_GERAL'  => round($mediaGeralDias, 2),
        ],
        'pecas_por_mes'         => array_values($pecasPorMes),
        'serie_evolucao'        => $serieEvolucao,
        'total_ordens'          => count($itensAtrasados),
        'itens_detalhados'      => $itensAtrasados,
    ]);
}

/**
 * Formata data Y-m-d para formato por extenso (ex: "24 de agosto").
 */
function boletimFormatarDataPorExtenso(string $dataYmd): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dataYmd, $m)) {
        return $dataYmd;
    }
    $dia = (int) $m[3];
    $mes = (int) $m[2];
    $meses = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
        5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
        9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro'
    ];
    return sprintf('%d de %s', $dia, $meses[$mes] ?? '');
}
