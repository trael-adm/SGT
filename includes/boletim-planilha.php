<?php
declare(strict_types=1);

/**
 * Módulo de Integração de Produção — SQL Server (dw.vw_kardex_lotes e dw.vw_ficha_espc_trafo).
 *
 * Conecta-se diretamente ao servidor SQL Server interno (vsat.trael.local)
 * para obter em tempo real os registros de produção diária, classificação de núcleos,
 * potência média (kVA) e reprovas de laboratório.
 *
 * Possui cache inteligente em disco por mês em `storage/cache/` e fallback resiliente
 * para planilha local caso a rede com o SQL Server esteja indisponível.
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/helpers.php';

if (!defined('BOLETIM_KARDEX_CACHE_DIR')) {
    define('BOLETIM_KARDEX_CACHE_DIR', __DIR__ . '/../storage/cache');
}
if (!defined('BOLETIM_KARDEX_ARQUIVO')) {
    $caminhoPlanilha = __DIR__ . '/../PLANILHA QUE ATUALIZA/Relação Kardex.xlsx';
    if (!is_file($caminhoPlanilha)) {
        $caminhoPlanilha = __DIR__ . '/../PLANILHA Q ATUALIZA/Relação Kardex.xlsx';
    }
    define('BOLETIM_KARDEX_ARQUIVO', $caminhoPlanilha);
}

/**
 * Garante que strings ou arrays aninhados estejam em UTF-8 válido.
 * Converte automaticamente ISO-8859-1/Windows-1252 para UTF-8.
 */
function boletimUtf8Safe(mixed $data): mixed
{
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = boletimUtf8Safe($v);
        }
        return $data;
    }
    if (is_string($data)) {
        if (!mb_check_encoding($data, 'UTF-8')) {
            return mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
        }
        return $data;
    }
    return $data;
}

/**
 * Ajusta data de fim de semana (sábado/domingo) para a sexta-feira anterior.
 * Conforme regra da fábrica: produções de sábado/domingo compõem a sexta-feira.
 */
function boletimAjustarDataFimDeSemanaParaSexta(string $dataYmd): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataYmd)) {
        return $dataYmd;
    }
    $dw = (int) date('N', strtotime($dataYmd)); // 1=seg ... 6=sab, 7=dom
    if ($dw === 6) {
        return date('Y-m-d', strtotime($dataYmd . ' -1 day'));
    }
    if ($dw === 7) {
        return date('Y-m-d', strtotime($dataYmd . ' -2 days'));
    }
    return $dataYmd;
}

/**
 * Classifica o núcleo para a Distribuição conforme regra da fábrica:
 * - EMP: se ds_TpEnrolamentoNucleo for 'EMP' ou 'EMP-LM'.
 * - JC-TRIF (JC): APENAS se ds_TpEnrolamentoNucleo for 'JC' E o transformador for Trifásico ('TRI' ou '3F').
 * - ENR: se for 'ENR' OU se for 'JC' Monofásico/Bifásico ('MON', 'BIF', '1F', '2F').
 */
function boletimClassificarNucleoTrafo(?string $dsTpEnrolamentoNucleo, ?string $nrofasesTrafo, ?string $dsProd = ''): string
{
    $nucRaw  = strtoupper(trim((string) $dsTpEnrolamentoNucleo));
    $faseRaw = strtoupper(trim((string) $nrofasesTrafo));
    $prod    = strtoupper((string) $dsProd);

    if ($nucRaw === 'EMP' || $nucRaw === 'EMP-LM') {
        return 'EMP';
    }

    if ($nucRaw === 'JC') {
        // Apenas JC Trifásico (TRI ou 3F) é JC-TRIF
        if ($faseRaw === 'TRI' || preg_match('/\b3F\b/i', $prod)) {
            return 'JC';
        }
        // Se for JC Monofásico (1F) ou Bifásico (2F), é considerado ENR
        return 'ENR';
    }

    if ($nucRaw === 'ENR') {
        return 'ENR';
    }

    // Fallback por descrição
    if (preg_match('/\b(EMP|EMP-LM)\b/i', $prod)) {
        return 'EMP';
    }
    if (preg_match('/\bJC\b/i', $prod) && (preg_match('/\b3F\b/i', $prod) || $faseRaw === 'TRI')) {
        return 'JC';
    }

    return 'ENR';
}

/**
 * Classifica a linha para a Média Força / Seco:
 * - TPS: todo projeto que tiver 'TPS' no início da referência.
 * - TPD: transformadores com potência até 300 kVA (<= 300).
 * - TPM: transformadores com potência acima de 300 kVA (> 300).
 */
function boletimClassificarLinhaForca(string $ref, ?float $kva): string
{
    $refUpper = strtoupper(trim($ref));
    if (str_starts_with($refUpper, 'TPS')) {
        return 'TPS';
    }
    if ($kva !== null && $kva > 0) {
        return ($kva <= 300.0) ? 'TPD' : 'TPM';
    }
    if (str_starts_with($refUpper, 'TPD')) {
        return 'TPD';
    }
    return 'TPM';
}

/**
 * Retorna os dados agregados para um determinado mês (YYYY-MM).
 * Se $forcarRefresh for true, ignora o cache e reconsulta o SQL Server.
 */
