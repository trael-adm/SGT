<?php
declare(strict_types=1);

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/planilha-plano-mestre.php';

final class PlanilhaPlanoMestreTest extends TestCase
{
    public function test_converter_data(): void
    {
        $this->assertSame('2026-09-10', planoMestreConverterData(null, 2026, 9, 10));
        $this->assertSame('2026-08-15', planoMestreConverterData('2026-08-15 14:30:00.000'));
        $this->assertSame('2026-05-20', planoMestreConverterData('20/05/2026'));
        $this->assertNull(planoMestreConverterData('invalido'));
    }

    public function test_coluna_para_indice(): void
    {
        $this->assertSame(0, planoMestreColunaParaIndice('A1'));
        $this->assertSame(1, planoMestreColunaParaIndice('B12'));
        $this->assertSame(25, planoMestreColunaParaIndice('Z5'));
        $this->assertSame(26, planoMestreColunaParaIndice('AA1'));
        $this->assertSame(39, planoMestreColunaParaIndice('AN2'));
    }

    public function test_processar_registro_distribuicao_enr_por_potencia(): void
    {
        $resultado = [
            'porDia' => [],
            'porDiaTotal' => [],
            'porDiaNucleo' => [],
            'totaisMes' => [],
            'totaisMesGeral' => [],
            'itens' => [],
            'porDiaItens' => [],
        ];

        $row = [
            'Quantidade' => '3',
            'DataHoraProducaoAux' => '2026-09-15',
            'cd_Referencia' => 'TPD-12345',
            'ds_Prod' => 'TRAFO 15kVA',
            'PotenciaKVA' => '15',
            'ds_TpEnrolamentoNucleo' => 'JC',
            'TotalJC' => '3',
            'cdEntEmpDesti' => '1',
            'QtdProduzida' => '2',
            'QtdAproduzir' => '1',
        ];

        planoMestreProcessarRegistro($resultado, $row);

        $this->assertSame(3.0, $resultado['porDiaTotal'][1]['2026-09-15']);
        $this->assertSame(3.0, $resultado['porDia'][1]['2026-09-15']['ENR']);
        $this->assertSame(3.0, $resultado['porDiaNucleo'][1]['2026-09-15']['ENR']);
        $this->assertSame(3.0, $resultado['totaisMesGeral'][1]['2026-09']);
        $this->assertSame(3.0, $resultado['totaisMes'][1]['2026-09']['ENR']);
        $this->assertCount(1, $resultado['porDiaItens'][1]['2026-09-15']);

        $item = $resultado['porDiaItens'][1]['2026-09-15'][0];
        $this->assertSame('ENR', $item['linha']);
        $this->assertSame('ENR', $item['nucleo']);
        $this->assertSame(3.0, $item['quantidade']);
        $this->assertSame(2.0, $item['qtd_produzida']);
        $this->assertSame(1.0, $item['qtd_a_produzir']);
    }

    public function test_processar_registro_media_forca(): void
    {
        $resultado = [
            'porDia' => [],
            'porDiaTotal' => [],
            'porDiaNucleo' => [],
            'totaisMes' => [],
            'totaisMesGeral' => [],
            'itens' => [],
            'porDiaItens' => [],
        ];

        $row = [
            'Quantidade' => '1',
            'DataHoraProducaoAux' => '2026-09-20',
            'cd_Referencia' => 'TPM-55555',
            'ds_Prod' => 'TRAFO DE FORCA',
            'PotenciaKVA' => '1000',
            'cdEntEmpDesti' => '4',
            'QtdProduzida' => '0',
            'QtdAproduzir' => '1',
        ];

        planoMestreProcessarRegistro($resultado, $row);

        $this->assertSame(1.0, $resultado['porDiaTotal'][4]['2026-09-20']);
        $this->assertSame(1.0, $resultado['porDia'][4]['2026-09-20']['TPM']);
        $this->assertSame('TPM', $resultado['porDiaItens'][4]['2026-09-20'][0]['linha']);
    }

