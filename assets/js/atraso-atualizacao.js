/**
 * Contador "Próxima atualização em mm:ss m" das telas de atraso (Distribuição e Média Força).
 *
 * O servidor renderiza .atraso-proxima-atualizacao com o tempo restante (data-restante, em s) —
 * a contagem usa o relógio do navegador só como diferença, então não depende de o PC estar com a
 * hora igual à do servidor. Ao zerar: "Atualizando…" e consulta api/atraso-atualizacao.php; quando a
 * foto gravada muda, recarrega a tela (mantendo os filtros da URL), sem fechar modal aberto. Se passar
 * de data-tolerancia segundos sem foto nova (tarefa agendada parada), vira alerta vermelho.
 * Ver boletimHtmlAtualizacaoAtraso() em includes/boletim-atraso.php.
 */
(function () {
    'use strict';

    var el = document.querySelector('.atraso-proxima-atualizacao');
    if (!el) return;

    var texto = el.querySelector('.atraso-proxima-texto');
    var modulo = el.getAttribute('data-modulo') || 'distribuicao';
    var ultima = el.getAttribute('data-ultima') || '';
    var atualizadoFmt = el.getAttribute('data-atualizado-fmt') || '';
    var tolerancia = parseInt(el.getAttribute('data-tolerancia'), 10) || 300;
    var prazo = Date.now() + (parseInt(el.getAttribute('data-restante'), 10) || 0) * 1000;

    var CORES = { ok: '#64748b', atualizando: '#fbbf24', atrasada: '#ff6b6b' };
    var INTERVALO_CONSULTA_MS = 10000;

    var consultando = false;
    var ultimaConsulta = 0;

    function doisDigitos(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function pintar(estado, msg) {
        el.style.color = CORES[estado];
        if (texto.textContent !== msg) texto.textContent = msg;
    }

    // Não recarrega por cima de um modal aberto: espera fechar (a próxima consulta tenta de novo).
    function modalAberto() {
        return !!document.querySelector('.modal-fluxo-overlay.active');
    }

    function consultar() {
        var agora = Date.now();
        if (consultando || document.hidden || agora - ultimaConsulta < INTERVALO_CONSULTA_MS) return;
        consultando = true;
        ultimaConsulta = agora;

        var base = (typeof window.__APP_BASE === 'string') ? window.__APP_BASE : '';
        fetch(base + '/api/atraso-atualizacao.php?modulo=' + encodeURIComponent(modulo), {
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (j && j.ultima && j.ultima !== ultima && !modalAberto()) {
                    window.location.reload();
                }
            })
            .catch(function () { /* rede instável: tenta de novo na próxima volta */ })
            .then(function () { consultando = false; });
    }

    function tick() {
        var restante = Math.ceil((prazo - Date.now()) / 1000);

        if (restante > 0) {
            pintar('ok', 'Próxima atualização em ' + doisDigitos(Math.floor(restante / 60)) + ':' + doisDigitos(restante % 60) + ' m');
            return;
        }

        if (-restante <= tolerancia) {
            pintar('atualizando', 'Atualizando…');
        } else {
            pintar('atrasada', '⚠ Atualização atrasada — última em ' + atualizadoFmt);
        }
        consultar();
    }

    tick();
    setInterval(tick, 1000);
    document.addEventListener('visibilitychange', tick);
})();
