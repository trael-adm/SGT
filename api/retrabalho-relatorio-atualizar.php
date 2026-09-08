<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/retrabalho-relatorio-dados.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado']);
    exit;
}

if (!hasAcesso('tab:retrabalho')) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Você não tem permissão para acessar este relatório.']);
    exit;
}

$pdo = getDB();

// ─── Permissão de Visualização Financeira (R$) — mesma regra de relatorio.php ─
$podeVerValores = isAdmin() || podeEditar('ret.cus') || hasAcesso('ret.cus') || podeEditar('tab:retrabalho');

// ─── Cálculo (mesma função usada pelo carregamento inicial da página) ────────
$dadosRelatorio = calcularRelatorioRetrabalho($pdo, $_GET, $podeVerValores);
extract($dadosRelatorio);

// ─── Fragmentos HTML (reaproveitam exatamente a mesma marcação da página) ────
ob_start();
include __DIR__ . '/../includes/retrabalho-relatorio-kpis.php';
$htmlKpis = ob_get_clean();

ob_start();
include __DIR__ . '/../includes/retrabalho-relatorio-tabela-reprovas-tbody.php';
$htmlTabelaReprovas = ob_get_clean();

ob_start();
include __DIR__ . '/../includes/retrabalho-relatorio-tabela-materiais-tbody.php';
$htmlTabelaMateriais = ob_get_clean();

ob_start();
include __DIR__ . '/../includes/retrabalho-relatorio-tabela-registros-tbody.php';
$htmlTabelaRegistros = ob_get_clean();

echo json_encode([
    'sucesso' => true,
    'custo_hora_homem' => $custoHoraHomem,
    'contadores' => [
        'analise_reprovas' => count($analiseReprovas),
        'materiais'        => count($todosMateriaisConsumidos),
        'registros'        => count($registrosProcessados),
    ],
    'html' => [
        'kpis'              => $htmlKpis,
        'tabela_reprovas'   => $htmlTabelaReprovas,
        'tabela_materiais'  => $htmlTabelaMateriais,
        'tabela_registros'  => $htmlTabelaRegistros,
    ],
    'grafico_reprovas' => [
        'labels'      => $chartReprovaLabels,
        'custos'      => $chartReprovaCustos,
        'horas'       => $chartReprovaHoras,
        'acumulado'   => $chartReprovaAcumulado,
        'pode_ver_valores' => $podeVerValores,
    ],
    'grafico_setores' => [
        'labels'  => $chartSetorLabels,
        'custos'  => $chartSetorCustos,
        'horas'   => $chartSetorHoras,
        'colors'  => $chartSetorColors,
    ],
], JSON_UNESCAPED_UNICODE);
