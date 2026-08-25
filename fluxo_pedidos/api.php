<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/includes/conexao.php';
require_once __DIR__ . '/includes/followup_parser.php';
require_once __DIR__ . '/includes/motor_pedidos.php';
require_once __DIR__ . '/includes/motor_producao.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'resumo_esteira';

try {
    switch ($action) {
        case 'resumo_esteira':
            $resultado = carregarEsteiraPedidos2026();
            $sanitizado = sanitizarUtf8Recursivo($resultado);
            echo json_encode($sanitizado, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'setor_pedidos':
            $setor = $_GET['setor'] ?? 'COMERCIAL';
            $resultado = obterDadosSetorEspecifico($setor);
            $sanitizado = sanitizarUtf8Recursivo($resultado);
            echo json_encode($sanitizado, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'planilha_producao':
            $limite     = !empty($_GET['limite']) ? (int)$_GET['limite'] : null;
            $dtInicio   = !empty($_GET['dt_inicio']) ? trim((string)$_GET['dt_inicio']) : null;
            $dtFim      = !empty($_GET['dt_fim']) ? trim((string)$_GET['dt_fim']) : null;
            $semana     = !empty($_GET['semana']) ? (int)$_GET['semana'] : null;
            $mes        = !empty($_GET['mes']) ? (int)$_GET['mes'] : null;
            $ano        = !empty($_GET['ano']) ? (int)$_GET['ano'] : null;
            $pedido     = !empty($_GET['pedido']) ? trim((string)$_GET['pedido']) : null;
            $projeto    = !empty($_GET['projeto']) ? trim((string)$_GET['projeto']) : null;
            $ns         = !empty($_GET['ns']) ? trim((string)$_GET['ns']) : null;
            $statusFila = !empty($_GET['status_fila']) ? trim((string)$_GET['status_fila']) : 'em_aberto';
            $empresa    = !empty($_GET['empresa']) ? trim((string)$_GET['empresa']) : null;

            $resultado = carregarPlanilhaProducao($limite, $dtInicio, $dtFim, $semana, $mes, $ano, $pedido, $projeto, $ns, $statusFila, $empresa);
            $sanitizado = sanitizarUtf8Recursivo($resultado);
            echo json_encode($sanitizado, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'detalhe_pedido':
            $cdPedido = isset($_GET['cdPedido']) ? (int)$_GET['cdPedido'] : 0;
            if ($cdPedido <= 0) {
                echo json_encode(['sucesso' => false, 'erro' => 'Nº de Pedido inválido.'], JSON_UNESCAPED_UNICODE);
                break;
            }

            $followups = obterFollowupsDoPedido($cdPedido);
            $carteira = carregarEsteiraPedidos2026();
            $pedidoInfo = null;

            if (!empty($carteira['pedidos'])) {
                foreach ($carteira['pedidos'] as $p) {
                    if ((int)$p['cdPedido'] === $cdPedido) {
                        $pedidoInfo = $p;
                        break;
                    }
                }
            }

            $dados = [
                'sucesso'   => true,
                'pedido'    => $pedidoInfo,
                'followups' => $followups,
            ];
            $sanitizado = sanitizarUtf8Recursivo($dados);
            echo json_encode($sanitizado, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        default:
            echo json_encode(['sucesso' => false, 'erro' => "Ação '$action' desconhecida."], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