function boletimObterDadosMes(string $mes = '', bool $forcarRefresh = false): array
{
    static $memo = [];

    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = date('Y-m');
    }

    if (!$forcarRefresh && isset($memo[$mes])) {
        return $memo[$mes];
    }

    $jsonFile  = BOLETIM_KARDEX_CACHE_DIR . '/kardex_mes_' . $mes . '.json';
    $cacheFile = BOLETIM_KARDEX_CACHE_DIR . '/kardex_mes_' . $mes . '.cache';

    // 1. Se existir cache em disco (JSON ou binário) com dados válidos, lê o cache
    if (!$forcarRefresh) {
        if (is_file($jsonFile)) {
            $rawJson = @file_get_contents($jsonFile);
            if ($rawJson !== false) {
                $cachedJson = @json_decode($rawJson, true);
                if (is_array($cachedJson) && !empty($cachedJson['dados']) && (!empty($cachedJson['dados']['porDia']) || !empty($cachedJson['dados']['nucleoPorDia']))) {
                    if (!getSqlServerDB()) {
                        return $memo[$mes] = $cachedJson['dados'];
                    }
                    $idade = time() - ($cachedJson['timestamp'] ?? 0);
                    if ($mes !== date('Y-m') || $idade < 600) {
                        return $memo[$mes] = $cachedJson['dados'];
                    }
                }
            }
        }

        if (is_file($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            if ($raw !== false) {
                $cached = @unserialize($raw, ['allowed_classes' => false]);
                if (is_array($cached) && !empty($cached['dados']) && (!empty($cached['dados']['porDia']) || !empty($cached['dados']['nucleoPorDia']))) {
                    if (!getSqlServerDB()) {
                        return $memo[$mes] = $cached['dados'];
                    }
                    $idade = time() - ($cached['timestamp'] ?? 0);
                    if ($mes !== date('Y-m') || $idade < 600) {
                        return $memo[$mes] = $cached['dados'];
                    }
                }
            }
        }
    }

    // 2. Se tiver SQL Server disponível, consulta o banco da fábrica
    $dados = null;
    if (getSqlServerDB()) {
        $dados = boletimConsultarSqlServerMes($mes);
    }

    // 3. Se falhou ou não tem SQL Server (ex: Railway), tenta o cache existente antes de qualquer fallback
    if ($dados === null || (empty($dados['porDia']) && empty($dados['nucleoPorDia']))) {
        if (is_file($jsonFile)) {
            $rawJson = @file_get_contents($jsonFile);
            $cachedJson = $rawJson !== false ? @json_decode($rawJson, true) : null;
            if (is_array($cachedJson) && !empty($cachedJson['dados']) && (!empty($cachedJson['dados']['porDia']) || !empty($cachedJson['dados']['nucleoPorDia']))) {
                return $memo[$mes] = $cachedJson['dados'];
            }
        }
        if (is_file($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            $cached = $raw !== false ? @unserialize($raw, ['allowed_classes' => false]) : null;
            if (is_array($cached) && !empty($cached['dados']) && (!empty($cached['dados']['porDia']) || !empty($cached['dados']['nucleoPorDia']))) {
                return $memo[$mes] = $cached['dados'];
            }
        }
        $dados = boletimFallbackPlanilhaExcel();
    }

    // 4. Salva no cache apenas se tiver dados válidos para não sobrescrever com vazio
    if (!empty($dados) && (!empty($dados['porDia']) || !empty($dados['nucleoPorDia']))) {
        if (!is_dir(BOLETIM_KARDEX_CACHE_DIR)) {
            @mkdir(BOLETIM_KARDEX_CACHE_DIR, 0775, true);
        }
        @file_put_contents($cacheFile, serialize([
            'timestamp' => time(),
            'sincronizado_em' => date('Y-m-d H:i:s'),
            'dados' => $dados,
        ]));
    }

    return $memo[$mes] = $dados;
}

/**
 * Consulta direta ao SQL Server (dw.vw_kardex_lotes + dw.vw_ficha_espc_trafo).
 */
function boletimConsultarSqlServerMes(string $mes): ?array
{
    $pdo = getSqlServerDB();
    if (!$pdo) {
        return null;
    }

    $inicio = $mes . '-01';
    $fim    = date('Y-m-t', strtotime($inicio));
    $dtInicioMes   = $inicio . ' 00:00:00';
    $dtFimMesTurno = date('Y-m-d', strtotime($fim . ' +1 day')) . ' 07:30:00';
    $dtInicioShift = $inicio . ' 07:30:00';

    try {
        // 1. Produção (OFs com Entra_Sai = 'ENT') cruzando com piAudit para o turno da fábrica (07:30 às 02:48/07:30)
        $sqlProd = "
            WITH AuditLotes AS (
                SELECT 
                    A.Id_pk,
                    MAX(A.Data) AS DataHoraAudit
                FROM piAudit A WITH(NOLOCK)
                WHERE A.OIDTable = 29708
                  AND A.Coluna = 'StatusLote'
                  AND A.Data >= ? AND A.Data < ?
                GROUP BY A.Id_pk
            )
            SELECT 
                k.NumSerie,
                k.cd_Referencia,
                k.ds_Prod,
                k.cdEnt,
                k.cd_of,
                k.num_Docto,
                k.cd_AlmoxEmpresa,
                CONVERT(VARCHAR(10), k.dt_Movimento, 120) AS data_mov,
                CONVERT(VARCHAR(19), aud.DataHoraAudit, 120) AS data_hora_audit,
                CONVERT(VARCHAR(10), DATEADD(minute, -450, COALESCE(aud.DataHoraAudit, k.dt_Movimento)), 120) AS data_turno,
                f.ds_TpEnrolamentoNucleo,
                f.ds_potencia,
                f.nrofasesTrafo
            FROM dw.vw_kardex_lotes k
            INNER JOIN ControleLotes C WITH(NOLOCK) ON C.cd_LoteMercEntradaSaida = k.cd_LoteMercEntradaSaida
            LEFT JOIN AuditLotes aud ON aud.Id_pk = C.id_LoteMercEntradaSaida
            OUTER APPLY (
                SELECT TOP 1 ds_TpEnrolamentoNucleo, ds_potencia, nrofasesTrafo
                FROM dw.vw_ficha_espc_trafo f
                WHERE f.cd_Referencia = k.cd_Referencia
            ) f
            WHERE k.num_Docto LIKE 'OF %'
              AND k.Entra_Sai = 'ENT'
              AND (k.cd_Referencia LIKE 'TPD%' OR k.cd_Referencia LIKE 'TPM%' OR k.cd_Referencia LIKE 'TPS%')
              AND (
                  (aud.DataHoraAudit IS NOT NULL AND aud.DataHoraAudit >= ? AND aud.DataHoraAudit < ?)
                  OR
                  (aud.DataHoraAudit IS NULL AND k.dt_Movimento >= ? AND k.dt_Movimento <= ?)
              )
            ORDER BY COALESCE(aud.DataHoraAudit, k.dt_Movimento), k.num_Docto
        ";
        $stmt = $pdo->prepare($sqlProd);
        $stmt->execute([
            $dtInicioMes,
            $dtFimMesTurno,
            $dtInicioShift,
            $dtFimMesTurno,
            $inicio,
            $fim
        ]);
        
        $linhasProd = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Reprovas (cd_AlmoxEmpresa 22/422 com Entra_Sai = 'ENT')
        $sqlRep = "
            WITH AuditLotesRep AS (
                SELECT 
                    A.Id_pk,
                    MAX(A.Data) AS DataHoraAudit
                FROM piAudit A WITH(NOLOCK)
                WHERE A.OIDTable = 29708
                  AND A.Coluna = 'StatusLote'
                  AND A.Data >= ? AND A.Data < ?
                GROUP BY A.Id_pk
            )
            SELECT 
                CONVERT(VARCHAR(10), k.dt_Movimento, 120) AS data_mov,
                CONVERT(VARCHAR(19), aud.DataHoraAudit, 120) AS data_hora_audit,
                CONVERT(VARCHAR(10), DATEADD(minute, -450, COALESCE(aud.DataHoraAudit, k.dt_Movimento)), 120) AS data_turno,
                k.num_Docto,
                k.NumSerie,
                k.cd_Referencia,
                k.ds_Prod,
                k.cd_AlmoxEmpresa,
                k.cdEnt
            FROM dw.vw_kardex_lotes k
            INNER JOIN ControleLotes C WITH(NOLOCK) ON C.cd_LoteMercEntradaSaida = k.cd_LoteMercEntradaSaida
            LEFT JOIN AuditLotesRep aud ON aud.Id_pk = C.id_LoteMercEntradaSaida
            WHERE k.Entra_Sai = 'ENT'
              AND k.cd_AlmoxEmpresa IN (22, 422)
              AND (
                  (aud.DataHoraAudit IS NOT NULL AND aud.DataHoraAudit >= ? AND aud.DataHoraAudit < ?)
                  OR
                  (aud.DataHoraAudit IS NULL AND k.dt_Movimento >= ? AND k.dt_Movimento <= ?)
              )
            ORDER BY COALESCE(aud.DataHoraAudit, k.dt_Movimento), k.num_Docto
        ";
        $stmtRep = $pdo->prepare($sqlRep);
        $stmtRep->execute([
            $dtInicioMes,
            $dtFimMesTurno,
            $dtInicioShift,
            $dtFimMesTurno,
            $inicio,
            $fim
        ]);
        $linhasRep = $stmtRep->fetchAll(PDO::FETCH_ASSOC);

        // Agregação dos dados por data do turno
        $porDia              = [];
        $nucleoPorDia        = [];
        $forcaPorDia         = [];
        $potenciaPorDia      = [];
        $potenciaNucleo      = ['ENR' => ['soma' => 0.0, 'qtd' => 0], 'JC' => ['soma' => 0.0, 'qtd' => 0], 'EMP' => ['soma' => 0.0, 'qtd' => 0]];
        $potenciaForcaNucleo = ['TPD' => ['soma' => 0.0, 'qtd' => 0], 'TPS' => ['soma' => 0.0, 'qtd' => 0], 'TPM' => ['soma' => 0.0, 'qtd' => 0]];
        $reprovasPorDia      = [];
        $analitico           = [];
        $ultimaData          = null;

        foreach ($linhasProd as $r) {
            $d       = boletimAjustarDataFimDeSemanaParaSexta($r['data_turno']);
            $ref     = trim((string) $r['cd_Referencia']);
            $cdEnt   = trim((string) ($r['cdEnt'] ?? ''));

            if (!isset($porDia[$d])) {
                $porDia[$d] = ['TPM' => 0, 'TPS' => 0, 'TPD_distrib' => 0, 'TPD_forca' => 0];
            }
            if (!isset($forcaPorDia[$d])) {
                $forcaPorDia[$d] = ['TPD' => 0, 'TPS' => 0, 'TPM' => 0];
            }

            // Extração da potência (kVA)
            $prod = (string) ($r['ds_Prod'] ?? '');
            $kva = null;
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*kva\b/i', $prod, $m)) {
                $kva = (float) str_replace(',', '.', $m[1]);
            }

            $nucleoNome = '';
            $nucleoCod  = '';
            $linha      = '';

            if ($cdEnt === '1') {
                // Área: Distribuição
                $area = 'distrib';
                $areaPotencia = 'distrib';
                $linha = 'TPD';
                $porDia[$d]['TPD_distrib']++;

                if (!isset($nucleoPorDia[$d])) {
                    $nucleoPorDia[$d] = ['ENR' => 0, 'JC' => 0, 'EMP' => 0];
                }
                $nuc = boletimClassificarNucleoTrafo($r['ds_TpEnrolamentoNucleo'] ?? null, $r['nrofasesTrafo'] ?? null, $r['ds_Prod'] ?? '');
                if (isset($nucleoPorDia[$d][$nuc])) {
                    $nucleoPorDia[$d][$nuc]++;
                }
                $nucleoCod = $nuc;
                $nucleoNome = match ($nuc) {
                    'ENR' => 'ENR (Enrolado)',
                    'JC'  => 'JC-TRIF (Jean Cor Trifásico)',
                    'EMP' => 'EMP (Convencional)',
                    default => 'N/D',
                };

                if ($kva !== null) {
                    if (!isset($potenciaPorDia[$d]['distrib'])) {
                        $potenciaPorDia[$d]['distrib'] = ['soma' => 0.0, 'qtd' => 0];
                    }
                    $potenciaPorDia[$d]['distrib']['soma'] += $kva;
                    $potenciaPorDia[$d]['distrib']['qtd']++;

                    if (isset($potenciaNucleo[$nuc])) {
                        $potenciaNucleo[$nuc]['soma'] += $kva;
                        $potenciaNucleo[$nuc]['qtd']++;
                    }
                }
            } else {
                // Área: Média Força / Seco (cdEnt === '4' / Almox 403)
                $area = 'forca';
                $linhaForca = boletimClassificarLinhaForca($ref, $kva);
                $linha = $linhaForca;
                $nucleoCod = $linhaForca;
                $nucleoNome = match ($linhaForca) {
                    'TPS' => 'TPS (Seco)',
                    'TPD' => 'TPD (≤ 300 kVA)',
                    'TPM' => 'TPM (> 300 kVA)',
                    default => $linhaForca,
                };
                $forcaPorDia[$d][$linhaForca]++;

                if ($linhaForca === 'TPM') {
                    $porDia[$d]['TPM']++;
                    $areaPotencia = 'forca_tpm_tpd';
                } elseif ($linhaForca === 'TPS') {
                    $porDia[$d]['TPS']++;
                    $areaPotencia = 'forca_tps';
                } else { // TPD
                    $porDia[$d]['TPD_forca']++;
                    $areaPotencia = 'forca_tpm_tpd';
                }

                if ($kva !== null) {
                    if (!isset($potenciaPorDia[$d][$areaPotencia])) {
                        $potenciaPorDia[$d][$areaPotencia] = ['soma' => 0.0, 'qtd' => 0];
                    }
                    $potenciaPorDia[$d][$areaPotencia]['soma'] += $kva;
                    $potenciaPorDia[$d][$areaPotencia]['qtd']++;

                    if (!isset($potenciaPorDia[$d]['forca_total'])) {
                        $potenciaPorDia[$d]['forca_total'] = ['soma' => 0.0, 'qtd' => 0];
                    }
                    $potenciaPorDia[$d]['forca_total']['soma'] += $kva;
                    $potenciaPorDia[$d]['forca_total']['qtd']++;

                    if (isset($potenciaForcaNucleo[$linhaForca])) {
                        $potenciaForcaNucleo[$linhaForca]['soma'] += $kva;
                        $potenciaForcaNucleo[$linhaForca]['qtd']++;
                    }
                }
            }

            $analitico[] = [
                'tipo'                 => 'PRODUÇÃO',
                'data_turno'           => $d,
                'data_audit'           => $r['data_hora_audit'] ?: $r['data_mov'],
                'data_mov'             => $r['data_mov'],
                'operador'             => $r['operador_audit'] ?? ($r['data_hora_audit'] ? 'Sistema' : 'PierServer'),
                'area'                 => ($area === 'distrib') ? 'Distribuição' : 'Média Força',
                'area_cod'             => $area,
                'linha'                => $linha,
                'nucleo'               => $nucleoNome,
                'nucleo_cod'           => $nucleoCod,
                'of'                   => $r['num_Docto'],
                'serie'                => $r['NumSerie'] ?? '',
                'referencia'           => $r['cd_Referencia'] ?? '',
                'projeto'              => $r['cd_Referencia'] ?? '',
                'descricao'            => $r['ds_Prod'] ?? '',
                'pedido'               => '—',
                'pedido_cliente'       => '',
                'cliente'              => '—',
                'kva'                  => $kva !== null ? (string)$kva : '',
                'cdEnt'                => $cdEnt,
                'almoxarifado'         => $r['cd_AlmoxEmpresa'] ?? '',
                'motivo_reprova'       => '',
            ];

            if ($ultimaData === null || $d > $ultimaData) {
                $ultimaData = $d;
            }
        }

        foreach ($linhasRep as $r) {
            $d = boletimAjustarDataFimDeSemanaParaSexta($r['data_turno']);
            $almox = (string) $r['cd_AlmoxEmpresa'];
            $area = ($almox === '22') ? 'distrib' : 'forca';
            $reprovasPorDia[$d][$area] = ($reprovasPorDia[$d][$area] ?? 0) + 1;

            $analitico[] = [
                'tipo'                 => 'REPROVA LAB',
                'data_turno'           => $d,
                'data_audit'           => ($r['data_hora_audit'] ?? null) ?: ($r['data_mov'] ?? ''),
                'data_mov'             => $r['data_mov'] ?? '',
                'operador'             => ($r['operador_audit'] ?? null) ?: 'PierServer',
                'area'                 => ($area === 'distrib') ? 'Distribuição' : 'Média Força',
                'area_cod'             => $area,
                'linha'                => 'LAB',
                'nucleo'               => 'Reprova de Laboratório',
                'nucleo_cod'           => 'LAB',
                'of'                   => $r['num_Docto'] ?? '',
                'serie'                => $r['NumSerie'] ?? '',
                'referencia'           => $r['cd_Referencia'] ?? '',
                'projeto'              => $r['cd_Referencia'] ?? '',
                'descricao'            => $r['ds_Prod'] ?? '',
                'pedido'               => '—',
                'pedido_cliente'       => '',
                'cliente'              => '—',
                'kva'                  => '',
                'cdEnt'                => $r['cdEnt'] ?? '',
                'almoxarifado'         => $almox,
                'motivo_reprova'       => 'Reprova / Retrabalho em Ensaios de Laboratório (Almoxarifado ' . $almox . ')',
            ];

            if ($ultimaData === null || $d > $ultimaData) {
                $ultimaData = $d;
            }
        }

        return [
            'porDia'              => $porDia,
            'nucleoPorDia'        => $nucleoPorDia,
            'forcaPorDia'         => $forcaPorDia,
            'potenciaPorDia'      => $potenciaPorDia,
            'potenciaNucleo'      => $potenciaNucleo,
            'potenciaForcaNucleo' => $potenciaForcaNucleo,
            'reprovasPorDia'      => $reprovasPorDia,
            'analitico'           => $analitico,
            'ultimaData'          => $ultimaData,
            'sincronizadoEm'      => date('d/m/Y H:i:s'),
            'fonte'               => 'SQL Server (Tempo Real - Turno Fábrica)',
        ];
    } catch (Throwable $e) {
        error_log('Erro na consulta do SQL Server: ' . $e->getMessage());
        return null;
    }
}

