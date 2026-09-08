# Paint Check — Fluxo em Etapas (Bipar Etiqueta / Identificar → Complementar) Implementation Plan

> **Nota:** este documento é um **registro retroativo** — a implementação abaixo já foi feita e verificada (`php -l` em todos os arquivos PHP tocados + checagem de sintaxe do JS embutido) nesta mesma sessão, antes deste plano ser escrito. Não é um plano pra um subagente executar: é a documentação, no formato do superpowers, do que foi decidido e construído. O repositório não tem suíte de testes automatizada (`CLAUDE.md`: "Não há build, lint nem suíte de testes"), então os passos de verificação são manuais no navegador, não TDD.

**Goal:** Parar de forçar o operador a fotografar tampa+tanque+gancho sempre no Paint Check — identificar o transformador primeiro (por etiqueta bipada/NS digitado, ou por OCR reverso de tampa+gancho) e só então pedir, dinamicamente, as evidências extras que a concessionária daquele transformador realmente exige.

**Architecture:** Duas etapas. **Passo 0** (novo, opcional): campo estilo leitor de código de barras — igual ao já usado em `pages/retrabalho/confirmar-chegada.php` — resolve a etiqueta de produção (`extrairCdOfDaEtiqueta()` + índice novo por `cd_of` sobre o VSAT) ou o NS digitado, e devolve a regra da concessionária via `buscarRegraConcessionaria()`. **Passo 1** (fallback do Passo 0): tampa+gancho, únicas evidências universais, via OCR reverso já existente em `api/paint-check-multi.php` (agora com um modo `identificar` que pula os gates de regra). Assim que a regra é conhecida (por qualquer caminho), o **Passo 2** liga dinamicamente os campos extras (tanque, código adicional/patrimônio, potência — com um card combinado quando os dois últimos ficam fisicamente juntos) e a validação final roda a lógica de sempre.

**Tech Stack:** PHP 8.4 vanilla (`declare(strict_types=1)`), MySQL via PDO (`getDB()`), JS vanilla sem build step, Cropper.js/canvas já usados na tela.

**Spec:** Não houve documento de spec formal — a decisão foi tomada em conversa direta com o usuário (ver seção "Decisões" abaixo, que resume o histórico da conversa). O plano de implementação intermediário ficou salvo em `C:\Users\06688286173\.claude\plans\luminous-tinkering-simon.md` (fora do repo, artefato de sessão do Claude Code).

## Global Constraints

- Todo arquivo PHP novo/editado mantém `declare(strict_types=1)` no topo.
- Nunca instanciar PDO direto — sempre `getDB()`.
- Nomes de função, variável e comentários em português, seguindo o domínio existente.
- Sem framework de teste no projeto — verificação é manual no navegador (Laragon, `http://localhost/...`).
- Migração de banco (se necessária) seria entregue como `.sql` manual — não se aplicou aqui, pois não houve mudança de schema (só leitura de colunas já existentes).

---

## Decisões (resumo da conversa que gerou este plano)

1. **Problema identificado:** `pages/qualidade/paint-check.php` tinha tampa/tanque/gancho como checkboxes fixas (`checked`, escondidas) — a tela ignorava a coluna `concessionaria_regras.locais_obrigatorios`, que já sabia corretamente que só Equatorial/Neoenergia/Energisa (conforme seed) exigem puncionamento no tanque.
2. **"Etiqueta" ≠ "placa":** a etiqueta é o código de barras/QR de produção (`prefixo(5)+cd_of+sufixo(1)`, já lido em Produção/Retrabalho via `extrairCdOfDaEtiqueta()`), bipada com leitor físico — não a placa metálica com o NS puncionado (evidência de QC fotografada nos passos 1/2). São conceitos diferentes; a confusão inicial foi corrigida a pedido do usuário antes de implementar.
3. **Fonte de dados da etiqueta:** VSAT (`includes/vsat-num-series.php`), não a planilha `NS.OF.xlsx` — decisão explícita do usuário (`AskUserQuestion`), pra manter consistência com o resto do Paint Check, que já migrou pra VSAT numa sessão anterior por a planilha estar desatualizada pra alguns clientes.
4. **Combo Potência + Código/Patrimônio:** por sugestão do usuário, quando os dois ficam fisicamente juntos no tanque, a etapa 2 mostra **uma foto combinada por padrão**, com botão "Separar em duas fotos" — em vez de um atalho opt-in de "reusar foto".
5. **Tela intermediária:** por sugestão do usuário, o Passo 0 foi redesenhado pra copiar o visual/UX já existente em `pages/retrabalho/confirmar-chegada.php` (`assets/js/retrabalho-chegada.js`) — ícone, "Aguardando leitura do leitor de código de barras", captura de teclado global (funciona sem foco no campo), botão "Digitar manualmente" que só abre o campo sob demanda.

