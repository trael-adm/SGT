# Tela de Produção — Especificação da 1ª atividade (Card do Laboratório + Leitura QR)

> Documento de referência para a implementação futura do módulo **Produção**. Cobre apenas a **primeira atividade** do módulo: o card da estação **Laboratório (LAB)**, a área de leitura associada a ele e o fluxo de leitura de QR Code via botão flutuante. As demais estações (IQF, GER) e o painel gerencial (KPIs/gráficos) ficam fora do escopo deste documento e devem seguir o mesmo padrão aqui definido quando forem especificados.
>
> **Status: implementado**, com alguns desvios em relação ao texto original abaixo — cada desvio real está marcado inline como "**Nota de implementação**". A tabela de acompanhamento do padrão de 3 partes acabou saindo junto com esta atividade, como a tela **Lista de Registros** (`pages/producao/lista.php`) — fora do escopo original deste documento, mas descrita na seção 6 para não ficar sem registro.

---

## 0. Escopo e premissas

- Módulo **Produção** ainda não existe no código (`pages/producao/` não existe; hoje só há um card placeholder no hub apontando para `em-breve.php?s=Produção`). Esta especificação assume a criação futura de `pages/producao/index.php`.
- **Não alterar o layout base**: reaproveitar `includes/layout.php` (`layoutHeader()`/`layoutFooter()`), `includes/sidebar.php` e `includes/header.php` exatamente como usados pelo Retrabalho — a única mudança estrutural fora do conteúdo da página é **adicionar 1 item novo à sidebar**:
  ```php
  // includes/sidebar.php — novo item, antes de "Retrabalho"
  ['href' => '/pages/producao/index.php', 'label' => 'Produção', 'icon' => '...'],
  ```
- Reaproveitar o design system existente em `assets/css/main.css` (CSS variables, `.card`, `.badge-*`, `.btn-*`, `.modal-*`, `.form-control`) em vez de recriar classes locais `rt-*` como o Retrabalho fez — esta tela é a oportunidade de já nascer alinhada aos tokens globais.
- As 3 estações do chão de fábrica já existem como conceito no código (`$localMap` em `pages/retrabalho/index.php:149-153`): **IQF** (Inspeção final, azul `#2563eb`), **LAB** (Laboratório, roxo `#7c3aed`), **GER** (Geral, verde `#16a34a`). Esta 1ª atividade cobre só o card **LAB**, já desenhado para que IQF/GER sejam adicionados ao lado, no mesmo grid, sem redesenho.
- Stack: PHP + JS vanilla, sem build step, bibliotecas só via CDN — consistente com a regra do projeto (`PROJETO-SGT.md`, "Stack obrigatória").

---

## 1. Mapeamento de Wireframe / Layout

Estrutura geral herdada 1:1 do app shell (sidebar fixa 220px + header fixo 56px + conteúdo rolável). Nada muda nesses três blocos — a especificação abaixo cobre só a região `.app-content`.

```
┌─────────────┬──────────────────────────────────────────────────────────┐
│             │  ☰   Tela de Produção          Trocar de sistema  👤 A ▾  │  ← header (56px, inalterado)70101 
│             ├──────────────────────────────────────────────────────────┤
│   SGT       │  Produção                                                 │
│             │  Acompanhamento das etapas no chão de fábrica             │
│  ⌂ Home     │                                                            │
│ ▸Produção   │  ┌─────────────────────────┐  ┌─────────────────────────┐ │
│  ↻Retrab.   │  │ ● LAB — Laboratório     │  │  (reservado p/ IQF —    │ │
│  ≡Relação   │  │─────────────────────────│  │   próxima atividade)    │ │
│  ⌂Projetos  │  │  Em andamento           │  │                          │ │
│             │  │  NS-2026-00841           │  └─────────────────────────┘ │
│             │  │  TPD-378787 · PED-10422 │                              │
│             │  │  ⏱ 00:04:12              │                              │
│             │  │┈┈┈┈┈┈┈┈┈┈┈┈┈┈┈┈┈┈┈┈┈┈┈┈┈┈│                              │
│             │  │ Área de Leitura          │                              │
│             │  │ Lido às 14:32 · via QR   │                              │
│             │  │ ┌─────────────────────┐ │                              │
│             │  │ │ NS-2026-00841 (auto)│ │                              │
│             │  │ └─────────────────────┘ │                              │
│             │  │ ┌─────────────────────┐ │                              │
│             │  │ │ TPD-378787    (auto)│ │                              │
│             │  │ └─────────────────────┘ │                              │
│             │  └─────────────────────────┘                              │
│             │                                                            │
│             │                                            ┌────────┐    │
│  SGT v1.0.0 │                                            │  ⌖ QR  │ ←FAB│
└─────────────┴────────────────────────────────────────────┴────────┘    │
                                                              56px, fixo
                                                          bottom:24 right:24
```

