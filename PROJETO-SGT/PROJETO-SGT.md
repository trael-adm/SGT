# SGT — Sistema de Gestão Trael
## Referência viva do projeto — leia antes de qualquer tarefa

---

## O que é este projeto

O **SGT** é um hub/portal web de **gestão de lançamentos** da fábrica Trael (transformadores elétricos), onde **gerentes e supervisores** registram e acompanham os **lançamentos operacionais** de cada área — uma tela inicial ("Selecione um sistema") com um card de acesso por módulo:

| Módulo | Área | Descrição | Status |
|---|---|---|---|
| **SGT** | Engenharia | Gestão de demandas, projetos e etapas de produção | ✅ implementado |
| **SOMA** | PCP | Análise de tempos: peça/hora e dados de produção | 🚧 a construir |
| **Produção** | Operação | Acompanhamento das etapas no chão de fábrica | 🚧 em construção — 1ª atividade (estação LAB + leitura de QR) + Lista de Registros implementadas |
| **Retrabalho** | Qualidade | Registro e acompanhamento de retrabalhos | ✅ implementado |
| **5S** | Organização | Auditorias e checklists de 5S por setor | 🚧 a construir |
| **Ausências** | Pessoas | Faltas, férias e afastamentos da equipe | 🚧 a construir |
| **Incidentes** | Segurança | Registro e acompanhamento de incidentes no processo | 🚧 a construir |
| **Perdas** | Descartes | Lançamento de descartes do setor: sucatas e perdas | 🚧 a construir |
| **Paradas** | Operação | Registro de paradas de máquina: motivos e tempo | 🚧 a construir |

Todos os módulos vivem no mesmo app: mesma stack, mesmo login/perfis, mesma base de dados. O **Retrabalho** é o primeiro módulo de lançamentos implementado; o **Produção** teve sua 1ª atividade implementada (card da estação Laboratório + leitura de QR — especificação completa em `PROJETO-SGT/producao-tela-spec.md`) junto com a Lista de Registros (listagem + Reprovar, ponte para o Retrabalho), com as estações IQF/GER e o painel gerencial ainda pendentes; os demais módulos seguem como escopo futuro. Conforme cada módulo é construído, ganha sua própria seção de documentação neste arquivo.

---

## Conceito: gestão de lançamentos

O SGT é **gerencial** — a operação registra e a gestão acompanha. Cada módulo representa um **tipo de lançamento** (um retrabalho, uma perda, uma parada, um incidente, uma auditoria 5S, uma ausência) e entrega três coisas:

1. **Formulário de lançamento** — o supervisor registra a ocorrência (setor, data, responsável, motivo/categoria, a métrica da área — quantidade, tempo ou valor — e observações).
2. **Painel gerencial** — KPIs, gráficos e ranking (por setor, período, motivo) para leitura rápida da gestão.
3. **Tabela de acompanhamento** — lista dos lançamentos com filtros e ciclo de status **definido por cada módulo**. Padrão de referência: aberto → em andamento → concluído. Ex. atual do **Retrabalho**: **Agu. Abertura → Agu. Causa Raiz → Finalizado**, derivado automaticamente dos dados (data de finalização e causa raiz), sem seleção manual. Um módulo pode ganhar telas de listagem complementares além da tabela do painel — ex.: **Relação de Retrabalhos** (`pages/retrabalho/relacao.php`), com abas por estação, filtros, colunas ordenáveis e paginação, mais duas colunas calculadas: **flag de urgência** (verde/amarelo/laranja/vermelho por dias úteis parado, `diasUteisEntre()` em `includes/helpers.php`) e **reincidência** (quantas vezes o mesmo N° de série já apareceu no retrabalho); e a **Lista de Registros** do **Produção** (`pages/producao/lista.php`), com o mesmo padrão de abas/filtros/ordenação/paginação e uma ação **Reprovar** que registra a reprovação diretamente em `retrabalhos` (ponte manual entre os dois módulos, sem tabela compartilhada — ver "Módulo Produção" abaixo).

