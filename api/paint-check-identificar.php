<?php
declare(strict_types=1);

/**
 * Passo 0 do Paint Check: identifica o transformador (e resolve a regra da
 * concessionária) a partir da etiqueta de produção bipada ou do Nº de Série
 * digitado — sem depender de nenhuma foto/OCR. Mesma resolução de código já
 * usada em `confirmar_chegada`/`confirmar_inicio` (api/retrabalho-acao.php),
 * mas contra o VSAT em vez da planilha NS.OF.xlsx (fonte que o resto do
 * Paint Check já usa, ver includes/vsat-num-series.php).
 *
 * Isso só resolve QUAL REGRA usar pra montar a etapa 2 da tela — não
 * substitui a validação final. Tampa e gancho continuam sendo fotografadas e
 * validadas de verdade em api/paint-check-multi.php.
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/vsat-num-series.php';
require_once __DIR__ . '/../includes/planilha-ns-of.php';
require_once __DIR__ . '/../includes/concessionaria-regras.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

if (!hasAcesso('pin.pai') && !hasAcesso('tab:pintura') && !hasAcesso('admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sem permissão de acesso']);
    exit;
}

$input = file_get_contents('php://input');
$payload = json_decode($input, true);
$codigo = trim((string) ($payload['codigo'] ?? ''));

if ($codigo === '') {
    echo json_encode(['success' => false, 'error' => 'Informe o código da etiqueta ou o Nº de Série.']);
    exit;
}

// Etiqueta de produção (prefixo(5)+cd_of+sufixo(1)) -> VSAT por cd_of; código
// que não bate nesse formato (ou cd_of que o VSAT não conhece) é tratado como
// o próprio N° de série digitado à mão.
$cdOf = extrairCdOfDaEtiqueta($codigo);
$dados = $cdOf !== null ? buscarVsatPorCdOf($cdOf) : null;
if (!$dados) {
    $dados = buscarVsatPorNs($codigo);
}

if (!$dados) {
    echo json_encode(['success' => false, 'error' => 'Nº de série/etiqueta não encontrado no VSAT.']);
    exit;
}

$regra = buscarRegraConcessionaria((string) ($dados['cliente'] ?? ''));

$cdReferencia = (string) ($dados['cd_referencia'] ?? '');

echo json_encode([
    'success' => true,
    'num_serie' => $dados['num_serie'],
    'cliente' => $dados['cliente'],
    'cd_referencia' => $cdReferencia, // código do projeto (projetos.codigo) — usado pelo modal "Reprovar" pra pré-preencher o retrabalho
    'id_projeto' => resolverIdProjetoPorCodigo($cdReferencia), // null se o transformador ainda não tem projeto cadastrado no SGT (ver includes/vsat-num-series.php)
    'descricao' => (string) ($dados['descricao'] ?? ''),
    'cd_pedido' => (string) ($dados['cd_pedido'] ?? ''),
    'concessionaria' => [
        'nome_grupo' => $regra['nome_grupo'],
        'fallback' => $regra['fallback'],
    ],
    'regra' => [
        'locais_obrigatorios' => $regra['locais_obrigatorios'],
        'local_codigo_adicional' => $regra['local_codigo_adicional'],
        'local_potencia' => $regra['local_potencia'],
        'exige_elo_fusivel' => $regra['exige_elo_fusivel'],
    ],
], JSON_UNESCAPED_UNICODE);