    public function test_processar_registro_distribuicao_jc_e_emp(): void
    {
        $resultado = [
            'porDia' => [],
            'porDiaTotal' => [],
            'porDiaNucleo' => [],
            'totaisMes' => [],
            'totaisMesGeral' => [],
            'itens' => [],
            'porDiaItens' => [],
        ];

        // 1. JC com 75 kVA (não cai na exceção 5/10/15)
        $rowJc = [
            'Quantidade' => '2',
            'DataHoraProducaoAux' => '2026-09-15',
            'cd_Referencia' => 'TPD-99999',
            'ds_Prod' => 'TRAFO 75kVA',
            'PotenciaKVA' => '75',
            'ds_TpEnrolamentoNucleo' => 'JC',
            'TotalJC' => '2',
            'cdEntEmpDesti' => '1',
            'QtdProduzida' => '2',
            'QtdAproduzir' => '0',
        ];
        planoMestreProcessarRegistro($resultado, $rowJc);

        $this->assertSame(2.0, $resultado['porDiaTotal'][1]['2026-09-15']);
        $this->assertSame(2.0, $resultado['porDia'][1]['2026-09-15']['JC']);
        $this->assertSame('JC', $resultado['porDiaItens'][1]['2026-09-15'][0]['linha']);

        // 2. EMP com 112.5 kVA
        $rowEmp = [
            'Quantidade' => '1',
            'DataHoraProducaoAux' => '2026-09-15',
            'cd_Referencia' => 'TPD-88888',
            'ds_Prod' => 'TRAFO 112.5kVA',
            'PotenciaKVA' => '112.5',
            'ds_TpEnrolamentoNucleo' => 'EMP',
            'TotalEMP' => '1',
            'cdEntEmpDesti' => '1',
            'QtdProduzida' => '0',
            'QtdAproduzir' => '1',
        ];
        planoMestreProcessarRegistro($resultado, $rowEmp);

        $this->assertSame(3.0, $resultado['porDiaTotal'][1]['2026-09-15']);
        $this->assertSame(1.0, $resultado['porDia'][1]['2026-09-15']['EMP']);
        $this->assertSame(2.0, $resultado['porDia'][1]['2026-09-15']['JC']);
    }

    public function test_funcoes_consulta_plano_mestre(): void
    {
        $dados = planoMestreCarregar(false);
        $this->assertIsArray($dados);
        $this->assertArrayHasKey('porDiaTotal', $dados);
        $this->assertArrayHasKey('porDia', $dados);
        $this->assertArrayHasKey('porDiaNucleo', $dados);

        // Se houver dados carregados (cache ou live)
        if (!empty($dados['porDiaTotal'][1])) {
            $primeiroDia = array_key_first($dados['porDiaTotal'][1]);
            $progDia = planoMestreObterProgramadoDia($primeiroDia, 1, 'TODOS');
            $this->assertGreaterThan(0, $progDia);

            $itens = planoMestreObterItensDia($primeiroDia, 1, 'TODOS');
            $this->assertNotEmpty($itens);
        }
    }

    public function test_multiplicador_bobinas_por_fase(): void
    {
        // Monofásico / Bifásico = 2 bobinas por transformador
        $this->assertSame(2, planoMestreCalcularMultiplicadorBobinas('MON'));
        $this->assertSame(2, planoMestreCalcularMultiplicadorBobinas('BIF'));
        $this->assertSame(2, planoMestreCalcularMultiplicadorBobinas('1F'));
        $this->assertSame(2, planoMestreCalcularMultiplicadorBobinas('2F'));

        // Trifásico = 3 bobinas por transformador
        $this->assertSame(3, planoMestreCalcularMultiplicadorBobinas('TRI'));
        $this->assertSame(3, planoMestreCalcularMultiplicadorBobinas('3F'));
        $this->assertSame(3, planoMestreCalcularMultiplicadorBobinas('', 'TRANSFORMADOR TRIFÁSICO 75kVA'));

        // Exemplo da regra do usuário: 2 peças bifásicas = 4 bobinas, 1 peça trifásica = 3 bobinas
        $pecasBifasicas = 2;
        $bobinasBifasicas = $pecasBifasicas * planoMestreCalcularMultiplicadorBobinas('BIF');
        $this->assertSame(4, $bobinasBifasicas);

        $pecasTrifasicas = 1;
        $bobinasTrifasicas = $pecasTrifasicas * planoMestreCalcularMultiplicadorBobinas('TRI');
        $this->assertSame(3, $bobinasTrifasicas);
    }
}

