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
        'DB_HOST' => $getEnv('MYSQLHOST', $env['DB_HOST'] ?? 'mysql.railway.internal'),
        'DB_NAME' => $getEnv('MYSQLDATABASE', $env['DB_NAME'] ?? 'railway'),
        'DB_USER' => $getEnv('MYSQLUSER', $env['DB_USER'] ?? 'root'),
        'DB_PASS' => $getEnv('MYSQLPASSWORD', $env['DB_PASS'] ?? ''),
        'DB_PORT' => $getEnv('MYSQLPORT', $env['DB_PORT'] ?? '3306'),

        'APP_URL' => $getEnv('APP_URL', $env['APP_URL'] ?? ''),
        'APP_ENV' => $getEnv('APP_ENV', $env['APP_ENV'] ?? 'local'),
        'APP_TIMEZONE' => $getEnv('APP_TIMEZONE', $env['APP_TIMEZONE'] ?? 'America/Cuiaba'),
    ];

    define('_GFT_ENV', $config);

    // Detectar APP_URL dinamicamente se não definido
    $appUrl = $config['APP_URL'];
    if (empty($appUrl) || (strpos($appUrl, 'localhost') !== false && isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== 'localhost')) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $baseDir = str_replace('\\', '/', dirname($scriptPath));

        if (strpos($scriptPath, '/config/') !== false) {
            $baseDir = str_replace('\\', '/', dirname(dirname($scriptPath)));
        }

        $baseDir = rtrim($baseDir, '/');
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

    return $pdo;
}
