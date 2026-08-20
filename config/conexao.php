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
    ];

    define('_GFT_ENV', $config);

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

    // Auto-verificação de esquema para colunas novas essenciais (evita erro 1054 em produção/Railway)
    static $schemaChecked = false;
    if (!$schemaChecked) {
        $schemaChecked = true;
        try {
            // 1. preco_medio em itens_catalogo
            $colItens = $pdo->query("
                SELECT COUNT(*) FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'itens_catalogo' AND COLUMN_NAME = 'preco_medio'
            ")->fetchColumn();
            if (!$colItens) {
                $pdo->exec("ALTER TABLE itens_catalogo ADD COLUMN preco_medio DECIMAL(14,4) NULL DEFAULT NULL AFTER unidade");
            }
        } catch (\Throwable $e) {}

        try {
            // 2. preco_medio em retrabalho_material_uso
            $colUso = $pdo->query("
                SELECT COUNT(*) FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retrabalho_material_uso' AND COLUMN_NAME = 'preco_medio'
            ")->fetchColumn();
            if (!$colUso) {
                $pdo->exec("ALTER TABLE retrabalho_material_uso ADD COLUMN preco_medio DECIMAL(14,4) NULL DEFAULT NULL AFTER quantidade");
            }
        } catch (\Throwable $e) {}
    }

    return $pdo;
}
