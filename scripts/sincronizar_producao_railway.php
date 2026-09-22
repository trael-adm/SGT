<?php
declare(strict_types=1);

/**
 * Script de Sincronização Local (Fábrica) -> Nuvem (Railway)
 * Executa a extração direta do SQL Server da Trael e envia os dados consolidados para o Railway via HTTPS.
 *
 * Pode ser executado manualmente via terminal ou agendado no Windows Task Scheduler a cada 10/15 minutos.
 */

date_default_timezone_set('America/Cuiaba');

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/boletim-planilha.php';
require_once __DIR__ . '/../includes/boletim-atraso.php';
require_once __DIR__ . '/../includes/boletim-atraso-forca.php';
require_once __DIR__ . '/../includes/boletim-fluxo-pedidos.php';
require_once __DIR__ . '/../includes/boletim-acompanhamento.php';
require_once __DIR__ . '/../includes/planilha-plano-mestre.php';

$urlRailway = getenv('RAILWAY_URL') ?: 'https://sgt-production-10fe.up.railway.app';
$syncToken  = getenv('SYNC_TOKEN') ?: 'trael_sgt_sync_token_2026';
$mes        = date('Y-m');

echo "===============================================================\n";
echo "🚀 SGT — SINCRONIZADOR DE PRODUÇÃO (TRAEL -> RAILWAY)\n";
echo "Horário de início: " . date('d/m/Y H:i:s') . "\n";
echo "Mês de referência: $mes\n";
echo "Destino: $urlRailway/api/sync-boletim.php\n";
echo "===============================================================\n\n";

// 1. Extração do SQL Server local (Distribuição e Média Força)
echo "[1/6] Consultando dados de produção no SQL Server (vsat.trael.local)... ";
$dadosProducao = boletimConsultarSqlServerMes($mes);

if ($dadosProducao === null || (empty($dadosProducao['porDia']) && empty($dadosProducao['nucleoPorDia']))) {
    echo "\n[AVISO] Não foi possível conectar ao SQL Server agora. Tentando carregar dados do cache local...\n";
    $dadosProducao = boletimObterDadosMes($mes, false);
}

