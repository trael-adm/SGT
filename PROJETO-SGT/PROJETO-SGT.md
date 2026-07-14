# SGT — Sistema de Gestão Trael
## Referência viva do projeto — leia antes de qualquer tarefa

---

## O que é este projeto

O **SGT** é um hub/portal web de **gestão de lançamentos** da fábrica Trael (transformadores elétricos), onde **gerentes e supervisores** registram e acompanham os **lançamentos operacionais** de cada área — uma tela inicial ("Selecione um sistema") com um card de acesso por módulo:

| Módulo | Área | Descrição | Status |
|---|---|---|---|
| **SGT** | Engenharia | Gestão de demandas, projetos e etapas de produção | ✅ implementado |
| **SOMA** | PCP | Análise de tempos: peça/hora e dados de produção | 🚧 a construir |
| **Produção** | Operação | Acompanhamento das etapas no chão de fábrica | 🚧 a construir |
| **Retrabalho** | Qualidade | Registro e acompanhamento de retrabalhos | ✅ implementado |
| **5S** | Organização | Auditorias e checklists de 5S por setor | 🚧 a construir |
| **Ausências** | Pessoas | Faltas, férias e afastamentos da equipe | 🚧 a construir |
| **Incidentes** | Segurança | Registro e acompanhamento de incidentes no processo | 🚧 a construir |
| **Perdas** | Descartes | Lançamento de descartes do setor: sucatas e perdas | 🚧 a construir |
| **Paradas** | Operação | Registro de paradas de máquina: motivos e tempo | 🚧 a construir |

Todos os módulos vivem no mesmo app: mesma stack, mesmo login/perfis, mesma base de dados. O **Retrabalho** é o primeiro módulo de lançamentos implementado; os demais estão listados como escopo futuro. Conforme cada módulo é construído, ganha sua própria seção de documentação neste arquivo.

---

## Conceito: gestão de lançamentos

O SGT é **gerencial** — a operação registra e a gestão acompanha. Cada módulo representa um **tipo de lançamento** (um retrabalho, uma perda, uma parada, um incidente, uma auditoria 5S, uma ausência) e entrega três coisas:

1. **Formulário de lançamento** — o supervisor registra a ocorrência (setor, data, responsável, motivo/categoria, a métrica da área — quantidade, tempo ou valor — e observações).
2. **Painel gerencial** — KPIs, gráficos e ranking (por setor, período, motivo) para leitura rápida da gestão.
3. **Tabela de acompanhamento** — lista dos lançamentos com filtros e ciclo de status **definido por cada módulo**. Padrão de referência: aberto → em andamento → concluído. Ex. atual do **Retrabalho**: **Agu. Abertura → Agu. Causa Raiz → Finalizado**, derivado automaticamente dos dados (data de finalização e causa raiz), sem seleção manual.

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
│   └── projetos-acao.php  ← cadastro de apoio (pedidos/projetos)
├── config/
│   ├── conexao.php        ← conexão PDO + carga de ambiente (getDB, APP_URL)
│   ├── session.php        ← sessão + helpers de acesso (requireLogin/requirePerfil)
│   └── versao.php         ← APP_VERSION
├── includes/
│   ├── header.php / sidebar.php / footer.php
│   ├── layout.php         ← wrapper de layout das páginas autenticadas
│   └── helpers.php        ← funções utilitárias globais
├── pages/
│   ├── retrabalho/        ← 1º módulo de lançamentos (form + painel + tabela)
│   └── projetos/          ← cadastro de apoio: pedidos e projetos (usado pelo Retrabalho)
├── assets/
│   ├── css/main.css       ← design system
│   └── js/                ← app.js (global) + retrabalho.js / projetos.js (por tela)
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

