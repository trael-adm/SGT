<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAcessoModulo('retrabalho');

$base = defined('APP_URL') ? APP_URL : '';
$pageTitle = 'Painel e Mapa de Retrabalho';

require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>

<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">

<style>
/* ==========================================================================
   ESTILOS DO MAPA INTERATIVO DE SETORES (PAINEL DE RETRABALHO)
   ========================================================================== */
.mapa-page-container {
    max-width: 1380px;
    margin: 0 auto;
    padding: 10px 10px 40px;
    font-family: 'Plus Jakarta Sans', var(--font-sans, system-ui, sans-serif);
}

/* ─── Top Control Bar ────────────────────────────────────────────────────── */
.mapa-top-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 16px 20px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    margin-bottom: 20px;
}

.mapa-title-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
}

.mapa-title-wrap .title-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: linear-gradient(135deg, #133a27, #1e5a3d);
    color: #e8a020;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    box-shadow: 0 4px 10px rgba(19, 58, 39, 0.2);
}

.mapa-title-wrap h1 {
    font-size: 18px;
    font-weight: 800;
    color: #0f172a;
    margin: 0;
    letter-spacing: -0.02em;
}

.mapa-title-wrap p {
    font-size: 12px;
    color: #64748b;
    margin: 0;
}

.mapa-top-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.refresh-indicator {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #64748b;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 6px 12px;
}

.refresh-indicator .pulse-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    animation: pulseDot 2s infinite;
}

@keyframes pulseDot {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

.btn-ctrl {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    padding: 7px 14px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #334155;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-ctrl:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
}

.btn-ctrl.is-spinning svg {
    animation: spin 1s linear infinite;
}

@keyframes spin { 100% { transform: rotate(360deg); } }

/* ─── Metrics Cards ──────────────────────────────────────────────────────── */
.metrics-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}

.metric-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 18px;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
    position: relative;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}

.metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(15, 23, 42, 0.06);
}

.metric-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: #cbd5e1;
}

