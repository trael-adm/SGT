<?php
declare(strict_types=1);

require_once __DIR__ . '/versao.php';

(function () {

    // Helper seguro para buscar variáveis de ambiente
    $getEnv = function ($key, $default = '') {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        return $default;
    };

    // Carregar .env e .env.production se existirem
    $env = [];
    $possiblePaths = [
        __DIR__ . '/../.env',
    ];

    foreach ($possiblePaths as $path) {
        if (is_readable($path)) {
            $parsed = @parse_ini_file($path, false, INI_SCANNER_TYPED);
            if (is_array($parsed)) {
                $env = array_merge($env, $parsed);
            }
        }
    }

    // Mapeamento com prioridade:
    // 1) Variáveis Railway (MYSQL*)
    // 2) Variáveis DB_*
    // 3) Arquivo .env
    // 4) Defaults
    $config = [
        'DB_HOST' => $getEnv('MYSQLHOST', $getEnv('MYSQL_HOST', $env['DB_HOST'] ?? 'mysql.railway.internal')),
        'DB_NAME' => $getEnv('MYSQLDATABASE', $getEnv('MYSQL_DATABASE', $env['DB_NAME'] ?? 'railway')),
        'DB_USER' => $getEnv('MYSQLUSER', $getEnv('MYSQL_USER', $env['DB_USER'] ?? 'root')),
        'DB_PASS' => $getEnv('MYSQLPASSWORD', $getEnv('MYSQL_PASSWORD', $env['DB_PASS'] ?? '')),
        'DB_PORT' => $getEnv('MYSQLPORT', $getEnv('MYSQL_PORT', $env['DB_PORT'] ?? '3306')),

        'APP_URL' => $getEnv('APP_URL', $env['APP_URL'] ?? ''),
        'APP_ENV' => $getEnv('APP_ENV', $env['APP_ENV'] ?? 'local'),
        'APP_TIMEZONE' => $getEnv('APP_TIMEZONE', $env['APP_TIMEZONE'] ?? 'America/Cuiaba'),

        'SQLSRV_HOST'   => $getEnv('SQLSRV_HOST', $env['SQLSRV_HOST'] ?? 'vsat.trael.local'),
        'SQLSRV_DB'     => $getEnv('SQLSRV_DB', $env['SQLSRV_DB'] ?? 'vsattrael'),
        'SQLSRV_USER'   => $getEnv('SQLSRV_USER', $env['SQLSRV_USER'] ?? 'bi_consulta'),
        'SQLSRV_PASS'   => $getEnv('SQLSRV_PASS', $env['SQLSRV_PASS'] ?? ''),
        'SQLSRV_DRIVER' => $getEnv('SQLSRV_DRIVER', $env['SQLSRV_DRIVER'] ?? 'SQL Server Native Client 11.0'),
    ];

    define('_GFT_ENV', $config);
    if (!defined('_PCP_ENV')) {
        define('_PCP_ENV', $config);
    }

    // Detectar APP_URL dinamicamente se não definido
    $appUrl = $config['APP_URL'];
    if (empty($appUrl)) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $protocol = $isHttps ? 'https' : 'http';
        $rawHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Sanitizar host para permitir apenas caracteres válidos de hostname/porta
        $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', explode(',', $rawHost)[0]);
        if (empty($host)) {
            $host = 'localhost';
        }

        $baseDir = '';
        if (!empty($config['APP_URL'])) {
            $parsedPath = parse_url($config['APP_URL'], PHP_URL_PATH);
            if ($parsedPath) {
                $baseDir = rtrim($parsedPath, '/');
            }
        }
        
        if ($baseDir === '') {
            $appRoot = str_replace('\\', '/', dirname(__DIR__));
            $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\'));
            
            if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
                $baseDir = substr($appRoot, strlen($docRoot));
            } else {
                // Fallback
                $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
                $baseDir = str_replace('\\', '/', dirname($scriptPath));
                $baseDir = preg_replace('#/(api|config|pages|includes).*$#', '', $baseDir);
            }
            $baseDir = rtrim($baseDir, '/');
        }

        $appUrl = $protocol . '://' . $host . $baseDir;
    }

    define('APP_URL', $appUrl);
    define('APP_ENV', $config['APP_ENV']);

})();


