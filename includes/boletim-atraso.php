<?php
declare(strict_types=1);

/**
 * Módulo de Análise e Métricas de Atraso da Distribuição — SGT.
 *
 * 100% Baseado em Banco de Dados MySQL (com suporte a sincronização direta com SQL Server / Railway).
 * Implementa as regras de negócio e medidas DAX / Power Query do PowerBI corporativo.
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/boletim-planilha.php';
require_once __DIR__ . '/helpers.php';

/**
 * Garante defensivamente que as tabelas de atraso existam no banco de dados.
 */
function boletimGarantirTabelasAtraso(PDO $pdo): void
{
    static $tabelasVerificadas = false;
    if ($tabelasVerificadas) return;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `atraso_distribuicao_registros` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `data_extracao` DATE NOT NULL,
                `data_programada` DATE NOT NULL,
                `cd_referencia` VARCHAR(50) NULL,
                `ds_produto` VARCHAR(255) NULL,
                `qtd_item` INT NOT NULL DEFAULT 1,
                `quantidade` INT NOT NULL DEFAULT 1,
                `qtd_produzida` INT NOT NULL DEFAULT 0,
                `qtd_a_produzir` INT NOT NULL DEFAULT 0,
                `cliente_nome` VARCHAR(255) NULL,
                `cliente_apelido` VARCHAR(100) NULL,
                `cd_pedido` INT NULL,
                `dt_pedido` DATETIME NULL,
                `dt_limite_entrega` DATE NULL,
                `potencia_kva` DECIMAL(10,2) NULL,
                `fases` VARCHAR(20) NULL,
                `classe_tensao` VARCHAR(50) NULL,
                `tipo_nucleo` VARCHAR(50) NULL,
                `tipo_construtivo` VARCHAR(50) NULL,
                `linha` VARCHAR(30) NOT NULL,
                `seq_plano` INT NULL,
                `uf` VARCHAR(10) NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_data_extracao` (`data_extracao`),
                INDEX `idx_data_prog` (`data_programada`),
                INDEX `idx_linha` (`linha`),
                INDEX `idx_extracao_linha` (`data_extracao`, `linha`),
                INDEX `idx_pedido_ref` (`cd_pedido`, `cd_referencia`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `atraso_metas` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `mes` VARCHAR(7) NOT NULL,
                `linha` VARCHAR(30) NOT NULL,
                `meta_dias` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_mes_linha` (`mes`, `linha`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $tabelasVerificadas = true;
    } catch (\Throwable $e) {
        // Log silencioso
    }
}

/**
 * Classifica a linha de fabricação com base na regra do Power Query M.
 */
function boletimClassificarLinhaAtraso(?string $nucleo, ?string $fases, ?string $descricaoProd): string
{
    $n = strtoupper(trim((string) $nucleo));
    $f = strtoupper(trim((string) $fases));
    $p = strtoupper(trim((string) $descricaoProd));

    if ($n === 'EMP' || $n === 'EMP-LM') {
        return 'Convencional';
    }
    if ($n === 'JC' && ($f === 'TRI' || $f === '3' || $f === '3F' || str_contains($p, '3F'))) {
        return 'JC-TRIF';
    }
    if (($n === 'ENR' || $n === 'JC') && ($f === 'MON' || $f === 'BIF' || $f === '1' || $f === '2' || $f === '1F' || $f === '2F')) {
        return 'Monofásico';
    }
    return 'Outros';
}

/**
 * Retorna a lista de datas de extração disponíveis no Banco de Dados.
 */
function boletimListarDatasExtracaoAtraso(): array
{
    $pdo = getDB();
    boletimGarantirTabelasAtraso($pdo);

    $datas = [];
    try {
        $stmt = $pdo->query("
            SELECT data_extracao, COUNT(*) AS total_registros 
            FROM atraso_distribuicao_registros 
            GROUP BY data_extracao 
            ORDER BY data_extracao DESC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $dataIso = $row['data_extracao'];
            $datas[$dataIso] = [
                'data'            => $dataIso,
                'total_registros' => (int) $row['total_registros'],
                'label'           => date('d/m/Y', strtotime($dataIso)) . ' (' . number_format((int) $row['total_registros'], 0, ',', '.') . ' ordens)'
            ];
        }
    } catch (\Throwable $e) {}

    // Fallback: Se o banco estiver zerado (primeira execução), tenta importar dos arquivos de snapshot
    if (empty($datas)) {
        boletimAutoImportarSnapshotsLegados($pdo);
        try {
            $stmt = $pdo->query("
                SELECT data_extracao, COUNT(*) AS total_registros 
                FROM atraso_distribuicao_registros 
                GROUP BY data_extracao 
                ORDER BY data_extracao DESC
            ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $dataIso = $row['data_extracao'];
                $datas[$dataIso] = [
                    'data'            => $dataIso,
                    'total_registros' => (int) $row['total_registros'],
                    'label'           => date('d/m/Y', strtotime($dataIso)) . ' (' . number_format((int) $row['total_registros'], 0, ',', '.') . ' ordens)'
                ];
            }
        } catch (\Throwable $e) {}
    }

    return $datas;
}

