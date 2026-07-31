<?php
declare(strict_types=1);

/**
 * Converte um DATETIME/TIMESTAMP do MySQL (string "naive", sem offset — ex.:
 * "2026-07-21 14:32:10") para ISO-8601 com o offset explícito de $timezone.
 * Evita que o JS do cliente interprete a string como hora local do dispositivo
 * (que pode ter fuso diferente do servidor) — sempre usar isto ao embutir
 * datas/horas em JSON destinado ao navegador.
 */
function isoComOffset(?string $datetimeSql, string $timezone = 'America/Cuiaba'): ?string
{
    if ($datetimeSql === null || $datetimeSql === '') return null;
    try {
        $dt = new DateTime($datetimeSql, new DateTimeZone($timezone));
        return $dt->format('c');
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * Calcula prazo de D+2 dias úteis (segunda–sexta) para LMC.
 *
 * @param  string $dataPedido  Data ISO (Y-m-d ou datetime) de criação da demanda
 * @return array{prazo: DateTime, status: 'ok'|'urgente'|'atrasada'}
 */
function calcularPrazoLMC(string $dataPedido): array
{
    $dt = new DateTime($dataPedido);
    $dt->setTime(0, 0, 0);

    $diasUteis = 0;
    while ($diasUteis < 2) {
        $dt->modify('+1 day');
        if ((int) $dt->format('N') < 6) { // 1=Seg … 5=Sex
            $diasUteis++;
        }
    }

    $hoje     = new DateTime('today');
    $atrasada = $dt < $hoje;
    $diff     = (int) $hoje->diff($dt)->days;

    if ($atrasada) {
        $status = 'atrasada';
    } elseif ($diff <= 1) { // vence hoje ou amanhã
        $status = 'urgente';
    } else {
        $status = 'ok';
    }

    return ['prazo' => $dt, 'status' => $status];
}

/**
 * Conta dias úteis (segunda–sexta) entre duas datas, andando dia a dia a partir
 * de $inicio (exclusive) até $fim (inclusive). Retorna 0 se $fim <= $inicio.
 */
function diasUteisEntre(DateTime $inicio, DateTime $fim): int
{
    if ($fim <= $inicio) return 0;

    $dias   = 0;
    $cursor = clone $inicio;
    while ($cursor < $fim) {
        $cursor->modify('+1 day');
        if ((int) $cursor->format('N') < 6) { // 1=Seg … 5=Sex
            $dias++;
        }
    }
    return $dias;
}

/**
 * Calcula o status de alerta de qualquer prazo armazenado como DATETIME.
 * - 'atrasada' : prazo < hoje
 * - 'urgente'  : prazo <= amanhã
 * - 'ok'       : prazo > amanhã ou sem prazo
 *
 * Retorna ['status' => string, 'prazo' => DateTime|null]
 */
function calcularPrazoBadge(?string $datetime): array
{
    if (!$datetime) return ['status' => 'ok', 'prazo' => null];

    $prazo = new DateTime($datetime);
    $prazo->setTime(0, 0, 0);
    $hoje  = new DateTime('today');
    $diff  = (int) $hoje->diff($prazo)->days;
    $past  = $prazo < $hoje;

    if ($past)       return ['status' => 'atrasada', 'prazo' => $prazo];
    if ($diff <= 1)  return ['status' => 'urgente',  'prazo' => $prazo];
    return           ['status' => 'ok',              'prazo' => $prazo];
}

/**
 * Formata um valor decimal em pt-BR sem zeros decimais desnecessários.
 * 300.0 → "300"   |   112.5 → "112,5"   |   1000.0 → "1.000"
 */
function fmtDecimal(mixed $value): string
{
    if ($value === null || $value === '') return '—';
    $n = (float) $value;
    $formatted = number_format($n, 1, ',', '.');
    return rtrim(rtrim($formatted, '0'), ',');
}

/**
 * Formata a Tensão Principal AT para exibição: ponto de milhar + "v".
 * Idempotente — se o usuário já digitou o ponto e/ou o "v", não duplica.
 *   13800 → "13.800v"   |   13.800 → "13.800v"   |   13.800v → "13.800v"
 * Valores não-inteiros (ex.: com vírgula/letras) ficam só com o "v" anexado.
 */
function gftFmtTensaoAt(?string $value): string
{
    $v = trim((string) $value);
    if ($v === '') return '—';
    $core = rtrim(preg_replace('/\s*[vV]\s*$/', '', $v));      // tira "v" final, se houver
    // Inteiro puro OU inteiro já com ponto de milhar → reformata com ponto de milhar.
    if (preg_match('/^\d+$/', $core) || preg_match('/^\d{1,3}(\.\d{3})+$/', $core)) {
        $core = number_format((float) str_replace('.', '', $core), 0, ',', '.');
    }
    return $core . 'v';
}

/**
 * Formata a Tensão BT para exibição: só anexa o "v" (valor composto como 380/220
 * não leva ponto de milhar). Idempotente quanto ao "v".
 *   380/220 → "380/220v"   |   380/220v → "380/220v"
 */
function gftFmtTensaoBt(?string $value): string
{
    $v = trim((string) $value);
    if ($v === '') return '—';
    $core = rtrim(preg_replace('/\s*[vV]\s*$/', '', $v));
    return $core . 'v';
}

/**
 * Extrai potência (kVA) e classe de tensão (kV) do texto livre da descrição
 * de um projeto (ex.: "TRANSFORMADOR 45kVA 15kV 3F 220/127V 13800V").
 * Retorna [potencia, classe], cada um string numérica ou null se ausente.
 */
function parsePotenciaClasse(?string $descricao): array
{
    $potencia = null;
    $classe   = null;
    if ($descricao) {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*kva\b/i', $descricao, $m)) {
            $potencia = str_replace(',', '.', $m[1]);
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*kv\b(?!a)/i', $descricao, $m)) {
            $classe = str_replace(',', '.', $m[1]);
        }
    }
    return [$potencia, $classe];
}

/**
 * Setores de destino disponíveis na triagem do retrabalho (para onde o
 * transformador pode ser enviado a seguir). Slug => rótulo, na ordem de exibição.
 * Fonte única — usada tanto para renderizar os checkboxes quanto para validar
 * o que vem do POST em api/retrabalho-acao.php.
 */
function retrabalhoSetoresTriagem(): array
{
    return [
        'bobinagem_at'    => 'Bobinagem AT',
        'bobinagem_bt'    => 'Bobinagem BT',
        'montagem_nucleo' => 'Montagem de Núcleo',
        'solda'           => 'Solda',
        'radiador'        => 'Radiador',
        'pintura'         => 'Pintura',
        'montagem_final'  => 'Montagem Final',
        'laboratorio'     => 'Laboratório',
    ];
}

/**
 * Catálogo fixo de materiais/peças exibido na Triagem do retrabalho (checklist
 * de "o que foi gasto e quantidade", preenchido antes da Causa da Reprova).
 * Fonte: retrabalho_materiais_catalogo (ver _inicial/migrar-retrabalho-materiais.sql).
 */
function retrabalhoMateriaisCatalogo(PDO $pdo): array
{
    return $pdo->query("
        SELECT id, descricao, unidade FROM retrabalho_materiais_catalogo
        WHERE ativo = 1 ORDER BY ordem, id
    ")->fetchAll();
}

/**
 * Níveis de prioridade de um Pedido — mesma escala de cores do protocolo de
 * triagem de saúde (Manchester), sem o tempo-alvo de atendimento (não se aplica
 * aqui). Definido nesta faixa por ora, a pedido do usuário.
 * Slug => rótulo/cores do badge, na ordem de exibição (mais urgente primeiro).
 */
function pedidoPrioridades(): array
{
    return [
        'vermelho' => ['label' => 'Emergência',    'bg' => '#fee2e2', 'fg' => '#dc2626'],
        'laranja'  => ['label' => 'Muito Urgente', 'bg' => '#ffedd5', 'fg' => '#c2410c'],
        'amarelo'  => ['label' => 'Urgente',        'bg' => '#fef9c3', 'fg' => '#a16207'],
        'verde'    => ['label' => 'Pouco Urgente', 'bg' => '#dcfce7', 'fg' => '#16a34a'],
        'azul'     => ['label' => 'Não Urgente',   'bg' => '#dbeafe', 'fg' => '#2563eb'],
    ];
}

/**
 * Formata segundos em texto legível: "2h 30min", "45min", "30s"
 */
function fmtDurSegmentoPHP(int $segundos): string
{
    $h = intdiv($segundos, 3600);
    $m = intdiv($segundos % 3600, 60);
    $s = $segundos % 60;
    if ($h > 0 && $m > 0) return "{$h}h {$m}min";
    if ($h > 0) return "{$h}h";
    if ($m > 0) return "{$m}min";
    return "{$s}s";
}

/**
 * Verifica se uma data (Y-m-d) é feriado usando a tabela feriados.
 *
 * Prioridade: data_efetiva (acordo coletivo) > data original.
 * - Se data_efetiva preenchida: só ela vale para cálculos de HE.
 * - Se vazia: usa lógica padrão (dd/mm para fixos, data completa para móveis).
 */
function isFeriado(PDO $pdo, string $dataYmd): bool
{
    [$ano, $mes, $dia] = explode('-', $dataYmd);
    $ddmm = "{$mes}-{$dia}";

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM feriados
        WHERE data_efetiva = ?
           OR (data_efetiva IS NULL AND movel = 0 AND DATE_FORMAT(data, '%m-%d') = ?)
           OR (data_efetiva IS NULL AND movel = 1 AND data = ?)
    ");
    $stmt->execute([$dataYmd, $ddmm, $dataYmd]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Classifica um segmento de trabalho em Normal, HE50 ou HE100.
 *
 * Regras CLT simplificadas:
 * - Feriado ou domingo ou sábado sem jornada → HE100 integral
 * - Dentro do turno configurado no grupo → Normal
 * - Fora do turno: primeiras 2h além do dia → HE50; restante → HE100
 *
 * Retorna array ['normal'=>int, 'he50'=>int, 'he100'=>int] em segundos.
 *
 * @param PDO    $pdo
 * @param array  $grupoItem  linha de grupos_horas_itens para o dia da semana do segmento
 * @param bool   $feriado    se o dia é feriado
 * @param int    $inicioTs   timestamp de início do segmento
 * @param int    $fimTs      timestamp de fim do segmento
 * @param int    &$heAcum    acumulador de segundos extra já contados no dia (passado por referência)
 */
function classificarSegmento(
    array $grupoItem,
    bool  $feriado,
    int   $inicioTs,
    int   $fimTs,
    int   &$heAcum
): array {
    $total  = $fimTs - $inicioTs;
    $normal = 0; $he50 = 0; $he100 = 0;

    $tipoDia = $grupoItem['tipo_dia'] ?? 'folga';
    $isRepouso = ($tipoDia === 'folga' || $feriado);

    if ($isRepouso) {
        // Dia de repouso: tudo HE100
        $he100 = $total;
        $heAcum += $total;
        return compact('normal', 'he50', 'he100');
    }

    // Construir intervalos do turno no mesmo dia
    $data  = date('Y-m-d', $inicioTs);
    $turnos = [];
    if (!empty($grupoItem['entrada1']) && !empty($grupoItem['saida1'])) {
        $turnos[] = [
            strtotime("{$data} {$grupoItem['entrada1']}"),
            strtotime("{$data} {$grupoItem['saida1']}"),
        ];
    }
    if (!empty($grupoItem['entrada2']) && !empty($grupoItem['saida2'])) {
        $turnos[] = [
            strtotime("{$data} {$grupoItem['entrada2']}"),
            strtotime("{$data} {$grupoItem['saida2']}"),
        ];
    }

    // Percorrer segundo a segundo (simplificação — OK para turnos de até 12h)
    // Para performance real usar aritmética de intervalos; aqui usa-se lógica de sobreposição
    $cur = $inicioTs;
    while ($cur < $fimTs) {
        $chunk  = min(60, $fimTs - $cur); // processa em blocos de 60s
        $dentroTurno = false;
        foreach ($turnos as [$ts, $te]) {
            if ($cur >= $ts && $cur < $te) { $dentroTurno = true; break; }
        }

        if ($dentroTurno) {
            $normal += $chunk;
        } else {
            // Fora do turno: verificar limite de 2h extras (7200s)
            $limite50 = 7200;
            if ($heAcum < $limite50) {
                $cab = min($chunk, $limite50 - $heAcum);
                $he50   += $cab;
                $he100  += $chunk - $cab;
                $heAcum += $chunk;
            } else {
                $he100  += $chunk;
                $heAcum += $chunk;
            }
        }
        $cur += $chunk;
    }

    return compact('normal', 'he50', 'he100');
}

/**
 * Processa os eventos de uma tarefa e retorna segmentos classificados.
 * Cada segmento: ['tipo', 'inicio', 'fim', 'duracao_s', 'motivo',
 *                 'normal_s', 'he50_s', 'he100_s']
 *
 * @param PDO        $pdo
 * @param array      $eventos  array de tarefas_eventos ordenados por created_at ASC
 * @param array|null $grupoItens  array[1..7] de grupos_horas_itens; null = sem classificação
 */
function calcularSegmentosPHP(PDO $pdo, array $eventos, ?array $grupoItens): array
{
    $segs   = [];
    $aberto = null;

    $workStart = ['iniciada', 'retomada'];
    $pauseStart = ['pausada'];
    $close  = ['enviada_controle', 'concluida', 'cancelada', 'devolvida'];

    $fecharSeg = function () use (&$aberto, &$segs, $pdo, $grupoItens) {
        if (!$aberto) return;
        $seg = $aberto;
        $seg['duracao_s'] = $seg['fim'] - $seg['inicio'];
        $seg['normal_s'] = $seg['he50_s'] = $seg['he100_s'] = 0;

        if ($seg['tipo'] === 'trabalho' && $grupoItens) {
            $data    = date('Y-m-d', $seg['inicio']);
            $dow     = (int) date('N', $seg['inicio']); // 1=Seg…7=Dom
            $item    = $grupoItens[$dow] ?? ['tipo_dia' => 'folga'];
            $feriado = isFeriado($pdo, $data);
            $he      = 0;
            $cls     = classificarSegmento($item, $feriado, $seg['inicio'], $seg['fim'], $he);
            $seg['normal_s'] = $cls['normal'];
            $seg['he50_s']   = $cls['he50'];
            $seg['he100_s']  = $cls['he100'];
        }

        $segs[]  = $seg;
        $aberto  = null;
    };

    foreach ($eventos as $ev) {
        $ts = strtotime($ev['created_at']);
        if (in_array($ev['tipo'], $workStart, true)) {
            $fecharSeg();
            $aberto = ['tipo' => 'trabalho', 'inicio' => $ts, 'fim' => $ts, 'motivo' => null];
        } elseif (in_array($ev['tipo'], $pauseStart, true)) {
            if ($aberto) $aberto['fim'] = $ts;
            $fecharSeg();
            $aberto = ['tipo' => 'pausa', 'inicio' => $ts, 'fim' => $ts, 'motivo' => $ev['motivo'] ?? null];
        } elseif (in_array($ev['tipo'], $close, true)) {
            if ($aberto) $aberto['fim'] = $ts;
            $fecharSeg();
        }
    }

    return $segs;
}

/**
 * Soma os totais de um array de segmentos (output de calcularSegmentosPHP).
 * Retorna ['trabalho_s', 'pausa_s', 'normal_s', 'he50_s', 'he100_s']
 */
function somarSegmentos(array $segs): array
{
    $t = ['trabalho_s' => 0, 'pausa_s' => 0, 'normal_s' => 0, 'he50_s' => 0, 'he100_s' => 0];
    foreach ($segs as $s) {
        if ($s['tipo'] === 'trabalho') {
            $t['trabalho_s'] += $s['duracao_s'];
            $t['normal_s']   += $s['normal_s'];
            $t['he50_s']     += $s['he50_s'];
            $t['he100_s']    += $s['he100_s'];
        } else {
            $t['pausa_s'] += $s['duracao_s'];
        }
    }
    return $t;
}

/**
 * Redimensiona uma foto de perfil para no máximo $maxDim×$maxDim px,
 * mantendo a proporção. Salva em $destPath com qualidade reduzida.
 * Retorna false se GD não estiver disponível — nesse caso o chamador
 * deve fazer move_uploaded_file() diretamente.
 */
function gftRedimensionarFoto(string $tmpPath, string $destPath, int $maxDim = 200): bool
{
    if (!function_exists('imagecreatefromjpeg')) return false;

    $info = @getimagesize($tmpPath);
    if (!$info) return false;

    [$w, $h, $type] = [$info[0], $info[1], $info[2]];

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpPath),
        IMAGETYPE_PNG  => @imagecreatefrompng($tmpPath),
        IMAGETYPE_GIF  => @imagecreatefromgif($tmpPath),
        default        => false,
    };
    if (!$src) return false;

    $ratio = min($maxDim / $w, $maxDim / $h, 1.0); // nunca ampliar
    $nw    = (int) round($w * $ratio);
    $nh    = (int) round($h * $ratio);

    $dst = imagecreatetruecolor($nw, $nh);

    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $ok = match ($type) {
        IMAGETYPE_JPEG => imagejpeg($dst, $destPath, 85),
        IMAGETYPE_PNG  => imagepng($dst, $destPath, 7),
        IMAGETYPE_GIF  => imagegif($dst, $destPath),
        default        => false,
    };

    imagedestroy($src);
    imagedestroy($dst);
    return (bool) $ok;
}

/**
 * Rótulo amigável da área de engenharia responsável (demandas.engenharia).
 * NULL/legado (anterior à v1.4.4) ou valor desconhecido → '—'.
 */
function gftEngenhariaLabel(?string $engenharia): string
{
    return [
        'media_forca'      => 'Média Força',
        'distribuicao'     => 'Distribuição',
        'regulador_tensao' => 'Regulador de Tensão',
    ][$engenharia ?? ''] ?? '—';
}

/**
 * Sigla curta da área de engenharia (MF/DT/RT) para badges em telas densas.
 * NULL/legado ou valor desconhecido → '' (sem badge).
 */
function gftEngenhariaSigla(?string $engenharia): string
{
    return [
        'media_forca'      => 'MF',
        'distribuicao'     => 'DT',
        'regulador_tensao' => 'RT',
    ][$engenharia ?? ''] ?? '';
}

/**
 * Opções <option> do filtro de Área de Engenharia usado nas telas de gestão.
 * Rótulos abreviados (DT, MF-TODOS, MF-ÓLEO, MF-SECO, RT) para caber ao lado das
 * buscas/demais filtros. Média Força é dividida pelo meio isolante
 * (projetos.meio_isolante): o valor "media_forca" pega toda a MF, e
 * "media_forca:oleo" / "media_forca:seco" refinam. O JS de cada tela casa o valor
 * selecionado contra data-engenharia + data-meio (ver engMatch). Mantém o filtro
 * num único lugar para as 6 telas.
 */
function gftEngenhariaFiltroOptions(string $sel = ''): string
{
    $opts = [
        ['',                 'Engenharia...'],
        ['distribuicao',     'DT'],
        ['media_forca',      'MF-TODOS'],
        ['media_forca:oleo', 'MF-ÓLEO'],
        ['media_forca:seco', 'MF-SECO'],
        ['regulador_tensao', 'RT'],
    ];
    $html = '';
    foreach ($opts as [$val, $label]) {
        $selected = ($val === $sel) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($val) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
    }
    return $html;
}

/**
 * Traduz o valor do filtro de engenharia (gftEngenhariaFiltroOptions) em condições
 * SQL para telas que filtram no servidor. Devolve ['conds' => string[], 'params' => array]
 * (listas vazias se sem filtro) — o chamador concatena no seu WHERE como preferir.
 * Casa demandas.engenharia e, quando houver, projetos.meio_isolante (aliases dados).
 */
function gftEngenhariaFiltroSql(string $valor, string $aliasD = 'd', string $aliasP = 'p'): array
{
    if ($valor === '') return ['conds' => [], 'params' => []];

    [$eng, $meio] = array_pad(explode(':', $valor, 2), 2, '');
    if (!in_array($eng, ['media_forca', 'distribuicao', 'regulador_tensao'], true)) {
        return ['conds' => [], 'params' => []];
    }

    $conds  = ["{$aliasD}.engenharia = ?"];
    $params = [$eng];
    if (in_array($meio, ['oleo', 'seco'], true)) {
        $conds[]  = "{$aliasP}.meio_isolante = ?";
        $params[] = $meio;
    }
    return ['conds' => $conds, 'params' => $params];
}
