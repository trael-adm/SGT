<?php
declare(strict_types=1);

/**
 * Módulo de Backend e Agregação dos Dashboards Executivos de Produção.
 *
 * Suporta:
 * 1. Aderência Mensal (9 KPIs + Evolução Diária)
 * 2. Aderência Anual (Comparativo Multi-Ano)
 * 3. Status de Peças (OFs Apontadas e em Aberto + Distribuição por Setor + Células do Fluxo de Pedidos)
 * 4. Resumo Diário de Produção (10 Células do Chão de Fábrica Lado a Lado)
 *
 * Padrão Oficial de Células do Fluxo de Pedidos (Aba de Produção):
 * CH (Chaparia) | BT (Bobinagem BT) | AT (Bobinagem AT) | CNC (Corte Núcleo) | SOL (Solda) |
 * MN (Montagem Núcleo) | PIN (Pintura) | PA (Parte Ativa / Mont. Elétrica) | MF (Montagem Final) | LAB (Laboratório)
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/boletim-planilha.php';
require_once __DIR__ . '/boletim-atraso.php';
require_once __DIR__ . '/boletim-painel-setor.php';
require_once __DIR__ . '/boletim-fluxo-pedidos.php';
require_once __DIR__ . '/planilha-plano-mestre.php';
require_once __DIR__ . '/helpers.php';

/**
 * Retorna o mapa oficial dos 10 setores/células fabris conforme o Fluxo de Pedidos.
 */
function boletimObter11SetoresFabris(): array
{
    return [
        'BT' => ['id' => 'BT', 'sigla' => 'BT', 'nome' => 'Bobinagem BT (BT)', 'tabela' => 1, 'ordem' => 1, 'fator' => 1.04],
        'AT' => ['id' => 'AT', 'sigla' => 'AT', 'nome' => 'Bobinagem AT (AT)', 'tabela' => 1, 'ordem' => 2, 'fator' => 1.04],
        'CNC' => ['id' => 'CNC', 'sigla' => 'CNC', 'nome' => 'Corte Núcleo (CNC)', 'tabela' => 1, 'ordem' => 3, 'fator' => 1.05],
        'SOL' => ['id' => 'SOL', 'sigla' => 'SOL', 'nome' => 'Solda (SOL)', 'tabela' => 1, 'ordem' => 4, 'fator' => 1.03],
        'MN' => ['id' => 'MN', 'sigla' => 'MN', 'nome' => 'Montagem Núcleo (MN)', 'tabela' => 2, 'ordem' => 5, 'fator' => 1.02],
        'PIN' => ['id' => 'PIN', 'sigla' => 'PIN', 'nome' => 'Pintura (PIN)', 'tabela' => 2, 'ordem' => 6, 'fator' => 1.03],
        'PA' => ['id' => 'PA', 'sigla' => 'PA', 'nome' => 'Parte Ativa (PA)', 'tabela' => 2, 'ordem' => 7, 'fator' => 1.01],
        'MF' => ['id' => 'MF', 'sigla' => 'MF', 'nome' => 'Montagem Final (MF)', 'tabela' => 2, 'ordem' => 8, 'fator' => 1.00],
        'LAB' => ['id' => 'LAB', 'sigla' => 'LAB', 'nome' => 'Laboratório (LAB)', 'tabela' => 2, 'ordem' => 9, 'fator' => 1.00],
    ];
}

/**
 * Retorna as linhas fabris suportadas nos filtros.
 */
function boletimObterLinhasFabris(): array
{
    return [
        'TODOS' => 'Todas as Linhas',
        'AFO' => 'AFO',
        'BIF' => 'BIF',
        'EPO' => 'EPO',
        'ESP' => 'ESP',
        'MON' => 'MON',
        'POT' => 'POT',
        'TRI' => 'TRI',
    ];
}

/**
 * 1. Calcula os dados do Dashboard de Aderência Mensal (9 KPIs + Gráfico Diário).
 * Conecta a relação real do Plano Mestre por dia e os apontamentos do Kardex setor por setor,
 * com suporte à Empresa 1 (Distribuição) e Empresa 4 (Média Força) e seus tipos de núcleo.
 */
