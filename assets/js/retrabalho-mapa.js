/**
 * SGT — Sistema de Gestão Trael
 * Mapa Interativo de Setores e Painel de Retrabalho (retrabalho-mapa.js)
 */

(function () {
    'use strict';

    // ─── Estado Global ──────────────────────────────────────────────────────────
    const state = {
        carregando: false,
        dados: null,
        busca: '',
        filtroAtivo: 'todos', // 'todos' | 'urgentes' | 'retorno' | 'triagem'
        setorSelecionado: null,
        autoRefreshTimer: null,
        intervaloSegundos: 30,
        segundosRestantes: 30,
    };

    // Ícones SVG para os setores
    const ICONS = {
        RET_IF: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
        RET_LAB: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M10 2v7.31L4.65 19.3A2 2 0 0 0 6.4 22h11.2a2 2 0 0 0 1.75-2.7L14 9.31V2"/><path d="M8.5 2h7"/></svg>',
        LAB: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 2v7.31L4.65 19.3A2 2 0 0 0 6.4 22h11.2a2 2 0 0 0 1.75-2.7L14 9.31V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/></svg>',
        MF: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/><circle cx="12" cy="10" r="3"/></svg>',
        RET: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/><circle cx="12" cy="12" r="2"/></svg>',
        ME: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 12h10"/><path d="M12 7v10"/><circle cx="12" cy="12" r="2"/></svg>',
        BOB: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/><path d="M12 3v6"/><path d="M12 15v6"/><path d="M3 12h6"/><path d="M15 12h6"/></svg>',
        PINT: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 11V4a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v7"/><path d="M5 11a4 4 0 0 0 4 4h6a4 4 0 0 0 4-4"/><path d="M12 15v7"/><path d="M9 22h6"/></svg>',
        CALD: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
        PCP: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/></svg>',
        ENG: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        ALMX: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>',
    };

    // Helper de escape
    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Helper para URL base da aplicação
    function getAppBase() {
        if (typeof window.__APP_BASE === 'string') return window.__APP_BASE;
        if (typeof window.APP_URL === 'string') return window.APP_URL;
        return '';
    }

    // ─── Mover para Outro Setor (somente admin) ────────────────────────────────
    const IS_ADMIN = window.IS_ADMIN === true;

    const SETORES_MOVER = ['RET', 'PINT', 'MF', 'ME', 'BOB', 'CALD'];
    const NOMES_SETORES = {
        RET: 'Setor de Retrabalho',
        PINT: 'Pintura e Tratamento',
        MF: 'Montagem Final',
        ME: 'Montagem Elétrica',
        BOB: 'Bobinagem (AT / BT)',
        CALD: 'Caldeiraria e Solda',
        LAB: 'Laboratório de Ensaios',
    };

    let moverContexto = null; // { ns, idProjeto, setorAtual }

    function abrirModalMover(ns, idProjeto, setorAtual) {
        moverContexto = { ns, idProjeto, setorAtual };
        const overlay = document.getElementById('moverModalOverlay');
        const nsEl = document.getElementById('moverModalNs');
        const grid = document.getElementById('moverSetorGrid');
        if (!overlay || !grid) return;

        const nomeAtual = NOMES_SETORES[setorAtual] || setorAtual;
        if (nsEl) nsEl.textContent = `NS ${ns} — atualmente em: ${nomeAtual} (${setorAtual})`;

        const setoresInfo = (state.dados && state.dados.setores) || {};
        const opcoes = SETORES_MOVER.filter(cod => cod !== setorAtual);

        const setoresFabrilHtml = opcoes.map(cod => {
            const info = setoresInfo[cod] || {};
            const nomeExibir = info.nome || NOMES_SETORES[cod] || cod;
            return `
                <button type="button" class="mover-setor-card" data-destino="${cod}">
                    <span class="ms-icon">${ICONS[cod] || ''}</span>
                    <span class="ms-info">
                        <span class="ms-code">${cod}</span>
                        <span class="ms-nome">${esc(nomeExibir)}</span>
                    </span>
                </button>
            `;
        }).join('');

        grid.innerHTML = `
            <div class="mover-modal-section-title">
                <span>🔄 Enviar para Aba de Retornos</span>
            </div>
            <div class="mover-retornos-row">
                <button type="button" class="mover-setor-card card-retorno-destaque" data-destino="RET_IF">
                    <span class="ms-icon">${ICONS.RET_IF}</span>
                    <span class="ms-info">
                        <span class="ms-code" style="color: #1e40af;">Retorno IF</span>
                        <span class="ms-nome" style="color: #1e3a8a; font-weight: 600;">Inspeção Final (IQF)</span>
                    </span>
                </button>
                <button type="button" class="mover-setor-card card-retorno-lab" data-destino="RET_LAB">
                    <span class="ms-icon">${ICONS.RET_LAB}</span>
                    <span class="ms-info">
                        <span class="ms-code" style="color: #6d28d9;">Retorno LAB</span>
                        <span class="ms-nome" style="color: #581c87; font-weight: 600;">Laboratório de Ensaios</span>
                    </span>
                </button>
            </div>

            <div class="mover-modal-section-title" style="border-top: 1px solid #e2e8f0; margin-top: 10px; padding-top: 12px;">
                <span>🏭 Mover para Setor Fabril</span>
            </div>
            <div class="mover-setor-grid-inner">
                ${setoresFabrilHtml}
            </div>
        `;

        overlay.classList.add('is-open');
    }

    function fecharModalMover() {
        const overlay = document.getElementById('moverModalOverlay');
        if (overlay) overlay.classList.remove('is-open');
        moverContexto = null;
    }

    async function executarMoverSetor(destinoCod, cardEl) {
        if (!moverContexto) return;
        if (cardEl) cardEl.classList.add('is-loading');

        try {
            const api = window.RETRABALHO_ACAO_API || (getAppBase() + '/api/retrabalho-acao.php');
            const body = new FormData();
            body.append('acao', 'mover_setor');
            body.append('ns_transformador', moverContexto.ns);
            body.append('id_projeto', moverContexto.idProjeto);
            body.append('setor_destino', destinoCod);

            const resp = await fetch(api, { method: 'POST', body });
            const json = await resp.json().catch(() => ({ sucesso: false, erro: 'Resposta inválida do servidor.' }));

            if (!json.sucesso) {
                alert(json.erro || 'Erro ao mover o transformador.');
                if (cardEl) cardEl.classList.remove('is-loading');
                return;
            }

            fecharModalMover();
            await carregarDados(true);
        } catch (err) {
            alert('Falha de conexão ao mover o transformador.');
            if (cardEl) cardEl.classList.remove('is-loading');
        }
    }

    // ─── Carregamento de Dados ──────────────────────────────────────────────────
    async function carregarDados(silencioso = false) {
        if (!silencioso) {
            state.carregando = true;
            atualizarIndicadorCarregando();
        }

        try {
            const baseUrl = window.MAPA_API || (getAppBase() + '/api/retrabalho-mapa-api.php');
            const sep = baseUrl.indexOf('?') === -1 ? '?' : '&';
            const url = baseUrl + sep + 't=' + Date.now();
            const resp = await fetch(url, {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });

            if (!resp.ok) throw new Error('Status ' + resp.status);

            const json = await resp.json();
            if (!json.sucesso) throw new Error(json.erro || 'Erro ao carregar');

            const errBanner = document.getElementById('mapaErrorBanner');
            if (errBanner) {
                errBanner.style.display = 'none';
            }

            state.dados = json;
            state.segundosRestantes = state.intervaloSegundos;
            renderizarPainel();
        } catch (err) {
            console.error('Erro ao carregar mapa de retrabalho:', err);
            const errBanner = document.getElementById('mapaErrorBanner');
            if (errBanner) {
                errBanner.textContent = 'Não foi possível atualizar os dados do mapa. Tentando novamente...';
                errBanner.style.display = 'block';
            }
        } finally {
            state.carregando = false;
            atualizarIndicadorCarregando();
        }
    }

    function atualizarIndicadorCarregando() {
        const btn = document.getElementById('btnRefreshMapa');
        if (btn) {
            if (state.carregando) {
                btn.classList.add('is-spinning');
            } else {
                btn.classList.remove('is-spinning');
            }
        }
    }

    // ─── Filtros e Pesquisa ─────────────────────────────────────────────────────
    function testarTransformador(t, query, filtro) {
        // Filtro por categoria
        if (filtro === 'urgentes') {
            const p = String(t.prioridade || '').toLowerCase().trim();
            const isUrg = t.is_urgente || ['emergente', 'urgente', 'importante'].includes(p);
            if (!isUrg) return false;
        }
        if (filtro === 'retorno' && !t.em_retorno) {
            return false;
        }
        if (filtro === 'triagem' && t.status !== 'agu_chegada' && t.status !== 'agu_abertura') {
            return false;
        }

        // Filtro por texto de busca
        if (!query) return true;
        const q = query.toLowerCase().trim();

        if (t.ns && t.ns.toLowerCase().includes(q)) return true;
        if (t.pedido_numero && t.pedido_numero.toLowerCase().includes(q)) return true;
        if (t.projeto_codigo && t.projeto_codigo.toLowerCase().includes(q)) return true;
        if (t.projeto_descricao && t.projeto_descricao.toLowerCase().includes(q)) return true;
        if (t.responsavel && t.responsavel.toLowerCase().includes(q)) return true;

        if (t.reprovas && t.reprovas.length > 0) {
            for (const r of t.reprovas) {
                if (r.codigo && r.codigo.toLowerCase().includes(q)) return true;
                if (r.familia && r.familia.toLowerCase().includes(q)) return true;
                if (r.descricao && r.descricao.toLowerCase().includes(q)) return true;
            }
        }

        return false;
    }

    // ─── Renderização Principal ─────────────────────────────────────────────────
    function renderizarPainel() {
        if (!state.dados) return;

        renderizarMetricas();
        renderizarSetores();
        renderizarDestaquesBusca();

        if (state.setorSelecionado) {
            renderizarDrawerSetor(state.setorSelecionado);
        }
    }

    // Renderiza o resumo superior
    function renderizarMetricas() {
        const res = state.dados.resumo || {};
        const elTotal = document.getElementById('statTotalPecas');
        const elUrgentes = document.getElementById('statUrgentes');
        const elRetorno = document.getElementById('statRetornoLab');
        const elTriagem = document.getElementById('statTriagem');
        const elAtualizado = document.getElementById('mapaLastUpdate');

        if (elTotal) elTotal.textContent = res.total_pecas || '0';
        if (elUrgentes) elUrgentes.textContent = res.total_urgentes || '0';
        if (elRetorno) elRetorno.textContent = (res.aguardando_retorno !== undefined ? res.aguardando_retorno : (res.em_retorno_lab || '0'));
        if (elTriagem) elTriagem.textContent = res.aguardando_chegada || '0';
        if (elAtualizado) elAtualizado.textContent = res.atualizado_em || '--:--:--';
    }

    // Renderiza cada card de setor no mapa
    function renderizarSetores() {
        const setores = state.dados.setores || {};
        const query = state.busca.trim();
        const filtro = state.filtroAtivo;
        const temFiltroAtivo = query.length > 0 || filtro !== 'todos';

        Object.keys(setores).forEach(cod => {
            const s = setores[cod];
            const card = document.querySelector(`.setor-node[data-setor="${cod}"]`);
            if (!card) return;

            // Filtra transformadores dentro deste setor
            const matches = s.transformadores.filter(t => testarTransformador(t, query, filtro));
            const countMatches = matches.length;

            // Define contagem a ser exibida: se tiver filtro ativo, mostra a contagem filtrada
            const countExibir = temFiltroAtivo ? countMatches : s.total_pecas;

            // Atualiza contadores visuais do card
            const countBadge = card.querySelector('.setor-count');
            const subText = card.querySelector('.setor-subtext');
            const queueSlots = card.querySelector('.setor-queue-slots');

            if (countBadge) {
                countBadge.textContent = countExibir;
                countBadge.className = 'setor-count ' + getContadorClass(countExibir, s.pecas_urgentes);
            }

            if (subText) {
                if (temFiltroAtivo && !query) {
                    if (filtro === 'urgentes') {
                        subText.innerHTML = countMatches > 0 ? `<span class="tag-urgente">${countMatches} urgente${countMatches > 1 ? 's' : ''}</span>` : '0 urgentes';
                    } else if (filtro === 'retorno') {
                        subText.textContent = countMatches > 0 ? `${countMatches} em retorno` : '0 em retorno';
                    } else if (filtro === 'triagem') {
                        subText.textContent = countMatches > 0 ? `${countMatches} em triagem` : '0 em triagem';
                    }
                } else if (s.pecas_urgentes > 0) {
                    subText.innerHTML = `<span class="tag-urgente">${s.pecas_urgentes} urgente${s.pecas_urgentes > 1 ? 's' : ''}</span>`;
                } else if (s.total_pecas > 0) {
                    subText.textContent = `${s.total_pecas} peça${s.total_pecas > 1 ? 's' : ''}`;
                } else {
                    subText.textContent = 'Livre / Disponível';
                }
            }

            // Mini rack / queue slots estilo o desenho (traços ao lado)
            if (queueSlots) {
                let slotsHtml = '';
                const totalSlots = Math.min(Math.max(countExibir, 3), 6);
                for (let i = 0; i < totalSlots; i++) {
                    const ocupado = i < countExibir;
                    const urgente = ocupado && matches[i] && (matches[i].is_urgente || ['emergente', 'urgente', 'importante'].includes(String(matches[i].prioridade || '').toLowerCase()));
                    slotsHtml += `<span class="slot-dash ${ocupado ? (urgente ? 'is-urgent' : 'is-filled') : 'is-empty'}"></span>`;
                }
                queueSlots.innerHTML = slotsHtml;
            }

            // Estado de Iluminação (Glow Effect para busca por texto E para filtros rápidos)
            if (!temFiltroAtivo) {
                card.classList.remove('is-dimmed', 'is-highlighted');
            } else if (countMatches > 0) {
                card.classList.remove('is-dimmed');
                card.classList.add('is-highlighted');
            } else {
                card.classList.add('is-dimmed');
                card.classList.remove('is-highlighted');
            }

            // Atualiza tag flutuante de correspondência quando houver filtro ou busca ativa
            let matchTag = card.querySelector('.setor-match-tag');
            if (temFiltroAtivo && countMatches > 0) {
                if (!matchTag) {
                    matchTag = document.createElement('div');
                    matchTag.className = 'setor-match-tag';
                    card.appendChild(matchTag);
                }
                matchTag.innerHTML = `<span>🎯 ${countMatches}</span>`;
            } else if (matchTag) {
                matchTag.remove();
            }
        });
    }

    function getContadorClass(total, urgentes) {
        if (urgentes > 0 && total > 0) return 'count-danger';
        if (total > 8) return 'count-warning';
        if (total > 0) return 'count-primary';
        return 'count-zero';
    }

    // Renderiza a barra de status de busca e filtros
    function renderizarDestaquesBusca() {
        const bar = document.getElementById('searchResultBar');
        if (!bar) return;

        const query = state.busca.trim();
        const filtro = state.filtroAtivo;

        if (!query && filtro === 'todos') {
            bar.style.display = 'none';
            return;
        }

        const setores = state.dados.setores || {};
        const matchesPorSetor = [];
        let totalMatches = 0;

        Object.keys(setores).forEach(cod => {
            const s = setores[cod];
            const m = s.transformadores.filter(t => testarTransformador(t, query, filtro));
            if (m.length > 0) {
                matchesPorSetor.push({ cod, nome: s.nome, qtd: m.length });
                totalMatches += m.length;
            }
        });

        bar.style.display = 'flex';
        if (totalMatches === 0) {
            bar.innerHTML = `
                <div class="search-result-content no-match">
                    <span class="icon">🔍</span>
                    <span>Nenhum transformador localizado para o critério selecionado.</span>
                    <button type="button" class="btn-clear-search" id="btnClearSearch">Limpar filtro</button>
                </div>
            `;
        } else {
            const chips = matchesPorSetor.map(item => `
                <button type="button" class="match-sector-chip" data-goto="${item.cod}">
                    <strong>${item.cod}</strong>: ${item.qtd} peça${item.qtd > 1 ? 's' : ''}
                </button>
            `).join('');

            const labelTexto = query
                ? `<span><strong>${totalMatches}</strong> transformador(es) localizado(s) para "<strong>${esc(query)}</strong>" em:</span>`
                : `<span><strong>${totalMatches}</strong> transformador(es) iluminado(s) em:</span>`;

            bar.innerHTML = `
                <div class="search-result-content">
                    <span class="icon">✨</span>
                    ${labelTexto}
                    <div class="match-chips-row">${chips}</div>
                    <button type="button" class="btn-clear-search" id="btnClearSearch">Limpar</button>
                </div>
            `;
        }

        const btnClear = document.getElementById('btnClearSearch');
        if (btnClear) {
            btnClear.addEventListener('click', () => {
                state.busca = '';
                state.filtroAtivo = 'todos';
                const input = document.getElementById('inputBuscaMapa');
                if (input) input.value = '';
                document.querySelectorAll('.filter-pill').forEach(p => {
                    p.classList.toggle('active', p.dataset.filtro === 'todos');
                });
                renderizarPainel();
            });
        }
    }

    // ─── Drawer Lateral de Inspeção do Setor ─────────────────────────────────────
    function abrirDrawerSetor(codigo) {
        state.setorSelecionado = codigo;
        renderizarDrawerSetor(codigo);
        const drawer = document.getElementById('setorDrawer');
        const overlay = document.getElementById('drawerOverlay');
        if (drawer) drawer.classList.add('is-open');
        if (overlay) overlay.classList.add('is-open');
    }

    function fecharDrawer() {
        state.setorSelecionado = null;
        const drawer = document.getElementById('setorDrawer');
        const overlay = document.getElementById('drawerOverlay');
        if (drawer) drawer.classList.remove('is-open');
        if (overlay) overlay.classList.remove('is-open');
    }

    function renderizarDrawerSetor(codigo) {
        const setores = state.dados ? state.dados.setores : null;
        if (!setores || !setores[codigo]) return;

        const s = setores[codigo];
        const query = state.busca.trim();
        const filtro = state.filtroAtivo;

        const headerTitle = document.getElementById('drawerSetorNome');
        const headerCode = document.getElementById('drawerSetorCod');
        const headerIcon = document.getElementById('drawerSetorIcon');
        const headerCount = document.getElementById('drawerSetorCount');
        const bodyList = document.getElementById('drawerItemsList');

        // Filtra peças a exibir se houver busca ou filtro rápido
        const transformadoresExibir = s.transformadores.filter(t => testarTransformador(t, query, filtro));

        if (headerTitle) headerTitle.textContent = s.nome;
        if (headerCode) headerCode.textContent = s.codigo;
        if (headerIcon) headerIcon.innerHTML = ICONS[s.codigo] || '';
        if (headerCount) headerCount.textContent = `${transformadoresExibir.length} transformador${transformadoresExibir.length !== 1 ? 'es' : ''}`;

        if (!bodyList) return;

        if (transformadoresExibir.length === 0) {
            bodyList.innerHTML = `
                <div class="drawer-empty-state">
                    <div class="empty-icon">${s.total_pecas === 0 ? '✅' : '🔍'}</div>
                    <h4>${s.total_pecas === 0 ? 'Nenhum transformador neste setor' : 'Nenhuma peça com o filtro atual'}</h4>
                    <p>${s.total_pecas === 0 ? `O setor ${s.nome} está livre sem peças pendentes no momento.` : `O setor possui ${s.total_pecas} peça(s), mas nenhuma atende aos critérios do filtro selecionado.`}</p>
                </div>
            `;
            return;
        }

        // Renderiza cada card de transformador (Sem botão de abrir triagem - visão gerencial)
        const html = transformadoresExibir.map(t => {
            const pStr = String(t.prioridade || '').toLowerCase();
            const isUrgente = (t.is_urgente || ['emergente', 'urgente', 'importante'].includes(pStr));
            let tagPrio = '';
            if (pStr === 'emergente') {
                tagPrio = '<span class="tag-p1" style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;">EMERGENTE</span>';
            } else if (pStr === 'urgente') {
                tagPrio = '<span class="tag-p1" style="background:#ffedd5;color:#ea580c;border:1px solid #fdba74;">URGENTE</span>';
            } else if (pStr === 'importante') {
                tagPrio = '<span class="tag-p1" style="background:#fef9c3;color:#ca8a04;border:1px solid #fde047;">IMPORTANTE</span>';
            } else if (isUrgente) {
                tagPrio = '<span class="tag-p1">URGENTE P1</span>';
            }

            const reprovasHtml = t.reprovas && t.reprovas.length > 0
                ? t.reprovas.map(r => `
                    <span class="reprova-pill" title="${esc(r.descricao)}">
                        <strong>${esc(r.codigo || r.familia)}</strong>: ${esc(r.descricao || r.familia)}
                    </span>
                `).join('')
                : '<span class="reprova-pill none">Nenhuma reprova ativa</span>';

            const outrosSetores = t.setores.filter(x => x !== codigo);
            const outrosSetoresHtml = outrosSetores.length > 0
                ? `<div class="card-outros-setores">Também alocado em: <strong>${outrosSetores.join(', ')}</strong></div>`
                : '';

            return `
                <div class="transformer-item-card ${isUrgente ? 'is-urgent' : ''}">
                    <div class="card-top-row">
                        <div class="ns-badge-group">
                            <span class="ns-number">NS ${esc(t.ns)}</span>
                            ${tagPrio}
                            ${t.em_retorno ? `<span class="tag-retorno">${esc(t.retorno_label || 'EM RETORNO')}</span>` : (t.em_retorno_lab ? '<span class="tag-retorno">RETORNO AO LAB</span>' : (t.status === 'em_andamento' && t.setores && t.setores.includes('LAB') ? '<span class="tag-retorno" style="background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;">NO LABORATÓRIO</span>' : ''))}
                        </div>
                        <span class="dias-badge">${t.dias_aberto}d aberto</span>
                    </div>

                    <div class="card-details-grid">
                        <div>
                            <span class="dt-label">Pedido</span>
                            <span class="dt-val">${esc(t.pedido_numero || '—')}</span>
                        </div>
                        <div>
                            <span class="dt-label">Projeto</span>
                            <span class="dt-val">${esc(t.projeto_codigo || '—')}</span>
                        </div>
                        <div class="full">
                            <span class="dt-label">Modelo / Descrição</span>
                            <span class="dt-val text-truncate">${esc(t.projeto_descricao || '—')}</span>
                        </div>
                    </div>

                    <div class="card-reprovas-section">
                        <span class="dt-label">Reprovas / Motivos de Retrabalho:</span>
                        <div class="reprovas-wrap">${reprovasHtml}</div>
                    </div>

                    ${outrosSetoresHtml}

                    <div class="card-actions-row">
                        <a href="${getAppBase()}/pages/retrabalho/relacao.php?q=${encodeURIComponent(t.ns)}" class="btn-card-action btn-relacao">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            Ver na Relação Completa &rarr;
                        </a>
                        ${(t.setores && t.setores.includes('LAB')) ? `
                        <a href="${getAppBase()}/pages/producao/lista.php?busca=${encodeURIComponent(t.ns)}" class="btn-card-action" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 2v7.31L4.65 19.3A2 2 0 0 0 6.4 22h11.2a2 2 0 0 0 1.75-2.7L14 9.31V2"/><path d="M8.5 2h7"/></svg>
                            Ver no Laboratório &rarr;
                        </a>` : ''}
                        ${IS_ADMIN ? `
                        <button type="button" class="btn-card-action btn-mover-setor" data-ns="${esc(t.ns)}" data-projeto="${t.id_projeto}" data-setor-atual="${codigo}">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
                            Mover Card &rarr;
                        </button>` : ''}
                    </div>
                </div>
            `;
        }).join('');

        bodyList.innerHTML = html;
    }

    // ─── Tooltip de Hover Rápido ────────────────────────────────────────────────
    let tooltipTimer = null;
    function setupTooltips() {
        const tooltip = document.getElementById('mapaTooltip');
        if (!tooltip) return;

        document.querySelectorAll('.setor-node').forEach(node => {
            node.addEventListener('mouseenter', e => {
                const cod = node.dataset.setor;
                const setores = state.dados ? state.dados.setores : null;
                if (!setores || !setores[cod]) return;

                const s = setores[cod];
                const rect = node.getBoundingClientRect();

                // Monta conteúdo do tooltip
                let reprovasList = Object.entries(s.reprovas_frequentes || {})
                    .map(([rep, count]) => `<span>${esc(rep)} (<strong>${count}</strong>)</span>`)
                    .join(', ');
                if (!reprovasList) reprovasList = 'Nenhuma';

                tooltip.innerHTML = `
                    <div class="tt-header">
                        <div class="tt-code">${s.codigo}</div>
                        <div class="tt-title">
                            <strong>${s.nome}</strong>
                            <small>${s.total_pecas} transformador(es)</small>
                        </div>
                    </div>
                    <div class="tt-body">
                        <div class="tt-row">
                            <span>Urgentes:</span> <strong>${s.pecas_urgentes}</strong>
                        </div>
                        <div class="tt-row">
                            <span>Em Retorno:</span> <strong>${s.pecas_retorno}</strong>
                        </div>
                        <div class="tt-reprovas">
                            <span class="lbl">Reprovas mais comuns:</span>
                            <div class="reps">${reprovasList}</div>
                        </div>
                        <div class="tt-hint">Clique para abrir a lista completa &rarr;</div>
                    </div>
                `;

                // Posicionamento inteligente
                const top = rect.top + window.scrollY - 10;
                const left = rect.left + window.scrollX + (rect.width / 2);

                tooltip.style.top = `${top}px`;
                tooltip.style.left = `${left}px`;
                tooltip.style.transform = 'translate(-50%, -100%)';
                tooltip.style.opacity = '1';
                tooltip.style.pointerEvents = 'none';
            });

            node.addEventListener('mouseleave', () => {
                tooltip.style.opacity = '0';
            });
        });
    }

    // ─── Inicialização de Eventos da Interface ──────────────────────────────────
    function setupEventos() {
        // Busca instantânea
        const inputBusca = document.getElementById('inputBuscaMapa');
        if (inputBusca) {
            inputBusca.addEventListener('input', e => {
                state.busca = e.target.value;
                renderizarPainel();
            });
        }

        // Pílulas de filtro rápido
        document.querySelectorAll('.filter-pill').forEach(pill => {
            pill.addEventListener('click', () => {
                document.querySelectorAll('.filter-pill').forEach(p => p.classList.remove('active'));
                pill.classList.add('active');
                state.filtroAtivo = pill.dataset.filtro;
                renderizarPainel();
            });
        });

        // Clique no setor para abrir Drawer
        document.querySelectorAll('.setor-node').forEach(node => {
            node.addEventListener('click', () => {
                const cod = node.dataset.setor;
                if (cod) abrirDrawerSetor(cod);
            });
        });

        // Clique no chip de setor da barra de busca
        document.addEventListener('click', e => {
            const chip = e.target.closest('.match-sector-chip');
            if (chip && chip.dataset.goto) {
                abrirDrawerSetor(chip.dataset.goto);
            }
        });

        // Fechar Drawer
        const btnCloseDrawer = document.getElementById('btnCloseDrawer');
        const overlay = document.getElementById('drawerOverlay');
        if (btnCloseDrawer) btnCloseDrawer.addEventListener('click', fecharDrawer);
        if (overlay) overlay.addEventListener('click', fecharDrawer);

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                fecharModalMover();
                fecharDrawer();
            }
        });

        // Modal "Mover para Outro Setor" (somente admin): abrir a partir do botão
        // no card, escolher o setor de destino, ou fechar clicando fora / no X.
        if (IS_ADMIN) {
            document.addEventListener('click', e => {
                const btnMover = e.target.closest('.btn-mover-setor');
                if (btnMover) {
                    abrirModalMover(btnMover.dataset.ns, btnMover.dataset.projeto, btnMover.dataset.setorAtual);
                    return;
                }
                const cardDestino = e.target.closest('.mover-setor-card');
                if (cardDestino) {
                    executarMoverSetor(cardDestino.dataset.destino, cardDestino);
                }
            });

            const btnCloseMover = document.getElementById('btnCloseMoverModal');
            const moverOverlay = document.getElementById('moverModalOverlay');
            if (btnCloseMover) btnCloseMover.addEventListener('click', fecharModalMover);
            if (moverOverlay) {
                moverOverlay.addEventListener('click', e => {
                    if (e.target === moverOverlay) fecharModalMover();
                });
            }
        }

        // Botão de Refresh Manual
        const btnRefresh = document.getElementById('btnRefreshMapa');
        if (btnRefresh) {
            btnRefresh.addEventListener('click', () => {
                carregarDados(false);
            });
        }

        // Toggle Fullscreen / Modo Painel Fábrica
        const btnFullscreen = document.getElementById('btnFullscreen');
        if (btnFullscreen) {
            btnFullscreen.addEventListener('click', () => {
                if (!document.fullscreenElement) {
                    document.documentElement.requestFullscreen().catch(() => {});
                } else {
                    document.exitFullscreen().catch(() => {});
                }
            });
        }

        // Auto Refresh Timer (Contador regressivo de 30s)
        setInterval(() => {
            if (state.segundosRestantes > 1) {
                state.segundosRestantes--;
                const timerEl = document.getElementById('mapaCountdown');
                if (timerEl) timerEl.textContent = `${state.segundosRestantes}s`;
            } else {
                carregarDados(true);
            }
        }, 1000);
    }

    // Inicialização ao carregar a página
    document.addEventListener('DOMContentLoaded', () => {
        setupEventos();
        setupTooltips();
        carregarDados(false);
    });

})();