**Sem modelo de dados único obrigatório:** cada módulo define o **seu próprio** conjunto de campos e sua tabela conforme a natureza da área — não há schema comum imposto entre módulos. O que se mantém padrão é a experiência (formulário + painel + tabela) e as convenções técnicas de banco (soft delete, status, auditoria).

**Telas de apoio (cadastro):** um módulo pode depender de cadastros auxiliares fora do fluxo padrão (formulário + painel + tabela) — ex.: o **Retrabalho** depende do cadastro de **Pedidos e Projetos** (`pages/projetos/`), que alimenta os selects do formulário de lançamento. Essas telas de apoio têm acesso próprio na sidebar, mas não aparecem como card no hub.

---

## Stack obrigatória

> Vale para o hub inteiro e para todos os módulos — mesma stack, sem framework novo nem stack paralela.

**Back-end**
- PHP 8.4 vanilla — sem frameworks; `declare(strict_types=1)` em todo arquivo PHP
- MySQL via PDO — conexão singleton `getDB()` em `config/conexao.php`

**Front-end**
- Tailwind CSS via CDN play (`cdn.tailwindcss.com`) — sem build step, sem config file
- `assets/css/main.css` — sistema de design próprio com CSS variables
- `assets/js/app.js` — JavaScript global, carregado via `includes/footer.php`
- JavaScript vanilla — sem jQuery, sem React, sem Alpine
- Chart.js via CDN (`cdn.jsdelivr.net/npm/chart.js`) — gráficos do painel gerencial (usado no Retrabalho, `chartRanking`/`chartDonut`); padrão para os demais módulos
- jsQR via CDN (`cdn.jsdelivr.net/npm/jsqr`) — leitura de QR Code por câmera ou imagem enviada (usado no Produção, `assets/js/producao.js`)

**Layout**
- Páginas autenticadas usam `layoutHeader()` + `layoutFooter()` de `includes/layout.php` (incluem sidebar, header, footer, fontes e assets automaticamente)

**Deploy (produção)**
- Railway com `Dockerfile` na raiz (`php:8.3-cli` + `pdo pdo_mysql`)
- `.env` (local, gitignored) e variáveis Railway (`MYSQLHOST`, `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD`, `MYSQLPORT`, `APP_URL`, `APP_ENV`)
- **Base de URL no JS:** montar `fetch` internos com `APP_URL` (`window.__APP_BASE`), nunca com prefixo fixo de pasta — evita 404 entre local e produção.

---

## Regras de comportamento

**Escopo de trabalho**
- Faça **apenas** o que for pedido na tarefa atual
- Não crie arquivos além dos solicitados
- Não refatore, não renomeie, não reorganize código que não foi pedido
- Ao terminar cada tarefa, liste exatamente o que foi criado/alterado e aguarde validação
- Nunca pule para a próxima etapa sem confirmação

**Conflito com este documento**
- Este documento descreve o **estado atual acordado** do projeto — não é uma trava imutável
- Quando um pedido divergir de algo registrado aqui (stack, convenção, estrutura, decisão técnica), **alertar antes de executar**:
  > "Este pedido conflita com [seção/regra]. Confirma que quer prosseguir? Se sim, atualizo o documento também."
- O pedido do usuário sempre prevalece após confirmação
- Após confirmação, atualizar este documento para refletir a nova decisão
- Se tiver dúvida sobre conflito, **pergunte antes de fazer**

---

## Estrutura de pastas do projeto

