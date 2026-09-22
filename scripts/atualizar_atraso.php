<?php
declare(strict_types=1);

/**
 * Atualização automática do atraso (Distribuição + Média Força): SQL Server (vsat.trael.local) -> MySQL.
 *
 * Roda a cada 15 minutos pelo Agendador de Tarefas do Windows (scripts/atualizar_atraso.bat) e
 * substitui a foto do DIA no lugar; dias anteriores nunca são tocados, então a última atualização
 * de cada dia fica como o fechamento dele (histórico/tendência). Só o atraso: não envia nada ao
 * Railway (o fluxo antigo continua em scripts/sincronizar_producao_railway.php).
 *
 * Se a extração vier vazia (ou sem a classificação de gargalo, na Distribuição), a foto anterior
 * do dia é preservada — ver forcarSobrescrita em boletimSincronizarAtrasoSqlServer().
 *
 * Uso: php scripts/atualizar_atraso.php
 * Log: storage/logs/atraso-atualizar.log
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente linha de comando.\n");
}

date_default_timezone_set('America/Cuiaba');

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/boletim-atraso.php';
require_once __DIR__ . '/../includes/boletim-atraso-forca.php';

function atrasoRegistrarLog(string $mensagem): void
{
    $linha = '[' . date('d/m/Y H:i:s') . '] ' . $mensagem . "\n";
    echo $linha;

    $dir = __DIR__ . '/../storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $arquivo = $dir . '/atraso-atualizar.log';

    // ~96 execuções/dia: mantém só as últimas linhas quando o log passa de 256 KB.
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
$lock = fopen($dirCache . '/atualizar_atraso.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    atrasoRegistrarLog('Já existe uma atualização em andamento; esta execução foi ignorada.');
    exit(0);
}

$extracoes = [
    'Distribuição' => fn() => boletimSincronizarAtrasoSqlServer(null, true),
    'Média Força'  => fn() => boletimExtrairSnapshotAtrasoForca(null, true),
];

$falhas = 0;
foreach ($extracoes as $nome => $extrair) {
    try {
        $res = $extrair();
    } catch (Throwable $e) {
        $res = ['sucesso' => false, 'erro' => $e->getMessage()];
    }

    if (!empty($res['sucesso'])) {
        $fases = '';
        if (!empty($res['tempos_s'])) {
            $fases = " [ERP {$res['tempos_s']['erp']}s · gargalo {$res['tempos_s']['gargalo']}s · gravação {$res['tempos_s']['gravacao']}s]";
        }
        atrasoRegistrarLog("$nome: OK — {$res['total_importado']} ordens (foto de {$res['data_extracao']}) em " . ($res['duracao_s'] ?? '?') . 's' . $fases);
    } else {
        $falhas++;
        atrasoRegistrarLog("$nome: FALHA — " . ($res['erro'] ?? 'erro desconhecido'));
    }
}

exit($falhas > 0 ? 1 : 0);