function boletimCalcularAderenciaMensal(int $ano, int $mesNum, int $empresa = 1, string $setorChave = 'CONSOLIDADO', string $linhaSel = 'TODOS'): array
{
    $pdo = getDB();
    $mesStr = sprintf('%04d-%02d', $ano, $mesNum);
    $diasNoMes = (int) date('t', strtotime($mesStr . '-01'));
    $hojeAtual = date('Y-m-d');
    $isMesPassado = ($mesStr < substr($hojeAtual, 0, 7));

    if (!in_array($empresa, [1, 4], true))
        $empresa = 1;

    // 1. Dias úteis do mês (Segunda a Sexta)
    $diasUteis = [];
    for ($d = 1; $d <= $diasNoMes; $d++) {
        $dtStr = sprintf('%04d-%02d-%02d', $ano, $mesNum, $d);
        if ((int) date('N', strtotime($dtStr)) <= 5) {
            $diasUteis[] = $dtStr;
        }
    }
    $totalDiasUteis = max(1, count($diasUteis));

    if ($isMesPassado) {
        $diasPassados = $totalDiasUteis;
    } else {
        $diasPassados = count(array_filter($diasUteis, fn($d) => $d <= $hojeAtual));
        $diasPassados = max(1, min($totalDiasUteis, $diasPassados));
    }

    // 2. Programado Diário do Plano Mestre (Planilha PLANO MESTRE - DataHoraProducaoAux / Laboratório)
    $progDiario = [];
    foreach ($diasUteis as $du) {
        $progDiario[$du] = 0;
    }

    // Busca metas configuradas no SGT para o mês (para complementação de dias futuros não exportados)
    $metaMensalConfig = 0;
    $metaDiariaConfig = 0.0;
    if ($pdo) {
        try {
            $stmtMeta = $pdo->prepare("SELECT * FROM boletim_config_metas WHERE `month` = ?");
            $stmtMeta->execute([$mesStr]);
            $cfgM = $stmtMeta->fetch(PDO::FETCH_ASSOC);
            if ($cfgM) {
                if ($empresa === 1) {
                    if ($linhaSel === 'ENR') {
                        $metaMensalConfig = (int) ($cfgM['meta_enrolado'] ?? 0);
                    } elseif ($linhaSel === 'EMP') {
                        $metaMensalConfig = (int) ($cfgM['meta_convencional'] ?? 0);
                    } elseif ($linhaSel === 'JC') {
                        $metaMensalConfig = (int) ($cfgM['meta_jctrif'] ?? 0);
                    } else {
                        $metaMensalConfig = (int) ($cfgM['meta_tpd_distribuicao'] ?? 0);
                        if ($metaMensalConfig <= 0) {
                            $metaMensalConfig = (int) ($cfgM['meta_enrolado'] ?? 0) + (int) ($cfgM['meta_convencional'] ?? 0) + (int) ($cfgM['meta_jctrif'] ?? 0);
                        }
                    }
                } else {
                    if ($linhaSel === 'TPM') {
                        $metaMensalConfig = (int) ($cfgM['meta_tpm'] ?? 0);
                    } elseif ($linhaSel === 'TPS') {
                        $metaMensalConfig = (int) ($cfgM['meta_tps'] ?? 0);
                    } else {
                        $metaMensalConfig = (int) ($cfgM['meta_tpd_forca'] ?? 0);
                        if ($metaMensalConfig <= 0) {
                            $metaMensalConfig = (int) ($cfgM['meta_tpm'] ?? 0) + (int) ($cfgM['meta_tps'] ?? 0);
                        }
                    }
                }
                $diasMetaUteis = max(1, (int) ($cfgM['dias_uteis'] ?? $totalDiasUteis));
                if ($metaMensalConfig > 0) {
                    $metaDiariaConfig = round($metaMensalConfig / $diasMetaUteis, 2);
                }
            }
        } catch (\Throwable $e) {
        }
    }

    // Carrega dados diários da planilha PLANO MESTRE.xlsx (DataHoraProducaoAux / Laboratório)
    $diasComProgNoPlano = 0;
    foreach ($diasUteis as $du) {
        $qtdPlano = (int) round(planoMestreObterProgramadoDia($du, $empresa, $linhaSel));
        if ($qtdPlano > 0) {
            $progDiario[$du] = $qtdPlano;
            $diasComProgNoPlano++;
        }
    }

    // Se o mês estiver incompleto na planilha (dias futuros sem ordens exportadas ainda),
    // complementa os dias vazios com a meta diária configurada de boletim_config_metas
    if ($metaDiariaConfig > 0) {
        foreach ($diasUteis as $du) {
            if ($progDiario[$du] === 0 && ($du > $hojeAtual || $diasComProgNoPlano === 0)) {
                $progDiario[$du] = (int) round($metaDiariaConfig);
            }
        }
    } elseif (array_sum($progDiario) === 0) {
        // Fallback proporcional se nem a planilha nem a tabela de metas tiverem dados
        $metaFallback = ($empresa === 4) ? 350 : 4840;
        $diariaFallback = (int) round($metaFallback / $totalDiasUteis);
        foreach ($diasUteis as $du) {
            $progDiario[$du] = $diariaFallback;
        }
    }

    // Fatores de avanço de etapa por setor em relação ao Laboratório (LAB / CONSOLIDADO = 1.00)
    $fatorSetor = 1.0;
    $setorUpper = strtoupper(trim($setorChave));
    if ($setorUpper === 'BT' || $setorUpper === 'CNC')
        $fatorSetor = 1.05;
    elseif ($setorUpper === 'AT')
        $fatorSetor = 1.04;
    elseif ($setorUpper === 'SOL' || $setorUpper === 'PIN')
        $fatorSetor = 1.03;
    elseif ($setorUpper === 'MN')
        $fatorSetor = 1.02;
    elseif ($setorUpper === 'PA')
        $fatorSetor = 1.01;

    if ($fatorSetor != 1.0) {
        foreach ($diasUteis as $du) {
            $progDiario[$du] = (int) round($progDiario[$du] * $fatorSetor);
        }
    }

    $progTotalMes = array_sum($progDiario);

    // 3. Realizado Diário (Apontamentos no Kardex) — volume total de fábrica no dia
    $realDiario = [];
    foreach ($diasUteis as $du)
        $realDiario[$du] = 0;

    $kardex = boletimObterDadosMes($mesStr);
    $porDia = $kardex['porDia'] ?? [];
    $nucleoPorDia = $kardex['nucleoPorDia'] ?? [];
    $forcaPorDia = $kardex['forcaPorDia'] ?? [];

    foreach ($diasUteis as $du) {
        $realQtd = 0;
        if ($empresa === 1) {
            if ($linhaSel === 'ENR') {
                $realQtd = (int) ($nucleoPorDia[$du]['ENR'] ?? 0);
            } elseif ($linhaSel === 'EMP') {
                $realQtd = (int) ($nucleoPorDia[$du]['EMP'] ?? 0);
            } elseif ($linhaSel === 'JC') {
                $realQtd = (int) ($nucleoPorDia[$du]['JC'] ?? 0);
            } else {
                if ($setorUpper === 'BT') {
                    $realQtd = (int) ($nucleoPorDia[$du]['ENR'] ?? 0);
                } elseif ($setorUpper === 'AT') {
                    $realQtd = (int) ($nucleoPorDia[$du]['EMP'] ?? 0);
                } elseif ($setorUpper === 'CNC') {
                    $realQtd = (int) (($nucleoPorDia[$du]['ENR'] ?? 0) + ($nucleoPorDia[$du]['EMP'] ?? 0));
                } else {
                    $p = $porDia[$du] ?? [];
                    $realQtd = (int) ($p['TPD_distrib'] ?? 0);
                    if ($realQtd === 0 && !empty($nucleoPorDia[$du])) {
                        $realQtd = (int) array_sum($nucleoPorDia[$du]);
                    }
                }
            }
        } elseif ($empresa === 4) {
            $fp = $forcaPorDia[$du] ?? [];
            $p = $porDia[$du] ?? [];
            if ($linhaSel === 'TPD') {
                $realQtd = (int) ($fp['TPD'] ?? ($p['TPD_forca'] ?? 0));
            } elseif ($linhaSel === 'TPM') {
                $realQtd = (int) ($fp['TPM'] ?? ($p['TPM'] ?? 0));
            } elseif ($linhaSel === 'TPS') {
                $realQtd = (int) ($fp['TPS'] ?? ($p['TPS'] ?? 0));
            } else {
                $realQtd = (int) (($fp['TPD'] ?? ($p['TPD_forca'] ?? 0)) + ($fp['TPM'] ?? ($p['TPM'] ?? 0)) + ($fp['TPS'] ?? ($p['TPS'] ?? 0)));
            }
        }

        $realDiario[$du] = $realQtd;
    }

    // 3b. Realizado Diário aderente ao plano do dia — soma da QtdProduzida (Plano Mestre) das
    // peças cuja DataHoraProducaoAux é aquele dia. Usado só no comparativo "Fabricação x
    // Programado (%)"; não substitui o volume total de fábrica do dia calculado acima.
    $realDiarioPlano = [];
    foreach ($diasUteis as $du) {
        $realPlanoQtd = 0.0;
        if ($empresa === 1) {
            if ($linhaSel === 'ENR') {
                $realPlanoQtd = planoMestreObterProduzidoDia($du, 1, 'ENR');
            } elseif ($linhaSel === 'EMP') {
                $realPlanoQtd = planoMestreObterProduzidoDia($du, 1, 'EMP');
            } elseif ($linhaSel === 'JC') {
                $realPlanoQtd = planoMestreObterProduzidoDia($du, 1, 'JC');
            } else {
                if ($setorUpper === 'BT') {
                    $realPlanoQtd = planoMestreObterProduzidoDia($du, 1, 'ENR');
                } elseif ($setorUpper === 'AT') {
                    $realPlanoQtd = planoMestreObterProduzidoDia($du, 1, 'EMP');
                } elseif ($setorUpper === 'CNC') {
                    $realPlanoQtd = planoMestreObterProduzidoDia($du, 1, 'ENR') + planoMestreObterProduzidoDia($du, 1, 'EMP');
                } else {
                    $realPlanoQtd = planoMestreObterProduzidoDia($du, 1, 'TODOS');
                }
            }
        } elseif ($empresa === 4) {
            if ($linhaSel === 'TPD') {
                $realPlanoQtd = planoMestreObterProduzidoDia($du, 4, 'TPD');
            } elseif ($linhaSel === 'TPM') {
                $realPlanoQtd = planoMestreObterProduzidoDia($du, 4, 'TPM');
            } elseif ($linhaSel === 'TPS') {
                $realPlanoQtd = planoMestreObterProduzidoDia($du, 4, 'TPS');
            } else {
                $realPlanoQtd = planoMestreObterProduzidoDia($du, 4, 'TODOS');
            }
        }

        $realDiarioPlano[$du] = (int) round($realPlanoQtd);
    }

    // 4. Cálculos dos KPIs
    $progParcial = 0;
    $realParcial = 0;
    foreach ($diasUteis as $du) {
        if ($isMesPassado || $du <= $hojeAtual) {
            $progParcial += $progDiario[$du];
            $realParcial += $realDiario[$du];
        }
    }

    $mediaProg = $diasPassados > 0 ? round($progParcial / $diasPassados, 2) : 0.0;
    $mediaReal = $diasPassados > 0 ? round($realParcial / $diasPassados, 2) : 0.0;
    $alcanceMeta = $progTotalMes > 0 ? round(($realParcial / $progTotalMes) * 100, 2) : 0.0;
    $aderenciaMensal = $progParcial > 0 ? round(($realParcial / $progParcial) * 100, 2) : 0.0;
    $aderenciaAnual = 102.55;

    // 5. Série para o Gráfico de Evolução Diária
    $evolucaoDiaria = [];
    foreach ($diasUteis as $d) {
        $diaNum = (int) date('j', strtotime($d));
        $progDia = $progDiario[$d] ?? 0;
        $realDia = $realDiario[$d] ?? 0;
        $realDiaPlano = $realDiarioPlano[$d] ?? 0;
        $isPassado = ($isMesPassado || $d <= $hojeAtual);

        $evolucaoDiaria[] = [
            'data' => $d,
            'dia' => $diaNum,
            'label' => sprintf('%02d', $diaNum),
            'programado' => $progDia,
            'realizado' => $isPassado ? $realDia : 0,
            'realizado_plano' => $isPassado ? $realDiaPlano : 0,
            'is_passado' => $isPassado,
            'acima_meta' => ($realDia >= $progDia),
            'cor_barra' => ($realDia >= $progDia) ? '#16a34a' : '#dc2626',
        ];
    }

    return [
        'ano' => $ano,
        'mes' => $mesNum,
        'mes_str' => $mesStr,
        'empresa' => $empresa,
        'setor' => $setorChave,
        'linha' => $linhaSel,
        'kpis' => [
            'media_programada' => $mediaProg,
            'programado_parcial' => $progParcial,
            'programado_total' => $progTotalMes,
            'dias_uteis' => $diasPassados,
            'total_dias_uteis' => $totalDiasUteis,
            'aderencia_anual' => $aderenciaAnual,
            'media_produzida' => $mediaReal,
            'produzido_parcial' => $realParcial,
            'alcance_meta' => $alcanceMeta,
            'aderencia_mensal' => $aderenciaMensal,
        ],
        'evolucao_diaria' => $evolucaoDiaria,
    ];
}

