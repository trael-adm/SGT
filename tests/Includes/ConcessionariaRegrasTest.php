<?php
declare(strict_types=1);

namespace Tests\Includes;

use Tests\TestCase;

/**
 * Cobre includes/concessionaria-regras.php — o "cérebro" de qual regra de
 * validação o Paint Check aplica pra cada transformador. Testado em
 * integração (banco de dev real, transação com rollback — ver TestCase),
 * não em unidade com mocks: o comportamento que mais importa aqui (casar
 * apelido, cair no fallback certo) depende diretamente do schema e dos dados
 * reais cadastrados em concessionaria_regras / concessionaria_regras_apelidos.
 */
final class ConcessionariaRegrasTest extends TestCase
{
    public function test_normaliza_removendo_acento_e_colapsando_espacos(): void
    {
        $resultado = normalizarNomeConcessionaria('Equatorial   Pará Distribuidora de Energia S.A');

        // Não faz assert de igualdade exata contra uma string com acento
        // transliterado: a implementação de iconv//TRANSLIT varia entre
        // plataformas (Windows dev vs Linux em produção) e pode gerar um
        // separador extra onde estava o acento — o que importa aqui é que o
        // resultado fica em maiúsculas, sem espaços duplicados e sem os
        // caracteres acentuados originais.
        $this->assertSame(mb_strtoupper($resultado), $resultado, 'deve estar em maiúsculas');
        $this->assertDoesNotMatchRegularExpression('/\s{2,}/', $resultado, 'não deve ter espaços duplicados');
        $this->assertStringNotContainsString('Á', $resultado);
        $this->assertStringStartsWith('EQUATORIAL', $resultado);
        $this->assertStringContainsString('DISTRIBUIDORA DE ENERGIA', $resultado);
    }

    public function test_apelido_exato_encontra_a_concessionaria(): void
    {
        $regra = buscarRegraConcessionaria('EQTL');

        $this->assertSame('Equatorial', $regra['nome_grupo']);
        $this->assertFalse($regra['fallback']);
    }

    public function test_apelido_como_substring_do_nome_do_vsat_tambem_casa(): void
    {
        // Nome real do VSAT costuma vir com sufixo/prefixo em volta do apelido
        // cadastrado — ver comentário de buscarRegraConcessionaria() sobre
        // casamento nas duas direções.
        $regra = buscarRegraConcessionaria('EQTL DISTRIBUIDORA DE ENERGIA LTDA');

        $this->assertSame('Equatorial', $regra['nome_grupo']);
    }

    public function test_apelido_cadastrado_como_nome_de_transportadora_casa(): void
    {
        // Confirmado no sistema: a CEMIG aparece no VSAT como a transportadora
        // "Arrow Transportes e Logistica Ltda", não como "CEMIG".
        $regra = buscarRegraConcessionaria('Arrow Transportes e Logistica Ltda');

        $this->assertSame('CEMIG', $regra['nome_grupo']);
    }

    public function test_nome_sem_apelido_cadastrado_cai_no_fallback_particular(): void
    {
        $regra = buscarRegraConcessionaria('Cliente Totalmente Desconhecido XYZ999');

        $this->assertSame('PARTICULAR', $regra['nome_grupo']);
        $this->assertTrue($regra['fallback']);
    }

    public function test_nome_vazio_cai_no_fallback_particular(): void
    {
        $regra = buscarRegraConcessionaria('');

        $this->assertSame('PARTICULAR', $regra['nome_grupo']);
        $this->assertTrue($regra['fallback']);
    }

    public function test_equatorial_exige_patrimonio_na_tampa_e_no_tanque(): void
    {
        // Regressão do bug corrigido nesta sessão: o formulário antigo (select
        // de valor único) não conseguia representar as duas localizações.
        $regra = buscarRegraConcessionaria('EQTL');

        $this->assertContains('tanque', $regra['local_codigo_adicional']);
        $this->assertContains('tampa', $regra['local_codigo_adicional']);
    }

    public function test_regra_fallback_devolve_estrutura_completa_mesmo_sem_linha_particular(): void
    {
        // Simula o caminho "linha PARTICULAR sumiu do banco" chamando direto
        // a função que monta esse fallback embutido no código.
        $regra = carregarLocaisObrigatorios([
            'ativo' => 1,
            'local_codigo_adicional' => 'tanque',
            'local_potencia' => 'tampa,tanque',
            'locais_obrigatorios' => 'tampa,tanque,gancho',
        ]);

        $this->assertTrue($regra['ativo']);
        $this->assertSame(['tanque'], $regra['local_codigo_adicional']);
        $this->assertSame(['tampa', 'tanque'], $regra['local_potencia']);
        $this->assertSame(['tampa', 'tanque', 'gancho'], $regra['locais_obrigatorios']);
    }
}
