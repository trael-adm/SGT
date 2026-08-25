<?php
declare(strict_types=1);

/**
 * Conexão com o SQL Server (vsat.trael.local / vsattrael)
 * Módulo Standalone: Fluxo do Pedido & Acompanhamento de Produção
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

    $host   = 'vsat.trael.local';
    $db     = 'vsattrael';
    $user   = 'bi_consulta';
    $pass   = 'YtowDn2zp5CvuhNO1vtM';
    $driver = 'SQL Server Native Client 11.0';

    $dsn = "odbc:Driver={{$driver}};Server={$host};Database={$db};";

    try {
        $pdoSrv = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 60,
        ]);
        return $pdoSrv;
    } catch (Throwable $e) {
        error_log('Erro ao conectar ao SQL Server: ' . $e->getMessage());
        return null;
    }
}

/**
 * Garante que todos os textos e arrays estejam em UTF-8 válido para json_encode
 */
function sanitizarUtf8Recursivo(mixed $dado): mixed
{
    if (is_string($dado)) {
        if (!mb_check_encoding($dado, 'UTF-8')) {
            $convertido = @mb_convert_encoding($dado, 'UTF-8', 'Windows-1252');
            return is_string($convertido) ? $convertido : utf8_encode($dado);
        }
        return $dado;
    }
    if (is_array($dado)) {
        $saida = [];
        foreach ($dado as $k => $v) {
            $chaveSanitizada = is_string($k) ? sanitizarUtf8Recursivo($k) : $k;
            $saida[$chaveSanitizada] = sanitizarUtf8Recursivo($v);
        }
        return $saida;
    }
    return $dado;
}