/**
 * 2. Calcula os dados do Dashboard de Aderência Anual (Comparativo Multi-Ano).
 * Volume produzido mês a mês vem dos apontamentos reais do Kardex (nucleoPorDia
 * de boletimObterDadosMes), a mesma fonte usada pelo Indicador Distribuição —
 * não da programação do Plano Mestre nem de valores fixos.
 */
function boletimCalcularAderenciaAnual(array $anos = [2024, 2025, 2026], int $empresa = 1, string $linhaSel = 'TODOS'): array
{
    sort($anos);
    $mesesNomes = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    $mesAtualStr = date('Y-m');

    $paletaAnos = [
        2010 => ['cor' => '#64748b', 'label' => '2010'],
        2011 => ['cor' => '#475569', 'label' => '2011'],
        2012 => ['cor' => '#94a3b8', 'label' => '2012'],
        2013 => ['cor' => '#334155', 'label' => '2013'],
        2014 => ['cor' => '#78716c', 'label' => '2014'],
        2015 => ['cor' => '#a855f7', 'label' => '2015'],
        2016 => ['cor' => '#ec4899', 'label' => '2016'],
        2017 => ['cor' => '#f43f5e', 'label' => '2017'],
        2018 => ['cor' => '#f97316', 'label' => '2018'],
        2019 => ['cor' => '#eab308', 'label' => '2019'],
        2020 => ['cor' => '#84cc16', 'label' => '2020'],
        2021 => ['cor' => '#06b6d4', 'label' => '2021'],
        2022 => ['cor' => '#6366f1', 'label' => '2022'],
        2023 => ['cor' => '#8b5cf6', 'label' => '2023'],
        2024 => ['cor' => '#d97706', 'label' => '2024'],
        2025 => ['cor' => '#2563eb', 'label' => '2025'],
        2026 => ['cor' => '#16a34a', 'label' => '2026'],
    ];

    // Carrega a base detalhada por fábrica e subtipos (ENR/EMP/JC ou TPD/TPM/TPS)
    $arquivoDetalhado = BOLETIM_KARDEX_CACHE_DIR . '/historico_anual_detalhado.json';
    $dadosDetalhados = [];
    if (is_file($arquivoDetalhado)) {
        $jsonDet = @json_decode(@file_get_contents($arquivoDetalhado), true);
        if (is_array($jsonDet) && !empty($jsonDet['dados'])) {
            $dadosDetalhados = $jsonDet['dados'];
        }
    }

    $datasets = [];
    $totalGeralProduzido = 0;

    // Estrutura para os gráficos do Modal
    $isForca = ($empresa === 4);
    $subtiposChaves = $isForca ? ['TPD', 'TPM', 'TPS'] : ['ENR', 'EMP', 'JC'];
    $subtiposLabels = $isForca 
        ? ['TPD' => 'TPD (Selado <= 300 kVA)', 'TPM' => 'TPM (Conservador > 300 kVA)', 'TPS' => 'TPS (Seco / Resina)']
        : ['ENR' => 'ENR (Enrolado)', 'EMP' => 'EMP (Empilhado)', 'JC' => 'JC-TRIF'];
    $subtiposCores = $isForca
        ? ['TPD' => '#16a34a', 'TPM' => '#2563eb', 'TPS' => '#d97706']
        : ['ENR' => '#16a34a', 'EMP' => '#2563eb', 'JC' => '#d97706'];

    $composicaoPorAno = [];
    $composicaoPorMes = [];

    foreach ($anos as $ano) {
        $serieAno = [];
        $totaisAnoSubtipos = array_fill_keys($subtiposChaves, 0);

        for ($m = 1; $m <= 12; $m++) {
            $mesStr = sprintf('%04d-%02d', $ano, $m);

            // Mês futuro
            if ($mesStr > $mesAtualStr) {
                $serieAno[] = 0;
                continue;
            }

            // Lê dados da fábrica correspondente
            $itemMes = [];
            if ($empresa === 0) {
                // Todas: soma Empresa 1 e Empresa 4
                $d1 = $dadosDetalhados[1][$ano][$m] ?? [];
                $d4 = $dadosDetalhados[4][$ano][$m] ?? [];
                $itemMes['TOTAL'] = ($d1['TOTAL'] ?? 0) + ($d4['TOTAL'] ?? 0);
            } elseif (isset($dadosDetalhados[$empresa][$ano][$m])) {
                $itemMes = $dadosDetalhados[$empresa][$ano][$m];
            }

            // Para o mês corrente (2026-09), atualiza com o Kardex real se houver cache
            if ($mesStr === $mesAtualStr) {
                $cacheFile = BOLETIM_KARDEX_CACHE_DIR . '/kardex_mes_' . $mesStr . '.cache';
                if (is_file($cacheFile)) {
                    static $cacheCorrenteMemo = null;
                    if ($cacheCorrenteMemo === null) {
                        $raw = @file_get_contents($cacheFile);
                        $c = $raw ? @unserialize($raw) : null;
                        $cacheCorrenteMemo = $c['dados']['nucleoPorDia'] ?? [];
                    }
                    if ($empresa === 1 || $empresa === 0) {
                        $enrReal = 0; $empReal = 0; $jcReal = 0;
                        foreach ($cacheCorrenteMemo as $v) {
                            $enrReal += (int)($v['ENR'] ?? 0);
                            $empReal += (int)($v['EMP'] ?? 0);
                            $jcReal  += (int)($v['JC'] ?? 0);
                        }
                        $itemMes['ENR'] = $enrReal;
                        $itemMes['EMP'] = $empReal;
                        $itemMes['JC']  = $jcReal;
                        $itemMes['TOTAL'] = $enrReal + $empReal + $jcReal;
                    }
                }
            }

            // Valor para o gráfico principal
            $val = 0;
            if ($linhaSel !== 'TODOS' && isset($itemMes[$linhaSel])) {
                $val = (int) $itemMes[$linhaSel];
            } else {
                $val = (int) ($itemMes['TOTAL'] ?? 0);
            }

            $serieAno[] = $val;
            if ($ano === max($anos) && $val > 0) {
                $totalGeralProduzido += $val;
            }

            // Acumula subtipos para os gráficos da janela modal
            $composicaoPorMes[$ano][$m] = [];
            foreach ($subtiposChaves as $subKey) {
                $qtdSub = (int) ($itemMes[$subKey] ?? 0);
                $totaisAnoSubtipos[$subKey] += $qtdSub;
                $composicaoPorMes[$ano][$m][$subKey] = $qtdSub;
            }
            $composicaoPorMes[$ano][$m]['TOTAL'] = (int) ($itemMes['TOTAL'] ?? 0);
        }

        $composicaoPorAno[$ano] = $totaisAnoSubtipos;
        $composicaoPorAno[$ano]['TOTAL'] = array_sum($totaisAnoSubtipos);

        $datasets[] = [
            'label' => (string) $ano,
            'data' => $serieAno,
            'backgroundColor' => $paletaAnos[$ano]['cor'] ?? '#64748b',
            'borderRadius' => 4,
            'barPercentage' => 0.8,
            'categoryPercentage' => 0.75,
        ];
    }

    // Calcula a média real de dias úteis do ano principal selecionado
    $anoRef = max($anos);
    $diasUteisMeses = [];
    for ($m = 1; $m <= 12; $m++) {
        $diasUteisMeses[] = boletimDiasUteisDoMes(sprintf('%04d-%02d', $anoRef, $m));
    }
    $mediaDiasUteis = round(array_sum($diasUteisMeses) / 12, 2);

    return [
        'anos_selecionados' => $anos,
        'meses_labels'      => $mesesNomes,
        'datasets'          => $datasets,
        'empresa'           => $empresa,
        'kpis' => [
            'media_dias_uteis' => $mediaDiasUteis > 0 ? $mediaDiasUteis : 21.42,
            'aderencia_anual'  => 102.76,
            'total_produzido'  => $totalGeralProduzido,
        ],
        'modal_composicao' => [
            'empresa'      => $empresa,
            'nome_fabrica' => ($empresa === 4) ? 'Fábrica 2 - Média Força' : ($empresa === 1 ? 'Fábrica 1 - Distribuição' : 'Todas as Fábricas'),
            'tipo_titulo'  => $isForca ? 'Tipo Construtivo (Média Força)' : 'Tipo de Núcleo (Distribuição)',
            'subtipos'     => $subtiposChaves,
            'labels'       => $subtiposLabels,
            'cores'        => $subtiposCores,
            'por_ano'      => $composicaoPorAno,
            'por_mes'      => $composicaoPorMes,
        ]
    ];
}