/**
 * Força a reatualização dos dados consultando diretamente o SQL Server.
 */
function boletimKardexForcarAtualizacao(string $mes = ''): bool
{
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = date('Y-m');
    }
    $dados = boletimObterDadosMes($mes, true);
    return !empty($dados);
}

/**
 * Retorna a data/hora da última sincronização do mês.
 */
function boletimKardexUltimaSincronizacao(string $mes = ''): string
{
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = date('Y-m');
    }
    $cacheFile = BOLETIM_KARDEX_CACHE_DIR . '/kardex_mes_' . $mes . '.cache';
    if (is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        $cached = $raw !== false ? @unserialize($raw, ['allowed_classes' => false]) : null;
        if (is_array($cached) && !empty($cached['sincronizado_em'])) {
            return date('d/m/Y H:i', strtotime($cached['sincronizado_em']));
        }
    }
    return date('d/m/Y H:i');
}

/**
 * Contagens de um dia específico — sempre as 4 chaves ['TPM', 'TPS', 'TPD_distrib', 'TPD_forca'].
 */
function boletimKardexDoDia(string $dataYmd): array
{
    $vazio = ['TPM' => 0, 'TPS' => 0, 'TPD_distrib' => 0, 'TPD_forca' => 0];
    if (!preg_match('/^(\d{4}-\d{2})-\d{2}$/', $dataYmd, $m)) {
        return $vazio;
    }
    $mes = $m[1];
    $dados = boletimObterDadosMes($mes);
    return $dados['porDia'][$dataYmd] ?? $vazio;
}