---

## Task 1: Índice VSAT por cd_of

**Files:**
- Modify: `includes/vsat-num-series.php`

**Interfaces:**
- Consumes: `carregarIndiceVsat(): array` (já existente, linha 28) — cada linha já traz a coluna `cd_of`.
- Produces: `buscarVsatPorCdOf(string $cdOf): ?array` — mesmo formato de retorno de `buscarVsatPorNs()` (linha 81): `['num_serie', 'num_serie_cliente', 'observacao', 'cd_referencia', 'descricao', 'cd_pedido', 'pedido_cliente', 'cliente', 'cd_of', 'data_pcp']` ou `null`.

- [x] **Passo 1: Adicionar `carregarIndiceVsatPorCdOf()` e `buscarVsatPorCdOf()`**

```php
/**
 * Índice cd_of -> dados, construído em cima do mesmo `carregarIndiceVsat()`
 * (sem query nova) — usado pra resolver a etiqueta de produção (código de
 * barras prefixo(5)+cd_of+sufixo(1), ver `extrairCdOfDaEtiqueta()` em
 * `includes/planilha-ns-of.php`) contra o VSAT em vez da planilha NS.OF.xlsx,
 * que ficou confirmadamente desatualizada pra alguns clientes (Copel, Energisa).
 */
function carregarIndiceVsatPorCdOf(): array
{
    static $memo = null;
    if ($memo !== null) return $memo;

    $indice = [];
    foreach (carregarIndiceVsat() as $linha) {
        $cdOf = (string) ($linha['cd_of'] ?? '');
        if ($cdOf !== '' && is_numeric($cdOf)) {
            $indice[(string) (int) $cdOf] = $linha;
        }
    }

    return $memo = $indice;
}

/** Busca um cd_of (miolo da etiqueta de produção) no índice do VSAT. Devolve null se não constar. */
function buscarVsatPorCdOf(string $cdOf): ?array
{
    $cdOf = trim($cdOf);
    if ($cdOf === '' || !is_numeric($cdOf)) return null;
    $indice = carregarIndiceVsatPorCdOf();
    return $indice[(string) (int) $cdOf] ?? null;
}
```

- [x] **Passo 2: Verificar sintaxe**

Rodar: `php -l includes/vsat-num-series.php`
Esperado: `No syntax errors detected`

- [x] **Passo 3: Commit** (não feito — usuário ainda não pediu commit; aguardar autorização explícita conforme `CLAUDE.md`)

---

## Task 2: Endpoint de identificação por etiqueta/NS

**Files:**
- Create: `api/paint-check-identificar.php`

**Interfaces:**
- Consumes: `extrairCdOfDaEtiqueta(string): ?string` (`includes/planilha-ns-of.php:33`), `buscarVsatPorCdOf(string): ?array` (Task 1), `buscarVsatPorNs(string): ?array` (`includes/vsat-num-series.php:81`), `buscarRegraConcessionaria(string): array` (`includes/concessionaria-regras.php:40`).
- Produces: resposta JSON `{success, num_serie, cliente, concessionaria: {nome_grupo, fallback}, regra: {locais_obrigatorios, formato_codigo_adicional, local_codigo_adicional, incluir_ano_tampa}}` — mesmo formato usado pelo modo `identificar` de `api/paint-check-multi.php` (Task 3), consumido por `aplicarRegraIdentificada()` no frontend (Task 4).

- [x] **Passo 1: Criar o endpoint**