```
SGT/
├── _inicial/              ← referência (não subir para produção)
│   ├── database.sql       ← estrutura do banco + dados iniciais
│   └── migrar-*.sql       ← migrações incrementais (idempotentes, ver "Banco de dados")
├── api/                   ← endpoints chamados via fetch/AJAX
│   ├── retrabalho-acao.php
│   ├── projetos-acao.php  ← cadastro de apoio (pedidos/projetos)
│   └── producao-acao.php  ← ler/confirmar/status (Registro) + remover_etapa (usado pelo Reprovar da Lista)
├── config/
│   ├── conexao.php        ← conexão PDO + carga de ambiente (getDB, APP_URL)
│   ├── session.php        ← sessão + helpers de acesso (requireLogin/requirePerfil)
│   └── versao.php         ← APP_VERSION
├── includes/
│   ├── header.php / sidebar.php / footer.php
│   ├── layout.php         ← wrapper de layout das páginas autenticadas
│   ├── helpers.php        ← funções utilitárias globais
│   └── planilha-ns-of.php ← índice cd_of → N° de série/projeto/pedido (planilha externa, ver "Módulo Produção")
├── pages/
│   ├── retrabalho/        ← 1º módulo de lançamentos: index.php (form + painel) + relacao.php (listagem)
│   ├── projetos/          ← cadastro de apoio: pedidos e projetos (usado pelo Retrabalho)
│   └── producao/          ← módulo Produção: index.php (Registro — card LAB + leitura QR) + lista.php (Lista — listagem + Reprovar)
├── assets/
│   ├── css/main.css       ← design system
│   └── js/                ← app.js (global) + retrabalho.js / projetos.js / producao.js / producao-lista.js (por tela)
├── PLANILHA QUE ATUALIZA/ ← NS.OF.xlsx, planilha externa (atualizada por Power Query), lida por includes/planilha-ns-of.php
├── storage/
│   └── cache/             ← cache do índice da planilha OF (gitignored)
├── uploads/               ← arquivos enviados
├── .env                   ← credenciais locais (gitignored)
├── Dockerfile             ← build de produção (Railway)
├── index.php              ← hub "Selecione um sistema"
├── login.php / logout.php ← autenticação
└── em-breve.php           ← placeholder de módulos a construir
```

> Cada novo módulo ganha sua subpasta `pages/<modulo>/`, seguindo o mesmo padrão de `includes/layout.php` + `config/conexao.php`. Telas de apoio (cadastro) seguem a mesma convenção, mas sem entrada como card no hub — só na sidebar.

---

## Decisões técnicas — não rediscutir

### Banco de dados
Estrutura em `_inicial/database.sql`. Conexão via singleton `getDB()` em `config/conexao.php` — nunca instanciar PDO diretamente nas páginas.

**Tabelas de infraestrutura (compartilhadas pelo hub)**
| Grupo | Tabelas |
|---|---|
| Acesso | `perfis`, `alocacoes`, `usuarios`, `password_resets` |
| Operação | `setores` |
| Auditoria | `logs_atividade` |
| Sessão | `php_sessions` |

**Por módulo:** cada módulo adiciona sua(s) própria(s) tabela(s) de lançamentos. O módulo **Retrabalho** usa `retrabalhos` (lançamentos) + `pedidos` e `projetos` (cadastro próprio, tela `pages/projetos/`, vinculado 1 pedido → N projetos) + `reprovas` (tabela de referência das contenções: código, família, descrição, local IQF/LAB/GER). O módulo **Produção** (1ª atividade) usa `producao_transformadores` (registro N° de série → Projeto) + `producao_etapas` (uma linha por passagem numa estação IQF/LAB/GER; coluna gerada `ns_ativo` + índice único garantem, a nível de banco, que nunca haja 2 linhas `em_andamento` simultâneas para o mesmo N° de série) — ver `_inicial/migrar-producao.sql`. Novos módulos criam suas tabelas seguindo as convenções abaixo, sem alterar as de infraestrutura.

**Migrações incrementais:** mudanças de schema em banco já existente (local + Railway) são versionadas como scripts em `_inicial/migrar-<descrição>.sql`, idempotentes, aplicados manualmente (`mysql -u root trael_db < _inicial/migrar-....sql`). `database.sql` não é reeditado retroativamente — ele reflete o estado inicial; o estado atual é `database.sql` + migrações aplicadas em ordem.