/**
 * Contagem de um núcleo (ENR/JC/EMP) do TPD-Distribuição num dia.
 */
function boletimKardexNucleoDoDia(string $dataYmd, string $nucleo): ?int
{
    if (!preg_match('/^(\d{4}-\d{2})-\d{2}$/', $dataYmd, $m)) {
        return null;
    }
    $mes = $m[1];
    $dados = boletimObterDadosMes($mes);
    return $dados['nucleoPorDia'][$dataYmd][$nucleo] ?? null;
}

/**
 * Potência média (kVA) de um dia/área.
 */
function boletimKardexPotenciaMediaDoDia(string $dataYmd, string $area): ?float
{
    if (!preg_match('/^(\d{4}-\d{2})-\d{2}$/', $dataYmd, $m)) {
        return null;
    }
    $mes = $m[1];
    $dados = boletimObterDadosMes($mes);
    $dia = $dados['potenciaPorDia'][$dataYmd][$area] ?? null;
    if ($dia === null || $dia['qtd'] <= 0) return null;
    return round($dia['soma'] / $dia['qtd'], 2);
}

/**
 * Potência Média de um núcleo no mês (kVA).
 */
function boletimKardexPotenciaMediaNucleo(string $mes, string $nucleo): ?float
{
    $dados = boletimObterDadosMes($mes);
    $n = $dados['potenciaNucleo'][$nucleo] ?? null;
    if ($n === null || ($n['qtd'] ?? 0) <= 0) return null;
    return round($n['soma'] / $n['qtd'], 1);
}

