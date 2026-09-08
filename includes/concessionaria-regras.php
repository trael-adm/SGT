<?php
declare(strict_types=1);

/**
 * Regras de validação de serigrafia/puncionamento por concessionária
 * (Paint Check). Fonte: tabelas `concessionaria_regras` / `concessionaria_regras_apelidos`
 * (ver _inicial/migrar-concessionaria-regras.sql).
 *
 * O nome do cliente que chega do VSAT/planilha (`NomeCli`) é o nome de cadastro
 * do sistema de produção, não necessariamente o nome oficial da concessionária —
 * ex.: a Cemig aparece cadastrada como "Arrow Transportes e Logistica Ltda"
 * (transportadora), a Equatorial como "EQTL". O casamento é feito contra a
 * lista de apelidos, não contra o `nome_grupo` diretamente.
 */

/** Normaliza um nome de cliente/apelido para comparação: maiúsculas, sem acento, espaços colapsados. */
function normalizarNomeConcessionaria(string $nome): string
{
    $nome = mb_strtoupper(trim($nome), 'UTF-8');
    $transliterado = @iconv('UTF-8', 'ASCII//TRANSLIT', $nome);
    if ($transliterado !== false) {
        $nome = $transliterado;
    }
    $nome = preg_replace('/[^A-Z0-9 ]/', ' ', $nome) ?? $nome;
    $nome = preg_replace('/\s+/', ' ', $nome) ?? $nome;
    return trim($nome);
}

/**
 * Busca a regra de validação da concessionária a partir do nome de cliente vindo
 * do VSAT/planilha. Casa contra `concessionaria_regras_apelidos.apelido`
 * (normalizado) por igualdade ou substring, nas duas direções — cobre tanto
 * apelidos curtos batendo dentro de nomes compostos ("EQTL" dentro de
 * "EQTL DISTRIBUIDORA...") quanto nomes compostos cadastrados como apelido
 * batendo num nome de cliente mais curto vindo do VSAT.
 *
 * Sem match → devolve a regra `PARTICULAR` (padrão ABNT), nunca null — todo
 * cliente tem uma regra aplicável, mesmo que genérica.
 */
function buscarRegraConcessionaria(string $nomeCliente): array
{
    $pdo = getDB();
    $alvo = normalizarNomeConcessionaria($nomeCliente);

    if ($alvo !== '') {
        $stmt = $pdo->query("
            SELECT r.*, a.apelido
            FROM concessionaria_regras r
            JOIN concessionaria_regras_apelidos a ON a.id_regra = r.id
            WHERE r.deleted_at IS NULL AND r.ativo = 1
        ");
        foreach ($stmt->fetchAll() as $linha) {
            $apelidoNormalizado = normalizarNomeConcessionaria((string) $linha['apelido']);
            if ($apelidoNormalizado === '') {
                continue;
            }
            if (str_contains($alvo, $apelidoNormalizado) || str_contains($apelidoNormalizado, $alvo)) {
                unset($linha['apelido']);
                return carregarLocaisObrigatorios($linha);
            }
        }
    }

    $stmtFallback = $pdo->prepare("
        SELECT * FROM concessionaria_regras
        WHERE nome_grupo = 'PARTICULAR' AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmtFallback->execute();
    $particular = $stmtFallback->fetch();

    if (!$particular) {
        // Nunca deveria acontecer (linha PARTICULAR é seed obrigatório), mas evita
        // fatal error em ambiente onde a migração ainda não rodou.
        return [
            'id' => null,
            'nome_grupo' => 'PARTICULAR',
            'norma_referencia' => null,
            'local_codigo_adicional' => ['tanque'],
            'local_potencia' => ['tanque'],
            'exige_elo_fusivel' => false,
            'locais_obrigatorios' => ['tampa', 'tanque', 'gancho'],
            'observacoes' => null,
            'ativo' => true,
            'fallback' => true,
        ];
    }

    return carregarLocaisObrigatorios($particular, true);
}

/** Converte as colunas SET (string "tampa,tanque") em array PHP e tipa os booleanos. */
function carregarLocaisObrigatorios(array $regra, bool $ehFallback = false): array
{
    $regra['ativo'] = (bool) ($regra['ativo'] ?? true);
    $regra['local_codigo_adicional'] = array_filter(explode(',', (string) ($regra['local_codigo_adicional'] ?? '')));
    $regra['local_potencia'] = array_filter(explode(',', (string) ($regra['local_potencia'] ?? 'tanque')));
    $regra['exige_elo_fusivel'] = (bool) ($regra['exige_elo_fusivel'] ?? false);
    $regra['locais_obrigatorios'] = array_filter(explode(',', (string) ($regra['locais_obrigatorios'] ?? '')));
    $regra['fallback'] = $ehFallback;
    return $regra;
}
