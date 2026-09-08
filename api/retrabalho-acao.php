<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/planilha-ns-of.php';

header('Content-Type: application/json; charset=utf-8');

// ─── Autenticação e Autorização ───────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado']);
    exit;
}

if (!hasAcesso('tab:retrabalho') && !hasAcesso('tab:laboratorio') && !hasAcesso('tab:inspecao_final') && !hasAcesso('tab:pintura') && !hasAcesso('tab:analise') && !hasAcesso('admin')) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Sem permissão de acesso ao módulo de retrabalho']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método não permitido']);
    exit;
}

$acao   = trim((string) ($_POST['acao'] ?? ''));
$userId = (int) (currentUser()['id'] ?? 0);

// Verificação de permissão de escrita para ações de modificação
$isReadAction = in_array($acao, ['buscar_material', 'detalhes', 'historico', 'obter_lote', 'reprovas_disponiveis', 'buscar_projetos'], true);
if (!$isReadAction) {
    if (in_array($acao, ['aprovar_retorno', 'reprovar_retorno', 'excluir_retorno'], true)) {
        if (!podeEditar('lab.ret') && !podeEditar('iqf.ret') && !isAdmin()) {
            http_response_code(403);
            echo json_encode(['sucesso' => false, 'erro' => 'Apenas consulta: sem permissão para gerenciar retornos.']);
            exit;
        }
    } else {
        $origemReq = trim((string) ($_POST['origem'] ?? ''));
        if ($origemReq === 'pintura') {
            if (!podeEditar('pin.ret') && !isAdmin()) {
                http_response_code(403);
                echo json_encode(['sucesso' => false, 'erro' => 'Apenas consulta: sem permissão para alterações em pintura.']);
                exit;
            }
        } elseif ($origemReq === 'painel') {
            if (!podeEditar('ret.pan') && !isAdmin()) {
                http_response_code(403);
                echo json_encode(['sucesso' => false, 'erro' => 'Apenas consulta: sem permissão para alterações no retrabalho operacional.']);
                exit;
            }
        } else {
            if (!podeEditar('ret.rel') && !podeEditar('ret.pan') && !podeEditar('lab.lis') && !podeEditar('iqf.lis') && !isAdmin()) {
                http_response_code(403);
                echo json_encode(['sucesso' => false, 'erro' => 'Apenas consulta: você não tem permissão para realizar lançamentos ou alterações no retrabalho.']);
                exit;
            }
        }
    }
}

if (!function_exists('lerData')) {
    /** Converte uma string 'YYYY-MM-DD' em data válida ou null. */
    function lerData(string $d): ?string
    {
        $d = trim($d);
        return ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
    }
}

if (!function_exists('lerCamposRetrabalho')) {
    /**
     * Lê e normaliza os campos comuns do formulário de retrabalho — tudo exceto a
     * identificação da reprova (id_reprova/data_reprova), que em "registrar" pode
     * vir em lote (várias reprovas numa só triagem) e em "editar" é um valor único.
     */
    function lerCamposRetrabalho(): array
    {
        $obs          = trim((string) ($_POST['observacoes'] ?? ''));
        $causaRaiz    = trim((string) ($_POST['causa_raiz'] ?? ''));
        $causaReprova = trim((string) ($_POST['causa_reprova'] ?? ''));
        $ns           = trim((string) ($_POST['ns_transformador'] ?? ''));
        $idProjeto    = (int) ($_POST['id_projeto'] ?? 0);

        // Obs.: responsável = usuário logado (definido na ação). Status = derivado (derivarStatus()).
        return [
            'observacoes'      => $obs !== '' ? $obs : null,
            'causa_raiz'       => $causaRaiz !== '' ? $causaRaiz : null,
            'causa_reprova'    => $causaReprova !== '' ? $causaReprova : null,
            'ns_transformador' => $ns !== '' ? $ns : null,
            'id_projeto'       => $idProjeto > 0 ? $idProjeto : null,
            'data_chegada'     => lerData((string) ($_POST['data_chegada'] ?? '')),
            'data_inicio'      => lerData((string) ($_POST['data_inicio'] ?? '')),
            'data_finalizacao' => lerData((string) ($_POST['data_finalizacao'] ?? '')),
            'setores_destino'  => lerSetoresDestino(),
        ];
    }
}

if (!function_exists('lerReprovasEmLote')) {
    /** Lê `id_reprova[]` + `data_reprova[]` do POST (lote da triagem). Ignora entradas sem código. */
    function lerReprovasEmLote(): array
    {
        $ids   = (array) ($_POST['id_reprova'] ?? []);
        $datas = (array) ($_POST['data_reprova'] ?? []);
        $defaultData = (string) ($datas[0] ?? date('Y-m-d'));
        $itens = [];
        foreach ($ids as $i => $idReprova) {
            $idReprova = (int) $idReprova;
            if ($idReprova <= 0) continue;
            $dataStr = (string) ($datas[$i] ?? $defaultData);
            $itens[] = ['id_reprova' => $idReprova, 'data_reprova' => lerData($dataStr) ?: date('Y-m-d')];
        }
        return $itens;
    }
}

if (!function_exists('lerSetoresDestino')) {
    /** Lê `setores_destino[]` do POST e valida contra a lista permitida. Retorna string "a,b,c" ou null. */
    function lerSetoresDestino(): ?string
    {
        $permitidos = array_keys(retrabalhoSetoresTriagem());
        $enviados   = array_intersect((array) ($_POST['setores_destino'] ?? []), $permitidos);
        return $enviados ? implode(',', array_values($enviados)) : null;
    }
}

if (!function_exists('lerMateriaisUsados')) {
    /**
     * Lê as linhas de materiais utilizados montadas na tela (arrays paralelos
     * `material_codigo[]` / `material_descricao[]` / `material_unidade[]` /
     * `material_qtd[]` — mesmo padrão de `id_reprova[]`/`data_reprova[]` dos blocos
     * de reprova). Cada linha com descrição vira 1 linha em retrabalho_material_uso
     * — ver gravarMateriaisUsados(). `codigo`/`unidade` vazios viram NULL (linha
     * "adicionado manualmente", fora do catálogo).
     */
    function lerMateriaisUsados(?PDO $pdo = null): array
    {
        $codigos    = (array) ($_POST['material_codigo'] ?? []);
        $descricoes = (array) ($_POST['material_descricao'] ?? []);
        $unidades   = (array) ($_POST['material_unidade'] ?? []);
        $qtds       = (array) ($_POST['material_qtd'] ?? []);
        $precos     = (array) ($_POST['material_preco_unitario'] ?? []);

        $itens = [];
        foreach ($descricoes as $i => $descricao) {
            $descricao = trim((string) $descricao);
            if ($descricao === '') continue;
            $codigo   = trim((string) ($codigos[$i] ?? ''));
            $unidade  = trim((string) ($unidades[$i] ?? ''));
            $qtd      = (float) str_replace(',', '.', (string) ($qtds[$i] ?? 0));
            $precoRaw = trim((string) ($precos[$i] ?? ''));
            $preco    = null;
            if ($precoRaw !== '') {
                $limpo = str_replace(['R$', ' ', '"'], '', $precoRaw);
                $limpo = str_replace(',', '.', $limpo);
                if (is_numeric($limpo)) {
                    $preco = (float) $limpo;
                }
            }

            if (($preco === null || $preco <= 0) && $codigo !== '' && $pdo !== null) {
                try {
                    $stmtP = $pdo->prepare("SELECT preco_medio FROM itens_catalogo WHERE codigo = ? LIMIT 1");
                    $stmtP->execute([$codigo]);
                    $pVal = $stmtP->fetchColumn();
                    if ($pVal !== false && $pVal !== null && is_numeric($pVal)) {
                        $preco = (float) $pVal;
                    }
                } catch (\Throwable $e) {}
            }

            $itens[] = [
                'codigo'      => $codigo !== '' ? $codigo : null,
                'descricao'   => $descricao,
                'unidade'     => $unidade !== '' ? $unidade : null,
                'quantidade'  => max(0, $qtd),
                'preco_medio' => $preco,
            ];
        }

        return $itens;
    }
}

