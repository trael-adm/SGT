<?php
declare(strict_types=1);

@ini_set('memory_limit', '512M');

/**
 * Índice cd_of -> {num_serie, cd_referencia, descricao, cd_pedido, cliente}
 * extraído de "PLANILHA QUE ATUALIZA/NS.OF.xlsx" (tabela NUMERO_DE_SERIE,
 * alimentada por consulta externa/Power Query). Usado pela leitura de
 * etiqueta em Produção: a etiqueta traz prefixo(5) + cd_of + sufixo(1)
 * concatenados sem separador e o cd_of (miolo) é a chave de busca — dela vêm
 * o N° de série, o projeto (cd_Referencia), a descrição do produto (ds_Prod)
 * e o cliente (NomeCli) que alimentam a Área de Leitura da tela de Produção.
 * cd_pedido só é usado como referência — o pedido exibido na tela vem do
 * vínculo já cadastrado em `projetos.id_pedido`, não deste valor.
 *
 * Lido via PharData (suporte a zip embutido no próprio PHP) em vez de
 * ZipArchive/PhpSpreadsheet: a extensão ext-zip não vem habilitada neste
 * ambiente e o projeto não usa Composer.
 */

define('PLANILHA_NS_OF_ARQUIVO', __DIR__ . '/../PLANILHA QUE ATUALIZA/NS.OF.xlsx');
define('PLANILHA_NS_OF_CACHE', __DIR__ . '/../storage/cache/ns_of_indice.cache');

/**
 * Extrai o cd_of de uma etiqueta escaneada no formato prefixo(5) + cd_of +
 * sufixo(1), ex.: "1001020421786" -> cd_of "2042178" (descarta os 5
 * primeiros caracteres e o último, ficando só com o miolo). Códigos fora
 * desse padrão (curtos demais, não numéricos — N° de série digitado
 * manualmente, QR legado) devolvem null; quem chamou decide o fallback
 * (tratar o código bruto como N° de série direto).
 */
function extrairCdOfDaEtiqueta(string $codigoBruto): ?string
{
    $codigo = trim($codigoBruto);
    if (strlen($codigo) < 7 || !ctype_digit($codigo)) return null;
    $cdOf = substr($codigo, 5, -1);
    return $cdOf === '' ? null : (string) (int) $cdOf;
}

/**
 * Busca um cd_of no índice da planilha. Devolve null se o arquivo de origem
 * não existir ou o cd_of não constar na última versão carregada.
 */
function buscarPlanilhaOF(string $cdOf): ?array
{
    $indice = carregarIndicePlanilhaOF();
    return $indice[(string) (int) $cdOf] ?? null;
}

/**
 * Busca um N° de série no índice da planilha. Devolve null se não constar.
 */
function buscarPlanilhaOFPorNs(string $ns): ?array
{
    $ns = trim($ns);
    if ($ns === '') return null;
    $indice = carregarIndicePlanilhaOFPorNs();
    return $indice[$ns] ?? null;
}

/**
 * Data PCP (DataEntraProducao) de um N° de série, para exibição na Relação de
 * Retrabalhos (coluna "Data PCP"). Devolve null se o NS não constar na última
 * versão carregada da planilha ou não tiver essa data preenchida.
 */
function buscarDataPcpPorNs(string $ns): ?string
{
    $dados = buscarPlanilhaOFPorNs($ns);
    return $dados['data_pcp'] ?? null;
}

/** Índice cd_of -> dados (etiqueta de OF), ver construirIndicePlanilhaOF(). */
function carregarIndicePlanilhaOF(): array
{
    return carregarIndiceCompletoPlanilhaOF()['porCdOf'];
}

/** Índice N° de série -> dados completos da planilha, ver construirIndicePlanilhaOF(). */
function carregarIndicePlanilhaOFPorNs(): array
{
    return carregarIndiceCompletoPlanilhaOF()['porNs'];
}

/**
 * Carrega os dois índices da planilha (por cd_of e por N° de série), cacheados
 * em disco e reconstruídos automaticamente sempre que o arquivo fonte mudar
 * (mtime + tamanho). Evita reprocessar dezenas de milhares de linhas a cada
 * leitura de etiqueta: a planilha inteira leva alguns segundos para
 * reprocessar; o cache carrega em milissegundos.
 */
function carregarIndiceCompletoPlanilhaOF(): array
{
    static $memo = null;
    if ($memo !== null) return $memo;

    if (!is_file(PLANILHA_NS_OF_ARQUIVO)) return $memo = ['porCdOf' => [], 'porNs' => []];

    // Prefixo de versão do formato do índice em cache — muda sempre que o conjunto de
    // colunas extraídas mudar (ex.: adição de descricao/cliente/DataEntraProducao),
    // forçando reconstrução mesmo que o arquivo fonte não tenha sido tocado.
    $assinatura = 'v5:' . filemtime(PLANILHA_NS_OF_ARQUIVO) . ':' . filesize(PLANILHA_NS_OF_ARQUIVO);

    if (is_file(PLANILHA_NS_OF_CACHE)) {
        $raw    = @file_get_contents(PLANILHA_NS_OF_CACHE);
        $cached = $raw !== false ? @unserialize($raw, ['allowed_classes' => false]) : false;
        if (is_array($cached) && ($cached['assinatura'] ?? null) === $assinatura) {
            return $memo = $cached['indice'];
        }
    }

    $indice = construirIndicePlanilhaOF(PLANILHA_NS_OF_ARQUIVO);

    $cacheDir = dirname(PLANILHA_NS_OF_CACHE);
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    @file_put_contents(PLANILHA_NS_OF_CACHE, serialize(['assinatura' => $assinatura, 'indice' => $indice]));

    return $memo = $indice;
}

