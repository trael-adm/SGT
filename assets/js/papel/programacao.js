/**
 * Programação de Corte — Setor de Papel (SGT)
 * Integração Direta ao ERP VSAT (TRAEL Transformadores)
 * 
 * 1. MODO BOBINA (VISÃO MENSAL CONSOLIDADA):
 *    - Agrupa a demanda do mês por Material + Espessura + Largura da Bobina.
 *    - Matriz de Semanas (Sem 1, Sem 2, Sem 3, Sem 4) com seleção interativa para corte conjunto (otimização de setup do Slitter).
 * 2. MODO PROJETO COMPLETO (KIT CMI):
 *    - Visão orientada a transformadores com validação do Status da Engenharia (trava de segurança).
 */

// Variáveis globais do Inventário de Papel
let estoqueAlmoxarifado = [];
let decisoesAproveitamentoMap = {};
let matchAtivoParaDecisao = null;

// Liberação de corte (persistência real dos botões "Liberar") + estado de seleção
// que sobrevive à troca de modo/filtro e ao recarregamento da demanda.
let liberacoesMap = {};
const estadoSelecaoPrograma = {
  bobinaSemanasDesmarcadas: {}, // key (mat||esp||larg) -> Set de semanas desmarcadas pelo usuário
  pecasSelecionadas: new Set()  // Set de números de OF marcados no modo Peça
};

function montarChaveNatural(granularidade, partes) {
  if (granularidade === 'bobina') {
    return `${partes.mesPcp}|${partes.material}|${partes.espessura}|${partes.larguraBobina}|${partes.semana}`;
  }
  if (granularidade === 'projeto') {
    return String(partes.tpdProjeto);
  }
  if (granularidade === 'peca') {
    return String(partes.numeroOf);
  }
  return '';
}