**Descrição por região:**

| Região | Elemento | Observação |
|---|---|---|
| Sidebar | novo item "Produção" | inserido antes de "Retrabalho", ícone próprio (ex.: `activity`/`gauge`, já disponíveis em `_sidebarIcon()`) |
| Header | título "Tela de Produção" | via `layoutHeader('Tela de Produção')`, sem mudança de componente |
| Conteúdo — topo | H1 "Produção" + subtítulo | mesmo padrão do cabeçalho do Retrabalho (`pages/retrabalho/index.php:217+`) |
| Conteúdo — grid de estações | **Card do Laboratório** no canto superior esquerdo da área principal | grid responsivo (`repeat(auto-fit, minmax(280px,1fr))`, mesma lógica de `.rt-kpis`), hoje com 1 card visível, preparado para IQF/GER ao lado |
| Dentro do card LAB | **Área de Leitura/Escaner** | sub-seção inferior do próprio card, separada por divisor tracejado sutil, mesma superfície branca do card (não é um card separado) |
| Canto inferior da tela | **FAB** | fixo, sempre visível sobre o conteúdo, `bottom:24px; right:24px`, círculo 56px |
| Tela cheia (sob demanda) | **Overlay do Leitor de QR** | some por padrão; ocupa a tela inteira ao abrir (kiosk-friendly para tablet de chão de fábrica) |

---

## 2. Especificação de Componentes

### 2.1 Card do Laboratório

Base: classes globais `.card` / `.card-header` / `.card-title` (`main.css:339-352`) — **não** recriar como `rt-card`.

Cabeçalho do card: badge de estação (pill roxo `#7c3aed`/`bg #f5f3ff`, mesma cor de `$localMap['LAB']`) + label "Laboratório" + ícone de estação.

**Estados:**

| Estado | Conteúdo | Gatilho |
|---|---|---|
| **Vazio (idle)** | Ilustração/ícone neutro + texto "Nenhum transformador em andamento no Laboratório" + dica leve "Toque no botão de leitura para iniciar" | Nenhum lançamento ativo nesta estação |
| **Em andamento** | N° de série (`ns_transformador`, fonte mono como `.rt-code`), Projeto + Pedido, cronômetro decorrido desde o início (`⏱ 00:04:12`, atualiza a cada segundo em JS) | Após confirmação de uma leitura QR |
| **Recém-confirmado** | Mesma visão de "Em andamento", com destaque temporário (borda/glow verde por ~2s, reaproveitando `--color-success`) antes de assentar no estado normal | Imediatamente após o POST de confirmação retornar sucesso |
| **Erro ao carregar/atualizar** | Texto de erro discreto + botão "Tentar novamente" | Falha ao buscar o estado atual da estação (polling/refresh) |

### 2.2 Área de Leitura/Escaner (dentro do card)

Sub-seção fixa na parte inferior do card, sempre presente (não é um modal — é onde o resultado da última leitura fica visível mesmo depois que o overlay de câmera fecha).