// Função de conexão PDO
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $env = _GFT_ENV;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $env['DB_HOST'],
        $env['DB_PORT'],
        $env['DB_NAME']
    );

    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET time_zone = '-04:00'");

    // Auto-verificação e criação direta de colunas essenciais (evita erro 1054 em produção/Railway)
    static $schemaChecked = false;
    if (!$schemaChecked) {
        $schemaChecked = true;
        try {
            $pdo->exec("ALTER TABLE itens_catalogo ADD COLUMN preco_medio DECIMAL(14,4) NULL DEFAULT NULL AFTER unidade");
        } catch (\Throwable $e) {}

        try {
            $pdo->exec("ALTER TABLE retrabalho_material_uso ADD COLUMN preco_medio DECIMAL(14,4) NULL DEFAULT NULL AFTER quantidade");
        } catch (\Throwable $e) {}

        try {
            $pdo->exec("ALTER TABLE reprovas ADD COLUMN setor_causador VARCHAR(50) NOT NULL DEFAULT 'S/ Setor Causador' AFTER codigo");
        } catch (\Throwable $e) {}

        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN cpf VARCHAR(14) NULL DEFAULT NULL AFTER email");
        } catch (\Throwable $e) {}

        try {
            $pdo->exec("
                UPDATE reprovas 
                SET setor_causador = CASE
                    WHEN codigo REGEXP '^EG[0-9]+' THEN 'ENGENHARIA'
                    WHEN codigo REGEXP '^C[0-9]+'  THEN 'CALDEIRARIA'
                    WHEN codigo REGEXP '^E[0-9]+'  THEN 'ELÉTRICO'
                    WHEN codigo REGEXP '^L[0-9]+'  THEN 'LINHA'
                    WHEN codigo REGEXP '^P[0-9]+'  THEN 'PINTURA'
                    WHEN codigo REGEXP '^R[0-9]+'  THEN 'REVITALIZAÇÃO'
                    ELSE setor_causador
                END
                WHERE setor_causador = 'S/ Setor Causador' AND codigo REGEXP '^(EG|C|E|L|P|R)[0-9]+'
            ");
        } catch (\Throwable $e) {}

        try {
            $pdo->exec("
                UPDATE reprovas 
                SET local = 'IQF' 
                WHERE local = 'GER' AND codigo NOT LIKE 'R%'
            ");
            $pdo->exec("
                UPDATE reprovas 
                SET local = 'GER', setor_causador = 'REVITALIZAÇÃO' 
                WHERE codigo LIKE 'R%'
            ");
        } catch (\Throwable $e) {}

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS boletim_config_metas (
                    `month`               CHAR(7)   NOT NULL PRIMARY KEY,
                    meta_total            INT       NOT NULL DEFAULT 0,
                    meta_tpm              INT       NOT NULL DEFAULT 0,
                    meta_tpd_distribuicao INT       NOT NULL DEFAULT 0,
                    meta_enrolado         INT       NOT NULL DEFAULT 0,
                    meta_convencional     INT       NOT NULL DEFAULT 0,
                    meta_jctrif           INT       NOT NULL DEFAULT 0,
                    meta_tpd_forca        INT       NOT NULL DEFAULT 0,
                    meta_tps              INT       NOT NULL DEFAULT 0,
                    dias_uteis            INT       NOT NULL DEFAULT 0,
                    dias_trabalhados      INT       NOT NULL DEFAULT 0,
                    dias_customizados     TEXT      NULL,
                    created_at            TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at            TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
                INSERT IGNORE INTO boletim_config_metas (`month`, meta_tpd_distribuicao, meta_enrolado, meta_convencional, meta_jctrif, meta_tpm, meta_tpd_forca, meta_tps, dias_uteis)
                VALUES ('2026-08', 5250, 3780, 1386, 84, 63, 252, 21, 21)
            ");
            try {
                $pdo->exec("ALTER TABLE boletim_config_metas ADD COLUMN metas_setores TEXT NULL");
            } catch (\Throwable $e) {}
        } catch (\Throwable $e) {}

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS boletim_registros (
                    id           INT          AUTO_INCREMENT PRIMARY KEY,
                    `date`       DATE         NOT NULL,
                    `area`       ENUM('distrib','forca') NOT NULL,
                    line         VARCHAR(10)  NOT NULL,
                    core_type    ENUM('ENR','JC','EMP','LAB') NULL,
                    prog         INT          NOT NULL DEFAULT 0,
                    `real`       INT          NOT NULL DEFAULT 0,
                    description  VARCHAR(255) NULL,
                    `origin`     ENUM('manual','excel','excel_consolidated') NOT NULL DEFAULT 'manual',
                    id_criador   INT          NOT NULL,
                    created_at   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    deleted_at   TIMESTAMP    NULL DEFAULT NULL,
                    KEY idx_boletim_registros_consulta (`date`, `area`, line, core_type),
                    KEY idx_boletim_registros_deleted (deleted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Throwable $e) {}

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS boletim_equipamentos (
                    id          INT          AUTO_INCREMENT PRIMARY KEY,
                    nome        VARCHAR(100) NOT NULL,
                    `status`    ENUM('verde','amarelo','vermelho') NOT NULL DEFAULT 'verde',
                    observacao  VARCHAR(255) NULL,
                    id_criador  INT          NOT NULL,
                    created_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    deleted_at  TIMESTAMP    NULL DEFAULT NULL,
                    KEY idx_boletim_equipamentos_status (`status`),
                    KEY idx_boletim_equipamentos_deleted (deleted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Throwable $e) {}

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS boletim_potencia_diaria (
                    id             INT           AUTO_INCREMENT PRIMARY KEY,
                    `date`         DATE          NOT NULL,
                    `area`         ENUM('distrib','forca') NOT NULL,
                    potencia_media DECIMAL(10,2) NOT NULL,
                    id_criador     INT           NOT NULL,
                    created_at     TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at     TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    deleted_at     TIMESTAMP     NULL DEFAULT NULL,
                    KEY idx_potencia_dia_area (`date`, `area`),
                    KEY idx_potencia_deleted (deleted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Throwable $e) {}

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS retrabalho_configuracoes (
                    chave VARCHAR(50) NOT NULL PRIMARY KEY,
                    valor VARCHAR(255) NOT NULL,
                    descricao VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
                INSERT IGNORE INTO retrabalho_configuracoes (chave, valor, descricao) VALUES
                ('custo_hora_homem', '45.00', 'Custo médio da hora de trabalho para retrabalho (R$/h)'),
                ('horas_trabalho_dia', '8.80', 'Horas úteis de expediente padrão por dia')
            ");
        } catch (\Throwable $e) {}

        try {
            $pdo->exec("ALTER TABLE reprovas ADD COLUMN tempo_padrao_minutos INT NOT NULL DEFAULT 60 AFTER setor_causador");
        } catch (\Throwable $e) {}

        try {
            $pdo->exec("ALTER TABLE retrabalho_materiais_catalogo ADD COLUMN custo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER unidade");
        } catch (\Throwable $e) {}
    }

    return $pdo;
}

/**
 * Conexão singleton PDO com o SQL Server (vsat.trael.local / vsattrael).
 * Retorna null se o servidor estiver inacessível (sem interromper o sistema).
 */
function getSqlServerDB(): ?PDO
{
    static $pdoSrv = null;
    static $tentou = false;

    if ($pdoSrv !== null) {
        return $pdoSrv;
    }
    if ($tentou) {
        return null;
    }
    $tentou = true;

    $env = _GFT_ENV;
    if (empty($env['SQLSRV_HOST']) || empty($env['SQLSRV_USER'])) {
        return null;
    }

    $driver = $env['SQLSRV_DRIVER'] ?? 'SQL Server Native Client 11.0';
    $host   = $env['SQLSRV_HOST'];
    $db     = $env['SQLSRV_DB'];
    $user   = $env['SQLSRV_USER'];
    $pass   = $env['SQLSRV_PASS'];

    $dsn = "odbc:Driver={{$driver}};Server={$host};Database={$db};";

    try {
        $pdoSrv = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 4,
        ]);
        return $pdoSrv;
    } catch (\Throwable $e) {
        error_log('Erro ao conectar ao SQL Server: ' . $e->getMessage());
        return null;
    }
}