document.addEventListener('DOMContentLoaded', () => {
  if (typeof D === 'undefined') return;

  carregarMatchesEstoque();
  carregarLiberacoes();

  const selMes = document.getElementById('sel-mes');
  const selSem = document.getElementById('sel-sem');
  const selModo = document.getElementById('sel-modo');
  const selFiltroEng = document.getElementById('sel-filtro-eng');
  const selFiltroStatus = document.getElementById('sel-filtro-status');
  const inputBusca = document.getElementById('input-busca');
  const btnCarregar = document.getElementById('btn-carregar');
  const containerLotes = document.getElementById('container-lotes');
  const headTitulo = document.getElementById('head-titulo-direita');
  const descModo = document.getElementById('desc-modo-direita');

  if (!selMes || !selSem || !btnCarregar || !containerLotes) return;

  // --- Funções Auxiliares de Formatação pt-BR ---
  function formatarNumeroBr(n, dec = 0) {
    if (n === null || n === undefined || isNaN(n)) return '0';
    if (dec > 0) {
      return Number(n).toLocaleString('pt-BR', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    }
    return Math.round(Number(n)).toLocaleString('pt-BR');
  }

  function formatarEspessuraBr(esp) {
    if (esp === null || esp === undefined) return '—';
    return Number(esp).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' mm';
  }

  function formatarMassaBr(kg) {
    if (!kg || kg <= 0) return '—';
    const val = Number(kg);
    if (val >= 1000) {
      const tons = val / 1000;
      const dec = tons >= 100 ? 1 : 2;
      return tons.toLocaleString('pt-BR', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + ' t';
    }
    return val.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' kg';
  }

  // Helper Nomes dos Meses
  function getNomeMes(mKey) {
    if (mKey === '2026-07') return 'Julho / 2026';
    if (mKey === '2026-08') return 'Agosto / 2026';
    if (mKey === '2026-09') return 'Setembro / 2026';
    if (mKey === '2026-10') return 'Outubro / 2026';
    if (mKey === '2026-11') return 'Novembro / 2026';
    const parts = mKey.split('-');
    const mNum = parseInt(parts[1] || '0', 10);
    const nomes = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    return (nomes[mNum - 1] || mKey) + ' / ' + parts[0];
  }

  // Popular Meses (Padrão: Mês atual)
  const mesesKeys = [...new Set(D.rows.map(r => String(r[1]).substring(0, 7)))].sort();
  const hojeKey = new Date().toISOString().substring(0, 7);
  const mesPadrao = mesesKeys.includes(hojeKey) ? hojeKey : (mesesKeys.includes('2026-08') ? '2026-08' : (mesesKeys[0] || ''));

  selMes.innerHTML = '';
  mesesKeys.forEach(mKey => {
    const opt = document.createElement('option');
    opt.value = mKey;
    opt.textContent = getNomeMes(mKey);
    if (mKey === mesPadrao) opt.selected = true;
    selMes.appendChild(opt);
  });

  // Popular Select de TPDs no Modal de Corte Extra
  const extraTpdSelect = document.getElementById('extra-tpd');
  const extraPecaSelect = document.getElementById('extra-peca-select');

  if (extraTpdSelect) {
    extraTpdSelect.innerHTML = '';
    D.tpds.slice(0, 200).forEach((tpd) => {
      const opt = document.createElement('option');
      opt.value = tpd;
      opt.textContent = tpd;
      extraTpdSelect.appendChild(opt);
    });
    extraTpdSelect.onchange = atualizarSelectPecasExtra;
  }

  if (extraPecaSelect) {
    extraPecaSelect.onchange = atualizarPreviewPecaExtra;
  }

  // Atualizar Semanas conforme Mês selecionado
  function atualizarSemanas() {
    const mesSel = selMes.value;
    const rowsMes = D.rows.filter(r => String(r[1]).startsWith(mesSel));
    const semanasMes = [...new Set(rowsMes.map(r => r[0]))].sort((a,b)=>a-b);
    
    selSem.innerHTML = '';
    const optTodas = document.createElement('option');
    optTodas.value = 'todas';
    optTodas.textContent = '📅 Mês Inteiro (Consolidado — Todas as Semanas)';
    selSem.appendChild(optTodas);

    semanasMes.forEach(s => {
      const opt = document.createElement('option');
      opt.value = s;
      opt.textContent = 'Semana ' + s;
      selSem.appendChild(opt);
    });
  }

  selMes.onchange = () => {
    atualizarSemanas();
    carregarDemanda();
  };
  selSem.onchange = carregarDemanda;
  if (selModo) selModo.onchange = carregarDemanda;
  if (selFiltroEng) selFiltroEng.onchange = carregarDemanda;
  if (selFiltroStatus) selFiltroStatus.onchange = carregarDemanda;
  if (inputBusca) inputBusca.oninput = carregarDemanda;

  atualizarSemanas();

  // Função Principal de Filtragem e Renderização
  function carregarDemanda() {
    const mesSel = selMes.value;
    const semVal = selSem.value;
    const modoVal = selModo ? selModo.value : 'bobina';
    const engVal = selFiltroEng ? selFiltroEng.value : 'todos';
    const apenasFalta = selFiltroStatus ? (selFiltroStatus.value === 'pendentes') : true;
    const termoBusca = inputBusca ? inputBusca.value.toLowerCase().trim() : '';

    let filtrados = D.rows.filter(r => String(r[1]).startsWith(mesSel));
    
    if (semVal !== 'todas') {
      const semNum = parseInt(semVal, 10);
      filtrados = filtrados.filter(r => r[0] === semNum);
    }

    if (engVal === 'liberados') {
      filtrados = filtrados.filter(r => (r[16] || (D.tpd_status_eng ? D.tpd_status_eng[r[8]] : 'LIBERADO')) === 'LIBERADO');
    } else if (engVal === 'aguardando') {
      filtrados = filtrados.filter(r => (r[16] || (D.tpd_status_eng ? D.tpd_status_eng[r[8]] : 'LIBERADO')) !== 'LIBERADO');
    }

    if (apenasFalta) {
      filtrados = filtrados.filter(r => (r[13] !== undefined ? r[13] : 0) === 0);
    }

    if (termoBusca) {
      filtrados = filtrados.filter(r => {
        const peca = D.pecas[r[3]] ? D.pecas[r[3]].toLowerCase() : '';
        const tpd = D.tpds[r[8]] ? D.tpds[r[8]].toLowerCase() : '';
        const of = String(r[9]);
        const mat = D.mats[r[4]] ? D.mats[r[4]].toLowerCase() : '';
        return peca.includes(termoBusca) || tpd.includes(termoBusca) || of.includes(termoBusca) || mat.includes(termoBusca);
      });
    }

    if (filtrados.length === 0) {
      containerLotes.innerHTML = `<div style="padding:40px;text-align:center;color:#9aa3b8;font-size:13px">Nenhuma demanda encontrada para os filtros aplicados.</div>`;
      return;
    }

    containerLotes.innerHTML = '';

    if (modoVal === 'bobina') {
      if (headTitulo) headTitulo.textContent = '2. Programação por Bobina (Visão Mensal & Setup Otimizado)';
      if (descModo) descModo.textContent = 'O sistema consolida a demanda do mês todo de cada especificação de bobina (Material + Espessura + Largura) e detalha a distribuição por semanas, permitindo selecionar quais semanas cortar juntas no Slitter.';
      renderModoBobinaMensal(filtrados, mesSel);
    } else if (modoVal === 'projeto') {
      if (headTitulo) headTitulo.textContent = '2. Programação por Projeto Completo (Kit CMI da Engenharia)';
      if (descModo) descModo.textContent = 'Visão orientada aos projetos de transformadores do PCP. Validação formal do Status da Engenharia para garantir que nenhum kit seja cortado sem liberação do desenho.';
      renderModoProjeto(filtrados);
    } else if (modoVal === 'peca') {
      if (headTitulo) headTitulo.textContent = '2. Seleção Manual de Peças Específicas';
      if (descModo) descModo.textContent = 'Selecione manualmente as ordens de fabricação individuais que deseja liberar para corte imediato.';
      renderModoPecaIndividual(filtrados);
    }
  }

  // 1. MODO BOBINA MENSAL COM MATRIZ DE SEMANAS
  function renderModoBobinaMensal(filtrados, mesSel) {
    const mapBobinas = {};
    const semanasMesSet = new Set();

    filtrados.forEach(r => {
      const sem = r[0];
      semanasMesSet.add(sem);

      const mat = D.mats[r[4]] || 'MATERIAL';
      const esp = r[5];
      const largBobina = r[14] || 620;
      const key = mat + '||' + esp + '||' + largBobina;

      if (!mapBobinas[key]) {
        mapBobinas[key] = {
          mat,
          esp,
          largBobina,
          ofs: new Set(),
          tpds: new Set(),
          pecasTotal: 0,
          massaTotal: 0,
          porSemana: {} // sem -> { pecas, massa, ofs }
        };
      }

      mapBobinas[key].ofs.add(r[9]);
      mapBobinas[key].tpds.add(r[8]);
      mapBobinas[key].pecasTotal += r[10];
      mapBobinas[key].massaTotal += (r[11] || 0);

      if (!mapBobinas[key].porSemana[sem]) {
        mapBobinas[key].porSemana[sem] = { pecas: 0, massa: 0, ofs: new Set() };
      }
      mapBobinas[key].porSemana[sem].pecas += r[10];
      mapBobinas[key].porSemana[sem].massa += (r[11] || 0);
      mapBobinas[key].porSemana[sem].ofs.add(r[9]);
    });

    const listaBobinas = Object.values(mapBobinas).sort((a,b) => b.pecasTotal - a.pecasTotal);
    const semanasOrdenadas = [...semanasMesSet].sort((a,b) => a-b);

    const bannerResumo = document.createElement('div');
    bannerResumo.style.cssText = 'background:#f0fdf4;border:1px solid #bbf7d0;border-left:4px solid #15803d;padding:12px 16px;border-radius:8px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px';
    bannerResumo.innerHTML = `
      <div>
        <div style="font-size:14px;font-weight:700;color:#166534">📦 OTIMIZAÇÃO DE BOBINAS — ${getNomeMes(mesSel).toUpperCase()}</div>
        <div style="font-size:12px;color:#15803d;margin-top:2px">
          ${formatarNumeroBr(listaBobinas.length)} especificações de bobina encontradas · ${semanasOrdenadas.map(s => 'Sem ' + s).join(' · ')}
        </div>
      </div>
      <span style="background:#dcfce7;color:#166534;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:700">Slitter / Guilhotina</span>
    `;
    containerLotes.appendChild(bannerResumo);

    listaBobinas.forEach((bob, bIdx) => {
      const card = document.createElement('div');
      card.id = `card-bobina-${bIdx}`;
      card.style.cssText = 'border:1px solid #e2e6ed;border-radius:8px;margin-bottom:16px;background:#fff;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.04)';

      const tipoInsumo = (bob.mat === 'PRESSPHAN' && bob.esp >= 1.0) ? 'Chapa' : 'Bobina';
      const bobId = `BOBINA #${String(bIdx + 1).padStart(2, '0')}`;
      const bobKey = `${bob.mat}||${bob.esp}||${bob.largBobina}`;
      const semanasDesmarcadas = estadoSelecaoPrograma.bobinaSemanasDesmarcadas[bobKey];

      // Montar HTML das Semanas com Checkboxes
      let semanasHtml = '';
      semanasOrdenadas.forEach(sem => {
        const dadosSem = bob.porSemana[sem];
        if (dadosSem && dadosSem.pecas > 0) {
          const chaveNatural = montarChaveNatural('bobina', { mesPcp: mesSel, material: bob.mat, espessura: bob.esp, larguraBobina: bob.largBobina, semana: sem });
          const liberacao = liberacoesMap['bobina|' + chaveNatural];
          if (liberacao) {
            semanasHtml += `
              <label style="display:flex;align-items:center;gap:6px;background:#f0fdf4;border:1px solid #86efac;padding:6px 12px;border-radius:6px;font-size:12px">
                <input type="checkbox" checked disabled style="cursor:not-allowed">
                <span><b>Sem ${sem}:</b> ${formatarNumeroBr(dadosSem.pecas)} pçs · <span style="color:#15803d">✓ Liberado por ${liberacao.nome_usuario_liberacao || 'Planejamento'} em ${liberacao.data_liberacao_fmt || '—'}</span></span>
              </label>
            `;
          } else {
            const marcado = !(semanasDesmarcadas && semanasDesmarcadas.has(sem));
            semanasHtml += `
              <label style="display:flex;align-items:center;gap:6px;background:#f8fafc;border:1px solid #cbd5e1;padding:6px 12px;border-radius:6px;font-size:12px;cursor:pointer">
                <input type="checkbox" class="chk-sem-bobina chk-sem-bob-${bIdx}" data-sem="${sem}" data-pecas="${dadosSem.pecas}" data-massa="${dadosSem.massa}" data-bobkey="${bobKey}" ${marcado ? 'checked' : ''} style="cursor:pointer">
                <span><b>Sem ${sem}:</b> ${formatarNumeroBr(dadosSem.pecas)} pçs ${dadosSem.massa > 0 ? '(' + formatarMassaBr(dadosSem.massa) + ')' : ''}</span>
              </label>
            `;
          }
        }
      });

      card.innerHTML = `
        <div style="padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e2e6ed;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
          <div style="display:flex;align-items:center;gap:10px">
            <span style="background:#1a3d2a;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;font-family:monospace">${bobId}</span>
            <span style="font-size:15px;font-weight:700;color:#1a2133">${bob.mat} ${formatarEspessuraBr(bob.esp)} · ${tipoInsumo} ${formatarNumeroBr(bob.largBobina)} mm</span>
          </div>
          <div style="font-size:12px;color:#64748b">
            <b>Demanda do Mês:</b> ${formatarNumeroBr(bob.pecasTotal)} pçs ${bob.massaTotal > 0 ? '· ' + formatarMassaBr(bob.massaTotal) : ''} · ${formatarNumeroBr(bob.tpds.size)} Projetos (${formatarNumeroBr(bob.ofs.size)} OFs)
          </div>
        </div>
        <div style="padding:14px 16px">
          <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:8px">Distribuição por Semanas do PCP (Selecione o Lote a Cortar):</div>
          <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
            ${semanasHtml}
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between;border-top:1px solid #f1f5f9;padding-top:12px;flex-wrap:wrap;gap:10px">
            <div style="font-size:12px;color:#15803d;font-weight:600" id="resumo-sel-bob-${bIdx}">
              ✓ Todas as ${semanasOrdenadas.length} semanas selecionadas (${formatarNumeroBr(bob.pecasTotal)} peças a correr na máquina)
            </div>
            <div style="display:flex;gap:8px">
              <button class="btn-ordem" style="background:#1a3d2a;color:#fff;border:none;padding:7px 16px;border-radius:6px;font-weight:700" onclick="liberarLoteBobinaCustom('${bobId}', '${bob.mat} ${formatarEspessuraBr(bob.esp)} · ${tipoInsumo} ${bob.largBobina}mm', ${bIdx}, this, '${mesSel}', '${bob.mat}', ${bob.esp}, ${bob.largBobina})">⚡ Liberar Semanas Selecionadas</button>
            </div>
          </div>
        </div>
      `;

      containerLotes.appendChild(card);

      // Eventos dos checkboxes de semana
      const chksBob = card.querySelectorAll(`.chk-sem-bob-${bIdx}`);
      const resumoEl = card.querySelector(`#resumo-sel-bob-${bIdx}`);

      chksBob.forEach(chk => {
        chk.onchange = () => {
          const key = chk.dataset.bobkey;
          const sem = parseInt(chk.dataset.sem, 10);
          if (!estadoSelecaoPrograma.bobinaSemanasDesmarcadas[key]) {
            estadoSelecaoPrograma.bobinaSemanasDesmarcadas[key] = new Set();
          }
          if (chk.checked) {
            estadoSelecaoPrograma.bobinaSemanasDesmarcadas[key].delete(sem);
          } else {
            estadoSelecaoPrograma.bobinaSemanasDesmarcadas[key].add(sem);
          }

          const marcados = card.querySelectorAll(`.chk-sem-bob-${bIdx}:checked`);
          let totalPecasSel = 0;
          const semsMarcadas = [];
          marcados.forEach(m => {
            totalPecasSel += parseInt(m.dataset.pecas || '0', 10);
            semsMarcadas.push('Sem ' + m.dataset.sem);
          });

          if (marcados.length === 0) {
            resumoEl.innerHTML = '<span style="color:#b91c1c">⚠️ Nenhuma semana selecionada</span>';
          } else {
            resumoEl.innerHTML = `✓ ${semsMarcadas.join(' + ')} selecionadas (${formatarNumeroBr(totalPecasSel)} peças a correr na máquina)`;
          }
        };
      });
    });
  }

  // 2. MODO PROJETO (KIT COMPLETO CMI) COM VALIDAÇÃO DE ENGENHARIA
  function renderModoProjeto(filtrados) {
    const mapProjetos = {};
    filtrados.forEach(r => {
      const tpdIdx = r[8];
      const tpdName = D.tpds[tpdIdx] || 'Sem Projeto';
      if (!mapProjetos[tpdName]) {
        const trafosQtd = (r[12] !== undefined) ? r[12] : (D.tpd_trafos ? D.tpd_trafos[tpdIdx] : 5) || 5;
        const ofsTotal = (r[15] !== undefined) ? r[15] : (D.tpd_ofs ? D.tpd_ofs[tpdIdx] : 28) || 28;
        const stEng = r[16] || (D.tpd_status_eng ? D.tpd_status_eng[tpdIdx] : 'LIBERADO');

        mapProjetos[tpdName] = {
          tpd: tpdName,
          trafos: trafosQtd,
          ofsTotal: ofsTotal,
          sem: r[0],
          statusEng: stEng,
          ofs: new Set(),
          pecas: 0,
          massa: 0,
          materiais: new Set()
        };
      }
      mapProjetos[tpdName].ofs.add(r[9]);
      mapProjetos[tpdName].pecas += r[10];
      mapProjetos[tpdName].massa += (r[11] || 0);
      mapProjetos[tpdName].materiais.add(`${D.mats[r[4]]} ${formatarEspessuraBr(r[5])}`);
    });

    const listProjetos = Object.values(mapProjetos).sort((a,b) => b.pecas - a.pecas);

    const headDiv = document.createElement('div');
    headDiv.style.cssText = 'background:#f0f7ff;padding:12px 16px;border-radius:8px;margin-bottom:14px;border-left:4px solid #2563eb;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px';
    headDiv.innerHTML = `
      <div>
        <span style="font-size:15px;font-weight:700;color:#1e40af">🏗️ PROGRAMAÇÃO POR KIT COMPLETO DE PROJETO (CMI)</span>
        <div style="font-size:12px;color:#3b82f6;margin-top:2px">${formatarNumeroBr(listProjetos.length)} Projetos cadastrados — Clique em "👁️ Ver Papéis do Kit" para inspecionar os desenhos.</div>
      </div>
    `;
    containerLotes.appendChild(headDiv);

    listProjetos.forEach(p => {
      const div = document.createElement('div');
      div.style.cssText = 'padding:14px 16px;border:1px solid #e2e6ed;border-radius:8px;margin-bottom:12px;background:#fff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px';
      
      const matStr = [...p.materiais].slice(0, 4).join(' · ');
      const podeLiberar = (p.statusEng === 'LIBERADO');
      const liberacaoProjeto = liberacoesMap['projeto|' + montarChaveNatural('projeto', { tpdProjeto: p.tpd })];

      let badgeEng = '';
      if (podeLiberar) {
        badgeEng = '<span class="badge" style="background:#dcfce7;color:#15803d">🟢 Liberado Engenharia</span>';
      } else if (p.statusEng === 'BLOQUEADO') {
        badgeEng = '<span class="badge" style="background:#fee2e2;color:#b91c1c">🔴 Bloqueado Engenharia</span>';
      } else {
        badgeEng = '<span class="badge" style="background:#fef3c7;color:#78350f">🟡 Aguardando Liberação</span>';
      }

      div.innerHTML = `
        <div>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span style="background:#2563eb;color:#fff;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:700;font-family:monospace">${p.tpd}</span>
            <span style="font-size:14px;font-weight:700;color:#15803d">⚡ ${formatarNumeroBr(p.trafos)} Transformadores</span>
            <span style="background:#f1f5f9;color:#475569;padding:2px 6px;border-radius:4px;font-size:11px">Semana ${p.sem}</span>
            ${badgeEng}
          </div>
          <div style="font-size:12px;color:#64748b;margin-top:6px">
            <b>${formatarNumeroBr(p.ofsTotal)} OFs no kit</b> · ${formatarNumeroBr(p.pecas)} peças soltas ${p.massa > 0 ? '· ' + formatarMassaBr(p.massa) : ''}
          </div>
          <div style="font-size:11px;color:#9aa3b8;margin-top:4px">
            Componentes de Isolação: ${matStr}
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
          <button class="btn-ordem" style="background:#f1f5f9;color:#1e40af;border:1px solid #93c5fd;padding:7px 12px;border-radius:6px;font-weight:600;font-size:12px" onclick="verPapeisDoProjeto('${p.tpd}')">👁️ Ver Papéis do Kit</button>
          ${liberacaoProjeto ?
            `<span style="font-size:11.5px;color:#15803d;font-weight:600">✓ Liberado por ${liberacaoProjeto.nome_usuario_liberacao || 'Planejamento'} em ${liberacaoProjeto.data_liberacao_fmt || '—'}</span>` :
            (podeLiberar ?
              `<button class="btn-ordem" style="background:#2563eb;color:#fff;border:none;padding:7px 14px;border-radius:6px;font-weight:700;font-size:12px" onclick="liberarItem('${p.tpd}', 'Kit Completo do Projeto (${p.trafos} trafos)', ${p.ofsTotal}, ${p.pecas}, ${p.massa}, this)">Liberar Kit</button>` :
              `<button class="btn-ordem disabled" onclick="alertBloqueioEngenharia('${p.tpd}')">Bloqueado</button>`
            )
          }
        </div>
      `;
      containerLotes.appendChild(div);
    });
  }

  // 3. MODO PEÇA INDIVIDUAL
  function renderModoPecaIndividual(filtrados) {
    const headDiv = document.createElement('div');
    headDiv.style.cssText = 'background:#fefce8;padding:12px 16px;border-radius:8px;margin-bottom:14px;border-left:4px solid #ca8a04;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px';
    headDiv.innerHTML = `
      <div>
        <span style="font-size:15px;font-weight:700;color:#854d0e">✂️ SELEÇÃO MANUAL DE PEÇAS ESPECÍFICAS DA SEMANA</span>
        <div style="font-size:12px;color:#a16207;margin-top:2px">Marque as peças que deseja enviar para corte agora e clique em liberar.</div>
      </div>
      <button class="btn-ordem" id="btn-liberar-selecao" style="background:#854d0e;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:700;font-size:13px">✓ Liberar Peças Selecionadas (0)</button>
    `;
    containerLotes.appendChild(headDiv);

    const tableWrap = document.createElement('div');
    tableWrap.style.cssText = 'overflow-x:auto;border:1px solid #e2e6ed;border-radius:8px;background:#fff';
    
    let tableHtml = `
      <table class="data-table" style="width:100%;border-collapse:collapse">
        <thead>
          <tr style="background:#f8f9fb;border-bottom:1px solid #e2e6ed;font-size:11px;color:#64748b;text-transform:uppercase">
            <th style="padding:10px;text-align:center"><input type="checkbox" id="chk-marcar-todos" style="cursor:pointer"></th>
            <th style="padding:10px;text-align:left">OF Filha / Projeto</th>
            <th style="padding:10px;text-align:left">Nome da Peça (Engenharia)</th>
            <th style="padding:10px;text-align:left">Material & Espessura</th>
            <th style="padding:10px;text-align:left">Medida de Corte (L × C)</th>
            <th style="padding:10px;text-align:right">Qtd Peças</th>
            <th style="padding:10px;text-align:center">Status Eng.</th>
            <th style="padding:10px;text-align:center">Semana</th>
          </tr>
        </thead>
        <tbody>
    `;

    const amostra = filtrados.slice(0, 100);

    amostra.forEach((r) => {
      const ofNum = String(r[9]);
      const tpdName = D.tpds[r[8]] || '—';
      const pecaName = D.pecas[r[3]] || 'Peça';
      const matName = D.mats[r[4]] || 'Material';
      const esp = r[5];
      const dim = `${r[6]} × ${r[7]} mm`;
      const qtd = r[10];
      const sem = r[0];
      const stEng = r[16] || (D.tpd_status_eng ? D.tpd_status_eng[r[8]] : 'LIBERADO');

      const podeMarcar = (stEng === 'LIBERADO');
      const liberacaoPeca = liberacoesMap['peca|' + montarChaveNatural('peca', { numeroOf: ofNum })];
      const marcado = estadoSelecaoPrograma.pecasSelecionadas.has(ofNum);

      let celulaAcao;
      if (liberacaoPeca) {
        celulaAcao = `<span title="Liberado por ${liberacaoPeca.nome_usuario_liberacao || 'Planejamento'} em ${liberacaoPeca.data_liberacao_fmt || '—'}" style="color:#15803d;font-weight:700">✓</span>`;
      } else if (podeMarcar) {
        celulaAcao = `<input type="checkbox" class="chk-peca" data-of="${ofNum}" data-peca="${pecaName}" data-qtd="${qtd}" data-tpd="${tpdName}" ${marcado ? 'checked' : ''} style="cursor:pointer">`;
      } else {
        celulaAcao = `<span title="Bloqueado por Engenharia">🔒</span>`;
      }

      tableHtml += `
        <tr style="border-bottom:1px solid #eef1f5">
          <td style="padding:10px;text-align:center">${celulaAcao}</td>
          <td style="padding:10px;font-weight:600;font-family:monospace">${ofNum} <span style="font-weight:normal;color:#64748b">(${tpdName})</span></td>
          <td style="padding:10px;font-weight:600;color:#1a2133">${pecaName}</td>
          <td style="padding:10px"><span class="badge" style="background:#eef1f5;color:#333">${matName} ${formatarEspessuraBr(esp)}</span></td>
          <td style="padding:10px;font-family:monospace;font-weight:600;color:#1a3d2a">${dim}</td>
          <td style="padding:10px;text-align:right;font-weight:700;font-family:monospace">${formatarNumeroBr(qtd)} pçs</td>
          <td style="padding:10px;text-align:center">
            ${liberacaoPeca ? '<span class="badge" style="background:#dcfce7;color:#15803d">Liberado p/ Corte</span>' : (stEng === 'LIBERADO' ? '<span class="badge" style="background:#dcfce7;color:#15803d">Liberado</span>' : '<span class="badge" style="background:#fef3c7;color:#78350f">Bloqueado</span>')}
          </td>
          <td style="padding:10px;text-align:center">Sem ${sem}</td>
        </tr>
      `;
    });

    tableHtml += `</tbody></table>`;
    tableWrap.innerHTML = tableHtml;
    containerLotes.appendChild(tableWrap);

    const chkTodos = document.getElementById('chk-marcar-todos');
    const chks = document.querySelectorAll('.chk-peca');
    const btnLiberarSel = document.getElementById('btn-liberar-selecao');

    function atualizarContadorSelecao() {
      const selecionados = document.querySelectorAll('.chk-peca:checked');
      btnLiberarSel.textContent = `✓ Liberar Peças Selecionadas (${formatarNumeroBr(selecionados.length)})`;
    }

    if (chkTodos) {
      chkTodos.onchange = () => {
        chks.forEach(c => {
          c.checked = chkTodos.checked;
          if (chkTodos.checked) estadoSelecaoPrograma.pecasSelecionadas.add(c.dataset.of);
          else estadoSelecaoPrograma.pecasSelecionadas.delete(c.dataset.of);
        });
        atualizarContadorSelecao();
      };
    }

    chks.forEach(c => {
      c.onchange = () => {
        if (c.checked) estadoSelecaoPrograma.pecasSelecionadas.add(c.dataset.of);
        else estadoSelecaoPrograma.pecasSelecionadas.delete(c.dataset.of);
        atualizarContadorSelecao();
      };
    });

    if (btnLiberarSel) {
      btnLiberarSel.onclick = () => {
        const selecionados = document.querySelectorAll('.chk-peca:checked');
        if (selecionados.length === 0) {
          showModalMessage('Seleção de Peças', 'Por favor, marque ao menos uma peça na tabela para liberar.');
          return;
        }

        const itens = [];
        let totalPecasSel = 0;
        selecionados.forEach(s => {
          totalPecasSel += parseInt(s.dataset.qtd || '0', 10);
          itens.push({
            tpd_projeto: s.dataset.tpd,
            numero_of: s.dataset.of,
            peca_nome: s.dataset.peca,
            qtd_pecas: parseInt(s.dataset.qtd || '0', 10)
          });
        });

        const appBase = window.__APP_BASE || '';
        const formData = new FormData();
        formData.append('acao', 'liberar_peca');
        formData.append('itens', JSON.stringify(itens));

        btnLiberarSel.disabled = true;
        const textoOriginal = btnLiberarSel.textContent;
        btnLiberarSel.textContent = 'Liberando...';

        fetch(`${appBase}/api/papel-liberacao-acao.php`, { method: 'POST', body: formData })
          .then(r => r.json())
          .then(res => {
            if (res.success) {
              itens.forEach(item => estadoSelecaoPrograma.pecasSelecionadas.delete(item.numero_of));
              return carregarLiberacoes().then(() => {
                showModalMessage('Peças Liberadas', `✅ ${formatarNumeroBr(selecionados.length)} itens/OFs (${formatarNumeroBr(totalPecasSel)} peças no total) foram programados e liberados com sucesso para o Chão de Fábrica!`);
                carregarDemanda();
              });
            } else {
              btnLiberarSel.disabled = false;
              btnLiberarSel.textContent = textoOriginal;
              showModalMessage('Falha ao Liberar', res.error || 'Não foi possível liberar as peças selecionadas.');
            }
          })
          .catch(err => {
            btnLiberarSel.disabled = false;
            btnLiberarSel.textContent = textoOriginal;
            showModalMessage('Erro de Comunicação', 'Não foi possível liberar as peças: ' + err.message);
          });
      };
    }
  }

  btnCarregar.onclick = carregarDemanda;
  // Exposto no escopo global: liberarLoteBobinaCustom/liberarItem/o handler de liberar
  // peças (fora deste fechamento) precisam re-renderizar depois de persistir a liberação.
  window.carregarDemanda = carregarDemanda;
  carregarDemanda(); // Inicial
});

// Funções Globais de Controle de Modais e Liberação
function abrirModal(id) {
  const modal = document.getElementById(id);
  if (modal) modal.style.display = 'flex';
}

function fecharModal(id) {
  const modal = document.getElementById(id);
  if (modal) modal.style.display = 'none';
}

function showModalMessage(titulo, mensagem) {
  let modalMsg = document.getElementById('modal-mensagem-alerta');
  if (!modalMsg) {
    modalMsg = document.createElement('div');
    modalMsg.id = 'modal-mensagem-alerta';
    modalMsg.className = 'modal-overlay';
    modalMsg.style.display = 'none';
    modalMsg.innerHTML = `
      <div class="modal" style="max-width:440px">
        <div class="modal-header">
          <h3 class="modal-title" id="msg-modal-title">Aviso</h3>
          <button class="modal-close" onclick="fecharModal('modal-mensagem-alerta')">&times;</button>
        </div>
        <div class="modal-body" id="msg-modal-body" style="white-space:pre-line"></div>
        <div class="modal-footer">
          <button class="btn btn-primary" onclick="fecharModal('modal-mensagem-alerta')">OK</button>
        </div>
      </div>
    `;
    document.body.appendChild(modalMsg);
  }
  document.getElementById('msg-modal-title').textContent = titulo;
  document.getElementById('msg-modal-body').textContent = mensagem;
  abrirModal('modal-mensagem-alerta');
}

function liberarLoteBobinaCustom(bobId, desc, bIdx, btn, mesSel, material, espessura, larguraBobina) {
  const card = document.getElementById(`card-bobina-${bIdx}`);
  const marcados = card.querySelectorAll(`.chk-sem-bob-${bIdx}:checked`);
  if (marcados.length === 0) {
    showModalMessage('Seleção de Semanas', 'Por favor, selecione ao menos uma semana do lote de bobina para liberar.');
    return;
  }

  let totalPecas = 0;
  const sems = [];
  const itens = [];
  marcados.forEach(m => {
    const pecas = parseInt(m.dataset.pecas || '0', 10);
    totalPecas += pecas;
    sems.push('Sem ' + m.dataset.sem);
    itens.push({ semana: parseInt(m.dataset.sem, 10), qtd_pecas: pecas, massa_kg: parseFloat(m.dataset.massa || '0') });
  });

  const appBase = window.__APP_BASE || '';
  const formData = new FormData();
  formData.append('acao', 'liberar_bobina');
  formData.append('mes_pcp', mesSel);
  formData.append('material', material);
  formData.append('espessura', espessura);
  formData.append('largura_bobina', larguraBobina);
  formData.append('descricao_snapshot', desc);
  formData.append('itens', JSON.stringify(itens));

  btn.disabled = true;
  const textoOriginal = btn.textContent;
  btn.textContent = 'Liberando...';

  fetch(`${appBase}/api/papel-liberacao-acao.php`, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        return carregarLiberacoes().then(() => {
          showModalMessage(
            'Lote de Bobina Liberado com Sucesso',
            `✅ ${bobId} — ${desc}\n\nSemanas Liberadas: ${sems.join(' + ')}\nTotal de Peças a Cortar: ${totalPecas.toLocaleString('pt-BR')} peças\n\nO lote de bobina foi enviado diretamente para a Fila do Slitter e Mesa de Corte!`
          );
          carregarDemanda();
        });
      } else {
        btn.disabled = false;
        btn.textContent = textoOriginal;
        showModalMessage('Falha ao Liberar', res.error || 'Não foi possível liberar o lote de bobina.');
      }
    })
    .catch(err => {
      btn.disabled = false;
      btn.textContent = textoOriginal;
      showModalMessage('Erro de Comunicação', 'Não foi possível liberar o lote: ' + err.message);
    });
}