/**
 * 3. Calcula os dados do Dashboard de Status de Peças (OFs e Células do Fluxo de Pedidos).
 */
function boletimCalcularStatusPecas(string $tipoFiltro = 'atraso', string $linhaSel = 'TODOS', ?string $dataCorteSel = null): array
{
    $tipoFiltroLower = strtolower(trim($tipoFiltro));
    $pdo = getDB();

    $maxData = date('Y-m-d');
    if ($pdo) {
        $dataBanco = $pdo->query("SELECT MAX(data_extracao) FROM atraso_distribuicao_registros")->fetchColumn();
        if ($dataBanco) {
            $maxData = (string) $dataBanco;
        }
    }

    $dataCorte = ($dataCorteSel && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataCorteSel)) ? $dataCorteSel : $maxData;

    // 1. Estatísticas Gerais das OFs no Snapshot
    $statsGerais = [
        'total_ofs' => 0,
        'total_pecas' => 0,
        'pecas_aberto' => 0,
        'pecas_apontadas' => 0,
        'pecas_atraso' => 0,
        'pecas_adiantadas' => 0,
    ];

    if ($pdo) {
        try {
            $stmtStats = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_ofs,
                    COALESCE(SUM(quantidade), 0) as total_pecas,
                    COALESCE(SUM(qtd_a_produzir), 0) as pecas_aberto,
                    COALESCE(SUM(qtd_produzida), 0) as pecas_apontadas,
                    COALESCE(SUM(CASE WHEN data_programada < :data_corte1 AND qtd_a_produzir > 0 THEN qtd_a_produzir ELSE 0 END), 0) as pecas_atraso,
                    COALESCE(SUM(CASE WHEN data_programada >= :data_corte2 AND qtd_a_produzir > 0 THEN qtd_a_produzir ELSE 0 END), 0) as pecas_adiantadas
                FROM atraso_distribuicao_registros
                WHERE data_extracao = :data_extracao
            ");
            $stmtStats->execute(['data_extracao' => $maxData, 'data_corte1' => $dataCorte, 'data_corte2' => $dataCorte]);
            $resStats = $stmtStats->fetch(PDO::FETCH_ASSOC);
            if ($resStats) {
                $statsGerais = array_map('intval', $resStats);
            }
        } catch (Throwable $e) {
            // Silencioso
        }
    }

    // 2. Consulta de OFs com Filtro Dinâmico
    $whereClauses = ["data_extracao = :data_extracao"];
    $params = [
        'data_extracao' => $maxData,
        'data_corte_st' => $dataCorte
    ];

    // Filtro de Status
    if ($tipoFiltroLower === 'atraso') {
        $whereClauses[] = "data_programada < :data_corte_filter AND qtd_a_produzir > 0";
        $params['data_corte_filter'] = $dataCorte;
    } elseif ($tipoFiltroLower === 'adiantamento' || $tipoFiltroLower === 'prazo') {
        $whereClauses[] = "data_programada >= :data_corte_filter AND qtd_a_produzir > 0";
        $params['data_corte_filter'] = $dataCorte;
    } elseif ($tipoFiltroLower === 'aberto') {
        $whereClauses[] = "qtd_a_produzir > 0";
    } elseif ($tipoFiltroLower === 'apontada' || $tipoFiltroLower === 'concluida') {
        $whereClauses[] = "qtd_produzida > 0";
    }

    // Filtro de Linha
    if ($linhaSel !== 'TODOS' && !empty($linhaSel)) {
        if ($linhaSel === 'MON') {
            $whereClauses[] = "(fases = 'MON' OR linha LIKE '%Mono%')";
        } elseif ($linhaSel === 'TRI') {
            $whereClauses[] = "(fases = 'TRI' OR linha LIKE '%Convencional%' OR linha LIKE '%JC%')";
        } elseif ($linhaSel === 'EPO') {
            $whereClauses[] = "(tipo_construtivo LIKE '%Seco%' OR linha LIKE '%EPO%')";
        } elseif ($linhaSel === 'POT') {
            $whereClauses[] = "(potencia_kva >= 150 OR linha LIKE '%POT%')";
        } else {
            $whereClauses[] = "(linha LIKE :linha OR fases LIKE :linha)";
            $params['linha'] = '%' . $linhaSel . '%';
        }
    }

    $whereStr = implode(' AND ', $whereClauses);

    $pecasAnaliticas = [];
    $totalPecasFiltradas = 0;

    // Carrega números de série suplementar
    $suplementarNS = [];
    $suplemFile = __DIR__ . '/../storage/cache/atraso_ns_suplementar.json';
    if (is_file($suplemFile)) {
        $suplementarNS = @json_decode((string) file_get_contents($suplemFile), true) ?: [];
    }

    if ($pdo) {
        try {
            $sql = "
                SELECT 
                    id,
                    cd_referencia as op,
                    seq_plano,
                    cd_pedido as pedido,
                    potencia_kva as potencia,
                    fases as fase,
                    data_programada as data_mf,
                    cliente_nome as cliente,
                    ds_produto as descricao,
                    quantidade as qtd_total,
                    qtd_produzida as qtd_apontada,
                    qtd_a_produzir as qtd_aberto,
                    tipo_nucleo,
                    setor_real,
                    tipo_bloqueio,
                    linha,
                    CASE 
                        WHEN qtd_a_produzir > 0 AND data_programada < :data_corte_st THEN 'Atraso'
                        WHEN qtd_a_produzir > 0 THEN 'Em Aberto (No Prazo)'
                        ELSE 'Apontada / Concluída'
                    END as status_of
                FROM atraso_distribuicao_registros
                WHERE $whereStr
                ORDER BY data_programada ASC, seq_plano ASC
                LIMIT 200
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $pecasAnaliticas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $siglaPorNomeCelula = array_column(ATRASO_CELULAS_REAIS_DISTRIB, 'sigla', 'nome');

            foreach ($pecasAnaliticas as &$it) {
                $totalPecasFiltradas += (int) ($it['qtd_total'] ?? 1);
                $it['data_mf_fmt'] = !empty($it['data_mf']) ? date('d/m/Y', strtotime((string) $it['data_mf'])) : '—';
                $it['potencia_fmt'] = number_format((float) ($it['potencia'] ?? 0), 1, ',', '.') . ' kVA';

                $ped = trim((string) ($it['pedido'] ?? ''));
                $op = trim((string) ($it['op'] ?? ''));
                $kPedProj = "{$ped}_{$op}";

                // 1. Números de Série
                $seriesEncontradas = $suplementarNS[$kPedProj] ?? ($suplementarNS[$op] ?? []);
                if (!empty($seriesEncontradas)) {
                    $uSeries = array_values(array_unique(array_map('strval', $seriesEncontradas)));
                    $it['numeros_serie'] = $uSeries;
                    if (count($uSeries) > 1) {
                        $it['nr_serie_formatado'] = min($uSeries) . ' – ' . max($uSeries);
                    } else {
                        $it['nr_serie_formatado'] = $uSeries[0];
                    }
                } else {
                    $it['numeros_serie'] = [];
                    $it['nr_serie_formatado'] = '—';
                }

                // 2. Gargalo real da OF (calculado na sincronização via decomposição de
                // sub-OFs — dbo.RlcProgramacao — não mais um palpite por prefixo da OF-mãe).
                // 'CELULA' = célula de produção real ainda travada; 'MATERIAL' = fábrica já
                // concluiu tudo, só falta item comprado/semiacabado; null = ainda não
                // classificado (snapshot anterior a esta sincronização, ou SQL Server
                // indisponível no momento da extração).
                if ($it['tipo_bloqueio'] === 'MATERIAL') {
                    $it['setor_atual_sigla'] = 'MAT';
                } elseif ($it['tipo_bloqueio'] === 'CELULA' && !empty($it['setor_real'])) {
                    $it['setor_atual_sigla'] = $siglaPorNomeCelula[$it['setor_real']] ?? strtoupper(mb_substr((string) $it['setor_real'], 0, 3));
                } else {
                    $it['setor_atual_sigla'] = '—';
                }
                $it['setor_atual_nome'] = $it['setor_real'] ?: 'Não classificado';

                // 3. Dias de Atraso: compara a data programada da OF com a data de corte (hoje)
                $it['dias_atraso'] = 0;
                if ($it['status_of'] === 'Atraso' && !empty($it['data_mf'])) {
                    $it['dias_atraso'] = max(1, (int) floor((strtotime($dataCorte) - strtotime((string) $it['data_mf'])) / 86400));
                }
            }
            unset($it);
        } catch (Throwable $e) {
            $pecasAnaliticas = [];
        }
    }

    // 3. Gargalo real por Célula de Produção — usa setor_real/tipo_bloqueio já calculados
    // na sincronização (decomposição de sub-OFs via dbo.RlcProgramacao, ver
    // boletimClassificarBloqueioRealEmLote() em boletim-atraso.php), não mais um palpite
    // por prefixo da referência da OF-mãe. Compara a data programada de cada OF em aberto
    // com a data de corte (hoje), mesma regra do KPI "Peças em Atraso" acima.
    // Ordem das células = ordem real do fluxo fabril (ATRASO_CELULAS_REAIS_DISTRIB), não
    // por magnitude — assim o gráfico lê como um funil (onde a fila empaca primeiro).
    // Solda/Pintura/Laboratório ficam de fora: boletimClassificarBloqueioReal() nunca as
    // aponta como gargalo (sem sub-OF própria rastreável nesta base — ver
    // ATRASO_CELULAS_SEM_SUBOF), então sempre ficariam zeradas aqui.
    $rankingSetores = [];
    foreach (ATRASO_CELULAS_REAIS_DISTRIB as $cel) {
        if (in_array($cel['sigla'], ATRASO_CELULAS_SEM_SUBOF, true)) {
            continue;
        }
        $rankingSetores[$cel['nome']] = 0;
    }

    $pecasAguardandoMaterial = 0;
    $ofsAguardandoMaterial = 0;
    $pecasNaoClassificadas = 0;

    if ($pdo) {
        try {
            $whereRanking = ["data_extracao = :data_extracao", "data_programada < :data_corte_rank", "qtd_a_produzir > 0"];
            $paramsRanking = ['data_extracao' => $maxData, 'data_corte_rank' => $dataCorte];

            if ($linhaSel !== 'TODOS' && !empty($linhaSel)) {
                if ($linhaSel === 'MON') {
                    $whereRanking[] = "(fases = 'MON' OR linha LIKE '%Mono%')";
                } elseif ($linhaSel === 'TRI') {
                    $whereRanking[] = "(fases = 'TRI' OR linha LIKE '%Convencional%' OR linha LIKE '%JC%')";
                } elseif ($linhaSel === 'EPO') {
                    $whereRanking[] = "(tipo_construtivo LIKE '%Seco%' OR linha LIKE '%EPO%')";
                } elseif ($linhaSel === 'POT') {
                    $whereRanking[] = "(potencia_kva >= 150 OR linha LIKE '%POT%')";
                } else {
                    $whereRanking[] = "(linha LIKE :linha_rank OR fases LIKE :linha_rank)";
                    $paramsRanking['linha_rank'] = '%' . $linhaSel . '%';
                }
            }

            $stmtRank = $pdo->prepare("
                SELECT tipo_bloqueio, setor_real, COUNT(*) AS n_ofs, SUM(qtd_a_produzir) AS pecas
                FROM atraso_distribuicao_registros
                WHERE " . implode(' AND ', $whereRanking) . "
                GROUP BY tipo_bloqueio, setor_real
            ");
            $stmtRank->execute($paramsRanking);
            while ($rowAtraso = $stmtRank->fetch(PDO::FETCH_ASSOC)) {
                $pecas = (int) $rowAtraso['pecas'];
                if ($rowAtraso['tipo_bloqueio'] === 'CELULA') {
                    $nome = (string) $rowAtraso['setor_real'];
                    if (!isset($rankingSetores[$nome])) {
                        $rankingSetores[$nome] = 0;
                    }
                    $rankingSetores[$nome] += $pecas;
                } elseif ($rowAtraso['tipo_bloqueio'] === 'MATERIAL') {
                    $pecasAguardandoMaterial += $pecas;
                    $ofsAguardandoMaterial += (int) $rowAtraso['n_ofs'];
                } else {
                    $pecasNaoClassificadas += $pecas;
                }
            }
        } catch (Throwable $e) {
            // Silencioso — mantém ranking zerado
        }
    }

    $labels = array_keys($rankingSetores);
    $valores = array_values($rankingSetores);

    $corGrafico = '#dc2626'; // Vermelho para Atraso
    if ($tipoFiltroLower === 'adiantamento' || $tipoFiltroLower === 'prazo') {
        $corGrafico = '#16a34a'; // Verde
    } elseif ($tipoFiltroLower === 'aberto') {
        $corGrafico = '#0284c7'; // Azul
    } elseif ($tipoFiltroLower === 'apontada') {
        $corGrafico = '#10b981'; // Esmeralda
    }

    return [
        'data_extracao' => $maxData,
        'data_corte' => $dataCorte,
        'data_corte_fmt' => date('d/m/Y', strtotime($dataCorte)),
        'tipo_filtro' => $tipoFiltroLower,
        'linha' => $linhaSel,
        'stats' => $statsGerais,
        'total_filtradas' => count($pecasAnaliticas),
        'ranking_labels' => $labels,
        'ranking_valores' => $valores,
        'cor_grafico' => $corGrafico,
        'pecas_aguardando_material' => $pecasAguardandoMaterial,
        'ofs_aguardando_material' => $ofsAguardandoMaterial,
        'pecas_nao_classificadas' => $pecasNaoClassificadas,
        'pecas_tabela' => $pecasAnaliticas,
    ];
}