.metric-card.m-total::before { background: #3b82f6; }
.metric-card.m-urgent::before { background: #ef4444; }
.metric-card.m-retorno::before { background: #e8a020; }
.metric-card.m-triagem::before { background: #8b5cf6; }

.metric-lbl {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748b;
    margin-bottom: 4px;
}

.metric-val {
    font-size: 24px;
    font-weight: 800;
    color: #0f172a;
    font-family: 'JetBrains Mono', monospace;
}

/* ─── Search & Highlighting Bar ──────────────────────────────────────────── */
.search-glow-panel {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 16px 20px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
    margin-bottom: 24px;
}

.search-input-wrap {
    position: relative;
    display: flex;
    align-items: center;
    margin-bottom: 12px;
}

.search-input-wrap .search-icon {
    position: absolute;
    left: 14px;
    color: #94a3b8;
    pointer-events: none;
    width: 18px;
    height: 18px;
}

.search-input-wrap input {
    width: 100%;
    height: 46px;
    padding: 0 16px 0 44px;
    border-radius: 10px;
    border: 2px solid #e2e8f0;
    font-size: 14px;
    color: #0f172a;
    font-family: inherit;
    background: #f8fafc;
    transition: all 0.25s ease;
}

.search-input-wrap input:focus {
    outline: none;
    background: #ffffff;
    border-color: #e8a020;
    box-shadow: 0 0 0 4px rgba(232, 160, 32, 0.15);
}

.quick-filters-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.quick-filters-label {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    margin-right: 4px;
}

.filter-pill {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s;
}

.filter-pill:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.filter-pill.active {
    background: #133a27;
    border-color: #133a27;
    color: #ffffff;
    box-shadow: 0 2px 6px rgba(19, 58, 39, 0.25);
}

.search-result-bar {
    display: none;
    margin-top: 14px;
    padding: 10px 16px;
    border-radius: 8px;
    background: #fef3c7;
    border: 1px solid #fde68a;
    font-size: 13px;
    color: #92400e;
    align-items: center;
    justify-content: space-between;
}

.search-result-content {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    width: 100%;
}

.match-chips-row {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-left: 6px;
}

.match-sector-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 6px;
    background: #d97706;
    color: #ffffff;
    font-size: 11px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    transition: transform 0.15s;
}

.match-sector-chip:hover {
    transform: scale(1.05);
    background: #b45309;
}

.btn-clear-search {
    margin-left: auto;
    background: transparent;
    border: none;
    font-size: 12px;
    font-weight: 700;
    color: #b45309;
    cursor: pointer;
    text-decoration: underline;
}

/* ==========================================================================
   O MAPA DE FÁBRICA / CIRCUITO DOS SETORES
   ========================================================================== */
.mapa-canvas-wrap {
    background: radial-gradient(circle at 50% 30%, #ffffff 0%, #f8fafc 100%);
    border: 1px solid #cbd5e1;
    border-radius: 18px;
    padding: 36px 24px;
    box-shadow: inset 0 2px 6px rgba(0, 0, 0, 0.02), 0 10px 30px rgba(15, 23, 42, 0.04);
    position: relative;
    overflow: hidden;
}

.mapa-canvas-wrap::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image: 
        linear-gradient(to right, rgba(203, 213, 225, 0.35) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(203, 213, 225, 0.35) 1px, transparent 1px);
    background-size: 28px 28px;
    pointer-events: none;
}

.factory-diagram {
    position: relative;
    max-width: 900px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0;
    z-index: 2;
}

/* ─── Dutos de Conexão ─────────────────────────────────────────────────── */
.conduit-pipe {
    position: relative;
    transition: all 0.3s ease;
    z-index: 1;
}

.pipe-vertical {
    width: 22px;
    height: 44px;
    background: #cbd5e1;
    border-left: 2px solid #94a3b8;
    border-right: 2px solid #94a3b8;
    position: relative;
}

.center-top-pipe {
    width: 22px;
    height: 52px;
    background: #cbd5e1;
    border-left: 2px solid #94a3b8;
    border-right: 2px solid #94a3b8;
    position: relative;
    margin-top: -1px;
}

.pipe-vertical::after,
.center-top-pipe::after,
.bus-pipe-horizontal::after {
    content: '';
    position: absolute;
    inset: 0;
    background: repeating-linear-gradient(
        45deg,
        rgba(255, 255, 255, 0.55) 0px,
        rgba(255, 255, 255, 0.55) 8px,
        transparent 8px,
        transparent 16px
    );
}

.pipe-fork-container,
.pipe-converge-container {
    width: 760px;
    height: 48px;
    position: relative;
    background: transparent !important;
    box-shadow: none !important;
    border: none !important;
    z-index: 1;
}

.pipe-fork-container::after,
.pipe-converge-container::after {
    display: none !important;
}

.pipe-fork-container {
    margin: -1px auto 0;
}

.pipe-converge-container {
    margin: 0 auto 0;
}

.pipe-fork-svg {
    width: 100%;
    height: 100%;
    display: block;
    overflow: visible;
}

.pipe-fork-svg .pipe-path-outer {
    stroke: #94a3b8;
    stroke-width: 22;
    fill: none;
    stroke-linecap: square;
    stroke-linejoin: round;
    transition: stroke 0.3s;
}

.pipe-fork-svg .pipe-path-inner {
    stroke-width: 18;
    fill: none;
    stroke-linecap: square;
    stroke-linejoin: round;
    transition: filter 0.3s;
}

.factory-branches-row {
    display: grid;
    grid-template-columns: 230px 240px 230px;
    justify-content: space-between;
    width: 760px;
    position: relative;
    z-index: 2;
}

.branch-column {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.center-branch-column {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
}

.factory-bottom-row {
    display: grid;
    grid-template-columns: 200px 240px 200px;
    justify-content: space-between;
    width: 760px;
    align-items: center;
    gap: 20px;
    margin-top: 0;
    position: relative;
    z-index: 2;
}

.bus-pipe-horizontal {
    height: 22px;
    background: #cbd5e1;
    border-top: 2px solid #94a3b8;
    border-bottom: 2px solid #94a3b8;
    position: absolute;
    top: 50%;
    left: 100px;
    right: 100px;
    transform: translateY(-50%);
    z-index: 1;
}

/* ==========================================================================
   CARDS DE CADA SETOR
   ========================================================================== */
.setor-node {
    position: relative;
    background: #ffffff;
    border: 2px solid #cbd5e1;
    border-radius: 12px;
    padding: 12px 14px;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    user-select: none;
    z-index: 3;
}

.setor-node:hover {
    transform: translateY(-3px);
    border-color: #133a27;
    box-shadow: 0 10px 24px rgba(19, 58, 39, 0.15);
}

.setor-node.node-main { width: 280px; }
.setor-node.node-branch { width: 240px; }
.setor-node.node-bottom { width: 100%; }

.setor-node.is-highlighted {
    border-color: #e8a020 !important;
    background: #fffbeb !important;
    box-shadow: 0 0 0 4px rgba(232, 160, 32, 0.25), 0 12px 30px rgba(232, 160, 32, 0.35) !important;
    transform: translateY(-4px) scale(1.02);
    animation: pulseNode 2s infinite;
}

@keyframes pulseNode {
    0% { box-shadow: 0 0 0 4px rgba(232, 160, 32, 0.25), 0 12px 30px rgba(232, 160, 32, 0.35); }
    50% { box-shadow: 0 0 0 8px rgba(232, 160, 32, 0.4), 0 16px 36px rgba(232, 160, 32, 0.5); }
    100% { box-shadow: 0 0 0 4px rgba(232, 160, 32, 0.25), 0 12px 30px rgba(232, 160, 32, 0.35); }
}

.setor-node.is-dimmed {
    opacity: 0.3;
    filter: grayscale(0.6);
    transform: scale(0.98);
}

.setor-node-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.setor-info-col {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.setor-badge-row {
    display: flex;
    align-items: center;
    gap: 6px;
}

.setor-code {
    font-size: 16px;
    font-weight: 800;
    font-family: 'JetBrains Mono', monospace;
    letter-spacing: -0.02em;
    color: #0f172a;
}

.setor-node[data-setor="LAB"] .setor-code { color: #1e40af; }
.setor-node[data-setor="MF"] .setor-code { color: #6b21a8; }
.setor-node[data-setor="RET"] .setor-code { color: #d97706; }
.setor-node[data-setor="ME"] .setor-code { color: #0e7490; }
.setor-node[data-setor="BOB"] .setor-code { color: #047857; }
.setor-node[data-setor="PINT"] .setor-code { color: #b45309; }
.setor-node[data-setor="CALD"] .setor-code { color: #b91c1c; }
.setor-node[data-setor="PCP"] .setor-code { color: #334155; }
.setor-node[data-setor="ENG"] .setor-code { color: #be185d; }
.setor-node[data-setor="ALMX"] .setor-code { color: #a16207; }

.setor-name {
    font-size: 11px;
    font-weight: 600;
    color: #64748b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 130px;
}

.setor-subtext {
    font-size: 10px;
    color: #94a3b8;
    margin-top: 2px;
}

.tag-urgente {
    color: #dc2626;
    font-weight: 700;
}

.setor-count-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
}

.setor-count {
    min-width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 800;
    font-family: 'JetBrains Mono', monospace;
    padding: 0 6px;
    box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
}

.count-primary { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.count-warning { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
.count-danger  { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.count-zero    { background: #f8fafc; color: #94a3b8; border: 1px solid #e2e8f0; }

.setor-queue-slots {
    display: flex;
    flex-direction: column;
    gap: 3px;
    justify-content: center;
}

.slot-dash {
    width: 12px;
    height: 3px;
    border-radius: 2px;
    background: #e2e8f0;
}

.slot-dash.is-filled { background: #3b82f6; }
.slot-dash.is-urgent { background: #ef4444; }

.setor-match-tag {
    position: absolute;
    top: -10px;
    right: 10px;
    background: #e8a020;
    color: #ffffff;
    font-size: 10px;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 10px;
    box-shadow: 0 2px 6px rgba(232, 160, 32, 0.5);
    animation: bounceTag 0.5s ease;
}

@keyframes bounceTag {
    0% { transform: scale(0.5); }
    70% { transform: scale(1.15); }
    100% { transform: scale(1); }
}

/* ==========================================================================
   TOOLTIP FLUTUANTE DE HOVER
   ========================================================================== */
.mapa-tooltip {
    position: absolute;
    background: #0f172a;
    color: #ffffff;
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 12px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
    z-index: 9999;
    opacity: 0;
    transition: opacity 0.2s ease, transform 0.2s ease;
    width: 250px;
    pointer-events: none;
}

.mapa-tooltip::after {
    content: '';
    position: absolute;
    bottom: -6px;
    left: 50%;
    transform: translateX(-50%);
    border-width: 6px 6px 0;
    border-style: solid;
    border-color: #0f172a transparent transparent;
}

.tt-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    padding-bottom: 6px;
}

.tt-code {
    font-family: 'JetBrains Mono', monospace;
    font-weight: 800;
    font-size: 14px;
    color: #e8a020;
}

.tt-title small {
    display: block;
    font-size: 10px;
    color: #94a3b8;
}

.tt-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 4px;
    color: #cbd5e1;
}

.tt-reprovas {
    margin-top: 6px;
    padding-top: 6px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
}

.tt-reprovas .lbl {
    font-size: 10px;
    color: #94a3b8;
    display: block;
    margin-bottom: 2px;
}

.tt-reprovas .reps {
    color: #fde68a;
    font-size: 11px;
}

.tt-hint {
    margin-top: 8px;
    font-size: 10px;
    color: #38bdf8;
    text-align: right;
}

/* ==========================================================================
   DRAWER LATERAL DE INSPEÇÃO DE SETOR
   ========================================================================== */
.drawer-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.5);
    backdrop-filter: blur(2px);
    z-index: 9998;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease;
}

.drawer-overlay.is-open {
    opacity: 1;
    pointer-events: auto;
}

.setor-drawer {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: 480px;
    max-width: 90vw;
    background: #ffffff;
    box-shadow: -10px 0 30px rgba(15, 23, 42, 0.15);
    z-index: 9999;
    display: flex;
    flex-direction: column;
    transform: translateX(100%);
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.setor-drawer.is-open {
    transform: translateX(0);
}

.drawer-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
}

.drawer-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.drawer-setor-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    background: #133a27;
    color: #e8a020;
    display: flex;
    align-items: center;
    justify-content: center;
}

.drawer-setor-icon svg { width: 22px; height: 22px; }

.drawer-title-col h2 {
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
    margin: 0;
}

.drawer-title-col small {
    font-size: 12px;
    color: #64748b;
}

.btn-close-drawer {
    background: transparent;
    border: none;
    font-size: 20px;
    color: #94a3b8;
    cursor: pointer;
    padding: 6px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.btn-close-drawer:hover {
    background: #e2e8f0;
    color: #0f172a;
}

.drawer-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px 24px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.transformer-item-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
    transition: all 0.2s;
    position: relative;
}

.transformer-item-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 6px 14px rgba(15, 23, 42, 0.06);
}

.transformer-item-card.is-matched {
    border-color: #e8a020;
    background: #fffdf5;
    box-shadow: 0 0 0 2px rgba(232, 160, 32, 0.2);
}

.transformer-item-card.is-urgent {
    border-left: 4px solid #ef4444;
}

.card-top-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.ns-badge-group {
    display: flex;
    align-items: center;
    gap: 6px;
}

.ns-number {
    font-family: 'JetBrains Mono', monospace;
    font-size: 14px;
    font-weight: 800;
    color: #0f172a;
    background: #f1f5f9;
    padding: 2px 8px;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
}

.tag-p1 {
    background: #fee2e2;
    color: #b91c1c;
    font-size: 10px;
    font-weight: 800;
    padding: 2px 6px;
    border-radius: 4px;
}

.tag-retorno {
    background: #fef3c7;
    color: #92400e;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 4px;
}

.dias-badge {
    font-size: 11px;
    color: #64748b;
    font-weight: 600;
}

.card-details-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    margin-bottom: 10px;
    background: #f8fafc;
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 11px;
}

.card-details-grid .full { grid-column: 1 / -1; }
.card-details-grid .dt-label { color: #64748b; font-size: 10px; display: block; }
.card-details-grid .dt-val { color: #1e293b; font-weight: 600; }
.text-truncate { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }

.card-reprovas-section {
    margin-bottom: 10px;
}

.card-reprovas-section .dt-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 4px;
    display: block;
}

.reprovas-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.reprova-pill {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 2px 8px;
    font-size: 11px;
    color: #334155;
}

.reprova-pill strong { color: #b91c1c; }
.reprova-pill.none { color: #94a3b8; font-style: italic; }

.card-outros-setores {
    font-size: 11px;
    color: #64748b;
    margin-bottom: 10px;
}

.card-actions-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #f1f5f9;
}

.card-actions-row .btn-card-action {
    flex: 1 1 140px;
}

.btn-card-action {
    width: 100%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 700;
    padding: 8px 14px;
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-relacao {
    background: #f8fafc;
    color: #133a27;
    border: 1px solid #cbd5e1;
}

.btn-relacao:hover {
    background: #133a27;
    border-color: #133a27;
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(19, 58, 39, 0.15);
}

.btn-mover-setor {
    background: #fffbeb;
    color: #b45309;
    border: 1px solid #fde68a;
}

.btn-mover-setor:hover {
    background: #e8a020;
    border-color: #e8a020;
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(232, 160, 32, 0.25);
}

.reprovas-header-flex {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 6px;
}

.btn-mover-setor-pill {
    background: #fffbeb;
    color: #b45309;
    border: 1px solid #fde68a;
    font-size: 11px;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.18s ease;
    white-space: nowrap;
    box-shadow: 0 1px 2px rgba(180, 83, 9, 0.08);
}

.btn-mover-setor-pill:hover {
    background: #e8a020;
    border-color: #e8a020;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(232, 160, 32, 0.25);
}

/* ==========================================================================
   MODAL "MOVER PARA OUTRO SETOR" (somente admin)
   ========================================================================== */
.mover-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    z-index: 10001;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
}

.mover-modal-overlay.is-open {
    opacity: 1;
    pointer-events: auto;
}

.mover-modal {
    background: #ffffff;
    border-radius: 14px;
    width: 440px;
    max-width: 100%;
    max-height: 82vh;
    overflow-y: auto;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.3);
    transform: translateY(10px);
    transition: transform 0.2s ease;
}

.mover-modal-overlay.is-open .mover-modal {
    transform: translateY(0);
}

.mover-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 18px 20px;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
}

.mover-modal-header h3 {
    margin: 0;
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
}

.mover-modal-header small {
    color: #64748b;
    font-size: 12px;
}

.mover-modal-hint {
    margin: 14px 20px 6px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
}

.mover-setor-grid {
    padding: 6px 20px 20px;
}

.mover-modal-section-title {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #475569;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.mover-retornos-row,
.mover-setor-grid-inner {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 12px;
}

.mover-setor-card.card-retorno-destaque {
    border-color: #bfdbfe;
    background: #eff6ff;
}

.mover-setor-card.card-retorno-destaque:hover {
    border-color: #2563eb;
    background: #dbeafe;
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(37, 99, 235, 0.18);
}

.mover-setor-card.card-retorno-destaque .ms-icon {
    background: #1e40af;
    color: #ffffff;
}

.mover-setor-card.card-retorno-lab {
    border-color: #ddd6fe;
    background: #f5f3ff;
}

.mover-setor-card.card-retorno-lab:hover {
    border-color: #7c3aed;
    background: #ede9fe;
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(124, 58, 237, 0.18);
}

.mover-setor-card.card-retorno-lab .ms-icon {
    background: #6d28d9;
    color: #ffffff;
}

.mover-setor-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    border-radius: 10px;
    border: 1.5px solid #e2e8f0;
    background: #f8fafc;
    cursor: pointer;
    transition: all 0.15s ease;
    text-align: left;
    font-family: inherit;
}

.mover-setor-card:hover {
    border-color: #e8a020;
    background: #fffbeb;
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(232, 160, 32, 0.15);
}

.mover-setor-card.is-loading {
    opacity: 0.55;
    pointer-events: none;
}

.mover-setor-card .ms-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background: #133a27;
    color: #e8a020;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.mover-setor-card .ms-icon svg {
    width: 16px;
    height: 16px;
}

.mover-setor-card .ms-info {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
}

.mover-setor-card .ms-code {
    font-family: 'JetBrains Mono', monospace;
    font-weight: 800;
    font-size: 13px;
    color: #0f172a;
}

.mover-setor-card .ms-nome {
    font-size: 10.5px;
    color: #64748b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.drawer-empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #64748b;
}

.drawer-empty-state .empty-icon { font-size: 40px; margin-bottom: 12px; }
.drawer-empty-state h4 { font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 6px; }
.drawer-empty-state p { font-size: 13px; margin: 0; }

@media (max-width: 768px) {
    .factory-branches-row {
        grid-template-columns: 1fr 1fr;
        width: 100%;
        gap: 12px;
    }
    .pipe-fork-container, .pipe-converge-container {
        width: 100%;
    }
    .factory-bottom-row {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    .bus-pipe-horizontal { display: none; }
    .setor-node.node-main, .setor-node.node-branch { width: 100%; }
}
</style>

<div class="mapa-page-container">

    <!-- ─── Cabeçalho Padrão SGT ───────────────────────────────────────────── -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px;">
        <div>
            <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;margin-top:2px;">Painel de Retrabalho</h1>
            <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
                Monitoramento em tempo real do fluxo e localização dos transformadores na fábrica
            </p>
        </div>
        <div class="mapa-top-actions" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <div class="refresh-indicator" style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--color-text-secondary,#5a6480);background:#fff;border:1px solid var(--color-border,#e5e7eb);border-radius:8px;padding:6px 12px;">
                <span class="pulse-dot"></span>
                <span>Auto-refresh: <strong id="mapaCountdown">30s</strong></span>
                <span style="opacity:0.4;">|</span>
                <span>Atualizado: <strong id="mapaLastUpdate">--:--:--</strong></span>
            </div>
            <button type="button" class="filter-btn filter-btn-secondary" id="btnRefreshMapa" title="Atualizar dados agora" style="padding:6px 12px;font-size:12px;display:inline-flex;align-items:center;gap:5px;">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                Atualizar
            </button>
            <button type="button" class="filter-btn filter-btn-secondary" id="btnFullscreen" title="Alternar Tela Cheia" style="padding:6px 12px;font-size:12px;display:inline-flex;align-items:center;gap:5px;">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
                Tela Cheia
            </button>
            <a href="<?= htmlspecialchars($base) ?>/pages/retrabalho/relacao.php" class="filter-btn filter-btn-secondary" style="padding:6px 12px;font-size:12px;">
                Relação Completa &rarr;
            </a>
        </div>
    </div>

    <!-- ─── Métricas Resumo ─────────────────────────────────────────────────── -->
    <div class="metrics-row">
        <div class="metric-card m-total">
            <div class="metric-lbl">Total em Retrabalho</div>
            <div class="metric-val" id="statTotalPecas">0</div>
        </div>
        <div class="metric-card m-urgent">
            <div class="metric-lbl">Prioridade Alta</div>
            <div class="metric-val" id="statUrgentes">0</div>
        </div>
        <div class="metric-card m-retorno">
            <div class="metric-lbl">Aguardando Retorno</div>
            <div class="metric-val" id="statRetornoLab">0</div>
        </div>
        <div class="metric-card m-triagem">
            <div class="metric-lbl">Aguardando Triagem</div>
            <div class="metric-val" id="statTriagem">0</div>
        </div>
    </div>

    <!-- ─── Barra de Pesquisa e Iluminação ─────────────────────────────────── -->
    <div class="search-glow-panel">
        <div class="search-input-wrap">
            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="inputBuscaMapa" placeholder="Pesquisar por N° de Série (NS), Pedido, Projeto ou Reprova (ex: 900201, TPD, Vazamento, M4)..." autocomplete="off">
        </div>
        <div class="quick-filters-row">
            <span class="quick-filters-label">Filtros rápidos:</span>
            <button type="button" class="filter-pill active" data-filtro="todos">Todos</button>
            <button type="button" class="filter-pill" data-filtro="urgentes">🚨 Urgentes</button>
            <button type="button" class="filter-pill" data-filtro="retorno">🔄 Em Retorno</button>
            <button type="button" class="filter-pill" data-filtro="triagem">⏳ Aguardando Triagem</button>
        </div>
        <div class="search-result-bar" id="searchResultBar"></div>
    </div>

    <div id="mapaErrorBanner" style="display:none;padding:12px;margin-bottom:16px;background:#fee2e2;border:1px solid #fca5a5;color:#b91c1c;border-radius:10px;font-size:13px;text-align:center;"></div>

    <!-- ─── CANVAS DO MAPA DA FÁBRICA ───────────────────────────────────────── -->
    <div class="mapa-canvas-wrap">
        <div class="factory-diagram">

            <!-- 1. TOPO: LAB (Laboratório de Ensaios) -->
            <div class="setor-node node-main" data-setor="LAB">
                <div class="setor-node-inner">
                    <div class="setor-info-col">
                        <div class="setor-badge-row">
                            <span class="setor-code">LAB</span>
                        </div>
                        <span class="setor-name">Laboratório de Ensaios</span>
                        <span class="setor-subtext">0 peças</span>
                    </div>
                    <div class="setor-count-wrap">
                        <div class="setor-count count-zero">0</div>
                        <div class="setor-queue-slots"></div>
                    </div>
                </div>
            </div>

            <!-- Duto Vertical (LAB -> MF) -->
            <div class="conduit-pipe pipe-vertical" data-from="LAB" data-to="MF"></div>

            <!-- 2. HUB: MF (Montagem Final) -->
            <div class="setor-node node-main" data-setor="MF">
                <div class="setor-node-inner">
                    <div class="setor-info-col">
                        <div class="setor-badge-row">
                            <span class="setor-code">MF</span>
                        </div>
                        <span class="setor-name">Montagem Final</span>
                        <span class="setor-subtext">0 peças</span>
                    </div>
                    <div class="setor-count-wrap">
                        <div class="setor-count count-zero">0</div>
                        <div class="setor-queue-slots"></div>
                    </div>
                </div>
            </div>

            <!-- Duto com Derivação Tripla (MF -> ME / RET / PINT) -->
            <div class="pipe-fork-container conduit-pipe" data-from="MF" data-to="ME,RET,PINT">
                <svg class="pipe-fork-svg" viewBox="0 0 760 48">
                    <defs>
                        <pattern id="pipeStripesTop" width="16" height="16" patternUnits="userSpaceOnUse" patternTransform="rotate(-45)">
                            <rect width="16" height="16" fill="#cbd5e1" />
                            <rect x="0" y="0" width="8" height="16" fill="rgba(255, 255, 255, 0.55)" />
                        </pattern>
                    </defs>
                    <!-- Ramo esquerdo: centro -> ME | Ramo centro: centro -> RET | Ramo direito: centro -> PINT -->
                    <path class="pipe-path-outer" d="M 380,0 L 380,24 L 115,24 L 115,48 M 380,0 L 380,48 M 380,0 L 380,24 L 645,24 L 645,48" />
                    <path class="pipe-path-inner" stroke="url(#pipeStripesTop)" d="M 380,0 L 380,24 L 115,24 L 115,48 M 380,0 L 380,48 M 380,0 L 380,24 L 645,24 L 645,48" />
                </svg>
            </div>

            <!-- 3. QUADRANTE CENTRAL: ME/BOB (esq) | RET (centro) | PINT/CALD (dir) -->
            <div class="factory-branches-row">

                <!-- Coluna Esquerda: ME -> BOB -->
                <div class="branch-column">
                    <div class="setor-node node-branch" data-setor="ME">
                        <div class="setor-node-inner">
                            <div class="setor-info-col">
                                <div class="setor-badge-row">
                                    <span class="setor-code">ME</span>
                                </div>
                                <span class="setor-name">Montagem Elétrica</span>
                                <span class="setor-subtext">0 peças</span>
                            </div>
                            <div class="setor-count-wrap">
                                <div class="setor-count count-zero">0</div>
                                <div class="setor-queue-slots"></div>
                            </div>
                        </div>
                    </div>

                    <div class="conduit-pipe pipe-vertical" data-from="ME" data-to="BOB"></div>

                    <div class="setor-node node-branch" data-setor="BOB">
                        <div class="setor-node-inner">
                            <div class="setor-info-col">
                                <div class="setor-badge-row">
                                    <span class="setor-code">BOB</span>
                                </div>
                                <span class="setor-name">Bobinagem (AT/BT)</span>
                                <span class="setor-subtext">0 peças</span>
                            </div>
                            <div class="setor-count-wrap">
                                <div class="setor-count count-zero">0</div>
                                <div class="setor-queue-slots"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Coluna Central: RET (Setor de Retrabalho - conectado em cima, desconectado embaixo) -->
                <div class="branch-column center-branch-column">
                    <div class="conduit-pipe pipe-vertical center-top-pipe" data-from="MF" data-to="RET"></div>

                    <div class="setor-node node-branch" data-setor="RET">
                        <div class="setor-node-inner">
                            <div class="setor-info-col">
                                <div class="setor-badge-row">
                                    <span class="setor-code">RET</span>
                                </div>
                                <span class="setor-name">Setor de Retrabalho</span>
                                <span class="setor-subtext">0 peças</span>
                            </div>
                            <div class="setor-count-wrap">
                                <div class="setor-count count-zero">0</div>
                                <div class="setor-queue-slots"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Coluna Direita: PINT -> CALD -->
                <div class="branch-column">
                    <div class="setor-node node-branch" data-setor="PINT">
                        <div class="setor-node-inner">
                            <div class="setor-info-col">
                                <div class="setor-badge-row">
                                    <span class="setor-code">PINT</span>
                                </div>
                                <span class="setor-name">Pintura e Tratamento</span>
                                <span class="setor-subtext">0 peças</span>
                            </div>
                            <div class="setor-count-wrap">
                                <div class="setor-count count-zero">0</div>
                                <div class="setor-queue-slots"></div>
                            </div>
                        </div>
                    </div>

                    <div class="conduit-pipe pipe-vertical" data-from="PINT" data-to="CALD"></div>

                    <div class="setor-node node-branch" data-setor="CALD">
                        <div class="setor-node-inner">
                            <div class="setor-info-col">
                                <div class="setor-badge-row">
                                    <span class="setor-code">CALD</span>
                                </div>
                                <span class="setor-name">Caldeiraria e Solda</span>
                                <span class="setor-subtext">0 peças</span>
                            </div>
                            <div class="setor-count-wrap">
                                <div class="setor-count count-zero">0</div>
                                <div class="setor-queue-slots"></div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Duto de Convergência Inferior (BOB / CALD -> ENG) -->
            <div class="pipe-converge-container conduit-pipe" data-from="BOB,CALD" data-to="ENG">
                <svg class="pipe-fork-svg" viewBox="0 0 760 48">
                    <defs>
                        <pattern id="pipeStripesBottom" width="16" height="16" patternUnits="userSpaceOnUse" patternTransform="rotate(-45)">
                            <rect width="16" height="16" fill="#cbd5e1" />
                            <rect x="0" y="0" width="8" height="16" fill="rgba(255, 255, 255, 0.55)" />
                        </pattern>
                    </defs>
                    <!-- Ramo esquerdo: BOB -> ENG | Ramo direito: CALD -> ENG -->
                    <path class="pipe-path-outer" d="M 115,0 L 115,24 L 380,24 L 380,48 M 645,0 L 645,24 L 380,24 L 380,48" />
                    <path class="pipe-path-inner" stroke="url(#pipeStripesBottom)" d="M 115,0 L 115,24 L 380,24 L 380,48 M 645,0 L 645,24 L 380,24 L 380,48" />
                </svg>
            </div>

            <!-- 4. BASE DA FÁBRICA: PCP / ENG / ALMX -->
            <div class="factory-bottom-row">
                <div class="bus-pipe-horizontal"></div>

                <!-- PCP -->
                <div class="setor-node node-bottom" data-setor="PCP">
                    <div class="setor-node-inner">
                        <div class="setor-info-col">
                            <div class="setor-badge-row">
                                <span class="setor-code">PCP</span>
                            </div>
                            <span class="setor-name">Planejamento</span>
                            <span class="setor-subtext">0 peças</span>
                        </div>
                        <div class="setor-count-wrap">
                            <div class="setor-count count-zero">0</div>
                        </div>
                    </div>
                </div>

                <!-- ENG (Centro da base) -->
                <div class="setor-node node-bottom" data-setor="ENG">
                    <div class="setor-node-inner">
                        <div class="setor-info-col">
                            <div class="setor-badge-row">
                                <span class="setor-code">ENG</span>
                            </div>
                            <span class="setor-name">Engenharia / Análise</span>
                            <span class="setor-subtext">0 peças</span>
                        </div>
                        <div class="setor-count-wrap">
                            <div class="setor-count count-zero">0</div>
                        </div>
                    </div>
                </div>

                <!-- ALMX -->
                <div class="setor-node node-bottom" data-setor="ALMX">
                    <div class="setor-node-inner">
                        <div class="setor-info-col">
                            <div class="setor-badge-row">
                                <span class="setor-code">ALMX</span>
                            </div>
                            <span class="setor-name">Almoxarifado</span>
                            <span class="setor-subtext">0 peças</span>
                        </div>
                        <div class="setor-count-wrap">
                            <div class="setor-count count-zero">0</div>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>

</div>

<!-- ─── Tooltip de Hover ─────────────────────────────────────────────────── -->
<div class="mapa-tooltip" id="mapaTooltip"></div>

<!-- ─── Drawer Lateral de Inspeção de Setor ───────────────────────────────── -->
<div class="drawer-overlay" id="drawerOverlay"></div>
<aside class="setor-drawer" id="setorDrawer">
    <div class="drawer-header">
        <div class="drawer-header-left">
            <div class="drawer-setor-icon" id="drawerSetorIcon">🏭</div>
            <div class="drawer-title-col">
                <h2 id="drawerSetorNome">Setor</h2>
                <small id="drawerSetorCount">0 transformadores</small>
            </div>
        </div>
        <button type="button" class="btn-close-drawer" id="btnCloseDrawer" title="Fechar painel">&times;</button>
    </div>
    <div class="drawer-body" id="drawerItemsList">
        <!-- Renderizado dinamicamente via JS -->
    </div>
</aside>

<!-- ─── Modal "Mover para Outro Setor" (somente admin) ────────────────────── -->
<div class="mover-modal-overlay" id="moverModalOverlay">
    <div class="mover-modal">
        <div class="mover-modal-header">
            <div>
                <h3>Mover Transformador</h3>
                <small id="moverModalNs">NS —</small>
            </div>
            <button type="button" class="btn-close-drawer" id="btnCloseMoverModal" title="Fechar">&times;</button>
        </div>
        <div class="mover-modal-hint">Selecione o setor de destino</div>
        <div class="mover-setor-grid" id="moverSetorGrid"></div>
    </div>
</div>

<script>
    window.APP_URL = <?= json_encode($base) ?>;
    window.MAPA_API = <?= json_encode($base . '/api/retrabalho-mapa-api.php') ?>;
    window.RETRABALHO_ACAO_API = <?= json_encode($base . '/api/retrabalho-acao.php') ?>;
    window.IS_ADMIN = <?= json_encode(isAdmin() || hasAcesso('admin')) ?>;
</script>
<?php $mapaJsVer = @filemtime(__DIR__ . '/../../assets/js/retrabalho-mapa.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/retrabalho-mapa.js?v=<?= htmlspecialchars((string) $mapaJsVer) ?>"></script>

<?php layoutFooter(); ?>
