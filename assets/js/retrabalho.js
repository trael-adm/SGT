(function () {
    'use strict';

    var API      = window.RETRABALHO_API || '';
    var PROJETOS = window.RETRABALHO_PROJETOS || [];
    var REPROVAS = window.RETRABALHO_REPROVAS || [];

    var LOCAL_LABEL = { IQF: 'IQF — Inspeção final', LAB: 'LAB — Laboratório', GER: 'GER — Geral' };

    // ─── Helpers ──────────────────────────────────────────────────────────────
    function notify(msg, type) {
        if (typeof window.showAlert === 'function') window.showAlert(msg, type || 'info');
        else if (type === 'danger') alert(msg);
    }

    function postAcao(payload) {
        var body = new URLSearchParams(payload);
        return fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json().catch(function () { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; }); });
    }

    function setField(id, val) {
        var el = document.getElementById(id);
        if (el) el.value = (val === null || val === undefined) ? '' : val;
    }

    // ─── Cascata Pedido → Projeto ──────────────────────────────────────────────
    function popularProjetos(idPedido, selecionado) {
        var sel = document.getElementById('f-projeto');
        if (!sel) return;
        sel.innerHTML = '';
        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = idPedido ? 'Selecione o projeto…' : 'Selecione o pedido primeiro…';
        sel.appendChild(ph);

        PROJETOS.filter(function (p) { return String(p.id_pedido) === String(idPedido); })
            .forEach(function (p) {
                var o = document.createElement('option');
                o.value = String(p.id);
                o.textContent = p.descricao ? (p.codigo + ' — ' + p.descricao) : p.codigo;
                sel.appendChild(o);
            });

        if (selecionado) sel.value = String(selecionado);
    }

    var pedidoSel = document.getElementById('f-pedido');
    if (pedidoSel) {
        pedidoSel.addEventListener('change', function () { popularProjetos(pedidoSel.value, ''); });
    }

    // ─── Auto-preenchimento da reprova ─────────────────────────────────────────
    function preencherReprova(idReprova) {
        var r = REPROVAS.filter(function (x) { return String(x.id) === String(idReprova); })[0];
        setField('f-rep-descricao', r ? r.descricao : '');
        setField('f-rep-familia',   r ? r.familia : '');
        setField('f-rep-local',     r ? (LOCAL_LABEL[r.local] || r.local) : '');
    }

    var reprovaSel = document.getElementById('f-reprova');
    if (reprovaSel) {
        reprovaSel.addEventListener('change', function () { preencherReprova(reprovaSel.value); });
    }

    // ─── Modal ────────────────────────────────────────────────────────────────
    var modal     = document.getElementById('rt-modal');
    var form      = document.getElementById('rt-form');
    var titleEl   = document.getElementById('rt-modal-title');
    var erroEl    = document.getElementById('rt-form-erro');
    var submitBtn = document.getElementById('rt-form-submit');

    function openModal()  { if (modal) modal.style.display = 'flex'; }
    function closeModal() { if (modal) modal.style.display = 'none'; }

    function resetForm() {
        if (!form) return;
        form.reset();
        setField('f-id', '');
        popularProjetos('', '');
        preencherReprova('');
        if (erroEl) { erroEl.style.display = 'none'; erroEl.textContent = ''; }
    }

    // Abrir em modo "novo"
    var btnNovo = document.getElementById('btn-novo-retrabalho');
    if (btnNovo) {
        btnNovo.addEventListener('click', function () {
            resetForm();
            if (titleEl) titleEl.textContent = 'Registrar retrabalho';
            openModal();
        });
    }

    // ─── Expandir / recolher grupo (projeto) na Relação de Retrabalhos ─────────
    // Delegado no document (não em cada botão): sobrevive à troca de HTML da
    // tabela pelo auto-refresh (ver iniciarAutoRefresh() em app.js), que recria
    // esses botões a cada atualização.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-toggle-grupo');
        if (!btn) return;
        var row = document.getElementById(btn.dataset.target);
        if (!row) return;
        var aberto = row.classList.toggle('is-open');
        btn.textContent = aberto ? '−' : '+';
        btn.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    });

    // ─── Auto-refresh (só na Relação de Retrabalhos — index.php não tem essa tabela) ─
    // Espera o DOMContentLoaded porque este script roda antes de app.js (que
    // define iniciarAutoRefresh) — ele só é incluído no footer, mais abaixo.
    var rtTableWrap = document.getElementById('rt-table-wrap');
    if (rtTableWrap) {
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof window.iniciarAutoRefresh === 'function') {
                window.iniciarAutoRefresh({
                    seletores: ['#rt-table-wrap', '.rt-pager'],
                    intervaloS: 30,
                    elIndicador: document.getElementById('rt-autorefresh-indicador'),
                    modaisPausa: ['#rt-modal']
                });
            }
        });
    }

    // Fechar modal
    ['rt-modal-close', 'rt-modal-cancel'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', closeModal);
    });
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
    }

    // Submeter formulário (registrar/editar)
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (erroEl) { erroEl.style.display = 'none'; erroEl.textContent = ''; }

            var id = document.getElementById('f-id').value;
            var payload = {
                acao: id ? 'editar' : 'registrar',
                id: id,
                id_projeto: document.getElementById('f-projeto').value,
                ns_transformador: document.getElementById('f-ns').value,
                id_reprova: document.getElementById('f-reprova').value,
                data_reprova: document.getElementById('f-data_reprova').value,
                data_inicio: document.getElementById('f-data_inicio').value,
                data_finalizacao: document.getElementById('f-data_finalizacao').value,
                causa_raiz: document.getElementById('f-causa_raiz').value,
                observacoes: document.getElementById('f-observacoes').value
            };

            // Validação rápida no cliente
            if (!document.getElementById('f-pedido').value) { mostraErro('Selecione o pedido.'); return; }
            if (!payload.id_projeto)       { mostraErro('Selecione o projeto.'); return; }
            if (!payload.ns_transformador) { mostraErro('Informe o N° de série.'); return; }
            if (!payload.id_reprova)       { mostraErro('Selecione o código de reprova.'); return; }

            if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Salvando…'; }

            postAcao(payload).then(function (res) {
                if (res && res.sucesso) {
                    window.location.reload();
                } else {
                    mostraErro((res && res.erro) || 'Erro ao salvar.');
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Salvar'; }
                }
            }).catch(function () {
                mostraErro('Falha de conexão.');
                if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Salvar'; }
            });
        });
    }

    function mostraErro(msg) {
        if (erroEl) { erroEl.textContent = msg; erroEl.style.display = 'block'; }
    }

    // ─── Gráficos ──────────────────────────────────────────────────────────────
    var chart = window.RETRABALHO_CHART || { familia: { labels: [], values: [] }, local: { labels: [], values: [] } };
    var PALETTE = ['#E89B1C', '#16a34a', '#2563eb', '#dc2626', '#9333ea', '#0891b2', '#65a30d', '#db2777', '#ea580c', '#475569'];
    var LOCAL_COLOR = { IQF: '#2563eb', LAB: '#7c3aed', GER: '#16a34a', '—': '#94a3b8' };

    if (typeof Chart !== 'undefined') {

        var ranking = document.getElementById('chartRanking');
        if (ranking && chart.familia && chart.familia.labels.length) {
            new Chart(ranking, {
                type: 'bar',
                data: {
                    labels: chart.familia.labels,
                    datasets: [{
                        label: 'Retrabalhos',
                        data: chart.familia.values,
                        backgroundColor: '#E89B1C',
                        borderRadius: 5,
                        maxBarThickness: 26
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(0,0,0,.05)' } },
                        y: { grid: { display: false } }
                    }
                }
            });
        }

        var donut = document.getElementById('chartDonut');
        if (donut && chart.local && chart.local.labels.length) {
            new Chart(donut, {
                type: 'doughnut',
                data: {
                    labels: chart.local.labels,
                    datasets: [{
                        data: chart.local.values,
                        backgroundColor: chart.local.labels.map(function (l, i) { return LOCAL_COLOR[l] || PALETTE[i % PALETTE.length]; }),
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } }
                }
            });
        }
    }

}());
