# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Documento de referência (leia sempre primeiro)

`_inicial/PROJETO-GFT.md` é a **referência viva** e a fonte de verdade sobre stack, convenções, schema e decisões técnicas — muito mais detalhado que este arquivo. Consulte-o antes de qualquer tarefa e **atualize-o** a cada mudança de código (doc viva, junto com o changelog). Este CLAUDE.md é só o cartão de regras rápidas: cada item aponta "o quê"; o "porquê/como/versão" mora no PROJETO-GFT.md.

Quando um pedido divergir de algo registrado no PROJETO-GFT.md, **alerte antes de executar** e só prossiga após confirmação — depois atualize o documento.

## O que é

SGE (ex-GFT) — sistema web de controle de demandas de engenharia para uma fábrica de transformadores elétricos (Trael): do cadastro pelo Planejador à execução por engenheiros/técnicos, com Check List de qualidade ISO-9001 (F-72) e controle de jornada.

Todo o domínio, UI e código de negócio estão em **português** — nomes de funções, variáveis, comentários e commits. Mantenha esse idioma ao escrever código novo.

## Stack

- **PHP 8.4 vanilla**, sem frameworks. `declare(strict_types=1)` no topo de todo arquivo PHP.
- **MySQL via PDO**, sempre pela singleton `getDB()` de `config/database.php` — nunca instancie PDO direto nas páginas.
- **Front-end sem build step**: Tailwind via CDN, CSS próprio em `assets/css/main.css` (tokens via CSS variables), JS vanilla em `assets/js/app.js`. Sem jQuery/React/Alpine. SortableJS via CDN só na Gestão de Cards.

## Rodar localmente

Servido pelo Laragon em `http://localhost/gft` (Apache + PHP). Não há build, lint nem suíte de testes. Para validar, abra a tela no navegador — **o usuário testa UX/visual no navegador; não monte automação local pra isso.**

- **Migrações de banco:** manuais, rodadas à mão no LOCAL e na PROD; entregues como **`.sql`** (o antivírus do dev apaga `.php` novo com `ALTER TABLE ... ADD COLUMN`). Schema completo em `_inicial/database.sql`. Ver PROJETO-GFT.md.
- **Deploy (produção):** `git push` dispara o build no Railway (Dockerfile na raiz), que fica em **NEEDS_APPROVAL** — só vai ao ar após aprovação manual no dashboard. Produção usa só as vars `MYSQL*`. Ver PROJETO-GFT.md.

## Fluxo de trabalho com git

- **Releases** vão **direto na `main`**, sem PR. O **dia a dia** de uma feature em andamento pode viver num branch **`dev`** (trabalho em casa/no trabalho, **sem disparar Railway** — só a `main` builda); a `main` recebe apenas o commit **versionado** no merge. Fluxo completo, rotina "vou embora/cheguei" e o handoff `_inicial/handoff-dev.md` estão no PROJETO-GFT.md.
- Mensagens em **português**, com a versão (ex.: `feat: v1.9.2 — ...`). A versão fica em `config/versao.php` (`APP_VERSION`).
- **Merge de release leva `-m` explícito:** `release: v1.x.x — <título>`. Nunca o `Merge branch 'dev' — …` padrão do git — o painel do Railway lista o assunto do commit e a frase faz parecer que a `dev` está sendo publicada (é sempre a `main`). Ver PROJETO-GFT.md.
- **Espere autorização explícita antes de commitar/pushar.** Não commite por iniciativa própria — peça o "ok" a cada commit.
- Versionamento SemVer adaptado: feature → minor, fix → patch, uma release = um número; não renumere o passado.

## Arquitetura

- **Roteamento por perfil:** `index.php` redireciona conforme `id_perfil` (1=Admin, 2=Planejador, 3=Executor, 4=Dashboard-TV, 5=Cliente, 6=Visualizador). A flag `usuarios.e_executor` (independente do perfil) dá acesso à Execução e a receber tarefas.
- **Página autenticada:** `require` de `database.php` + `session.php` → guard (`requireLogin()` / `requirePerfil([...])`) → `layoutHeader($titulo)` / `layoutFooter()` de `includes/layout.php` (injetam sidebar/header/footer/assets; ícones Heroicons SVG inline em `_sidebarIcon()`).
- **APIs** (`api/*.php`): endpoints `fetch` → JSON, allow-list de perfil no topo, POST pra escrita. URLs montadas com `window.__APP_BASE` — **nunca** prefixo fixo `/gft/` (404 na produção, que roda na raiz).
- **Sessão no banco** (`php_sessions` via `GftDbSessionHandler`) — o filesystem do Railway é efêmero.
- **Regras de negócio centralizadas** em `config/regras-negocio.php` (**fonte única**: deps entre etapas, desbloqueio, status inicial, controle opcional, cadeia da Tarefa Avulsa, gates do Check List, conclusão de demanda). Helpers globais em `includes/helpers.php`.
- **Domínio:** Demanda (Projeto Novo, Correção, Reforma, Tarefa Avulsa) → Etapas encadeadas com dependências → Tarefas (`fazer`/`controlar`) alocadas a Executores. Tabela de etapas e deps no PROJETO-GFT.md.
- **Check List F-72** (qualidade ISO, só Projeto Novo): revisões imutáveis, 3 famílias (dt/mf_oleo/seco), ficha fixada na criação, gateia o avanço de cada etapa FAZER. Lógica em `regras-negocio.php` (`gftChecklist*`) + `includes/checklist-render.php` / `tarefa-painel.php`. Ver PROJETO-GFT.md.
- **Jornada e notificações** (v1.9.0): trava de inatividade + lembretes de horário em toda tela de executor (`assets/js/notificacoes-jornada.js`), âncora de tempo no servidor (`api/jornada-hoje.php`). Ver PROJETO-GFT.md.

## Convenções obrigatórias do banco

- `` `status` `` é palavra reservada — sempre entre crases.
- Durações em **minutos** (`INT`); conversão para horas no PHP.
- **Soft delete**: `deleted_at TIMESTAMP NULL` — toda query padrão filtra `WHERE deleted_at IS NULL`.
- `usuarios.ativo` = suspensão temporária (≠ exclusão lógica).
- Acentos de nomes legados gravados como `??` (0x3F), irrecuperável — casamentos por âncora ASCII (`%Gerente%`) são intencionais.

## Cuidados que já causaram bugs (detalhe no PROJETO-GFT.md)

- **Polling espelha os filtros da tela:** `api/polling-carga.php` e `api/fila-partial.php` devem repetir os `WHERE` de status das telas que servem, senão detectam "tarefa nova" falsa e re-renderizam/recarregam em loop.
- **JSON em `onclick`:** nunca embuta `json_encode` numa string JS literal — um apóstrofo quebra o argumento. Guarde em `data-*` e leia com `getAttribute`.
- **`.dockerignore`:** só entra o que nenhum `require`/`include`/leitura de runtime da produção alcança. `uploads/` **não** é ignorado; `_inicial/` e `scripts/` são.