```php
<?php
declare(strict_types=1);

/**
 * Passo 0 do Paint Check: identifica o transformador (e resolve a regra da
 * concessionária) a partir da etiqueta de produção bipada ou do Nº de Série
 * digitado — sem depender de nenhuma foto/OCR. Mesma resolução de código já
 * usada em `confirmar_chegada`/`confirmar_inicio` (api/retrabalho-acao.php),
 * mas contra o VSAT em vez da planilha NS.OF.xlsx (fonte que o resto do
 * Paint Check já usa, ver includes/vsat-num-series.php).
 *
 * Isso só resolve QUAL REGRA usar pra montar a etapa 2 da tela — não
 * substitui a validação final. Tampa e gancho continuam sendo fotografadas e
 * validadas de verdade em api/paint-check-multi.php.
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/vsat-num-series.php';
require_once __DIR__ . '/../includes/planilha-ns-of.php';
require_once __DIR__ . '/../includes/concessionaria-regras.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

if (!hasAcesso('pin.pai') && !hasAcesso('tab:pintura') && !hasAcesso('admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sem permissão de acesso']);
    exit;
}

$input = file_get_contents('php://input');
$payload = json_decode($input, true);
$codigo = trim((string) ($payload['codigo'] ?? ''));

if ($codigo === '') {
    echo json_encode(['success' => false, 'error' => 'Informe o código da etiqueta ou o Nº de Série.']);
    exit;
}

// Etiqueta de produção (prefixo(5)+cd_of+sufixo(1)) -> VSAT por cd_of; código
// que não bate nesse formato (ou cd_of que o VSAT não conhece) é tratado como
// o próprio N° de série digitado à mão.
$cdOf = extrairCdOfDaEtiqueta($codigo);
$dados = $cdOf !== null ? buscarVsatPorCdOf($cdOf) : null;
if (!$dados) {
    $dados = buscarVsatPorNs($codigo);
}

if (!$dados) {
    echo json_encode(['success' => false, 'error' => 'Nº de série/etiqueta não encontrado no VSAT.']);
    exit;
}

$regra = buscarRegraConcessionaria((string) ($dados['cliente'] ?? ''));

echo json_encode([
    'success' => true,
    'num_serie' => $dados['num_serie'],
    'cliente' => $dados['cliente'],
    'concessionaria' => [
        'nome_grupo' => $regra['nome_grupo'],
        'fallback' => $regra['fallback'],
    ],
    'regra' => [
        'locais_obrigatorios' => $regra['locais_obrigatorios'],
        'formato_codigo_adicional' => $regra['formato_codigo_adicional'],
        'local_codigo_adicional' => $regra['local_codigo_adicional'],
        'incluir_ano_tampa' => $regra['incluir_ano_tampa'],
    ],
], JSON_UNESCAPED_UNICODE);
```

- [x] **Passo 2: Verificar sintaxe**

Rodar: `php -l api/paint-check-identificar.php`
Esperado: `No syntax errors detected`

- [x] **Passo 3: Commit** (aguardando autorização)

---

## Task 3: Modo `identificar` em `api/paint-check-multi.php`

**Files:**
- Modify: `api/paint-check-multi.php`

**Interfaces:**
- Consumes: payload JSON do frontend, agora podendo incluir `_modo: 'identificar'`.
- Produces: quando `_modo === 'identificar'`, resposta de sucesso pula os gates 4a/4b/4c (ano de fabricação, formato do código adicional, locais obrigatórios) e inclui o mesmo bloco `regra` do Task 2, pra alimentar `aplicarRegraIdentificada()` no frontend (Task 4) tanto no caminho do Passo 0 quanto no fallback do Passo 1.

- [x] **Passo 1: Extrair `$modo` do payload logo após o decode**

Em `api/paint-check-multi.php`, logo após o bloco que valida `$payload` não vazio:

```php
// 'identificar' (Passo 1 do fluxo em etapas — fallback do Passo 0 de
// bipar/digitar etiqueta): só usa tampa+gancho pra achar o transformador e
// devolver a regra da concessionária, sem cobrar ainda as evidências extras
// (isso fica pra etapa 2, na chamada final em modo 'validar').
$modo = (string) ($payload['_modo'] ?? 'validar');
unset($payload['_modo']);
```

- [x] **Passo 2: Envolver os blocos 4a/4b/4c num `if ($modo !== 'identificar')`**

O `checkMatch()` já ignora slots ausentes do payload (linha `if (!isset($payload[$key])) return true;`), então mandar só `tampa_serie`/`gancho_serie` já funciona sem tocar na lógica de match — só os gates de regra (ano de fabricação, formato do código, locais obrigatórios) precisam ser pulados quando `$modo === 'identificar'`, porque nessa fase ainda não pedimos as evidências extras.

