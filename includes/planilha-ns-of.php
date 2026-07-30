<?php
declare(strict_types=1);

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
 * Carrega o índice cd_of -> dados, cacheado em disco e reconstruído
 * automaticamente sempre que o arquivo fonte mudar (mtime + tamanho).
 * Evita reprocessar dezenas de milhares de linhas a cada leitura de etiqueta:
 * a planilha inteira leva alguns segundos para reprocessar; o cache carrega
 * em milissegundos.
 */
function carregarIndicePlanilhaOF(): array
{
    static $memo = null;
    if ($memo !== null) return $memo;

    if (!is_file(PLANILHA_NS_OF_ARQUIVO)) return $memo = [];

    // Prefixo de versão do formato do índice em cache — muda sempre que o conjunto de
    // colunas extraídas mudar (ex.: adição de descricao/cliente), forçando reconstrução
    // mesmo que o arquivo fonte não tenha sido tocado.
    $assinatura = 'v2:' . filemtime(PLANILHA_NS_OF_ARQUIVO) . ':' . filesize(PLANILHA_NS_OF_ARQUIVO);

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
 * Lê o .xlsx via PharData e monta o índice. Os índices de coluna vêm da
 * definição da tabela do Excel (xl/tables/table1.xml) em vez de fixos por
 * letra — sobrevive a reordenação de colunas na planilha de origem. Se a
 * estrutura interna mudar de forma inesperada, cai nos índices padrão
 * observados no arquivo atual (A=NumSerie, E=cd_Referencia, F=ds_Prod,
 * G=cdPedido, O=cd_of, T=NomeCli).
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

    $colunas = ['NumSerie' => 0, 'cd_Referencia' => 4, 'ds_Prod' => 5, 'cdPedido' => 6, 'cd_of' => 14, 'NomeCli' => 19];
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
    if (!$reader->open($xmlPath)) return [];

    $indice = [];
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

        $cdOf = trim((string) ($cells[$colunas['cd_of']] ?? ''));
        if ($cdOf === '' || !is_numeric($cdOf)) continue;

        $indice[(string) (int) $cdOf] = [
            'num_serie'     => trim((string) ($cells[$colunas['NumSerie']] ?? '')),
            'cd_referencia' => trim((string) ($cells[$colunas['cd_Referencia']] ?? '')),
            'descricao'     => trim((string) ($cells[$colunas['ds_Prod']] ?? '')),
            'cd_pedido'     => trim((string) ($cells[$colunas['cdPedido']] ?? '')),
            'cliente'       => trim((string) ($cells[$colunas['NomeCli']] ?? '')),
        ];
    }
    $reader->close();

    return $indice;
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
