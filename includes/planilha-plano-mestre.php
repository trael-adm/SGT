<?php
declare(strict_types=1);

/**
 * Módulo de leitura, indexação e cache da planilha "PLANO MESTRE.xlsx".
 *
 * Fonte oficial de programação da fábrica (PCP):
 * - Data de programação: campo DataHoraProducaoAux
 * - Associação: Corresponde à data programada de conclusão e ensaios no LABORATÓRIO (LAB)
 */

if (!defined('PLANILHA_PLANO_MESTRE_CACHE')) {
    define('PLANILHA_PLANO_MESTRE_CACHE', __DIR__ . '/../storage/cache/plano_mestre.cache');
}

/**
 * Retorna o caminho do arquivo PLANO MESTRE.xlsx.
 */
function planoMestreObterCaminhoArquivo(): ?string
{
    $caminhos = [
        __DIR__ . '/../PLANILHA QUE ATUALIZA/PLANO MESTRE.xlsx',
        __DIR__ . '/../PLANILHA Q ATUALIZA/PLANO MESTRE.xlsx',
    ];
    foreach ($caminhos as $p) {
        if (is_file($p))
            return $p;
    }
    return null;
}

/**
 * Converte valor do Excel para data YYYY-MM-DD.
 */
function planoMestreConverterData(mixed $valor, int $ano = 0, int $mes = 0, int $dia = 0): ?string
{
    if ($ano > 1900 && $mes >= 1 && $mes <= 12 && $dia >= 1 && $dia <= 31) {
        return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
    }
    if (is_numeric($valor)) {
        $dias = (int) floor((float) $valor);
        if ($dias > 60) {
            $epoch = -2209075200; // 1899-12-30 00:00:00 UTC
            return gmdate('Y-m-d', $epoch + $dias * 86400);
        }
    }
    $str = trim((string) $valor);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $str, $m)) {
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $str, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    return null;
}

/**
 * Converte coluna de letras do Excel (ex: 'A', 'Z', 'AA', 'AN') em índice zero-based.
 */
function planoMestreColunaParaIndice(string $cellRef): int
{
    $letras = preg_replace('/[0-9]/', '', $cellRef);
    $idx = 0;
    $len = strlen($letras);
    for ($i = 0; $i < $len; $i++) {
        $idx = $idx * 26 + (ord($letras[$i]) - 64);
    }
    return $idx - 1;
}

/**
 * Carrega todos os dados indexados do Plano Mestre, utilizando cache em disco com invalidação por timestamp.
 */
function planoMestreCarregar(bool $forcarRefresh = false): array
{
    static $memoria = null;
    if (!$forcarRefresh && $memoria !== null) {
        return $memoria;
    }

    $caminhoXlsx = planoMestreObterCaminhoArquivo();
    $cacheFile = PLANILHA_PLANO_MESTRE_CACHE;

    if (!$caminhoXlsx && is_file($cacheFile)) {
        $cached = @unserialize((string) file_get_contents($cacheFile), ['allowed_classes' => false]);
        if (is_array($cached))
            return $memoria = $cached;
    }
    if (!$caminhoXlsx) {
        return $memoria = ['porDia' => [], 'porDiaTotal' => [], 'porDiaNucleo' => [], 'totaisMes' => [], 'itens' => []];
    }

    $mtimeXlsx = filemtime($caminhoXlsx);
    if (!$forcarRefresh && is_file($cacheFile)) {
        $mtimeCache = filemtime($cacheFile);
        if ($mtimeCache >= $mtimeXlsx) {
            $cached = @unserialize((string) file_get_contents($cacheFile), ['allowed_classes' => false]);
            if (is_array($cached) && !empty($cached['porDiaTotal'])) {
                return $memoria = $cached;
            }
        }
    }

    // Leitura e indexação da planilha
    $dados = planoMestreConstruirIndice($caminhoXlsx);

    // Salva cache em disco
    $dirCache = dirname($cacheFile);
    if (!is_dir($dirCache)) {
        @mkdir($dirCache, 0775, true);
    }
    @file_put_contents($cacheFile, serialize($dados), LOCK_EX);

    return $memoria = $dados;
}

/**
 * Lê o XLSX via PharData + XMLReader e gera as estruturas agregadas.
 */