- [x] **Passo 3: Incluir `regra` em `$respostaBase`**

```php
$respostaBase = [
    'num_serie' => $transformador['num_serie'],
    'cliente' => $transformador['cliente'],
    'concessionaria' => [
        'nome_grupo' => $regra['nome_grupo'],
        'fallback' => $regra['fallback'],
    ],
    'regras_checadas' => $checagensRegra,
    'regra' => [
        'locais_obrigatorios' => $regra['locais_obrigatorios'],
        'formato_codigo_adicional' => $regra['formato_codigo_adicional'],
        'local_codigo_adicional' => $regra['local_codigo_adicional'],
        'incluir_ano_tampa' => $regra['incluir_ano_tampa'],
    ],
];
```

- [x] **Passo 4: Verificar sintaxe**

Rodar: `php -l api/paint-check-multi.php`
Esperado: `No syntax errors detected`

- [x] **Passo 5: Commit** (aguardando autorização)

---

## Task 4: Passo 0 no frontend (leitura de etiqueta/NS)

**Files:**
- Modify: `pages/qualidade/paint-check.php`

**Interfaces:**
- Consumes: `api/paint-check-identificar.php` (Task 2) e `api/paint-check-multi.php` em modo `identificar` (Task 3) — ambos devolvem o mesmo formato `{success, num_serie, cliente, concessionaria, regra}`.
- Produces: `aplicarRegraIdentificada(data)` — função global que qualquer um dos dois caminhos de identificação chama; liga os campos da etapa 2 (Task 5) e muda `faseAtual` de `'identificar'` pra `'validar'`.

- [x] **Passo 1: CSS do card de leitura (mesmo visual de `pages/retrabalho/confirmar-chegada.php`)**

Adicionado ao `<style>` já existente no topo do arquivo:

```css
/* Passo 0: tela de leitura de etiqueta — mesmo visual de pages/retrabalho/confirmar-chegada.php */
.barcode-box { border: 2px dashed #cbd5e1; border-radius: 10px; padding: 24px; text-align: center; background: #fafafa; transition: all 0.2s ease; }
.barcode-box.is-focused { border-color: #2563eb; background: #f0fdf4; }
.barcode-input { width: 100%; max-width: 360px; height: 48px; font-size: 18px; font-weight: 700; text-align: center; font-family: monospace; letter-spacing: 2px; border: 2px solid #2563eb; border-radius: 8px; padding: 0 16px; outline: none; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); text-transform: uppercase; }
```

- [x] **Passo 2: HTML do card "Passo 0"**, inserido antes do card "Passo 1: Evidências" — ícone, "Aguardando leitura do leitor de código de barras", botão "Digitar número de série manualmente" que revela um `<form>` com `<input class="barcode-input">` só sob demanda (evita abrir o teclado do tablet sozinho), e uma `<div id="passo0Feedback">` pra erros.

- [x] **Passo 3: JS — captura de leitor físico sem precisar de foco no campo**

Mesmo padrão de `assets/js/retrabalho-chegada.js`: um buffer global (`passo0BarcodeBuffer`) acumula teclas rápidas via `window.addEventListener('keydown', ...)`, dispara `identificarPorCodigo(buffer)` no Enter se o buffer tiver 3+ caracteres. Ignora a captura quando o foco está num `<input>` normal (digitação manual) ou quando `passo0Resolvido` já é `true`.

- [x] **Passo 4: `identificarPorCodigo(codigoForcado)`** — POST pra `api/paint-check-identificar.php` com `{codigo}`; em sucesso, substitui o conteúdo do card por uma confirmação (✅ NS + cliente + concessionária) e chama `aplicarRegraIdentificada(data)`; em erro, mostra mensagem inline sem travar a tela (o operador pode tentar de novo ou seguir pras fotos de tampa/gancho).

- [x] **Passo 5: Verificar sintaxe (PHP + JS embutido)**

Rodar:
```bash
php -l pages/qualidade/paint-check.php
node -e "
const fs = require('fs');
const content = fs.readFileSync('pages/qualidade/paint-check.php', 'utf8');
const js = content.slice(content.indexOf('<script>') + 8, content.indexOf('</script>'));
new Function(js);
console.log('JS OK');
"
```
Esperado: `No syntax errors detected` e `JS OK`.

- [x] **Passo 6: Commit** (aguardando autorização)