function liberarItem(itemId, desc, ofs, pecas, massa, btn) {
  const appBase = window.__APP_BASE || '';
  const formData = new FormData();
  formData.append('acao', 'liberar_projeto');
  formData.append('tpd_projeto', itemId);
  formData.append('ofs_total', ofs);
  formData.append('pecas_total', pecas);
  formData.append('massa_total', massa || 0);
  formData.append('descricao_snapshot', desc);

  btn.disabled = true;
  const textoOriginal = btn.textContent;
  btn.textContent = 'Liberando...';

  fetch(`${appBase}/api/papel-liberacao-acao.php`, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        return carregarLiberacoes().then(() => {
          const ofsFmt = typeof ofs === 'number' ? ofs.toLocaleString('pt-BR') : ofs;
          const pecasFmt = typeof pecas === 'number' ? pecas.toLocaleString('pt-BR') : pecas;
          showModalMessage('Kit Liberado com Sucesso', `✅ ${itemId} — ${desc} liberado com sucesso!\n\nAs ${ofsFmt} OFs e ${pecasFmt} peças foram enviadas para a Fila de Máquinas e Apontamento do Chão.`);
          carregarDemanda();
        });
      } else {
        btn.disabled = false;
        btn.textContent = textoOriginal;
        showModalMessage('Falha ao Liberar', res.error || 'Não foi possível liberar o kit do projeto.');
      }
    })
    .catch(err => {
      btn.disabled = false;
      btn.textContent = textoOriginal;
      showModalMessage('Erro de Comunicação', 'Não foi possível liberar o kit: ' + err.message);
    });
}