if (!function_exists('gravarMateriaisUsados')) {
    /**
     * Grava as linhas de materiais utilizados na Triagem: como o formulário envia
     * sempre o conjunto completo (igual causa/observações/setores), substitui tudo
     * que já estava gravado para este lote em vez de acumular reenvios.
     */
    function gravarMateriaisUsados(PDO $pdo, int $idLote, array $itens, int $userId): void
    {
        // Garante que a coluna preco_medio exista antes de gravar
        try {
            $colCheck = $pdo->query("
                SELECT COUNT(*) FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retrabalho_material_uso' AND COLUMN_NAME = 'preco_medio'
            ")->fetchColumn();
            if (!$colCheck) {
                $pdo->exec("ALTER TABLE retrabalho_material_uso ADD COLUMN preco_medio DECIMAL(14,4) NULL DEFAULT NULL AFTER quantidade");
            }
        } catch (\Throwable $e) {}

        $pdo->prepare("DELETE FROM retrabalho_material_uso WHERE id_lote = ?")->execute([$idLote]);
        if (!$itens) return;

        $stmt = $pdo->prepare("
            INSERT INTO retrabalho_material_uso (id_lote, codigo, descricao, unidade, quantidade, preco_medio, id_criador)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($itens as $it) {
            $stmt->execute([$idLote, $it['codigo'], $it['descricao'], $it['unidade'], $it['quantidade'], $it['preco_medio'], $userId]);
        }
    }
}

if (!function_exists('sincronizarRetornosProducaoEtapas')) {
    /**
     * Cria a pendência em producao_etapas com status 'aguardando_retorno' para as
     * estações de produção suportadas ('LAB' para 'laboratorio' e 'IQF' para 'inspecao_final')
     * quando o transformador é direcionado a elas na Triagem ou Edição do Retrabalho.
     * 
     * Regra de Isolamento de Retornos:
     * - Se a reprova foi originada na Inspeção Final ('IQF'), ela SEMPRE retorna para 'IQF'.
     * - Se a reprova foi originada no Laboratório ('LAB'):
     *   - Se 'inspecao_final' ou 'montagem_final' foi marcado nos próximos setores,
     *     a peça vai primeiramente para a Inspeção Final (IQF, etapa intermediária).
     *     Ao ser aprovada na IQF, ela avança automaticamente para o Laboratório (LAB).
     *   - Caso contrário, vai diretamente para o Laboratório ('LAB').
     */
    function sincronizarRetornosProducaoEtapas(PDO $pdo, string $ns, int $idProjeto, ?string $setoresDestinoStr, int $userId): void
    {
        $setoresDestino = $setoresDestinoStr !== null ? explode(',', $setoresDestinoStr) : [];

        // Identifica a estação de origem do retrabalho ativo deste transformador
        $stmtEst = $pdo->prepare("
            SELECT estacao FROM retrabalhos
            WHERE ns_transformador = ? AND id_projeto = ? AND deleted_at IS NULL
            ORDER BY data_reprova DESC, id DESC
            LIMIT 1
        ");
        $stmtEst->execute([$ns, $idProjeto]);
        $estacaoOrigem = strtoupper(trim((string) $stmtEst->fetchColumn()));
        if ($estacaoOrigem === '') $estacaoOrigem = 'LAB';

        if ($estacaoOrigem === 'IQF') {
            // Reprova de Inspeção Final NUNCA vai para a aba de retorno do Laboratório
            $estacoesParaCriar = ['IQF'];
        } else {
            // Reprova de Laboratório (LAB)
            if (in_array('inspecao_final', $setoresDestino, true) || in_array('montagem_final', $setoresDestino, true)) {
                $estacoesParaCriar = ['IQF'];
            } else {
                $estacoesParaCriar = ['LAB'];
            }
        }

        foreach ($estacoesParaCriar as $estacaoSigla) {
            $jaAguardando = $pdo->prepare("
                SELECT id FROM producao_etapas
                WHERE ns_transformador = ? AND estacao = ? AND status = 'aguardando_retorno' AND deleted_at IS NULL
            ");
            $jaAguardando->execute([$ns, $estacaoSigla]);
            if (!$jaAguardando->fetch()) {
                $pdo->prepare("
                    INSERT INTO producao_etapas
                        (ns_transformador, id_projeto, estacao, status, data_inicio, id_responsavel, id_criador)
                    VALUES (?, ?, ?, 'aguardando_retorno', NOW(), ?, ?)
                ")->execute([$ns, $idProjeto, $estacaoSigla, $userId, $userId]);
            }
        }
    }
}

if (!function_exists('processarAnexosUpload')) {
    /**
     * Move os anexos enviados em `anexos[]` (multipart) para o diretório final, uma
     * única vez. Valida extensão + MIME real do arquivo. Retorna a lista de arquivos
     * movidos (para depois vincular a 1+ retrabalhos com vincularAnexos()) ou uma
     * string de erro. Lista vazia (sem erro) se nenhum arquivo foi enviado.
     */
    function processarAnexosUpload(): array|string
    {
        if (empty($_FILES['anexos']) || empty($_FILES['anexos']['name'][0])) {
            return [];
        }

        $ALLOWED = [
            'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',  'gif'  => 'image/gif',
            'webp' => 'image/webp', 'pdf'  => 'application/pdf',
        ];
        $MAX_BYTES = 8 * 1024 * 1024;
        $MAX_ARQUIVOS = 10;

        $nomes    = $_FILES['anexos']['name'];
        $tmpNames = $_FILES['anexos']['tmp_name'];
        $errors   = $_FILES['anexos']['error'];
        $sizes    = $_FILES['anexos']['size'];

        if (count($nomes) > $MAX_ARQUIVOS) {
            return "Envie no máximo {$MAX_ARQUIVOS} anexos por vez.";
        }

        $destDir = __DIR__ . '/../uploads/retrabalho';
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            return 'Não foi possível preparar o diretório de anexos.';
        }

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $movidos  = [];

        foreach ($nomes as $i => $nomeOriginal) {
            if ($nomeOriginal === '' || ($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($errors[$i] !== UPLOAD_ERR_OK) {
                return "Falha ao enviar o arquivo \"{$nomeOriginal}\".";
            }
            if ($sizes[$i] > $MAX_BYTES) {
                return "O arquivo \"{$nomeOriginal}\" excede o limite de 8MB.";
            }

            $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
            if (!isset($ALLOWED[$ext])) {
                return "Tipo de arquivo não permitido: \"{$nomeOriginal}\" (use imagem ou PDF).";
            }
            $mimeReal = finfo_file($finfo, $tmpNames[$i]);
            if ($mimeReal !== $ALLOWED[$ext]) {
                return "O conteúdo do arquivo \"{$nomeOriginal}\" não corresponde à extensão informada.";
            }

            $nomeArquivo = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!move_uploaded_file($tmpNames[$i], $destDir . '/' . $nomeArquivo)) {
                return "Não foi possível salvar o arquivo \"{$nomeOriginal}\".";
            }

            $movidos[] = ['nome_arquivo' => $nomeArquivo, 'nome_original' => $nomeOriginal, 'tamanho_bytes' => (int) $sizes[$i]];
        }
        finfo_close($finfo);

        return $movidos;
    }
}

if (!function_exists('vincularAnexos')) {
    /** Vincula os arquivos já movidos (processarAnexosUpload()) a 1+ retrabalhos. */
    function vincularAnexos(PDO $pdo, array $arquivos, array $idsRetrabalho, int $userId): void
    {
        if (!$arquivos || !$idsRetrabalho) return;
        $stmt = $pdo->prepare("
            INSERT INTO retrabalho_anexos (id_retrabalho, nome_arquivo, nome_original, tamanho_bytes, id_criador)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($idsRetrabalho as $idRetrabalho) {
            foreach ($arquivos as $a) {
                $stmt->execute([$idRetrabalho, $a['nome_arquivo'], $a['nome_original'], $a['tamanho_bytes'], $userId]);
            }
        }
    }
}

if (!function_exists('derivarStatus')) {
    /**
     * Deriva o status a partir dos dados:
     *   statusAtual === 'finalizado' -> finalizado (mantém se já foi finalizado no retorno)
     *   data de chegada / início / finalização / causa raiz -> agu_causa_raiz (em andamento)
     *   senão -> agu_abertura
     *
     * Nota: O retrabalho só é considerado 'finalizado' de fato quando for APROVADO no retorno
     * (pelo Laboratório ou Inspeção Final) ou caso seja correção interna direta sem retorno.
     */
    function derivarStatus(array $c, ?string $statusAtual = null): string
    {
        if ($statusAtual === 'finalizado') {
            return 'finalizado';
        }
        if ($c['data_chegada'] !== null || $c['data_inicio'] !== null || $c['causa_raiz'] !== null || $c['data_finalizacao'] !== null) {
            return 'agu_causa_raiz';
        }
        return 'agu_abertura';
    }
}

if (!function_exists('validarCamposComuns')) {
    /** Valida projeto/NS/datas — comum a toda gravação, com ou sem código de reprova novo. */
    function validarCamposComuns(PDO $pdo, array $c, int $idAtual = 0): ?string
    {
        if ($c['id_projeto'] === null)           return 'Selecione o projeto.';
        if ($c['ns_transformador'] === null)     return 'Informe o N° de série do transformador.';

        // Projeto precisa existir
        $q = $pdo->prepare("SELECT id FROM projetos WHERE id = ? AND deleted_at IS NULL");
        $q->execute([$c['id_projeto']]);
        if (!$q->fetch()) return 'Projeto inválido.';

        // Unicidade do N° de série: o mesmo NS não pode estar em outro projeto
        $q = $pdo->prepare("
            SELECT pr.codigo
            FROM retrabalhos r
            JOIN projetos pr ON pr.id = r.id_projeto
            WHERE r.ns_transformador = ? AND r.id_projeto <> ? AND r.deleted_at IS NULL AND r.id <> ?
            LIMIT 1
        ");
        $q->execute([$c['ns_transformador'], $c['id_projeto'], $idAtual]);
        if ($confl = $q->fetch()) {
            return 'Este N° de série já está registrado em outro projeto (' . $confl['codigo'] . ').';
        }

        // Coerência de datas
        if ($c['data_inicio'] && $c['data_finalizacao'] && $c['data_finalizacao'] < $c['data_inicio']) {
            return 'A data de finalização não pode ser anterior à data de início.';
        }

        return null;
    }
}

if (!function_exists('validarReprovaCodigo')) {
    /** Valida só o código de reprova (existe e ativo). */
    function validarReprovaCodigo(PDO $pdo, int $idReprova): ?string
    {
        if ($idReprova <= 0) return 'Selecione o código de reprova.';
        $q = $pdo->prepare("SELECT id FROM reprovas WHERE id = ? AND ativo = 1");
        $q->execute([$idReprova]);
        if (!$q->fetch()) return 'Código de reprova inválido.';
        return null;
    }
}

if (!function_exists('validarRetrabalho')) {
    /** Valida os campos + o código de reprova. Retorna string de erro ou null se OK. $idAtual exclui o próprio registro na edição. */
    function validarRetrabalho(PDO $pdo, array $c, int $idReprova, int $idAtual = 0): ?string
    {
        return validarCamposComuns($pdo, $c, $idAtual) ?? validarReprovaCodigo($pdo, $idReprova);
    }
}

try {
    $pdo = getDB();

    switch ($acao) {

        // ─── Registrar Triagem — 0+ reprovas novas, além das já existentes ─────
        // A Triagem (chegada/observações/setores/materiais/anexos) é compartilhada
        // por todo o lote de um mesmo N° de série + projeto — causa raiz é exceção,
        // rascunhada por reprova em acao=definir_causa_raiz, mas só finaliza a
        // reprova quando a Triagem inteira é reenviada (aqui, mais abaixo).
        // Uma nova reprova é opcional aqui: se já existe pelo menos 1 reprova aberta
        // para este NS/projeto (ex.: a que criou o retrabalho lá na Produção), o
        // envio só precisa atualizar os dados da Triagem nela — não é obrigatório
        // abrir outra reprova a cada envio.
        // "aberta" aqui exclui finalizado E aprovado: um NS que já passou por um
        // ciclo encerrado (aprovado no retorno ao Laboratório, por ex.) e volta a
        // reprovar depois é um novo ciclo — não pode herdar data_chegada/data_inicio
        // do ciclo antigo, senão a Triagem pula direto pra "Triagem" sem pedir o
        // scan de "Confirmar Chegada" de novo (ver acao=confirmar_chegada).
        case 'registrar': {
            $c        = lerCamposRetrabalho();
            $reprovas = lerReprovasEmLote();

            $stmtExist = $pdo->prepare("
                SELECT id, id_lote, data_chegada, data_inicio FROM retrabalhos
                WHERE id_projeto = ? AND ns_transformador = ? AND deleted_at IS NULL AND status != 'finalizado'
            ");
            $stmtExist->execute([$c['id_projeto'], $c['ns_transformador']]);
            $existentes    = $stmtExist->fetchAll();
            $idsExistentes = array_map(fn ($r) => (int) $r['id'], $existentes);

            // Este form (Triagem) não coleta data_chegada nem data_inicio — quem grava
            // isso são os scans de QR separados ("Confirmar Chegada" e o de início, ver
            // acao=confirmar_chegada/confirmar_inicio). Uma reprova nova adicionada aqui
            // herda o que já foi confirmado nas reprovas existentes, e a sincronização
            // abaixo nunca sobrescreve o que já estava gravado.
            $dataChegadaExistente = null;
            $dataInicioExistente  = null;
            foreach ($existentes as $ex) {
                if ($dataChegadaExistente === null && $ex['data_chegada']) $dataChegadaExistente = $ex['data_chegada'];
                if ($dataInicioExistente === null && $ex['data_inicio'])   $dataInicioExistente  = $ex['data_inicio'];
            }

            $isTriagemSubmit = isset($_POST['setores_destino']);
            if (!$isTriagemSubmit && $dataChegadaExistente !== null) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'O número de série já se encontra em retrabalho.']);
                exit;
            }

            if (!$reprovas && !$idsExistentes) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Adicione ao menos uma reprova.']);
                exit;
            }

            if ($err = validarCamposComuns($pdo, $c)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => $err]);
                exit;
            }
            foreach ($reprovas as $i => $rep) {
                if ($err = validarReprovaCodigo($pdo, $rep['id_reprova'])) {
                    http_response_code(400);
                    echo json_encode(['sucesso' => false, 'erro' => 'Reprova ' . ($i + 1) . ': ' . $err]);
                    exit;
                }
            }

            $arquivos = processarAnexosUpload();
            if (is_string($arquivos)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => $arquivos]);
                exit;
            }

            $status      = derivarStatus($c);
            $concluidoEm = ($status === 'finalizado') ? date('Y-m-d H:i:s') : null;

            // Reaproveita o id_lote já aberto para este NS/projeto (mesma sessão de
            // Triagem) — só nasce um lote novo quando não havia nenhuma reprova prévia.
            $idLote = null;
            foreach ($existentes as $ex) {
                if ($ex['id_lote']) { $idLote = (int) $ex['id_lote']; break; }
            }

            $estacaoOrigem = trim((string) ($_POST['estacao'] ?? ''));
            if ($estacaoOrigem === '') $estacaoOrigem = 'LAB';

            // ─── Verificação de vai_retrabalho ──────────────────────────────────────────
            // Se a requisição veio da Produção/Lista (não é envio da tela de Triagem) e TODAS
            // as reprovas selecionadas têm vai_retrabalho = 0, a peça NÃO vai para o retrabalho:
            // é enviada diretamente para a aba Retornos da própria estação (LAB ou IQF).
            $vaiAoRetrabalho = true;
            if (!$isTriagemSubmit && !empty($reprovas)) {
                $idsRep = array_map(fn ($r) => (int) $r['id_reprova'], $reprovas);
                $phVai = implode(',', array_fill(0, count($idsRep), '?'));
                $stmtVai = $pdo->prepare("SELECT vai_retrabalho FROM reprovas WHERE id IN ($phVai)");
                $stmtVai->execute($idsRep);
                $flagsVai = $stmtVai->fetchAll(PDO::FETCH_COLUMN);

                $temAlgumSim = false;
                foreach ($flagsVai as $f) {
                    if ((int)$f === 1) {
                        $temAlgumSim = true;
                        break;
                    }
                }
                if (!$temAlgumSim) {
                    $vaiAoRetrabalho = false;
                }
            }

            if (!$vaiAoRetrabalho) {
                $status = 'finalizado';
                $concluidoEm = date('Y-m-d H:i:s');
                $setorLocal = ($estacaoOrigem === 'IQF' ? 'inspecao_final' : 'laboratorio');
                $c['setores_destino'] = $setorLocal;
                if ($c['causa_raiz'] === null) {
                    $c['causa_raiz'] = 'Correção interna no setor';
                }
            } else {
                // Vai ao retrabalho / setores fabris / retornos: mantém em andamento (agu_causa_raiz)
                // e só finaliza quando o retorno for efetivamente APROVADO pelo Laboratório ou IQF.
                $status = 'agu_causa_raiz';
                $concluidoEm = null;
            }

            $pdo->beginTransaction();

            // Responsável = usuário logado (fixo). Status derivado dos dados.
            $stmt = $pdo->prepare("
                INSERT INTO retrabalhos
                    (id_responsavel, id_projeto, ns_transformador, id_reprova, estacao,
                     data_reprova, data_chegada, data_inicio, data_finalizacao, status,
                     observacoes, causa_raiz, causa_reprova, setores_destino,
                     id_criador, concluido_em, id_lote)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $dataChegadaNova = $c['data_chegada'] ?? $dataChegadaExistente;
            $dataInicioNova  = $c['data_inicio'] ?? $dataInicioExistente;
            $novosIds = [];
            foreach ($reprovas as $rep) {
                $setorItem = $c['setores_destino'];
                if ($setorItem === null) {
                    $repInfo = $pdo->prepare("SELECT familia FROM reprovas WHERE id = ?");
                    $repInfo->execute([$rep['id_reprova']]);
                    $fam = strtoupper(trim((string)$repInfo->fetchColumn()));
                    if (in_array($fam, ['PINTURA', 'SERIGRAFIA', 'CAMADA'], true)) {
                        $setorItem = 'pintura';
                    }
                }

                $stmt->execute([
                    $userId, $c['id_projeto'], $c['ns_transformador'], $rep['id_reprova'], $estacaoOrigem,
                    $rep['data_reprova'], $dataChegadaNova, $dataInicioNova, $c['data_finalizacao'], $status,
                    $c['observacoes'], $c['causa_raiz'], $c['causa_reprova'], $setorItem,
                    $userId, $concluidoEm, $idLote,
                ]);
                $novosIds[] = (int) $pdo->lastInsertId();
            }

            if ($idLote === null) {
                $idLote = $idsExistentes[0] ?? $novosIds[0] ?? null;
            }

            // A Triagem é compartilhada: sincroniza os campos em TODAS as reprovas
            // abertas deste NS/projeto, novas e já existentes — não só nas novas.
            // data_chegada e data_inicio ficam de fora do SET: nunca são sobrescritas por aqui.
            $todosIds = array_merge($idsExistentes, $novosIds);
            if ($todosIds) {
                $ph = implode(',', array_fill(0, count($todosIds), '?'));
                $pdo->prepare("
                    UPDATE retrabalhos SET
                        id_lote = COALESCE(id_lote, ?), data_finalizacao = ?,
                        observacoes = ?, setores_destino = ?
                    WHERE id IN ($ph)
                ")->execute([
                    $idLote, $c['data_finalizacao'],
                    $c['observacoes'], $c['setores_destino'],
                    ...$todosIds,
                ]);

                // Só finaliza diretamente se NÃO vai ao retrabalho (correção interna imediata).
                // Se vai ao retrabalho ou setores/retorno, continua como 'agu_causa_raiz' até a aprovação do retorno.
                if (!$vaiAoRetrabalho) {
                    $pdo->prepare("
                        UPDATE retrabalhos SET status = 'finalizado', concluido_em = COALESCE(concluido_em, NOW())
                        WHERE id IN ($ph)
                    ")->execute($todosIds);
                }
            }

            gravarMateriaisUsados($pdo, (int) $idLote, lerMateriaisUsados($pdo), $userId);

            if (!$vaiAoRetrabalho) {
                // Remove a etapa ativa em_andamento e insere/garante o status aguardando_retorno na estação atual
                $pdo->prepare("
                    UPDATE producao_etapas SET deleted_at = NOW()
                    WHERE ns_transformador = ? AND status = 'em_andamento' AND deleted_at IS NULL
                ")->execute([$c['ns_transformador']]);

                $jaAguardando = $pdo->prepare("
                    SELECT id FROM producao_etapas
                    WHERE ns_transformador = ? AND estacao = ? AND status = 'aguardando_retorno' AND deleted_at IS NULL
                ");
                $jaAguardando->execute([$c['ns_transformador'], $estacaoOrigem]);
                if (!$jaAguardando->fetch()) {
                    $pdo->prepare("
                        INSERT INTO producao_etapas
                            (ns_transformador, id_projeto, estacao, status, data_inicio, id_responsavel, id_criador)
                        VALUES (?, ?, ?, 'aguardando_retorno', NOW(), ?, ?)
                    ")->execute([$c['ns_transformador'], $c['id_projeto'], $estacaoOrigem, $userId, $userId]);
                }
            } else {
                // Se veio da lista de produção e vai ao retrabalho, remove a etapa em_andamento
                if (!$isTriagemSubmit) {
                    $pdo->prepare("
                        UPDATE producao_etapas SET deleted_at = NOW()
                        WHERE ns_transformador = ? AND status = 'em_andamento' AND deleted_at IS NULL
                    ")->execute([$c['ns_transformador']]);
                }

                // Sincroniza retornos para Laboratório e Inspeção Final se marcados em Próximos setores
                sincronizarRetornosProducaoEtapas($pdo, $c['ns_transformador'], (int) $c['id_projeto'], $c['setores_destino'], $userId);
            }

            vincularAnexos($pdo, $arquivos, $todosIds, $userId);

            $pdo->commit();

            if (!$vaiAoRetrabalho) {
                $nomeSetor = ($estacaoOrigem === 'IQF' ? 'da Inspeção Final' : 'do Laboratório');
                echo json_encode([
                    'sucesso'        => true,
                    'vai_retrabalho' => false,
                    'destino'        => 'retornos',
                    'mensagem'       => 'Reprovação registrada. O transformador foi encaminhado para a tela de Retornos ' . $nomeSetor . ' para correção interna.',
                    'ids'            => $novosIds
                ]);
            } else {
                $msg = count($novosIds) > 1 ? count($novosIds) . ' reprovas registradas e enviadas para o Retrabalho.' : 'Reprovação registrada e enviada para o Retrabalho.';
                echo json_encode([
                    'sucesso'        => true,
                    'vai_retrabalho' => true,
                    'destino'        => 'retrabalho',
                    'mensagem'       => $msg,
                    'ids'            => $novosIds
                ]);
            }
            break;
        }

        // ─── Causa raiz de UMA reprova específica ──────────────────────────────
        // Ao contrário dos demais campos da Triagem, causa raiz não é compartilhada
        // entre as reprovas do mesmo N° de série — cada uma tem a sua, definida
        // aqui (popup em pages/retrabalho/detalhe.php). Só grava o texto — NÃO
        // finaliza a reprova ainda: isso só acontece ao reenviar a Triagem inteira
        // (botão "Enviar" -> acao=registrar), que finaliza toda reprova com causa
        // raiz preenchida. Dá pra rascunhar a causa raiz e continuar mexendo na
        // Triagem antes de confirmar o envio.
        case 'definir_causa_raiz': {
            $id              = (int) ($_POST['id'] ?? 0);
            $causaRaiz       = trim((string) ($_POST['causa_raiz'] ?? ''));
            $finalizarDireto = !empty($_POST['finalizar_direto']);
            $observacoes     = trim((string) ($_POST['observacoes'] ?? ''));

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Reprova inválida.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id FROM retrabalhos WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Reprova não encontrada.']);
                exit;
            }

            $causaRaizVal = $causaRaiz !== '' ? $causaRaiz : null;
            if ($finalizarDireto) {
                $pdo->prepare("
                    UPDATE retrabalhos SET
                        causa_raiz = COALESCE(?, causa_raiz),
                        observacoes = CASE WHEN ? != '' THEN CONCAT(COALESCE(observacoes, ''), '\n', ?) ELSE observacoes END,
                        status = 'finalizado',
                        concluido_em = NOW()
                    WHERE id = ?
                ")->execute([$causaRaizVal, $observacoes, $observacoes, $id]);
                echo json_encode(['sucesso' => true, 'mensagem' => 'Retrabalho finalizado com sucesso.']);
            } else {
                $pdo->prepare("
                    UPDATE retrabalhos SET
                        causa_raiz = ?,
                        observacoes = CASE WHEN ? != '' THEN ? ELSE observacoes END
                    WHERE id = ?
                ")->execute([$causaRaizVal, $observacoes, $observacoes, $id]);
                echo json_encode(['sucesso' => true, 'mensagem' => 'Parecer salvo com sucesso.']);
            }
            break;
        }

        // ─── Correção de UMA reprova específica ────────────────────────────────
        // Mesmo padrão da causa raiz (popup em pages/retrabalho/detalhe.php): não é
        // compartilhada entre as reprovas do mesmo N° de série, e só grava o texto —
        // não finaliza a reprova (isso continua dependendo só da causa raiz).
        case 'definir_correcao': {
            $id       = (int) ($_POST['id'] ?? 0);
            $correcao = trim((string) ($_POST['correcao'] ?? ''));

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Reprova inválida.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id FROM retrabalhos WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Reprova não encontrada.']);
                exit;
            }

            $correcaoVal = $correcao !== '' ? $correcao : null;
            $pdo->prepare("UPDATE retrabalhos SET correcao = ? WHERE id = ?")->execute([$correcaoVal, $id]);

            echo json_encode(['sucesso' => true, 'mensagem' => 'Correção salva.']);
            break;
        }

        // ─── Editar retrabalho ────────────────────────────────────────────────
        case 'editar': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro inválido.']);
                exit;
            }
            $c         = lerCamposRetrabalho();
            $idReprova = (int) ($_POST['id_reprova'] ?? 0);
            $dataReprova = lerData((string) ($_POST['data_reprova'] ?? ''));
            if ($err = validarRetrabalho($pdo, $c, $idReprova, $id)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => $err]);
                exit;
            }

            // Carregar carimbo atual para preservar a data de finalização já registrada
            // e o status (não deve reverter sozinho por causa desta edição)
            $stmtCur = $pdo->prepare("SELECT concluido_em, status FROM retrabalhos WHERE id = ? AND deleted_at IS NULL");
            $stmtCur->execute([$id]);
            $atual = $stmtCur->fetch();
            if (!$atual) {
                echo json_encode(['sucesso' => false, 'erro' => 'Registro não encontrado.']);
                exit;
            }

            $status      = derivarStatus($c, $atual['status']);
            $concluidoEm = ($status === 'finalizado')
                ? ($atual['concluido_em'] ?: date('Y-m-d H:i:s'))
                : null;

            // Responsável original preservado; status derivado dos dados.
            $stmt = $pdo->prepare("
                UPDATE retrabalhos SET
                    id_projeto = ?, ns_transformador = ?,
                    id_reprova = ?, data_reprova = ?, data_chegada = ?, data_inicio = ?, data_finalizacao = ?,
                    status = ?, observacoes = ?, causa_raiz = ?, causa_reprova = ?, setores_destino = ?, concluido_em = ?
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([
                $c['id_projeto'], $c['ns_transformador'], $idReprova,
                $dataReprova, $c['data_chegada'], $c['data_inicio'], $c['data_finalizacao'],
                $status, $c['observacoes'], $c['causa_raiz'], $c['causa_reprova'], $c['setores_destino'],
                $concluidoEm, $id,
            ]);

            $arquivos = processarAnexosUpload();
            if (is_string($arquivos)) {
                echo json_encode(['sucesso' => true, 'mensagem' => 'Retrabalho atualizado, mas houve um problema com os anexos: ' . $arquivos]);
                break;
            }
            vincularAnexos($pdo, $arquivos, [$id], $userId);

            sincronizarRetornosProducaoEtapas($pdo, $c['ns_transformador'], (int) $c['id_projeto'], $c['setores_destino'], $userId);

            echo json_encode(['sucesso' => true, 'mensagem' => 'Retrabalho atualizado.']);
            break;
        }

        // ─── Mover manualmente para outro setor (Mapa de Retrabalho, só admin) ──
        // Atalho administrativo do mesmo mecanismo que já move a peça no mapa:
        // sobrescreve setores_destino do lote aberto (ver 'setor' calculado em
        // api/retrabalho-mapa-api.php, 3ª prioridade). Não mexe em producao_etapas,
        // então não tem efeito se a peça estiver no LAB/MF por 1ª/2ª prioridade
        // (etapa ativa/aguardando_retorno ou status agu_chegada) — mesma limitação
        // que a edição manual de setores_destino já tinha em 'editar'.
        case 'mover_setor': {
            if (!isAdmin() && !hasAcesso('admin')) {
                http_response_code(403);
                echo json_encode(['sucesso' => false, 'erro' => 'Apenas administradores podem mover transformadores entre setores.']);
                exit;
            }

            $ns           = trim((string) ($_POST['ns_transformador'] ?? ''));
            $idProjeto    = (int) ($_POST['id_projeto'] ?? 0);
            $setorDestino = strtoupper(trim((string) ($_POST['setor_destino'] ?? '')));

            $MAPA_SETOR_SLUG = [
                'RET_IF'  => 'inspecao_final',
                'RET_LAB' => 'laboratorio',
                'LAB'     => 'laboratorio',
                'MF'      => 'montagem_final',
                'ME'      => 'montagem_nucleo',
                'BOB'     => 'bobinagem_at',
                'PINT'    => 'pintura',
                'CALD'    => 'solda',
                'RET'     => 'retrabalho',
            ];

            if ($ns === '' || !array_key_exists($setorDestino, $MAPA_SETOR_SLUG)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Dados inválidos para mover o transformador.']);
                exit;
            }

            $slugDestino = $MAPA_SETOR_SLUG[$setorDestino];

            // 1. Localiza se existe registro de retrabalho ou etapa para esse NS
            $stmtCheck = $pdo->prepare("
                SELECT id, id_projeto, status, setores_destino 
                FROM retrabalhos 
                WHERE ns_transformador = ? AND deleted_at IS NULL
                ORDER BY (status != 'finalizado') DESC, id DESC
                LIMIT 1
            ");
            $stmtCheck->execute([$ns]);
            $retExistente = $stmtCheck->fetch();

            if ($idProjeto <= 0 && $retExistente && !empty($retExistente['id_projeto'])) {
                $idProjeto = (int) $retExistente['id_projeto'];
            }

            if ($idProjeto <= 0) {
                $stmtEt = $pdo->prepare("
                    SELECT id_projeto FROM producao_etapas 
                    WHERE ns_transformador = ? AND id_projeto > 0 AND deleted_at IS NULL 
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtEt->execute([$ns]);
                $idProjeto = (int) ($stmtEt->fetchColumn() ?: 0);
            }

            if (!$retExistente && $idProjeto <= 0) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => "Transformador NS {$ns} não encontrado no sistema."]);
                exit;
            }

            // 2. Atualiza registros de retrabalho ativos deste NS
            $stmtUp = $pdo->prepare("
                UPDATE retrabalhos 
                SET setores_destino = ?,
                    status = IF(status = 'agu_chegada', 'agu_abertura', status),
                    data_chegada = IF(status = 'agu_chegada' AND data_chegada IS NULL, CURDATE(), data_chegada)
                WHERE ns_transformador = ? AND deleted_at IS NULL
            ");
            $stmtUp->execute([$slugDestino, $ns]);

            // 3. Sincroniza producao_etapas se foi enviado para Retorno IF, Retorno LAB ou estações de produção
            if (in_array($slugDestino, ['laboratorio', 'inspecao_final'], true)) {
                sincronizarRetornosProducaoEtapas($pdo, $ns, $idProjeto, $slugDestino, $userId);
            } else {
                // Se saiu para outro setor fabril, finaliza retornos pendentes em producao_etapas
                $pdo->prepare("
                    UPDATE producao_etapas 
                    SET status = 'concluido', data_fim = NOW() 
                    WHERE ns_transformador = ? AND status IN ('aguardando_retorno', 'em_andamento') AND deleted_at IS NULL
                ")->execute([$ns]);
            }

            // 4. Auditoria
            try {
                $pdo->prepare("
                    INSERT INTO logs_atividade (id_usuario, tipo, descricao, ip, user_agent)
                    VALUES (?, 'acao', ?, ?, ?)
                ")->execute([
                    $userId,
                    "Moveu NS {$ns} para {$setorDestino} ({$slugDestino})",
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
            } catch (\Throwable $t) {}

            $msg = in_array($setorDestino, ['RET_IF', 'RET_LAB'], true)
                ? ($setorDestino === 'RET_IF' ? 'Transformador enviado para a aba Retornos da Inspeção Final com sucesso!' : 'Transformador enviado para a aba Retornos do Laboratório com sucesso!')
                : 'Transformador movido com sucesso para ' . $setorDestino . '!';

            echo json_encode(['sucesso' => true, 'mensagem' => $msg]);
            break;
        }

        // ─── Confirmar chegada física ao retrabalho via leitura de QR ──────────
        // O código lido é resolvido do mesmo jeito que a leitura de QR de Produção
        // (etiqueta de OF -> planilha NS.OF, com N° de série puro como reserva) e
        // comparado com o que este registro espera — nunca confia num id_projeto/ns
        // vindo do cliente. Só grava data_chegada quando bate.
        case 'confirmar_chegada': {
            $id     = (int) ($_POST['id'] ?? 0);
            $codigo = trim((string) ($_POST['codigo'] ?? ''));

            if ($codigo === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Informe o código lido.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT r.id, r.ns_transformador, r.data_chegada, pr.codigo AS projeto_codigo
                FROM retrabalhos r
                JOIN projetos pr ON pr.id = r.id_projeto
                WHERE r.id = ? AND r.deleted_at IS NULL
            ");
            $stmt->execute([$id]);
            $registro = $stmt->fetch();
            if (!$registro) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro não encontrado.']);
                exit;
            }
            if ($registro['data_chegada'] !== null) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Chegada já confirmada para este registro.']);
                exit;
            }

            // Etiqueta de OF resolve N° de série + projeto pela planilha; código que não
            // bate nesse formato (ou que a planilha não conhece) é tratado como o próprio
            // N° de série digitado à mão — aí só o NS é conferido, sem projeto/OF.
            $cdOf    = extrairCdOfDaEtiqueta($codigo);
            $dadosOF = $cdOf !== null ? buscarPlanilhaOF($cdOf) : null;
            if ($dadosOF && $dadosOF['num_serie'] !== '') {
                $nsLido   = $dadosOF['num_serie'];
                $projLido = $dadosOF['cd_referencia'] !== '' ? $dadosOF['cd_referencia'] : null;
            } else {
                $nsLido   = $codigo;
                $projLido = null;
            }

            $nsConfere   = $nsLido === $registro['ns_transformador'];
            $projConfere = $projLido === null || $projLido === $registro['projeto_codigo'];

            if (!$nsConfere || !$projConfere) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' =>
                    'Transformador não confere: esperado NS ' . $registro['ns_transformador'] . ' / Projeto ' . $registro['projeto_codigo']
                    . ', lido NS ' . $nsLido . ($projLido !== null ? ' / Projeto ' . $projLido : '') . '.'
                ]);
                exit;
            }

            $pdo->prepare("UPDATE retrabalhos SET data_chegada = CURDATE(), status = 'agu_causa_raiz' WHERE id = ?")->execute([$id]);
            echo json_encode(['sucesso' => true, 'mensagem' => 'Chegada confirmada.']);
            break;
        }

        // ─── Registrar início do retrabalho (dia + horário) via leitura de QR ──
        // Mesma resolução de código do confirmar_chegada (etiqueta de OF -> planilha
        // NS.OF, com N° de série puro como reserva), conferida contra o NS/projeto
        // da Triagem atual — nunca contra um id vindo do cliente. data_inicio é
        // compartilhada por todo o lote (mesma NS/projeto), então grava em todas as
        // reprovas ainda abertas de uma vez (ver o mesmo compartilhamento em 'registrar').
        case 'confirmar_inicio': {
            $idProjeto = (int) ($_POST['id_projeto'] ?? 0);
            $ns        = trim((string) ($_POST['ns_transformador'] ?? ''));
            $codigo    = trim((string) ($_POST['codigo'] ?? ''));

            if ($idProjeto <= 0 || $ns === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro inválido.']);
                exit;
            }
            if ($codigo === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Informe o código lido.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT codigo FROM projetos WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$idProjeto]);
            $projeto = $stmt->fetch();
            if (!$projeto) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Projeto não encontrado.']);
                exit;
            }

            $cdOf    = extrairCdOfDaEtiqueta($codigo);
            $dadosOF = $cdOf !== null ? buscarPlanilhaOF($cdOf) : null;
            if ($dadosOF && $dadosOF['num_serie'] !== '') {
                $nsLido   = $dadosOF['num_serie'];
                $projLido = $dadosOF['cd_referencia'] !== '' ? $dadosOF['cd_referencia'] : null;
            } else {
                $nsLido   = $codigo;
                $projLido = null;
            }

            $nsConfere   = $nsLido === $ns;
            $projConfere = $projLido === null || $projLido === $projeto['codigo'];

            if (!$nsConfere || !$projConfere) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' =>
                    'Transformador não confere: esperado NS ' . $ns . ' / Projeto ' . $projeto['codigo']
                    . ', lido NS ' . $nsLido . ($projLido !== null ? ' / Projeto ' . $projLido : '') . '.'
                ]);
                exit;
            }

            $agora = date('Y-m-d H:i:s');
            $pdo->prepare("
                UPDATE retrabalhos SET data_inicio = ?
                WHERE id_projeto = ? AND ns_transformador = ? AND deleted_at IS NULL AND status != 'finalizado'
            ")->execute([$agora, $idProjeto, $ns]);

            echo json_encode(['sucesso' => true, 'mensagem' => 'Início do retrabalho registrado.', 'data_inicio' => $agora]);
            break;
        }

        // ─── Aprovar retorno ao Laboratório ─────────────────────────────────────
        // Tela "Retornos ao Laboratório": o transformador foi reinspecionado e
        // passou. Resolve a pendência de retorno (mesmo soft-delete que a leitura
        // de QR faz em api/producao-acao.php::case 'confirmar') e marca todas as
        // reprovas ainda abertas deste NS/projeto como "finalizado" — status distinto
        // de "finalizado" (que representa causa raiz já documentada). Aparece na
        // Relação do Histórico.
        case 'aprovar_retorno': {
            $idProjeto = (int) ($_POST['id_projeto'] ?? 0);
            $ns        = trim((string) ($_POST['ns_transformador'] ?? ''));

            if ($idProjeto <= 0 || $ns === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro inválido.']);
                exit;
            }

            $stmtRet = $pdo->prepare("
                SELECT id, estacao FROM producao_etapas
                WHERE ns_transformador = ? AND id_projeto = ? AND estacao IN ('LAB', 'IQF')
                  AND status = 'aguardando_retorno' AND deleted_at IS NULL
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtRet->execute([$ns, $idProjeto]);
            $etapaRet = $stmtRet->fetch();
            if (!$etapaRet) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Este transformador não está aguardando retorno.']);
                exit;
            }

            $estacaoAprovacao = $etapaRet['estacao']; // 'IQF' ou 'LAB'

            // Identifica se a origem da reprovação foi no Laboratório (LAB)
            // (ex.: peça reprovada no LAB -> retrabalho enviou para IQF -> ao aprovar na IQF deve cair no LAB)
            $origemTeveLab = false;
            $stmtOrigens = $pdo->prepare("
                SELECT r.estacao AS ret_estacao
                FROM retrabalhos r
                WHERE r.ns_transformador = ? AND r.id_projeto = ? AND r.deleted_at IS NULL
                  AND r.status != 'finalizado'
            ");
            $stmtOrigens->execute([$ns, $idProjeto]);
            $origensRows = $stmtOrigens->fetchAll(PDO::FETCH_ASSOC);

            if (empty($origensRows)) {
                // Nenhuma reprova em aberto: cai no ciclo de retrabalho mais recente
                // (não nos últimos 10 registros quaisquer). Ordena por data_reprova,
                // não por id — o histórico importado tem eventos antigos com id novo
                // (inseridos hoje, com data_reprova de anos atrás), então ordenar por
                // id faria um evento antigo "parecer" mais recente que um atual.
                $stmtOrigens2 = $pdo->prepare("
                    SELECT r.estacao AS ret_estacao
                    FROM retrabalhos r
                    WHERE r.ns_transformador = ? AND r.id_projeto = ? AND r.deleted_at IS NULL
                    ORDER BY r.data_reprova DESC, r.id DESC LIMIT 1
                ");
                $stmtOrigens2->execute([$ns, $idProjeto]);
                $origensRows = $stmtOrigens2->fetchAll(PDO::FETCH_ASSOC);
            }

            // Origem real da reprova = o campo `estacao` gravado na própria ocorrência
            // (retrabalhos.estacao), nunca o `local` do catálogo de reprovas — esse é
            // só a lista de estações onde aquele código PODE ocorrer em geral (ex.:
            // "LAB,IQF"), não onde esta peça especificamente foi reprovada.
            foreach ($origensRows as $row) {
                $retEst = strtoupper(trim((string)($row['ret_estacao'] ?? '')));
                if ($retEst === 'LAB') {
                    $origemTeveLab = true;
                    break;
                }
            }

            $pdo->beginTransaction();

            // Se foi aprovado na Inspeção Final (IQF), mas a reprova originou do Laboratório (LAB),
            // a peça deve avançar para o Laboratório (setor de origem) ao invés de finalizar direto.
            if ($estacaoAprovacao === 'IQF' && $origemTeveLab) {
                // 1. Remove a pendência de retorno da Inspeção Final
                $pdo->prepare("
                    UPDATE producao_etapas SET deleted_at = NOW()
                    WHERE ns_transformador = ? AND estacao = 'IQF' AND status = 'aguardando_retorno' AND deleted_at IS NULL
                ")->execute([$ns]);

                // 2. Cria a pendência de retorno no Laboratório (se ainda não existir)
                $stmtLab = $pdo->prepare("
                    SELECT id FROM producao_etapas
                    WHERE ns_transformador = ? AND estacao = 'LAB' AND status = 'aguardando_retorno' AND deleted_at IS NULL
                ");
                $stmtLab->execute([$ns]);
                if (!$stmtLab->fetch()) {
                    $pdo->prepare("
                        INSERT INTO producao_etapas
                            (ns_transformador, id_projeto, estacao, status, data_inicio, id_responsavel, id_criador)
                        VALUES (?, ?, 'LAB', 'aguardando_retorno', NOW(), ?, ?)
                    ")->execute([$ns, $idProjeto, $userId, $userId]);
                }

                // 3. Atualiza setores_destino para incluir 'laboratorio'
                $pdo->prepare("
                    UPDATE retrabalhos 
                    SET setores_destino = IF(setores_destino IS NULL OR setores_destino = '', 'laboratorio', 
                                             IF(FIND_IN_SET('laboratorio', setores_destino), setores_destino, CONCAT(setores_destino, ',laboratorio')))
                    WHERE ns_transformador = ? AND id_projeto = ? AND deleted_at IS NULL AND status != 'finalizado'
                ")->execute([$ns, $idProjeto]);

                $pdo->commit();

                echo json_encode([
                    'sucesso'         => true,
                    'proxima_estacao' => 'LAB',
                    'mensagem'        => 'Transformador aprovado na Inspeção Final e encaminhado para o Laboratório (setor de origem).'
                ]);
                break;
            }

            // Caso padrão (aprovado no Laboratório, ou aprovado na IQF e originado na IQF):
            // Encerra a pendência de retorno e finaliza os retrabalhos abertos.
            $agora = date('Y-m-d H:i:s');
            $hoje  = date('Y-m-d');
            $pdo->prepare("
                UPDATE producao_etapas SET deleted_at = NOW()
                WHERE ns_transformador = ? AND status = 'aguardando_retorno' AND deleted_at IS NULL
            ")->execute([$ns]);

            $pdo->prepare("
                UPDATE retrabalhos SET status = 'finalizado', concluido_em = ?, data_finalizacao = ?
                WHERE ns_transformador = ? AND id_projeto = ? AND deleted_at IS NULL AND status != 'finalizado'
            ")->execute([$agora, $hoje, $ns, $idProjeto]);

            $pdo->commit();

            echo json_encode([
                'sucesso'  => true,
                'mensagem' => 'Retorno aprovado e finalizado com sucesso.'
            ]);
            break;
        }

        // ─── Excluir retorno ao Laboratório / Inspeção Final ───────────────────
        // Remove a pendência de retorno em producao_etapas (soft delete)
        case 'excluir_retorno': {
            $idProjeto = (int) ($_POST['id_projeto'] ?? 0);
            $ns        = trim((string) ($_POST['ns_transformador'] ?? ''));

            if ($ns === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'N° de série inválido.']);
                exit;
            }

            $stmtRet = $pdo->prepare("
                SELECT id, estacao FROM producao_etapas
                WHERE ns_transformador = ? AND estacao IN ('LAB', 'IQF')
                  AND status = 'aguardando_retorno' AND deleted_at IS NULL
                LIMIT 1
            ");
            $stmtRet->execute([$ns]);
            $etapaRet = $stmtRet->fetch();
            if (!$etapaRet) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Este transformador não está aguardando retorno.']);
                exit;
            }

            $pdo->prepare("
                UPDATE producao_etapas SET deleted_at = NOW()
                WHERE ns_transformador = ? AND estacao IN ('LAB', 'IQF') AND status = 'aguardando_retorno' AND deleted_at IS NULL
            ")->execute([$ns]);

            echo json_encode(['sucesso' => true, 'mensagem' => 'Retorno excluído com sucesso.']);
            break;
        }

        // ─── Reprovar retorno ao Laboratório / Inspeção Final ───────────────────
        case 'reprovar_retorno': {
            $tipo       = $_POST['tipo_reprova'] ?? 'reincidencia';
            $idProjeto  = (int) ($_POST['id_projeto'] ?? 0);
            $ns         = trim((string) ($_POST['ns_transformador'] ?? ''));
            $idsReprova = (array) ($_POST['id_reprova'] ?? []);

            if ($idProjeto <= 0 || $ns === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Registro inválido.']);
                exit;
            }
            if ($tipo === 'nova' && empty($idsReprova)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Selecione ao menos uma reprova.']);
                exit;
            }

            $stmtRet = $pdo->prepare("
                SELECT id, estacao FROM producao_etapas
                WHERE ns_transformador = ? AND estacao IN ('LAB', 'IQF')
                  AND status = 'aguardando_retorno' AND deleted_at IS NULL
                LIMIT 1
            ");
            $stmtRet->execute([$ns]);
            $etapaRet = $stmtRet->fetch();
            if (!$etapaRet) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Este transformador não está aguardando retorno.']);
                exit;
            }

            // ─── Verificação de vai_retrabalho no retorno ───────────────────────────
            $vaiAoRetrabalho = true;
            if ($tipo === 'nova' && !empty($idsReprova)) {
                $idsRepInt = array_values(array_filter(array_map('intval', $idsReprova), fn ($x) => $x > 0));
                if ($idsRepInt) {
                    $phVai = implode(',', array_fill(0, count($idsRepInt), '?'));
                    $stmtVai = $pdo->prepare("SELECT vai_retrabalho FROM reprovas WHERE id IN ($phVai)");
                    $stmtVai->execute($idsRepInt);
                    $flagsVai = $stmtVai->fetchAll(PDO::FETCH_COLUMN);

                    $temAlgumSim = false;
                    foreach ($flagsVai as $f) {
                        if ((int)$f === 1) {
                            $temAlgumSim = true;
                            break;
                        }
                    }
                    if (!$temAlgumSim) {
                        $vaiAoRetrabalho = false;
                    }
                }
            }

            $pdo->beginTransaction();

            $estacaoOrigem = $etapaRet['estacao'] ?? 'LAB';
            $setorDestinoLocal = ($estacaoOrigem === 'IQF' ? 'inspecao_final' : 'laboratorio');

            if (!$vaiAoRetrabalho) {
                // Permanece na tela de Retornos da mesma estação
                $dataReprova = date('Y-m-d');
                $stmtIns = $pdo->prepare("
                    INSERT INTO retrabalhos
                        (id_responsavel, id_projeto, ns_transformador, id_reprova, estacao, data_reprova, status,
                         causa_raiz, setores_destino, id_criador, concluido_em)
                    VALUES (?, ?, ?, ?, ?, ?, 'finalizado', 'Correção interna no setor', ?, ?, NOW())
                ");
                foreach ($idsReprova as $idRep) {
                    $idRep = (int) $idRep;
                    if ($idRep > 0) {
                        $stmtIns->execute([$userId, $idProjeto, $ns, $idRep, $estacaoOrigem, $dataReprova, $setorDestinoLocal, $userId]);
                    }
                }

                // Garante que etapa aguardando_retorno continue ativa
                $jaAguardando = $pdo->prepare("
                    SELECT id FROM producao_etapas
                    WHERE ns_transformador = ? AND estacao = ? AND status = 'aguardando_retorno' AND deleted_at IS NULL
                ");
                $jaAguardando->execute([$ns, $estacaoOrigem]);
                if (!$jaAguardando->fetch()) {
                    $pdo->prepare("
                        INSERT INTO producao_etapas
                            (ns_transformador, id_projeto, estacao, status, data_inicio, id_responsavel, id_criador)
                        VALUES (?, ?, ?, 'aguardando_retorno', NOW(), ?, ?)
                    ")->execute([$ns, $idProjeto, $estacaoOrigem, $userId, $userId]);
                }

                $pdo->commit();

                $nomeSetor = ($estacaoOrigem === 'IQF' ? 'da Inspeção Final' : 'do Laboratório');
                echo json_encode([
                    'sucesso'        => true,
                    'vai_retrabalho' => false,
                    'destino'        => 'retornos',
                    'mensagem'       => 'Nova reprova registrada. Peça mantida na tela de Retornos ' . $nomeSetor . ' para correção interna.'
                ]);
                break;
            }

            // Se vai ao retrabalho: remove a etapa aguardando_retorno e reabre/insere em retrabalhos
            $pdo->prepare("
                UPDATE producao_etapas SET deleted_at = NOW()
                WHERE ns_transformador = ? AND status = 'aguardando_retorno' AND deleted_at IS NULL
            ")->execute([$ns]);

            // Acha o lote mais recente
            $stmtLote = $pdo->prepare("
                SELECT id, id_lote FROM retrabalhos
                WHERE ns_transformador = ? AND id_projeto = ? AND deleted_at IS NULL
                ORDER BY CASE WHEN status IN ('finalizado', 'agu_causa_raiz') THEN 0 ELSE 1 END, concluido_em DESC, updated_at DESC, id DESC
                LIMIT 1
            ");
            $stmtLote->execute([$ns, $idProjeto]);
            $ultimo = $stmtLote->fetch();

            $idLoteAtual = $ultimo ? ($ultimo['id_lote'] ?: $ultimo['id']) : null;

            if ($idLoteAtual) {
                // Busca as reprovas que faziam parte desse lote
                $stmtLoteRep = $pdo->prepare("
                    SELECT id, id_reprova FROM retrabalhos
                    WHERE deleted_at IS NULL AND (id_lote = ? OR id = ?)
                ");
                $stmtLoteRep->execute([$idLoteAtual, $idLoteAtual]);
                $reprovasDoLote = $stmtLoteRep->fetchAll(PDO::FETCH_KEY_PAIR); // map [id => id_reprova]

                $idsParaReabrir = [];
                $novosIdsReprova = [];
                $bancoUsados = [];

                foreach ($idsReprova as $idTela) {
                    $idTela = (int)$idTela;
                    $encontrou = false;
                    foreach ($reprovasDoLote as $idLinhaBanco => $idReprovaBanco) {
                        if ($idReprovaBanco == $idTela && !in_array($idLinhaBanco, $bancoUsados)) {
                            $idsParaReabrir[] = $idLinhaBanco;
                            $bancoUsados[] = $idLinhaBanco;
                            $encontrou = true;
                            break;
                        }
                    }
                    if (!$encontrou) {
                        $novosIdsReprova[] = $idTela;
                    }
                }

                // Reabre as que foram mantidas (as excluídas continuam como finalizado)
                if ($idsParaReabrir) {
                    $ph = implode(',', array_fill(0, count($idsParaReabrir), '?'));
                    $pdo->prepare("
                        UPDATE retrabalhos SET
                            status = 'agu_abertura', causa_raiz = NULL,
                            data_chegada = NULL, data_inicio = NULL, data_finalizacao = NULL, concluido_em = NULL,
                            setores_destino = NULL
                        WHERE id IN ($ph)
                    ")->execute($idsParaReabrir);
                }

                // Insere as novas no mesmo lote
                if ($novosIdsReprova) {
                    $dataReprova = date('Y-m-d');
                    $stmtIns = $pdo->prepare("
                        INSERT INTO retrabalhos
                            (id_responsavel, id_projeto, ns_transformador, id_reprova, estacao, data_reprova, status, id_criador, id_lote)
                        VALUES (?, ?, ?, ?, ?, ?, 'agu_abertura', ?, ?)
                    ");
                    foreach ($novosIdsReprova as $idRep) {
                        if ($idRep > 0) {
                            $stmtIns->execute([$userId, $idProjeto, $ns, $idRep, $estacaoOrigem, $dataReprova, $userId, $idLoteAtual]);
                        }
                    }
                }
            } else {
                // Fallback: se não achar lote anterior, insere todas como novas
                $dataReprova = date('Y-m-d');
                $stmtIns = $pdo->prepare("
                    INSERT INTO retrabalhos
                        (id_responsavel, id_projeto, ns_transformador, id_reprova, estacao, data_reprova, status, id_criador)
                    VALUES (?, ?, ?, ?, ?, ?, 'agu_abertura', ?)
                ");
                foreach ($idsReprova as $idRep) {
                    if ((int)$idRep > 0) {
                        $stmtIns->execute([$userId, $idProjeto, $ns, (int)$idRep, $estacaoOrigem, $dataReprova, $userId]);
                    }
                }
            }

            $pdo->commit();

            echo json_encode([
                'sucesso'        => true,
                'vai_retrabalho' => true,
                'destino'        => 'retrabalho',
                'mensagem'       => 'Retorno reprovado — reaberto na Relação de Retrabalhos.'
            ]);
            break;
        }

        // ─── Busca de materiais no catálogo (itens_catalogo) para o autocomplete
        // de "Materiais utilizados" na Triagem — ver buscarMateriaisCatalogo() em
        // includes/helpers.php.
        case 'buscar_material': {
            $termo = trim((string) ($_POST['termo'] ?? ''));
            $itensEncontrados = $termo !== '' ? buscarMateriaisCatalogo($pdo, $termo) : [];
            echo json_encode(['sucesso' => true, 'itens' => $itensEncontrados]);
            break;
        }

        // ─── Adicionar nova reprova diretamente no Retrabalho (estacao = RET) ──
        case 'adicionar_reprova_ret': {
            $idProjeto   = (int) ($_POST['id_projeto'] ?? 0);
            $ns          = trim((string) ($_POST['ns_transformador'] ?? ''));
            $idsReprovas = (array) ($_POST['id_reprova'] ?? []);
            $datas       = (array) ($_POST['data_reprova'] ?? []);

            if ($idProjeto <= 0 || $ns === '') {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Projeto e N° de série são obrigatórios.']);
                exit;
            }

            // Herda dados do lote / registros existentes para este NS/projeto.
            // ORDER BY data_reprova (não por id): registros de importação histórica
            // são inseridos com id novo mas data_reprova antiga — ORDER BY id sozinho
            // escolheria o registro importado (sem id_lote/chegada/início) em vez do
            // lote real ativo, perdendo a continuidade da triagem (602 pares NS+projeto
            // confirmados afetados por esse padrão em ago/2026).
            $stmtExist = $pdo->prepare("
                SELECT id_lote, data_chegada, data_inicio, status, observacoes, causa_reprova, setores_destino
                FROM retrabalhos
                WHERE id_projeto = ? AND ns_transformador = ? AND deleted_at IS NULL
                ORDER BY data_reprova DESC, id DESC LIMIT 1
            ");
            $stmtExist->execute([$idProjeto, $ns]);
            $rowExist = $stmtExist->fetch();

            $idLote       = $rowExist ? $rowExist['id_lote'] : null;
            $dataChegada  = $rowExist ? $rowExist['data_chegada'] : null;
            $dataInicio   = $rowExist ? $rowExist['data_inicio'] : null;
            $statusExist  = $rowExist && in_array($rowExist['status'], ['agu_abertura', 'agu_causa_raiz'], true) ? $rowExist['status'] : 'agu_abertura';
            $observacoes  = $rowExist ? $rowExist['observacoes'] : null;
            $causaReprova = $rowExist ? $rowExist['causa_reprova'] : null;
            $setoresDest  = $rowExist ? $rowExist['setores_destino'] : null;

            $stmtIns = $pdo->prepare("
                INSERT INTO retrabalhos
                    (id_responsavel, id_projeto, ns_transformador, id_reprova, estacao,
                     data_reprova, data_chegada, data_inicio, status, observacoes, causa_reprova, setores_destino,
                     id_criador, id_lote)
                VALUES (?, ?, ?, ?, 'RET', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $novosIds = [];
            foreach ($idsReprovas as $i => $idRep) {
                $idRep = (int) $idRep;
                if ($idRep <= 0) continue;
                $dataRep = lerData((string)($datas[$i] ?? '')) ?: date('Y-m-d');
                $stmtIns->execute([
                    $userId, $idProjeto, $ns, $idRep,
                    $dataRep, $dataChegada, $dataInicio, $statusExist, $observacoes, $causaReprova, $setoresDest,
                    $userId, $idLote
                ]);
                $novosIds[] = (int) $pdo->lastInsertId();
            }

            if (empty($novosIds)) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => 'Selecione ao menos um código de reprova válido.']);
                exit;
            }

            echo json_encode([
                'sucesso'    => true,
                'mensagem'   => count($novosIds) . ' reprova(s) adicionada(s) com sucesso!',
                'id_reprova' => $novosIds[0]
            ]);
            break;
        }

        // ─── Excluir (soft delete) ────────────────────────────────────────────
        case 'excluir': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['sucesso' => false, 'erro' => 'Registro inválido.']); exit; }

            $stmtCheck = $pdo->prepare("SELECT estacao, id_criador FROM retrabalhos WHERE id = ? AND deleted_at IS NULL");
            $stmtCheck->execute([$id]);
            $retRow = $stmtCheck->fetch();
            if (!$retRow) {
                http_response_code(404);
                echo json_encode(['sucesso' => false, 'erro' => 'Reprova não encontrada.']);
                exit;
            }

            if ($retRow['estacao'] !== 'RET' && !hasAcesso('admin')) {
                http_response_code(403);
                echo json_encode(['sucesso' => false, 'erro' => 'Não é permitido excluir reprovas originadas em outros setores (' . ($retRow['estacao'] ?: 'Original') . '). Apenas reprovas adicionadas no Retrabalho podem ser excluídas.']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE retrabalhos SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$id]);
            echo json_encode(['sucesso' => true, 'mensagem' => 'Retrabalho excluído.']);
            break;
        }

        // ─── Excluir em lote (soft delete) — usado no Histórico pra excluir de uma
        // vez todas as reprovas de um N° de série (mesma regra de permissão do
        // 'excluir' acima, aplicada linha a linha: reprovas originadas em outros
        // setores são puladas em vez de bloquear o lote inteiro) ────────────────
        case 'excluir_lote': {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), fn ($v) => $v > 0)));
            if (!$ids) { http_response_code(400); echo json_encode(['sucesso' => false, 'erro' => 'Nenhum registro informado.']); exit; }

            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmtCheck = $pdo->prepare("SELECT id, estacao FROM retrabalhos WHERE id IN ($ph) AND deleted_at IS NULL");
            $stmtCheck->execute($ids);
            $linhas = $stmtCheck->fetchAll();

            if (!$linhas) {
                http_response_code(404);
                echo json_encode(['sucesso' => false, 'erro' => 'Nenhuma reprova encontrada.']);
                exit;
            }

            $podeExcluirTudo = hasAcesso('admin');
            $idsExcluir = [];
            $bloqueadas = 0;
            foreach ($linhas as $l) {
                if ($podeExcluirTudo || $l['estacao'] === 'RET') {
                    $idsExcluir[] = (int) $l['id'];
                } else {
                    $bloqueadas++;
                }
            }

            if (!$idsExcluir) {
                http_response_code(403);
                echo json_encode(['sucesso' => false, 'erro' => 'Não é permitido excluir reprovas originadas em outros setores. Apenas reprovas adicionadas no Retrabalho podem ser excluídas.']);
                exit;
            }

            $phDel = implode(',', array_fill(0, count($idsExcluir), '?'));
            $pdo->prepare("UPDATE retrabalhos SET deleted_at = NOW() WHERE id IN ($phDel) AND deleted_at IS NULL")->execute($idsExcluir);

            $msg = count($idsExcluir) . ' reprova(s) excluída(s).';
            if ($bloqueadas > 0) {
                $msg .= ' ' . $bloqueadas . ' não puderam ser excluídas por terem origem em outro setor.';
            }

            echo json_encode(['sucesso' => true, 'mensagem' => $msg, 'excluidas' => count($idsExcluir), 'bloqueadas' => $bloqueadas]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'erro' => 'Ação desconhecida.']);
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro ao processar a solicitação.']);
}