/**
 * Importa arquivos legados se o banco estiver vazio.
 */
function boletimAutoImportarSnapshotsLegados(PDO $pdo): void
{
    $files = glob(__DIR__ . '/../storage/snapshots/snapshot_*.csv') ?: [];
    if (empty($files)) {
        $files = glob(__DIR__ . '/../PLANILHA QUE ATUALIZA/snapshot_*.csv') ?: [];
    }
    if (empty($files)) return;

    $stmtInsert = $pdo->prepare("
        INSERT INTO atraso_distribuicao_registros (
            data_extracao, data_programada, cd_referencia, ds_produto, qtd_item,
            quantidade, qtd_produzida, qtd_a_produzir, cliente_nome, cliente_apelido,
            cd_pedido, dt_pedido, dt_limite_entrega, potencia_kva, fases,
            classe_tensao, tipo_nucleo, tipo_construtivo, linha, seq_plano, uf
        ) VALUES (
            :data_extracao, :data_programada, :cd_referencia, :ds_produto, :qtd_item,
            :quantidade, :qtd_produzida, :qtd_a_produzir, :cliente_nome, :cliente_apelido,
            :cd_pedido, :dt_pedido, :dt_limite_entrega, :potencia_kva, :fases,
            :classe_tensao, :tipo_nucleo, :tipo_construtivo, :linha, :seq_plano, :uf
        )
    ");

    foreach ($files as $file) {
        if (!preg_match('/snapshot_(\d{4}-\d{2}-\d{2})\.csv$/', basename($file), $m)) continue;
        $dataExtracao = $m[1];

        $fp = @fopen($file, 'r');
        if (!$fp) continue;
        $firstLine = fgets($fp);
        $delim = strpos($firstLine, ';') !== false ? ';' : ',';
        rewind($fp);
        $header = fgetcsv($fp, 0, $delim, '"', '\\');
        if (!$header) { fclose($fp); continue; }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $header = array_map('trim', $header);

        $pdo->beginTransaction();
        while (($row = fgetcsv($fp, 0, $delim, '"', '\\')) !== false) {
            if (count($row) !== count($header)) continue;
            $r = array_combine($header, $row);

            $dtProgRaw = trim((string)($r['DataHoraProducaoAux'] ?? ''));
            $dtProg = substr($dtProgRaw, 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dtProg)) $dtProg = $dataExtracao;

            $qtd = (int)($r['Quantidade'] ?? 1);
            if ($qtd <= 0) $qtd = (int)($r['QtdAproduzir'] ?? 1);
            if ($qtd <= 0) $qtd = 1;

            $nuc  = trim((string)($r['ds_TpEnrolamentoNucleo'] ?? ''));
            $fase = trim((string)($r['nrofasesTrafo'] ?? ''));
            $prod = trim((string)($r['ds_Prod'] ?? ''));
            $linha = boletimClassificarLinhaAtraso($nuc, $fase, $prod);

            $kva = (float)str_replace(',', '.', (string)($r['PotenciaKVA'] ?? 0));
            $seq = (int)($r['SeqPlano'] ?? 0);

            $dtPedido = trim((string)($r['dt_Pedido'] ?? '')) ?: null;
            if ($dtPedido && !strtotime($dtPedido)) $dtPedido = null;
            $dtLimite = trim((string)($r['dt_LimiteEntrega'] ?? '')) ?: null;
            if ($dtLimite && !strtotime($dtLimite)) $dtLimite = null;

            $stmtInsert->execute([
                'data_extracao'     => $dataExtracao,
                'data_programada'   => $dtProg,
                'cd_referencia'     => trim((string)($r['cd_Referencia'] ?? '')),
                'ds_produto'        => $prod,
                'qtd_item'          => (int)($r['qtdItem'] ?? 1),
                'quantidade'        => $qtd,
                'qtd_produzida'     => (int)($r['QtdProduzida'] ?? 0),
                'qtd_a_produzir'    => (int)($r['QtdAproduzir'] ?? 0),
                'cliente_nome'      => trim((string)($r['Nome'] ?? '')),
                'cliente_apelido'   => trim((string)($r['Apelido'] ?? '')),
                'cd_pedido'         => (int)($r['cdPedido'] ?? 0) ?: null,
                'dt_pedido'         => $dtPedido,
                'dt_limite_entrega' => $dtLimite,
                'potencia_kva'      => $kva,
                'fases'             => $fase,
                'classe_tensao'     => trim((string)($r['ds_classeTensaoTrafo'] ?? '')),
                'tipo_nucleo'       => $nuc,
                'tipo_construtivo'  => trim((string)($r['Ds_tpConstrTrafo'] ?? '')),
                'linha'             => $linha,
                'seq_plano'         => $seq,
                'uf'                => trim((string)($r['cd_SglEstado'] ?? ''))
            ]);
        }
        fclose($fp);
        $pdo->commit();
    }
}

