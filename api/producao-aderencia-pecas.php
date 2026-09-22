<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/planilha-plano-mestre.php';
require_once __DIR__ . '/../includes/boletim-planilha.php';
require_once __DIR__ . '/../includes/boletim-fluxo-pedidos.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Sessão expirada. Faça login novamente.']);
    exit;
}

$dataParam = trim((string) ($_GET['data'] ?? ''));
$dataInicioParam = trim((string) ($_GET['data_inicio'] ?? ''));
$dataFimParam = trim((string) ($_GET['data_fim'] ?? ''));

if ($dataInicioParam !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicioParam)) {
    $dataInicio = $dataInicioParam;
    $dataFim = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFimParam)) ? $dataFimParam : $dataInicioParam;
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataParam)) {
    $dataInicio = $dataFim = $dataParam;
} else {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'Data inválida.']);
    exit;
}
if ($dataInicio > $dataFim) {
    [$dataInicio, $dataFim] = [$dataFim, $dataInicio];
}

$empresa = (int) ($_GET['empresa'] ?? 1);
if (!in_array($empresa, [1, 4], true)) $empresa = 1;

$linha = strtoupper(trim((string) ($_GET['linha'] ?? 'TODOS')));
if ($linha === '') $linha = 'TODOS';

// Setor/célula fabril (só se aplica à Distribuição — mesma regra de núcleo por setor usada
// em boletimRealizadoDiaPorSetor(), replicada aqui a nível de item pra filtrar a lista de OFs).
$setor = strtoupper(trim((string) ($_GET['setor'] ?? '')));

function producaoAderenciaPecasAceitaItem(int $empresa, string $setorUpper, string $linhaSel, string $nucleoOuLinhaItem): bool
{
    if ($empresa === 4) {
        return ($linhaSel === 'TODOS') || ($nucleoOuLinhaItem === $linhaSel);
    }

    // Empresa 1 (Distribuição)
    if ($setorUpper === 'BT') {
        return $nucleoOuLinhaItem === 'ENR' && ($linhaSel === 'TODOS' || $linhaSel === 'ENR');
    }
    if ($setorUpper === 'AT') {
        return $nucleoOuLinhaItem === 'EMP' && ($linhaSel === 'TODOS' || $linhaSel === 'EMP');
    }
    if ($setorUpper === 'CNC') {
        if ($linhaSel === 'ENR') return $nucleoOuLinhaItem === 'ENR';
        if ($linhaSel === 'EMP') return $nucleoOuLinhaItem === 'EMP';
        if ($linhaSel === 'JC') return false;
        return in_array($nucleoOuLinhaItem, ['ENR', 'EMP'], true);
    }

    return ($linhaSel === 'TODOS') || ($nucleoOuLinhaItem === $linhaSel);
}

