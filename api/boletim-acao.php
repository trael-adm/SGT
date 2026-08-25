<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');

// ─── Autenticação e RBAC ────────────────────────────────────────────────────
// Visualizador (4) só lê os dashboards — nunca pode registrar/editar/excluir.
// Checagem real no servidor (corrige o RBAC cosmético do protótipo antigo).
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado']);
    exit;
}

$usuario = currentUser();
$acao    = trim((string) ($_POST['acao'] ?? $_GET['acao'] ?? ''));
$userId  = (int) ($usuario['id'] ?? 0);

// Ações somente de leitura permitidas para todos os usuários logados (incluindo perfil 4)
$acoesLeitura = ['buscar_detalhes_pecas'];

if (!in_array($acao, $acoesLeitura, true) && !in_array((int) ($usuario['id_perfil'] ?? 0), [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Você não tem permissão para esta ação.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !in_array($acao, $acoesLeitura, true)) {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método não permitido']);
    exit;
}

const BOLETIM_AREAS  = ['distrib', 'forca'];
const BOLETIM_LINHAS = ['TPD', 'TPM', 'TPS'];
const BOLETIM_CORES  = ['ENR', 'JC', 'EMP', 'LAB'];

/**
 * Valida e normaliza os campos comuns de um registro de produção vindos do POST.
 * Retorna ['erro' => string|null, ...campos] — $erro não-nulo interrompe o chamador.
 *
 * Núcleo (core_type) só se aplica à Distribuição (area='distrib') — na Força/Seco
 * o lançamento é sempre um total da linha, sem quebra por núcleo, mesmo quando é
 * TPD rodando na linha de Média Força (ver PROJETO-BOLETIM.md > "Banco de dados").
 */
function boletimValidarRegistro(array $post): array
{
    $data        = trim((string) ($post['date'] ?? ''));
    $area        = trim((string) ($post['area'] ?? ''));
    $line        = trim((string) ($post['line'] ?? ''));
    $coreTypeRaw = trim((string) ($post['core_type'] ?? ''));
    $prog        = $post['prog'] ?? null;
    $real        = $post['real'] ?? null;
    $descricao   = trim((string) ($post['description'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !strtotime($data)) {
        return ['erro' => 'Data inválida.'];
    }
    if (!in_array($area, BOLETIM_AREAS, true)) {
        return ['erro' => 'Área inválida.'];
    }
    if (!in_array($line, BOLETIM_LINHAS, true)) {
        return ['erro' => 'Linha inválida.'];
    }

    // LAB não é um núcleo de verdade — é o marcador de reprovas de laboratório,
    // e por isso é o único valor de core_type aceito também para area='forca'
    // (reprovas da Média Força/Seco, ver PROJETO-BOLETIM.md > "Regras de negócio
    // herdadas"). ENR/JC/EMP continuam exclusivos da Distribuição.
    $coreType = null;
    if ($area === 'distrib') {
        if (!in_array($coreTypeRaw, BOLETIM_CORES, true)) {
            return ['erro' => 'Núcleo inválido.'];
        }
        $coreType = $coreTypeRaw;
    } elseif ($area === 'forca') {
        if ($coreTypeRaw !== '' && $coreTypeRaw !== 'LAB') {
            return ['erro' => 'Núcleo inválido para Média Força/Seco — só reprovas (LAB) usam esse campo aqui.'];
        }
        $coreType = $coreTypeRaw === 'LAB' ? 'LAB' : null;
    }

    if (!is_numeric($prog) || (int) $prog < 0) {
        return ['erro' => 'Programado deve ser um número maior ou igual a zero.'];
    }
    if (!is_numeric($real) || (int) $real < 0) {
        return ['erro' => 'Realizado deve ser um número maior ou igual a zero.'];
    }

    return [
        'erro'        => null,
        'date'        => $data,
        'area'        => $area,
        'line'        => $line,
        'core_type'   => $coreType,
        'prog'        => (int) $prog,
        'real'        => (int) $real,
        'description' => $descricao !== '' ? $descricao : null,
    ];
}

/**
 * Valida a potência média (kVA) de um dia — um valor por dia/área, somando
 * todos os núcleos (não quebrado por ENR/JC/EMP, ver "Layout de referência"
 * em PROJETO-BOLETIM.md). Independente de boletim_registros.
 */
function boletimValidarPotencia(array $post): array
{
    $data      = trim((string) ($post['date'] ?? ''));
    $area      = trim((string) ($post['area'] ?? ''));
    $potencia  = $post['potencia_media'] ?? null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !strtotime($data)) {
        return ['erro' => 'Data inválida.'];
    }
    if (!in_array($area, BOLETIM_AREAS, true)) {
        return ['erro' => 'Área inválida.'];
    }
    if (!is_numeric($potencia) || (float) $potencia <= 0) {
        return ['erro' => 'Potência média deve ser um número maior que zero.'];
    }

    return [
        'erro'           => null,
        'date'           => $data,
        'area'           => $area,
        'potencia_media' => round((float) $potencia, 2),
    ];
}

/**
 * Valida os campos de meta mensal + calendário de um mês (Sprint 6 — Configurações).
 * Um registro por mês (`month` é chave primária) — sempre os dois campos de meta
 * de TPD, mesmo que um fique zerado (ver PROJETO-BOLETIM.md > "Banco de dados").
 */
function boletimValidarMetas(array $post): array
{
    $month = trim((string) ($post['month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        return ['erro' => 'Mês inválido.'];
    }

    // meta_total ("meta entre empresas") não existe na operação real — sem
    // função, campo ocultado em Configurações (decisão do usuário em
    // 18/08/2026) e fora da validação/gravação daqui em diante. dias_uteis/
    // dias_trabalhados também saíram daqui — calendário automático (mesma
    // data), calculado em includes/helpers.php, nunca mais digitado.
    $campos = ['meta_tpm', 'meta_tpd_distribuicao', 'meta_enrolado', 'meta_convencional',
               'meta_jctrif', 'meta_tpd_forca', 'meta_tps'];
    $out = ['erro' => null, 'month' => $month];
    foreach ($campos as $campo) {
        $v = $post[$campo] ?? null;
        if (!is_numeric($v) || (int) $v < 0) {
            return ['erro' => 'Todos os campos de meta devem ser números maiores ou iguais a zero.'];
        }
        $out[$campo] = (int) $v;
    }

    return $out;
}

try {
    $pdo = getDB();

    switch ($acao) {

        // ─── Registrar novo lançamento de produção ─────────────────────────
        case 'registrar': {
            $r = boletimValidarRegistro($_POST);
            if ($r['erro'] !== null) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => $r['erro']]);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO boletim_registros
                    (`date`, `area`, line, core_type, prog, `real`, description, `origin`, id_criador)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'manual', ?)
            ");
            $stmt->execute([
                $r['date'], $r['area'], $r['line'], $r['core_type'],
                $r['prog'], $r['real'], $r['description'], $userId,
            ]);

            echo json_encode(['sucesso' => true, 'mensagem' => 'Registro salvo.', 'id' => (int) $pdo->lastInsertId()]);
            break;
        }

        // ─── Editar um lançamento existente ─────────────────────────────────
        case 'editar': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro inválido.']);
                exit;
            }

            $r = boletimValidarRegistro($_POST);
            if ($r['erro'] !== null) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => $r['erro']]);
                exit;
            }

            // Só edita registros de entrada manual — os importados da planilha-ponte
            // (Sprint 5) são somente leitura por aqui, para não divergir da fonte.
            $stmt = $pdo->prepare("
                UPDATE boletim_registros
                SET `date` = ?, `area` = ?, line = ?, core_type = ?, prog = ?, `real` = ?, description = ?
                WHERE id = ? AND `origin` = 'manual' AND deleted_at IS NULL
            ");
            $stmt->execute([
                $r['date'], $r['area'], $r['line'], $r['core_type'],
                $r['prog'], $r['real'], $r['description'], $id,
            ]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro não encontrado ou não editável.']);
                exit;
            }

            echo json_encode(['sucesso' => true, 'mensagem' => 'Registro atualizado.']);
            break;
        }

        // ─── Excluir (soft delete) um lançamento manual ─────────────────────
        case 'excluir': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro inválido.']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE boletim_registros SET deleted_at = NOW()
                WHERE id = ? AND `origin` = 'manual' AND deleted_at IS NULL
            ");
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro não encontrado ou não excluível.']);
                exit;
            }

            echo json_encode(['sucesso' => true, 'mensagem' => 'Registro excluído.']);
            break;
        }

        // ─── Registrar a potência média do dia (um valor por dia, todos os núcleos) ─
        case 'potencia_registrar': {
            $p = boletimValidarPotencia($_POST);
            if ($p['erro'] !== null) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => $p['erro']]);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO boletim_potencia_diaria (`date`, `area`, potencia_media, id_criador)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$p['date'], $p['area'], $p['potencia_media'], $userId]);

            echo json_encode(['sucesso' => true, 'mensagem' => 'Potência média salva.', 'id' => (int) $pdo->lastInsertId()]);
            break;
        }

        case 'potencia_editar': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro inválido.']);
                exit;
            }

            $p = boletimValidarPotencia($_POST);
            if ($p['erro'] !== null) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => $p['erro']]);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE boletim_potencia_diaria
                SET `date` = ?, `area` = ?, potencia_media = ?
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$p['date'], $p['area'], $p['potencia_media'], $id]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro não encontrado.']);
                exit;
            }

            echo json_encode(['sucesso' => true, 'mensagem' => 'Potência média atualizada.']);
            break;
        }

        case 'potencia_excluir': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro inválido.']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE boletim_potencia_diaria SET deleted_at = NOW()
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro não encontrado.']);
                exit;
            }

            echo json_encode(['sucesso' => true, 'mensagem' => 'Potência média excluída.']);
            break;
        }

        // ─── Configurações (Sprint 6) — só Administrador/Coordenador, mesma regra
        // de acesso da própria página (ver PROJETO-BOLETIM.md > "Autenticação e RBAC").
        case 'metas_salvar': {
            if (!in_array((int) ($usuario['id_perfil'] ?? 0), [1, 2], true)) {
                http_response_code(403);
                echo json_encode(['sucesso' => false, 'erro' => 'Apenas Administrador e Coordenador podem alterar Configurações.']);
                exit;
            }

            $m = boletimValidarMetas($_POST);
            if ($m['erro'] !== null) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => $m['erro']]);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO boletim_config_metas
                    (`month`, meta_tpm, meta_tpd_distribuicao, meta_enrolado, meta_convencional,
                     meta_jctrif, meta_tpd_forca, meta_tps)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    meta_tpm = VALUES(meta_tpm),
                    meta_tpd_distribuicao = VALUES(meta_tpd_distribuicao),
                    meta_enrolado = VALUES(meta_enrolado), meta_convencional = VALUES(meta_convencional),
                    meta_jctrif = VALUES(meta_jctrif), meta_tpd_forca = VALUES(meta_tpd_forca),
                    meta_tps = VALUES(meta_tps)
            ");
            $stmt->execute([
                $m['month'], $m['meta_tpm'], $m['meta_tpd_distribuicao'],
                $m['meta_enrolado'], $m['meta_convencional'], $m['meta_jctrif'],
                $m['meta_tpd_forca'], $m['meta_tps'],
            ]);

            echo json_encode(['sucesso' => true, 'mensagem' => 'Metas do mês salvas.']);
            break;
        }

        // ─── Salvar metas diárias da Distribuição (calculando o mês automaticamente) ──
        case 'metas_distrib_salvar': {
            if (!in_array((int) ($usuario['id_perfil'] ?? 0), [1, 2, 3], true)) {
                http_response_code(403);
                echo json_encode(['sucesso' => false, 'erro' => 'Você não tem permissão para alterar as metas.']);
                exit;
            }

            $month = trim((string) ($_POST['month'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Mês inválido.']);
                exit;
            }

            // Dias de produção selecionados pelo usuário (calendário interativo)
            $diasCustomizados = null;
            $diasProducaoPost = $_POST['dias_producao'] ?? null;
            if (is_string($diasProducaoPost)) {
                $diasProducaoPost = json_decode($diasProducaoPost, true);
            }

            if (is_array($diasProducaoPost) && !empty($diasProducaoPost)) {
                // Valida cada data
                $diasValidos = [];
                foreach ($diasProducaoPost as $dp) {
                    $dp = trim((string) $dp);
                    if (str_starts_with($dp, $month . '-') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dp)) {
                        $diasValidos[] = $dp;
                    }
                }
                sort($diasValidos);
                if (!empty($diasValidos)) {
                    $diasUteis = count($diasValidos);
                    $diasCustomizados = json_encode($diasValidos);
                } else {
                    require_once __DIR__ . '/../includes/helpers.php';
                    $diasUteis = boletimDiasUteisDoMes($month);
                }
            } else {
                require_once __DIR__ . '/../includes/helpers.php';
                $diasUteis = boletimDiasUteisDoMes($month);
            }

            if ($diasUteis <= 0) {
                $diasUteis = 22;
            }

            // Metas diárias informadas pelo supervisor
            $diaEnr = max(0.0, (float) ($_POST['meta_dia_enrolado'] ?? 0));
            $diaJc  = max(0.0, (float) ($_POST['meta_dia_jctrif'] ?? 0));
            $diaEmp = max(0.0, (float) ($_POST['meta_dia_convencional'] ?? 0));

            // Meta mensal calculada com base nos dias de produção selecionados
            $metaEnr = (int) round($diaEnr * $diasUteis);
            $metaJc  = (int) round($diaJc  * $diasUteis);
            $metaEmp = (int) round($diaEmp * $diasUteis);
            $metaTotal = $metaEnr + $metaJc + $metaEmp;

            $stmt = $pdo->prepare("
                INSERT INTO boletim_config_metas
                    (`month`, meta_tpd_distribuicao, meta_enrolado, meta_convencional, meta_jctrif, dias_uteis, dias_customizados)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    meta_tpd_distribuicao = VALUES(meta_tpd_distribuicao),
                    meta_enrolado = VALUES(meta_enrolado),
                    meta_convencional = VALUES(meta_convencional),
                    meta_jctrif = VALUES(meta_jctrif),
                    dias_uteis = VALUES(dias_uteis),
                    dias_customizados = VALUES(dias_customizados)
            ");
            $stmt->execute([$month, $metaTotal, $metaEnr, $metaEmp, $metaJc, $diasUteis, $diasCustomizados]);

            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Métricas e calendário salvos com sucesso. Meta do mês: ' . number_format($metaTotal, 0, ',', '.') . ' un (' . $diasUteis . ' dias de produção).',
                'meta_total' => $metaTotal,
                'meta_enrolado' => $metaEnr,
                'meta_jctrif' => $metaJc,
                'meta_convencional' => $metaEmp,
                'dias_uteis' => $diasUteis,
            ]);
            break;
        }

        // ─── Salvar metas diárias da Média Força/Seco (espelha metas_distrib_salvar,
        // mesmo calendário de dias de produção — é único por mês, compartilhado com
        // a Distribuição) ──────────────────────────────────────────────────────────
        case 'metas_forca_salvar': {
            if (!in_array((int) ($usuario['id_perfil'] ?? 0), [1, 2, 3], true)) {
                http_response_code(403);
                echo json_encode(['sucesso' => false, 'erro' => 'Você não tem permissão para alterar as metas.']);
                exit;
            }

            $month = trim((string) ($_POST['month'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Mês inválido.']);
                exit;
            }

            $diasCustomizados = null;
            $diasProducaoPost = $_POST['dias_producao'] ?? null;
            if (is_string($diasProducaoPost)) {
                $diasProducaoPost = json_decode($diasProducaoPost, true);
            }

            if (is_array($diasProducaoPost) && !empty($diasProducaoPost)) {
                $diasValidos = [];
                foreach ($diasProducaoPost as $dp) {
                    $dp = trim((string) $dp);
                    if (str_starts_with($dp, $month . '-') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dp)) {
                        $diasValidos[] = $dp;
                    }
                }
                sort($diasValidos);
                if (!empty($diasValidos)) {
                    $diasUteis = count($diasValidos);
                    $diasCustomizados = json_encode($diasValidos);
                } else {
                    require_once __DIR__ . '/../includes/helpers.php';
                    $diasUteis = boletimDiasUteisDoMes($month);
                }
            } else {
                require_once __DIR__ . '/../includes/helpers.php';
                $diasUteis = boletimDiasUteisDoMes($month);
            }

            if ($diasUteis <= 0) {
                $diasUteis = 22;
            }

            $diaTpm = max(0.0, (float) ($_POST['meta_dia_tpm'] ?? 0));
            $diaTps = max(0.0, (float) ($_POST['meta_dia_tps'] ?? 0));
            $diaTpd = max(0.0, (float) ($_POST['meta_dia_tpd'] ?? 0));

            $metaTpm      = (int) round($diaTpm * $diasUteis);
            $metaTps      = (int) round($diaTps * $diasUteis);
            $metaTpdForca = (int) round($diaTpd * $diasUteis);

            $stmt = $pdo->prepare("
                INSERT INTO boletim_config_metas
                    (`month`, meta_tpm, meta_tps, meta_tpd_forca, dias_uteis, dias_customizados)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    meta_tpm = VALUES(meta_tpm),
                    meta_tps = VALUES(meta_tps),
                    meta_tpd_forca = VALUES(meta_tpd_forca),
                    dias_uteis = VALUES(dias_uteis),
                    dias_customizados = VALUES(dias_customizados)
            ");
            $stmt->execute([$month, $metaTpm, $metaTps, $metaTpdForca, $diasUteis, $diasCustomizados]);

            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Métricas e calendário salvos com sucesso. Meta TPM: ' . number_format($metaTpm, 0, ',', '.') . ' un · Meta TPS: ' . number_format($metaTps, 0, ',', '.') . ' un · Meta TPD: ' . number_format($metaTpdForca, 0, ',', '.') . ' un (' . $diasUteis . ' dias de produção).',
                'meta_tpm' => $metaTpm,
                'meta_tps' => $metaTps,
                'meta_tpd_forca' => $metaTpdForca,
                'dias_uteis' => $diasUteis,
            ]);
            break;
        }

        // ─── Forçar atualização imediata do SQL Server ──────────────────────
        case 'atualizar_banco': {
            require_once __DIR__ . '/../includes/boletim-planilha.php';
            $mes = trim((string) ($_POST['mes'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
                $mes = date('Y-m');
            }
            $ok = boletimKardexForcarAtualizacao($mes);
            if ($ok) {
                echo json_encode([
                    'sucesso' => true,
                    'mensagem' => 'Dados sincronizados com sucesso do SQL Server.',
                    'sincronizado_em' => boletimKardexUltimaSincronizacao($mes),
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['sucesso' => false, 'erro' => 'Não foi possível conectar ao SQL Server. Verifique a rede.']);
            }
            break;
        }

        // ─── Buscar detalhes analíticos de peças para o modal interativo ────
        case 'buscar_detalhes_pecas': {
            require_once __DIR__ . '/../includes/boletim-planilha.php';
            $mes      = trim((string) ($_REQUEST['mes'] ?? ''));
            $dataDia  = trim((string) ($_REQUEST['data'] ?? ''));
            $tipo     = trim((string) ($_REQUEST['tipo'] ?? ''));
            $area     = trim((string) ($_REQUEST['area'] ?? 'distrib'));
            $refresh  = !empty($_REQUEST['refresh']);

            if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
                $mes = date('Y-m');
            }

            $itens = boletimBuscarDetalhesProducao($mes, $dataDia ?: null, $tipo ?: null, $area, $refresh);

            echo json_encode([
                'sucesso' => true,
                'mes'     => $mes,
                'data'    => $dataDia,
                'tipo'    => $tipo,
                'area'    => $area,
                'total'   => count($itens),
                'itens'   => $itens,
            ]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'erro' => 'Ação desconhecida.']);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro ao processar a solicitação.']);
}
