(function () {
    'use strict';

    var API = window.RETORNOS_API || '';

    function postAcao(payload) {
        var body = new URLSearchParams(payload);
        return fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json().catch(function () { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; }); });
    }

    // ─── Expandir/recolher reprovas por trás do "+" ────────────────────────────
    // Delegado no document (sobrevive a qualquer futura troca de HTML da tabela,
    // ex.: um auto-refresh).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-toggle-retorno');
        if (!btn) return;
        var row = document.getElementById(btn.dataset.target);
        if (!row) return;
        var aberto = row.classList.toggle('is-open');
        btn.textContent = aberto ? '−' : '+';
        btn.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    });

    // ─── Aprovado: ação direta, sem confirmação (só Reprovado exige) ───────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-aprovar-retorno');
        if (!btn) return;
        var d = btn.dataset;

        btn.disabled = true;
        postAcao({ acao: 'aprovar_retorno', id_projeto: d.id_projeto, ns_transformador: d.ns_transformador })
            .then(function (res) {
                if (res && res.sucesso) {
                    window.location.reload();
                } else {
                    if (typeof window.showAlert === 'function') window.showAlert((res && res.erro) || 'Erro ao aprovar retorno.', 'danger');
                    else alert((res && res.erro) || 'Erro ao aprovar retorno.');
                    btn.disabled = false;
                }
            })
            .catch(function () {
                if (typeof window.showAlert === 'function') window.showAlert('Falha de conexão.', 'danger');
                else alert('Falha de conexão.');
                btn.disabled = false;
            });
    });

    // ─── Reprovado: abre modal de confirmação antes de agir ────────────────────
    var modal        = document.getElementById('ret-reprovar-modal');
    var nsEl         = document.getElementById('ret-reprovar-ns');
    var projetoEl    = document.getElementById('ret-reprovar-projeto');
    var confirmarBtn = document.getElementById('ret-reprovar-confirmar');
    var cancelarBtn  = document.getElementById('ret-reprovar-cancelar');
    var closeBtn     = document.getElementById('ret-reprovar-close');
    var pendente     = null; // { id_projeto, ns_transformador }

    function abrirModal(d) {
        pendente = { id_projeto: d.id_projeto, ns_transformador: d.ns_transformador };
        if (nsEl) nsEl.textContent = d.ns_transformador;
        if (projetoEl) projetoEl.textContent = d.projeto_codigo || '—';
        if (modal) modal.style.display = 'flex';
    }
    function fecharModal() {
        pendente = null;
        if (modal) modal.style.display = 'none';
        if (confirmarBtn) { confirmarBtn.disabled = false; confirmarBtn.textContent = 'Reprovar'; }
    }

    if (modal) {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-reprovar-retorno');
            if (!btn) return;
            abrirModal(btn.dataset);
        });

        [cancelarBtn, closeBtn].forEach(function (el) {
            if (el) el.addEventListener('click', fecharModal);
        });
        modal.addEventListener('click', function (e) { if (e.target === modal) fecharModal(); });

        if (confirmarBtn) {
            confirmarBtn.addEventListener('click', function () {
                if (!pendente) return;
                confirmarBtn.disabled = true;
                confirmarBtn.textContent = 'Reprovando…';

                postAcao({ acao: 'reprovar_retorno', id_projeto: pendente.id_projeto, ns_transformador: pendente.ns_transformador })
                    .then(function (res) {
                        if (res && res.sucesso) {
                            window.location.reload();
                        } else {
                            if (typeof window.showAlert === 'function') window.showAlert((res && res.erro) || 'Erro ao reprovar retorno.', 'danger');
                            else alert((res && res.erro) || 'Erro ao reprovar retorno.');
                            confirmarBtn.disabled = false;
                            confirmarBtn.textContent = 'Reprovar';
                        }
                    })
                    .catch(function () {
                        if (typeof window.showAlert === 'function') window.showAlert('Falha de conexão.', 'danger');
                        else alert('Falha de conexão.');
                        confirmarBtn.disabled = false;
                        confirmarBtn.textContent = 'Reprovar';
                    });
            });
        }
    }
}());