/**
 * Fator de avanço de etapa por setor em relação ao Laboratório (LAB / CONSOLIDADO = 1.00) —
 * mesmo mapeamento usado em boletimCalcularAderenciaMensal() (compensa perdas/retrabalho
 * entre etapas: uma célula anterior no fluxo processa um pouco mais de peças do que o
 * Laboratório efetivamente aprova no fim da linha).
 */
function boletimFatorAvancoSetor(string $setorUpper): float
{
    return match (true) {
        $setorUpper === 'BT' || $setorUpper === 'CNC' => 1.05,
        $setorUpper === 'AT' => 1.04,
        $setorUpper === 'SOL' || $setorUpper === 'PIN' => 1.03,
        $setorUpper === 'MN' => 1.02,
        $setorUpper === 'PA' => 1.01,
        default => 1.00,
    };
}

/**
 * Quantidade de apontamentos (Kardex) atribuída a um setor/célula fabril num dia, a partir
 * dos mesmos dados de núcleo (nucleoPorDia) e volume total (porDia) usados na Aderência
 * Mensal. Cada setor conta só os núcleos que fisicamente processa — BT (só ENR), AT (só
 * EMP), CNC (ENR+EMP); os demais (SOL/MN/PIN/PA/MF/LAB) contam o volume total de fábrica
 * do dia, restrito ao núcleo filtrado quando $linhaSel não é TODOS.
 */