---

## Task 5: Ativação dinâmica dos campos da etapa 2 (incluindo combo Potência+Código)

**Files:**
- Modify: `pages/qualidade/paint-check.php`

**Interfaces:**
- Consumes: `regra` (formato do Task 2/3) via `aplicarRegraIdentificada(data)`.
- Produces: `photoConfigs` com uma entrada `combo`; `separarComboPotenciaCodigo()`; `imagesData` sempre com as chaves reais (`tanque_potencia`, `tanque_cliente`) preenchidas mesmo quando a foto veio do card combinado — é isso que `validarTudo()` (Task 6) manda pro backend.

- [x] **Passo 1: `photoConfigs` ganha `local` e a entrada combo**

```js
const photoConfigs = [
    { id: 'tampa_serie', label: 'Nº SÉRIE DA TAMPA', local: 'tampa' },
    { id: 'tampa_codigo', label: 'CÓDIGO DA TAMPA (OPC)', local: 'tampa' },
    { id: 'tampa_potencia', label: 'POTÊNCIA DA TAMPA (OPC)', local: 'tampa' },
    { id: 'tanque_serie', label: 'Nº SÉRIE TANQUE', local: 'tanque' },
    { id: 'tanque_cliente', label: 'SÉRIE CLIENTE TANQUE (OPC)', local: 'tanque' },
    { id: 'tanque_potencia', label: 'POTÊNCIA TANQUE', local: 'tanque' },
    { id: 'gancho_serie', label: 'Nº SÉRIE GANCHO', local: 'gancho' },
    { id: 'elo_fusivel', label: 'ELO FUSÍVEL (OPC)', local: 'tanque' },
    { id: 'tanque_potencia_codigo_combo', label: 'POTÊNCIA + CÓDIGO/PATRIMÔNIO', local: 'tanque', combo: ['tanque_potencia', 'tanque_cliente'] }
];
```

- [x] **Passo 2: `aplicarRegraIdentificada(data)`**

```js
function aplicarRegraIdentificada(data) {
    regraAtual = data.regra;
    faseAtual = 'validar';

    document.getElementById('tg_tanque_serie').checked = regraAtual.locais_obrigatorios.includes('tanque');

    const temCodigoAdicional = !!regraAtual.formato_codigo_adicional;
    const codigoNoTanque = temCodigoAdicional && regraAtual.local_codigo_adicional.includes('tanque');
    const codigoNaTampa = temCodigoAdicional && regraAtual.local_codigo_adicional.includes('tampa');

    document.getElementById('tg_tanque_potencia_codigo_combo').checked = codigoNoTanque;
    document.getElementById('tg_tanque_potencia').checked = !codigoNoTanque;
    document.getElementById('tg_tanque_cliente').checked = false; // só liga junto com o combo, ou separado via botão "Separar"
    document.getElementById('tg_tampa_codigo').checked = codigoNaTampa;

    initGrid();

    const btn = document.getElementById('btnValidarTudo');
    if (btn && !btn.disabled) btn.innerText = 'Enviar Evidências Completas';
}
```

- [x] **Passo 3: `confirmarRecorte()` espelha a foto do combo nos dois slots reais**

```js
const conf = photoConfigs.find(c => c.id === currentCropId);
if (conf && conf.combo) {
    conf.combo.forEach(slotId => { imagesData[slotId] = base64; });
}
```

- [x] **Passo 4: `separarComboPotenciaCodigo()`**

```js
function separarComboPotenciaCodigo() {
    document.getElementById('tg_tanque_potencia_codigo_combo').checked = false;
    document.getElementById('tg_tanque_potencia').checked = true;
    document.getElementById('tg_tanque_cliente').checked = true;
    delete imagesData['tanque_potencia_codigo_combo'];
    initGrid();
}
```

- [x] **Passo 5: `initGrid()` renderiza o botão "Separar em duas fotos" só no card combo**

```js
const separarHtml = conf.combo
    ? `<button type="button" class="btn-photo" style="margin-top:8px;background:transparent;border:none;color:#93c5fd;text-decoration:underline;padding:0;" onclick="separarComboPotenciaCodigo()">Separar em duas fotos</button>`
    : '';
```

- [x] **Passo 6: Verificar sintaxe** — mesmo comando do Task 4, Passo 5.

- [x] **Passo 7: Commit** (aguardando autorização)