/**
 * Contagem de uma linha de Média Força (TPD, TPS, TPM) num dia.
 */
function boletimKardexForcaLinhaDoDia(string $dataYmd, string $linha): ?int
{
    if (!preg_match('/^(\d{4}-\d{2})-\d{2}$/', $dataYmd, $m)) {
        return null;
    }
    $mes = $m[1];
    $dados = boletimObterDadosMes($mes);
    return $dados['forcaPorDia'][$dataYmd][$linha] ?? null;
}

/**
 * Potência Média de uma linha de Média Força no mês (kVA).
 */
function boletimKardexPotenciaMediaForcaLinha(string $mes, string $linha): ?float
{
    $dados = boletimObterDadosMes($mes);
    $n = $dados['potenciaForcaNucleo'][$linha] ?? null;
    if ($n === null || ($n['qtd'] ?? 0) <= 0) return null;
    return round($n['soma'] / $n['qtd'], 1);
}

/**
 * Reprovas (REP-LAB) de um dia/área.
 */
function boletimKardexReprovasDoDia(string $dataYmd, string $area): ?int
{
    if (!preg_match('/^(\d{4}-\d{2})-\d{2}$/', $dataYmd, $m)) {
        return null;
    }
    $mes = $m[1];
    $dados = boletimObterDadosMes($mes);
    return $dados['reprovasPorDia'][$dataYmd][$area] ?? null;
}

/**
 * Data mais recente presente nos dados do mês.
 */
function boletimKardexUltimaData(string $mes = ''): ?string
{
    $dados = boletimObterDadosMes($mes);
    return $dados['ultimaData'] ?? null;
}

/**
 * Busca todas as linhas analíticas individuais do mês no SQL Server para exportação (CSV).
 */
