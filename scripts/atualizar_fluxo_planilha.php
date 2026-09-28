<?php
declare(strict_types=1);

/**
 * Atualização dos caches do Fluxo de Pedidos: SQL Server (vsat.trael.local) -> JSON local.
 *   - storage/cache/fluxo_planilha.json      — Planilha de Produção (status atual das 10 células por NS)
 *   - storage/cache/fluxo_encerramentos.json — encerramentos de sub-OF por célula e dia de turno
 *     (produção real de MF / PIN / ME / BOB no gráfico do Painel por Setor)
 * Um cache não depende do outro: se um falhar, o outro ainda é gravado.
 *
 * A consulta ao vivo (carregarPlanilhaProducaoFluxoAoVivo) tem ~15 joins e passou de 4min30s mesmo
 * filtrada por um intervalo de 25 dias (testado ao vivo em 25/09/2026) — rodar isso a cada
 * carregamento de tela deixava a aba "Setor: PRODUÇÃO" travada em "Carregando...". Por isso a tela
 * (carregarPlanilhaProducaoFluxo, includes/boletim-fluxo-pedidos.php) só lê o snapshot gravado aqui;
 * este script é quem deve rodar periodicamente pra manter o snapshot atualizado.
 *
 * Roda pelo Agendador de Tarefas do Windows (scripts/atualizar_fluxo_planilha.bat) — ver
 * scripts/atualizar_atraso.php pro mesmo padrão. Como a consulta é pesada, não agendar com
 * intervalo curto (15 min como o atraso); o lock abaixo evita sobreposição se uma execução
 * atrasar além do intervalo agendado.
 *
 * Uso: php scripts/atualizar_fluxo_planilha.php
 * Log: storage/logs/fluxo-planilha-atualizar.log
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente linha de comando.\n");
}

date_default_timezone_set('America/Cuiaba');

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/boletim-fluxo-pedidos.php';

function fluxoPlanilhaRegistrarLog(string $mensagem): void
{
    $linha = '[' . date('d/m/Y H:i:s') . '] ' . $mensagem . "\n";
    echo $linha;

    $dir = __DIR__ . '/../storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $arquivo = $dir . '/fluxo-planilha-atualizar.log';

    if (is_file($arquivo) && filesize($arquivo) > 262144) {
        $linhas = file($arquivo, FILE_IGNORE_NEW_LINES) ?: [];
        @file_put_contents($arquivo, implode("\n", array_slice($linhas, -300)) . "\n");
    }
    @file_put_contents($arquivo, $linha, FILE_APPEND | LOCK_EX);
}

// Evita duas atualizações ao mesmo tempo (execução manual + agendada).
$dirCache = __DIR__ . '/../storage/cache';
if (!is_dir($dirCache)) {
    @mkdir($dirCache, 0775, true);
}
$lock = fopen($dirCache . '/atualizar_fluxo_planilha.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fluxoPlanilhaRegistrarLog('Já existe uma atualização em andamento; esta execução foi ignorada.');
    exit(0);
}

function fluxoPlanilhaGravarCache(string $cacheFile, array $resultado): bool
{
    $resultado['gerado_em'] = date('c');
    $tmpFile = $cacheFile . '.tmp';
    $ok = @file_put_contents($tmpFile, json_encode($resultado, JSON_UNESCAPED_UNICODE));
    return $ok !== false && @rename($tmpFile, $cacheFile);
}

$falhou = false;

// 1. Planilha (status atual de cada célula por NS).
$t0 = microtime(true);
try {
    // status_fila='todos' + sem filtros: grava o dataset completo (aberto + concluído) no cache;
    // o filtro por empresa/status/data/etc. é aplicado em memória em carregarPlanilhaProducaoFluxo().
    $resultado = carregarPlanilhaProducaoFluxoAoVivo(null, null, null, null, null, null, null, null, null, 'todos', null);
} catch (Throwable $e) {
    $resultado = ['sucesso' => false, 'erro' => $e->getMessage()];
}
$duracao = round(microtime(true) - $t0, 1);

$cacheFile = $dirCache . '/fluxo_planilha.json';
if (empty($resultado['sucesso'])) {
    fluxoPlanilhaRegistrarLog('FALHA planilha — ' . ($resultado['erro'] ?? 'erro desconhecido') . " ({$duracao}s)");
    $falhou = true;
} elseif (!fluxoPlanilhaGravarCache($cacheFile, $resultado)) {
    fluxoPlanilhaRegistrarLog("FALHA planilha — não foi possível gravar {$cacheFile}");
    $falhou = true;
} else {
    fluxoPlanilhaRegistrarLog("OK planilha — {$resultado['total']} itens ({$resultado['total_concluidos']} concluídos, {$resultado['total_pendentes']} pendentes) em {$duracao}s");
}

// 2. Encerramentos de sub-OF por célula (produção real por dia de turno), desde o
//    dia 1º do mês anterior — cobre o filtro "mês" e intervalos personalizados recentes.
$janelaInicio = date('Y-m-01', strtotime('first day of last month'));
$t0 = microtime(true);
try {
    $encerramentos = carregarEncerramentosCelulasFluxoAoVivo($janelaInicio);
} catch (Throwable $e) {
    $encerramentos = ['sucesso' => false, 'erro' => $e->getMessage()];
}
$duracao = round(microtime(true) - $t0, 1);

if (empty($encerramentos['sucesso'])) {
    fluxoPlanilhaRegistrarLog('FALHA encerramentos — ' . ($encerramentos['erro'] ?? 'erro desconhecido') . " ({$duracao}s)");
    $falhou = true;
} elseif (!fluxoPlanilhaGravarCache(FLUXO_ENCERRAMENTOS_CACHE, $encerramentos)) {
    fluxoPlanilhaRegistrarLog('FALHA encerramentos — não foi possível gravar ' . FLUXO_ENCERRAMENTOS_CACHE);
    $falhou = true;
} else {
    fluxoPlanilhaRegistrarLog("OK encerramentos — {$encerramentos['total']} (célula, NS) desde {$janelaInicio} em {$duracao}s");
}

flock($lock, LOCK_UN);
exit($falhou ? 1 : 0);