**Convenções obrigatórias**
- Campo `status` é palavra reservada — sempre entre crases: `` `status` ``
- Soft delete com `deleted_at TIMESTAMP NULL DEFAULT NULL` — toda query padrão filtra `WHERE deleted_at IS NULL`
- Datas/timestamps padrão `created_at`/`updated_at`; auditoria de autor via `id_criador`/`id_responsavel`
- `ativo BOOLEAN` em `usuarios` = suspensão temporária (diferente de exclusão lógica)
- `e_executor BOOLEAN` em `usuarios` = pode registrar lançamentos, independente do perfil
- Regra do Retrabalho: `ns_transformador` é único **por projeto** — o mesmo N° de série não pode estar vinculado a outro projeto (validado em `api/retrabalho-acao.php`, não é constraint de banco)
- Datas/horas embutidas em JSON para o navegador: usar `isoComOffset()` (`includes/helpers.php`) para converter o DATETIME "naive" do MySQL em ISO-8601 com offset explícito — nunca embutir a string crua, ou o JS pode interpretar a hora como fuso local do dispositivo

### Autenticação
- Login por e-mail + senha em `login.php`
- Senhas: `password_hash()` com `PASSWORD_BCRYPT`
- Sessão PHP armazenada no banco (`php_sessions`) para compatibilidade com Railway (armazenamento efêmero)
- Proteção contra força bruta e logs de acesso em `logs_atividade`
- ⚠️ **Nesta cópia (SGT-dev)** a proteção contra força bruta está **desativada** em `login.php` — feito a pedido, só para destravar testes manuais da Tela de Produção. O projeto original em `c:\laragon\www\SGT` mantém a proteção normalmente. Reativar antes de qualquer deploy a partir desta cópia.

### Perfis e controle de acesso
- IDs fixos: 1=Administrador, 2=Planejador, 3=Executor, 4=Dashboard, 5=Cliente Interno
- Flag `e_executor`: usuários com esta flag (de qualquer perfil) podem registrar lançamentos operacionais
- Cada módulo pode restringir telas por perfil via `requirePerfil([...])` (`config/session.php`)

### Visual
Tokens via CSS variables em `assets/css/main.css`. Tailwind CSS (CDN) para utilitários.
- Verde Trael (`#1a3d2a` / `#0e2c1d`) como base; Âmbar (`#e8a020` / `#E89B1C`) para destaques
- Fontes: Inter / Manrope (UI)
- Componentes globais reutilizáveis além de `.card`/`.badge-*`/`.btn-*`/`.modal-*`: `.fab`/`.fab--secondary` (botão flutuante) e `.station-*`/`.scan-*` (cards de estação + área de leitura), introduzidos pelo Produção mas disponíveis a qualquer módulo (`assets/css/main.css`)

### Funções centralizadas
- `includes/helpers.php` — funções utilitárias globais (formatação pt-BR, datas, `isoComOffset()`, `diasUteisEntre()`, etc.)
- `includes/planilha-ns-of.php` — índice `cd_of → N° de série/projeto/pedido`, lido de `PLANILHA QUE ATUALIZA/NS.OF.xlsx` (planilha externa, atualizada por Power Query) via `PharData` (sem Composer/ext-zip), cacheado em `storage/cache/`; usado hoje só pela leitura de etiqueta do Produção
- `assets/js/app.js` — JavaScript global (toasts/alertas, utilidades de UI)

### Módulo Produção — 1ª atividade + Lista de Registros (implementadas)
Especificação de UX completa (1ª atividade) em `PROJETO-SGT/producao-tela-spec.md`. Cobre hoje só a estação **LAB**: card no grid de estações (`pages/producao/index.php`, item de sidebar "Registro"), leitura de QR/imagem/manual via FAB, confirmação de entrada (`api/producao-acao.php`). IQF/GER seguem como cards "em breve" no mesmo grid, mesmo padrão de card quando forem especificados.

**Leitura de etiqueta via planilha OF** (decisão tomada durante a implementação, diferente da premissa original do spec): etiquetas do chão de fábrica trazem prefixo(5) + `cd_of` + sufixo(1) **concatenados sem separador** (ex.: `1001020421786` → `cd_of` = `2042178`, descartando os 5 primeiros e o último caractere — não é um formato `prefixo | cd_of | sufixo` com `|` literal, correção feita em 2026-07-22 após teste com etiqueta real). O `cd_of` (miolo) é resolvido em `includes/planilha-ns-of.php` (`extrairCdOfDaEtiqueta()`), que lê `PLANILHA QUE ATUALIZA/NS.OF.xlsx` (fonte externa, atualizada por Power Query) e devolve N° de série + projeto (`cd_Referencia`) + descrição + pedido (`cdPedido`) + cliente. Códigos fora desse formato (curtos demais, não numéricos — N° de série digitado manualmente, QR legado) são tratados como N° de série direto, como o spec original previa.