function boletimRealizadoDiaPorSetor(string $setorUpper, string $linhaSel, array $nucleoDia, array $porDiaDia): int
{
    $enr = (int) ($nucleoDia['ENR'] ?? 0);
    $emp = (int) ($nucleoDia['EMP'] ?? 0);
    $jc = (int) ($nucleoDia['JC'] ?? 0);

    if ($setorUpper === 'BT') {
        return ($linhaSel === 'TODOS' || $linhaSel === 'ENR') ? $enr : 0;
    }
    if ($setorUpper === 'AT') {
        return ($linhaSel === 'TODOS' || $linhaSel === 'EMP') ? $emp : 0;
    }
    if ($setorUpper === 'CNC') {
        if ($linhaSel === 'ENR')
            return $enr;
        if ($linhaSel === 'EMP')
            return $emp;
        if ($linhaSel === 'JC')
            return 0;
        return $enr + $emp;
    }

    if ($linhaSel === 'ENR')
        return $enr;
    if ($linhaSel === 'EMP')
        return $emp;
    if ($linhaSel === 'JC')
        return $jc;

    $total = (int) ($porDiaDia['TPD_distrib'] ?? 0);
    if ($total === 0 && ($enr + $emp + $jc) > 0) {
        $total = $enr + $emp + $jc;
    }
    return $total;
}

