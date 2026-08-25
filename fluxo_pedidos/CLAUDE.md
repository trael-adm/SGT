# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Scope: this file covers only `fluxo_pedidos/`, a self-contained module inside the larger SGT-dev PHP application. See the repo root for the main app's own conventions (`gemini.md`, `PROJETO-SGT/PROJETO-SGT.md`) — they largely do **not** apply here, by design (see "Deliberate isolation" below).

## What this module is

"Fluxo do Pedido & Acompanhamento de Produção" — tracks Trael transformer orders as they move through five factory sectors (Comercial → Engenharia → PCP → Produção → Logística), plus a detailed shop-floor production spreadsheet that breaks each transformer down into 11 production cells. Built for a manufacturing manager's view of "where is this order right now, and how long has it been there."

## Running it locally

No build step, package manager, linter, or test suite — plain PHP 8.4, executed directly by PHP's built-in server (same as the parent app's `Dockerfile`):

```
php -S localhost:8000 -t .        # run from the SGT-dev repo root, not from inside fluxo_pedidos/
```

Then open `http://localhost:8000/fluxo_pedidos/`. It must run from the repo root (not a symlinked/copied `fluxo_pedidos/` alone) because `index.php`/`setor.php` load `../assets/css/main.css` (the shared SGT design system) via a relative path.

Live data requires network access to the SQL Server host below — without it, pages still render but show "Não foi possível conectar ao SQL Server Trael."

## Deliberate isolation from the parent SGT-dev app

This module intentionally does **not** use the parent app's shared infrastructure:
- No `config/conexao.php` (MySQL `getDB()`) — it has its own connection, to a different database engine entirely (see below).
- No `config/session.php` — no login required, no `requireLogin()`/`requireAcessoModulo()`.
- No `includes/layout.php` — not registered in `includes/sidebar.php`, no card on the root `index.php` hub.
- Not reachable in the Railway production deploy in any useful way: the SQL Server host is only resolvable on Trael's internal network, and the Docker image only has `pdo_mysql` installed (no ODBC/SQL Server driver). This module is meant to run locally/on-prem for now — that's an accepted, known limitation, not a bug to fix.