if (!empty($dadosProducao['porDia'])) {
    echo "OK! (" . count($dadosProducao['porDia']) . " dias de produção extraídos)\n";
    // Atualizar cache local em storage/cache
    try {
        $cacheDir = __DIR__ . '/../storage/cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $localJson = $cacheDir . '/kardex_mes_' . $mes . '.json';
        $localBin  = $cacheDir . '/kardex_mes_' . $mes . '.cache';
        $envelope = [
            'timestamp' => time(),
            'sincronizado_em' => date('Y-m-d H:i:s'),
            'fonte' => 'Sincronizador Local Trael (vsat.trael.local)',
            'dados' => $dadosProducao,
        ];
        file_put_contents($localJson, json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($localBin, serialize($envelope));
    } catch (Throwable $eCache) {}
} else {
    echo "FALHA! Nenhum dado de produção encontrado.\n";
}

// 2. Extração e Sincronização dos registros de Atraso de Distribuição (SQL Server -> MySQL)
echo "[2/6] Consultando ordens de atraso no SQL Server (vsat.trael.local)... ";
$snapshotCsv = null;
$snapshotData = date('Y-m-d');
$atrasoRegistros = null;

try {
    $resSync = boletimSincronizarAtrasoSqlServer();
    if ($resSync['sucesso']) {
        if (!empty($resSync['congelado'])) {
            echo "CONGELADO! (" . $resSync['mensagem'] . ")\n";
        } else {
            echo "OK! (" . $resSync['total_importado'] . " ordens extraídas e travadas para hoje - Data: {$resSync['data_extracao']})\n";
        }
    }
} catch (Throwable $eSyncAtr) {}

try {
    $pdoLocal = getDB();
    boletimGarantirTabelasAtraso($pdoLocal);
    $stmtAtraso = $pdoLocal->query("
        SELECT * FROM atraso_distribuicao_registros
        WHERE data_extracao = (SELECT MAX(data_extracao) FROM atraso_distribuicao_registros)
    ");
    $atrasoRegistros = $stmtAtraso->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($atrasoRegistros)) {
        $snapshotData = $atrasoRegistros[0]['data_extracao'] ?? date('Y-m-d');
    }
} catch (Throwable $eDbAtraso) {}

// 2b. Extração e Sincronização dos registros de Atraso de Média Força (SQL Server -> MySQL)
echo "[2b/6] Consultando ordens de atraso de Média Força no SQL Server... ";
$atrasoForcaRegistros = null;
$snapshotDataForca = date('Y-m-d');

try {
    $resSyncForca = boletimExtrairSnapshotAtrasoForca();
    if ($resSyncForca['sucesso']) {
        if (!empty($resSyncForca['congelado'])) {
            echo "CONGELADO! (" . $resSyncForca['mensagem'] . ")\n";
        } else {
            echo "OK! (" . $resSyncForca['total_importado'] . " ordens extraídas e travadas para hoje - Data: {$resSyncForca['data_extracao']})\n";
        }
    } else {
        echo "FALHA! (" . ($resSyncForca['erro'] ?? 'erro desconhecido') . ")\n";
    }
} catch (Throwable $eSyncAtrForca) {}

try {
    boletimGarantirTabelasAtrasoForca($pdoLocal);
    $stmtAtrasoForca = $pdoLocal->query("
        SELECT * FROM atraso_forca_registros
        WHERE data_extracao = (SELECT MAX(data_extracao) FROM atraso_forca_registros)
    ");
    $atrasoForcaRegistros = $stmtAtrasoForca->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($atrasoForcaRegistros)) {
        $snapshotDataForca = $atrasoForcaRegistros[0]['data_extracao'] ?? date('Y-m-d');
    }
} catch (Throwable $eDbAtrasoForca) {}

// 3. Extração do Fluxo de Pedidos & Esteira Industrial
echo "[3/6] Extraindo esteira e planilha de Fluxo de Pedidos... ";
$fluxoPedidos = null;
$fluxoPlanilha = null;
try {
    $fluxoPedidos = carregarEsteiraPedidos();
    $fluxoPlanilha = carregarPlanilhaProducaoFluxo();
    $qtdPed = count($fluxoPedidos['pedidos'] ?? []);
    $qtdItens = count($fluxoPlanilha['itens'] ?? []);
    echo "OK! ($qtdPed pedidos / $qtdItens ordens na esteira)\n";
} catch (Throwable $e) {
    echo "Erro Fluxo: " . $e->getMessage() . "\n";
}

// 4. Extração de Acompanhamento (Pintura x Montagem)
echo "[4/6] Extraindo dados de Acompanhamento de Produção... ";
$acompanhamento = null;
try {
    $acompanhamento = carregarAcompanhamentoProducao();
    $qtdAcomp = count($acompanhamento['itens'] ?? []);
    echo "OK! ($qtdAcomp transformadores em acompanhamento)\n";
} catch (Throwable $e) {
    echo "Erro Acompanhamento: " . $e->getMessage() . "\n";
}

// 5. Extração e Sincronização do Plano Mestre direto do SQL Server
echo "[5/6] Extraindo Plano Mestre no SQL Server (vsat.trael.local)... ";
$planoMestreGz = null;
try {
    $resPm = planoMestreSincronizarSqlServer();
    if ($resPm['sucesso']) {
        echo "OK! ({$resPm['total_registros']} registros — Dist: {$resPm['total_pecas_distribuicao']} pcs / Força: {$resPm['total_pecas_forca']} pcs em {$resPm['tempo_segundos']}s)\n";
        $dadosPm = planoMestreCarregar(false);
        $planoMestreGz = base64_encode((string) gzencode((string) json_encode($dadosPm), 6));
    } else {
        echo "FALHA! (" . ($resPm['erro'] ?? 'erro desconhecido') . ")\n";
    }
} catch (Throwable $e) {
    echo "Erro Plano Mestre: " . $e->getMessage() . "\n";
}

// 6. Envio do payload completo para o Railway via cURL
echo "[6/6] Enviando payload consolidado para o Railway... ";

$payload = [
    'mes' => $mes,
    'dados' => sanitizarUtf8Recursivo($dadosProducao),
    'snapshot_data' => $snapshotData,
    'snapshot_csv' => $snapshotCsv,
    'atraso_registros' => sanitizarUtf8Recursivo($atrasoRegistros),
    'snapshot_data_forca' => $snapshotDataForca,
    'atraso_forca_registros' => sanitizarUtf8Recursivo($atrasoForcaRegistros),
    'fluxo_pedidos' => sanitizarUtf8Recursivo($fluxoPedidos),
    'fluxo_planilha' => sanitizarUtf8Recursivo($fluxoPlanilha),
    'acompanhamento' => sanitizarUtf8Recursivo($acompanhamento),
    'plano_mestre_gz' => $planoMestreGz,
];

$jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

$ch = curl_init("$urlRailway/api/sync-boletim.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $jsonPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Sync-Token: ' . $syncToken
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($httpCode === 200) {
    $resJson = json_decode((string)$response, true);
    echo "SUCESSO! (HTTP 200)\n\n";
    echo "Resposta do Railway:\n";
    if (is_array($resJson) && !empty($resJson['atualizacoes'])) {
        foreach ($resJson['atualizacoes'] as $att) {
            echo "  ✓ $att\n";
        }
    } else {
        echo "  " . $response . "\n";
    }
} else {
    echo "FALHA! (HTTP $httpCode)\n";
    if ($curlError) {
        echo "Erro cURL: $curlError\n";
    }
    echo "Resposta recebida:\n$response\n";
}

echo "\n===============================================================\n";
echo "Sincronização concluída em " . date('d/m/Y H:i:s') . "\n";
echo "===============================================================\n";
