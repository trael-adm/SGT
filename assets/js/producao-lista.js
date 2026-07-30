(function () {
    'use strict';

    var API          = window.LISTA_API || '';
    var PRODUCAO_API = window.LISTA_PRODUCAO_API || '';
    var REPROVAS     = window.LISTA_REPROVAS || [];

    var LOCAL_LABEL = { IQF: 'IQF — Inspeção final', LAB: 'LAB — Laboratório', GER: 'GER — Geral' };

    function notify(msg, type) {
        if (typeof window.showAlert === 'function') window.showAlert(msg, type || 'info');
        else if (type === 'danger') alert(msg);
    }

    function postJson(url, payload) {
        var body = new URLSearchParams(payload);
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json().catch(function () { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; }); });
    }

    function postAcao(payload) { return postJson(API, payload); }
    function postProducaoAcao(payload) { return postJson(PRODUCAO_API, payload); }

    function setField(id, val) {
        var el = document.getElementById(id);
        if (el) el.value = (val === null || val === undefined) ? '' : val;
    }

    var modal        = document.getElementById('rep-modal');
    var form         = document.getElementById('rep-form');
    var erroEl       = document.getElementById('rep-form-erro');
    var submitBtn    = document.getElementById('rep-form-submit');
    var reprovasList = document.getElementById('rep-reprovas-list');
    var addBtn       = document.getElementById('rep-add-reprova');
    var template     = document.getElementById('rep-reprova-template');

    if (!modal || !form || !reprovasList || !template) return; // página não carregou os elementos esperados

    function openModal()  { modal.style.display = 'flex'; }
    function closeModal() { modal.style.display = 'none'; }

    function mostraErro(msg) {
        erroEl.textContent = msg;
        erroEl.style.display = 'block';
    }

    // ─── Blocos de reprova (1 código + descrição/família/local auto) — repetíveis ──
    function preencherReprovaBlock(block, idReprova) {
        var r = REPROVAS.filter(function (x) { return String(x.id) === String(idReprova); })[0];
        block.querySelector('.rep-descricao-field').value = r ? r.descricao : '';
        block.querySelector('.rep-familia-field').value   = r ? r.familia : '';
        block.querySelector('.rep-local-field').value      = r ? (LOCAL_LABEL[r.local] || r.local) : '';
    }

    // Só deixa remover enquanto sobrar mais de 1 bloco — sempre precisa de pelo menos 1 reprova.
    function atualizarBotoesRemover() {
        var blocos = reprovasList.querySelectorAll('.lst-reprova-block');
        blocos.forEach(function (b) {
            b.querySelector('.lst-reprova-remove').style.display = blocos.length > 1 ? 'flex' : 'none';
        });
    }

    function addReprovaBlock() {
        var frag  = template.content.cloneNode(true);
        var block = frag.querySelector('.lst-reprova-block');
        var sel   = block.querySelector('.rep-reprova-select');

        sel.addEventListener('change', function () { preencherReprovaBlock(block, sel.value); });
        block.querySelector('.lst-reprova-remove').addEventListener('click', function () {
            if (reprovasList.querySelectorAll('.lst-reprova-block').length <= 1) return;
            block.remove();
            atualizarBotoesRemover();
        });

        reprovasList.appendChild(frag);
        atualizarBotoesRemover();
        return block;
    }

    function resetReprovasList() {
        reprovasList.innerHTML = '';
        addReprovaBlock();
    }

    if (addBtn) addBtn.addEventListener('click', function () { addReprovaBlock(); });

    // ─── Abrir o popup a partir de uma linha da lista ───────────────────────────────
    // Pedido/Projeto/N° de série vêm travados do próprio registro escaneado — o
    // operador só escolhe o(s) código(s) da reprovação.
    // Delegado no document (não em cada botão): sobrevive à troca de HTML da
    // tabela pelo auto-refresh (ver iniciarAutoRefresh() em app.js), que recria
    // esses botões a cada atualização.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-reprovar');
        if (!btn) return;

        form.reset();
        erroEl.style.display = 'none';
        erroEl.textContent = '';
        resetReprovasList();

        // Nomes de atributo com "_" não viram camelCase no dataset — acessar
        // literalmente como veio do HTML (data-id_projeto -> d.id_projeto).
        var d = btn.dataset;
        setField('rep-id_projeto', d.id_projeto);
        setField('rep-ns', d.ns_transformador);
        setField('rep-pedido-display', d.pedido_numero);
        setField('rep-projeto-display', d.projeto_codigo);
        setField('rep-projeto-descricao-display', d.projeto_descricao);
        setField('rep-ns-display', d.ns_transformador);

        openModal();
    });

    ['rep-modal-close', 'rep-modal-cancel'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', closeModal);
    });
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

    // ─── Submeter (1 reprovação = 1 retrabalho; várias reprovas = vários registros) ─
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        erroEl.style.display = 'none';
        erroEl.textContent = '';

        var idProjeto    = document.getElementById('rep-id_projeto').value;
        var ns           = document.getElementById('rep-ns').value;
        var dataReprova  = new Date().toISOString().slice(0, 10); // sempre hoje, sem campo na tela
        var idsReprova   = Array.prototype.map.call(
            reprovasList.querySelectorAll('.rep-reprova-select'),
            function (sel) { return sel.value; }
        );

        if (!idsReprova.length || idsReprova.some(function (v) { return !v; })) {
            mostraErro('Selecione o código de todas as reprovações adicionadas.');
            return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Reprovando…';

        // Envia uma reprovação por vez (cada uma vira 1 linha em `retrabalhos`) — para no
        // primeiro erro em vez de deixar metade registrada silenciosamente.
        function enviarProxima(i) {
            if (i >= idsReprova.length) {
                // Todas as reprovas foram registradas em Retrabalho — agora tira o item
                // da Lista de Produção (soft delete da etapa). Falha aqui não desfaz o
                // que já foi enviado para o Retrabalho, só recarrega mesmo assim.
                postProducaoAcao({ acao: 'remover_etapa', ns_transformador: ns }).catch(function () {}).then(function () {
                    notify('Reprovação registrada e enviada para o Retrabalho.', 'success');
                    window.location.reload();
                });
                return;
            }
            postAcao({
                acao: 'registrar',
                id_projeto: idProjeto,
                ns_transformador: ns,
                id_reprova: idsReprova[i],
                data_reprova: dataReprova
            }).then(function (res) {
                if (res && res.sucesso) {
                    enviarProxima(i + 1);
                } else {
                    mostraErro((res && res.erro) || 'Erro ao reprovar.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Reprovar';
                }
            }).catch(function () {
                mostraErro('Falha de conexão.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Reprovar';
            });
        }
        enviarProxima(0);
    });

    // ─── Auto-refresh ────────────────────────────────────────────────────────
    var lstTableWrap = document.getElementById('lst-table-wrap');
    if (lstTableWrap && typeof window.iniciarAutoRefresh === 'function') {
        window.iniciarAutoRefresh({
            seletores: ['#lst-table-wrap', '.lst-pager'],
            intervaloS: 30,
            elIndicador: document.getElementById('lst-autorefresh-indicador'),
            modaisPausa: ['#rep-modal']
        });
    }
}());