- Cabeçalho pequeno: "Área de Leitura" + timestamp da última leitura ("Lido às 14:32") + selo "via QR" quando o valor veio de scan (ícone pequeno, não texto extenso).
- Campos: reaproveitar o padrão já usado no Retrabalho para valores derivados automaticamente — classe `.rt-field.auto` (`pages/retrabalho/index.php:204`: borda tracejada, fundo `#f3f6f4`) generalizada para uma classe global (ex.: `.field-auto`), aplicada a **N° de série** e **Projeto**.
- **Importante**: os campos continuam **editáveis manualmente** mesmo depois de auto-preenchidos — não travar o input. Isso cobre o caso de leitura incorreta de 1 dígito sem obrigar um novo scan.
- Estado vazio: campos mostram placeholder "Aguardando leitura…" em vez de auto-preenchidos.

> **Nota de implementação:** saiu diferente deste texto. Os campos (N° de série, Projeto, **Descrição**, Pedido, **Cliente** — dois campos a mais que o previsto aqui) são `<span>` somente-leitura, não inputs — não há edição manual em nenhum estado. A resoluao enviar ção acontece inteira no servidor (`resolverTransformador()` em `api/producao-acao.php`, a partir da planilha `NS.OF.xlsx`), então permitir o operador sobrescrever um valor já validado sem revalidar reintroduziria o risco que a resolução automática existe para evitar; a saída escolhida foi bloquear o registro por completo quando o projeto não resolve (ver 2.4), em vez de liberar edição parcial. Campo vazio mostra "Não registrado", não um placeholder de "aguardando".

### 2.3 FAB (Floating Action Button)

**Padrão novo** — não existe nenhum precedente no design system hoje; deve ser criado como classe global reutilizável (não local à página), para servir de base a outros módulos.

```css
.fab {
  position: fixed; right: 24px; bottom: 24px;
  width: 56px; height: 56px; border-radius: var(--radius-full);
  background: var(--color-accent); color: #fff;
  display: flex; align-items: center; justify-content: center;
  box-shadow: var(--shadow-lg); border: none; cursor: pointer;
  z-index: 400; /* abaixo do modal-overlay (1000), acima do conteúdo */
  transition: transform var(--transition), background var(--transition);
}
.fab:hover { background: var(--color-accent-hover); transform: scale(1.05); }
.fab:active { transform: scale(0.96); }
```

- Ícone: reaproveitar o SVG `scan-eye` já definido (e hoje não usado) em `_sidebarIcon()` (`includes/layout.php:28`) — evita criar um ícone novo.
- Alvo de toque generoso (56px) pensando em uso em tablet de chão de fábrica, possivelmente com luvas.
- Em telas muito pequenas, permanece fixo (não deve ser coberto por navegação mobile, se houver).

### 2.4 Overlay/Modal do Leitor de QR

Reaproveita o backdrop já existente (`.modal-overlay`, `main.css`), mas o conteúdo interno é novo (não é o `.modal-box` de formulário do Retrabalho — é uma área de câmera em tela cheia).

