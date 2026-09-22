<?php
declare(strict_types=1);

/**
 * Endpoint de Sincronização Segura — Local (Fábrica) -> Nuvem (Railway)
 * Recebe o payload consolidado de produção (Kardex, Metas e Atraso) e atualiza o Railway.
 */

date_default_timezone_set('America/Cuiaba');

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/boletim-planilha.php';
require_once __DIR__ . '/../includes/boletim-atraso.php';
require_once __DIR__ . '/../includes/boletim-atraso-forca.php';
require_once __DIR__ . '/../includes/planilha-plano-mestre.php';

header('Content-Type: application/json; charset=utf-8');

// Token de segurança compartilhado entre o servidor local e o Railway
$tokenEsperado = getenv('SYNC_TOKEN') ?: 'trael_sgt_sync_token_2026';
$tokenRecebido = $_SERVER['HTTP_X_SYNC_TOKEN'] ?? $_POST['sync_token'] ?? $_GET['sync_token'] ?? '';

if (!hash_equals($tokenEsperado, (string)$tokenRecebido)) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Token de sincronização inválido ou ausente.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método HTTP inválido. Utilize POST.']);
    exit;
}

try {
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (!is_array($payload)) {
        // Fallback para form-data tradicional
        $payload = $_POST;
        if (isset($payload['dados']) && is_string($payload['dados'])) {
            $payload['dados'] = json_decode($payload['dados'], true);
        }
        if (isset($payload['metas']) && is_string($payload['metas'])) {
            $payload['metas'] = json_decode($payload['metas'], true);
        }
    }

    $mes = trim((string)($payload['mes'] ?? date('Y-m')));
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = date('Y-m');
    }

    $dados = $payload['dados'] ?? null;
    $metas = $payload['metas'] ?? null;
    $snapshotCsv = $payload['snapshot_csv'] ?? null;
    $snapshotData = $payload['snapshot_data'] ?? null;

    $atualizacoes = [];

    // 1. Atualiza cache do Kardex (Distribuição e Média Força)
    if (is_array($dados) && (!empty($dados['porDia']) || !empty($dados['nucleoPorDia']))) {
        if (!is_dir(BOLETIM_KARDEX_CACHE_DIR)) {
            @mkdir(BOLETIM_KARDEX_CACHE_DIR, 0775, true);
        }

        $cacheJson = BOLETIM_KARDEX_CACHE_DIR . '/kardex_mes_' . $mes . '.json';
        $cacheBin  = BOLETIM_KARDEX_CACHE_DIR . '/kardex_mes_' . $mes . '.cache';

        $cacheEnvelope = [
            'timestamp' => time(),
            'sincronizado_em' => date('Y-m-d H:i:s'),
            'fonte' => 'Sincronizador Local Trael (vsat.trael.local)',
            'dados' => $dados,
        ];

        file_put_contents($cacheJson, json_encode($cacheEnvelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($cacheBin, serialize($cacheEnvelope));
        $atualizacoes[] = "Cache de produção ($mes) atualizado com " . count($dados['porDia'] ?? []) . " dias";
    }

    // 2. Atualiza metas no banco de dados MySQL do Railway (apenas se explicitamente enviadas)
    if (is_array($metas) && !empty($metas)) {
        $pdo = getDB();
        $stmtMeta = $pdo->prepare("
            INSERT INTO boletim_config_metas (
                `month`, meta_tpd_distribuicao, meta_enrolado, meta_convencional, 
                meta_jctrif, meta_tpm, meta_tpd_forca, meta_tps, dias_uteis, dias_customizados
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                meta_tpd_distribuicao = VALUES(meta_tpd_distribuicao),
                meta_enrolado         = VALUES(meta_enrolado),
                meta_convencional     = VALUES(meta_convencional),
                meta_jctrif           = VALUES(meta_jctrif),
                meta_tpm              = VALUES(meta_tpm),
                meta_tpd_forca        = VALUES(meta_tpd_forca),
                meta_tps              = VALUES(meta_tps),
                dias_uteis            = VALUES(dias_uteis),
                dias_customizados     = VALUES(dias_customizados)
        ");

        $stmtMeta->execute([
            $mes,
            (int)($metas['meta_tpd_distribuicao'] ?? 0),
            (int)($metas['meta_enrolado'] ?? 0),
            (int)($metas['meta_convencional'] ?? 0),
            (int)($metas['meta_jctrif'] ?? 0),
            (int)($metas['meta_tpm'] ?? 0),
            (int)($metas['meta_tpd_forca'] ?? 0),
            (int)($metas['meta_tps'] ?? 0),
            (int)($metas['dias_uteis'] ?? 21),
            isset($metas['dias_customizados']) ? (is_string($metas['dias_customizados']) ? $metas['dias_customizados'] : json_encode($metas['dias_customizados'])) : null
        ]);
        $atualizacoes[] = "Metas do mês ($mes) gravadas no banco";
    }

    // 3. Atualiza dados de Atraso de Distribuição diretamente no Banco de Dados MySQL
    $atrasoRegistros = $payload['atraso_registros'] ?? null;
    $snapDate = $snapshotData ?: date('Y-m-d');
    $pdo = getDB();

    if (is_array($atrasoRegistros) && !empty($atrasoRegistros)) {
        boletimGarantirTabelasAtraso($pdo);
        $pdo->prepare("DELETE FROM atraso_distribuicao_registros WHERE data_extracao = ?")->execute([$snapDate]);

        $stmtInsAtraso = $pdo->prepare("
            INSERT INTO atraso_distribuicao_registros (
                data_extracao, data_programada, cd_referencia, ds_produto, qtd_item,
                quantidade, qtd_produzida, qtd_a_produzir, cliente_nome, cliente_apelido,
                cd_pedido, dt_pedido, dt_limite_entrega, potencia_kva, fases,
                classe_tensao, tipo_nucleo, tipo_construtivo, setor_real, tipo_bloqueio,
                linha, seq_plano, uf
            ) VALUES (
                :data_extracao, :data_programada, :cd_referencia, :ds_produto, :qtd_item,
                :quantidade, :qtd_produzida, :qtd_a_produzir, :cliente_nome, :cliente_apelido,
                :cd_pedido, :dt_pedido, :dt_limite_entrega, :potencia_kva, :fases,
                :classe_tensao, :tipo_nucleo, :tipo_construtivo, :setor_real, :tipo_bloqueio,
                :linha, :seq_plano, :uf
            )
        ");

        $pdo->beginTransaction();
        $insCount = 0;
        foreach ($atrasoRegistros as $ar) {
            $nuc  = (string)($ar['tipo_nucleo'] ?? $ar['ds_TpEnrolamentoNucleo'] ?? '');
            $fase = (string)($ar['fases'] ?? $ar['nrofasesTrafo'] ?? '');
            $prod = (string)($ar['ds_produto'] ?? $ar['ds_Prod'] ?? '');
            $linha = $ar['linha'] ?? boletimClassificarLinhaAtraso($nuc, $fase, $prod);

            $stmtInsAtraso->execute([
                'data_extracao'     => $snapDate,
                'data_programada'   => substr((string)($ar['data_programada'] ?? $ar['DataHoraProducaoAux'] ?? $snapDate), 0, 10),
                'cd_referencia'     => trim((string)($ar['cd_referencia'] ?? $ar['cd_Referencia'] ?? '')),
                'ds_produto'        => $prod,
                'qtd_item'          => (int)($ar['qtd_item'] ?? $ar['qtdItem'] ?? 1),
                'quantidade'        => (int)($ar['quantidade'] ?? $ar['Quantidade'] ?? 1),
                'qtd_produzida'     => (int)($ar['qtd_produzida'] ?? $ar['QtdProduzida'] ?? 0),
                'qtd_a_produzir'    => (int)($ar['qtd_a_produzir'] ?? $ar['QtdAproduzir'] ?? 0),
                'cliente_nome'      => trim((string)($ar['cliente_nome'] ?? $ar['Nome'] ?? '')),
                'cliente_apelido'   => trim((string)($ar['cliente_apelido'] ?? $ar['Apelido'] ?? '')),
                'cd_pedido'         => (int)($ar['cd_pedido'] ?? $ar['cdPedido'] ?? 0) ?: null,
                'dt_pedido'         => ($ar['dt_pedido'] ?? $ar['dt_Pedido'] ?? null) ?: null,
                'dt_limite_entrega' => ($ar['dt_limite_entrega'] ?? $ar['dt_LimiteEntrega'] ?? null) ?: null,
                'potencia_kva'      => (float)($ar['potencia_kva'] ?? $ar['PotenciaKVA'] ?? 0),
                'fases'             => $fase,
                'classe_tensao'     => trim((string)($ar['classe_tensao'] ?? $ar['ds_classeTensaoTrafo'] ?? '')),
                'tipo_nucleo'       => $nuc,
                'tipo_construtivo'  => trim((string)($ar['tipo_construtivo'] ?? $ar['Ds_tpConstrTrafo'] ?? '')),
                'setor_real'        => ($ar['setor_real'] ?? null) ?: null,
                'tipo_bloqueio'     => ($ar['tipo_bloqueio'] ?? null) ?: null,
                'linha'             => $linha,
                'seq_plano'         => (int)($ar['seq_plano'] ?? $ar['SeqPlano'] ?? 0),
                'uf'                => trim((string)($ar['uf'] ?? $ar['cd_SglEstado'] ?? ''))
            ]);
            $insCount++;
        }
        $pdo->commit();
        $atualizacoes[] = "Tabela MySQL atraso_distribuicao_registros ($snapDate) atualizada com $insCount registros";
    } elseif (!empty($snapshotCsv) && is_string($snapshotCsv)) {
        boletimGarantirTabelasAtraso($pdo);
        $pdo->prepare("DELETE FROM atraso_distribuicao_registros WHERE data_extracao = ?")->execute([$snapDate]);

        $lines = explode("\n", str_replace("\r", "", $snapshotCsv));
        if (!empty($lines)) {
            $delim = strpos($lines[0], ';') !== false ? ';' : ',';
            $header = str_getcsv(array_shift($lines), $delim, '"', '\\');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $header = array_map('trim', $header);

            $stmtIns = $pdo->prepare("
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

            $pdo->beginTransaction();
            $countCsv = 0;
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $row = str_getcsv($line, $delim, '"', '\\');
                if (count($row) !== count($header)) continue;
                $r = array_combine($header, $row);

                $dtProgRaw = trim((string)($r['DataHoraProducaoAux'] ?? ''));
                $dtProg = substr($dtProgRaw, 0, 10);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dtProg)) $dtProg = $snapDate;

                $nuc  = trim((string)($r['ds_TpEnrolamentoNucleo'] ?? ''));
                $fase = trim((string)($r['nrofasesTrafo'] ?? ''));
                $prod = trim((string)($r['ds_Prod'] ?? ''));
                $linha = boletimClassificarLinhaAtraso($nuc, $fase, $prod);

                $stmtIns->execute([
                    'data_extracao'     => $snapDate,
                    'data_programada'   => $dtProg,
                    'cd_referencia'     => trim((string)($r['cd_Referencia'] ?? '')),
                    'ds_produto'        => $prod,
                    'qtd_item'          => (int)($r['qtdItem'] ?? 1),
                    'quantidade'        => (int)($r['Quantidade'] ?? 1),
                    'qtd_produzida'     => (int)($r['QtdProduzida'] ?? 0),
                    'qtd_a_produzir'    => (int)($r['QtdAproduzir'] ?? 0),
                    'cliente_nome'      => trim((string)($r['Nome'] ?? '')),
                    'cliente_apelido'   => trim((string)($r['Apelido'] ?? '')),
                    'cd_pedido'         => (int)($r['cdPedido'] ?? 0) ?: null,
                    'dt_pedido'         => ($r['dt_Pedido'] ?? null) ?: null,
                    'dt_limite_entrega' => ($r['dt_LimiteEntrega'] ?? null) ?: null,
                    'potencia_kva'      => (float)str_replace(',', '.', (string)($r['PotenciaKVA'] ?? 0)),
                    'fases'             => $fase,
                    'classe_tensao'     => trim((string)($r['ds_classeTensaoTrafo'] ?? '')),
                    'tipo_nucleo'       => $nuc,
                    'tipo_construtivo'  => trim((string)($r['Ds_tpConstrTrafo'] ?? '')),
                    'linha'             => $linha,
                    'seq_plano'         => (int)($r['SeqPlano'] ?? 0),
                    'uf'                => trim((string)($r['cd_SglEstado'] ?? ''))
                ]);
                $countCsv++;
            }
            $pdo->commit();
            $atualizacoes[] = "Snapshot importado para MySQL ($snapDate) com $countCsv registros";
        }
    }

    // 3b. Atualiza dados de Atraso de Média Força diretamente no Banco de Dados MySQL
    $atrasoForcaRegistros = $payload['atraso_forca_registros'] ?? null;
    $snapDateForca = $payload['snapshot_data_forca'] ?? date('Y-m-d');

    if (is_array($atrasoForcaRegistros) && !empty($atrasoForcaRegistros)) {
        boletimGarantirTabelasAtrasoForca($pdo);
        $pdo->prepare("DELETE FROM atraso_forca_registros WHERE data_extracao = ?")->execute([$snapDateForca]);

        $stmtInsAtrasoForca = $pdo->prepare("
            INSERT INTO atraso_forca_registros (
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

        $pdo->beginTransaction();
        $insCountForca = 0;
        foreach ($atrasoForcaRegistros as $ar) {
            $stmtInsAtrasoForca->execute([
                'data_extracao'     => $snapDateForca,
                'data_programada'   => substr((string)($ar['data_programada'] ?? $snapDateForca), 0, 10),
                'cd_referencia'     => trim((string)($ar['cd_referencia'] ?? '')),
                'ds_produto'        => (string)($ar['ds_produto'] ?? ''),
                'qtd_item'          => (int)($ar['qtd_item'] ?? 1),
                'quantidade'        => (int)($ar['quantidade'] ?? 1),
                'qtd_produzida'     => (int)($ar['qtd_produzida'] ?? 0),
                'qtd_a_produzir'    => (int)($ar['qtd_a_produzir'] ?? 0),
                'cliente_nome'      => trim((string)($ar['cliente_nome'] ?? '')),
                'cliente_apelido'   => trim((string)($ar['cliente_apelido'] ?? '')),
                'cd_pedido'         => trim((string)($ar['cd_pedido'] ?? '')) ?: null,
                'dt_pedido'         => ($ar['dt_pedido'] ?? null) ?: null,
                'dt_limite_entrega' => ($ar['dt_limite_entrega'] ?? null) ?: null,
                'potencia_kva'      => (float)($ar['potencia_kva'] ?? 0),
                'fases'             => (int)($ar['fases'] ?? 3),
                'classe_tensao'     => trim((string)($ar['classe_tensao'] ?? '')),
                'tipo_nucleo'       => (string)($ar['tipo_nucleo'] ?? ''),
                'tipo_construtivo'  => (string)($ar['tipo_construtivo'] ?? ''),
                'linha'             => (string)($ar['linha'] ?? ''),
                'seq_plano'         => !empty($ar['seq_plano']) ? (int)$ar['seq_plano'] : null,
                'uf'                => trim((string)($ar['uf'] ?? ''))
            ]);
            $insCountForca++;
        }
        $pdo->commit();
        $atualizacoes[] = "Tabela MySQL atraso_forca_registros ($snapDateForca) atualizada com $insCountForca registros";
    }

    // 4. Atualiza cache do Fluxo de Pedidos
    $fluxoPedidos = $payload['fluxo_pedidos'] ?? null;
    if (is_array($fluxoPedidos) && !empty($fluxoPedidos['pedidos'])) {
        $cacheDir = __DIR__ . '/../storage/cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        file_put_contents($cacheDir . '/fluxo_pedidos.json', json_encode($fluxoPedidos, JSON_UNESCAPED_UNICODE));
        $atualizacoes[] = "Fluxo de Pedidos (" . count($fluxoPedidos['pedidos']) . " pedidos na esteira) atualizado";
    }

    // 5. Atualiza cache da Planilha de Produção do Fluxo
    $fluxoPlanilha = $payload['fluxo_planilha'] ?? null;
    if (is_array($fluxoPlanilha) && !empty($fluxoPlanilha['itens'])) {
        $cacheDir = __DIR__ . '/../storage/cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        file_put_contents($cacheDir . '/fluxo_planilha.json', json_encode($fluxoPlanilha, JSON_UNESCAPED_UNICODE));
        $atualizacoes[] = "Planilha de Produção do Fluxo (" . count($fluxoPlanilha['itens']) . " ordens) atualizada";
    }

    // 6. Atualiza cache de Acompanhamento (Pintura x Montagem)
    $acompanhamento = $payload['acompanhamento'] ?? null;
    if (is_array($acompanhamento) && !empty($acompanhamento['itens'])) {
        $cacheDir = __DIR__ . '/../storage/cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        file_put_contents($cacheDir . '/acompanhamento.json', json_encode($acompanhamento, JSON_UNESCAPED_UNICODE));
        $atualizacoes[] = "Acompanhamento de Produção (" . count($acompanhamento['itens']) . " trafos) atualizado";
    }

    // 7. Atualiza cache do Plano Mestre
    if (!empty($payload['plano_mestre_gz'])) {
        $raw = @gzdecode(base64_decode((string)$payload['plano_mestre_gz']));
        if ($raw) {
            $dadosPm = json_decode($raw, true);
            if (is_array($dadosPm) && !empty($dadosPm['porDiaTotal'])) {
                planoMestreSalvarCache($dadosPm);
                $diasDist = count($dadosPm['porDiaTotal'][1] ?? []);
                $diasForca = count($dadosPm['porDiaTotal'][4] ?? []);
                $atualizacoes[] = "Plano Mestre sincronizado via gzip ($diasDist dias Dist / $diasForca dias Força)";
            }
        }
    } elseif (!empty($payload['plano_mestre']) && is_array($payload['plano_mestre'])) {
        planoMestreSalvarCache($payload['plano_mestre']);
        $atualizacoes[] = "Plano Mestre sincronizado (" . count($payload['plano_mestre']['porDiaTotal'][1] ?? []) . " dias)";
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Sincronização concluída com sucesso!',
        'mes' => $mes,
        'timestamp' => date('Y-m-d H:i:s'),
        'atualizacoes' => $atualizacoes
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Erro interno ao processar sincronização: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
