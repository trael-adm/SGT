<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/boletim-planilha.php';

requireLogin();

$mes  = trim((string) ($_GET['mes'] ?? ''));
$area = trim((string) ($_GET['area'] ?? 'todas'));

if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
    $mes = date('Y-m');
}

if (!in_array($area, ['distrib', 'forca', 'todas'], true)) {
    $area = 'todas';
}

$linhas = boletimBuscarLinhasAnaliticasMes($mes, $area);

$nomeArea = match ($area) {
    'distrib' => 'distribuicao',
    'forca'   => 'media_forca',
    default   => 'geral',
};

$filename = sprintf('boletim_auditoria_%s_%s.csv', $nomeArea, $mes);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Abre saída com BOM UTF-8 para Excel abrir sem problemas de acentuação
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

// Cabeçalhos das colunas
$cabecalho = [
    'Tipo de Registro',
    'Data Turno (Produção)',
    'Data/Hora Apontamento (piAudit)',
    'Data Movimento (Kardex)',
    'Operador (piAudit)',
    'Área',
    'Linha',
    'Núcleo Classificado',
    'Número da OF',
    'Número de Série',
    'Código de Referência',
    'Descrição do Produto',
    'Potência (kVA)',
    'Planta (cdEnt)',
    'Almoxarifado (cd_AlmoxEmpresa)',
];
fputcsv($out, $cabecalho, ';', '"', "\\");

foreach ($linhas as $r) {
    fputcsv($out, [
        $r['tipo'],
        $r['data_turno'],
        $r['data_audit'],
        $r['data_mov'],
        $r['operador'],
        $r['area'],
        $r['linha'],
        $r['nucleo'],
        $r['of'],
        $r['serie'],
        $r['referencia'],
        trim($r['descricao']),
        $r['kva'],
        $r['cdEnt'],
        $r['almoxarifado'],
    ], ';', '"', "\\");
}

fclose($out);
exit;
