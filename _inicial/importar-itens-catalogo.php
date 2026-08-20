<?php
declare(strict_types=1);

/**
 * Importa/atualiza o catálogo de itens (código/descrição/unidade) a partir de
 * "PLANILHA QUE ATUALIZA/Item.csv" (export CSV com BOM UTF-8, colunas CodProd,
 * DescProd, CodMedEstoque, PrecoMedio) para a tabela itens_catalogo — fonte da
 * busca de "Materiais utilizados" na Triagem do Retrabalho (ver
 * buscarMateriaisCatalogo() em includes/helpers.php).
 *
 * Idempotente: usa INSERT ... ON DUPLICATE KEY UPDATE — rodar de novo depois
 * que o Item.csv for atualizado só atualiza descrição/unidade dos códigos que
 * mudaram e insere os novos, sem duplicar.
 *
 * USO (a partir da raiz do projeto):
 *   php _inicial/importar-itens-catalogo.php                — simula (não grava nada)
 *   php _inicial/importar-itens-catalogo.php --commit       — grava de verdade, usando o banco do .env local
 *   php _inicial/importar-itens-catalogo.php <host> <port> <dbname> <user> <pass> [--commit]
 *                                                            — mesma coisa, mas contra outro banco
 *                                                              (ex.: o do Railway, pra sincronizar produção)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser executado via linha de comando.');
}

define('ITENS_CATALOGO_CSV', __DIR__ . '/../PLANILHA QUE ATUALIZA/Item.csv');

$commit = in_array('--commit', $argv, true);
$argsPosicionais = array_values(array_filter($argv, fn($a, $i) => $i > 0 && $a !== '--commit', ARRAY_FILTER_USE_BOTH));

if (count($argsPosicionais) >= 5) {
    [$host, $port, $dbname, $user, $pass] = $argsPosicionais;
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $alvo = "$dbname @ $host:$port";
} else {
    require_once __DIR__ . '/../config/conexao.php';
    $pdo  = getDB();
    $alvo = (defined('_GFT_ENV') ? _GFT_ENV['DB_NAME'] : 'banco padrão') . ' (config/conexao.php)';
}

echo "=== Alvo: $alvo " . ($commit ? '[GRAVANDO]' : '[SIMULAÇÃO]') . " ===\n";

if (!is_file(ITENS_CATALOGO_CSV)) {
    echo "ERRO: arquivo não encontrado: " . ITENS_CATALOGO_CSV . "\n";
    exit(1);
}

$fh = fopen(ITENS_CATALOGO_CSV, 'r');
if ($fh === false) {
    echo "ERRO: não foi possível abrir o arquivo.\n";
    exit(1);
}

// A 1ª linha vem com BOM UTF-8 (EF BB BF) grudado no início do 1º campo — remove
// antes de interpretar como CSV, senão o cabeçalho "CodProd" não bate no strcmp.
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($fh);

$cabecalho = fgetcsv($fh);
if (!$cabecalho || trim((string) $cabecalho[0]) !== 'CodProd') {
    echo "ERRO: cabeçalho inesperado — esperava a coluna 'CodProd' primeiro, achei: " . json_encode($cabecalho) . "\n";
    exit(1);
}

$itens = []; // codigo => [descricao, unidade]
$linha = 1;
$ignoradas = 0;
while (($row = fgetcsv($fh)) !== false) {
    $linha++;
    $codigo = trim((string) ($row[0] ?? ''));
    $descricao = trim((string) ($row[1] ?? ''));
    $unidade = trim((string) ($row[2] ?? ''));
    if ($codigo === '' || $descricao === '' || $unidade === '') { $ignoradas++; continue; }
    $itens[$codigo] = [$descricao, $unidade];
}
fclose($fh);

echo "Linhas lidas: " . ($linha - 1) . " | itens válidos (código único): " . count($itens) . " | ignoradas: $ignoradas\n";

if (!$commit) {
    echo "\n[SIMULAÇÃO] Nada foi gravado. Rode com --commit pra aplicar de verdade.\n";
    exit;
}

echo "\nGravando...\n";

function inserirEmLotes(PDO $pdo, string $sql, array $tuplas, int $tamanhoLote = 500): void
{
    foreach (array_chunk($tuplas, $tamanhoLote) as $lote) {
        $placeholders = implode(',', array_fill(0, count($lote), '(' . implode(',', array_fill(0, count($lote[0]), '?')) . ')'));
        $params = [];
        foreach ($lote as $tupla) array_push($params, ...$tupla);
        $pdo->prepare(str_replace('__VALUES__', $placeholders, $sql))->execute($params);
    }
}

$tuplas = [];
foreach ($itens as $codigo => [$descricao, $unidade]) {
    $tuplas[] = [$codigo, $descricao, $unidade];
}

$pdo->beginTransaction();
try {
    if ($tuplas) {
        inserirEmLotes($pdo, "
            INSERT INTO itens_catalogo (codigo, descricao, unidade) VALUES __VALUES__
            ON DUPLICATE KEY UPDATE descricao = VALUES(descricao), unidade = VALUES(unidade)
        ", $tuplas);
    }
    $pdo->commit();
    echo "OK — " . count($tuplas) . " itens gravados/atualizados em itens_catalogo.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ERRO, desfeito: " . $e->getMessage() . "\n";
    exit(1);
}
