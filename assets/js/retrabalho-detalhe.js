(function () {
    'use strict';

    var API      = window.RETRABALHO_API || '';
    var REPROVAS = window.RETRABALHO_REPROVAS || [];
    var LOCAL_LABEL = { IQF: 'IQF — Inspeção final', LAB: 'LAB — Laboratório', GER: 'GER — Geral' };

    /** Envia o form como multipart/form-data (necessário para os anexos). */
    function postAcaoForm(form, acao) {
        var body = new FormData(form);
        body.append('acao', acao);
        return fetch(API, {
            method: 'POST',
            body: body
        }).then(function (r) { return r.json().catch(function () { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; }); });
    }

    // ─── Expandir / recolher "Adicionar nova reprova" + Triagem ────────────────
    var toggleBtn = document.getElementById('rtd-toggle-add');
    var addWrap   = document.getElementById('rtd-add-wrap');

    function setAddWrapAberto(aberto) {
        if (!addWrap || !toggleBtn) return;
        addWrap.style.display = aberto ? 'block' : 'none';
        toggleBtn.textContent = aberto ? '× Fechar' : '+ Nova Reprova';
        toggleBtn.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    }

    if (toggleBtn && addWrap) {
        toggleBtn.addEventListener('click', function () {
            setAddWrapAberto(addWrap.style.display === 'none');
        });
    }

    // ─── Blocos repetíveis de "Código de reprova" (uma triagem, várias reprovas) ─
    var blocosWrap = document.getElementById('rtd-reprova-blocos');
    var addBlocoBtn = document.getElementById('rtd-add-bloco');

    function preencherReprovaNoBloco(bloco, idReprova) {
        var r = REPROVAS.filter(function (x) { return String(x.id) === String(idReprova); })[0];
        var familiaEl = bloco.querySelector('.js-familia');
        var localEl   = bloco.querySelector('.js-local');
        if (familiaEl) familiaEl.value = r ? r.familia : '';
        if (localEl)   localEl.value   = r ? (LOCAL_LABEL[r.local] || r.local) : '';
    }

    if (blocosWrap) {
        // Delegação: troca de código de reprova em qualquer bloco (existente ou clonado).
        blocosWrap.addEventListener('change', function (e) {
            if (!e.target.classList.contains('js-reprova-sel')) return;
            var bloco = e.target.closest('.rtd-reprova-bloco');
            if (bloco) preencherReprovaNoBloco(bloco, e.target.value);
        });

        // Delegação: excluir um bloco específico. Se for o único bloco restante,
        // fecha a seção inteira (cancela) e recria um bloco em branco para a próxima vez.
        blocosWrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-remover-bloco');
            if (!btn) return;
            var bloco = btn.closest('.rtd-reprova-bloco');
            if (!bloco) return;

            var ultimo = blocosWrap.querySelectorAll('.rtd-reprova-bloco').length <= 1;
            bloco.remove();
            if (ultimo) {
                blocosWrap.appendChild(criarBlocoVazio());
                setAddWrapAberto(false);
            }
        });
    }

    if (addBlocoBtn && blocosWrap) {
        addBlocoBtn.addEventListener('click', function () {
            var blocos = blocosWrap.querySelectorAll('.rtd-reprova-bloco');
            var base = blocos.length ? blocos[blocos.length - 1] : null;
            var novo = base ? base.cloneNode(true) : criarBlocoVazio();
            if (base) {
                novo.querySelector('.js-reprova-sel').value = '';
                var dataEl = novo.querySelector('.js-data-reprova');
                if (dataEl) dataEl.value = new Date().toISOString().slice(0, 10);
                novo.querySelector('.js-familia').value = '';
                novo.querySelector('.js-local').value = '';
            }
            blocosWrap.appendChild(novo);
        });
    }

    /** Fallback caso todos os blocos tenham sido excluídos: recria um a partir do template original. */
    function criarBlocoVazio() {
        var tpl = document.getElementById('rtd-bloco-template');
        return tpl.content.firstElementChild.cloneNode(true);
    }

    // ─── Envio (Triagem + 1 ou mais reprovas) ──────────────────────────────────
    var formAdd  = document.getElementById('rtd-form-add');
    var erroEl   = document.getElementById('rtd-add-erro');
    var submitEl = document.getElementById('rtd-add-submit');

    function mostraErro(msg) {
        if (erroEl) { erroEl.textContent = msg; erroEl.style.display = 'block'; }
    }

    if (formAdd) {
        formAdd.addEventListener('submit', function (e) {
            e.preventDefault();
            if (erroEl) { erroEl.style.display = 'none'; erroEl.textContent = ''; }

            var temCodigo = Array.prototype.some.call(
                formAdd.querySelectorAll('.js-reprova-sel'),
                function (sel) { return sel.value; }
            );
            if (!temCodigo) {
                mostraErro('Selecione ao menos um código de reprova — abra "+ Nova Reprova" acima e escolha um código antes de enviar.');
                // A seção "Adicionar nova reprova" pode estar fechada (ou nem ter sido
                // aberta ainda) — sem isso, o erro aparece longe do campo que falta, sem
                // pista nenhuma de onde ele está.
                if (addWrap && addWrap.style.display === 'none') setAddWrapAberto(true);
                var primeiroSelect = formAdd.querySelector('.js-reprova-sel');
                if (primeiroSelect) {
                    primeiroSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    primeiroSelect.focus();
                }
                return;
            }

            if (submitEl) { submitEl.disabled = true; submitEl.textContent = 'Enviando…'; }

            postAcaoForm(formAdd, 'registrar').then(function (res) {
                if (res && res.sucesso) {
                    window.location.reload();
                } else {
                    mostraErro((res && res.erro) || 'Erro ao registrar.');
                    if (submitEl) { submitEl.disabled = false; submitEl.textContent = 'Enviar'; }
                }
            }).catch(function () {
                mostraErro('Falha de conexão.');
                if (submitEl) { submitEl.disabled = false; submitEl.textContent = 'Enviar'; }
            });
        });
    }

}());