**Leitura de QR:** `jsQR` via CDN, não `html5-qrcode` como o spec recomendava — decodificação em `<canvas>` a partir do `<video>` (câmera) ou de uma imagem enviada pelo botão secundário de upload (fallback adicional não previsto no spec original, útil quando a câmera do tablet falha ou está indisponível).

**Resolução de transformador — automática, sem cascata manual** (diferente da premissa original do spec, que previa um modo de cadastro Pedido → Projeto editável pelo operador): a Área de Leitura só exibe campos fixos, somente-leitura (N° de série, Projeto, Descrição, Pedido, Cliente) — nada é digitável ali além do N° de série/OF na leitura manual. `resolverTransformador()` em `api/producao-acao.php` decide tudo no servidor: se o N° de série já está em `producao_transformadores`, usa o vínculo salvo; senão tenta casar `cd_Referencia` da planilha com `projetos.codigo`; se nem isso resolver mas a planilha trouxer Projeto **e** Pedido da OF, marca `precisa_criar` — nesse caso o Pedido+Projeto só são criados de fato dentro da ação `confirmar` (nunca na prévia `ler`, para não gravar cadastro por um scan que o operador só olhou e cancelou), por `criarPedidoProjetoDaPlanilha()`. Se nada disso resolve o projeto, a tela mostra "Projeto não cadastrado" e **bloqueia** o registro (botão desabilitado) até o Pedido/Projeto ser cadastrado manualmente em **Pedidos e Projetos** — não há mais fallback de cadastro inline na tela de Produção.

**Ações de `api/producao-acao.php`:** `ler` (resolve um código digitado/escaneado e devolve preview — status `ok`/`conflict`/`nao_registrado`, sem gravar nada), `confirmar` (regrava tudo a partir do código bruto — nunca confia num id_projeto vindo do cliente — e insere a linha em `producao_etapas`), `status` (usado pelo polling do card a cada 20s, para manter tablets/abas sincronizados), `remover_etapa` (soft delete de uma etapa `em_andamento` por N° de série — usado hoje só pelo fluxo de Reprovar da Lista, abaixo).

**Lista de Registros** (`pages/producao/lista.php`, item de sidebar "Lista", JS em `assets/js/producao-lista.js`) — cumpre para o Produção o papel da "3. Tabela de acompanhamento" do padrão de 3 partes (ver "Conceito: gestão de lançamentos"), no mesmo espírito da Relação de Retrabalhos: abas por estação (Todas/IQF/LAB/GER), busca textual (N° de série/projeto/pedido/responsável), filtro por mês, checkboxes "Mostrar" por `status` de `producao_etapas` (rótulos da tela: **Não iniciado** = `em_andamento`, **Finalizado** = `finalizado` — hoje nenhuma ação do sistema grava `finalizado`; a única forma de uma etapa sair de `em_andamento` é via Reprovar/`remover_etapa`), colunas ordenáveis por clique e paginação (10/25/50/100). Estilo: usa classes CSS locais `lst-*` num `<style>` inline na própria página — mesmo padrão `rt-*` que o Retrabalho já usava, não os tokens globais que a seção 0 do spec recomendava para telas novas de Produção.

**Reprovar (ponte Produção → Retrabalho):** cada linha da Lista tem um botão "Reprovar" que abre um modal com Pedido/Projeto/N° de série travados (vindos do próprio registro, sem edição) e 1+ blocos repetíveis de código de reprova (catálogo `reprovas`, mesmo usado no Retrabalho — descrição/família/local preenchidos automaticamente ao escolher o código). Ao confirmar, o JS envia uma reprova por vez para `api/retrabalho-acao.php` (ação `registrar`) — cada uma vira 1 linha nova em `retrabalhos` — e só depois de todas confirmadas chama `api/producao-acao.php` (ação `remover_etapa`) para tirar a etapa da Lista. Não há tabela compartilhada entre os módulos; a ponte é essa sequência de chamadas no cliente, e para no primeiro erro em vez de deixar metade registrada silenciosamente.

