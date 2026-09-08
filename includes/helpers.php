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
        'laboratorio'     => 'Laboratório',
        'montagem_final'  => 'Montagem Final',
    ];
}

/**
 * Busca no catálogo real de materiais/peças (itens_catalogo, populado a partir
 * de "PLANILHA QUE ATUALIZA/Item.csv" por _inicial/importar-itens-catalogo.php)
 * usada pela busca de "Materiais utilizados" na Triagem do retrabalho.
 *
 * Busca por código OU descrição, sempre "contém" (envolve o termo em % dos dois
 * lados) — se o usuário já digitou % no meio do termo (ex.: "isolador%25kva"),
 * ele continua funcionando como curinga nativo do LIKE dentro do padrão maior,
 * sem exigir que o termo bata exatamente no início/fim da descrição. `_` é
 * escapado porque no LIKE ele é curinga de 1 caractere — só % foi pedido como
 * curinga aqui.
 */
function buscarMateriaisCatalogo(PDO $pdo, string $termo, int $limite = 20): array
{
    $termo = trim($termo);
    if ($termo === '') return [];

    $termoEscapado = str_replace('_', '\\_', $termo);
    // Permite que tanto '%' quanto espaços funcionem como coringa entre palavras
    $padrao = '%' . preg_replace('/\s+/', '%', $termoEscapado) . '%';

    try {
        $stmt = $pdo->prepare("
            SELECT codigo, descricao, unidade, preco_medio FROM itens_catalogo
            WHERE codigo LIKE :padraoCodigo OR descricao LIKE :padraoDescricao
            ORDER BY (codigo = :termoExato) DESC, descricao
            LIMIT :limite
        ");
        $stmt->bindValue(':padraoCodigo', $padrao, PDO::PARAM_STR);
        $stmt->bindValue(':padraoDescricao', $padrao, PDO::PARAM_STR);
        $stmt->bindValue(':termoExato', $termo, PDO::PARAM_STR);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (\Throwable $e) {
        $stmt = $pdo->prepare("
            SELECT codigo, descricao, unidade FROM itens_catalogo
            WHERE codigo LIKE :padraoCodigo OR descricao LIKE :padraoDescricao
            ORDER BY (codigo = :termoExato) DESC, descricao
            LIMIT :limite
        ");
        $stmt->bindValue(':padraoCodigo', $padrao, PDO::PARAM_STR);
        $stmt->bindValue(':padraoDescricao', $padrao, PDO::PARAM_STR);
        $stmt->bindValue(':termoExato', $termo, PDO::PARAM_STR);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

/**
 * Níveis de prioridade de um Pedido. Todo pedido nasce "neutro" (ver coluna
 * `prioridade` em pedidos, NOT NULL DEFAULT 'neutro') até alguém mudar
 * manualmente em pages/pedidos/prioridade.php.
 * Slug => rótulo/cores do badge, na ordem de exibição (mais urgente primeiro).
 */
function pedidoPrioridades(): array
{
    return [
        'emergente'  => ['label' => 'Emergente',  'bg' => '#fee2e2', 'fg' => '#dc2626'],
        'urgente'    => ['label' => 'Urgente',    'bg' => '#ffedd5', 'fg' => '#c2410c'],
        'importante' => ['label' => 'Importante', 'bg' => '#fef9c3', 'fg' => '#a16207'],
        'neutro'     => ['label' => 'Neutro',     'bg' => '#f1f5f9', 'fg' => '#64748b'],
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
 * Retorna o calendário consolidado de feriados (Nacionais + Estaduais de MT + Municipais de Cuiabá)
 * para um determinado ano.
 *
 * Fontes Oficiais:
 * - Feriados Nacionais: Lei 662/1949, Lei 6.802/1980, Lei 10.607/2002 e Lei 14.759/2023
 * - Feriados Estaduais MT: Lei Estadual nº 9.129/2009 (Consciência Negra)
 * - Feriados Municipais Cuiabá: Lei Municipal nº 5.576/2012 e Decretos Municipais
 * - Feriados Móveis: Carnaval, Sexta-feira Santa e Corpus Christi (calculados via algoritmo canônico da Páscoa)
 *
 * @param int $ano Ano a ser consultado (ex: 2026)
 * @param PDO|null $pdo Opcional: para consultar a tabela `feriados` do banco, se existir
 * @return array<string, string> Array ordenado no formato ['YYYY-MM-DD' => 'Nome do Feriado']
 */
function boletimObterFeriadosAno(int $ano, ?PDO $pdo = null): array
{
    static $cache = [];
    if (isset($cache[$ano]) && $pdo === null) {
        return $cache[$ano];
    }

    $feriados = [
        sprintf('%04d-01-01', $ano) => 'Confraternização Universal (Ano Novo)',
        sprintf('%04d-04-08', $ano) => 'Aniversário de Cuiabá (Feriado Municipal MT)',
        sprintf('%04d-04-21', $ano) => 'Tiradentes (Feriado Nacional)',
        sprintf('%04d-05-01', $ano) => 'Dia do Trabalhador (Feriado Nacional)',
        sprintf('%04d-09-07', $ano) => 'Independência do Brasil (Feriado Nacional)',
        sprintf('%04d-10-12', $ano) => 'Nossa Senhora Aparecida (Feriado Nacional)',
        sprintf('%04d-11-02', $ano) => 'Finados (Feriado Nacional)',
        sprintf('%04d-11-15', $ano) => 'Proclamação da República (Feriado Nacional)',
        sprintf('%04d-11-20', $ano) => 'Dia Nacional de Zumbi e da Consciência Negra (Feriado Nacional / MT)',
        sprintf('%04d-12-08', $ano) => 'Nossa Senhora da Conceição (Feriado Municipal Cuiabá)',
        sprintf('%04d-12-25', $ano) => 'Natal (Feriado Nacional)',
    ];

    // Feriados móveis canônicos baseados na Páscoa (algoritmo anônimo de Meeus/Jones/Butcher)
    $a = $ano % 19;
    $b = (int) floor($ano / 100);
    $c = $ano % 100;
    $d = (int) floor($b / 4);
    $e = $b % 4;
    $f = (int) floor(($b + 8) / 25);
    $g = (int) floor(($b - $f + 1) / 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = (int) floor($c / 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = (int) floor(($a + 11 * $h + 22 * $l) / 451);
    $mesPascoa = (int) floor(($h + $l - 7 * $m + 114) / 31);
    $diaPascoa = (($h + $l - 7 * $m + 114) % 31) + 1;

    $tsPascoa = mktime(0, 0, 0, $mesPascoa, $diaPascoa, $ano);

    // Segunda e Terça de Carnaval (-48 e -47 dias da Páscoa)
    $feriados[date('Y-m-d', strtotime('-48 days', $tsPascoa))] = 'Carnaval (Segunda-feira)';
    $feriados[date('Y-m-d', strtotime('-47 days', $tsPascoa))] = 'Carnaval (Terça-feira)';

    // Sexta-feira Santa / Paixão de Cristo (-2 dias da Páscoa)
    $feriados[date('Y-m-d', strtotime('-2 days', $tsPascoa))] = 'Sexta-feira Santa / Paixão de Cristo (Feriado Nacional)';

    // Corpus Christi (+60 dias da Páscoa - Feriado Municipal em Cuiabá - Lei 5.576/2012)
    $feriados[date('Y-m-d', strtotime('+60 days', $tsPascoa))] = 'Corpus Christi (Feriado Municipal Cuiabá / MT)';

    // Consulta complementar da tabela `feriados` do banco de dados (se ela existir)
    if ($pdo !== null) {
        try {
            $stmt = $pdo->query("SELECT data, data_efetiva, nome, movel FROM feriados");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $nomeBanco = !empty($row['nome']) ? (string) $row['nome'] : 'Feriado';
                if (!empty($row['data_efetiva'])) {
                    $feriados[$row['data_efetiva']] = $nomeBanco . ' (Acordo Coletivo)';
                } elseif (!empty($row['data'])) {
                    if ((int) ($row['movel'] ?? 0) === 0) {
                        $dt = sprintf('%04d-%s', $ano, substr((string) $row['data'], 5));
                        $feriados[$dt] = $nomeBanco;
                    } else {
                        if (substr((string) $row['data'], 0, 4) == $ano) {
                            $feriados[$row['data']] = $nomeBanco;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Tabela pode não existir no banco; o calendário acima já cobre 100% dos feriados
        }
    }

    ksort($feriados);
    if ($pdo === null) {
        $cache[$ano] = $feriados;
    }
    return $feriados;
}

/**
 * Retorna se uma data específica (YYYY-MM-DD) é feriado Nacional, Estadual de MT ou Municipal de Cuiabá.
 */
function boletimIsFeriado(string $dataYmd, ?PDO $pdo = null): bool
{
    if (strlen($dataYmd) !== 10) return false;
    $ano = (int) substr($dataYmd, 0, 4);
    $feriados = boletimObterFeriadosAno($ano, $pdo);
    return isset($feriados[$dataYmd]);
}

/**
 * Retorna o nome do feriado para uma data (YYYY-MM-DD), ou null se não for feriado.
 */
function boletimObterNomeFeriado(string $dataYmd, ?PDO $pdo = null): ?string
{
    if (strlen($dataYmd) !== 10) return null;
    $ano = (int) substr($dataYmd, 0, 4);
    $feriados = boletimObterFeriadosAno($ano, $pdo);
    return $feriados[$dataYmd] ?? null;
}

/**
 * Verifica se uma data (Y-m-d) é feriado (retrocompatibilidade para HE e módulos).
 */
function isFeriado(PDO $pdo, string $dataYmd): bool
{
    return boletimIsFeriado($dataYmd, $pdo);
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

/**
 * Dias úteis (segunda a sexta, excluindo feriados nacionais, estaduais de MT e municipais de Cuiabá)
 * de um mês "YYYY-MM", respeitando dias customizados de boletim_config_metas caso existam.
 */
function boletimDiasUteisDoMes(string $mes, ?PDO $pdo = null): int
{
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = date('Y-m');
    }

    // Se houver configuração explícita de dias de produção no banco, respeita
    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare("SELECT dias_uteis, dias_customizados FROM boletim_config_metas WHERE `month` = ?");
            $stmt->execute([$mes]);
            $cfg = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cfg) {
                if (!empty($cfg['dias_customizados'])) {
                    $arr = json_decode((string) $cfg['dias_customizados'], true);
                    if (is_array($arr) && !empty($arr)) {
                        return count($arr);
                    }
                }
                if (!empty($cfg['dias_uteis']) && (int) $cfg['dias_uteis'] > 0) {
                    return (int) $cfg['dias_uteis'];
                }
            }
        } catch (\Throwable $e) {}
    }

    [$ano, $m] = array_map('intval', explode('-', $mes));
    $diasNoMes = (int) date('t', mktime(0, 0, 0, $m, 1, $ano));
    $feriados = boletimObterFeriadosAno($ano, $pdo);

    $uteis = 0;
    for ($d = 1; $d <= $diasNoMes; $d++) {
        $dtStr = sprintf('%04d-%02d-%02d', $ano, $m, $d);
        $isFimDeSemana = ((int) date('N', strtotime($dtStr)) > 5);
        $isFeriado = isset($feriados[$dtStr]);
        if (!$isFimDeSemana && !$isFeriado) {
            $uteis++;
        }
    }
    return $uteis;
}

/**
 * Dias úteis já decorridos num mês "YYYY-MM": do dia 1 até hoje (mês atual),
 * até o último dia do mês (mês passado), ou zero (mês futuro),
 * desconsiderando fins de semana e feriados (Nacionais + MT + Cuiabá).
 */
function boletimDiasUteisTrabalhados(string $mes, ?PDO $pdo = null): int
{
    $hoje = new DateTime('today');
    [$ano, $m] = array_map('intval', explode('-', $mes));
    $primeiroDia = new DateTime(sprintf('%04d-%02d-01', $ano, $m));
    if ($primeiroDia > $hoje) return 0;

    $ultimoDiaMes = (int) $primeiroDia->format('t');
    $ultimoDia = new DateTime(sprintf('%04d-%02d-%02d', $ano, $m, $ultimoDiaMes));
    $fim = $ultimoDia < $hoje ? $ultimoDia : $hoje;

    // Se houver dias customizados no banco, calcula a intersecção decorrida
    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare("SELECT dias_customizados FROM boletim_config_metas WHERE `month` = ?");
            $stmt->execute([$mes]);
            $raw = $stmt->fetchColumn();
            if ($raw) {
                $arr = json_decode((string) $raw, true);
                if (is_array($arr) && !empty($arr)) {
                    $hojeStr = $hoje->format('Y-m-d');
                    return count(array_filter($arr, fn($d) => $d <= $hojeStr));
                }
            }
        } catch (\Throwable $e) {}
    }

    $feriados = boletimObterFeriadosAno($ano, $pdo);

    $uteis = 0;
    $cursor = clone $primeiroDia;
    while ($cursor <= $fim) {
        $dtStr = $cursor->format('Y-m-d');
        $isFimDeSemana = ((int) $cursor->format('N') > 5);
        $isFeriado = isset($feriados[$dtStr]);
        if (!$isFimDeSemana && !$isFeriado) {
            $uteis++;
        }
        $cursor->modify('+1 day');
    }
    return $uteis;
}

/**
 * Escape seguro contra XSS
 */
function e(?string $str): string
{
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}

/**
 * Formata data padrão pt-BR (DD/MM/AAAA)
 */
function formatarDataBr(?string $data): string
{
    if (!$data) return '—';
    $timestamp = strtotime($data);
    return $timestamp ? date('d/m/Y', $timestamp) : '—';
}

/**
 * Formata minutos em formato amigável (ex.: 125 min -> 2h 05min)
 */
function formatarMinutosHoras(int|float|string|null $minutos): string
{
    if ($minutos === null || $minutos === '') return '0 min';
    $min = (int) round((float) $minutos);
    if ($min <= 0) return '0 min';
    $h = intdiv($min, 60);
    $m = $min % 60;
    if ($h === 0) return "{$m} min";
    return sprintf('%dh %02dmin', $h, $m);
}

/**
 * Formata percentual com 1 casa decimal
 */
function formatarPercentual(float|int|string|null $valor): string
{
    if ($valor === null || $valor === '') return '—';
    return number_format((float) $valor, 1, ',', '.') . '%';
}

/**
 * Renderiza badge de status com base nas regras de cronoanálise industrial
 */
function renderBadgeStatus(?string $status): string
{
    if ($status === null || $status === '') return '<span class="badge badge-neutral">—</span>';
    $statusNorm = trim(strtoupper($status));
    return match ($statusNorm) {
        'DENTRO DO PADRÃO', '[DENTRO DO PADRÃO]', 'DENTRO DO PADRAO', '[DENTRO DO PADRAO]' => '<span class="badge badge-success">[DENTRO DO PADRÃO]</span>',
        'DESVIO MODERADO', '[DESVIO MODERADO]'                                               => '<span class="badge badge-warning">[DESVIO MODERADO]</span>',
        'GARGALO CRÍTICO', '[GARGALO CRÍTICO]', 'GARGALO CRITICO', '[GARGALO CRITICO]'       => '<span class="badge badge-danger">[GARGALO CRÍTICO]</span>',
        default                                                                             => '<span class="badge badge-neutral">' . e($status) . '</span>',
    };
}

/**
 * Verifica se o usuário atual possui um dos perfis informados
 */
function temPerfil(array|int $perfis): bool
{
    $user = currentUser();
    $idPerfil = (int) ($user['id_perfil'] ?? 0);
    if (is_int($perfis)) {
        return $idPerfil === $perfis;
    }
    return in_array($idPerfil, $perfis, true);
}