function planoMestreConstruirIndice(string $caminhoXlsx): array
{
    $resultado = [
        'timestamp' => time(),
        'arquivo_mtime' => filemtime($caminhoXlsx),
        'porDia' => [], // [empresa][Y-m-d][linha] => qtd
        'porDiaTotal' => [], // [empresa][Y-m-d] => qtd
        'porDiaNucleo' => [], // [empresa][Y-m-d][nucleo] => qtd
        'totaisMes' => [], // [empresa][Y-m][linha] => qtd
        'totaisMesGeral' => [], // [empresa][Y-m] => qtd
        'itens' => [], // Últimos registros ou analíticos
    ];

    try {
        $phar = new PharData($caminhoXlsx, 0, null, Phar::ZIP);

        $sharedStrings = [];
        if (isset($phar['xl/sharedStrings.xml'])) {
            $xml = simplexml_load_string($phar['xl/sharedStrings.xml']->getContent());
            if ($xml && isset($xml->si)) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string) $si->t;
                    } else {
                        $txt = '';
                        foreach ($si->r as $r) {
                            $txt .= (string) $r->t;
                        }
                        $sharedStrings[] = $txt;
                    }
                }
            }
        }

        // Mapeamento dinâmico de colunas
        $colMap = [
            'Quantidade' => 0,
            'DataHoraProducaoAux' => 1,
            'cd_Referencia' => 2,
            'ds_Prod' => 3,
            'qtdItem' => 4,
            'dt_LimiteEntrega' => 5,
            'cdEnt' => 6,
            'Nome' => 7,
            'Apelido' => 8,
            'dt_Pedido' => 9,
            'cdPedido' => 10,
            'ds_potencia' => 11,
            'PotenciaKVA' => 12,
            'nrofasesTrafo' => 13,
            'ds_classeTensaoTrafo' => 14,
            'Ano' => 24,
            'Mes' => 25,
            'ds_TpEnrolamentoNucleo' => 26,
            'Ds_tpConstrTrafo' => 27,
            'Dia' => 28,
            'QtdProduzida' => 32,
            'TotalJC' => 33,
            'TotalAM' => 34,
            'TotalEMP' => 35,
            'TotalEMPLM' => 36,
            'TotalENR' => 37,
            'TotalSL' => 38,
            'cdEntEmpDesti' => 39,
            'SeqPlano' => 40,
            'QtdAproduzirTotal' => 41,
            'QtdAproduzir' => 42,
        ];

        if (isset($phar['xl/tables/table1.xml'])) {
            $tabela = simplexml_load_string($phar['xl/tables/table1.xml']->getContent());
            if ($tabela && isset($tabela->tableColumns->tableColumn)) {
                $i = 0;
                foreach ($tabela->tableColumns->tableColumn as $col) {
                    $nomeCol = (string) $col['name'];
                    if (array_key_exists($nomeCol, $colMap)) {
                        $colMap[$nomeCol] = $i;
                    }
                    $i++;
                }
            }
        }

        $xmlPath = 'phar://' . str_replace('\\', '/', $caminhoXlsx) . '/xl/worksheets/sheet1.xml';
        $reader = new XMLReader();
        if (!$reader->open($xmlPath)) {
            return $resultado;
        }

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'row')
                continue;

            $rowEl = new SimpleXMLElement($reader->readOuterXML());
            if ((int) $rowEl['r'] === 1)
                continue; // cabeçalho

            $cells = [];
            foreach ($rowEl->c as $c) {
                $idx = planoMestreColunaParaIndice((string) $c['r']);
                $val = isset($c->v) ? (string) $c->v : '';
                if ((string) $c['t'] === 's' && isset($sharedStrings[(int) $val])) {
                    $val = $sharedStrings[(int) $val];
                }
                $cells[$idx] = $val;
            }

            $rawQtd = $cells[$colMap['Quantidade']] ?? '1';
            $qtd = (float) str_replace(',', '.', (string) $rawQtd);
            if ($qtd <= 0)
                $qtd = 1.0;

            $rawEmp = trim((string) ($cells[$colMap['cdEntEmpDesti']] ?? '1'));
            $empresa = ($rawEmp === '4') ? 4 : 1;

            $rawAno = (int) ($cells[$colMap['Ano']] ?? 0);
            $rawMes = (int) ($cells[$colMap['Mes']] ?? 0);
            $rawDia = (int) ($cells[$colMap['Dia']] ?? 0);
            $rawDtProg = $cells[$colMap['DataHoraProducaoAux']] ?? null;

            $dtFmt = planoMestreConverterData($rawDtProg, $rawAno, $rawMes, $rawDia);
            if (!$dtFmt)
                continue;

            $ym = substr($dtFmt, 0, 7);

            $ref = strtoupper(trim((string) ($cells[$colMap['cd_Referencia']] ?? '')));
            $nuc = strtoupper(trim((string) ($cells[$colMap['ds_TpEnrolamentoNucleo']] ?? '')));
            $fase = strtoupper(trim((string) ($cells[$colMap['nrofasesTrafo']] ?? '')));

            $rawPot = (float) str_replace(',', '.', (string) ($cells[$colMap['PotenciaKVA']] ?? 0));
            if ($rawPot <= 0) {
                $descTmp = (string) ($cells[$colMap['ds_Prod']] ?? '');
                if (preg_match('/(\d+(?:[.,]\d+)?)\s*kva\b/i', $descTmp, $pm)) {
                    $rawPot = (float) str_replace(',', '.', $pm[1]);
                }
            }
            $potInt = (int) round($rawPot);

            // Determina linha/núcleo normalizado
            $linhaNormalizada = 'OUTROS';
            $nucleoNormalizado = 'OUTROS';

            if ($empresa === 1) {
                // Distribuição
                // REGRA OFICIAL DA FÁBRICA: Todos os 5, 10 e 15 kVA (mesmo com núcleo JC) são classificados como ENR
                if (in_array($potInt, [5, 10, 15], true)) {
                    $linhaNormalizada = 'ENR';
                    $nucleoNormalizado = 'ENR';
                } elseif (str_contains($nuc, 'ENR') || (int) ($cells[$colMap['TotalENR']] ?? 0) > 0) {
                    $linhaNormalizada = 'ENR';
                    $nucleoNormalizado = 'ENR';
                } elseif (str_contains($nuc, 'EMP') || (int) ($cells[$colMap['TotalEMP']] ?? 0) > 0 || (int) ($cells[$colMap['TotalEMPLM']] ?? 0) > 0) {
                    $linhaNormalizada = 'EMP';
                    $nucleoNormalizado = 'EMP';
                } elseif (str_contains($nuc, 'JC') || (int) ($cells[$colMap['TotalJC']] ?? 0) > 0) {
                    $linhaNormalizada = 'JC';
                    $nucleoNormalizado = 'JC';
                } else {
                    $nucleoNormalizado = $nuc ?: 'OUTROS';
                }
            } else {
                // Média Força
                if (str_starts_with($ref, 'TPD')) {
                    $linhaNormalizada = 'TPD';
                } elseif (str_starts_with($ref, 'TPM')) {
                    $linhaNormalizada = 'TPM';
                } elseif (str_starts_with($ref, 'TPS') || $nuc === 'SL' || (int) ($cells[$colMap['TotalSL']] ?? 0) > 0) {
                    $linhaNormalizada = 'TPS';
                }
                $nucleoNormalizado = $nuc ?: $linhaNormalizada;
            }

            $resultado['porDiaItens'][$empresa][$dtFmt][] = [
                'op' => $ref,
                'descricao' => trim((string) ($cells[$colMap['ds_Prod']] ?? '')),
                'pedido' => trim((string) ($cells[$colMap['cdPedido']] ?? '')),
                'cliente' => trim((string) ($cells[$colMap['Nome']] ?? ($cells[$colMap['Apelido']] ?? ''))),
                'potencia' => (float) str_replace(',', '.', (string) ($cells[$colMap['PotenciaKVA']] ?? 0)),
                'fases' => $fase,
                'nucleo' => $nucleoNormalizado,
                'linha' => $linhaNormalizada,
                'quantidade' => $qtd,
                'qtd_produzida' => (float) str_replace(',', '.', (string) ($cells[$colMap['QtdProduzida']] ?? 0)),
                'qtd_a_produzir' => (float) str_replace(',', '.', (string) ($cells[$colMap['QtdAproduzir']] ?? 0)),
                'seq_plano' => (int) ($cells[$colMap['SeqPlano']] ?? 0),
            ];

            // Agregações
            $resultado['porDiaTotal'][$empresa][$dtFmt] = ($resultado['porDiaTotal'][$empresa][$dtFmt] ?? 0.0) + $qtd;
            $resultado['porDia'][$empresa][$dtFmt][$linhaNormalizada] = ($resultado['porDia'][$empresa][$dtFmt][$linhaNormalizada] ?? 0.0) + $qtd;
            $resultado['porDiaNucleo'][$empresa][$dtFmt][$nucleoNormalizado] = ($resultado['porDiaNucleo'][$empresa][$dtFmt][$nucleoNormalizado] ?? 0.0) + $qtd;

            $resultado['totaisMesGeral'][$empresa][$ym] = ($resultado['totaisMesGeral'][$empresa][$ym] ?? 0.0) + $qtd;
            $resultado['totaisMes'][$empresa][$ym][$linhaNormalizada] = ($resultado['totaisMes'][$empresa][$ym][$linhaNormalizada] ?? 0.0) + $qtd;
        }

        $reader->close();
    } catch (\Throwable $e) {
        error_log('Erro ao construir índice do Plano Mestre: ' . $e->getMessage());
    }

    return $resultado;
}

