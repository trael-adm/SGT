(function () {
    'use strict';

    // ─── Formulário de Metas Mensais + Calendário (upsert por mês) ─────────────
    var formMetas = document.getElementById('bo-metas-form');
    if (formMetas) {
        var elErroMetas    = document.getElementById('bo-metas-erro');
        var btnSubmitMetas = document.getElementById('bo-metas-submit');

        formMetas.addEventListener('submit', function (e) {
            e.preventDefault();
            elErroMetas.style.display = 'none';

            var fd = new FormData(formMetas);
            fd.set('acao', 'metas_salvar');

            btnSubmitMetas.disabled = true;
            fetch(window.BOLETIM_API, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.sucesso) {
                        elErroMetas.textContent = res.erro || 'Não foi possível salvar.';
                        elErroMetas.style.display = 'flex';
                        btnSubmitMetas.disabled = false;
                        return;
                    }
                    window.location.reload();
                })
                .catch(function () {
                    elErroMetas.textContent = 'Falha de comunicação com o servidor.';
                    elErroMetas.style.display = 'flex';
                    btnSubmitMetas.disabled = false;
                });
        });
    }

}());