| Estado | UI | Observação |
|---|---|---|
| **Solicitando permissão** | Overlay abre já mostrando um placeholder com spinner + texto "Solicitando acesso à câmera…" | Prompt nativo do navegador aparece por cima |
| **Lendo (câmera ativa)** | Vídeo em tela cheia + moldura/guia de leitura (cantos em L sobre o centro do vídeo) + linha de scan animada + texto "Aponte a câmera para o QR Code do transformador" + botão "Digitar manualmente" sempre visível abaixo | Loop de decodificação em segundo plano |
| **Sucesso (decodificado)** | Flash de borda verde (~400ms) + ícone de check + vibração tátil opcional (`navigator.vibrate(100)`) → overlay fecha automaticamente e os campos da Área de Leitura são preenchidos | Trava novas leituras nesse ciclo (debounce) até o overlay reabrir |
| **Erro — permissão negada** | Ícone de câmera bloqueada + texto explicando + botão único "Digitar manualmente" (câmera não é obrigatória) | Detecta `NotAllowedError` do `getUserMedia` |
| **Erro — sem câmera disponível** | Mesma tela de fallback manual, sem sequer tentar abrir vídeo | Detecta ausência de `navigator.mediaDevices` ou dispositivo de vídeo |
| **Erro — timeout de leitura (30–45s sem detectar nada)** | Texto de dica progressiva ("Não conseguimos ler o código. Verifique a iluminação ou aproxime o QR.") + botão manual reforçado | Não fecha sozinho — deixa o operador decidir |
| **Erro — código não encontrado** | Após decodificar, se o N° de série não existir na base: banner vermelho "Transformador não encontrado" + botão "Ler novamente" | Não preenche a Área de Leitura |
| **Erro — conflito (já em andamento em outra estação)** | Banner amarelo (`--color-warning`) explicando o conflito, com detalhe de onde o transformador está | Bloqueia o botão "Confirmar entrada" até o conflito ser resolvido |
| **Fallback manual** | Campo de texto simples (`.form-control`) para digitar o N° de série + botão "Buscar" | Sempre acessível a partir de qualquer estado de erro, sem precisar reabrir o overlay |

> **Nota de implementação:** o estado "código não encontrado" saiu diferente — não existe mais "Transformador não encontrado" com "Ler novamente" bloqueando tudo. A leitura sempre fecha o overlay e leva para a Área de Leitura (mesmo quando o projeto não resolve), que aí sim mostra um banner "Projeto não cadastrado" com o texto "cadastre o Pedido/Projeto em Pedidos e Projetos antes de registrar" e desabilita o botão "Registrar" — sem opção de novo scan direto desse estado, o operador cancela e tenta de novo depois de cadastrar. O estado de conflito saiu como especificado.

### 2.5 Botão "Confirmar entrada"

Aparece dentro da Área de Leitura assim que há dados preenchidos (por scan ou digitação manual) e ainda não confirmados.

- Estado padrão: `.btn-primary`, texto "Confirmar entrada no Laboratório".
- Estado carregando: spinner inline + `disabled`, enquanto o POST está em andamento.
- Estado erro de rede: banner de erro acima do botão + botão volta a ficar clicável (retry), **sem perder os dados já lidos**.
- Botão secundário "Cancelar leitura" ao lado, que limpa a Área de Leitura de volta ao estado vazio.

---

## 3. Fluxo do Usuário

```
[Card LAB em estado vazio, FAB visível]
        │
        ▼
1. Operador toca no FAB (⌖)
        │
        ▼
2. Overlay abre em tela cheia → solicita permissão de câmera
        │
        ├── Permissão negada / sem câmera ──► [Fallback manual: digitar NS] ──┐
        │                                                                     │
        ▼ (permissão concedida)                                              │
3. Câmera ativa, vídeo ao vivo + moldura de leitura                          │
        │                                                                     │
        ├── 30–45s sem detectar nada ──► dica de erro + reforça botão manual │
        │                                                                     │
        ▼ (QR detectado)                                                     │
4. Flash verde + vibração + overlay fecha automaticamente                    │
        │                                                                     │
        ▼                                                                    │
5. Sistema extrai o N° de série do payload do QR                             │
        │                                                                     │
        ├── Não encontrado na base ──► banner "Transformador não encontrado" │
        │                              + "Ler novamente" (volta ao passo 2)  │
        │                                                                     │
        ├── Já em andamento em outra estação ──► banner de conflito,         │
        │                                          bloqueia confirmação      │
        │                                                                     │
        ▼ (válido)                                                           │
6. Campos da Área de Leitura são preenchidos automaticamente ◄───────────────┘
   (N° de série, Projeto/Pedido resolvidos no servidor), estilo "auto",
   ainda editáveis manualmente
        │
        ▼
7. Operador revisa os dados (pode corrigir manualmente se necessário)
        │
        ▼
8. Operador toca em "Confirmar entrada no Laboratório"
        │
        ├── Falha de rede ──► mensagem de erro, mantém dados, permite retry
        │
        ▼ (sucesso)
9. POST registra a entrada → Card do Laboratório muda para "Em andamento"
   com destaque verde temporário e cronômetro iniciado
```

