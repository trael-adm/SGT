<?php
declare(strict_types=1);

/**
 * Funções Auxiliares e Auto-Migração do Módulo SOMA (Cronoanálise Industrial)
 */

require_once __DIR__ . '/../config/conexao.php';

function somaGarantirTabelas(?PDO $db = null): void
{
    static $verificado = false;
    if ($verificado) {
        return;
    }
    $verificado = true;

    if ($db === null) {
        $db = getDB();
    }

    try {
        $tem = $db->query("SHOW TABLES LIKE 'soma_turnos'")->fetch();
        if (!$tem) {
            $sqlPath = __DIR__ . '/../_inicial/migrar-soma.sql';
            if (is_file($sqlPath)) {
                $sql = (string) file_get_contents($sqlPath);
                $db->exec($sql);
            }
        }

        // Garante os 33 motivos oficiais da folha Trael (Controle de Produção Individual)
        $motivosOficiais = [
            ['38', 'FALTA DE MATERIAL FIO', 'NAO_PROG'],
            ['40', 'FALTA MATERIAL PAPEL', 'NAO_PROG'],
            ['34', 'FALTA MATERIAL ACESSORIOS', 'NAO_PROG'],
            ['95', 'RH', 'PROG'],
            ['10', 'AMBULATÓRIO', 'PROG'],
            ['63', 'LIMPEZA DE MÁQUINA', 'PROG'],
            ['94', 'REUNIÃO C/LÍDER', 'PROG'],
            ['41', 'FALTA DE MATERIAL BT', 'NAO_PROG'],
            ['4', 'AJUSTE DE FORMA', 'PROG'],
            ['55', 'FIO REPROVADO', 'NAO_PROG'],
            ['118', 'TROCA DE PROJETO', 'PROG'],
            ['9', 'ALMOÇO', 'PROG'],
            ['125', 'MANUTENÇÃO', 'NAO_PROG'],
            ['96', 'SAÍDA ANTECIPADA', 'PROG'],
            ['12', 'ATRASO OP.', 'NAO_PROG'],
            ['43', 'FALTA OP.', 'NAO_PROG'],
            ['74', 'MUDANÇA SETOR', 'PROG'],
            ['122', 'RETRABALHO', 'NAO_PROG'],
            ['101', 'SESMT', 'PROG'],
            ['114', 'TROCA DE FIO', 'PROG'],
            ['85', 'PROTÓTIPO', 'PROG'],
            ['60', 'LANCHE/CAFÉ', 'PROG'],
            ['73', 'MUDANÇA DE MAQUINA', 'PROG'],
            ['107', 'TIRAR BOBINA', 'PROG'],
            ['88', 'REPROVEITAMENTO', 'PROG'],
            ['18', 'EM OUTRA FUNÇÃO NO SETOR', 'PROG'],
            ['3', 'AFERIÇÃO DE PROJETO', 'PROG'],
            ['21', 'ENCOLDER', 'PROG'],
            ['102', 'SOLDA', 'PROG'],
            ['2', 'AFERIÇÃO DE MATERIAL', 'PROG'],
            ['23', 'EXAME PERIÓDICO', 'PROG'],
            ['1', 'REFORMA', 'PROG'],
            ['110', 'TREINAMENTO INTERNO', 'PROG'],
        ];

        $stmtIns = $db->prepare("INSERT INTO soma_paradas_motivos (cod, descricao, tipo, ativo) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE descricao = VALUES(descricao), tipo = VALUES(tipo)");
        foreach ($motivosOficiais as $m) {
            $stmtIns->execute($m);
        }
    } catch (Throwable $e) {
        error_log('Erro ao garantir tabelas SOMA: ' . $e->getMessage());
    }
}