The one thing it *does* now share with the parent app (as of a recent visual rebuild) is the CSS design system: `index.php`/`setor.php` load `../assets/css/main.css` plus the same Inter/JetBrains Mono fonts and Tailwind CDN that `includes/layout.php` uses, and the module's own `assets/css/style.css` only holds what's specific to it (the circuit/pipe diagram, the Excel-style column filter popup, the 11-cell grid), built on `main.css`'s CSS variables rather than duplicating its own theme. Two gotchas from that rebuild, worth knowing before touching the CSS again:
- The module's page wrapper is named `.fp-wrapper`, deliberately *not* `.app-wrapper` — `main.css` already defines `.app-wrapper` as the sidebar's flex/100vh layout container, and since CSS cascades per-property, reusing that name silently pulled in `display:flex; height:100vh; overflow:hidden` and broke the whole layout (stations rendered as full-height vertical strips). Don't rename it back.
- `style.css` re-declares `html, body { overflow: visible; height: auto; }` on purpose, overriding `main.css`'s `overflow:hidden` — that rule assumes the parent app's `.app-content` inner-scroll structure, which this standalone module doesn't have.
- UI shows no aggregate monetary (R$) or kVA totals anywhere (KPI cards, table columns) — a deliberate product decision (this module tracks order *path*, not financials). Per-unit technical specs (an individual transformer's kVA/class in the production sheet) are fine; only *summed* totals were removed. Don't reintroduce total-value/total-kVA aggregates without checking that this is actually wanted.

## Data sources

Two, both external to the SGT-dev MySQL database:

1. **SQL Server** (Trael's ERP, "Areco/VSAT") — `vsat.trael.local`, database `vsattrael`, via ODBC (`SQL Server Native Client 11.0`). Connection lives in `includes/conexao.php::getSqlServerDB()`, a lazy singleton (`static $pdoSrv`, only retries once per request via `static $tentou`). Credentials are currently hardcoded in that file rather than in `.env` — that's consistent with this module staying outside the shared config system, not an oversight to silently "fix" by moving them.
2. **`planilhas/Dados.csv`** — a follow-up feed manually exported from VSAT (semicolon-delimited, 20 columns, no header contract beyond column position). Parsed by `includes/followup_parser.php::carregarFollowupsCSV()`, which only keeps rows where column 18 (`Nome Tabela`) equals `Pedidos`; column 19 is the order id (`cdPedido`) events get grouped under. Looks for the file at a few fallback paths (`planilhas/Dados.csv`, `planilhas/dados.csv`, or the parent app's `PLANILHA QUE ATUALIZA/`), and caches the parsed result in memory for the request.

All JSON responses (`api.php`) pass through `sanitizarUtf8Recursivo()` before encoding, because data coming out of SQL Server can arrive in mixed encodings (Windows-1252 leaking into what should be UTF-8).

## The two classification "motors"

This is the core logic to understand before changing any business rule — both are pure functions over the SQL Server data, independently cacheable per-request (`static $cache`).

**`includes/motor_pedidos.php::carregarEsteiraPedidos2026()`** — loads all non-cancelled 2026 orders (joined with PCP programming, pré-programação, and almoxarifado 10/11 stock), then assigns each order to exactly one of the 5 sectors via a strict priority cascade, evaluated in this order — first match wins:

1. **LOGISTICA** — stock in almox 10/11 covers the full ordered quantity, or ERP `StatusPedido = 'ENP'`.
2. **PRODUCAO** — ≥99.9% of quantity programmed in PCP (with active OFs).
3. **PCP** — last follow-up type is 4 or 5, or the order is in pré-programação, or it's partially programmed (>0%).
4. **ENGENHARIA** — last follow-up type is 1, 3, 6, or 7, or ERP `StatusPedido = 'AGU'`.
5. **COMERCIAL** — default: no follow-up, or last follow-up type is 2 (returned to comercial).

Each order gets both `setor_atual` and a human-readable `setor_motivo`. `obterDadosSetorEspecifico($setor)` just filters this same result set.

**`includes/motor_producao.php::carregarPlanilhaProducao()`** — the shop-floor detail, one row per transformer serial number (`CtrlNumSerie`). For each transformer it extracts the project-code suffix (regex `(?:TPD|TF|TR|TS|TD)-([A-Za-z0-9]+)`), looks up the sibling component OFs by prefix (`MDA`→chassi, `BT`, `AT`, `CNC`, `MTP`→solda, `MN`, `MTQ`→pintura, `PA`, `MFL`), groups transformers into lots by that suffix, and — ordering **ascending by serial number within the lot** — marks each of 11 cells (`CH BT AT CNC SOL MN PIN ML ME MF LAB`) as `OK`/`PEND` per unit. Cells that are "1 OF per piece" (most of them) rate 1:1 against produced quantity. `BT`/`AT` (bobinagem) divide produced quantity by a per-lot multiplier inferred from `TipoNucleo`/`Fases`: `ENR`/`JC` = 2 bobinas/peça, `EMP`/`JC-TRIF` (trifásico) = 3 bobinas/peça. If you touch this rateio logic, the historical validation case is pedido/NS 850297–850300 (documented in the parent repo's `log_conversa.md`) — a real bug (sub-OFs not being found because the query only looked at the parent OF) was fixed here; don't reintroduce that regression by simplifying the sub-OF lookup.

## API surface

`api.php` is a single GET-based router (`?action=`), unauthenticated:
- `resumo_esteira` → `carregarEsteiraPedidos2026()` (feeds the circuit map).
- `setor_pedidos&setor=X` → `obterDadosSetorEspecifico($setor)`.
- `planilha_producao` (+ `limite`, `dt_inicio`, `dt_fim`, `semana`, `pedido`, `projeto`, `ns`, `status_fila`) → `carregarPlanilhaProducao(...)`.
- `detalhe_pedido&cdPedido=X` → merges the order record with its follow-up timeline, for the modal.

## Frontend structure

`index.php` (the circuit map, `assets/js/mapa.js`) and `setor.php` (per-sector table + the production spreadsheet, `assets/js/setor.js`) are separate pages — clicking a sector navigates to its own page rather than expanding inline, mirroring the pattern used by the parent app's Retrabalho module. The per-sector table (`setor.js`) renders a different column set per sector (COMERCIAL/ENGENHARIA/PCP/LOGISTICA each have their own `montarCabecalhoSetor()` branch; PRODUCAO is the 11-cell spreadsheet with its own Excel-style per-column filter popup, `.excel-th-filter-btn` / `.excel-filter-popup`).