function alertBloqueioEngenharia(tpd) {
  showModalMessage(
    '⚠️ Projeto Não Liberado pela Engenharia',
    `O Projeto ${tpd} encontra-se com status de Engenharia PENDENTE ou BLOQUEADO.\n\nPor normas de qualidade da TRAEL (ISO-9001), o corte de isolação não pode ser iniciado enquanto a Engenharia não liberar formalmente o desenho técnico e a lista de materiais.`
  );
}

// Classifier de CMI
function classificarGrupoPapel(pecaName, catName) {
  const text = (pecaName + ' ' + catName).toUpperCase();
  if (text.includes('CANUDO') || text.includes('ENTRE CAMADAS') || text.includes('REFORÇO') || text.includes('REFORCO') || text.includes('CAMADAS AT') || (text.includes('AT') && !text.includes('NÚCLEO') && !text.includes('NUCLEO'))) {
    return 'AT';
  } else if (text.includes('CILINDRO') || text.includes('FORMA') || text.includes('FAIXA') || (text.includes('BT') && !text.includes('NÚCLEO') && !text.includes('NUCLEO'))) {
    return 'BT';
  } else {
    return 'PARTE_ATIVA';
  }
}

function calcMassaUnitJs(esp, larg, comp, mat) {
  const dens = (mat === 'PRESSPHAN') ? 1.2 : ((mat === 'DIAMANT') ? 0.95 : 0.85);
  const volCm3 = (esp / 10.0) * (larg / 10.0) * (comp / 10.0);
  return (volCm3 * dens) / 1000.0;
}