try {
    // 0. Status por célula (Fluxo de Pedidos — CH/BT/AT/CNC/SOL/MN/PIN/ME/MF/LAB) das OFs de
    // Média Força no período. Fonte separada do Plano Mestre (tabelas do ERP via SQL Server),
    // por isso é agregada aqui e casada por "pedido|referência" — não existe pra Distribuição
    // nesta tela ainda (só foi pedido pra Média Força).
    $celulasPorChave = [];
    if ($empresa === 4) {
        $fluxoRes = carregarPlanilhaProducaoFluxo(null, $dataInicio, $dataFim, null, null, null, null, null, null, 'todos', '4');
        if (!empty($fluxoRes['sucesso'])) {
            foreach (($fluxoRes['itens'] ?? []) as $trafo) {
                $chaveTrafo = strtoupper(trim((string) ($trafo['pedido'] ?? ''))) . '|' . strtoupper(trim((string) ($trafo['projeto'] ?? '')));
                foreach (($trafo['setores'] ?? []) as $cel => $st) {
                    if (!isset($celulasPorChave[$chaveTrafo][$cel])) {
                        $celulasPorChave[$chaveTrafo][$cel] = ['ok' => 0, 'total' => 0];
                    }
                    $celulasPorChave[$chaveTrafo][$cel]['total']++;
                    if ($st === 'OK') $celulasPorChave[$chaveTrafo][$cel]['ok']++;
                }
            }
        }
    }

    // 1. Peças Programadas do Plano Mestre (DataHoraProducaoAux / Laboratório), somadas dia a dia
    $programadas = [];
    $qtdProgramadaTotal = 0.0;
    $qtdProduzidaProgramadasTotal = 0.0;

    $dCursor = $dataInicio;
    while ($dCursor <= $dataFim) {
        $programadasRaw = planoMestreObterItensDia($dCursor, $empresa, 'TODOS');

        foreach ($programadasRaw as $p) {
            $nucleoOuLinhaItem = strtoupper(trim((string) (($empresa === 4) ? ($p['linha'] ?? '') : ($p['nucleo'] ?? ''))));
            if (!producaoAderenciaPecasAceitaItem($empresa, $setor, $linha, $nucleoOuLinhaItem)) continue;

            $qtd = (float) ($p['quantidade'] ?? 1);
            $qtdProgramadaTotal += $qtd;
            $qtdProduzidaProgramadasTotal += (float) ($p['qtd_produzida'] ?? 0);

            $chaveItem = strtoupper(trim((string) ($p['pedido'] ?? ''))) . '|' . strtoupper(trim((string) ($p['op'] ?? '')));

            $programadas[] = [
                'data'           => $dCursor,
                'op'             => $p['op'] ?? '—',
                'pedido'         => $p['pedido'] ?? '—',
                'descricao'      => $p['descricao'] ?? '—',
                'cliente'        => $p['cliente'] ?? '—',
                'potencia'       => $p['potencia'] > 0 ? number_format((float)$p['potencia'], 1, ',', '.') . ' kVA' : '—',
                'nucleo'         => $p['nucleo'] ?? '—',
                'linha'          => $p['linha'] ?? '—',
                'quantidade'     => (int) round($qtd),
                'qtd_produzida'  => (int) round((float)($p['qtd_produzida'] ?? 0)),
                'qtd_a_produzir' => (int) round((float)($p['qtd_a_produzir'] ?? 0)),
                'seq_plano'      => (int) ($p['seq_plano'] ?? 0),
                'celulas'        => $celulasPorChave[$chaveItem] ?? null,
            ];
        }

        $dCursor = date('Y-m-d', strtotime($dCursor . ' +1 day'));
    }

    // 2. Peças Produzidas / Concluídas do Kardex (Laboratório), somadas dia a dia — pode cruzar meses
    $produzidas = [];
    $areaCodEsperada = ($empresa === 4) ? 'forca' : 'distrib';
    $mesesBusca = [];
    $mCursor = substr($dataInicio, 0, 7);
    $mFim = substr($dataFim, 0, 7);
    while ($mCursor <= $mFim) {
        $mesesBusca[] = $mCursor;
        $mCursor = date('Y-m', strtotime($mCursor . '-01 +1 month'));
    }

    foreach ($mesesBusca as $mesStr) {
        $kardex = boletimObterDadosMes($mesStr);
        $analitico = $kardex['analitico'] ?? [];

        foreach ($analitico as $it) {
            $dtItem = $it['data_mov'] ?? ($it['data_turno'] ?? '');
            if ($dtItem < $dataInicio || $dtItem > $dataFim) continue;

            $areaItem = $it['area_cod'] ?? '';
            if ($areaItem !== $areaCodEsperada) continue;

            $nucItem = strtoupper(trim((string) ($it['nucleo_cod'] ?? '')));
            $linItem = strtoupper(trim((string) ($it['linha'] ?? '')));

            // REGRA DE FÁBRICA: Todos os 5, 10 e 15 kVA (mesmo que JC) são classificados como ENR
            $kvaProd = (float) ($it['kva'] ?? 0);
            if (in_array((int) round($kvaProd), [5, 10, 15], true) && $areaItem === 'distrib') {
                $nucItem = 'ENR';
            }

            $nucleoOuLinhaItem = ($empresa === 4) ? $linItem : $nucItem;
            if (!producaoAderenciaPecasAceitaItem($empresa, $setor, $linha, $nucleoOuLinhaItem)) continue;

            $serieItem = trim((string) ($it['serie'] ?? ''));
            $refItem = trim((string) ($it['referencia'] ?? ($it['projeto'] ?? '')));

            $produzidas[] = [
                'data'           => $dtItem, // fallback: data do Laboratório — trocada abaixo pela data programada da OF quando encontrada
                'data_lab'       => $dtItem,
                'serie'          => $serieItem !== '' ? $serieItem : '—',
                'of'             => trim((string) ($it['of'] ?? '—')),
                'pedido'         => trim((string) ($it['pedido'] ?? '—')),
                'referencia'     => $refItem !== '' ? $refItem : '—',
                'descricao'      => trim((string) ($it['descricao'] ?? '—')),
                'cliente'        => trim((string) ($it['cliente'] ?? '—')),
                'potencia'       => !empty($it['kva']) ? number_format((float)$it['kva'], 1, ',', '.') . ' kVA' : '—',
                'nucleo'         => trim((string) ($it['nucleo'] ?? $nucItem)),
                'linha'          => $linItem,
                'data_audit'     => !empty($it['data_audit']) ? date('H:i:s', strtotime((string)$it['data_audit'])) : '—',
                'tipo_construtivo' => trim((string) ($it['tipo_construtivo'] ?? '—')),
            ];
        }
    }

    // 2.1. Troca a "Data" exibida na aba OFs Produzidas: em vez da data do Laboratório
    // (data_mov, quando a peça foi apontada), mostra a data programada da OF — mesma fonte
    // (atraso_distribuicao_registros / "Data MF") usada no Status de Peças. Casa por número
    // de série (mais preciso) e cai para a referência (cd_referencia) quando não achar.
    if (!empty($produzidas)) {
        try {
            $pdoOf = getDB();
            if ($pdoOf) {
                $seriesBusca = array_values(array_unique(array_filter(array_column($produzidas, 'serie'), fn($s) => $s !== '—' && $s !== '')));
                $refsBusca = array_values(array_unique(array_filter(array_column($produzidas, 'referencia'), fn($r) => $r !== '—' && $r !== '')));

                if (!empty($seriesBusca) || !empty($refsBusca)) {
                    $maxDataSnap = $pdoOf->query('SELECT MAX(data_extracao) FROM atraso_distribuicao_registros')->fetchColumn();
                    if ($maxDataSnap) {
                        $condicoes = [];
                        $paramsOf = ['data_extracao' => $maxDataSnap];
                        if (!empty($seriesBusca)) {
                            $inSerie = [];
                            foreach ($seriesBusca as $i => $s) { $k = "s{$i}"; $inSerie[] = ":{$k}"; $paramsOf[$k] = $s; }
                            $condicoes[] = 'num_serie IN (' . implode(',', $inSerie) . ')';
                        }
                        if (!empty($refsBusca)) {
                            $inRef = [];
                            foreach ($refsBusca as $i => $r) { $k = "r{$i}"; $inRef[] = ":{$k}"; $paramsOf[$k] = $r; }
                            $condicoes[] = 'cd_referencia IN (' . implode(',', $inRef) . ')';
                        }

                        $stmtOf = $pdoOf->prepare("
                            SELECT num_serie, cd_referencia, data_programada
                            FROM atraso_distribuicao_registros
                            WHERE data_extracao = :data_extracao AND data_programada IS NOT NULL AND (" . implode(' OR ', $condicoes) . ")
                        ");
                        $stmtOf->execute($paramsOf);

                        $dataOfPorSerie = [];
                        $dataOfPorReferencia = [];
                        while ($rowOf = $stmtOf->fetch(PDO::FETCH_ASSOC)) {
                            $ns = trim((string) ($rowOf['num_serie'] ?? ''));
                            if ($ns !== '' && !isset($dataOfPorSerie[$ns])) {
                                $dataOfPorSerie[$ns] = $rowOf['data_programada'];
                            }
                            $ref = trim((string) ($rowOf['cd_referencia'] ?? ''));
                            if ($ref !== '' && !isset($dataOfPorReferencia[$ref])) {
                                $dataOfPorReferencia[$ref] = $rowOf['data_programada'];
                            }
                        }

                        foreach ($produzidas as &$pItem) {
                            $dataOf = $dataOfPorSerie[$pItem['serie']] ?? ($dataOfPorReferencia[$pItem['referencia']] ?? null);
                            if ($dataOf) {
                                $pItem['data'] = $dataOf;
                            }
                        }
                        unset($pItem);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silencioso — mantém a data do Laboratório como fallback já definida acima
        }
    }

    // Estatísticas do período — aderência ao plano (peças programadas x QtdProduzida delas),
    // não o total do Laboratório no período (que pode incluir peças atrasadas de outras datas e
    // mascarar o não cumprimento do programado desse período especificamente).
    $diff = $qtdProduzidaProgramadasTotal - $qtdProgramadaTotal;
    $aderencia = ($qtdProgramadaTotal > 0) ? round(($qtdProduzidaProgramadasTotal / $qtdProgramadaTotal) * 100, 2) : ($qtdProduzidaProgramadasTotal > 0 ? 100.0 : 0.0);

    $diasSemana = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
    if ($dataInicio === $dataFim) {
        $dataFmt = date('d/m/Y', strtotime($dataInicio));
        $diaSemanaNome = $diasSemana[(int) date('w', strtotime($dataInicio))];
    } else {
        $dataFmt = date('d/m/Y', strtotime($dataInicio)) . ' a ' . date('d/m/Y', strtotime($dataFim));
        $diaSemanaNome = null;
    }

    echo json_encode([
        'sucesso'            => true,
        'data'               => $dataInicio,
        'data_inicio'        => $dataInicio,
        'data_fim'           => $dataFim,
        'data_formatada'     => $dataFmt,
        'dia_semana'         => $diaSemanaNome,
        'empresa'            => $empresa,
        'linha'              => $linha,
        'setor'              => $setor,
        'resumo' => [
            'programado'     => (int) round($qtdProgramadaTotal),
            'produzido'      => (int) round($qtdProduzidaProgramadasTotal),
            'superavit'      => (int) round($diff),
            'aderencia'      => $aderencia,
            'atingiu_meta'   => ($qtdProduzidaProgramadasTotal >= $qtdProgramadaTotal),
        ],
        'pecas_programadas'  => $programadas,
        'pecas_produzidas'   => $produzidas,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro ao processar dados: ' . $e->getMessage()]);
}
