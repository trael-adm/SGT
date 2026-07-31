(function () {
    'use strict';

    var API    = window.PROJETOS_API || '';
    var INFO   = window.PRIORIDADES_INFO || {};

    document.querySelectorAll('.pp-chips').forEach(function (wrap) {
        wrap.addEventListener('click', function (e) {
            var chip = e.target.closest('.js-prioridade-chip');
            if (!chip || wrap.dataset.saving === '1') return;

            var idPedido = wrap.getAttribute('data-id');
            var slug     = chip.getAttribute('data-slug');
            var savedTag = wrap.querySelector('.pp-saved-tag');

            wrap.dataset.saving = '1';
            wrap.querySelectorAll('.js-prioridade-chip').forEach(function (c) { c.setAttribute('data-saving', '1'); });

            var body = new URLSearchParams({ acao: 'pedido_prioridade', id: idPedido, prioridade: slug });
            fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) {
                return r.json().catch(function () { return { sucesso: false }; });
            }).then(function (res) {
                if (res && res.sucesso) {
                    wrap.querySelectorAll('.js-prioridade-chip').forEach(function (c) {
                        var s = c.getAttribute('data-slug');
                        var ativo = s === slug;
                        c.classList.toggle('active', ativo);
                        c.style.background = ativo ? (INFO[s] ? INFO[s].bg : '') : '';
                        c.style.color      = ativo ? (INFO[s] ? INFO[s].fg : '') : '';
                    });
                    if (savedTag) {
                        savedTag.classList.add('show');
                        setTimeout(function () { savedTag.classList.remove('show'); }, 1500);
                    }
                } else {
                    alert((res && res.erro) || 'Erro ao salvar a prioridade.');
                }
            }).catch(function () {
                alert('Falha de conexão ao salvar a prioridade.');
            }).finally(function () {
                wrap.dataset.saving = '0';
                wrap.querySelectorAll('.js-prioridade-chip').forEach(function (c) { c.removeAttribute('data-saving'); });
            });
        });
    });
}());