function boletimBuscarLinhasAnaliticasMes(string $mes, string $filtroArea = 'todas'): array
{
    $pdo = getSqlServerDB();
    if (!$pdo) {
        return [];
    }

    $inicio = $mes . '-01';
    $fim    = date('Y-m-t', strtotime($inicio));
    $dtInicioMes   = $inicio . ' 00:00:00';
    $dtFimMesTurno = date('Y-m-d', strtotime($fim . ' +1 day')) . ' 07:30:00';
    $dtInicioShift = $inicio . ' 07:30:00';

    $linhas = [];

    // 1. Produção com Pedido, Cliente e Projeto vinculados
    $sqlProd = "
        WITH AuditLotes AS (
            SELECT 
                A.Id_pk,
                MAX(A.Data) AS DataHoraAudit,
                MAX(A.Name_User) AS Usuario
            FROM piAudit A WITH(NOLOCK)
            WHERE A.OIDTable = 29708
              AND A.Coluna = 'StatusLote'
              AND A.Data >= ? AND A.Data < ?
            GROUP BY A.Id_pk
        )
        SELECT 
            k.NumSerie,
            k.cd_Referencia,
            k.ds_Prod,
            k.cdEnt,
            k.cd_of,
            k.num_Docto,
            k.cd_AlmoxEmpresa,
            CONVERT(VARCHAR(10), k.dt_Movimento, 120) AS data_mov,
            CONVERT(VARCHAR(19), aud.DataHoraAudit, 120) AS data_hora_audit,
            CONVERT(VARCHAR(10), DATEADD(minute, -450, COALESCE(aud.DataHoraAudit, k.dt_Movimento)), 120) AS data_turno,
            aud.Usuario AS operador_audit,
            f.ds_TpEnrolamentoNucleo,
            f.ds_potencia,
            f.nrofasesTrafo,
            ped_cli.cdPedido,
            ped_cli.PedidoCliente,
            ped_cli.ClienteNome,
            ped_cli.ClienteApelido
        FROM dw.vw_kardex_lotes k
        INNER JOIN ControleLotes C WITH(NOLOCK) ON C.cd_LoteMercEntradaSaida = k.cd_LoteMercEntradaSaida
        LEFT JOIN AuditLotes aud ON aud.Id_pk = C.id_LoteMercEntradaSaida
        OUTER APPLY (
            SELECT TOP 1 ds_TpEnrolamentoNucleo, ds_potencia, nrofasesTrafo
            FROM dw.vw_ficha_espc_trafo f
            WHERE f.cd_Referencia = k.cd_Referencia
        ) f
        OUTER APPLY (
            SELECT TOP 1
                p.cdPedido,
                p.PedidoCliente,
                cli.Nome AS ClienteNome,
                cli.Apelido AS ClienteApelido
            FROM dbo.CtrlNumSerie cns WITH(NOLOCK)
            JOIN dbo.It_Pedido it WITH(NOLOCK) ON it.id_it_pedido = cns.id_it_pedido
            JOIN dbo.Pedidos p WITH(NOLOCK) ON p.id_Ped = it.id_Ped
            JOIN dbo.Entidade cli WITH(NOLOCK) ON cli.Id_Ent = p.id_Cliente
            WHERE cns.NumSerie = k.NumSerie AND k.NumSerie > 0
        ) ped_cli
        WHERE k.num_Docto LIKE 'OF %'
          AND k.Entra_Sai = 'ENT'
          AND (k.cd_Referencia LIKE 'TPD%' OR k.cd_Referencia LIKE 'TPM%' OR k.cd_Referencia LIKE 'TPS%')
          AND (
              (aud.DataHoraAudit IS NOT NULL AND aud.DataHoraAudit >= ? AND aud.DataHoraAudit < ?)
              OR
              (aud.DataHoraAudit IS NULL AND k.dt_Movimento >= ? AND k.dt_Movimento <= ?)
          )
        ORDER BY COALESCE(aud.DataHoraAudit, k.dt_Movimento), k.num_Docto
    ";
    $stmt = $pdo->prepare($sqlProd);
    $stmt->execute([
        $dtInicioMes,
        $dtFimMesTurno,
        $dtInicioShift,
        $dtFimMesTurno,
        $inicio,
        $fim
    ]);
    
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ref     = trim((string) $r['cd_Referencia']);
        $cdEnt   = trim((string) ($r['cdEnt'] ?? ''));

        // Potência kVA
        $prod = (string) ($r['ds_Prod'] ?? '');
        $kvaNum = null;
        $kva = '';
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*kva\b/i', $prod, $m)) {
            $kva = str_replace(',', '.', $m[1]);
            $kvaNum = (float) $kva;
        }

        if ($cdEnt === '1') {
            $area = 'distrib';
            $linha = 'TPD';
            $nuc = boletimClassificarNucleoTrafo($r['ds_TpEnrolamentoNucleo'] ?? null, $r['nrofasesTrafo'] ?? null, $r['ds_Prod'] ?? '');
            $nucleo = match ($nuc) {
                'ENR' => 'ENR (Enrolado)',
                'JC'  => 'JC-TRIF (Jean Cor Trifásico)',
                'EMP' => 'EMP (Convencional)',
                default => 'N/D',
            };
            $nucleoCod = $nuc;
        } else {
            $area = 'forca';
            $linhaForca = boletimClassificarLinhaForca($ref, $kvaNum);
            $linha = $linhaForca;
            $nucleo = match ($linhaForca) {
                'TPS' => 'TPS (Seco)',
                'TPD' => 'TPD (≤ 300 kVA)',
                'TPM' => 'TPM (> 300 kVA)',
                default => $linhaForca,
            };
            $nucleoCod = $linhaForca;
        }

        if ($filtroArea !== 'todas' && $filtroArea !== $area) {
            continue;
        }

        $cliente = trim((string) ($r['ClienteApelido'] ?: $r['ClienteNome'] ?: ''));
        $pedido  = trim((string) ($r['cdPedido'] ?? ''));
        if ($pedido === '' && !empty($r['PedidoCliente'])) {
            $pedido = trim((string) $r['PedidoCliente']);
        }

        $linhas[] = [
            'tipo'                 => 'PRODUÇÃO',
            'data_turno'           => boletimAjustarDataFimDeSemanaParaSexta($r['data_turno']),
            'data_audit'           => $r['data_hora_audit'] ?: $r['data_mov'],
            'data_mov'             => $r['data_mov'],
            'operador'             => $r['operador_audit'] ?: 'PierServer',
            'area'                 => ($area === 'distrib') ? 'Distribuição' : 'Média Força',
            'linha'                => $linha,
            'nucleo'               => $nucleo,
            'nucleo_cod'           => $nucleoCod,
            'of'                   => $r['num_Docto'],
            'serie'                => $r['NumSerie'] ?? '',
            'referencia'           => $ref,
            'projeto'              => $ref,
            'descricao'            => $prod,
            'pedido'               => $pedido ?: '—',
            'pedido_cliente'       => $r['PedidoCliente'] ?? '',
            'cliente'              => $cliente ?: '—',
            'kva'                  => $kva,
            'cdEnt'                => $cdEnt,
            'almoxarifado'         => $r['cd_AlmoxEmpresa'] ?? '',
            'motivo_reprova'       => '',
        ];
    }

    // 2. Reprovas
    $sqlRep = "
        WITH AuditLotesRep AS (
            SELECT 
                A.Id_pk,
                MAX(A.Data) AS DataHoraAudit,
                MAX(A.Name_User) AS Usuario
            FROM piAudit A WITH(NOLOCK)
            WHERE A.OIDTable = 29708
              AND A.Coluna = 'StatusLote'
              AND A.Data >= ? AND A.Data < ?
            GROUP BY A.Id_pk
        )
        SELECT 
            CONVERT(VARCHAR(10), k.dt_Movimento, 120) AS data_mov,
            CONVERT(VARCHAR(19), aud.DataHoraAudit, 120) AS data_hora_audit,
            CONVERT(VARCHAR(10), DATEADD(minute, -450, COALESCE(aud.DataHoraAudit, k.dt_Movimento)), 120) AS data_turno,
            aud.Usuario AS operador_audit,
            k.num_Docto,
            k.NumSerie,
            k.cd_Referencia,
            k.ds_Prod,
            k.cd_AlmoxEmpresa,
            k.cdEnt,
            ped_cli.cdPedido,
            ped_cli.PedidoCliente,
            ped_cli.ClienteNome,
            ped_cli.ClienteApelido
        FROM dw.vw_kardex_lotes k
        INNER JOIN ControleLotes C WITH(NOLOCK) ON C.cd_LoteMercEntradaSaida = k.cd_LoteMercEntradaSaida
        LEFT JOIN AuditLotesRep aud ON aud.Id_pk = C.id_LoteMercEntradaSaida
        OUTER APPLY (
            SELECT TOP 1
                p.cdPedido,
                p.PedidoCliente,
                cli.Nome AS ClienteNome,
                cli.Apelido AS ClienteApelido
            FROM dbo.CtrlNumSerie cns WITH(NOLOCK)
            JOIN dbo.It_Pedido it WITH(NOLOCK) ON it.id_it_pedido = cns.id_it_pedido
            JOIN dbo.Pedidos p WITH(NOLOCK) ON p.id_Ped = it.id_Ped
            WHERE TRY_CAST(cns.NumSerie AS VARCHAR(50)) = TRY_CAST(k.NumSerie AS VARCHAR(50))
              AND k.NumSerie IS NOT NULL AND k.NumSerie <> ''
        ) ped_cli
        WHERE k.Entra_Sai = 'ENT'
          AND k.cd_AlmoxEmpresa IN (22, 422)
          AND (
              (aud.DataHoraAudit IS NOT NULL AND aud.DataHoraAudit >= ? AND aud.DataHoraAudit < ?)
              OR
              (aud.DataHoraAudit IS NULL AND k.dt_Movimento >= ? AND k.dt_Movimento <= ?)
          )
        ORDER BY COALESCE(aud.DataHoraAudit, k.dt_Movimento), k.num_Docto
    ";
    $stmtRep = $pdo->prepare($sqlRep);
    $stmtRep->execute([
        $dtInicioMes,
        $dtFimMesTurno,
        $dtInicioShift,
        $dtFimMesTurno,
        $inicio,
        $fim
    ]);
    
    while ($r = $stmtRep->fetch(PDO::FETCH_ASSOC)) {
        $almox = (string) $r['cd_AlmoxEmpresa'];
        $area  = ($almox === '22') ? 'distrib' : 'forca';

        if ($filtroArea !== 'todas' && $filtroArea !== $area) {
            continue;
        }

        $cliente = trim((string) ($r['ClienteApelido'] ?: $r['ClienteNome'] ?: ''));
        $pedido  = trim((string) ($r['cdPedido'] ?? ''));
        if ($pedido === '' && !empty($r['PedidoCliente'])) {
            $pedido = trim((string) $r['PedidoCliente']);
        }

        $linhas[] = [
            'tipo'                 => 'REPROVA LAB',
            'data_turno'           => boletimAjustarDataFimDeSemanaParaSexta($r['data_turno']),
            'data_audit'           => $r['data_hora_audit'] ?: $r['data_mov'],
            'data_mov'             => $r['data_mov'],
            'operador'             => $r['operador_audit'] ?: 'PierServer',
            'area'                 => ($area === 'distrib') ? 'Distribuição' : 'Média Força',
            'linha'                => 'LAB',
            'nucleo'               => 'Reprova de Laboratório',
            'nucleo_cod'           => 'LAB',
            'of'                   => $r['num_Docto'],
            'serie'                => $r['NumSerie'] ?? '',
            'referencia'           => $r['cd_Referencia'] ?? '',
            'projeto'              => $r['cd_Referencia'] ?? '',
            'descricao'            => $r['ds_Prod'] ?? '',
            'pedido'               => $pedido ?: '—',
            'pedido_cliente'       => $r['PedidoCliente'] ?? '',
            'cliente'              => $cliente ?: '—',
            'kva'                  => '',
            'cdEnt'                => $r['cdEnt'] ?? '',
            'almoxarifado'         => $almox,
            'motivo_reprova'       => 'Reprova / Retrabalho em Ensaios de Laboratório (Almoxarifado ' . $almox . ')',
        ];
    }

    return boletimUtf8Safe($linhas);
}