---

## 4. Boas práticas de UX (recomendações)

- **Feedback visual acima de sonoro**: chão de fábrica é ambiente barulhento — priorizar moldura de leitura, linha de scan animada e flashes de cor (verde/vermelho) em vez de depender de som. Vibração tátil (`navigator.vibrate`) como reforço opcional, nunca como único sinal.
- **Fallback manual sempre disponível**, nunca uma tela "só câmera" — etiquetas de QR danificadas, iluminação ruim ou tablets sem câmera são cenários reais de chão de fábrica.
- **Debounce contra leitura duplicada**: travar novas decodificações assim que a 1ª leitura da sessão for bem-sucedida, até o overlay ser reaberto — evita disparar múltiplas leituras do mesmo código por tremor de mão/QR ainda em quadro.
- **Encerrar a câmera corretamente**: parar todas as tracks do `MediaStream` (`track.stop()`) ao fechar o overlay em qualquer estado — evita dreno de bateria e mantém o indicador de câmera do navegador/SO coerente.
- **Timeout de leitura** (30–45s) com dica progressiva, em vez de deixar a câmera rodando indefinidamente sem feedback de que algo está errado.
- **Alvos de toque grandes**: FAB de 56px, botão "Confirmar entrada" em largura confortável — considerar uso com luvas em tablet fixo na estação.
- **Confirmação explícita antes de salvar** (decisão já validada): nunca gravar um lançamento de produção só pela leitura bruta do QR — exigir o toque em "Confirmar entrada" dá ao operador a chance de corrigir uma leitura errada antes que ela vire dado.
- **Campos "auto" continuam editáveis**: seguir o mesmo princípio já usado no Retrabalho (`.rt-field.auto`) — dado derivado automaticamente não deve travar o campo, só sinalizar visualmente a origem. *(Não seguido na implementação — ver nota em 2.2.)*
- **Acessibilidade**: região `aria-live="polite"` para anunciar mudanças de estado do scanner (sucesso, erro, "transformador não encontrado") a leitores de tela, já que o feedback é majoritariamente visual.
- **Não silenciar erros de estação**: se o polling/atualização do card do Laboratório falhar, mostrar um estado de erro explícito com "Tentar novamente" em vez de deixar o card com dado desatualizado sem indicação.

---

## 5. Notas técnicas para implementação futura (fora do escopo desta especificação de UX, registradas para não se perderem)

> As 4 notas abaixo eram recomendações prospectivas; a coluna implica o que de fato aconteceu.

- **Biblioteca de leitura de QR**: nenhuma existe hoje no projeto. Recomenda-se `html5-qrcode` via CDN (mesmo padrão do Chart.js já usado — sem build step), com possibilidade de usar a API nativa `BarcodeDetector` como caminho mais leve em navegadores que a suportam, caindo para `html5-qrcode` nos demais. → **Implementado com `jsQR`** (não `html5-qrcode`, sem `BarcodeDetector`) — decodificação em `<canvas>` a partir do `<video>` ou de uma imagem enviada (fallback extra não previsto aqui).
- **Formato do payload do QR**: não existe convenção definida ainda. Recomenda-se manter o payload mínimo — apenas o `ns_transformador` (mesmo nome de campo já usado em `retrabalhos.ns_transformador`) — resolvendo Projeto/Pedido via consulta ao servidor, em vez de embutir tudo no código impresso (reduz risco do QR ficar desatualizado se o cadastro mudar). → **Não seguido**: o payload real é a etiqueta de OF do chão de fábrica (prefixo(5)+`cd_of`+sufixo(1)), resolvida contra a planilha externa `NS.OF.xlsx` (`includes/planilha-ns-of.php`) — N° de série puro só é aceito como fallback quando o código não bate com esse formato.
- **Tabela nova**: um lançamento de "entrada em estação" provavelmente exige uma tabela própria do módulo Produção (ex.: `producao_etapas` ou similar), seguindo as convenções já documentadas em `PROJETO-SGT.md` (soft delete via `deleted_at`, `status` sempre entre crases, `created_at`/`updated_at`, FK `id_responsavel`) — a ser definida junto do formulário completo do módulo, não apenas desta 1ª atividade. → **Implementado**: `producao_etapas` + `producao_transformadores`, ver `_inicial/migrar-producao.sql` (inclui coluna gerada `ns_ativo` + índice único, que a nível de banco impede duas linhas `em_andamento` simultâneas para o mesmo N° de série — proteção que este spec não havia antecipado).
- **Endpoint**: seguindo a convenção `api/<modulo>-acao.php` já usada por Retrabalho/Projetos, esperado algo como `api/producao-acao.php` com uma ação de confirmação de entrada. → **Implementado**: `api/producao-acao.php`, com 4 ações (`ler`, `confirmar`, `status`, `remover_etapa` — só a última não fazia parte desta previsão, ver seção 6).