---

## Task 6: `validarTudo()` em duas fases

**Files:**
- Modify: `pages/qualidade/paint-check.php`

**Interfaces:**
- Consumes: `faseAtual` (`'identificar' | 'validar'`), `photoConfigs` (Task 5), `imagesData`.
- Produces: POST pra `api/paint-check-multi.php` com `_modo: 'identificar'` quando `faseAtual === 'identificar'` (Passo 1, fallback do Passo 0), ou payload completo sem `_modo` quando `faseAtual === 'validar'` (validação final da etapa 2) — e expande a entrada `combo` nos dois slots reais antes de montar o payload.

- [x] **Passo 1: Montagem do payload com expansão do combo**

```js
const payload = {};
ativos.forEach(conf => {
    if (conf.combo) {
        conf.combo.forEach(slotId => { payload[slotId] = imagesData[slotId]; });
    } else {
        payload[conf.id] = imagesData[conf.id];
    }
});
if (faseAtual === 'identificar') payload._modo = 'identificar';
```

- [x] **Passo 2: Tratamento de sucesso bifurcado por fase**

```js
.then(data => {
    if (data.success && faseAtual === 'identificar') {
        mostrarFeedbackPasso0('sucesso', `Identificado: NS ${data.num_serie} — ${data.cliente} (${data.concessionaria.nome_grupo})`);
        aplicarRegraIdentificada(data);
    } else if (data.success) {
        exibirBotaoRetrabalhoSerigrafia(null);
        mostrarModal('sucesso', 'Validação Concluída!', `...` + montarBlocoConcessionaria(data));
    } else {
        exibirBotaoRetrabalhoSerigrafia(data.num_serie || null);
        mostrarModal('erro', 'Divergência Encontrada', (data.error || '') + montarBlocoConcessionaria(data));
    }
})
```

- [x] **Passo 3: Rótulo do botão não se perde entre fases**

`btn.innerText = 'Processando IA...'` limpa o `innerHTML` do botão (perde o SVG do ícone, comportamento pré-existente, não introduzido por este plano). O `.finally()` decide o rótulo certo por `faseAtual`, em vez de restaurar cegamente o texto capturado antes do fetch:

```js
.finally(() => {
    btn.disabled = false;
    btn.innerText = (faseAtual === 'validar') ? 'Enviar Evidências Completas' : txtOriginal;
});
```

- [x] **Passo 4: Verificar sintaxe** — mesmo comando do Task 4, Passo 5.

- [x] **Passo 5: Commit** (aguardando autorização)

---

## Verificação Manual (sem suíte automatizada)

Rodar no navegador (Laragon local), conforme a seção "Verificação" do plano de sessão original:

1. Bipar (ou digitar) uma etiqueta/NS válido no Passo 0 → confirma que resolve a regra e pula direto pra etapa 2, sem pedir foto nenhuma antes.
2. Rodar um Paint Check de concessionária que **exige** tanque (ex.: Equatorial/Neoenergia) → confirmar que a etapa 2 pede a foto do tanque.
3. Rodar um de concessionária que **não exige** (ex.: Copel/Cemig) → confirmar que a etapa 2 pula o tanque.
4. Testar o card combinado "Potência + Código/Patrimônio" (ex.: Neoenergia/CPFL) e o botão "Separar em duas fotos".
5. Forçar falha do Passo 0 (código inexistente) e confirmar que cai pro fluxo normal de tampa+gancho sem travar a tela.
6. Conferir que o "Validar Tudo"/"Enviar Evidências Completas" final ainda reprova corretamente quando falta algo exigido pela regra (regressão do comportamento anterior).
7. **Ainda não executado nesta sessão** — pendente de teste manual no navegador pelo usuário ou numa sessão seguinte.

## Observação em aberto (não resolvida por este plano)

Ao ler `_inicial/migrar-concessionaria-regras.sql`, há uma divergência entre o `locais_obrigatorios` semeado pra **CPFL** (tem `tanque`) e **Energisa** (não tem `tanque`) vs. a tabela consolidada do `pintura.md` (é o oposto). Os comentários no seed dizem que foi "confirmado por foto real via VSAT" — pode ser intencional, mas vale o usuário confirmar essas duas linhas em `pages/admin/concessionaria-regras.php`, já que a tela agora *usa de fato* esse dado pra decidir se pede foto do tanque.
