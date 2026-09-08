<?php
declare(strict_types=1);

/**
 * Bootstrap dos testes PHPUnit. O projeto não usa autoload PSR-4 (PHP vanilla,
 * require_once) — aqui carregamos manualmente os arquivos de config/include
 * que os testes vão precisar, na mesma ordem que uma página real do SGT faria.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/concessionaria-regras.php';

require_once __DIR__ . '/TestCase.php';
