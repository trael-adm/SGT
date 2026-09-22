<?php
declare(strict_types=1);

/**
 * Módulo de Análise e Métricas de Atraso da Distribuição — SGT.
 *
 * 100% Baseado em Banco de Dados MySQL (com suporte a sincronização direta com SQL Server / Railway).
 * Implementa as regras de negócio e medidas DAX / Power Query do PowerBI corporativo.
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/boletim-planilha.php';
require_once __DIR__ . '/helpers.php';

/**
 * Garante defensivamente que as tabelas de atraso existam no banco de dados.
 */
function boletimGarantirTabelasAtraso(PDO $pdo): void
{
    // Marcador em disco em vez de `static`: mesmo motivo do fix em config/conexao.php::getDB() —
    // `static` não sobrevive entre requisições (cada request é um processo PHP novo), então sem o
    // marcador esse bloco de CREATE TABLE roda em toda página de Atraso/Produção.
    $marcadorAtraso = __DIR__ . '/../storage/cache/.schema_atraso_verificado';
    if (is_file($marcadorAtraso)) return;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `atraso_distribuicao_registros` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `data_extracao` DATE NOT NULL,
                `data_programada` DATE NOT NULL,
                `cd_referencia` VARCHAR(50) NULL,
                `ds_produto` VARCHAR(255) NULL,
                `qtd_item` INT NOT NULL DEFAULT 1,
                `quantidade` INT NOT NULL DEFAULT 1,
                `qtd_produzida` INT NOT NULL DEFAULT 0,
                `qtd_a_produzir` INT NOT NULL DEFAULT 0,
                `cliente_nome` VARCHAR(255) NULL,
                `cliente_apelido` VARCHAR(100) NULL,
                `cd_pedido` INT NULL,
                `dt_pedido` DATETIME NULL,
                `dt_limite_entrega` DATE NULL,
                `potencia_kva` DECIMAL(10,2) NULL,
                `fases` VARCHAR(20) NULL,
                `classe_tensao` VARCHAR(50) NULL,
                `tipo_nucleo` VARCHAR(50) NULL,
                `tipo_construtivo` VARCHAR(50) NULL,
                `setor_real` VARCHAR(60) NULL,
                `tipo_bloqueio` VARCHAR(20) NULL,
                `linha` VARCHAR(30) NOT NULL,
                `seq_plano` INT NULL,
                `uf` VARCHAR(10) NULL,
                `num_serie` INT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_data_extracao` (`data_extracao`),
                INDEX `idx_data_prog` (`data_programada`),
                INDEX `idx_linha` (`linha`),
                INDEX `idx_extracao_linha` (`data_extracao`, `linha`),
                INDEX `idx_pedido_ref` (`cd_pedido`, `cd_referencia`),
                INDEX `idx_num_serie` (`num_serie`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Colunas e tabelas novas em bancos que já tinham a tabela
        try {
            $temColuna = $pdo->query("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atraso_distribuicao_registros' AND COLUMN_NAME = 'num_serie'
            ")->fetchColumn();
            if ((int) $temColuna === 0) {
                $pdo->exec("ALTER TABLE atraso_distribuicao_registros ADD COLUMN num_serie INT NULL, ADD INDEX idx_num_serie (num_serie)");
            }

            $temColSetores = $pdo->query("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atraso_distribuicao_registros' AND COLUMN_NAME = 'setores_pendentes'
            ")->fetchColumn();
            if ((int) $temColSetores === 0) {
                $pdo->exec("ALTER TABLE atraso_distribuicao_registros ADD COLUMN setores_pendentes VARCHAR(255) NULL AFTER setor_real");
            }

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `atraso_distribuicao_celulas` (
                    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                    `registro_id` BIGINT NOT NULL,
                    `data_extracao` DATE NOT NULL,
                    `celula_sigla` VARCHAR(10) NOT NULL,
                    `celula_nome` VARCHAR(60) NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_extracao_celula` (`data_extracao`, `celula_nome`),
                    INDEX `idx_registro` (`registro_id`),
                    INDEX `idx_extracao_sigla` (`data_extracao`, `celula_sigla`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (\Throwable $e) {
            // Log silencioso
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `atraso_metas` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `mes` VARCHAR(7) NOT NULL,
                `linha` VARCHAR(30) NOT NULL,
                `meta_dias` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_mes_linha` (`mes`, `linha`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Histórico diário compartilhado entre Distribuição ('distribuicao') e
        // Média Força ('media_forca') — snapshot real do dia, gravado a cada
        // sincronização. O gráfico "Média de Dias em Atraso" usa a linha real
        // daqui quando existe; para dias sem snapshot salvo (histórico antes
        // desta tabela existir), reconstrói por estimativa a partir da foto
        // atual de peças em aberto.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `atraso_historico_diario` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `modulo` VARCHAR(30) NOT NULL,
                `data_referencia` DATE NOT NULL,
                `media_geral` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                `dias_mono` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                `dias_conv` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                `dias_jc` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                `pecas_total` INT NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_modulo_data` (`modulo`, `data_referencia`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        if (!is_dir(dirname($marcadorAtraso))) {
            @mkdir(dirname($marcadorAtraso), 0775, true);
        }
        @file_put_contents($marcadorAtraso, date('Y-m-d H:i:s'));
    } catch (\Throwable $e) {
        // Log silencioso
    }
}

/**
 * Classifica a linha de fabricação com base na regra do Power Query M.
 */
function boletimClassificarLinhaAtraso(?string $nucleo, ?string $fases, ?string $descricaoProd): string
{
    $n = strtoupper(trim((string) $nucleo));
    $f = strtoupper(trim((string) $fases));
    $p = strtoupper(trim((string) $descricaoProd));

    if ($n === 'EMP' || $n === 'EMP-LM') {
        return 'Convencional';
    }
    if ($n === 'JC' && ($f === 'TRI' || $f === '3' || $f === '3F' || str_contains($p, '3F'))) {
        return 'JC-TRIF';
    }
    if (($n === 'ENR' || $n === 'JC') && ($f === 'MON' || $f === 'BIF' || $f === '1' || $f === '2' || $f === '1F' || $f === '2F')) {
        return 'Monofásico';
    }
    return 'Outros';
}

/**
 * As células reais de produção da Distribuição rastreáveis via prefixo de sub-OF. Ordem
 * do array = ordem de prioridade em boletimClassificarBloqueioReal() — segue o mesmo
 * fluxo oficial CH/BT/AT/CNC/SOL/MN/PIN/ME/MF/LAB já usado em fluxo-setor.js (`celulas`,
 * decisão de 2026-09-04), não o campo 'delta' (QtdDeltaDiasProgProd de dbo.CelulaProducao,
 * id_Empresa=1 — mantido só como referência histórica do offset em dias, não reflete mais
 * a ordem real). Fonte única pra classificar o gargalo real de uma OF pendente.
 *
 * Caldeiraria (delta -8 em dbo.CelulaProducao; "CH" no fluxo oficial) foi desmembrada em
 * 4 sub-itens pra dar granularidade real ao gráfico de gargalo — confirmado em
 * dbo.Materiais (2026-09-03): MDA = "ARMADURA", MFU = "FUNDO", MTQ = "TANQUE",
 * MTP = "TAMPA". A dupla MTP/MTQ estava trocada aqui e em avaliarStatusCelulaFluxo()
 * (boletim-fluxo-pedidos.php) — rotuladas como "Solda"/"Pintura", que na real são nomes
 * de partes (Tampa/Tanque), não de etapa.
 *
 * Solda, Pintura e Laboratório SÃO etapas reais do fluxo (com delta próprio em
 * dbo.CelulaProducao), mas não têm sub-OF/material dedicado rastreável neste ERP — não
 * participam da detecção de gargalo (ver skip em boletimClassificarBloqueioReal()).
 */
const ATRASO_CELULAS_REAIS_DISTRIB = [
    ['sigla' => 'ARM', 'nome' => 'Chassis',             'delta' => -8], // ignorada na prioridade, ver ATRASO_CELULAS_IGNORADAS_PRIORIDADE_GARGALO
    ['sigla' => 'FUN', 'nome' => 'Laser',               'delta' => -8], // parte de "CH" no fluxo oficial
    ['sigla' => 'BT',  'nome' => 'BT',                  'delta' => -5],
    ['sigla' => 'AT',  'nome' => 'AT',                  'delta' => -4],
    ['sigla' => 'CNC', 'nome' => 'Corte de Núcleo',     'delta' => -6],
    ['sigla' => 'TP',  'nome' => 'Solda',               'delta' => -8], // "SOL" no fluxo oficial
    ['sigla' => 'MN',  'nome' => 'Montagem de Núcleo',  'delta' => -4],
    ['sigla' => 'TQ',  'nome' => 'Pintura',             'delta' => -8], // "PIN" no fluxo oficial
    ['sigla' => 'ME',  'nome' => 'Montagem Elétrica',   'delta' => -3],
    ['sigla' => 'MF',  'nome' => 'Montagem Final',      'delta' => -1],
    ['sigla' => 'LAB', 'nome' => 'Laboratório',         'delta' => 1],  // sem sub-OF própria
    ['sigla' => 'SOL', 'nome' => 'Solda (Bobinagem)',   'delta' => -4], // sem sub-OF própria
    ['sigla' => 'PIN', 'nome' => 'Pintura (Bobinagem)', 'delta' => -2], // sem sub-OF própria
];

/**
 * Siglas de ATRASO_CELULAS_REAIS_DISTRIB sem sub-OF/material dedicado rastreável neste
 * ERP — nunca participam da detecção de gargalo em boletimClassificarBloqueioReal().
 */
const ATRASO_CELULAS_SEM_SUBOF = ['SOL', 'PIN', 'LAB'];

/**
 * Siglas de ATRASO_CELULAS_REAIS_DISTRIB desconsideradas como candidatas a gargalo em
 * boletimClassificarBloqueioReal() — mesmo com sub-OF própria ainda pendente, a OF não é
 * atribuída a elas; a busca segue pra próxima célula do fluxo. Diferente de
 * ATRASO_CELULAS_SEM_SUBOF (que não tem sub-OF rastreável nenhuma): Chassis (ARM) tem
 * sub-OF própria (MDA) e continua avaliada normalmente, só não pode "vencer" a prioridade
 * — decisão de 2026-09-04 porque, sendo a 1ª da Caldeiraria (mesmo delta de Laser/Solda/
 * Pintura), absorvia sozinha o volume das outras três.
 */
const ATRASO_CELULAS_IGNORADAS_PRIORIDADE_GARGALO = ['ARM'];

/**
 * Siglas de ATRASO_CELULAS_REAIS_DISTRIB ocultadas por escolha no gráfico "Gargalo Real
 * por Célula de Produção" (boletimGargaloRealPorCelula() em boletim-painel-producao.php).
 * Rede de segurança pra snapshots antigos: com ARM em
 * ATRASO_CELULAS_IGNORADAS_PRIORIDADE_GARGALO, nenhuma sincronização nova volta a marcar
 * setor_real = 'Chassis', mas linhas já gravadas antes dessa mudança continuam com esse
 * valor até a próxima sincronização.
 */
const ATRASO_CELULAS_OCULTAS_GRAFICO_GARGALO = ['ARM'];

/**
 * Avalia se uma célula real está concluída ('OK') ou pendente ('PEND') a partir dos
 * sub-nós (sub-OFs de componentes) decompostos via dbo.RlcProgramacao. Prefixos
 * confirmados em dbo.Materiais (ds_Prod) em 2026-09-03.
 */
function boletimAvaliarCelulaReal(array $subNos, string $sigla): string
{
    $temComponente = false;
    $todasConcluidas = true;

    foreach ($subNos as $n) {
        $ref = strtoupper(trim((string) ($n['RefSub'] ?? '')));
        $st = strtoupper(trim((string) ($n['StatusSub'] ?? '')));
        $qtdProd = (float) ($n['QtdProdSub'] ?? 0);
        $qtdTot = (float) ($n['QtdSub'] ?? 0);

        $match = match ($sigla) {
            'ARM' => str_starts_with($ref, 'MDA'),
            'FUN' => str_starts_with($ref, 'MFU') || str_starts_with($ref, 'MDA'),
            'TP' => str_starts_with($ref, 'MTP'), // Solda (Tampa)
            'TQ' => str_starts_with($ref, 'MTQ'), // Pintura (Tanque)
            'CNC' => str_starts_with($ref, 'CNC'),
            'BT' => str_starts_with($ref, 'BT-') || str_starts_with($ref, 'BT_') || $ref === 'BT',
            'MN' => str_starts_with($ref, 'MN-') || str_starts_with($ref, 'MNC'),
            'AT' => str_starts_with($ref, 'AT-') || str_starts_with($ref, 'AT_') || $ref === 'AT',
            'ME' => str_starts_with($ref, 'PA-') || str_starts_with($ref, 'PA_') || $ref === 'PA',
            'MF' => str_starts_with($ref, 'MFL'),
            default => false,
        };

        if ($match) {
            $temComponente = true;
            // Uma sub-OF só conta como concluída se produziu tudo, ou se foi encerrada (ENC) tendo produzido algo (> 0)
            $isConcluida = ($qtdTot > 0 && $qtdProd >= $qtdTot) || ($st === 'ENC' && $qtdProd > 0);
            if (!$isConcluida) {
                $todasConcluidas = false;
            }
        }
    }

    if (!$temComponente) {
        return 'NA'; // Célula não existe na árvore deste trafo
    }

    return $todasConcluidas ? 'OK' : 'PEND';
}

/**
 * Classifica todas as células reais pendentes de uma OF-mãe a partir dos sub-nós decompostos:
 * - 'celulas_pendentes': lista de todas as células do chão de fábrica onde há trabalho pendente
 *   (visão multissetorial real — uma OF pode estar pendente em Solda e Laser ao mesmo tempo).
 * - 'setor_real': primeiro setor pendente (para retrocompatibilidade com relatórios unitários).
 * - 'tipo_bloqueio': 'CELULA' ou 'MATERIAL' (Averiguar, caso todas as células reais estejam OK).
 */
function boletimClassificarBloqueioReal(array $subNos): array
{
    if (empty($subNos)) {
        return [
            'setor_real'            => null,
            'tipo_bloqueio'         => null,
            'celulas_pendentes'     => [],
            'setores_pendentes_str' => null,
        ];
    }

    $celulasPendentes = [];

    foreach (ATRASO_CELULAS_REAIS_DISTRIB as $cel) {
        // Ignora células sem sub-OF própria no ERP
        if (in_array($cel['sigla'], ATRASO_CELULAS_SEM_SUBOF, true)) {
            continue;
        }
        // Chassis (ARM) é avaliado junto com Laser (FUN/MFU/MDA)
        if ($cel['sigla'] === 'ARM') {
            continue;
        }

        $st = boletimAvaliarCelulaReal($subNos, $cel['sigla']);
        if ($st === 'PEND') {
            $celulasPendentes[] = [
                'sigla' => $cel['sigla'],
                'nome'  => $cel['nome'],
            ];
        }
    }

    if (empty($celulasPendentes)) {
        return [
            'setor_real'            => 'Averiguar',
            'tipo_bloqueio'         => 'MATERIAL',
            'celulas_pendentes'     => [
                ['sigla' => 'AVE', 'nome' => 'Averiguar']
            ],
            'setores_pendentes_str' => 'Averiguar',
        ];
    }

    $nomesPendentes = array_column($celulasPendentes, 'nome');

    return [
        'setor_real'            => $nomesPendentes[0], // primeiro gargalo para retrocompatibilidade
        'tipo_bloqueio'         => 'CELULA',
        'celulas_pendentes'     => $celulasPendentes,
        'setores_pendentes_str' => implode(', ', $nomesPendentes),
    ];
}

/**
 * Decompõe em UM único lote (sem N+1) as sub-OFs de componentes de todas as OFs-mãe
 * cujo id_ProgProdPCP está em $idsProgProdPCP, via cadeia de 5 níveis de
 * dbo.RlcProgramacao — mesma técnica de carregarPlanilhaProducaoFluxo() em
 * boletim-fluxo-pedidos.php. Retorna array [setor_real, tipo_bloqueio] por OF-mãe.
 */
function boletimClassificarBloqueioRealEmLote(PDO $pdoSrv, array $idsProgProdPCP): array
{
    $idsProgProdPCP = array_values(array_unique(array_filter(array_map('intval', $idsProgProdPCP))));
    if (empty($idsProgProdPCP)) {
        return [];
    }

    $idsLista = implode(',', $idsProgProdPCP);
    $sqlSubLote = "
        SELECT DISTINCT
            r1.id_ProgProdPCP AS id_Raiz,
            m_sub.cd_Referencia AS RefSub,
            ofp_sub.StatusOF AS StatusSub,
            ofp_sub.Quantidade AS QtdSub,
            ofp_sub.QtdProduzida AS QtdProdSub
        FROM dbo.RlcProgramacao r1 WITH(NOLOCK)
        LEFT JOIN dbo.RlcProgramacao r2 WITH(NOLOCK) ON r1.IDProgProdPCPAnt = r2.id_ProgProdPCP AND r2.PierSitReg = 'ATV'
        LEFT JOIN dbo.RlcProgramacao r3 WITH(NOLOCK) ON r2.IDProgProdPCPAnt = r3.id_ProgProdPCP AND r3.PierSitReg = 'ATV'
        LEFT JOIN dbo.RlcProgramacao r4 WITH(NOLOCK) ON r3.IDProgProdPCPAnt = r4.id_ProgProdPCP AND r4.PierSitReg = 'ATV'
        LEFT JOIN dbo.RlcProgramacao r5 WITH(NOLOCK) ON r4.IDProgProdPCPAnt = r5.id_ProgProdPCP AND r5.PierSitReg = 'ATV'
        CROSS APPLY (
            SELECT r1.IDProgProdPCPAnt AS id_Filho
            UNION SELECT r2.IDProgProdPCPAnt WHERE r2.IDProgProdPCPAnt IS NOT NULL
            UNION SELECT r3.IDProgProdPCPAnt WHERE r3.IDProgProdPCPAnt IS NOT NULL
            UNION SELECT r4.IDProgProdPCPAnt WHERE r4.IDProgProdPCPAnt IS NOT NULL
            UNION SELECT r5.IDProgProdPCPAnt WHERE r5.IDProgProdPCPAnt IS NOT NULL
        ) AS sub
        JOIN dbo.ProgramacaoProducao pp WITH(NOLOCK) ON sub.id_Filho = pp.id_ProgProdPCP
        JOIN dbo.Materiais m_sub WITH(NOLOCK) ON pp.id_Produto = m_sub.id_Produto
        LEFT JOIN dbo.OrdemFabricacao ofp_sub WITH(NOLOCK) ON pp.id_of = ofp_sub.id_of
        WHERE r1.PierSitReg = 'ATV'
          AND r1.id_ProgProdPCP IN ($idsLista)
          AND (
            m_sub.cd_Referencia LIKE 'MDA%' OR m_sub.cd_Referencia LIKE 'MFU%' OR
            m_sub.cd_Referencia LIKE 'BT%'  OR m_sub.cd_Referencia LIKE 'AT%'  OR
            m_sub.cd_Referencia LIKE 'CNC%' OR m_sub.cd_Referencia LIKE 'MTP%' OR
            m_sub.cd_Referencia LIKE 'MN%'  OR m_sub.cd_Referencia LIKE 'MNC%' OR
            m_sub.cd_Referencia LIKE 'MTQ%' OR m_sub.cd_Referencia LIKE 'PA%'  OR
            m_sub.cd_Referencia LIKE 'ME%'  OR m_sub.cd_Referencia LIKE 'MFL%' OR
            m_sub.cd_Referencia LIKE 'LAB%'
          )
    ";

    $subNosPorProgId = [];
    foreach ($pdoSrv->query($sqlSubLote)->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $subNosPorProgId[(int) $s['id_Raiz']][] = $s;
    }

    $resultado = [];
    foreach ($idsProgProdPCP as $idProg) {
        $resultado[$idProg] = boletimClassificarBloqueioReal($subNosPorProgId[$idProg] ?? []);
    }
    return $resultado;
}

/**
 * Retorna a lista de datas de sincronização disponíveis no Banco de Dados.
 */
function boletimListarDatasExtracaoAtraso(): array
{
    $pdo = getDB();
    boletimGarantirTabelasAtraso($pdo);

    $datas = [];
    try {
        $stmt = $pdo->query("
            SELECT data_extracao, COUNT(*) AS total_registros 
            FROM atraso_distribuicao_registros 
            GROUP BY data_extracao 
            ORDER BY data_extracao DESC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $dataIso = $row['data_extracao'];
            $datas[$dataIso] = [
                'data'            => $dataIso,
                'total_registros' => (int) $row['total_registros'],
                'label'           => date('d/m/Y', strtotime($dataIso)) . ' (' . number_format((int) $row['total_registros'], 0, ',', '.') . ' ordens)'
            ];
        }
    } catch (\Throwable $e) {}

    // Fallback: Se o banco estiver zerado (primeira execução), tenta importar dos arquivos de snapshot
    if (empty($datas)) {
        boletimAutoImportarSnapshotsLegados($pdo);
        try {
            $stmt = $pdo->query("
                SELECT data_extracao, COUNT(*) AS total_registros 
                FROM atraso_distribuicao_registros 
                GROUP BY data_extracao 
                ORDER BY data_extracao DESC
            ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $dataIso = $row['data_extracao'];
                $datas[$dataIso] = [
                    'data'            => $dataIso,
                    'total_registros' => (int) $row['total_registros'],
                    'label'           => date('d/m/Y', strtotime($dataIso)) . ' (' . number_format((int) $row['total_registros'], 0, ',', '.') . ' ordens)'
                ];
            }
        } catch (\Throwable $e) {}
    }

    return $datas;
}

/**
 * Importa arquivos legados se o banco estiver vazio.
 */
function boletimAutoImportarSnapshotsLegados(PDO $pdo): void
{
    $files = glob(__DIR__ . '/../storage/snapshots/snapshot_*.csv') ?: [];
    if (empty($files)) {
        $files = glob(__DIR__ . '/../PLANILHA QUE ATUALIZA/snapshot_*.csv') ?: [];
    }
    if (empty($files)) return;

    $stmtInsert = $pdo->prepare("
        INSERT INTO atraso_distribuicao_registros (
            data_extracao, data_programada, cd_referencia, ds_produto, qtd_item,
            quantidade, qtd_produzida, qtd_a_produzir, cliente_nome, cliente_apelido,
            cd_pedido, dt_pedido, dt_limite_entrega, potencia_kva, fases,
            classe_tensao, tipo_nucleo, tipo_construtivo, linha, seq_plano, uf
        ) VALUES (
            :data_extracao, :data_programada, :cd_referencia, :ds_produto, :qtd_item,
            :quantidade, :qtd_produzida, :qtd_a_produzir, :cliente_nome, :cliente_apelido,
            :cd_pedido, :dt_pedido, :dt_limite_entrega, :potencia_kva, :fases,
            :classe_tensao, :tipo_nucleo, :tipo_construtivo, :linha, :seq_plano, :uf
        )
    ");

    foreach ($files as $file) {
        if (!preg_match('/snapshot_(\d{4}-\d{2}-\d{2})\.csv$/', basename($file), $m)) continue;
        $dataExtracao = $m[1];

        $fp = @fopen($file, 'r');
        if (!$fp) continue;
        $firstLine = fgets($fp);
        $delim = strpos($firstLine, ';') !== false ? ';' : ',';
        rewind($fp);
        $header = fgetcsv($fp, 0, $delim, '"', '\\');
        if (!$header) { fclose($fp); continue; }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $header = array_map('trim', $header);

        $pdo->beginTransaction();
        while (($row = fgetcsv($fp, 0, $delim, '"', '\\')) !== false) {
            if (count($row) !== count($header)) continue;
            $r = array_combine($header, $row);

            $dtProgRaw = trim((string)($r['DataHoraProducaoAux'] ?? ''));
            $dtProg = substr($dtProgRaw, 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dtProg)) $dtProg = $dataExtracao;

            $qtd = (int)($r['Quantidade'] ?? 1);
            if ($qtd <= 0) $qtd = (int)($r['QtdAproduzir'] ?? 1);
            if ($qtd <= 0) $qtd = 1;

            $nuc  = trim((string)($r['ds_TpEnrolamentoNucleo'] ?? ''));
            $fase = trim((string)($r['nrofasesTrafo'] ?? ''));
            $prod = trim((string)($r['ds_Prod'] ?? ''));
            $linha = boletimClassificarLinhaAtraso($nuc, $fase, $prod);

            $kva = (float)str_replace(',', '.', (string)($r['PotenciaKVA'] ?? 0));
            $seq = (int)($r['SeqPlano'] ?? 0);

            $dtPedido = trim((string)($r['dt_Pedido'] ?? '')) ?: null;
            if ($dtPedido && !strtotime($dtPedido)) $dtPedido = null;
            $dtLimite = trim((string)($r['dt_LimiteEntrega'] ?? '')) ?: null;
            if ($dtLimite && !strtotime($dtLimite)) $dtLimite = null;

            $stmtInsert->execute([
                'data_extracao'     => $dataExtracao,
                'data_programada'   => $dtProg,
                'cd_referencia'     => trim((string)($r['cd_Referencia'] ?? '')),
                'ds_produto'        => $prod,
                'qtd_item'          => (int)($r['qtdItem'] ?? 1),
                'quantidade'        => $qtd,
                'qtd_produzida'     => (int)($r['QtdProduzida'] ?? 0),
                'qtd_a_produzir'    => (int)($r['QtdAproduzir'] ?? 0),
                'cliente_nome'      => trim((string)($r['Nome'] ?? '')),
                'cliente_apelido'   => trim((string)($r['Apelido'] ?? '')),
                'cd_pedido'         => (int)($r['cdPedido'] ?? 0) ?: null,
                'dt_pedido'         => $dtPedido,
                'dt_limite_entrega' => $dtLimite,
                'potencia_kva'      => $kva,
                'fases'             => $fase,
                'classe_tensao'     => trim((string)($r['ds_classeTensaoTrafo'] ?? '')),
                'tipo_nucleo'       => $nuc,
                'tipo_construtivo'  => trim((string)($r['Ds_tpConstrTrafo'] ?? '')),
                'linha'             => $linha,
                'seq_plano'         => $seq,
                'uf'                => trim((string)($r['cd_SglEstado'] ?? ''))
            ]);
        }
        fclose($fp);
        $pdo->commit();
    }
}

/**
 * Extrai diretamente do SQL Server corporativo (vsat.trael.local) e grava no MySQL.
 * Sem $forcarSobrescrita, se a foto de hoje já existir no banco, mantém congelada/travada
 * sem sobrescrever (comportamento do sincronizador antigo para o Railway).
 * Com $forcarSobrescrita (scripts/atualizar_atraso.php, a cada 15 min), a foto de hoje é
 * substituída no lugar — dias anteriores nunca são tocados, então a última atualização de
 * cada dia fica como o fechamento dele.
 */
function boletimSincronizarAtrasoSqlServer(?string $dataExtracao = null, bool $forcarSobrescrita = false): array
{
    $inicioSync = microtime(true);
    $dataHoje = $dataExtracao ?: date('Y-m-d');
    $pdoLocal = getDB();
    boletimGarantirTabelasAtraso($pdoLocal);

    // Se já existe foto travada para o dia de hoje e não foi forçada a sobrescrita, mantém congelada!
    if (!$forcarSobrescrita) {
        $stmtExiste = $pdoLocal->prepare("SELECT COUNT(*) FROM atraso_distribuicao_registros WHERE data_extracao = ?");
        $stmtExiste->execute([$dataHoje]);
        $qtdExistente = (int) $stmtExiste->fetchColumn();
        if ($qtdExistente > 0) {
            return [
                'sucesso' => true,
                'total_importado' => $qtdExistente,
                'data_extracao' => $dataHoje,
                'congelado' => true,
                'mensagem' => "Foto de $dataHoje já está congelada no banco ($qtdExistente ordens)."
            ];
        }
    }

    $pdoSrv = getSqlServerDB();
    if (!$pdoSrv) {
        return ['sucesso' => false, 'erro' => 'Não foi possível conectar ao SQL Server local (vsat.trael.local).'];
    }

    // Uma linha por Número de Série (cns.NumSerie), sem agrupar por lote — cada
    // id_ProgProdPCP/OF já corresponde 1:1 a um NS físico (confirmado: Quantidade=1 e
    // QtdProduzida binário em cada linha de ProgramacaoProducao/OrdemFabricacao), então o
    // agrupamento por lote que existia aqui era só uma escolha de exibição da extração, não
    // refletia nenhum agrupamento real do ERP. Some NS que estejam em estágios diferentes
    // do mesmo "lote" (mesma referência/pedido/prazo) agora saem em linhas — e portanto
    // células de gargalo — separadas, igual ao Painel por Setor (carregarPlanilhaProducaoFluxo
    // em boletim-fluxo-pedidos.php), que já é por NS. Exige cns.NumSerie > 0 pelo mesmo
    // motivo que lá: só itens já serializados.
    $sql = "
    SELECT cns.NumSerie AS NumSerie
    , CAST(PP.Quantidade AS INT) AS Quantidade
    , PP.id_ProgProdPCP AS IdProgProdPCP
    , PP.DATAHORAPRODUCAOAUX AS DataHoraProducaoAux
    , M.cd_Referencia AS cd_Referencia
    , M.ds_Prod AS ds_Prod
    , CAST(IPE.qtdItem AS INT) AS qtdItem
    , IPE.dt_LimiteEntrega AS dt_LimiteEntrega
    , Cli.cdEnt AS cdEnt
    , Cli.Nome AS Nome
    , Cli.Apelido AS Apelido
    , Ped.dt_Pedido AS dt_Pedido
    , Ped.cdPedido AS cdPedido
    , PT.ds_potencia AS ds_potencia
    , PT.PotenciaKVA AS PotenciaKVA
    , ET.nrofasesTrafo AS nrofasesTrafo
    , CTT.ds_classeTensaoTrafo AS ds_classeTensaoTrafo
    , PCP.dt_criacao AS dt_criacao
    , PCP.NroRevisao AS NroRevisao
    , PCP.dt_Revisao AS dt_Revisao
    , TP.ds_TensaoTrafo AS ds_TensaoTrafo
    , TS.ds_TensaoTrafo AS ds_TensaoTrafoSec
    , TT.ds_TapsTrafo AS ds_TapsTrafo
    , UF.cd_SglEstado AS cd_SglEstado
    , TEN.ds_TpEnrolamentoNucleo AS ds_TpEnrolamentoNucleo
    , TCT.Ds_tpConstrTrafo AS Ds_tpConstrTrafo
    , TEN.cd_TpEnrolamentoNucleo AS cd_TpEnrolamentoNucleo
    , TensoesTrafoDespacho.ds_TensaoTrafo AS ds_TensaoTrafoDesp
    , CAST(ORDF.QtdProduzida AS INT) AS QtdProduzida
    , EmpDestino.cdEnt AS cdEntEmpDesti
    , CtrlItemPedidoPCP.SeqPlano AS SeqPlano
    , CAST(PP.Quantidade - ORDF.QtdProduzida AS INT) AS QtdAproduzir
     FROM CtrlNumSerie AS cns WITH(NOLOCK)
    INNER JOIN ProgramacaoProducao AS PP WITH(NOLOCK) ON (PP.id_ProgProdPCP = cns.id_ProgProdPCP)
    INNER JOIN Materiais AS M WITH(NOLOCK) ON (M.id_Produto = PP.id_Produto)
    INNER JOIN SubGrupoProduto AS SG WITH(NOLOCK) ON (SG.id_SubGrupoPrd = M.id_SubGrupoPrd)
    INNER JOIN GrupoProduto AS G WITH(NOLOCK) ON (G.id_grpProd = SG.id_grpProd)
    INNER JOIN CatGrupo AS CG WITH(NOLOCK) ON (CG.id_catGrupo = G.id_catGrupo)
    LEFT JOIN RlcProgramacaoItPedido AS RPP WITH(NOLOCK) ON (RPP.id_ProgProdPCP = PP.id_ProgProdPCP AND RPP.PierSitReg = 'ATV')
    LEFT JOIN It_Pedido AS IPE WITH(NOLOCK) ON (IPE.id_it_pedido = RPP.id_it_pedido AND IPE.PierSitReg = 'ATV')
    LEFT JOIN Pedidos AS Ped WITH(NOLOCK) ON (Ped.id_Ped = IPE.id_Ped)
    LEFT JOIN Entidade AS Cli WITH(NOLOCK) ON (Cli.Id_Ent = Ped.id_Cliente)
    LEFT JOIN EspecTrafo AS ET WITH(NOLOCK) ON (ET.id_Produto = M.id_Produto)
    LEFT JOIN Potencia AS PT WITH(NOLOCK) ON (PT.id_potencia = ET.id_potencia)
    LEFT JOIN ClasseTensaoTrafo AS CTT WITH(NOLOCK) ON (CTT.id_classeTensaoTrafo = ET.id_classeTensaoTrafo)
    LEFT JOIN TensoesTrafo AS TP WITH(NOLOCK) ON (TP.id_TensaoTrafo = ET.id_TensaoTrafo)
    LEFT JOIN TensoesTrafo AS TS WITH(NOLOCK) ON (TS.id_TensaoTrafo = ET.id_tensaoTrafoSec)
    LEFT JOIN TapsTrafo AS TT WITH(NOLOCK) ON (TT.id_TapsTrafo = ET.id_TapsTrafo)
    INNER JOIN ControleProjetoPCP AS PCP WITH(NOLOCK) ON (PCP.id_Produto = M.id_Produto AND PCP.PierSitReg = 'ATV')
    LEFT JOIN Enderecos_entid AS EE WITH(NOLOCK) ON (EE.Id_End = Ped.IDEndEntrega)
    LEFT JOIN Unid_Federacao AS UF WITH(NOLOCK) ON (UF.id_SglEstado = EE.id_SglEstado)
    LEFT JOIN TipoEnrolamentoNucleo AS TEN WITH(NOLOCK) ON (TEN.id_TpEnrolamentoNucleo = ET.id_TpEnrolamentoNucleo)
    LEFT JOIN TipoConstrutivoTrafo AS TCT WITH(NOLOCK) ON (TCT.id_tpConstrTrafo = ET.id_tpConstrTrafo)
    LEFT JOIN OrdemFabricacao AS ORDF WITH(NOLOCK) ON (ORDF.id_of = PP.id_of AND ORDF.PierSitReg = 'ATV')
    LEFT JOIN TensoesTrafo AS TensoesTrafoDespacho WITH(NOLOCK) ON (TensoesTrafoDespacho.id_TensaoTrafo = ET.id_tensaoDespacho)
    LEFT JOIN Entidade AS EmpDestino WITH(NOLOCK) ON (EmpDestino.Id_Ent = PP.id_Empresa AND EmpDestino.PierSitReg = 'ATV')
    LEFT JOIN RlcCtrlItemPedidoPCPProgProd AS RlcCtrlItemPedidoPCPProgProd WITH(NOLOCK) ON (RlcCtrlItemPedidoPCPProgProd.id_ProgProdPCP = PP.id_ProgProdPCP AND RlcCtrlItemPedidoPCPProgProd.PierSitReg = 'ATV')
    LEFT JOIN CtrlItemPedidoPCP AS CtrlItemPedidoPCP WITH(NOLOCK) ON (CtrlItemPedidoPCP.IDCtrlItPedidoPCP = RlcCtrlItemPedidoPCPProgProd.IDCtrlItPedidoPCP AND CtrlItemPedidoPCP.PierSitReg = 'ATV')
    WHERE cns.NumSerie > 0
     AND (((PP.id_of > 0)) OR (PP.id_of = 0))
     AND (PP.PierSitReg = 'ATV')
     AND (CG.cd_CatGrupo = 40)
     AND (PP.DataHoraProducaoAux BETWEEN dateadd(month,-12, getdate()) AND DATEADD(day, -1, GETDATE()) )
     AND (PCP.statusProjeto = 'PRD' OR (PCP.statusProjeto = 'DES' AND NOT EXISTS (SELECT 1 FROM ControleProjetoPCP AS PCP2 WHERE PCP2.id_Produto = M.id_Produto AND PCP2.PierSitReg = 'ATV' AND PCP2.statusProjeto = 'PRD')))
     AND EmpDestino.cdEnt = '1'
     AND ORDF.StatusOF IN ('AGU', 'RES')
     AND (CAST(PP.Quantidade - ORDF.QtdProduzida AS INT) > 0)
    ORDER BY PP.DataHoraProducaoAux
    ";

    try {
        $stmt = $pdoSrv->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $tempoErp = microtime(true) - $inicioSync;

        // Classificação do gargalo REAL de cada linha pendente (célula de produção ainda
        // travada, ou só material/compra faltando) — um único lote de consulta, sem N+1.
        // IdProgProdPCP acima é MIN() da amostra (a linha pode agrupar várias unidades
        // idênticas do mesmo lote/data/cliente) — agora IdProgProdPCP é o do próprio NS (uma
        // linha por Número de Série, sem aproximação de lote). Se falhar (ex.: instabilidade
        // do SQL Server), segue sem classificar (setor_real/tipo_bloqueio ficam nulos).
        $bloqueioPorProgId = [];
        $classificacaoOk = true;
        try {
            $bloqueioPorProgId = boletimClassificarBloqueioRealEmLote(
                $pdoSrv,
                array_column($rows, 'IdProgProdPCP')
            );
        } catch (\Throwable $eBloqueio) {
            // Sem classificação, setor_real/tipo_bloqueio ficam nulos nesta sincronização.
            $classificacaoOk = false;
        }
        $tempoGargalo = microtime(true) - $inicioSync - $tempoErp;

        $pdoLocal = getDB();
        boletimGarantirTabelasAtraso($pdoLocal);

        // Atualização automática (forcarSobrescrita): nunca troca uma foto boa do dia por uma
        // extração vazia ou sem a classificação de gargalo (instabilidade momentânea do SQL Server).
        if ($forcarSobrescrita) {
            $stmtAtual = $pdoLocal->prepare("SELECT COUNT(*) FROM atraso_distribuicao_registros WHERE data_extracao = ?");
            $stmtAtual->execute([$dataHoje]);
            $qtdAtual = (int) $stmtAtual->fetchColumn();
            if ($qtdAtual > 0 && (empty($rows) || !$classificacaoOk)) {
                return [
                    'sucesso'   => false,
                    'preservado' => true,
                    'erro'      => empty($rows)
                        ? "Extração veio vazia; foto de $dataHoje ($qtdAtual ordens) mantida."
                        : "Falha ao classificar o gargalo; foto de $dataHoje ($qtdAtual ordens) mantida.",
                ];
            }
        }

        // DELETE + INSERT na mesma transação: com atualização frequente, a tela nunca abre
        // no meio da troca e vê a foto do dia vazia.
        $pdoLocal->beginTransaction();
        $pdoLocal->prepare("DELETE FROM atraso_distribuicao_celulas WHERE data_extracao = ?")->execute([$dataHoje]);
        $pdoLocal->prepare("DELETE FROM atraso_distribuicao_registros WHERE data_extracao = ?")->execute([$dataHoje]);

        $stmtIns = $pdoLocal->prepare("
            INSERT INTO atraso_distribuicao_registros (
                data_extracao, data_programada, cd_referencia, ds_produto, qtd_item,
                quantidade, qtd_produzida, qtd_a_produzir, cliente_nome, cliente_apelido,
                cd_pedido, dt_pedido, dt_limite_entrega, potencia_kva, fases,
                classe_tensao, tipo_nucleo, tipo_construtivo, setor_real, setores_pendentes, tipo_bloqueio,
                linha, seq_plano, uf, num_serie
            ) VALUES (
                :data_extracao, :data_programada, :cd_referencia, :ds_produto, :qtd_item,
                :quantidade, :qtd_produzida, :qtd_a_produzir, :cliente_nome, :cliente_apelido,
                :cd_pedido, :dt_pedido, :dt_limite_entrega, :potencia_kva, :fases,
                :classe_tensao, :tipo_nucleo, :tipo_construtivo, :setor_real, :setores_pendentes, :tipo_bloqueio,
                :linha, :seq_plano, :uf, :num_serie
            )
        ");

        $stmtInsCel = $pdoLocal->prepare("
            INSERT INTO atraso_distribuicao_celulas (
                registro_id, data_extracao, celula_sigla, celula_nome
            ) VALUES (
                :registro_id, :data_extracao, :celula_sigla, :celula_nome
            )
        ");

        $toUtf8 = function(?string $str): string {
            if ($str === null) return '';
            if (!mb_check_encoding($str, 'UTF-8')) {
                return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
            }
            return $str;
        };

        $count = 0;
        foreach ($rows as $r) {
            $dtProgRaw = trim($toUtf8((string)($r['DataHoraProducaoAux'] ?? '')));
            $dtProg = substr($dtProgRaw, 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dtProg)) $dtProg = $dataHoje;

            $nuc  = trim($toUtf8((string)($r['ds_TpEnrolamentoNucleo'] ?? '')));
            $fase = trim($toUtf8((string)($r['nrofasesTrafo'] ?? '')));
            $prod = trim($toUtf8((string)($r['ds_Prod'] ?? '')));
            $linha = boletimClassificarLinhaAtraso($nuc, $fase, $prod);

            $dtPedido = trim($toUtf8((string)($r['dt_Pedido'] ?? ''))) ?: null;
            if ($dtPedido && !strtotime($dtPedido)) $dtPedido = null;
            $dtLimite = trim($toUtf8((string)($r['dt_LimiteEntrega'] ?? ''))) ?: null;
            if ($dtLimite && !strtotime($dtLimite)) $dtLimite = null;

            $bloqueio = $bloqueioPorProgId[(int)($r['IdProgProdPCP'] ?? 0)] ?? [
                'setor_real'            => null,
                'tipo_bloqueio'         => null,
                'celulas_pendentes'     => [],
                'setores_pendentes_str' => null,
            ];

            $stmtIns->execute([
                'data_extracao'     => $dataHoje,
                'data_programada'   => $dtProg,
                'cd_referencia'     => trim($toUtf8((string)($r['cd_Referencia'] ?? ''))),
                'ds_produto'        => $prod,
                'qtd_item'          => (int)($r['qtdItem'] ?? 1),
                'quantidade'        => (int)($r['QtdAproduzir'] ?? $r['Quantidade'] ?? 1),
                'qtd_produzida'     => (int)($r['QtdProduzida'] ?? 0),
                'qtd_a_produzir'    => (int)($r['QtdAproduzir'] ?? 0),
                'cliente_nome'      => trim($toUtf8((string)($r['Nome'] ?? ''))),
                'cliente_apelido'   => trim($toUtf8((string)($r['Apelido'] ?? ''))),
                'cd_pedido'         => (int)($r['cdPedido'] ?? 0) ?: null,
                'dt_pedido'         => $dtPedido,
                'dt_limite_entrega' => $dtLimite,
                'potencia_kva'      => (float)($r['PotenciaKVA'] ?? 0),
                'fases'             => $fase,
                'classe_tensao'     => trim($toUtf8((string)($r['ds_classeTensaoTrafo'] ?? ''))),
                'tipo_nucleo'       => $nuc,
                'tipo_construtivo'  => trim($toUtf8((string)($r['Ds_tpConstrTrafo'] ?? ''))),
                'setor_real'        => $bloqueio['setor_real'],
                'setores_pendentes' => $bloqueio['setores_pendentes_str'] ?? $bloqueio['setor_real'],
                'tipo_bloqueio'     => $bloqueio['tipo_bloqueio'],
                'linha'             => $linha,
                'seq_plano'         => (int)($r['SeqPlano'] ?? 0),
                'uf'                => trim($toUtf8((string)($r['cd_SglEstado'] ?? ''))),
                'num_serie'         => (int)($r['NumSerie'] ?? 0) ?: null,
            ]);

            $registroId = (int) $pdoLocal->lastInsertId();
            if (!empty($bloqueio['celulas_pendentes'])) {
                foreach ($bloqueio['celulas_pendentes'] as $c) {
                    $stmtInsCel->execute([
                        'registro_id'   => $registroId,
                        'data_extracao' => $dataHoje,
                        'celula_sigla'  => $c['sigla'],
                        'celula_nome'   => $c['nome'],
                    ]);
                }
            }

            $count++;
        }
        $pdoLocal->commit();

        return [
            'sucesso' => true,
            'total_importado' => $count,
            'data_extracao' => $dataHoje,
            'classificacao_ok' => $classificacaoOk,
            'duracao_s' => round(microtime(true) - $inicioSync, 1),
            'tempos_s' => [
                'erp'      => round($tempoErp, 1),
                'gargalo'  => round($tempoGargalo, 1),
                'gravacao' => round(microtime(true) - $inicioSync - $tempoErp - $tempoGargalo, 1),
            ],
        ];
    } catch (\Throwable $e) {
        if (isset($pdoLocal) && $pdoLocal->inTransaction()) {
            $pdoLocal->rollBack();
        }
        return ['sucesso' => false, 'erro' => 'Erro ao extrair do SQL Server: ' . $e->getMessage()];
    }
}

/**
 * Quando a foto de atraso da Distribuição foi gravada pela última vez (created_at das linhas
 * da extração). Base do "Atualizado em HH:MM" da tela e do alerta de dados desatualizados.
 *
 * @return array{data_extracao:string, atualizado_em:string, idade_s:int, ultima:bool}|null
 */
function boletimUltimaAtualizacaoAtraso(?string $dataExtracao = null): ?array
{
    try {
        $pdo = getDB();
        $maxData = (string) $pdo->query("SELECT MAX(data_extracao) FROM atraso_distribuicao_registros")->fetchColumn();
        if ($dataExtracao === null || $dataExtracao === '') {
            $dataExtracao = $maxData;
        }
        if ($dataExtracao === '') {
            return null;
        }
        $stmt = $pdo->prepare("SELECT MAX(created_at) FROM atraso_distribuicao_registros WHERE data_extracao = ?");
        $stmt->execute([$dataExtracao]);
        $quando = (string) $stmt->fetchColumn();
        if ($quando === '') {
            return null;
        }
        return [
            'data_extracao' => $dataExtracao,
            'atualizado_em' => $quando,
            'idade_s'       => max(0, time() - (int) strtotime($quando)),
            'ultima'        => $dataExtracao === $maxData,
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Intervalo (s) da tarefa agendada "SGT - Atualizar Atraso" (scripts/atualizar_atraso.php).
 * Precisa ser igual ao gatilho no Agendador de Tarefas: é a base do contador da tela.
 */
const ATRASO_INTERVALO_ATUALIZACAO_S = 900;

/**
 * Segundos além do horário esperado até o contador acusar "atualização atrasada" (uma
 * execução normal leva ~30 s; a 1ª a frio chegou a ~110 s).
 */
const ATRASO_TOLERANCIA_ATUALIZACAO_S = 300;

/**
 * Contador "Próxima atualização em mm:ss m" sob a data de referência das telas de atraso
 * (Distribuição e Média Força). O servidor manda o tempo restante e assets/js/atraso-atualizacao.js
 * faz a contagem: ao zerar mostra "Atualizando…", consulta api/atraso-atualizacao.php e recarrega a
 * tela quando a foto nova chega. Se passar de ATRASO_TOLERANCIA_ATUALIZACAO_S sem foto nova (tarefa
 * agendada parada), vira o alerta vermelho "Atualização atrasada". Ao abrir uma foto antiga escolhida
 * de propósito, mostra só quando ela foi gravada, sem contador.
 *
 * @param array{data_extracao:string, atualizado_em:string, idade_s:int, ultima:bool}|null $atualizacao
 * @param string $modulo 'distribuicao' ou 'forca' (qual foto a API consulta)
 */
function boletimHtmlAtualizacaoAtraso(?array $atualizacao, string $modulo = 'distribuicao'): string
{
    if ($atualizacao === null) {
        return '';
    }

    $quandoFmt = date('d/m H:i', strtotime($atualizacao['atualizado_em']));

    if (!$atualizacao['ultima']) {
        return '<div class="mt-1 text-[0.68rem] font-semibold text-[#64748b]">Foto gravada em '
            . htmlspecialchars($quandoFmt, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    // Estado inicial idêntico ao que o JS calcula a cada segundo (sem "piscar" ao carregar a página).
    $restante = ATRASO_INTERVALO_ATUALIZACAO_S - $atualizacao['idade_s'];
    if ($restante > 0) {
        $texto = sprintf('Próxima atualização em %02d:%02d m', intdiv($restante, 60), $restante % 60);
        $cor = '#64748b';
    } elseif (-$restante <= ATRASO_TOLERANCIA_ATUALIZACAO_S) {
        $texto = 'Atualizando…';
        $cor = '#fbbf24';
    } else {
        $texto = "⚠ Atualização atrasada — última em {$quandoFmt}";
        $cor = '#ff6b6b';
    }

    $base = defined('APP_URL') ? APP_URL : '';
    $versaoJs = @filemtime(__DIR__ . '/../assets/js/atraso-atualizacao.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1');
    $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    return '<style>@media print{.atraso-proxima-atualizacao{display:none}}</style>'
        . '<div class="atraso-proxima-atualizacao mt-1 text-[0.68rem] font-semibold" style="color:' . $cor . ';" '
        . 'data-modulo="' . $h($modulo) . '" data-restante="' . $restante . '" '
        . 'data-tolerancia="' . ATRASO_TOLERANCIA_ATUALIZACAO_S . '" '
        . 'data-ultima="' . $h($atualizacao['atualizado_em']) . '" data-atualizado-fmt="' . $h($quandoFmt) . '" '
        . 'title="Foto do atraso extraída do ERP (VSAT) e atualizada automaticamente a cada 15 minutos. Última atualização: ' . $h($quandoFmt) . '.">'
        . '<span class="atraso-proxima-texto">' . $h($texto) . '</span></div>'
        . '<script src="' . $h($base) . '/assets/js/atraso-atualizacao.js?v=' . $versaoJs . '" defer></script>';
}

/**
 * Retorna os itens de ordens em atraso/aberto a partir do banco de dados MySQL
 * para o painel de setores e consultas analíticas.
 */
function boletimCarregarSnapshot(?string $dataSnapshot = null): array
{
    $pdo = getDB();
    boletimGarantirTabelasAtraso($pdo);

    $datasDisponiveis = boletimListarDatasExtracaoAtraso();
    if (empty($datasDisponiveis)) {
        return ['sucesso' => false, 'erro' => 'Nenhum registro de atraso encontrado no banco de dados.', 'itens' => []];
    }

    if ($dataSnapshot === null || !isset($datasDisponiveis[$dataSnapshot])) {
        $dataSnapshot = (string) array_key_first($datasDisponiveis);
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 
                id, data_extracao, data_programada, cd_referencia, cd_referencia AS referencia,
                ds_produto, ds_produto AS descricao, qtd_item, quantidade, qtd_produzida,
                qtd_a_produzir, cliente_nome, cliente_apelido, cd_pedido, cd_pedido AS pedido,
                dt_pedido, dt_limite_entrega, potencia_kva, potencia_kva AS kva, fases,
                classe_tensao, tipo_nucleo, tipo_construtivo, linha, seq_plano, uf
            FROM atraso_distribuicao_registros
            WHERE data_extracao = :data_extracao
            ORDER BY data_programada ASC, id ASC
        ");
        $stmt->execute(['data_extracao' => $dataSnapshot]);
        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'sucesso' => true,
            'itens' => $itens,
            'snapshot_info' => [
                'data' => $dataSnapshot,
                'total' => count($itens),
                'arquivo' => 'MySQL: atraso_distribuicao_registros (' . $dataSnapshot . ')'
            ]
        ];
    } catch (\Throwable $e) {
        return ['sucesso' => false, 'erro' => $e->getMessage(), 'itens' => []];
    }
}

/**
 * Calcula todas as métricas consolidadas de atraso a partir do Banco de Dados MySQL.
 *
 * @param string $dataCorte Data de corte do cálculo (ex: '2026-08-24' ou '2026-08-26')
 * @param array $mesesFiltro Filtro de meses (ex: ['2026-08', '2026-07']) ou vazio para todos
 * @param string|null $dataExtracao Data de extração/sincronização do Plano Mestre
 */
function boletimCalcularMetricasAtraso(string $dataCorte, array $mesesFiltro = [], ?string $dataExtracao = null): array
{
    $pdo = getDB();
    boletimGarantirTabelasAtraso($pdo);

    $datasDisponiveis = boletimListarDatasExtracaoAtraso();
    if (empty($datasDisponiveis)) {
        return ['sucesso' => false, 'erro' => 'Nenhum registro de atraso encontrado no banco de dados.'];
    }

    if ($dataExtracao === null || !isset($datasDisponiveis[$dataExtracao])) {
        $dataExtracao = (string) array_key_first($datasDisponiveis);
    }

    $mesRef = substr($dataCorte, 0, 7); // '2026-08'

    // 1. Carrega produção real diária do Kardex (SQL Server / Boletim) para o mês
    $dadosKardex = boletimObterDadosMes($mesRef);
    $kardexPorDia = $dadosKardex['nucleoPorDia'] ?? [];

    // 2. Dias úteis (Segunda a Sexta) do mês de referência
    [$anoRef, $mesRefNum] = array_map('intval', explode('-', $mesRef));
    $diasNoMesRef = (int) date('t', mktime(0, 0, 0, $mesRefNum, 1, $anoRef));
    $diasUteisMes = [];
    for ($d = 1; $d <= $diasNoMesRef; $d++) {
        $diaStr = sprintf('%04d-%02d-%02d', $anoRef, $mesRefNum, $d);
        if ((int) date('N', strtotime($diaStr)) <= 5) {
            $diasUteisMes[] = $diaStr;
        }
    }

    // Dias úteis decorridos até a data de corte (inclusive se o dia tiver 0 produção ou 0 atraso)
    $diasUteisTrabalhados = [];
    foreach ($diasUteisMes as $diaYmd) {
        if ($diaYmd <= $dataCorte) {
            $diasUteisTrabalhados[] = $diaYmd;
        }
    }
    if (empty($diasUteisTrabalhados)) {
        $diasUteisTrabalhados = [$dataCorte];
    }
    $totalDiasUteisDMenos1 = count($diasUteisTrabalhados);

    // 3. Produção acumulada no mês até a data de corte
    $prodAcumulada = [
        'Monofásico'   => 0,
        'Convencional' => 0,
        'JC-TRIF'      => 0,
    ];
    foreach ($diasUteisTrabalhados as $diaYmd) {
        $p = $kardexPorDia[$diaYmd] ?? [];
        $prodAcumulada['Monofásico']   += (int) ($p['ENR'] ?? 0);
        $prodAcumulada['Convencional'] += (int) ($p['EMP'] ?? 0);
        $prodAcumulada['JC-TRIF']      += (int) ($p['JC'] ?? 0);
    }

    // 4. Médias diárias acumuladas (peças/dia) — divide pelo total de dias úteis decorridos
    $mediaDiaria = [
        'Monofásico'   => ($totalDiasUteisDMenos1 > 0) ? ($prodAcumulada['Monofásico'] / $totalDiasUteisDMenos1) : 0.0,
        'Convencional' => ($totalDiasUteisDMenos1 > 0) ? ($prodAcumulada['Convencional'] / $totalDiasUteisDMenos1) : 0.0,
        'JC-TRIF'      => ($totalDiasUteisDMenos1 > 0) ? ($prodAcumulada['JC-TRIF'] / $totalDiasUteisDMenos1) : 0.0,
    ];

    // Capacidade consolidada do mês anterior como referência / baseline para virada de mês
    $mesAnterior = date('Y-m', strtotime($mesRef . '-01 -1 month'));
    $kardexAnt = boletimObterDadosMes($mesAnterior)['nucleoPorDia'] ?? [];
    $mediaDiariaRef = ['Monofásico' => 0.0, 'Convencional' => 0.0, 'JC-TRIF' => 0.0];
    if (!empty($kardexAnt)) {
        $diasAnt = count($kardexAnt);
        $pMonoAnt = 0; $pConvAnt = 0; $pJCAnt = 0;
        foreach ($kardexAnt as $p) {
            $pMonoAnt += (int) ($p['ENR'] ?? 0);
            $pConvAnt += (int) ($p['EMP'] ?? 0);
            $pJCAnt   += (int) ($p['JC'] ?? 0);
        }
        if ($diasAnt > 0) {
            $mediaDiariaRef['Monofásico']   = $pMonoAnt / $diasAnt;
            $mediaDiariaRef['Convencional'] = $pConvAnt / $diasAnt;
            $mediaDiariaRef['JC-TRIF']      = $pJCAnt / $diasAnt;
        }
    }

    // Se o mês estiver nos primeiros dias (dias úteis <= 3) ou se alguma linha não tiver capacidade representativa,
    // adota a média de referência consolidada do mês anterior para manter os indicadores de atraso estáveis e realistas
    foreach (['Monofásico', 'Convencional', 'JC-TRIF'] as $lKey) {
        if ($totalDiasUteisDMenos1 <= 3 || $mediaDiaria[$lKey] <= 0) {
            if ($mediaDiariaRef[$lKey] > 0) {
                $mediaDiaria[$lKey] = $mediaDiariaRef[$lKey];
            }
        }
    }

    // 5. Consulta itens do banco de dados MySQL para a data de extração selecionada
    $stmtItens = $pdo->prepare("
        SELECT 
            id, data_extracao, data_programada, cd_referencia, ds_produto, qtd_item,
            quantidade, qtd_produzida, qtd_a_produzir, cliente_nome, cliente_apelido,
            cd_pedido, dt_pedido, dt_limite_entrega, potencia_kva, fases,
            classe_tensao, tipo_nucleo, tipo_construtivo, linha, seq_plano, uf, num_serie
        FROM atraso_distribuicao_registros
        WHERE data_extracao = :data_extracao
        ORDER BY data_programada ASC, id ASC
    ");
    $stmtItens->execute(['data_extracao' => $dataExtracao]);
    $todosItens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

    // Carrega a esteira de produção para cruzar com as ordens em atraso (Número de Série e 10 Células)
    require_once __DIR__ . '/boletim-fluxo-pedidos.php';
    $planilhaFluxo = carregarPlanilhaProducaoFluxo();
    // Índice direto por NS (O(1), casamento exato) — desde que atraso_distribuicao_registros
    // passou a ter uma linha por Número de Série (ver boletimSincronizarAtrasoSqlServer()),
    // é o caminho principal abaixo. Os índices por pedido/projeto continuam só como
    // fallback pra linhas antigas sem num_serie ainda gravado (snapshot anterior à
    // mudança) — usá-los como caminho principal explodia memória/tempo aqui: um pedido
    // pode casar dezenas de itens do fluxo, e agora há 1 linha por NS (~12x mais linhas
    // que no snapshot por lote), então cada uma reconstruía essa lista grande à toa.
    $fluxoPorNS = [];
    $fluxoPorPedProj = [];
    $fluxoPorPed = [];
    $fluxoPorProj = [];
    if (!empty($planilhaFluxo['itens'])) {
        foreach ($planilhaFluxo['itens'] as $fl) {
            $nsFl = trim((string)($fl['nr_serie'] ?? ''));
            if ($nsFl !== '') {
                $fluxoPorNS[$nsFl] = $fl;
            }
            $pedFl = trim((string)($fl['pedido'] ?? ''));
            $projFl = trim((string)($fl['projeto'] ?? ''));
            if ($pedFl && $projFl) {
                $fluxoPorPedProj["{$pedFl}_{$projFl}"][] = $fl;
            }
            if ($pedFl) {
                $fluxoPorPed[$pedFl][] = $fl;
            }
            if ($projFl) {
                $fluxoPorProj[$projFl][] = $fl;
            }
        }
    }

    // Cache suplementar de NSs direto do SQL Server para ordens concluídas/entregues no comercial
    $suplementarNS = [];
    $suplemFile = __DIR__ . '/../storage/cache/atraso_ns_suplementar.json';
    if (is_file($suplemFile)) {
        $rawSuplem = @file_get_contents($suplemFile);
        if ($rawSuplem !== false) {
            $suplementarNS = @json_decode($rawSuplem, true) ?: [];
        }
    }

    $itensAtrasados = [];
    $pecasPorLinha = [
        'Monofásico'   => 0.0,
        'Convencional' => 0.0,
        'JC-TRIF'      => 0.0,
        'Outros'       => 0.0,
    ];
    $pecasPorMes = [];
    $semanasPorMes = [];
    $mesesDisponiveis = [];

    $mesesNomes = [
        1 => 'JANEIRO', 2 => 'FEVEREIRO', 3 => 'MARÇO', 4 => 'ABRIL',
        5 => 'MAIO', 6 => 'JUNHO', 7 => 'JULHO', 8 => 'AGOSTO',
        9 => 'SETEMBRO', 10 => 'OUTUBRO', 11 => 'NOVEMBRO', 12 => 'DEZEMBRO'
    ];

    foreach ($todosItens as $r) {
        $dtProg = (string) $r['data_programada'];
        $mKey = substr($dtProg, 0, 7); // '2026-08'
        $mesNum = (int) substr($dtProg, 5, 2);
        $anoDois = substr($dtProg, 2, 2);
        $mRotulo = ($mesesNomes[$mesNum] ?? 'MÊS') . '/' . $anoDois;

        if (!isset($mesesDisponiveis[$mKey])) {
            $mesesDisponiveis[$mKey] = $mRotulo;
        }

        // Condição de Atraso: Programação <= Data de Corte
        if ($dtProg > $dataCorte) {
            continue;
        }

        // Filtro opcional de meses
        if (!empty($mesesFiltro) && !in_array($mKey, $mesesFiltro, true)) {
            continue;
        }

        $qtd = (int) $r['quantidade'];
        $linha = $r['linha'];
        if (!isset($pecasPorLinha[$linha])) {
            $linha = 'Convencional';
        }

        $pecasPorLinha[$linha] += $qtd;

        if (!isset($pecasPorMes[$mKey])) {
            $pecasPorMes[$mKey] = [
                'chave'  => $mKey,
                'rotulo' => $mRotulo,
                'qtd'    => 0.0,
            ];
        }
        $pecasPorMes[$mKey]['qtd'] += $qtd;

        // Distribuição Semanal
        $tsProg = strtotime($dtProg);
        $numSemana = (int) date('W', $tsProg);
        $anoSemana = (int) date('o', $tsProg);

        $dto = new DateTime();
        $dto->setISODate($anoSemana, $numSemana);
        $dtIniSem = $dto->format('d/m');
        $dto->modify('+6 days');
        $dtFimSem = $dto->format('d/m');
        $rotuloSem = "Sem {$numSemana} ({$dtIniSem} a {$dtFimSem})";

        $r['semana_ano'] = $numSemana;
        $r['semana_rotulo'] = $rotuloSem;
        $r['mes_chave'] = $mKey;

        if (!isset($semanasPorMes[$mKey][$numSemana])) {
            $semanasPorMes[$mKey][$numSemana] = [
                'mes_chave'     => $mKey,
                'mes_rotulo'    => $mRotulo,
                'semana'        => $numSemana,
                'rotulo'        => $rotuloSem,
                'qtd'           => 0,
                'monofasico'    => 0,
                'convencional'  => 0,
                'jc_trif'       => 0,
            ];
        }
        $semanasPorMes[$mKey][$numSemana]['qtd'] += $qtd;
        if ($linha === 'Monofásico') $semanasPorMes[$mKey][$numSemana]['monofasico'] += $qtd;
        elseif ($linha === 'JC-TRIF') $semanasPorMes[$mKey][$numSemana]['jc_trif'] += $qtd;
        else $semanasPorMes[$mKey][$numSemana]['convencional'] += $qtd;

        $diasAtrasoOrdem = (int) max(0, (strtotime($dataCorte) - strtotime($dtProg)) / 86400);
        $r['dias_atraso_individual'] = $diasAtrasoOrdem;
        $r['mes_ano_rotulo'] = $mRotulo;

        // Cruzamento com Fluxo de Pedidos / Chão de Fábrica
        $ped = trim((string)($r['cd_pedido'] ?? ''));
        $proj = trim((string)($r['cd_referencia'] ?? ''));
        $kPedProj = "{$ped}_{$proj}";
        $nsRow = trim((string)($r['num_serie'] ?? ''));

        // Casamento direto por NS quando a linha já tem num_serie (caminho principal —
        // ver comentário acima de $fluxoPorNS). Só cai no fallback por pedido/projeto
        // (que pode trazer muitos itens de uma vez) pra linhas antigas sem NS gravado.
        $matchedFluxo = ($nsRow !== '' && isset($fluxoPorNS[$nsRow]))
            ? [$fluxoPorNS[$nsRow]]
            : ($fluxoPorPedProj[$kPedProj] ?? ($fluxoPorPed[$ped] ?? ($fluxoPorProj[$proj] ?? [])));

        $nsList = [];
        $setoresConsolidado = [
            'CH' => 'PEND', 'BT' => 'PEND', 'AT' => 'PEND', 'CNC' => 'PEND',
            'SOL' => 'PEND', 'MN' => 'PEND', 'PIN' => 'PEND', 'ME' => 'PEND',
            'MF' => 'PEND', 'LAB' => 'PEND'
        ];
        $fluxoDetalhes = [];

        if (!empty($matchedFluxo)) {
            foreach ($matchedFluxo as $mf) {
                if (!empty($mf['nr_serie'])) {
                    $nsList[] = (string)$mf['nr_serie'];
                }
                $fluxoDetalhes[] = [
                    'nr_serie'     => $mf['nr_serie'] ?? '—',
                    'of_mae'       => $mf['of_mae'] ?? '—',
                    'seq'          => $mf['seq'] ?? '—',
                    'tipo_constr'  => $mf['tipo_constr'] ?? '—',
                    'setores'      => $mf['setores'] ?? [],
                    'is_concluido' => !empty($mf['is_concluido']),
                ];
            }
            if (!empty($matchedFluxo[0]['setores'])) {
                $setoresConsolidado = $matchedFluxo[0]['setores'];
            }
        }

        // Se ainda não encontrou NS no fluxo ativo, recorre ao cache suplementar de séries do ERP
        if (empty($nsList)) {
            $suplSeries = $suplementarNS[$kPedProj] ?? ($suplementarNS[$proj] ?? []);
            if (!empty($suplSeries)) {
                foreach ($suplSeries as $nsNum) {
                    $nsList[] = (string)$nsNum;
                    $fluxoDetalhes[] = [
                        'nr_serie'     => (string)$nsNum,
                        'of_mae'       => '—',
                        'seq'          => '—',
                        'tipo_constr'  => '—',
                        'setores'      => [
                            'CH' => 'OK', 'BT' => 'OK', 'AT' => 'OK', 'CNC' => 'OK',
                            'SOL' => 'OK', 'MN' => 'OK', 'PIN' => 'OK', 'ME' => 'OK',
                            'MF' => 'OK', 'LAB' => 'PEND'
                        ],
                        'is_concluido' => false,
                    ];
                }
                $setoresConsolidado = [
                    'CH' => 'OK', 'BT' => 'OK', 'AT' => 'OK', 'CNC' => 'OK',
                    'SOL' => 'OK', 'MN' => 'OK', 'PIN' => 'OK', 'ME' => 'OK',
                    'MF' => 'OK', 'LAB' => 'PEND'
                ];
            }
        }

        $r['numeros_serie'] = array_values(array_unique($nsList));
        if (count($r['numeros_serie']) > 1) {
            $r['nr_serie_formatado'] = min($r['numeros_serie']) . ' – ' . max($r['numeros_serie']);
        } elseif (count($r['numeros_serie']) === 1) {
            $r['nr_serie_formatado'] = $r['numeros_serie'][0];
        } else {
            $r['nr_serie_formatado'] = '—';
        }

        $r['setores'] = $setoresConsolidado;
        $r['fluxo_detalhes'] = $fluxoDetalhes;

        $itensAtrasados[] = $r;
    }

    krsort($pecasPorMes);
    ksort($mesesDisponiveis);

    // 6. Cálculo dos Indicadores de Atraso em Dias (DAX DIVIDE)
    // Se não houver peças atrasadas em uma linha, o atraso é 0.0 e permanece zerado na conta
    $atrasoDias = [
        'Monofásico'   => ($mediaDiaria['Monofásico'] > 0) ? ($pecasPorLinha['Monofásico'] / $mediaDiaria['Monofásico']) : 0.0,
        'Convencional' => ($mediaDiaria['Convencional'] > 0) ? ($pecasPorLinha['Convencional'] / $mediaDiaria['Convencional']) : 0.0,
        'JC-TRIF'      => ($mediaDiaria['JC-TRIF'] > 0) ? ($pecasPorLinha['JC-TRIF'] / $mediaDiaria['JC-TRIF']) : 0.0,
    ];

    // Média Geral Simples (média aritmética dos 3 ramos industriais)
    $mediaGeralDias = ($atrasoDias['Monofásico'] + $atrasoDias['Convencional'] + $atrasoDias['JC-TRIF']) / 3.0;
    $totalPecasAtrasadas = $pecasPorLinha['Monofásico'] + $pecasPorLinha['Convencional'] + $pecasPorLinha['JC-TRIF'];

    // 7. Série Temporal para o Gráfico "Média de dias em atraso" (Curva Diária)
    // Média literal: para cada um dos últimos 15 dias úteis, quantos dias as
    // peças ainda em aberto (na foto de hoje) já estavam atrasadas NAQUELE dia
    // — média ponderada por quantidade, dentro de cada ramo (Mono/Conv/JC).
    // Não depende de produção nem de snapshots históricos salvos.
    $diasJanela = [];
    $cursor = strtotime($dataCorte);
    while (count($diasJanela) < 15) {
        if ((int) date('N', $cursor) <= 5) {
            $diasJanela[] = date('Y-m-d', $cursor);
        }
        $cursor = strtotime('-1 day', $cursor);
    }
    $diasJanela = array_reverse($diasJanela);

    // Snapshots reais já salvos para os dias desta janela (gravados no fim
    // desta função em cada sincronização) — têm prioridade sobre a
    // reconstrução por estimativa, pois refletem a foto de peças em aberto
    // do próprio dia, não a de hoje.
    $historicoRealPorDia = [];
    try {
        $stmtHistReal = $pdo->prepare("
            SELECT data_referencia, media_geral, dias_mono, dias_conv, dias_jc, pecas_total
            FROM `atraso_historico_diario`
            WHERE modulo = 'distribuicao' AND data_referencia IN (" . implode(',', array_fill(0, count($diasJanela), '?')) . ")
        ");
        $stmtHistReal->execute($diasJanela);
        while ($r = $stmtHistReal->fetch(PDO::FETCH_ASSOC)) {
            $historicoRealPorDia[$r['data_referencia']] = $r;
        }
    } catch (\Throwable $e) {}

    // Agrupa peças em aberto por data de programação
    $pecasPorDataProg = [];
    foreach ($todosItens as $item) {
        $mKey = substr($item['data_programada'], 0, 7);
        if (!empty($mesesFiltro) && !in_array($mKey, $mesesFiltro, true)) continue;
        $dProg = $item['data_programada'];
        $l = $item['linha'];
        if (!isset($pecasPorDataProg[$dProg])) {
            $pecasPorDataProg[$dProg] = ['Monofásico' => 0, 'Convencional' => 0, 'JC-TRIF' => 0];
        }
        if (isset($pecasPorDataProg[$dProg][$l])) {
            $pecasPorDataProg[$dProg][$l] += (int) $item['quantidade'];
        }
    }

    $serieEvolucao = [];
    foreach ($diasJanela as $diaSim) {
        if ($diaSim === $dataCorte) {
            $serieEvolucao[] = [
                'data'         => $diaSim,
                'label'        => date('d/m/Y', strtotime($diaSim)),
                'media_geral'  => round($mediaGeralDias, 2),
                'dias_mono'    => round($atrasoDias['Monofásico'], 2),
                'dias_conv'    => round($atrasoDias['Convencional'], 2),
                'dias_jc'      => round($atrasoDias['JC-TRIF'], 2),
                'pecas_total'  => (int) round($totalPecasAtrasadas),
            ];
            continue;
        }

        if (isset($historicoRealPorDia[$diaSim])) {
            $hr = $historicoRealPorDia[$diaSim];
            $serieEvolucao[] = [
                'data'         => $diaSim,
                'label'        => date('d/m/Y', strtotime($diaSim)),
                'media_geral'  => (float) $hr['media_geral'],
                'dias_mono'    => (float) $hr['dias_mono'],
                'dias_conv'    => (float) $hr['dias_conv'],
                'dias_jc'      => (float) $hr['dias_jc'],
                'pecas_total'  => (int) $hr['pecas_total'],
            ];
            continue;
        }

        $somaDiasMono = 0.0; $qtdMono = 0;
        $somaDiasConv = 0.0; $qtdConv = 0;
        $somaDiasJC   = 0.0; $qtdJC   = 0;

        foreach ($pecasPorDataProg as $dProg => $linhasQtd) {
            if ($dProg > $diaSim) continue;
            $diasAtrasoNaData = (int) round((strtotime($diaSim) - strtotime($dProg)) / 86400);

            if ($linhasQtd['Monofásico'] > 0) {
                $somaDiasMono += $diasAtrasoNaData * $linhasQtd['Monofásico'];
                $qtdMono += $linhasQtd['Monofásico'];
            }
            if ($linhasQtd['Convencional'] > 0) {
                $somaDiasConv += $diasAtrasoNaData * $linhasQtd['Convencional'];
                $qtdConv += $linhasQtd['Convencional'];
            }
            if ($linhasQtd['JC-TRIF'] > 0) {
                $somaDiasJC += $diasAtrasoNaData * $linhasQtd['JC-TRIF'];
                $qtdJC += $linhasQtd['JC-TRIF'];
            }
        }

        $diasMonoSim = ($qtdMono > 0) ? ($somaDiasMono / $qtdMono) : 0.0;
        $diasConvSim = ($qtdConv > 0) ? ($somaDiasConv / $qtdConv) : 0.0;
        $diasJCSim   = ($qtdJC   > 0) ? ($somaDiasJC   / $qtdJC)   : 0.0;
        $mediaGeralSim = ($diasMonoSim + $diasConvSim + $diasJCSim) / 3.0;

        $serieEvolucao[] = [
            'data'         => $diaSim,
            'label'        => date('d/m/Y', strtotime($diaSim)),
            'media_geral'  => round($mediaGeralSim, 1),
            'dias_mono'    => round($diasMonoSim, 2),
            'dias_conv'    => round($diasConvSim, 2),
            'dias_jc'      => round($diasJCSim, 2),
            'pecas_total'  => (int) ($qtdMono + $qtdConv + $qtdJC),
        ];
    }

    // Grava o snapshot real de hoje no histórico persistente (idempotente —
    // cada sincronização sobrescreve o valor do próprio dia, então o dia só
    // "congela" de fato quando o calendário vira). Só grava a data de hoje:
    // dias passados não são recalculados aqui.
    if ($dataCorte === date('Y-m-d')) {
        $ultimoPonto = end($serieEvolucao);
        if ($ultimoPonto && $ultimoPonto['data'] === $dataCorte) {
            try {
                $stmtUpHist = $pdo->prepare("
                    INSERT INTO `atraso_historico_diario` (
                        modulo, data_referencia, media_geral, dias_mono, dias_conv, dias_jc, pecas_total
                    ) VALUES (
                        'distribuicao', :dt, :media, :mono, :conv, :jc, :pecas
                    ) ON DUPLICATE KEY UPDATE
                        media_geral = VALUES(media_geral),
                        dias_mono   = VALUES(dias_mono),
                        dias_conv   = VALUES(dias_conv),
                        dias_jc     = VALUES(dias_jc),
                        pecas_total = VALUES(pecas_total)
                ");
                $stmtUpHist->execute([
                    ':dt'    => $dataCorte,
                    ':media' => $ultimoPonto['media_geral'],
                    ':mono'  => $ultimoPonto['dias_mono'],
                    ':conv'  => $ultimoPonto['dias_conv'],
                    ':jc'    => $ultimoPonto['dias_jc'],
                    ':pecas' => $ultimoPonto['pecas_total'],
                ]);
            } catch (\Throwable $e) {}
        }
    }

    $semanasPorMesFormatado = [];
    foreach ($semanasPorMes as $mK => $sems) {
        ksort($sems);
        $semanasPorMesFormatado[$mK] = array_values($sems);
    }

    return boletimUtf8Safe([
        'sucesso'               => true,
        'data_corte'            => $dataCorte,
        'data_corte_formatada'  => boletimFormatarDataPorExtenso($dataCorte),
        'data_extracao'         => $dataExtracao,
        'mes_referencia'        => $mesRef,
        'datas_disponiveis'     => $datasDisponiveis,
        'meses_disponiveis'     => $mesesDisponiveis,
        'meses_filtro_ativos'   => $mesesFiltro,
        'dias_trabalhados_d1'   => $totalDiasUteisDMenos1,
        'prod_acumulada'        => $prodAcumulada,
        'media_diaria'          => [
            'MONOFASICO'   => round($mediaDiaria['Monofásico'], 2),
            'CONVENCIONAL' => round($mediaDiaria['Convencional'], 2),
            'JC_TRIF'      => round($mediaDiaria['JC-TRIF'], 2),
        ],
        'pecas_atraso'          => [
            'MONOFASICO'   => (int) round($pecasPorLinha['Monofásico']),
            'CONVENCIONAL' => (int) round($pecasPorLinha['Convencional']),
            'JC_TRIF'      => (int) round($pecasPorLinha['JC-TRIF']),
            'TOTAL'        => (int) round($totalPecasAtrasadas),
        ],
        'atraso_dias'           => [
            'MONOFASICO'   => round($atrasoDias['Monofásico'], 2),
            'CONVENCIONAL' => round($atrasoDias['Convencional'], 2),
            'JC_TRIF'      => round($atrasoDias['JC-TRIF'], 2),
            'MEDIA_GERAL'  => round($mediaGeralDias, 2),
        ],
        'pecas_por_mes'         => array_values($pecasPorMes),
        'semanas_por_mes'       => $semanasPorMesFormatado,
        'serie_evolucao'        => $serieEvolucao,
        'total_ordens'          => count($itensAtrasados),
        'itens_detalhados'      => $itensAtrasados,
    ]);
}

/**
 * Formata data Y-m-d para formato por extenso (ex: "26 de agosto").
 */
function boletimFormatarDataPorExtenso(string $dataYmd): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dataYmd, $m)) {
        return $dataYmd;
    }
    $dia = (int) $m[3];
    $mes = (int) $m[2];
    $meses = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
        5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
        9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro'
    ];
    return sprintf('%d de %s', $dia, $meses[$mes] ?? '');
}
