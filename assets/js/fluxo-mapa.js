/**
 * SGT — Sistema de Gestão Trael
 * Módulo: Fluxo do Pedido & Acompanhamento de Produção (2026)
 * Mapa Interativo do Circuito Fabril (index.php)
 */

document.addEventListener('DOMContentLoaded', () => {
    let dadosEsteira = null;

    const btnRefresh = document.getElementById('btnRefresh');
    const searchInput = document.getElementById('searchInput');

    carregarMapa();

    const btnRefreshLabel = document.getElementById('btnRefreshLabel');

    btnRefresh.addEventListener('click', async () => {
        btnRefresh.disabled = true;
        btnRefreshLabel.textContent = 'Atualizando...';
        await carregarMapa();
        btnRefresh.disabled = false;
        btnRefreshLabel.textContent = 'Atualizar Dados';
    });

    searchInput.addEventListener('input', (e) => {
        const termo = e.target.value.toLowerCase().trim();
        destacarSetoresNaBusca(termo);
    });

    async function carregarMapa() {
        try {
            const res = await fetch(`${window.FLUXO_API_URL}?action=resumo_esteira`);
            const data = await res.json();

            if (data.sucesso) {
                dadosEsteira = data;
                renderizarKPIs(data.totais_macro);
                renderizarEstacoes(data.resumo_setores);
            }
        } catch (err) {
            console.error('Erro ao carregar dados do mapa:', err);
        }
    }

    function renderizarKPIs(macro) {
        if (!macro) return;
        document.getElementById('kpiCarteira').textContent = Number(macro.total_pedidos).toLocaleString('pt-BR');
        document.getElementById('kpiCarteiraSub').textContent = `${Number(macro.total_itens).toLocaleString('pt-BR')} transformadores`;

        document.getElementById('kpiRisco').textContent = Number(macro.total_atrasados).toLocaleString('pt-BR') + ' pedidos';
        document.getElementById('kpiRiscoSub').textContent = `${Number(macro.total_alertas).toLocaleString('pt-BR')} com entrega < 7 dias`;
    }

    function renderizarEstacoes(setores) {
        if (!setores) return;

        const defs = [
            { id: 'COMERCIAL' }, { id: 'ENGENHARIA' }, { id: 'PCP' }, { id: 'PRODUCAO' }, { id: 'LOGISTICA' },
        ];

        defs.forEach(def => {
            const info = setores[def.id] || { pedidos: 0, itens: 0, atrasados: 0, alertas: 0 };

            const countEl = document.getElementById(`count_${def.id}`);
            const statusEl = document.getElementById(`status_${def.id}`);

            if (countEl) countEl.textContent = info.pedidos;

            if (statusEl) {
                if (info.atrasados > 0) {
                    statusEl.innerHTML = `<span class="badge badge-danger">${info.atrasados} atrasados</span> ${info.alertas > 0 ? `<span class="badge badge-warning">${info.alertas} críticos</span>` : ''}`;
                } else {
                    statusEl.innerHTML = `<span class="badge badge-success">Em dia</span> ${info.alertas > 0 ? `<span class="badge badge-warning">${info.alertas} críticos</span>` : ''}`;
                }
            }
        });
    }

    function destacarSetoresNaBusca(termo) {
        if (!dadosEsteira || !dadosEsteira.pedidos) return;

        const nodes = document.querySelectorAll('.station-node');
        if (!termo) {
            nodes.forEach(n => n.classList.remove('is-highlighted'));
            return;
        }

        const setoresComMatch = new Set();
        dadosEsteira.pedidos.forEach(p => {
            const pedStr = String(p.cdPedido);
            const cliStr = (p.cliente || '').toLowerCase();
            const pedCli = (p.pedido_cliente || '').toLowerCase();
            const refStr = (p.produtos || []).map(x => x.cd_referencia.toLowerCase()).join(' ');

            if (pedStr.includes(termo) || cliStr.includes(termo) || pedCli.includes(termo) || refStr.includes(termo)) {
                setoresComMatch.add(p.setor_atual);
            }
        });

        nodes.forEach(node => {
            const sId = node.dataset.setor;
            if (setoresComMatch.has(sId)) {
                node.classList.add('is-highlighted');
            } else {
                node.classList.remove('is-highlighted');
            }
        });
    }
});

// Ação de Clique para Abrir a Tela Dedicada do Setor
window.abrirTelaSetor = function(setorId) {
    window.location.href = `setor.php?setor=${setorId}`;
};