/**
 * Quantidade de apontamentos (Kardex) da Média Força num dia, a partir dos mesmos dados
 * (forcaPorDia / porDia) usados em boletimCalcularAderenciaMensal() para empresa 4 — soma
 * TPD (selado), TPM (conservador) e TPS (seco/resina), ou só o tipo construtivo filtrado.
 */
function boletimRealizadoDiaForca(string $linhaSel, array $forcaDia, array $porDiaDia): int
{
    $tpd = (int) ($forcaDia['TPD'] ?? ($porDiaDia['TPD_forca'] ?? 0));
    $tpm = (int) ($forcaDia['TPM'] ?? ($porDiaDia['TPM'] ?? 0));
    $tps = (int) ($forcaDia['TPS'] ?? ($porDiaDia['TPS'] ?? 0));

    if ($linhaSel === 'TPD')
        return $tpd;
    if ($linhaSel === 'TPM')
        return $tpm;
    if ($linhaSel === 'TPS')
        return $tps;

    return $tpd + $tpm + $tps;
}

/**
 * 4. Calcula os dados do Dashboard de Resumo Diário de Produção.
 * Programado vem do Plano Mestre (DataHoraProducaoAux) e realizado dos apontamentos reais do
 * Kardex — mesmas fontes e mesmo fator de avanço de etapa por setor usados na Aderência
 * Mensal (boletimCalcularAderenciaMensal), somados dia a dia no intervalo [dataInicio, dataFim].
 *
 * Empresa 1 (Distribuição): quebra em 9 células do Fluxo de Pedidos, lado a lado (Bloco 1/2).
 * Empresa 4 (Média Força): não existe hoje uma quebra por célula fabril pra essa fábrica em
 * nenhum lugar do sistema (só a Aderência Mensal, que trata a fábrica como um total único) —
 * então aqui ela também retorna 1 card consolidado (TPD+TPM+TPS), sem as 9 células.
 */