function formatarMassaUnitJs(pesoKg) {
  if (!pesoKg || pesoKg <= 0) return '—';
  if (pesoKg < 0.001) {
    return (pesoKg * 1000.0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' g';
  }
  return pesoKg.toLocaleString('pt-BR', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) + ' kg';
}

function formatarMassaTotalJs(kg) {
  if (!kg || kg <= 0) return '—';
  const val = Number(kg);
  if (val >= 1000) {
    const tons = val / 1000;
    const dec = tons >= 100 ? 1 : 2;
    return tons.toLocaleString('pt-BR', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + ' t';
  }
  return val.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' kg';
}

// Modal Drill-Down do Projeto (TPD)
function verPapeisDoProjeto(tpdName) {
  if (typeof D === 'undefined') return;

  const tpdIdx = D.tpds.indexOf(tpdName);
  if (tpdIdx === -1) return;

  const rowsProj = D.rows.filter(r => r[8] === tpdIdx);
  const trafosQtd = rowsProj.length > 0 ? (rowsProj[0][12] !== undefined ? rowsProj[0][12] : 5) : 5;
  const ofsTotal = rowsProj.length > 0 ? (rowsProj[0][15] !== undefined ? rowsProj[0][15] : (D.tpd_ofs ? D.tpd_ofs[tpdIdx] : 28)) : 28;
  const totalPecas = rowsProj.reduce((acc, r) => acc + r[10], 0);
  const massaTotal = rowsProj.reduce((acc, r) => acc + (r[11] || 0), 0);
  const stEng = rowsProj.length > 0 ? (rowsProj[0][16] || (D.tpd_status_eng ? D.tpd_status_eng[tpdIdx] : 'LIBERADO')) : 'LIBERADO';

  let massaFmt = '—';
  if (massaTotal > 0) {
    if (massaTotal >= 1000) {
      const t = massaTotal / 1000;
      massaFmt = t.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' t';
    } else {
      massaFmt = massaTotal.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' kg';
    }
  }

  document.getElementById('modal-tpd-nome').textContent = tpdName;
  
  const resumoEl = document.getElementById('modal-tpd-resumo');
  resumoEl.innerHTML = `
    <div><b>Transformadores:</b> <span style="color:#15803d;font-weight:700">${trafosQtd.toLocaleString('pt-BR')} un</span></div>
    <div><b>Total de OFs no Kit:</b> ${ofsTotal.toLocaleString('pt-BR')}</div>
    <div><b>Total de Peças Soltas:</b> ${totalPecas.toLocaleString('pt-BR')} pçs</div>
    <div><b>Massa Total:</b> ${massaFmt}</div>
    <div><b>Status Engenharia:</b> <span style="font-weight:700;color:${stEng === 'LIBERADO' ? '#15803d' : '#b91c1c'}">${stEng}</span></div>
  `;

  // Separar em AT, BT e PARTE ATIVA
  const rowsAT = [], rowsBT = [], rowsPA = [];

  rowsProj.forEach(r => {
    const pecaName = D.pecas[r[3]] || '';
    const catName = D.cats[r[2]] || '';
    const grupo = classificarGrupoPapel(pecaName, catName);

    if (grupo === 'AT') rowsAT.push(r);
    else if (grupo === 'BT') rowsBT.push(r);
    else rowsPA.push(r);
  });

  const secoesEl = document.getElementById('modal-tpd-secoes');
  secoesEl.innerHTML = '';

  let fullHtml = '';
  fullHtml += renderSecaoTabela('1. CONJUNTO ALTA TENSÃO (AT)', '⚡', '#2563eb', '#2563eb', rowsAT, tpdName);
  fullHtml += renderSecaoTabela('2. CONJUNTO BAIXA TENSÃO (BT)', '⚡', '#16a34a', '#16a34a', rowsBT, tpdName);
  fullHtml += renderSecaoTabela('3. MONTAGEM DA PARTE ATIVA (ELÉTRICA + NÚCLEO)', '⚙️', '#d97706', '#d97706', rowsPA, tpdName);

  secoesEl.innerHTML = fullHtml;

  const btnLiberarKitComp = document.getElementById('btn-modal-liberar-tpd-completo');
  if (btnLiberarKitComp) {
    if (stEng === 'LIBERADO') {
      btnLiberarKitComp.disabled = false;
      btnLiberarKitComp.style.opacity = '1';
      btnLiberarKitComp.style.cursor = 'pointer';
      btnLiberarKitComp.onclick = () => {
        fecharModal('modal-detalhes-projeto');
        showModalMessage('Kit Liberado', `✅ O Kit Completo do Projeto ${tpdName} (${ofsTotal.toLocaleString('pt-BR')} OFs e ${totalPecas.toLocaleString('pt-BR')} peças) foi liberado com sucesso para o Chão de Fábrica!`);
      };
    } else {
      btnLiberarKitComp.disabled = true;
      btnLiberarKitComp.style.opacity = '0.5';
      btnLiberarKitComp.style.cursor = 'not-allowed';
      btnLiberarKitComp.onclick = null;
    }
  }

  abrirModal('modal-detalhes-projeto');
}

function renderSecaoTabela(titulo, icon, borderLeftColor, badgeColor, rowsGroup, tpdName) {
  if (!rowsGroup || rowsGroup.length === 0) return '';

  const totalPecas = rowsGroup.reduce((acc, r) => acc + r[10], 0);

  let html = `
    <div style="margin-bottom:20px;border:1px solid #e2e6ed;border-radius:8px;overflow:hidden;background:#fff">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e2e6ed;border-left:4px solid ${borderLeftColor}">
        <div style="font-size:14px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:6px">
          <span>${icon}</span> <span>${titulo}</span>
          <span style="font-size:11px;font-weight:600;background:${badgeColor};color:#fff;padding:2px 7px;border-radius:4px;margin-left:6px">${rowsGroup.length.toLocaleString('pt-BR')} itens · ${totalPecas.toLocaleString('pt-BR')} pçs</span>
        </div>
      </div>
      <div style="overflow-x:auto">
        <table class="data-table" style="width:100%;border-collapse:collapse">
          <thead>
            <tr style="background:#fff;border-bottom:1px solid #e2e6ed;font-size:11px;color:#64748b;text-transform:uppercase">
              <th style="padding:9px;text-align:left">OF Filha</th>
              <th style="padding:9px;text-align:left">Nome da Peça (Engenharia)</th>
              <th style="padding:9px;text-align:left">Material</th>
              <th style="padding:9px;text-align:left">Esp.</th>
              <th style="padding:9px;text-align:left">Medida (L × C)</th>
              <th style="padding:9px;text-align:right">Qtd Peças</th>
              <th style="padding:9px;text-align:right">Massa Total</th>
              <th style="padding:9px;text-align:center">Aproveitamento Estoque</th>
            </tr>
          </thead>
          <tbody>
  `;

  rowsGroup.forEach(r => {
    const ofNum = r[9];
    const pecaName = D.pecas[r[3]] || 'Peça';
    const catName = D.cats[r[2]] || '—';
    const matName = D.mats[r[4]] || 'Material';
    const esp = Number(r[5]);
    const larg = Number(r[6]);
    const comp = Number(r[7]);
    const dim = `${r[6]} × ${r[7]} mm`;
    const qtd = r[10];

    const pesoUnit = calcMassaUnitJs(esp, larg, comp, matName);
    const pesoTotal = r[11] || (pesoUnit * qtd);

    const matchEstoque = buscarMatchEstoque(tpdName, r[3], pecaName, matName, esp, larg, comp);
    let matchHtml = '<span style="color:#cbd5e1;font-size:11px">—</span>';
    if (matchEstoque) {
      const decisaoKey = tpdName + '_' + r[3];
      const decisao = decisoesAproveitamentoMap[decisaoKey];
      if (decisao && decisao.status === 'aprovado') {
        matchHtml = `<span class="badge" style="background:#dcfce7;color:#15803d;border:1px solid #86efac;font-size:11px" title="Aproveitado: Rua ${matchEstoque.rua} / Prat. ${matchEstoque.prateleira || matchEstoque.caixa}">📦 Estoque (${matchEstoque.rua}/${matchEstoque.prateleira || matchEstoque.caixa})</span>`;
      } else if (decisao && decisao.status === 'rejeitado') {
        matchHtml = `<span class="badge" style="background:#f1f5f9;color:#64748b;font-size:11px">Máquina</span>`;
      } else {
        matchHtml = `<button onclick="abrirModalAproveitamento('${tpdName}', ${r[3]}, '${pecaName}', '${matName}', ${esp}, '${larg} × ${comp}', ${qtd}, ${pesoTotal}, ${matchEstoque.id})" style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer" title="Clique para analisar aproveitamento do almoxarifado">🟡 📦 Match Estoque (${matchEstoque.rua}/${matchEstoque.prateleira || matchEstoque.caixa})</button>`;
      }
    }

    html += `
      <tr style="border-bottom:1px solid #eef1f5">
        <td style="padding:8px 10px;font-weight:600;font-family:monospace">${ofNum}</td>
        <td style="padding:8px 10px;font-weight:600;color:#1a2133">${pecaName} <div style="font-weight:normal;font-size:11px;color:#64748b">${catName}</div></td>
        <td style="padding:8px 10px"><span class="badge" style="background:#eef1f5;color:#333">${matName}</span></td>
        <td style="padding:8px 10px">${esp.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} mm</td>
        <td style="padding:8px 10px;font-family:monospace;font-weight:600;color:#1a3d2a">${dim}</td>
        <td style="padding:8px 10px;text-align:right;font-weight:700;font-family:monospace">${qtd.toLocaleString('pt-BR')} pçs</td>
        <td style="padding:8px 10px;text-align:right;font-weight:700;color:#1a3d2a;font-size:13px">${formatarMassaTotalJs(pesoTotal)}</td>
        <td style="padding:8px 10px;text-align:center">${matchHtml}</td>
      </tr>
    `;
  });

  html += `</tbody></table></div></div>`;
  return html;
}

// Modal Corte Extra
function solicitarCorteExtra() {
  atualizarSelectPecasExtra();
  abrirModal('modal-corte-extra');
}

function atualizarSelectPecasExtra() {
  const extraTpdSelect = document.getElementById('extra-tpd');
  const extraPecaSelect = document.getElementById('extra-peca-select');
  if (!extraTpdSelect || !extraPecaSelect || typeof D === 'undefined') return;

  const tpdName = extraTpdSelect.value;
  const tpdIdx = D.tpds.indexOf(tpdName);

  extraPecaSelect.innerHTML = '';

  if (tpdIdx === -1) {
    extraPecaSelect.innerHTML = '<option value="">Selecione um projeto válido...</option>';
    atualizarPreviewPecaExtra();
    return;
  }

  const rowsProj = D.rows.filter(r => r[8] === tpdIdx);

  if (rowsProj.length === 0) {
    extraPecaSelect.innerHTML = '<option value="">Nenhuma peça encontrada neste projeto</option>';
    atualizarPreviewPecaExtra();
    return;
  }

  const defaultOpt = document.createElement('option');
  defaultOpt.value = '';
  defaultOpt.textContent = `-- Selecione a peça do projeto ${tpdName} (${rowsProj.length} disponíveis) --`;
  extraPecaSelect.appendChild(defaultOpt);

  rowsProj.forEach(r => {
    const ofNum = r[9];
    const pecaName = D.pecas[r[3]] || 'Peça';
    const matName = D.mats[r[4]] || 'Material';
    const esp = Number(r[5]).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' mm';
    const dim = `${r[6]} × ${r[7]} mm`;
    const qtd = r[10];

    const opt = document.createElement('option');
    opt.value = ofNum;
    opt.textContent = `[OF ${ofNum}] ${pecaName} — ${matName} ${esp} (${dim})`;
    
    opt.dataset.pecaName = pecaName;
    opt.dataset.matName = matName;
    opt.dataset.esp = esp;
    opt.dataset.dim = dim;
    opt.dataset.qtd = qtd;
    opt.dataset.of = ofNum;

    extraPecaSelect.appendChild(opt);
  });

  if (rowsProj.length > 0) {
    extraPecaSelect.selectedIndex = 1;
  }

  atualizarPreviewPecaExtra();
}

function atualizarPreviewPecaExtra() {
  const extraPecaSelect = document.getElementById('extra-peca-select');
  const previewCard = document.getElementById('extra-peca-preview');
  if (!extraPecaSelect || !previewCard) return;

  const selOpt = extraPecaSelect.options[extraPecaSelect.selectedIndex];

  if (!selOpt || !selOpt.value || !selOpt.dataset.pecaName) {
    previewCard.style.display = 'none';
    return;
  }

  document.getElementById('preview-peca-nome').textContent = `OF ${selOpt.dataset.of} — ${selOpt.dataset.pecaName}`;
  document.getElementById('preview-peca-mat').textContent = selOpt.dataset.matName;
  document.getElementById('preview-peca-esp').textContent = selOpt.dataset.esp;
  document.getElementById('preview-peca-dim').textContent = selOpt.dataset.dim;
  document.getElementById('preview-peca-qtd').textContent = `${Number(selOpt.dataset.qtd).toLocaleString('pt-BR')} peças`;

  previewCard.style.display = 'block';
}

function confirmarCorteExtraModal() {
  const tpd = document.getElementById('extra-tpd').value;
  const extraPecaSelect = document.getElementById('extra-peca-select');
  const selOpt = extraPecaSelect ? extraPecaSelect.options[extraPecaSelect.selectedIndex] : null;

  if (!selOpt || !selOpt.value || !selOpt.dataset.pecaName) {
    alert("Por favor, selecione uma peça válida da lista da engenharia do projeto.");
    return;
  }

  const pecaName = selOpt.dataset.pecaName;
  const matName = selOpt.dataset.matName;
  const esp = selOpt.dataset.esp;
  const dim = selOpt.dataset.dim;
  const ofNum = selOpt.dataset.of;

  const qtd = document.getElementById('extra-qtd').value;
  const motivo = document.getElementById('extra-motivo').value;
  const obs = document.getElementById('extra-obs').value.trim();

  fecharModal('modal-corte-extra');

  const loteId = `LOTE #EXTRA-${Math.floor(Math.random()*900 + 100)}`;
  
  showModalMessage(
    '🚨 Reposição / Corte Extra Emitido com Sucesso!',
    `Lote URGENTE: ${loteId}\nProjeto: ${tpd}\nOF: ${ofNum}\nPeça: ${pecaName}\nMaterial & Especificação: ${matName} ${esp} (${dim})\nQtd Solicitada: ${qtd} peças\nMotivo da Reposição: ${motivo}\n\nObservação: ${obs || 'Sem observações'}\n\nPrioridade: 🔴 ALTA / URGENTE\n\nO lote de reposição foi inserido imediatamente no TOPO da Fila de Máquinas e Apontamento do Chão!`
  );
}

function sincronizarComVsat() {
  const btn = document.getElementById('btn-sync-vsat');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span>⏳ Conectando ao VSAT SQL Server...</span>';
  }

  const appBase = window.__APP_BASE || '';
  const url = (appBase ? appBase : '') + '/api/papel-sincronizar-vsat.php';

  fetch(url, { method: 'POST' })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        alert(`✅ Sincronização com o ERP VSAT concluída com sucesso!\n\nProjetos Carregados: ${data.total_projetos}\nOrdens Filhas de Isolação: ${data.total_itens}\nData da Sincronização: ${data.data_sincronizacao}`);
        window.location.reload();
      } else {
        alert(`❌ Falha na Sincronização com o ERP VSAT\n\n${data.error || 'Não foi possível obter dados do banco de dados.'}`);
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = '<span>🔄 Sincronizar com ERP VSAT</span>';
        }
      }
    })
    .catch(err => {
      alert(`❌ Falha de comunicação com o servidor:\n\n${err.message}`);
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<span>🔄 Sincronizar com ERP VSAT</span>';
      }
    });
}