/**
 * Lê o .xlsx via PharData e monta os dois índices (por cd_of e por N° de
 * série) numa única passada pelas linhas. Os índices de coluna vêm da
 * definição da tabela do Excel (xl/tables/table1.xml) em vez de fixos por
 * letra — sobrevive a reordenação de colunas na planilha de origem. Se a
 * estrutura interna mudar de forma inesperada, cai nos índices padrão
 * observados no arquivo atual (A=NumSerie, E=cd_Referencia, F=ds_Prod,
 * G=cdPedido, N=DataEntraProducao, O=cd_of, T=NomeCli).
 */
function construirIndicePlanilhaOF(string $caminhoXlsx): array
{
    $phar = new PharData($caminhoXlsx, 0, null, Phar::ZIP);

    $sharedStrings = [];
    if (isset($phar['xl/sharedStrings.xml'])) {
        $xml = simplexml_load_string($phar['xl/sharedStrings.xml']->getContent());
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $sharedStrings[] = (string) $si->t;
            } else {
                $txt = '';
                foreach ($si->r as $r) { $txt .= (string) $r->t; }
                $sharedStrings[] = $txt;
            }
        }
    }

    $colunas = [
        'NumSerie' => 0, 'NumSerieCliente' => 1, 'Observacao' => 2, 'cd_Referencia' => 4, 'ds_Prod' => 5, 'cdPedido' => 6,
        'DataEntraProducao' => 13, 'cd_of' => 14, 'NomeCli' => 19,
    ];
    if (isset($phar['xl/tables/table1.xml'])) {
        $tabela = simplexml_load_string($phar['xl/tables/table1.xml']->getContent());
        $mapa = [];
        $i = 0;
        foreach ($tabela->tableColumns->tableColumn as $col) {
            $mapa[(string) $col['name']] = $i++;
        }
        foreach (array_keys($colunas) as $nome) {
            if (isset($mapa[$nome])) $colunas[$nome] = $mapa[$nome];
        }
    }

    $xmlPath = 'phar://' . str_replace('\\', '/', $caminhoXlsx) . '/xl/worksheets/sheet1.xml';
    $reader  = new XMLReader();
    if (!$reader->open($xmlPath)) return ['porCdOf' => [], 'porNs' => []];

    $porCdOf = [];
    $porNs   = [];
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'row') continue;

        $rowEl = new SimpleXMLElement($reader->readOuterXML());
        if ((int) $rowEl['r'] === 1) continue; // linha de cabeçalho

        $cells = [];
        foreach ($rowEl->c as $c) {
            $idx  = colunaLetraParaIndice((string) $c['r']);
            $tipo = (string) $c['t'];
            $val  = isset($c->v) ? (string) $c->v : '';
            if ($tipo === 's' && $val !== '') $val = $sharedStrings[(int) $val] ?? '';
            $cells[$idx] = $val;
        }

        $ns      = trim((string) ($cells[$colunas['NumSerie']] ?? ''));
        $dataPcp = converterSerialExcelParaData((string) ($cells[$colunas['DataEntraProducao']] ?? ''));
        $cdOf    = trim((string) ($cells[$colunas['cd_of']] ?? ''));

        $dadosLinha = [
            'num_serie'          => $ns,
            'num_serie_cliente'  => trim((string) ($cells[$colunas['NumSerieCliente']] ?? '')),
            'observacao'         => trim((string) ($cells[$colunas['Observacao']] ?? '')),
            'cd_referencia'      => trim((string) ($cells[$colunas['cd_Referencia']] ?? '')),
            'descricao'          => trim((string) ($cells[$colunas['ds_Prod']] ?? '')),
            'cd_pedido'          => trim((string) ($cells[$colunas['cdPedido']] ?? '')),
            'cliente'            => trim((string) ($cells[$colunas['NomeCli']] ?? '')),
            'cd_of'              => $cdOf,
            'data_pcp'           => $dataPcp,
        ];

        if ($ns !== '') {
            if (!isset($porNs[$ns])) {
                $porNs[$ns] = $dadosLinha;
            } elseif ($dataPcp !== null && empty($porNs[$ns]['data_pcp'])) {
                $porNs[$ns]['data_pcp'] = $dataPcp;
            }
        }

        if ($cdOf !== '' && is_numeric($cdOf)) {
            $porCdOf[(string) (int) $cdOf] = $dadosLinha;
        }
    }
    $reader->close();

    return ['porCdOf' => $porCdOf, 'porNs' => $porNs];
}

/** Converte um serial de data do Excel (dias desde 30/12/1899) em 'Y-m-d'. Null se não for numérico. */
function converterSerialExcelParaData(string $bruto): ?string
{
    $bruto = trim($bruto);
    if ($bruto === '' || !is_numeric($bruto)) return null;
    $serial = (float) $bruto;
    if ($serial <= 0) return null;
    return gmdate('Y-m-d', (int) round(($serial - 25569) * 86400));
}

function colunaLetraParaIndice(string $ref): int
{
    preg_match('/^([A-Z]+)/', $ref, $m);
    $letras = $m[1] ?? 'A';
    $idx = 0;
    for ($i = 0; $i < strlen($letras); $i++) {
        $idx = $idx * 26 + (ord($letras[$i]) - ord('A') + 1);
    }
    return $idx - 1;
}