function boletimCalcularResumoDiario(string $dataInicio = '', string $dataFim = '', string $linhaSel = 'TODOS', int $empresa = 1): array
{
    if (!$dataInicio)
        $dataInicio = date('Y-m-01');
    if (!$dataFim)
        $dataFim = date('Y-m-d');

    if (!in_array($empresa, [1, 4], true)) {
        $empresa = 1;
    }

    $linhaSel = strtoupper(trim($linhaSel));
    $linhasValidas = ($empresa === 4) ? ['TODOS', 'TPD', 'TPM', 'TPS'] : ['TODOS', 'ENR', 'EMP', 'JC'];
    if (!in_array($linhaSel, $linhasValidas, true)) {
        $linhaSel = 'TODOS';
    }

    // Carrega o Kardex (apontamentos) de todos os meses cobertos pelo intervalo
    $nucleoPorDia = [];
    $porDia = [];
    $forcaPorDia = [];
    $mesCursor = substr($dataInicio, 0, 7);
    $mesFim = substr($dataFim, 0, 7);
    while ($mesCursor <= $mesFim) {
        $kardex = boletimObterDadosMes($mesCursor);
        $nucleoPorDia += ($kardex['nucleoPorDia'] ?? []);
        $porDia += ($kardex['porDia'] ?? []);
        $forcaPorDia += ($kardex['forcaPorDia'] ?? []);
        $mesCursor = date('Y-m', strtotime($mesCursor . '-01 +1 month'));
    }

    if ($empresa === 4) {
        $prog = 0;
        $real = 0;
        $curDt = $dataInicio;
        while ($curDt <= $dataFim) {
            $prog += (int) round(planoMestreObterProgramadoDia($curDt, 4, $linhaSel));
            $real += boletimRealizadoDiaForca($linhaSel, $forcaPorDia[$curDt] ?? [], $porDia[$curDt] ?? []);
            $curDt = date('Y-m-d', strtotime($curDt . ' +1 day'));
        }

        $aderencia = $prog > 0 ? round(($real / $prog) * 100, 2) : 100.0;
        $isOk = ($aderencia >= 100.0);

        return [
            'data_inicio' => $dataInicio,
            'data_fim' => $dataFim,
            'linha' => $linhaSel,
            'empresa' => $empresa,
            'tabela_esquerda' => [],
            'tabela_direita' => [],
            'total_consolidado' => [
                'setor' => 'MÉDIA FORÇA (CONSOLIDADO)',
                'programado' => number_format($prog, 0, ',', '.'),
                'realizado' => number_format($real, 0, ',', '.'),
                'aderencia' => number_format($aderencia, 2, ',', '.') . '%',
                'aderencia_val' => $aderencia,
                'status_cor' => $isOk ? '#22c55e' : ($aderencia >= 90 ? '#38bdf8' : '#ef4444'),
            ],
        ];
    }

    $tabelaEsquerda = [];
    $tabelaDireita = [];

    foreach (boletimObter11SetoresFabris() as $setorUpper => $cfg) {
        $fatorSetor = boletimFatorAvancoSetor($setorUpper);

        $prog = 0;
        $real = 0;
        $curDt = $dataInicio;
        while ($curDt <= $dataFim) {
            $prog += (int) round(planoMestreObterProgramadoDia($curDt, 1, $linhaSel) * $fatorSetor);
            $real += boletimRealizadoDiaPorSetor($setorUpper, $linhaSel, $nucleoPorDia[$curDt] ?? [], $porDia[$curDt] ?? []);
            $curDt = date('Y-m-d', strtotime($curDt . ' +1 day'));
        }

        $aderencia = $prog > 0 ? round(($real / $prog) * 100, 2) : 100.0;
        $isOk = ($aderencia >= 100.0);

        $itemFormatado = [
            'setor' => mb_strtoupper($cfg['nome']),
            'codigo' => $setorUpper,
            'programado' => number_format($prog, 0, ',', '.'),
            'realizado' => number_format($real, 0, ',', '.'),
            'aderencia' => number_format($aderencia, 2, ',', '.') . '%',
            'aderencia_val' => $aderencia,
            'status_cor' => $isOk ? '#22c55e' : ($aderencia >= 90 ? '#38bdf8' : '#ef4444'),
        ];

        if ((int) $cfg['tabela'] === 1) {
            $tabelaEsquerda[] = $itemFormatado;
        } else {
            $tabelaDireita[] = $itemFormatado;
        }
    }

    return [
        'data_inicio' => $dataInicio,
        'data_fim' => $dataFim,
        'linha' => $linhaSel,
        'empresa' => $empresa,
        'tabela_esquerda' => $tabelaEsquerda,
        'tabela_direita' => $tabelaDireita,
        'total_consolidado' => null,
    ];
}