// --- Motor de Match e Aprovação do Almoxarifado de Papel ---
function carregarMatchesEstoque() {
  const appBase = window.__APP_BASE || '';
  fetch(appBase + '/api/papel-inventario-acao.php?acao=verificar_matches')
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        estoqueAlmoxarifado = res.estoque || [];
        decisoesAproveitamentoMap = {};
        (res.decisoes || []).forEach(d => {
          const k = d.tpd_projeto + '_' + d.peca_idx;
          decisoesAproveitamentoMap[k] = d;
        });
      }
    })
    .catch(err => console.warn('Aviso: Não foi possível carregar estoque do almoxarifado:', err));
}

// --- Liberação de Corte: merge do estado persistido depois de carregar o JSON do PCP ---
// Mesmo padrão de carregarMatchesEstoque(): o dados_pcp_completo.json é regenerado do
// zero a cada sync com o VSAT, então o que foi liberado localmente precisa ser
// re-mesclado aqui, senão some no próximo sync/reload.
function carregarLiberacoes() {
  const appBase = window.__APP_BASE || '';
  return fetch(appBase + '/api/papel-liberacao-acao.php?acao=verificar_liberacoes')
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        liberacoesMap = {};
        (res.liberacoes || []).forEach(l => {
          const key = l.granularidade + '|' + l.chave_natural;
          liberacoesMap[key] = l;
        });
      }
    })
    .catch(err => console.warn('Aviso: Não foi possível carregar liberações de corte:', err));
}

