<?php
declare(strict_types=1);

/**
 * Script de Sincronização Local (Fábrica) -> Nuvem (Railway)
 * Executa a extração direta do SQL Server da Trael e envia os dados consolidados para o Railway via HTTPS.
 *
 * Pode ser executado manualmente via terminal ou agendado no Windows Task Scheduler a cada 10/15 minutos.
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/boletim-planilha.php';
require_once __DIR__ . '/../includes/boletim-fluxo-pedidos.php';
require_once __DIR__ . '/../includes/boletim-acompanhamento.php';

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
} else {
    echo "FALHA! Nenhum dado de produção encontrado.\n";
}

// 2. Leitura das Metas do mês no banco local
echo "[2/6] Lendo metas de produção configuradas no banco local... ";
$metas = null;
try {
    $pdoLocal = getDB();
    $stmt = $pdoLocal->prepare("SELECT * FROM boletim_config_metas WHERE `month` = ?");
    $stmt->execute([$mes]);
    $metas = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($metas) {
        echo "OK! (Meta TPD: " . ($metas['meta_tpd_distribuicao'] ?? 0) . " un)\n";
    } else {
        echo "Vazio (usando metas padrão)\n";
    }
} catch (Throwable $e) {
    echo "Erro ao ler metas: " . $e->getMessage() . "\n";
}

// 3. Leitura do Snapshot mais recente de Atraso de Distribuição
echo "[3/6] Verificando snapshots de atraso de distribuição... ";
$snapshotCsv = null;
$snapshotData = null;

$snapFiles = glob(__DIR__ . '/../storage/snapshots/snapshot_*.csv');
if (!empty($snapFiles)) {
    rsort($snapFiles);
    $latestSnap = $snapFiles[0];
    if (preg_match('/snapshot_(\d{4}-\d{2}-\d{2})\.csv$/', $latestSnap, $m)) {
        $snapshotData = $m[1];
        $snapshotCsv = file_get_contents($latestSnap);
        echo "OK! (Snapshot $snapshotData encontrado - " . round(strlen($snapshotCsv) / 1024, 1) . " KB)\n";
    }
} else {
    echo "Nenhum snapshot recente encontrado.\n";
}

// 4. Extração do Fluxo de Pedidos & Esteira Industrial
echo "[4/6] Extraindo esteira e planilha de Fluxo de Pedidos... ";
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

// 5. Extração de Acompanhamento (Pintura x Montagem)
echo "[5/6] Extraindo dados de Acompanhamento de Produção... ";
$acompanhamento = null;
try {
    $acompanhamento = carregarAcompanhamentoProducao();
    $qtdAcomp = count($acompanhamento['itens'] ?? []);
    echo "OK! ($qtdAcomp transformadores em acompanhamento)\n";
} catch (Throwable $e) {
    echo "Erro Acompanhamento: " . $e->getMessage() . "\n";
}

// 6. Envio do payload completo para o Railway via cURL
echo "[6/6] Enviando payload consolidado para o Railway... ";

$payload = [
    'mes' => $mes,
    'dados' => sanitizarUtf8Recursivo($dadosProducao),
    'metas' => sanitizarUtf8Recursivo($metas),
    'snapshot_data' => $snapshotData,
    'snapshot_csv' => $snapshotCsv,
    'fluxo_pedidos' => sanitizarUtf8Recursivo($fluxoPedidos),
    'fluxo_planilha' => sanitizarUtf8Recursivo($fluxoPlanilha),
    'acompanhamento' => sanitizarUtf8Recursivo($acompanhamento),
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
        echo "Erro de conexão cURL: $curlError\n";
    }
    echo "Retorno: $response\n";
}

echo "\nFinalizado em: " . date('d/m/Y H:i:s') . "\n";
echo "===============================================================\n";