**Por módulo:** cada módulo adiciona sua(s) própria(s) tabela(s) de lançamentos. O módulo **Retrabalho** usa `retrabalhos` (lançamentos) + `pedidos` e `projetos` (cadastro próprio, tela `pages/projetos/`, vinculado 1 pedido → N projetos) + `reprovas` (tabela de referência das contenções: código, família, descrição, local IQF/LAB/GER). Novos módulos criam suas tabelas seguindo as convenções abaixo, sem alterar as de infraestrutura.

**Migrações incrementais:** mudanças de schema em banco já existente (local + Railway) são versionadas como scripts em `_inicial/migrar-<descrição>.sql`, idempotentes, aplicados manualmente (`mysql -u root trael_db < _inicial/migrar-....sql`). `database.sql` não é reeditado retroativamente — ele reflete o estado inicial; o estado atual é `database.sql` + migrações aplicadas em ordem.

**Convenções obrigatórias**
- Campo `status` é palavra reservada — sempre entre crases: `` `status` ``
- Soft delete com `deleted_at TIMESTAMP NULL DEFAULT NULL` — toda query padrão filtra `WHERE deleted_at IS NULL`
- Datas/timestamps padrão `created_at`/`updated_at`; auditoria de autor via `id_criador`/`id_responsavel`
- `ativo BOOLEAN` em `usuarios` = suspensão temporária (diferente de exclusão lógica)
- `e_executor BOOLEAN` em `usuarios` = pode registrar lançamentos, independente do perfil
- Regra do Retrabalho: `ns_transformador` é único **por projeto** — o mesmo N° de série não pode estar vinculado a outro projeto (validado em `api/retrabalho-acao.php`, não é constraint de banco)

### Autenticação
- Login por e-mail + senha em `login.php`
- Senhas: `password_hash()` com `PASSWORD_BCRYPT`
- Sessão PHP armazenada no banco (`php_sessions`) para compatibilidade com Railway (armazenamento efêmero)
- Proteção contra força bruta e logs de acesso em `logs_atividade`

### Perfis e controle de acesso
- IDs fixos: 1=Administrador, 2=Planejador, 3=Executor, 4=Dashboard, 5=Cliente Interno
- Flag `e_executor`: usuários com esta flag (de qualquer perfil) podem registrar lançamentos operacionais
- Cada módulo pode restringir telas por perfil via `requirePerfil([...])` (`config/session.php`)

### Visual
Tokens via CSS variables em `assets/css/main.css`. Tailwind CSS (CDN) para utilitários.
- Verde Trael (`#1a3d2a` / `#0e2c1d`) como base; Âmbar (`#e8a020` / `#E89B1C`) para destaques
- Fontes: Inter / Manrope (UI)

### Funções centralizadas
- `includes/helpers.php` — funções utilitárias globais (formatação pt-BR, datas, etc.)
- `assets/js/app.js` — JavaScript global (toasts/alertas, utilidades de UI)

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

---

## Roteiro de sprints

| Sprint | Escopo | Status |
|---|---|---|
| **1 — Fundação** | Banco + login + perfis + sessão | ✅ |
| **2 — Hub** | Tela inicial "Selecione um sistema" (cards dos módulos) + navegação | ✅ |
| **3 — Retrabalho** | 1º módulo de lançamentos: formulário + painel (KPIs/gráficos) + tabela | ✅ |
| **4+ — Demais módulos** | Um por vez, mesma stack: Perdas → Paradas → Incidentes → 5S → Ausências → SOMA → Produção — escopo definido antes de começar | 🗓 Planejada |

**Regra:** cada sprint só começa após validação e confirmação da anterior.

---

## Como atualizar o changelog

- Versão do sistema em `config/versao.php` (`APP_VERSION`) — exibida automaticamente na interface.
- Histórico de versões mantido em `pages/changelog.php` (sem tabela no banco): inserir o bloco da nova versão antes do anterior.
- Commitar `config/versao.php` + `pages/changelog.php` com mensagem `feat:`/`fix: vX.Y.Z — …`.
