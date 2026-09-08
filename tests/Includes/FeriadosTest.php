<?php
declare(strict_types=1);

namespace Tests\Includes;

use Tests\TestCase;

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/boletim-painel-producao.php';

final class FeriadosTest extends TestCase
{
    public function test_obter_feriados_ano_inclui_nacionais_mt_e_cuiaba(): void
    {
        $feriados2026 = boletimObterFeriadosAno(2026);

        // Feriados Nacionais Fixos
        $this->assertArrayHasKey('2026-01-01', $feriados2026);
        $this->assertArrayHasKey('2026-04-21', $feriados2026);
        $this->assertArrayHasKey('2026-05-01', $feriados2026);
        $this->assertArrayHasKey('2026-09-07', $feriados2026);
        $this->assertArrayHasKey('2026-10-12', $feriados2026);
        $this->assertArrayHasKey('2026-11-02', $feriados2026);
        $this->assertArrayHasKey('2026-11-15', $feriados2026);
        $this->assertArrayHasKey('2026-11-20', $feriados2026); // Consciência Negra (Nacional / MT)
        $this->assertArrayHasKey('2026-12-25', $feriados2026);

        // Feriados Móveis 2026
        $this->assertArrayHasKey('2026-02-16', $feriados2026); // Carnaval Seg
        $this->assertArrayHasKey('2026-02-17', $feriados2026); // Carnaval Ter
        $this->assertArrayHasKey('2026-04-03', $feriados2026); // Sexta-feira Santa
        $this->assertArrayHasKey('2026-06-04', $feriados2026); // Corpus Christi

        // Feriados Locais Cuiabá - MT
        $this->assertArrayHasKey('2026-04-08', $feriados2026); // Aniversário de Cuiabá
        $this->assertArrayHasKey('2026-12-08', $feriados2026); // N. Sra. da Conceição
    }

    public function test_boletim_is_feriado(): void
    {
        $this->assertTrue(boletimIsFeriado('2026-09-07'));
        $this->assertFalse(boletimIsFeriado('2026-09-08'));
        $this->assertSame('Independência do Brasil (Feriado Nacional)', boletimObterNomeFeriado('2026-09-07'));
    }

    public function test_boletim_dias_uteis_do_mes_setembro_2026_retorna_21(): void
    {
        $diasUteis = boletimDiasUteisDoMes('2026-09');
        $this->assertSame(21, $diasUteis, 'Setembro de 2026 tem 22 dias de semana menos 1 feriado (07/09) = 21 dias úteis');
    }

    public function test_aderencia_mensal_exclui_feriado_do_grafico_quando_sem_producao(): void
    {
        $dados = boletimCalcularAderenciaMensal(2026, 9, 1, 'CONSOLIDADO', 'TODOS');

        $this->assertSame(21, $dados['kpis']['total_dias_uteis']);
        $this->assertArrayHasKey('2026-09-07', $dados['feriados_mes']);

        $datasNoGrafico = array_column($dados['evolucao_diaria'], 'data');
        $this->assertNotContains('2026-09-07', $datasNoGrafico, 'Dia 07/09 não deve aparecer como dia útil vazio no gráfico diário');
    }
}
