<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/boletim-fluxo-pedidos.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// ─── Autenticação ───────────────────────────────────────────────────────────
// Módulo Fluxo de Pedidos é só leitura (mesma filosofia do Acompanhamento) —
// qualquer perfil logado pode consultar, sem requirePerfil().
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'resumo_esteira';

try {
    switch ($action) {
        case 'resumo_esteira':
            $resultado = carregarEsteiraPedidos();
            echo json_encode(sanitizarUtf8Recursivo($resultado), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'setor_pedidos':
            $setor = $_GET['setor'] ?? 'COMERCIAL';
            $resultado = obterDadosSetorEspecifico($setor);
            echo json_encode(sanitizarUtf8Recursivo($resultado), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'planilha_producao':
            $limite     = !empty($_GET['limite']) ? (int) $_GET['limite'] : null;
            $dtInicio   = !empty($_GET['dt_inicio']) ? trim((string) $_GET['dt_inicio']) : null;
            $dtFim      = !empty($_GET['dt_fim']) ? trim((string) $_GET['dt_fim']) : null;
            $semana     = !empty($_GET['semana']) ? (int) $_GET['semana'] : null;
            $mes        = !empty($_GET['mes']) ? (int) $_GET['mes'] : null;
            $ano        = !empty($_GET['ano']) ? (int) $_GET['ano'] : null;
            $pedido     = !empty($_GET['pedido']) ? trim((string) $_GET['pedido']) : null;
            $projeto    = !empty($_GET['projeto']) ? trim((string) $_GET['projeto']) : null;
            $ns         = !empty($_GET['ns']) ? trim((string) $_GET['ns']) : null;
            $statusFila = !empty($_GET['status_fila']) ? trim((string) $_GET['status_fila']) : 'em_aberto';
            $empresa    = !empty($_GET['empresa']) ? trim((string) $_GET['empresa']) : null;

            $resultado = carregarPlanilhaProducaoFluxo($limite, $dtInicio, $dtFim, $semana, $mes, $ano, $pedido, $projeto, $ns, $statusFila, $empresa);
            echo json_encode(sanitizarUtf8Recursivo($resultado), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'detalhe_pedido':
            $cdPedido = isset($_GET['cdPedido']) ? (int) $_GET['cdPedido'] : 0;
            if ($cdPedido <= 0) {
                echo json_encode(['sucesso' => false, 'erro' => 'Nº de Pedido inválido.'], JSON_UNESCAPED_UNICODE);
                break;
            }

            $followups = obterFollowupsDoPedidoFluxo($cdPedido);
            $carteira = carregarEsteiraPedidos();
            $pedidoInfo = null;

            if (!empty($carteira['pedidos'])) {
                foreach ($carteira['pedidos'] as $p) {
                    if ((int) $p['cdPedido'] === $cdPedido) {
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
            echo json_encode(sanitizarUtf8Recursivo($dados), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        default:
            echo json_encode(['sucesso' => false, 'erro' => "Ação '$action' desconhecida."], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