/**
 * Retorna a lista detalhada de peças programadas para um dia específico.
 */
function planoMestreObterItensDia(string $dataYmd, int $empresa = 1, string $linha = 'TODOS'): array
{
    $dados = planoMestreCarregar();
    if (!in_array($empresa, [1, 4], true))
        $empresa = 1;

    $itens = $dados['porDiaItens'][$empresa][$dataYmd] ?? [];
    $linhaUpper = strtoupper(trim($linha));

    if ($linhaUpper === 'TODOS' || empty($linhaUpper)) {
        return $itens;
    }

    return array_values(array_filter($itens, function ($it) use ($linhaUpper, $empresa) {
        if ($empresa === 1) {
            return ($it['nucleo'] ?? '') === $linhaUpper;
        } else {
            return ($it['linha'] ?? '') === $linhaUpper;
        }
    }));
}

/**
 * Retorna a quantidade programada para um dia específico.
 */
function planoMestreObterProgramadoDia(string $dataYmd, int $empresa = 1, string $linha = 'TODOS'): float
{
    $dados = planoMestreCarregar();
    if (!in_array($empresa, [1, 4], true))
        $empresa = 1;

    $linhaUpper = strtoupper(trim($linha));

    if ($linhaUpper === 'TODOS' || empty($linhaUpper)) {
        return (float) ($dados['porDiaTotal'][$empresa][$dataYmd] ?? 0.0);
    }

    return (float) ($dados['porDia'][$empresa][$dataYmd][$linhaUpper] ?? 0.0);
}

