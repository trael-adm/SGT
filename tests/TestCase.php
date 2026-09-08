<?php
declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base de todos os testes de integração do SGT. Cada teste roda dentro de uma
 * transação aberta no setUp() e sempre desfeita (ROLLBACK) no tearDown() —
 * nunca grava dado de teste de verdade no banco de dev compartilhado, mesmo
 * que o código sob teste faça INSERT/UPDATE.
 *
 * getDB() é um singleton (ver config/conexao.php) — a mesma conexão PDO é
 * reaproveitada entre os testes da suíte inteira, então a transação por teste
 * é a única coisa que garante isolamento entre um teste e o outro.
 */
abstract class TestCase extends BaseTestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = getDB();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }
}