function buscarMatchEstoque(tpd, pecaIdx, nomePeca, mat, esp, larg, comp) {
  if (!estoqueAlmoxarifado || estoqueAlmoxarifado.length === 0) return null;

  // 1. Procura por vínculo direto de projeto TPD (Kits ou tiras reservadas)
  const matchProj = estoqueAlmoxarifado.find(i => 
    i.tpd_projeto && i.tpd_projeto.trim() === tpd.trim() && 
    (Number(i.estoque_real_kg) > 0 || Number(i.estoque_real_qtd) > 0) &&
    i.status !== 'consumido'
  );
  if (matchProj) return matchProj;

  // 2. Procura por especificação geométrica exata (Material + Espessura + Dimensões)
  const espNum = Number(esp);
  const largNum = Number(larg);
  const compNum = Number(comp);

  const matchEsp = estoqueAlmoxarifado.find(i => {
    if (i.status === 'consumido' || Number(i.estoque_real_kg) <= 0) return false;
    if (i.material !== mat) return false;
    if (Math.abs(Number(i.espessura) - espNum) > 0.01) return false;

    const tam = (i.tamanho || '').trim().replace(/\s+/g, '');
    if (tam === `${largNum}-${compNum}` || tam === `${largNum}x${compNum}` || tam === `${largNum}` || tam === `${compNum}`) {
      return true;
    }
    return false;
  });

  return matchEsp || null;
}