**Sidebar contextual:** dentro de `pages/producao/*` (Registro + Lista), os itens de Retrabalho/Projetos somem do menu, e vice-versa dentro de Retrabalho/Relação/Projetos — único módulo com esse comportamento hoje (`includes/sidebar.php`).

---

## Glossário

| Termo | Significado |
|---|---|
| **Lançamento** | Registro operacional inserido num módulo (um retrabalho, uma perda, uma parada, um incidente, uma auditoria 5S, uma ausência) |
| **Módulo** | Sistema/área do hub (Retrabalho, Perdas, Paradas, …) — cada um com seu formulário, painel e tabela |
| **Setor / Área** | Local da fábrica onde o lançamento ocorreu (Corte, Usinagem, Solda, Montagem, Bobinagem, Pintura, Acabamento, Expedição) |
| **Responsável** | Usuário associado ao lançamento |
| **Status** | Situação do lançamento — o fluxo é definido por módulo (Retrabalho: Agu. Abertura → Agu. Causa Raiz → Finalizado, automático) |
| **Painel** | Visão gerencial do módulo (KPIs, gráficos, ranking por setor/período) |
| **Pedido** | Agrupador comercial cadastrado em `pages/projetos/`: 1 pedido → N projetos |
| **Projeto** | Código do transformador/modelo vinculado a 1 pedido; é o projeto que recebe os retrabalhos |
| **Flag de urgência** | Indicador de cor (verde/amarelo/laranja/vermelho) calculado a partir dos dias úteis que um retrabalho está parado, usado na Relação de Retrabalhos |
| **Reincidência** | Quantidade de vezes que o mesmo N° de série já apareceu no retrabalho |
| **Estação** | Ponto do chão de fábrica no módulo Produção: **IQF** (Inspeção final), **LAB** (Laboratório), **GER** (Geral) |
| **OF / cd_of** | Ordem de Fabricação — código impresso na etiqueta do transformador (prefixo(5) + `cd_of` + sufixo(1) concatenados, sem separador), usado para resolver N° de série/projeto/pedido via a planilha `NS.OF.xlsx` |

---

## Roteiro de sprints

| Sprint | Escopo | Status |
|---|---|---|
| **1 — Fundação** | Banco + login + perfis + sessão | ✅ |
| **2 — Hub** | Tela inicial "Selecione um sistema" (cards dos módulos) + navegação | ✅ |
| **3 — Retrabalho** | 1º módulo de lançamentos: formulário + painel (KPIs/gráficos) + tabela + Relação de Retrabalhos (listagem, flags de urgência, reincidências) | ✅ |
| **4 — Produção (1ª atividade + Lista)** | Card da estação Laboratório + leitura de QR/imagem/manual + confirmação de entrada (ver `PROJETO-SGT/producao-tela-spec.md`) + Lista de Registros com filtros/ordenação/paginação e Reprovar (ponte para Retrabalho) | ✅ (parcial — IQF/GER e painel gerencial pendentes) |
| **5+ — Demais módulos** | Um por vez, mesma stack: Perdas → Paradas → Incidentes → 5S → Ausências → SOMA → resto de Produção (IQF/GER, painel gerencial) — escopo definido antes de começar | 🗓 Planejada |

**Regra:** cada sprint só começa após validação e confirmação da anterior.

---

## Como atualizar o changelog

- Versão do sistema em `config/versao.php` (`APP_VERSION`) — exibida automaticamente na interface.
- Histórico de versões mantido em `pages/changelog.php` (sem tabela no banco): inserir o bloco da nova versão antes do anterior.
- Commitar `config/versao.php` + `pages/changelog.php` com mensagem `feat:`/`fix: vX.Y.Z — …`.