/**
 * Retorna a quantidade efetivamente produzida (coluna QtdProduzida do Plano Mestre) das
 * peças programadas para um dia específico — mede aderência real ao plano do dia (por OF),
 * diferente de somar tudo que passou pelo Laboratório naquele dia (que pode incluir
 * atrasados de outros dias e mascarar o não cumprimento do programado do dia).
 */
function planoMestreObterProduzidoDia(string $dataYmd, int $empresa = 1, string $linha = 'TODOS'): float
{
    $itens = planoMestreObterItensDia($dataYmd, $empresa, $linha);

    $total = 0.0;
    foreach ($itens as $it) {
        $total += (float) ($it['qtd_produzida'] ?? 0);
    }

    return $total;
}

/**
 * Retorna o mapa de programação diária para todos os dias do mês informado: ['YYYY-MM-DD' => qtd].
 */
function planoMestreObterProgramadoMes(int $ano, int $mes, int $empresa = 1, string $linha = 'TODOS'): array
{
    $dados = planoMestreCarregar();
    if (!in_array($empresa, [1, 4], true))
        $empresa = 1;

    $mesStr = sprintf('%04d-%02d', $ano, $mes);
    $diasNoMes = (int) date('t', strtotime($mesStr . '-01'));
    $resultado = [];

    for ($d = 1; $d <= $diasNoMes; $d++) {
        $dt = sprintf('%04d-%02d-%02d', $ano, $mes, $d);
        $resultado[$dt] = planoMestreObterProgramadoDia($dt, $empresa, $linha);
    }

    return $resultado;
}

/**
 * Retorna os totais anuais agregados mês a mês para os anos selecionados.
 */
function planoMestreObterTotaisAnuais(array $anos, int $empresa = 1, string $linha = 'TODOS'): array
{
    $dados = planoMestreCarregar();
    if (!in_array($empresa, [1, 4], true))
        $empresa = 1;
    $linhaUpper = strtoupper(trim($linha));

    $resultado = [];
    foreach ($anos as $ano) {
        $ano = (int) $ano;
        $resultado[$ano] = [];
        for ($m = 1; $m <= 12; $m++) {
            $ym = sprintf('%04d-%02d', $ano, $m);
            if ($linhaUpper === 'TODOS' || empty($linhaUpper)) {
                $resultado[$ano][$m] = (float) ($dados['totaisMesGeral'][$empresa][$ym] ?? 0.0);
            } else {
                $resultado[$ano][$m] = (float) ($dados['totaisMes'][$empresa][$ym][$linhaUpper] ?? 0.0);
            }
        }
    }

    return $resultado;
}