/**
 * Calcula todas as métricas consolidadas de atraso a partir do Banco de Dados MySQL.
 *
 * @param string $dataCorte Data de corte do cálculo (ex: '2026-08-24' ou '2026-08-26')
 * @param array $mesesFiltro Filtro de meses (ex: ['2026-08', '2026-07']) ou vazio para todos
 * @param string|null $dataExtracao Data de extração da foto do Plano Mestre (se nulo, usa a mais recente)
 */
function boletimCalcularMetricasAtraso(string $dataCorte, array $mesesFiltro = [], ?string $dataExtracao = null): array
{
    $pdo = getDB();
    boletimGarantirTabelasAtraso($pdo);

    $datasDisponiveis = boletimListarDatasExtracaoAtraso();
    if (empty($datasDisponiveis)) {
        return ['sucesso' => false, 'erro' => 'Nenhum registro de atraso encontrado no banco de dados.'];
    }

    if ($dataExtracao === null || !isset($datasDisponiveis[$dataExtracao])) {
        $dataExtracao = (string) array_key_first($datasDisponiveis);
    }

    $mesRef = substr($dataCorte, 0, 7); // '2026-08'

    // 1. Carrega produção real diária do Kardex (SQL Server / Boletim) para o mês
    $dadosKardex = boletimObterDadosMes($mesRef);
    $kardexPorDia = $dadosKardex['nucleoPorDia'] ?? [];

    // 2. Busca estrita da data de medição D-1 (PowerBI DAX: MAXX(FILTER(MÉDIA DE PRODUÇÃO, Data < vData)))
    $diasUteisTrabalhados = [];
    foreach ($kardexPorDia as $diaYmd => $prodDia) {
        if ($diaYmd < $dataCorte) { // D-1 estrito conforme DAX
            $diasUteisTrabalhados[] = $diaYmd;
        }
    }
    sort($diasUteisTrabalhados);
    $totalDiasUteisDMenos1 = count($diasUteisTrabalhados);

    // Se for o 1º dia útil do mês ou não houver D-1 anterior, contingência com o dia atual
    if ($totalDiasUteisDMenos1 === 0 && isset($kardexPorDia[$dataCorte])) {
        $diasUteisTrabalhados = [$dataCorte];
        $totalDiasUteisDMenos1 = 1;
    }

    // 3. Produção acumulada no mês até D-1
    $prodAcumulada = [
        'Monofásico'   => 0,
        'Convencional' => 0,
        'JC-TRIF'      => 0,
    ];
    foreach ($diasUteisTrabalhados as $diaYmd) {
        $p = $kardexPorDia[$diaYmd] ?? [];
        $prodAcumulada['Monofásico']   += (int) ($p['ENR'] ?? 0);
        $prodAcumulada['Convencional'] += (int) ($p['EMP'] ?? 0);
        $prodAcumulada['JC-TRIF']      += (int) ($p['JC'] ?? 0);
    }

    // 4. Médias diárias acumuladas até D-1 (peças/dia)
    $mediaDiaria = [
        'Monofásico'   => ($totalDiasUteisDMenos1 > 0) ? ($prodAcumulada['Monofásico'] / $totalDiasUteisDMenos1) : 0.0,
        'Convencional' => ($totalDiasUteisDMenos1 > 0) ? ($prodAcumulada['Convencional'] / $totalDiasUteisDMenos1) : 0.0,
        'JC-TRIF'      => ($totalDiasUteisDMenos1 > 0) ? ($prodAcumulada['JC-TRIF'] / $totalDiasUteisDMenos1) : 0.0,
    ];

    // 5. Consulta itens do banco de dados MySQL para a data de extração selecionada
    $stmtItens = $pdo->prepare("
        SELECT 
            id, data_extracao, data_programada, cd_referencia, ds_produto, qtd_item,
            quantidade, qtd_produzida, qtd_a_produzir, cliente_nome, cliente_apelido,
            cd_pedido, dt_pedido, dt_limite_entrega, potencia_kva, fases,
            classe_tensao, tipo_nucleo, tipo_construtivo, linha, seq_plano, uf
        FROM atraso_distribuicao_registros
        WHERE data_extracao = :data_extracao
        ORDER BY data_programada ASC, id ASC
    ");
    $stmtItens->execute(['data_extracao' => $dataExtracao]);
    $todosItens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

    $itensAtrasados = [];
    $pecasPorLinha = [
        'Monofásico'   => 0.0,
        'Convencional' => 0.0,
        'JC-TRIF'      => 0.0,
        'Outros'       => 0.0,
    ];
    $pecasPorMes = [];
    $mesesDisponiveis = [];

    $mesesNomes = [
        1 => 'JANEIRO', 2 => 'FEVEREIRO', 3 => 'MARÇO', 4 => 'ABRIL',
        5 => 'MAIO', 6 => 'JUNHO', 7 => 'JULHO', 8 => 'AGOSTO',
        9 => 'SETEMBRO', 10 => 'OUTUBRO', 11 => 'NOVEMBRO', 12 => 'DEZEMBRO'
    ];

    foreach ($todosItens as $r) {
        $dtProg = (string) $r['data_programada'];
        $mKey = substr($dtProg, 0, 7); // '2026-08'
        $mesNum = (int) substr($dtProg, 5, 2);
        $anoDois = substr($dtProg, 2, 2);
        $mRotulo = ($mesesNomes[$mesNum] ?? 'MÊS') . '/' . $anoDois;

        if (!isset($mesesDisponiveis[$mKey])) {
            $mesesDisponiveis[$mKey] = $mRotulo;
        }

        // Condição de Atraso: Programação <= Data de Corte
        if ($dtProg > $dataCorte) {
            continue;
        }

        // Filtro opcional de meses
        if (!empty($mesesFiltro) && !in_array($mKey, $mesesFiltro, true)) {
            continue;
        }

        $qtd = (int) $r['quantidade'];
        $linha = $r['linha'];
        if (!isset($pecasPorLinha[$linha])) {
            $linha = 'Convencional';
        }

        $pecasPorLinha[$linha] += $qtd;

        if (!isset($pecasPorMes[$mKey])) {
            $pecasPorMes[$mKey] = [
                'chave'  => $mKey,
                'rotulo' => $mRotulo,
                'qtd'    => 0.0,
            ];
        }
        $pecasPorMes[$mKey]['qtd'] += $qtd;

        $diasAtrasoOrdem = (int) max(0, (strtotime($dataCorte) - strtotime($dtProg)) / 86400);
        $r['dias_atraso_individual'] = $diasAtrasoOrdem;
        $r['mes_ano_rotulo'] = $mRotulo;
        $itensAtrasados[] = $r;
    }

    krsort($pecasPorMes);
    ksort($mesesDisponiveis);

    // 6. Cálculo dos Indicadores de Atraso em Dias (DAX DIVIDE)
    $atrasoDias = [
        'Monofásico'   => ($mediaDiaria['Monofásico'] > 0) ? ($pecasPorLinha['Monofásico'] / $mediaDiaria['Monofásico']) : 0.0,
        'Convencional' => ($mediaDiaria['Convencional'] > 0) ? ($pecasPorLinha['Convencional'] / $mediaDiaria['Convencional']) : 0.0,
        'JC-TRIF'      => ($mediaDiaria['JC-TRIF'] > 0) ? ($pecasPorLinha['JC-TRIF'] / $mediaDiaria['JC-TRIF']) : 0.0,
    ];

    // Média Geral Simples (média aritmética dos 3 ramos industriais)
    $mediaGeralDias = ($atrasoDias['Monofásico'] + $atrasoDias['Convencional'] + $atrasoDias['JC-TRIF']) / 3.0;
    $totalPecasAtrasadas = $pecasPorLinha['Monofásico'] + $pecasPorLinha['Convencional'] + $pecasPorLinha['JC-TRIF'];

    // 7. Série Temporal para o Gráfico "Média de dias em atraso" (Curva Diária)
    $todosDiasKardex = array_keys($kardexPorDia);
    sort($todosDiasKardex);

    $serieEvolucao = [];
    $prodCumSim = ['Monofásico' => 0, 'Convencional' => 0, 'JC-TRIF' => 0];
    $countDiasSim = 0;

    // Agrupa peças em aberto por data de programação
    $pecasPorDataProg = [];
    foreach ($todosItens as $item) {
        $mKey = substr($item['data_programada'], 0, 7);
        if (!empty($mesesFiltro) && !in_array($mKey, $mesesFiltro, true)) continue;
        $dProg = $item['data_programada'];
        $l = $item['linha'];
        if (!isset($pecasPorDataProg[$dProg])) {
            $pecasPorDataProg[$dProg] = ['Monofásico' => 0, 'Convencional' => 0, 'JC-TRIF' => 0];
        }
        if (isset($pecasPorDataProg[$dProg][$l])) {
            $pecasPorDataProg[$dProg][$l] += (int) $item['quantidade'];
        }
    }

    foreach ($todosDiasKardex as $diaSim) {
        $countDiasSim++;
        $p = $kardexPorDia[$diaSim] ?? [];
        $prodCumSim['Monofásico']   += (int) ($p['ENR'] ?? 0);
        $prodCumSim['Convencional'] += (int) ($p['EMP'] ?? 0);
        $prodCumSim['JC-TRIF']      += (int) ($p['JC'] ?? 0);

        $avgMonoSim = ($countDiasSim > 0) ? ($prodCumSim['Monofásico'] / $countDiasSim) : 0.0;
        $avgConvSim = ($countDiasSim > 0) ? ($prodCumSim['Convencional'] / $countDiasSim) : 0.0;
        $avgJCSim   = ($countDiasSim > 0) ? ($prodCumSim['JC-TRIF'] / $countDiasSim) : 0.0;

        // Peças acumuladas em atraso até diaSim
        $pecasMonoSim = 0; $pecasConvSim = 0; $pecasJCSim = 0;
        foreach ($pecasPorDataProg as $dProg => $linhasQtd) {
            if ($dProg <= $diaSim) {
                $pecasMonoSim += $linhasQtd['Monofásico'];
                $pecasConvSim += $linhasQtd['Convencional'];
                $pecasJCSim   += $linhasQtd['JC-TRIF'];
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
        'data_extracao'         => $dataExtracao,
        'mes_referencia'        => $mesRef,
        'datas_disponiveis'     => $datasDisponiveis,
        'meses_disponiveis'     => $mesesDisponiveis,
        'meses_filtro_ativos'   => $mesesFiltro,
        'dias_trabalhados_d1'   => $totalDiasUteisDMenos1,
        'prod_acumulada'        => $prodAcumulada,
        'media_diaria'          => [
            'MONOFASICO'   => round($mediaDiaria['Monofásico'], 2),
            'CONVENCIONAL' => round($mediaDiaria['Convencional'], 2),
            'JC_TRIF'      => round($mediaDiaria['JC-TRIF'], 2),
        ],
        'pecas_atraso'          => [
            'MONOFASICO'   => (int) round($pecasPorLinha['Monofásico']),
            'CONVENCIONAL' => (int) round($pecasPorLinha['Convencional']),
            'JC_TRIF'      => (int) round($pecasPorLinha['JC-TRIF']),
            'TOTAL'        => (int) round($totalPecasAtrasadas),
        ],
        'atraso_dias'           => [
            'MONOFASICO'   => round($atrasoDias['Monofásico'], 2),
            'CONVENCIONAL' => round($atrasoDias['Convencional'], 2),
            'JC_TRIF'      => round($atrasoDias['JC-TRIF'], 2),
            'MEDIA_GERAL'  => round($mediaGeralDias, 2),
        ],
        'pecas_por_mes'         => array_values($pecasPorMes),
        'serie_evolucao'        => $serieEvolucao,
        'total_ordens'          => count($itensAtrasados),
        'itens_detalhados'      => $itensAtrasados,
    ]);
}

/**
 * Formata data Y-m-d para formato por extenso (ex: "26 de agosto").
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