---

## 6. Lista de Registros + Reprovar (implementado, fora do escopo original deste documento)

Esta seção cobre uma tela que não fazia parte da especificação original acima — é a tela que, no padrão de 3 partes do módulo (`PROJETO-SGT.md`, "Conceito: gestão de lançamentos"), cumpre o papel de **tabela de acompanhamento** para o Produção, no mesmo espírito da Relação de Retrabalhos (`pages/retrabalho/relacao.php`). Registrada aqui para não ficar sem documentação.

**Tela:** `pages/producao/lista.php`, JS em `assets/js/producao-lista.js`, item de sidebar "Lista" (par do item "Registro", que é a tela das seções 1–5 acima).

**Listagem:** lê `producao_etapas` (join com `projetos`/`pedidos`/`usuarios`) com:
- Abas por estação: Todas / IQF / LAB / GER
- Busca textual: N° de série, código de projeto, número de pedido, nome do responsável
- Filtro por mês (`data_inicio`), populado a partir dos meses que de fato têm registro
- Checkboxes "Mostrar" por `status`: rótulo **Não iniciado** para `em_andamento`, **Finalizado** para `finalizado` — na prática, hoje nenhuma ação do sistema grava `finalizado`; a única forma de uma etapa sair de `em_andamento` é via o fluxo de Reprovar abaixo (soft delete, não uma finalização)
- Colunas ordenáveis por clique (N° de série, Projeto, Pedido, Estação, Status), com seta indicando direção ativa
- Paginação configurável (10/25/50/100 por página)

**Ação "Reprovar" (ponte manual para o Retrabalho, sem tabela compartilhada entre os módulos):** cada linha tem um botão "Reprovar" que abre um modal com Pedido/Projeto/N° de série travados (somente leitura, vindos do próprio registro) e 1+ blocos repetíveis de "Código da Reprovação" — mesmo catálogo `reprovas` do Retrabalho (descrição/família/local preenchidos automaticamente ao escolher o código; blocos adicionais via "+ Adicionar outra reprova"). Ao submeter:
1. O JS envia uma requisição por reprova, em sequência, para `api/retrabalho-acao.php` (ação `registrar`) — cada uma cria 1 linha nova em `retrabalhos`. Para no primeiro erro (não deixa metade registrada silenciosamente).
2. Só depois de todas confirmadas, chama `api/producao-acao.php` (ação `remover_etapa`, por `ns_transformador`) para soft-deletar a etapa correspondente e tirá-la da Lista.

**Nota de estilo (desvio da seção 0 deste documento):** a seção 0 recomendava reaproveitar os tokens globais de `assets/css/main.css` em vez de recriar classes locais como o `rt-*` do Retrabalho. A Lista de Registros não seguiu essa recomendação — define seu próprio conjunto de classes locais `lst-*` num `<style>` inline dentro de `lista.php`, no mesmo padrão que o `rt-*` do Retrabalho já usava.