function abrirModalAproveitamento(tpd, pecaIdx, nomePeca, mat, esp, dim, qtdDemandada, massaDemandada, idEstoque) {
  const itemEstoque = estoqueAlmoxarifado.find(x => Number(x.id) === Number(idEstoque));
  if (!itemEstoque) {
    alert('Item de estoque não localizado.');
    return;
  }

  matchAtivoParaDecisao = {
    tpd,
    pecaIdx,
    nomePeca,
    mat,
    esp,
    dim,
    qtdDemandada,
    massaDemandada,
    idEstoque,
    itemEstoque
  };

  const decisaoKey = tpd + '_' + pecaIdx;
  const decisaoExistente = decisoesAproveitamentoMap[decisaoKey];

  const corpo = document.getElementById('modal-aproveitamento-corpo');
  if (!corpo) return;

  let localFmt = `Rua <strong>${itemEstoque.rua}</strong>`;
  if (itemEstoque.prateleira) localFmt += ` / Prat. <strong>${itemEstoque.prateleira}</strong>`;
  if (itemEstoque.caixa) localFmt += ` / Cx. <strong>${itemEstoque.caixa}</strong>`;

  let saldoFmt = Number(itemEstoque.estoque_real_kg) > 0 
    ? `${Number(itemEstoque.estoque_real_kg).toLocaleString('pt-BR', {minimumFractionDigits: 2})} kg`
    : `${itemEstoque.estoque_real_qtd} un`;

  let statusDecisaoHtml = '';
  if (decisaoExistente) {
    if (decisaoExistente.status === 'aprovado') {
      statusDecisaoHtml = `
        <div style="background:#dcfce7;border:1px solid #86efac;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;color:#15803d">
          <strong>✅ APROVADO POR:</strong> ${decisaoExistente.nome_usuario_decisao || 'Liderança'} em ${decisaoExistente.data_decisao_fmt || '—'}<br>
          <span style="font-size:12px">Localização para separação física: <strong>${localFmt}</strong></span>
        </div>
      `;
    } else {
      statusDecisaoHtml = `
        <div style="background:#fee2e2;border:1px solid #fca5a5;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;color:#b91c1c">
          <strong>❌ REJEITADO:</strong> ${decisaoExistente.motivo_rejeicao || 'Cortar novo na máquina'} (por ${decisaoExistente.nome_usuario_decisao || 'Liderança'})
        </div>
      `;
    }
  }

  corpo.innerHTML = `
    ${statusDecisaoHtml}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px">
      <!-- Demanda do PCP -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px">
        <h4 style="margin:0 0 8px;font-size:11.5px;text-transform:uppercase;color:#475569;font-weight:700">1. Demanda do PCP (Corte)</h4>
        <div style="font-size:13px;line-height:1.6;color:#1e293b">
          <div><b>Projeto:</b> <span style="font-family:monospace;font-weight:700;color:#1e40af">${tpd}</span></div>
          <div><b>Peça:</b> ${nomePeca}</div>
          <div><b>Material:</b> ${mat} (${esp} mm)</div>
          <div><b>Medida do Corte:</b> <span style="font-family:monospace;font-weight:700">${dim} mm</span></div>
          <div><b>Quantidade:</b> <strong>${qtdDemandada.toLocaleString('pt-BR')} pçs</strong></div>
          <div><b>Massa Estimada:</b> <strong style="color:#1a3d2a">${massaDemandada.toLocaleString('pt-BR', {minimumFractionDigits:2})} kg</strong></div>
        </div>
      </div>

      <!-- Estoque no Almoxarifado -->
      <div style="background:#fefce8;border:1px solid #fef08a;border-radius:8px;padding:14px">
        <h4 style="margin:0 0 8px;font-size:11.5px;text-transform:uppercase;color:#854d0e;font-weight:700">2. Disponível no Almoxarifado</h4>
        <div style="font-size:13px;line-height:1.6;color:#713f12">
          <div><b>Localização Física:</b> <span class="badge-local" style="font-size:13px">${localFmt}</span></div>
          <div><b>Tamanho em Estoque:</b> <span style="font-family:monospace;font-weight:700">${itemEstoque.tamanho || '—'}</span></div>
          <div><b>Tipo / Modelo:</b> ${itemEstoque.tipo_papel || 'DOBRA'} (${itemEstoque.modelo || 'DT'})</div>
          <div><b>Saldo Atual:</b> <strong style="font-size:15px;color:#15803d">${saldoFmt}</strong></div>
          ${itemEstoque.tpd_projeto ? `<div><b>Vínculo Projeto:</b> <span style="font-family:monospace;font-weight:700">${itemEstoque.tpd_projeto}</span></div>` : ''}
        </div>
      </div>
    </div>
    <div style="background:#f1f5f9;border-radius:6px;padding:10px 12px;font-size:12px;color:#475569">
      💡 <strong>Aproveitamento:</strong> Ao aprovar, o operador será direcionado para retirar o material em <strong>${localFmt}</strong>, poupando setup e corte de bobinas novas.
    </div>
  `;

  const btnAprovar = document.getElementById('btn-aprovar-aproveitamento');
  const btnRejeitar = document.getElementById('btn-rejeitar-aproveitamento');

  if (btnAprovar) {
    btnAprovar.onclick = () => executarDecisaoAproveitamento('aprovar');
  }
  if (btnRejeitar) {
    btnRejeitar.onclick = () => executarDecisaoAproveitamento('rejeitar');
  }

  abrirModal('modal-aproveitamento-estoque');
}

function executarDecisaoAproveitamento(tipo) {
  if (!matchAtivoParaDecisao) return;
  const m = matchAtivoParaDecisao;
  const appBase = window.__APP_BASE || '';
  const acao = (tipo === 'aprovar') ? 'aprovar_aproveitamento' : 'rejeitar_aproveitamento';

  const formData = new FormData();
  formData.append('acao', acao);
  formData.append('id_estoque', m.idEstoque);
  formData.append('tpd_projeto', m.tpd);
  formData.append('peca_idx', m.pecaIdx);
  formData.append('peca_nome', m.nomePeca);
  formData.append('material', m.mat);
  formData.append('espessura', m.esp);
  formData.append('tamanho_demanda', m.dim);
  formData.append('qtd_demandada', m.qtdDemandada);
  formData.append('massa_demandada_kg', m.massaDemandada);

  fetch(`${appBase}/api/papel-inventario-acao.php`, {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      alert(res.mensagem || 'Decisão registrada com sucesso!');
      fecharModal('modal-aproveitamento-estoque');
      carregarMatchesEstoque();
      if (typeof verPapeisDoProjeto === 'function' && m.tpd) {
        verPapeisDoProjeto(m.tpd);
      }
    } else {
      alert('Erro: ' + (res.error || 'Falha ao registrar decisão'));
    }
  })
  .catch(err => alert('Erro de comunicação: ' + err.message));
}

