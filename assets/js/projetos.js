(function () {
    'use strict';

    var API = window.PROJETOS_API || '';

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
        }).then(function (r) {
            return r.json().catch(function () { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; });
        });
    }

    function openModal(id) { var m = document.getElementById(id); if (m) m.style.display = 'flex'; }
    function closeModal(id) { var m = document.getElementById(id); if (m) m.style.display = 'none'; }

    function setVal(id, val) {
        var el = document.getElementById(id);
        if (el) el.value = (val === null || val === undefined) ? '' : val;
    }

    function showErro(elId, msg) {
        var el = document.getElementById(elId);
        if (el) { el.textContent = msg; el.style.display = 'block'; }
    }
    function hideErro(elId) {
        var el = document.getElementById(elId);
        if (el) { el.textContent = ''; el.style.display = 'none'; }
    }

    // ─── Fechar modais (botões [data-close], clique fora) ──────────────────────
    document.querySelectorAll('[data-close]').forEach(function (btn) {
        btn.addEventListener('click', function () { closeModal(btn.getAttribute('data-close')); });
    });
    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeModal(overlay.id);
        });
    });

    // ─── PEDIDO ────────────────────────────────────────────────────────────────
    var btnNovoPedido = document.getElementById('btn-novo-pedido');
    if (btnNovoPedido) {
        btnNovoPedido.addEventListener('click', function () {
            document.getElementById('pj-form-pedido').reset();
            setVal('ped-id', '');
            hideErro('pj-pedido-erro');
            document.getElementById('pj-pedido-title').textContent = 'Novo pedido';
            openModal('pj-modal-pedido');
        });
    }

    document.querySelectorAll('.js-edit-pedido').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('pj-form-pedido').reset();
            hideErro('pj-pedido-erro');
            setVal('ped-id', btn.dataset.id);
            setVal('ped-numero', btn.dataset.numero);
            document.getElementById('pj-pedido-title').textContent = 'Editar pedido';
            openModal('pj-modal-pedido');
        });
    });

    var formPedido = document.getElementById('pj-form-pedido');
    if (formPedido) {
        formPedido.addEventListener('submit', function (e) {
            e.preventDefault();
            hideErro('pj-pedido-erro');
            var numero = document.getElementById('ped-numero').value.trim();
            if (!numero) { showErro('pj-pedido-erro', 'Informe o número do pedido.'); return; }

            var btn = document.getElementById('pj-pedido-submit');
            btn.disabled = true; btn.textContent = 'Salvando…';

            postAcao({
                acao: 'pedido_salvar',
                id: document.getElementById('ped-id').value,
                numero: numero
            }).then(function (res) {
                if (res && res.sucesso) {
                    window.location.reload();
                } else {
                    showErro('pj-pedido-erro', (res && res.erro) || 'Erro ao salvar.');
                    btn.disabled = false; btn.textContent = 'Salvar';
                }
            }).catch(function () {
                showErro('pj-pedido-erro', 'Falha de conexão.');
                btn.disabled = false; btn.textContent = 'Salvar';
            });
        });
    }

    // ─── PROJETO ───────────────────────────────────────────────────────────────
    var btnNovoProjeto = document.getElementById('btn-novo-projeto');
    if (btnNovoProjeto) {
        btnNovoProjeto.addEventListener('click', function () {
            document.getElementById('pj-form-projeto').reset();
            setVal('proj-id', '');
            hideErro('pj-projeto-erro');
            document.getElementById('pj-projeto-title').textContent = 'Novo projeto';
            openModal('pj-modal-projeto');
        });
    }

    document.querySelectorAll('.js-edit-projeto').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('pj-form-projeto').reset();
            hideErro('pj-projeto-erro');
            setVal('proj-id', btn.dataset.id);
            setVal('proj-codigo', btn.dataset.codigo);
            setVal('proj-descricao', btn.dataset.descricao);
            setVal('proj-id_pedido', btn.dataset.id_pedido);
            document.getElementById('pj-projeto-title').textContent = 'Editar projeto';
            openModal('pj-modal-projeto');
        });
    });

    var formProjeto = document.getElementById('pj-form-projeto');
    if (formProjeto) {
        formProjeto.addEventListener('submit', function (e) {
            e.preventDefault();
            hideErro('pj-projeto-erro');
            var codigo   = document.getElementById('proj-codigo').value.trim();
            var idPedido = document.getElementById('proj-id_pedido').value;
            if (!idPedido) { showErro('pj-projeto-erro', 'Selecione o pedido.'); return; }
            if (!codigo)   { showErro('pj-projeto-erro', 'Informe o código do projeto.'); return; }

            var btn = document.getElementById('pj-projeto-submit');
            btn.disabled = true; btn.textContent = 'Salvando…';

            postAcao({
                acao: 'projeto_salvar',
                id: document.getElementById('proj-id').value,
                codigo: codigo,
                descricao: document.getElementById('proj-descricao').value,
                id_pedido: idPedido
            }).then(function (res) {
                if (res && res.sucesso) {
                    window.location.reload();
                } else {
                    showErro('pj-projeto-erro', (res && res.erro) || 'Erro ao salvar.');
                    btn.disabled = false; btn.textContent = 'Salvar';
                }
            }).catch(function () {
                showErro('pj-projeto-erro', 'Falha de conexão.');
                btn.disabled = false; btn.textContent = 'Salvar';
            });
        });
    }

    // ─── Excluir (pedido / projeto) ────────────────────────────────────────────
    function bindExcluir(selector, acao, confirmMsg) {
        document.querySelectorAll(selector).forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm(confirmMsg)) return;
                btn.disabled = true;
                postAcao({ acao: acao, id: btn.dataset.id }).then(function (res) {
                    if (res && res.sucesso) {
                        window.location.reload();
                    } else {
                        notify((res && res.erro) || 'Erro ao excluir.', 'danger');
                        btn.disabled = false;
                    }
                }).catch(function () {
                    notify('Falha de conexão.', 'danger');
                    btn.disabled = false;
                });
            });
        });
    }

    bindExcluir('.js-del-pedido',  'pedido_excluir',  'Excluir este pedido?');
    bindExcluir('.js-del-projeto', 'projeto_excluir', 'Excluir este projeto?');

}());
