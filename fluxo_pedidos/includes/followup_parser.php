<?php
declare(strict_types=1);

/**
 * Parser e Gerenciador de Follow-Ups (Dados.csv)
 * Mapeia os eventos do SAC do Areco ERP para os pedidos de 2026.
 */

function carregarFollowupsCSV(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $caminhos = [
        __DIR__ . '/../planilhas/Dados.csv',
        __DIR__ . '/../planilhas/dados.csv',
        __DIR__ . '/../../PLANILHA QUE ATUALIZA/Dados.csv',
        __DIR__ . '/../../PLANILHA QUE ATUALIZA/dados.csv',
    ];

    $arquivo = null;
    foreach ($caminhos as $c) {
        if (file_exists($c)) {
            $arquivo = $c;
            break;
        }
    }

    if (!$arquivo) {
        return $cache = [];
    }

    $fh = fopen($arquivo, 'r');
    if (!$fh) {
        return $cache = [];
    }

    // Pular cabeçalho
    $header = fgetcsv($fh, null, ';', '"', '\\');

    $pedidos = [];

    while (($row = fgetcsv($fh, null, ';', '"', '\\')) !== false) {
        if (count($row) < 10) continue;

        $tabela = trim((string)($row[18] ?? ''));
        $codChave = trim((string)($row[19] ?? ''));

        if ($tabela !== 'Pedidos' || !is_numeric($codChave)) {
            continue;
        }

        $pedido = (int)$codChave;
        $tipoCod = (int)($row[4] ?? 0);
        $tipoDesc = trim((string)($row[5] ?? ''));
        $situacao = trim((string)($row[1] ?? ''));
        $dataStr = trim((string)($row[3] ?? ''));
        $de = trim((string)($row[7] ?? ''));
        $para = trim((string)($row[16] ?? ''));
        $obs = trim((string)($row[0] ?? ''));
        $solucao = trim((string)($row[2] ?? ''));

        // Converter data pt-BR (DD/MM/YYYY HH:MM:SS) para timestamp
        $timestamp = 0;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2}):(\d{2})$/', $dataStr, $m)) {
            $timestamp = strtotime("{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}:{$m[6]}");
        }

        $evento = [
            'tipo_cod'     => $tipoCod,
            'tipo_desc'    => $tipoDesc,
            'situacao'     => $situacao,
            'data_str'     => $dataStr,
            'timestamp'    => $timestamp,
            'de'           => $de,
            'para'         => $para,
            'observacoes'  => $obs,
            'solucao'      => $solucao,
        ];

        $pedidos[$pedido][] = $evento;
    }
    fclose($fh);

    // Ordenar eventos de cada pedido cronologicamente
    foreach ($pedidos as $p => &$evts) {
        usort($evts, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    }
    unset($evts);

    return $cache = $pedidos;
}

/**
 * Obtém a lista de Follow-Ups de um pedido
 */
function obterFollowupsDoPedido(int $cdPedido): array
{
    $todos = carregarFollowupsCSV();
    return $todos[$cdPedido] ?? [];
}

/**
 * Obtém o último Follow-Up registrado de um pedido
 */
function obterUltimoFollowupDoPedido(int $cdPedido): ?array
{
    $eventos = obterFollowupsDoPedido($cdPedido);
    if (empty($eventos)) {
        return null;
    }
    return end($eventos);
}