/**
 * Busca detalhada e filtrada de peças para o Modal interativo.
 * Usa cache em disco para carregar instantaneamente ao navegar ou clicar no gráfico.
 */
function boletimBuscarDetalhesProducao(string $mes, ?string $dataDia = null, ?string $filtroTipo = null, string $area = 'distrib', bool $forcarRefresh = false): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = date('Y-m');
    }

    $dadosMes = boletimObterDadosMes($mes, $forcarRefresh);
    $todas = $dadosMes['analitico'] ?? [];

    if (empty($todas)) {
        $todas = boletimBuscarLinhasAnaliticasMes($mes, 'todas');
    }

    $filtradas = [];
    $filtroArea = ($area === 'forca') ? 'Média Força' : (($area === 'distrib') ? 'Distribuição' : 'todas');
    $tipoUpper = strtoupper(trim((string) $filtroTipo));

    foreach ($todas as $item) {
        // Filtro de Área
        if ($filtroArea !== 'todas' && $item['area'] !== $filtroArea) {
            continue;
        }

        // Filtro de Data do Turno
        if (!empty($dataDia)) {
            // Normalizar dataDia para YYYY-MM-DD
            $dataNormalizada = $dataDia;
            if (preg_match('/^\d{1,2}$/', $dataDia)) {
                $dataNormalizada = sprintf('%s-%02d', $mes, (int) $dataDia);
            }
            if ($item['data_turno'] !== $dataNormalizada) {
                continue;
            }
        }

        // Filtro de Tipo / Núcleo / Linha
        if ($tipoUpper !== '' && $tipoUpper !== 'TODOS' && $tipoUpper !== 'TOTAL' && $tipoUpper !== 'EXECUTADO TOTAL') {
            $nucCod = strtoupper($item['nucleo_cod'] ?? '');
            $linhaCod = strtoupper($item['linha'] ?? '');
            $tipoReg = strtoupper($item['tipo'] ?? '');

            if ($tipoUpper === 'REP' || $tipoUpper === 'LAB' || $tipoUpper === 'REPROVA') {
                if ($item['linha'] !== 'LAB' && !str_contains($tipoReg, 'REPROVA')) {
                    continue;
                }
            } elseif ($tipoUpper === 'ENR') {
                if ($nucCod !== 'ENR') continue;
            } elseif ($tipoUpper === 'JC' || $tipoUpper === 'JC-TRIF') {
                if ($nucCod !== 'JC') continue;
            } elseif ($tipoUpper === 'EMP') {
                if ($nucCod !== 'EMP') continue;
            } elseif ($tipoUpper === 'TPD') {
                if ($linhaCod !== 'TPD' && $nucCod !== 'TPD') continue;
            } elseif ($tipoUpper === 'TPS') {
                if ($linhaCod !== 'TPS' && $nucCod !== 'TPS') continue;
            } elseif ($tipoUpper === 'TPM') {
                if ($linhaCod !== 'TPM' && $nucCod !== 'TPM') continue;
            }
        }

        $filtradas[] = $item;
    }

    // Enriquecimento ultrarrápido em lote de Pedido e Cliente apenas para as peças filtradas
    if (!empty($filtradas)) {
        $seriesMap = [];
        foreach ($filtradas as $idx => $f) {
            $s = trim((string)($f['serie'] ?? ''));
            if ($s !== '' && is_numeric($s)) {
                $seriesMap[$s][] = $idx;
            }
        }

        if (!empty($seriesMap)) {
            $pdo = getSqlServerDB();
            if ($pdo) {
                $numSeries = array_keys($seriesMap);
                $chunks = array_chunk($numSeries, 50);
                foreach ($chunks as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $sqlCli = "
                        SELECT 
                            cns.NumSerie,
                            p.cdPedido,
                            p.PedidoCliente,
                            cli.Nome AS ClienteNome,
                            cli.Apelido AS ClienteApelido
                        FROM dbo.CtrlNumSerie cns WITH(NOLOCK)
                        JOIN dbo.It_Pedido it WITH(NOLOCK) ON it.id_it_pedido = cns.id_it_pedido
                        JOIN dbo.Pedidos p WITH(NOLOCK) ON p.id_Ped = it.id_Ped
                        JOIN dbo.Entidade cli WITH(NOLOCK) ON cli.Id_Ent = p.id_Cliente
                        WHERE cns.NumSerie IN ($placeholders)
                    ";
                    try {
                        $st = $pdo->prepare($sqlCli);
                        $st->execute($chunk);
                        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                            $sKey = (string)$row['NumSerie'];
                            if (isset($seriesMap[$sKey])) {
                                $cliNome = trim((string)($row['ClienteApelido'] ?: $row['ClienteNome'] ?: ''));
                                $pedNum  = trim((string)($row['cdPedido'] ?? ''));
                                if ($pedNum === '' && !empty($row['PedidoCliente'])) {
                                    $pedNum = trim((string)$row['PedidoCliente']);
                                }
                                foreach ($seriesMap[$sKey] as $targetIdx) {
                                    if ($cliNome !== '') {
                                        $filtradas[$targetIdx]['cliente'] = $cliNome;
                                    }
                                    if ($pedNum !== '') {
                                        $filtradas[$targetIdx]['pedido'] = $pedNum;
                                    }
                                    if (!empty($row['PedidoCliente'])) {
                                        $filtradas[$targetIdx]['pedido_cliente'] = (string)$row['PedidoCliente'];
                                    }
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        // Tolerante a falhas
                    }
                }
            }
        }
    }

    return boletimUtf8Safe($filtradas);
}

/**
 * Fallback para o caso extremo onde o SQL Server estiver inacessível.
 */
function boletimFallbackPlanilhaExcel(): array
{
    return [
        'porDia'         => [],
        'nucleoPorDia'   => [],
        'potenciaPorDia' => [],
        'reprovasPorDia' => [],
        'ultimaData'     => null,
        'sincronizadoEm' => date('d/m/Y H:i:s'),
        'fonte'          => 'Sem conexão com o banco',
    ];
}
