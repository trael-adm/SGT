/**
 * Mesa de Corte — Setor de Papel (SGT)
 * Reestruturação com foco em Máquina, Material, Espessura, Tamanho e Quantidade.
 * Otimização de setup industrial para corte em lote.
 */

// Variáveis globais para Modal e Filtros
let modalRowsAtuais = [];
let modalTituloAtual = '';
let fMaquina = ''; // '' = Todas, '17001', '17002', '17012', '17003'
let modoHorizonte = 'semana'; // 'semana' ou 'mes'
let fMes = null;
let fSemana = null;
let fMaterial = null;
let fEspessura = null;
let fMedida = new Set(); // multi-seleção; vazio = "Todas as Medidas de Projeto"
let fStatus = null;
let fStatusEng = null;
let ultimoFiltrados = []; // última lista de linhas cruas (r[]) que passou pelo filtro ativo

const maquinasNomes = {
  17001: { nome: 'Slitter de Bobina', cod: '17001', cls: 'badge-maq-17001' },
  17002: { nome: 'Guilhotina Pedal', cod: '17002', cls: 'badge-maq-17002' },
  17012: { nome: 'Guilhotina Elétrica', cod: '17012', cls: 'badge-maq-17012' },
  17003: { nome: 'Dobradeira Papel', cod: '17003', cls: 'badge-maq-17003' },
};

document.addEventListener('DOMContentLoaded', () => {
  if (typeof D === 'undefined' || !D.rows) return;

  const fMesEl = document.getElementById('f-mes');
  const fSemEl = document.getElementById('f-sem');
  const fStatusEngEl = document.getElementById('f-status-eng');
  const fStatusEl = document.getElementById('f-status');
  const fMatEl = document.getElementById('f-mat');
  const fEspEl = document.getElementById('f-esp');
  const msMedPanel = document.getElementById('ms-medida-panel');
  const msMedList = document.getElementById('ms-medida-list');
  const msMedLabel = document.getElementById('ms-medida-label');
  const msMedVazio = document.getElementById('ms-medida-vazio');
  const msMedBusca = document.getElementById('ms-medida-busca');
  const fCatEl = document.getElementById('f-cat');
  const fBuscaEl = document.getElementById('f-busca');

  // --- Funções Auxiliares pt-BR ---
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

  function formatarMassaKpi(kg) {
    if (!kg || kg <= 0) return { val: '—', un: '' };
    const val = Number(kg);
    if (val >= 1000) {
      const tons = val / 1000;
      const dec = tons >= 100 ? 1 : 2;
      return {
        val: tons.toLocaleString('pt-BR', { minimumFractionDigits: dec, maximumFractionDigits: dec }),
        un: 't'
      };
    }
    return {
      val: val.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }),
      un: 'kg'
    };
  }

  function formatarPercentualBr(pct) {
    return Number(pct).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
  }

  function getNomeMes(mKey) {
    const nomes = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    const parts = mKey.split('-');
    const mNum = parseInt(parts[1] || '0', 10);
    return (nomes[mNum - 1] || mKey) + ' / ' + parts[0];
  }

  // 1. Popular filtro Mês
  const mesesKeys = [...new Set(D.rows.map(r => String(r[1]).substring(0, 7)))].sort();
  const hojeKey = new Date().toISOString().substring(0, 7);
  const mesPadrao = mesesKeys.includes(hojeKey) ? hojeKey : (mesesKeys.includes('2026-08') ? '2026-08' : (mesesKeys[0] || null));
  fMes = mesPadrao;

  if (fMesEl) {
    mesesKeys.forEach(mKey => {
      const btn = document.createElement('button');
      btn.className = 'chip-mc';
      btn.textContent = getNomeMes(mKey);
      btn.onclick = () => {
        fMes = (fMes === mKey) ? null : mKey;
        renderizarChipsSemanas();
        atualizarChips();
        popularFiltroMedidas();
        filtrar();
      };
      fMesEl.appendChild(btn);
    });
  }

  // 2. Popular filtro Status Engenharia
  const statusEngList = [
    { id: 'LIBERADO', label: '🟢 Liberado Engenharia' },
    { id: 'AGUARDANDO_ENGENHARIA', label: '🟡 Aguardando Liberação' },
    { id: 'BLOQUEADO', label: '🔴 Bloqueado' }
  ];
  if (fStatusEngEl) {
    statusEngList.forEach(st => {
      const btn = document.createElement('button');
      btn.className = 'chip-mc';
      btn.textContent = st.label;
      btn.onclick = () => {
        fStatusEng = (fStatusEng === st.id) ? null : st.id;
        atualizarChips();
        filtrar();
      };
      fStatusEngEl.appendChild(btn);
    });
  }

  // 3. Popular filtro Status Corte
  const statusList = [
    { id: 0, label: '🟡 A Cortar' },
    { id: 1, label: '🔵 Em Corte' },
    { id: 2, label: '🟢 Cortado' }
  ];
  if (fStatusEl) {
    statusList.forEach(st => {
      const btn = document.createElement('button');
      btn.className = 'chip-mc';
      btn.textContent = st.label;
      btn.onclick = () => {
        fStatus = (fStatus === st.id) ? null : st.id;
        atualizarChips();
        filtrar();
      };
      fStatusEl.appendChild(btn);
    });
  }

  // 4. Semanas adaptativas
  function getSemanasDisponiveis() {
    const rowsMes = fMes ? D.rows.filter(r => String(r[1]).startsWith(fMes)) : D.rows;
    return [...new Set(rowsMes.map(r => r[0]))].sort((a,b)=>a-b);
  }

  function renderizarChipsSemanas() {
    if (!fSemEl) return;
    fSemEl.innerHTML = '';
    const semanasDisponiveis = getSemanasDisponiveis();

    if (modoHorizonte === 'semana') {
      if (fSemana === null || !semanasDisponiveis.includes(fSemana)) {
        fSemana = semanasDisponiveis[0] || null;
      }
    }

    semanasDisponiveis.forEach(s => {
      const btn = document.createElement('button');
      btn.className = 'chip-mc';
      btn.textContent = 'Sem ' + s;
      btn.setAttribute('aria-pressed', (modoHorizonte === 'semana' && s === fSemana) ? 'true' : 'false');
      btn.onclick = () => {
        if (modoHorizonte !== 'semana') {
          modoHorizonte = 'semana';
          const btnSemana = document.getElementById('btn-modo-semana');
          const btnMes = document.getElementById('btn-modo-mes');
          const wrapSem = document.getElementById('wrap-f-sem');
          if (btnSemana) btnSemana.classList.add('active');
          if (btnMes) btnMes.classList.remove('active');
          if (wrapSem) wrapSem.style.display = 'block';
        }
        fSemana = (fSemana === s) ? null : s;
        if (fSemana !== null) {
          const rowDaSemana = D.rows.find(r => r[0] === fSemana);
          if (rowDaSemana) {
            const mesDaSemana = String(rowDaSemana[1]).substring(0, 7);
            if (mesesKeys.includes(mesDaSemana)) fMes = mesDaSemana;
          }
        }
        renderizarChipsSemanas();
        atualizarChips();
        popularFiltroMedidas();
        filtrar();
      };
      fSemEl.appendChild(btn);
    });
  }

  renderizarChipsSemanas();

  // 5. Popular filtro Material
  if (fMatEl && D.mats) {
    D.mats.forEach(m => {
      const btn = document.createElement('button');
      btn.className = 'chip-mc';
      btn.textContent = m;
      btn.onclick = () => {
        fMaterial = (fMaterial === m) ? null : m;
        atualizarChips();
        popularFiltroMedidas();
        filtrar();
      };
      fMatEl.appendChild(btn);
    });
  }

  // 6. Popular filtro Espessuras Reais
  const espessuras = [...new Set(D.rows.map(r => r[5]))].filter(e => e > 0).sort((a,b)=>a-b);
  if (fEspEl) {
    espessuras.forEach(e => {
      const btn = document.createElement('button');
      btn.className = 'chip-mc';
      btn.textContent = formatarEspessuraBr(e);
      btn.onclick = () => {
        fEspessura = (fEspessura === e) ? null : e;
        atualizarChips();
        popularFiltroMedidas();
        filtrar();
      };
      fEspEl.appendChild(btn);
    });
  }

  // 7. Popular filtro Tamanho / Medidas (multi-seleção por checkbox)
  function msMedidaAtualizarLabel() {
    if (!msMedLabel) return;
    if (fMedida.size === 0) msMedLabel.textContent = 'Todas as Medidas de Projeto';
    else if (fMedida.size === 1) {
      const opt = msMedList.querySelector(`input[value="${[...fMedida][0]}"]`);
      msMedLabel.textContent = opt ? opt.parentNode.querySelector('.ms-nome').textContent : '1 selecionada';
    } else msMedLabel.textContent = `${fMedida.size} selecionadas`;
  }

  function popularFiltroMedidas() {
    if (!msMedList) return;

    const subset = D.rows.filter(r => {
      if (fMes !== null && !String(r[1]).startsWith(fMes)) return false;
      if (modoHorizonte === 'semana' && fSemana !== null && r[0] !== fSemana) return false;
      if (fMaquina !== '' && String(r[17] || '') !== fMaquina) return false;
      if (fMaterial !== null && D.mats[r[4]] !== fMaterial) return false;
      if (fEspessura !== null && r[5] !== fEspessura) return false;
      return true;
    });

    const mapaMedidas = {};
    subset.forEach(r => {
      const dimKey = `${r[6]}x${r[7]}`;
      const dimLabel = `${r[6]} × ${r[7]} mm`;
      if (!mapaMedidas[dimKey]) {
        mapaMedidas[dimKey] = {
          key: dimKey,
          label: dimLabel,
          larg: r[6],
          comp: r[7],
          tpds: new Set(),
          pecas: 0
        };
      }
      mapaMedidas[dimKey].tpds.add(r[8]);
      mapaMedidas[dimKey].pecas += r[10];
    });

    const lista = Object.values(mapaMedidas).sort((a, b) => b.pecas - a.pecas);
    const chavesValidas = new Set(lista.map(item => item.key));

    // Medidas marcadas que sumiram da lista (por causa de outro filtro) deixam
    // de contar — igual ao filtro de executores da Gestão de Cards.
    [...fMedida].forEach(k => { if (!chavesValidas.has(k)) fMedida.delete(k); });

    msMedList.innerHTML = '';
    lista.forEach(item => {
      const label = document.createElement('label');
      label.className = 'ms-opt';
      const texto = `${item.label} (${item.tpds.size} ${item.tpds.size === 1 ? 'projeto' : 'projetos'} · ${formatarNumeroBr(item.pecas)} pçs)`;
      label.innerHTML = `<input type="checkbox" value="${item.key}"${fMedida.has(item.key) ? ' checked' : ''}>
        <span class="ms-nome">${texto}</span>`;
      label.querySelector('input').addEventListener('change', (e) => {
        if (e.target.checked) fMedida.add(item.key); else fMedida.delete(item.key);
        msMedidaAtualizarLabel();
        filtrar();
      });
      msMedList.appendChild(label);
    });

    msMedidaFiltrarBusca();
    msMedidaAtualizarLabel();
  }

  // Busca dentro do painel: digita "490" e só sobram as opções cujo texto
  // (medida + contagem) contém isso — não precisa achar tudo de primeira,
  // dá pra ir marcando conforme aparece.
  function msMedidaNormalizar(s) {
    // "×" (sinal de multiplicação do label) e "x" digitado no teclado são
    // caracteres diferentes pro navegador - sem isso, digitar "490 x" nunca
    // bate com "490 × 15 mm".
    return s.toLowerCase().replace(/×/g, 'x');
  }
  function msMedidaFiltrarBusca() {
    if (!msMedList) return;
    const termo = msMedidaNormalizar((msMedBusca && msMedBusca.value || '').trim());
    let algumVisivel = false;
    msMedList.querySelectorAll('.ms-opt').forEach(opt => {
      const bate = !termo || msMedidaNormalizar(opt.textContent).includes(termo);
      opt.classList.toggle('ms-oculto', !bate);
      if (bate) algumVisivel = true;
    });
    if (msMedVazio) msMedVazio.style.display = algumVisivel ? 'none' : 'block';
  }
  if (msMedBusca) msMedBusca.addEventListener('input', msMedidaFiltrarBusca);

  popularFiltroMedidas();

  window.msMedidaToggle = (e) => {
    if (e) e.stopPropagation();
    if (!msMedPanel) return;
    const open = msMedPanel.classList.toggle('open');
    const btn = document.getElementById('ms-medida-toggle');
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  };
  window.msMedidaClear = () => {
    if (!msMedList) return;
    msMedList.querySelectorAll('input[type=checkbox]').forEach(c => { c.checked = false; });
    fMedida.clear();
    msMedidaAtualizarLabel();
    filtrar();
  };
  document.addEventListener('mousedown', (e) => {
    const wrap = document.getElementById('ms-medida');
    if (msMedPanel && wrap && !wrap.contains(e.target)) msMedPanel.classList.remove('open');
  });

  // 8. Categoria e Busca
  if (fCatEl && D.cats) {
    D.cats.forEach(c => {
      const opt = document.createElement('option');
      opt.value = c; opt.textContent = c;
      fCatEl.appendChild(opt);
    });
    fCatEl.onchange = filtrar;
  }

  if (fBuscaEl) {
    fBuscaEl.oninput = filtrar;
  }

  // Botão Limpar Tudo
  const btnLimpar = document.getElementById('limpar');
  if (btnLimpar) {
    btnLimpar.onclick = () => {
      fMes = mesPadrao;
      modoHorizonte = 'semana';
      fMaquina = '';
      const btnSemana = document.getElementById('btn-modo-semana');
      const btnMes = document.getElementById('btn-modo-mes');
      const wrapSem = document.getElementById('wrap-f-sem');
      if (btnSemana) btnSemana.classList.add('active');
      if (btnMes) btnMes.classList.remove('active');
      if (wrapSem) wrapSem.style.display = 'block';

      fMaterial = fEspessura = fStatus = fStatusEng = null;
      msMedidaClear();
      if (fCatEl) fCatEl.value = '';
      if (fBuscaEl) fBuscaEl.value = '';

      atualizarAbasMaquinas();
      renderizarChipsSemanas();
      popularFiltroMedidas();
      atualizarChips();
      filtrar();
    };
  }

  function atualizarChips() {
    if (fMesEl) {
      Array.from(fMesEl.children).forEach((b, i) => b.setAttribute('aria-pressed', mesesKeys[i] === fMes));
    }
    if (fStatusEngEl) {
      Array.from(fStatusEngEl.children).forEach((b, i) => b.setAttribute('aria-pressed', statusEngList[i].id === fStatusEng));
    }
    if (fStatusEl) {
      Array.from(fStatusEl.children).forEach((b, i) => b.setAttribute('aria-pressed', statusList[i].id === fStatus));
    }
    if (fSemEl) {
      Array.from(fSemEl.children).forEach(b => {
        const sNum = parseInt(b.textContent.replace('Sem ', '').trim(), 10);
        b.setAttribute('aria-pressed', (modoHorizonte === 'semana' && sNum === fSemana) ? 'true' : 'false');
      });
    }
    if (fMatEl) {
      Array.from(fMatEl.children).forEach((b, i) => b.setAttribute('aria-pressed', D.mats[i] === fMaterial));
    }
    if (fEspEl) {
      Array.from(fEspEl.children).forEach((b, i) => b.setAttribute('aria-pressed', espessuras[i] === fEspessura));
    }
  }

  // Alternância de Abas na Auditoria Inferior
  document.querySelectorAll('.aba-mc:not(.aba-modal-btn)').forEach(btn => {
    btn.onclick = () => {
      document.querySelectorAll('.aba-mc:not(.aba-modal-btn)').forEach(b => b.setAttribute('aria-pressed', 'false'));
      btn.setAttribute('aria-pressed', 'true');
      const aba = btn.dataset.aba;
      const wrapTpd = document.getElementById('wrap-aba-tpd');
      const wrapPeca = document.getElementById('wrap-aba-peca');
      const wrapOf = document.getElementById('wrap-aba-of');
      if (wrapTpd) wrapTpd.hidden = (aba !== 'tpd');
      if (wrapPeca) wrapPeca.hidden = (aba !== 'peca');
      if (wrapOf) wrapOf.hidden = (aba !== 'of');
    };
  });

  // Alternador de Horizonte de Planejamento (Semana vs Mês)
  window.alternarModoHorizonte = function(modo) {
    modoHorizonte = modo;
    const btnSemana = document.getElementById('btn-modo-semana');
    const btnMes = document.getElementById('btn-modo-mes');
    const wrapSem = document.getElementById('wrap-f-sem');

    if (modo === 'mes') {
      if (btnMes) btnMes.classList.add('active');
      if (btnSemana) btnSemana.classList.remove('active');
      if (wrapSem) wrapSem.style.display = 'none';
      fSemana = null;
    } else {
      if (btnSemana) btnSemana.classList.add('active');
      if (btnMes) btnMes.classList.remove('active');
      if (wrapSem) wrapSem.style.display = 'block';
      const semanasDisponiveis = getSemanasDisponiveis();
      if (fSemana === null || !semanasDisponiveis.includes(fSemana)) {
        fSemana = semanasDisponiveis[0] || null;
      }
      renderizarChipsSemanas();
    }
    atualizarChips();
    popularFiltroMedidas();
    filtrar();
  };

  // Selecionar Aba de Máquina no Topo
  window.selecionarAbaMaquina = function(maqCod) {
    fMaquina = String(maqCod);
    atualizarAbasMaquinas();
    popularFiltroMedidas();
    filtrar();
  };

  function atualizarAbasMaquinas() {
    document.querySelectorAll('.tab-maq-btn').forEach(btn => {
      const dataMaq = btn.dataset.maq || '';
      btn.classList.toggle('active', dataMaq === fMaquina);
    });
  }

  // Atualizar Contadores nas Abas de Máquinas
  function atualizarContadoresMaquinas(rowsBase) {
    const counts = { '': 0, '17001': 0, '17002': 0, '17012': 0, '17003': 0 };
    rowsBase.forEach(r => {
      const mId = String(r[17] || '17001');
      counts[''] += r[10];
      if (counts[mId] !== undefined) {
        counts[mId] += r[10];
      }
    });

    const elTodas = document.getElementById('maq-cnt-todas');
    if (elTodas) elTodas.textContent = `${formatarNumeroBr(counts[''])} pçs no período`;

    const el17001 = document.getElementById('maq-cnt-17001');
    if (el17001) el17001.textContent = `${formatarNumeroBr(counts['17001'])} pçs (Bobinas)`;

    const el17002 = document.getElementById('maq-cnt-17002');
    if (el17002) el17002.textContent = `${formatarNumeroBr(counts['17002'])} pçs (Placas/Tiras)`;

    const el17012 = document.getElementById('maq-cnt-17012');
    if (el17012) el17012.textContent = `${formatarNumeroBr(counts['17012'])} pçs (Chapas Pesadas)`;

    const el17003 = document.getElementById('maq-cnt-17003');
    if (el17003) el17003.textContent = `${formatarNumeroBr(counts['17003'])} pçs (Dobras/Canudos)`;
  }

  // Motor Central de Filtragem
  function filtrar() {
    const cat = fCatEl ? fCatEl.value : '';
    const busca = fBuscaEl ? fBuscaEl.value.toLowerCase().trim() : '';

    // Linhas do período para alimentar os contadores
    const rowsPeriodo = D.rows.filter(r => {
      if (fMes !== null && !String(r[1]).startsWith(fMes)) return false;
      if (modoHorizonte === 'semana' && fSemana !== null && r[0] !== fSemana) return false;
      return true;
    });
    atualizarContadoresMaquinas(rowsPeriodo);

    // Linhas filtradas com todos os critérios
    const filtrados = rowsPeriodo.filter(r => {
      if (fMaquina !== '' && String(r[17] || '') !== fMaquina) return false;
      if (fMaterial !== null && D.mats[r[4]] !== fMaterial) return false;
      if (fEspessura !== null && r[5] !== fEspessura) return false;
      if (fMedida.size > 0) {
        const dimKey = `${r[6]}x${r[7]}`;
        if (!fMedida.has(dimKey)) return false;
      }
      if (fStatus !== null && (r[13] !== undefined ? r[13] : 0) !== fStatus) return false;
      if (fStatusEng !== null && (r[16] || (D.tpd_status_eng ? D.tpd_status_eng[r[8]] : 'LIBERADO')) !== fStatusEng) return false;
      if (cat && D.cats[r[2]] !== cat) return false;
      if (busca) {
        const peca = D.pecas[r[3]] ? D.pecas[r[3]].toLowerCase() : '';
        const tpd = D.tpds[r[8]] ? D.tpds[r[8]].toLowerCase() : '';
        const of = String(r[9]);
        const dim = `${r[6]}x${r[7]}`.toLowerCase();
        const dimFmt = `${r[6]} × ${r[7]}`.toLowerCase();
        const mat = (D.mats[r[4]] || '').toLowerCase();
        if (!peca.includes(busca) && !tpd.includes(busca) && !of.includes(busca) && !dim.includes(busca) && !dimFmt.includes(busca) && !mat.includes(busca)) return false;
      }
      return true;
    });

    renderKpis(filtrados);
    renderGradePrincipalSetup(filtrados);
    renderAuditoriaTables(filtrados);

    ultimoFiltrados = filtrados;
    const btnF29Filtro = document.getElementById('btn-emitir-f29-filtro');
    if (btnF29Filtro) btnF29Filtro.disabled = (filtrados.length === 0);
  }

  // KPIs
  function renderKpis(rows) {
    const totalPecas = rows.reduce((acc, r) => acc + r[10], 0);
    const totalTpds = new Set(rows.map(r => r[8])).size;
    const massaTotal = rows.reduce((acc, r) => acc + (r[11] || 0), 0);
    const ofsSet = new Set(rows.map(r => r[9]));

    const mapTrafosBatch = {};
    rows.forEach(r => {
      const batchKey = r[8] + '_' + r[0];
      if (!mapTrafosBatch[batchKey]) {
        mapTrafosBatch[batchKey] = (r[12] !== undefined) ? r[12] : (D.tpd_trafos ? D.tpd_trafos[r[8]] : 5) || 5;
      }
    });
    const totalTrafos = Object.values(mapTrafosBatch).reduce((acc, val) => acc + val, 0);
    const kpiMassa = formatarMassaKpi(massaTotal);

    const kpisEl = document.getElementById('kpis');
    if (!kpisEl) return;
    kpisEl.innerHTML = `
      <div class="metric-card accent"><div class="metric-label">OFs no Kit CMI</div><div class="metric-value">${formatarNumeroBr(ofsSet.size)}</div></div>
      <div class="metric-card success"><div class="metric-label">Peças a Cortar</div><div class="metric-value">${formatarNumeroBr(totalPecas)}<span class="un">pçs</span></div></div>
      <div class="metric-card" style="border-left-color:var(--color-info)"><div class="metric-label">Transformadores</div><div class="metric-value" style="color:var(--color-info-text)">${formatarNumeroBr(totalTrafos)}<span class="un">un</span></div></div>
      <div class="metric-card accent"><div class="metric-label">Projetos do PCP</div><div class="metric-value">${formatarNumeroBr(totalTpds)}</div></div>
      <div class="metric-card accent"><div class="metric-label">Massa Estimada</div><div class="metric-value">${kpiMassa.val}<span class="un">${kpiMassa.un}</span></div></div>
    `;
  }

  // Tabela Principal: Demanda Agrupada por Máquina, Espessura, Tamanho e Quantidade
  function renderGradePrincipalSetup(rows) {
    const tbody = document.querySelector('#t-mat tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    const map = {};
    let totalPecasGeral = 0;

    rows.forEach(r => {
      const maqId = r[17] || 17001;
      const mat = D.mats[r[4]] || 'MATERIAL';
      const esp = r[5];
      const larg = r[6];
      const comp = r[7];
      const key = `${maqId}||${mat}||${esp}||${larg}x${comp}`;

      const trafosLote = (r[12] !== undefined) ? r[12] : (D.tpd_trafos ? D.tpd_trafos[r[8]] : 5) || 5;
      const pecasPorTrafo = trafosLote > 0 ? Math.round(r[10] / trafosLote) : 1;

      if (!map[key]) {
        map[key] = {
          key,
          maqId,
          mat,
          esp,
          larg,
          comp,
          med: `${larg} × ${comp} mm`,
          pecasPorTrafoTotal: 0,
          pecasDistinctMap: {},
          tpds: new Set(),
          ofs: new Set(),
          batchesMap: {},
          pecas: 0,
          massa: 0
        };
      }
      map[key].tpds.add(r[8]);
      map[key].ofs.add(r[9]);
      map[key].batchesMap[r[8] + '_' + r[0]] = trafosLote;
      map[key].pecas += r[10];
      map[key].massa += (r[11] || 0);
      map[key].pecasDistinctMap[r[3]] = (map[key].pecasDistinctMap[r[3]] || 0) + pecasPorTrafo;
      totalPecasGeral += r[10];
    });

    // Ordenar: por Máquina, depois por Espessura decrescente, depois por Quantidade
    const list = Object.values(map).sort((a,b) => (a.maqId - b.maqId) || (b.esp - a.esp) || (b.pecas - a.pecas));

    list.forEach(item => {
      const pct = totalPecasGeral > 0 ? ((item.pecas / totalPecasGeral) * 100) : 0;
      const badgeMatCls = item.mat === 'PRESSPHAN' ? 'badge-press' : (item.mat === 'DIAMANT' ? 'badge-diam' : 'badge-kraft');
      const maqCfg = maquinasNomes[item.maqId] || { nome: 'Máquina ' + item.maqId, cls: 'badge-maq-17001' };
      const totalTrafosItem = Object.values(item.batchesMap).reduce((acc, v) => acc + v, 0);

      // Soma de fatores unitários por trafo
      const somaFatores = Object.values(item.pecasDistinctMap).reduce((acc, v) => acc + v, 0);

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><span class="badge ${maqCfg.cls}">${maqCfg.nome}</span></td>
        <td><span class="badge ${badgeMatCls}">${item.mat}</span></td>
        <td class="mono" style="font-weight:700;font-size:13px">${formatarEspessuraBr(item.esp)}</td>
        <td class="mono" style="font-weight:700;color:#1a3d2a;font-size:13px">${item.med}</td>
        <td class="num" style="color:#64748b;font-weight:600">${somaFatores} pçs/un</td>
        <td class="num" style="font-weight:600;color:#1e40af">${formatarNumeroBr(item.tpds.size)}</td>
        <td class="num" style="font-weight:700;color:#15803d">${formatarNumeroBr(totalTrafosItem)} un</td>
        <td class="num" style="font-weight:700;color:#1a3d2a;font-size:14px;background:#f0fdf4">${formatarNumeroBr(item.pecas)}</td>
        <td class="num" style="font-weight:600">${formatarMassaBr(item.massa)}</td>
        <td><div style="display:flex;align-items:center;gap:6px"><span>${formatarPercentualBr(pct)}</span><div class="barra-mc" style="flex:1"><i style="width:${pct.toFixed(1)}%"></i></div></div></td>
        <td style="text-align:center">
          <button class="btn-detalhes-agrup" onclick="abrirModalDetalhesEspec('${item.key}')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Detalhes & Programação</button>
        </td>
      `;
      tbody.appendChild(tr);
    });

    const vMat = document.getElementById('v-mat');
    if (vMat) vMat.hidden = (list.length > 0);
  }

  // Auditoria Inferior (Projetos TPDs, Peças e OFs)
  function renderAuditoriaTables(rows) {
    renderTpdTable(rows);
    renderPecaTable(rows);
  }

  function renderTpdTable(rows) {
    const tbody = document.querySelector('#t-tpd tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    const map = {};

    rows.forEach(r => {
      const tpdIdx = r[8];
      const tpd = D.tpds[tpdIdx] || 'Projeto';
      const trafosQtd = (r[12] !== undefined) ? r[12] : (D.tpd_trafos ? D.tpd_trafos[tpdIdx] : 5) || 5;
      const ofsQtdReal = (r[15] !== undefined) ? r[15] : (D.tpd_ofs ? D.tpd_ofs[tpdIdx] : 28) || 28;
      const stCorte = (r[13] !== undefined) ? r[13] : (D.tpd_status ? D.tpd_status[tpdIdx] : 0) || 0;
      const stEng = r[16] || (D.tpd_status_eng ? D.tpd_status_eng[tpdIdx] : 'LIBERADO');

      if (!map[tpd]) {
        map[tpd] = {
          tpd,
          trafosMap: {},
          ofsTotal: ofsQtdReal,
          pecasDistintas: new Set(),
          totalPecas: 0,
          massa: 0,
          semanas: new Set(),
          statusCorte: stCorte,
          statusEng: stEng
        };
      }
      map[tpd].trafosMap[tpdIdx + '_' + r[0]] = trafosQtd;
      map[tpd].pecasDistintas.add(r[3]);
      map[tpd].totalPecas += r[10];
      map[tpd].massa += (r[11] || 0);
      map[tpd].semanas.add(r[0]);
    });

    const list = Object.values(map).sort((a,b) => b.totalPecas - a.totalPecas);
    list.forEach(item => {
      const trafosTotalTpd = Object.values(item.trafosMap).reduce((acc, v) => acc + v, 0);
      let badgeCorte = (item.statusCorte === 2) ? '<span class="badge" style="background:#dcfce7;color:#15803d">🟢 Cortado</span>' : ((item.statusCorte === 1) ? '<span class="badge" style="background:#dbeafe;color:#1e40af">🔵 Em Corte</span>' : '<span class="badge" style="background:#fef3c7;color:#78350f">🟡 A Cortar</span>');
      let badgeEng = (item.statusEng === 'LIBERADO') ? '<span class="badge" style="background:#dcfce7;color:#15803d">🟢 Liberado</span>' : ((item.statusEng === 'BLOQUEADO') ? '<span class="badge" style="background:#fee2e2;color:#b91c1c">🔴 Bloqueado</span>' : '<span class="badge" style="background:#fef3c7;color:#78350f">🟡 Aguardando Liberação</span>');
      const podeEmitir = (item.statusEng === 'LIBERADO');

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td class="mono" style="font-weight:700">${item.tpd}</td>
        <td class="num" style="font-weight:700;color:#1e40af">${trafosTotalTpd} un</td>
        <td class="num" style="font-weight:600">${formatarNumeroBr(item.ofsTotal)}</td>
        <td class="num">${formatarNumeroBr(item.pecasDistintas.size)}</td>
        <td class="num" style="font-weight:700;color:#1a2133">${formatarNumeroBr(item.totalPecas)}</td>
        <td class="num">${formatarMassaBr(item.massa)}</td>
        <td>${[...item.semanas].map(s => '<span class="badge" style="background:#eef1f5;color:#333;margin-right:2px;font-size:10px;padding:1px 4px">S' + s + '</span>').join('')}</td>
        <td style="text-align:center">${badgeEng}</td>
        <td style="text-align:center">${badgeCorte}</td>
        <td style="text-align:center">
          ${podeEmitir ? `<a href="ordem-corte.php?tpd=${item.tpd}" target="_blank" class="btn-ordem">Emitir F-29</a>` : `<button class="btn-ordem disabled">Bloqueado</button>`}
        </td>
      `;
      tbody.appendChild(tr);
    });
  }

  function renderPecaTable(rows) {
    const tbodyPeca = document.querySelector('#t-peca tbody');
    const tbodyOf = document.querySelector('#t-of tbody');
    if (!tbodyPeca || !tbodyOf) return;
    tbodyPeca.innerHTML = ''; tbodyOf.innerHTML = '';

    const mapPeca = {};
    rows.forEach(r => {
      const nomePeca = D.pecas[r[3]] || 'Peça Desconhecida';
      const key = `${r[17]}||${D.mats[r[4]]}||${r[5]}||${r[6]}x${r[7]}||${r[3]}`;
      const trafosLote = (r[12] !== undefined) ? r[12] : (D.tpd_trafos ? D.tpd_trafos[r[8]] : 5) || 5;
      const pecasPorTrafo = trafosLote > 0 ? Math.round(r[10] / trafosLote) : 1;

      if (!mapPeca[key]) {
        mapPeca[key] = {
          key,
          nome: nomePeca,
          cat: D.cats[r[2]] || 'Geral',
          maqId: r[17] || 17001,
          mat: D.mats[r[4]] || 'MATERIAL',
          esp: r[5],
          med: `${r[6]} × ${r[7]} mm`,
          pecasPorTrafo,
          tpds: new Set(),
          ofs: new Set(),
          batchesMap: {},
          pecas: 0,
          massa: 0
        };
      }
      mapPeca[key].tpds.add(r[8]);
      mapPeca[key].ofs.add(r[9]);
      mapPeca[key].batchesMap[r[8] + '_' + r[0]] = trafosLote;
      mapPeca[key].pecas += r[10];
      mapPeca[key].massa += (r[11] || 0);

      // Preenche OF individual
      const stCorte = (r[13] !== undefined) ? r[13] : (D.tpd_status ? D.tpd_status[r[8]] : 0) || 0;
      const stEng = r[16] || (D.tpd_status_eng ? D.tpd_status_eng[r[8]] : 'LIBERADO');
      let badgeCorte = (stCorte === 2) ? '<span class="badge" style="background:#dcfce7;color:#15803d">🟢 Cortado</span>' : ((stCorte === 1) ? '<span class="badge" style="background:#dbeafe;color:#1e40af">🔵 Em Corte</span>' : '<span class="badge" style="background:#fef3c7;color:#78350f">🟡 A Cortar</span>');
      let badgeEng = (stEng === 'LIBERADO') ? '<span class="badge" style="background:#dcfce7;color:#15803d">🟢 Liberado</span>' : '<span class="badge" style="background:#fee2e2;color:#b91c1c">🔴 Bloqueado</span>';
      const maqCfg = maquinasNomes[r[17]] || { nome: 'Máquina', cls: 'badge-maq-17001' };

      const trOf = document.createElement('tr');
      trOf.innerHTML = `
        <td class="mono" style="font-weight:600">${r[9]}</td>
        <td class="mono" style="font-weight:700;color:#1a3d2a">${D.tpds[r[8]] || 'Projeto'}</td>
        <td style="font-weight:600">${nomePeca}</td>
        <td><span class="badge ${maqCfg.cls}">${maqCfg.nome}</span></td>
        <td><span class="badge">${D.mats[r[4]]}</span></td>
        <td class="mono">${formatarEspessuraBr(r[5])}</td>
        <td class="mono">${r[6]} × ${r[7]} mm</td>
        <td class="num" style="font-weight:700">${formatarNumeroBr(r[10])}</td>
        <td>Sem ${r[0]}</td>
        <td style="text-align:center">${badgeEng}</td>
        <td style="text-align:center">${badgeCorte}</td>
        <td style="text-align:center"><a href="ordem-corte.php?tpd=${D.tpds[r[8]]}" target="_blank" class="btn-ordem">Emitir F-29</a></td>
      `;
      tbodyOf.appendChild(trOf);
    });

    Object.values(mapPeca).sort((a,b) => b.pecas - a.pecas).forEach(item => {
      const maqCfg = maquinasNomes[item.maqId] || { nome: 'Máquina', cls: 'badge-maq-17001' };
      const totalTrafosItem = Object.values(item.batchesMap).reduce((acc, v) => acc + v, 0);

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td style="font-weight:600;color:#1a2133">${item.nome}</td>
        <td><span style="font-size:11px;color:#64748b">${item.cat}</span></td>
        <td><span class="badge ${maqCfg.cls}">${maqCfg.nome}</span></td>
        <td><span class="badge">${item.mat}</span></td>
        <td class="mono" style="font-weight:600">${formatarEspessuraBr(item.esp)}</td>
        <td class="mono" style="font-weight:700;color:#1a3d2a">${item.med}</td>
        <td class="num" style="color:#64748b">${item.pecasPorTrafo} pçs/un</td>
        <td class="num" style="color:#1e40af;font-weight:600">${formatarNumeroBr(item.tpds.size)}</td>
        <td class="num" style="color:#15803d;font-weight:700">${formatarNumeroBr(totalTrafosItem)}</td>
        <td class="num" style="font-weight:700;color:#1a2133">${formatarNumeroBr(item.pecas)}</td>
        <td class="num">${formatarMassaBr(item.massa)}</td>
        <td style="text-align:center">
          <button class="btn-detalhes-agrup" onclick="abrirModalDetalhesEspec('${item.key}')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Detalhes</button>
        </td>
      `;
      tbodyPeca.appendChild(tr);
    });
  }

  // Inicializar
  filtrar();
});

// Funções Globais de Controle de Modal
function abrirModal(id) {
  const modal = document.getElementById(id);
  if (modal) modal.style.display = 'flex';
}

function fecharModal(id) {
  const modal = document.getElementById(id);
  if (modal) modal.style.display = 'none';
}

// Emite um F-29 "avulso" com exatamente as linhas que estão passando pelo
// filtro ativo da Mesa de Corte (pode juntar peças de vários TPDs) - ex.:
// "todo o mês da Slitter", ou "Slitter + 0,18mm", etc.
function emitirF29DoFiltro() {
  if (typeof D === 'undefined' || !ultimoFiltrados || ultimoFiltrados.length === 0) return;

  const linhasLiberadas = ultimoFiltrados.filter(r => {
    const stEng = r[16] || (D.tpd_status_eng ? D.tpd_status_eng[r[8]] : 'LIBERADO');
    return stEng === 'LIBERADO';
  });

  if (linhasLiberadas.length === 0) {
    alert('Nenhuma peça liberada pela Engenharia dentro do filtro atual. Nada para emitir.');
    return;
  }
  if (linhasLiberadas.length < ultimoFiltrados.length) {
    const bloqueadas = ultimoFiltrados.length - linhasLiberadas.length;
    if (!confirm(`${bloqueadas} item(ns) do filtro atual ainda não foram liberados pela Engenharia e vão ficar de fora do F-29. Continuar mesmo assim?`)) return;
  }

  const payload = linhasLiberadas.map(r => ({
    tpd: D.tpds[r[8]] || '—',
    of: r[9],
    peca: D.pecas[r[3]] || 'Peça de Papel',
    material: D.mats[r[4]] || 'MATERIAL',
    espessura: r[5],
    largura: r[6],
    comprimento: r[7],
    qtd: r[10],
    massa: r[11] || 0,
    semana: r[0],
    trafos: (r[12] !== undefined) ? r[12] : (D.tpd_trafos ? D.tpd_trafos[r[8]] : null)
  }));

  // Resumo textual dos filtros ativos, pro cabeçalho da Ordem de Corte
  const partesResumo = [];
  if (fMaquina !== '') {
    const maqCfg = maquinasNomes[parseInt(fMaquina, 10)];
    partesResumo.push(maqCfg ? maqCfg.nome : ('Máquina ' + fMaquina));
  }
  if (fMaterial !== null) partesResumo.push(fMaterial);
  if (fEspessura !== null) partesResumo.push(fEspessura + ' mm');
  if (fMedida && fMedida.size > 0) partesResumo.push([...fMedida].join(', ') + ' mm');
  if (modoHorizonte === 'semana' && fSemana !== null) partesResumo.push('Semana ' + fSemana);
  if (fMes !== null) partesResumo.push(fMes);
  const resumo = partesResumo.length > 0 ? partesResumo.join(' · ') : 'Todos os itens do período (sem filtro específico)';

  document.getElementById('input-f29-linhas').value = JSON.stringify(payload);
  document.getElementById('input-f29-resumo').value = resumo;
  document.getElementById('form-f29-filtro').submit();
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    fecharModal('modal-detalhe-agrupamento');
  }
});

function alternarAbaModal(aba) {
  const btnPecas = document.getElementById('btn-aba-modal-pecas');
  const btnProj = document.getElementById('btn-aba-modal-projetos');
  const btnOfs = document.getElementById('btn-aba-modal-ofs');
  const wrapPecas = document.getElementById('wrap-modal-pecas');
  const wrapProj = document.getElementById('wrap-modal-projetos');
  const wrapOfs = document.getElementById('wrap-modal-ofs');

  if (btnPecas) btnPecas.setAttribute('aria-pressed', (aba === 'pecas') ? 'true' : 'false');
  if (btnProj) btnProj.setAttribute('aria-pressed', (aba === 'projetos') ? 'true' : 'false');
  if (btnOfs) btnOfs.setAttribute('aria-pressed', (aba === 'ofs') ? 'true' : 'false');

  if (wrapPecas) wrapPecas.hidden = (aba !== 'pecas');
  if (wrapProj) wrapProj.hidden = (aba !== 'projetos');
  if (wrapOfs) wrapOfs.hidden = (aba !== 'ofs');
}

// Abrir Modal a partir da Grade Principal de Setup
function abrirModalDetalhesEspec(key) {
  if (typeof D === 'undefined') return;
  const parts = key.split('||');
  const maqId = parseInt(parts[0], 10);
  const mat = parts[1];
  const esp = parseFloat(parts[2]);
  const dim = parts[3]; // larg x comp
  const pecaIdx = parts[4] ? parseInt(parts[4], 10) : null;

  const dimParts = dim.split('x');
  const larg = parseFloat(dimParts[0]);
  const comp = parseFloat(dimParts[1]);

  const rowsFiltrados = D.rows.filter(r => {
    if (fMes !== null && !String(r[1]).startsWith(fMes)) return false;
    if (modoHorizonte === 'semana' && fSemana !== null && r[0] !== fSemana) return false;
    const matchBase = (r[17] === maqId) && (D.mats[r[4]] === mat) && (r[5] === esp) && (r[6] === larg) && (r[7] === comp);
    if (pecaIdx !== null) {
      return matchBase && (r[3] === pecaIdx);
    }
    return matchBase;
  });

  const maqCfg = maquinasNomes[maqId] || { nome: 'Máquina ' + maqId };
  const espFmt = Number(esp).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' mm';
  const titulo = `${maqCfg.nome} · ${mat} ${espFmt} · Medida: ${larg} × ${comp} mm`;

  renderModalDetalheGenerico(titulo, rowsFiltrados, maqCfg, mat, esp, larg, comp);
}

function renderModalDetalheGenerico(titulo, rows, maqCfg, mat, esp, larg, comp) {
  modalRowsAtuais = rows;
  modalTituloAtual = titulo;

  const lblNome = document.getElementById('lbl-modal-agrup-nome');
  if (lblNome) lblNome.textContent = titulo;

  const inputBuscaModal = document.getElementById('modal-busca-input');
  if (inputBuscaModal) inputBuscaModal.value = '';

  const totalPecas = rows.reduce((acc, r) => acc + r[10], 0);
  const massaTotal = rows.reduce((acc, r) => acc + (r[11] || 0), 0);
  const ofsSet = new Set(rows.map(r => r[9]));
  const tpdsSet = new Set(rows.map(r => r[8]));
  const semanasSet = new Set(rows.map(r => r[0]));

  const trafosBatchMap = {};
  rows.forEach(r => {
    const bKey = r[8] + '_' + r[0];
    if (!trafosBatchMap[bKey]) {
      trafosBatchMap[bKey] = (r[12] !== undefined) ? r[12] : (D.tpd_trafos ? D.tpd_trafos[r[8]] : 5) || 5;
    }
  });
  const totalTrafos = Object.values(trafosBatchMap).reduce((acc, val) => acc + val, 0);

  let massaFmt = '—';
  if (massaTotal > 0) {
    if (massaTotal >= 1000) {
      massaFmt = (massaTotal / 1000).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' t';
    } else {
      massaFmt = massaTotal.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' kg';
    }
  }

  const resumoEl = document.getElementById('modal-agrup-resumo');
  if (resumoEl) {
    resumoEl.innerHTML = `
      <div><b>Máquina Alocada:</b> <span style="color:#0369a1;font-weight:700">${maqCfg.nome}</span></div>
      <div><b>Material & Espessura:</b> <span style="font-weight:700">${mat} · ${Number(esp).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} mm</span></div>
      <div><b>Medida de Corte:</b> <span style="color:#1a3d2a;font-weight:700">${larg} × ${comp} mm</span></div>
      <div><b>Total de Peças a Cortar:</b> <span style="font-weight:700;color:#166534;font-size:14px">${totalPecas.toLocaleString('pt-BR')} pçs</span></div>
      <div><b>Massa Total:</b> <span style="font-weight:700">${massaFmt}</span></div>
      <div><b>Projetos / Lotes:</b> <span style="color:#1e40af;font-weight:700">${tpdsSet.size} TPDs (${totalTrafos} trafos)</span></div>
      <div><b>Semanas:</b> ${[...semanasSet].sort((a,b)=>a-b).map(s => '<span class="badge" style="background:#e2e8f0;color:#334155;margin-right:2px">Sem ' + s + '</span>').join('')}</div>
    `;
  }

  preencherTabelasModal(rows);
  alternarAbaModal('pecas');
  abrirModal('modal-detalhe-agrupamento');
}

function filtrarModalInterno() {
  const busca = (document.getElementById('modal-busca-input').value || '').toLowerCase().trim();
  if (!busca) {
    preencherTabelasModal(modalRowsAtuais);
    return;
  }

  const rowsFiltrados = modalRowsAtuais.filter(r => {
    const tpd = (D.tpds[r[8]] || '').toLowerCase();
    const of = String(r[9]);
    const peca = (D.pecas[r[3]] || '').toLowerCase();
    const cat = (D.cats[r[2]] || '').toLowerCase();
    return tpd.includes(busca) || of.includes(busca) || peca.includes(busca) || cat.includes(busca);
  });

  preencherTabelasModal(rowsFiltrados);
}

function preencherTabelasModal(rows) {
  // 1. Tabela de Peças de Engenharia Envolvidas
  const tbodyPecas = document.querySelector('#t-modal-pecas tbody');
  if (tbodyPecas) {
    tbodyPecas.innerHTML = '';
    const mapPecas = {};

    rows.forEach(r => {
      const pecaIdx = r[3];
      const nomePeca = D.pecas[pecaIdx] || 'Peça Desconhecida';
      const cat = D.cats[r[2]] || 'Geral';
      const trafosLote = (r[12] !== undefined) ? r[12] : (D.tpd_trafos ? D.tpd_trafos[r[8]] : 5) || 5;
      const pecasPorTrafo = trafosLote > 0 ? Math.round(r[10] / trafosLote) : 1;

      if (!mapPecas[pecaIdx]) {
        mapPecas[pecaIdx] = {
          nome: nomePeca,
          cat,
          maqId: r[17] || 17001,
          mat: D.mats[r[4]] || 'MATERIAL',
          esp: r[5],
          larg: r[6],
          comp: r[7],
          pecasPorTrafo,
          totalPecas: 0,
          massa: 0
        };
      }
      mapPecas[pecaIdx].totalPecas += r[10];
      mapPecas[pecaIdx].massa += (r[11] || 0);
    });

    Object.values(mapPecas).sort((a,b) => b.totalPecas - a.totalPecas).forEach(item => {
      const maqCfg = maquinasNomes[item.maqId] || { nome: 'Máquina', cls: 'badge-maq-17001' };
      const espFmt = Number(item.esp).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' mm';
      let massaFmt = item.massa >= 1000 ? (item.massa / 1000).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' t' : item.massa.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' kg';

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td style="font-weight:700;color:#1a2133">${item.nome}</td>
        <td><span style="font-size:11px;color:#64748b">${item.cat}</span></td>
        <td><span class="badge ${maqCfg.cls}">${maqCfg.nome}</span></td>
        <td><span class="badge">${item.mat}</span></td>
        <td class="mono">${espFmt}</td>
        <td class="mono" style="font-weight:700;color:#1a3d2a">${item.larg} × ${item.comp} mm</td>
        <td class="num" style="color:#64748b">${item.pecasPorTrafo} pçs/un</td>
        <td class="num" style="font-weight:700;color:#166534;font-size:13px">${item.totalPecas.toLocaleString('pt-BR')} pçs</td>
        <td class="num">${massaFmt}</td>
      `;
      tbodyPecas.appendChild(tr);
    });
  }

  // 2. Tabela de Projetos Agrupados
  const tbodyProj = document.querySelector('#t-modal-projetos tbody');
  if (tbodyProj) {
    tbodyProj.innerHTML = '';
    const mapProj = {};

    rows.forEach(r => {
      const tpdIdx = r[8];
      const tpd = D.tpds[tpdIdx] || 'Projeto';
      const trafosLote = (r[12] !== undefined) ? r[12] : (D.tpd_trafos ? D.tpd_trafos[tpdIdx] : 5) || 5;
      const stCorte = (r[13] !== undefined) ? r[13] : (D.tpd_status ? D.tpd_status[tpdIdx] : 0) || 0;
      const stEng = r[16] || (D.tpd_status_eng ? D.tpd_status_eng[tpdIdx] : 'LIBERADO');
      const pecasPorTrafo = trafosLote > 0 ? Math.round(r[10] / trafosLote) : 1;

      if (!mapProj[tpd]) {
        mapProj[tpd] = {
          tpd,
          trafosMap: {},
          pecasPorTrafo,
          ofs: new Set(),
          pecas: 0,
          massa: 0,
          semanas: new Set(),
          statusEng: stEng,
          statusCorte: stCorte
        };
      }
      mapProj[tpd].trafosMap[tpdIdx + '_' + r[0]] = trafosLote;
      mapProj[tpd].ofs.add(r[9]);
      mapProj[tpd].pecas += r[10];
      mapProj[tpd].massa += (r[11] || 0);
      mapProj[tpd].semanas.add(r[0]);
    });

    Object.values(mapProj).sort((a,b) => b.pecas - a.pecas).forEach(item => {
      const trafosTpd = Object.values(item.trafosMap).reduce((acc, v) => acc + v, 0);
      const podeEmitir = (item.statusEng === 'LIBERADO');
      let badgeEng = podeEmitir ? '<span class="badge" style="background:#dcfce7;color:#15803d">🟢 Liberado</span>' : '<span class="badge" style="background:#fee2e2;color:#b91c1c">🔴 Bloqueado</span>';
      let badgeCorte = (item.statusCorte === 2) ? '<span class="badge" style="background:#dcfce7;color:#15803d">🟢 Cortado</span>' : ((item.statusCorte === 1) ? '<span class="badge" style="background:#dbeafe;color:#1e40af">🔵 Em Corte</span>' : '<span class="badge" style="background:#fef3c7;color:#78350f">🟡 A Cortar</span>');
      let massaFmt = item.massa >= 1000 ? (item.massa / 1000).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' t' : item.massa.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' kg';

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td class="mono" style="font-weight:700;color:#1a2133">${item.tpd}</td>
        <td>${[...item.semanas].sort((a,b)=>a-b).map(s => '<span class="badge" style="background:#eef1f5;color:#333;margin-right:2px">Sem ' + s + '</span>').join('')}</td>
        <td class="num" style="font-weight:700;color:#1e40af">${trafosTpd.toLocaleString('pt-BR')} un</td>
        <td class="num" style="font-weight:600;color:#64748b">${item.pecasPorTrafo} pçs/trafo</td>
        <td class="num" style="font-weight:700;color:#1a3d2a">${item.pecas.toLocaleString('pt-BR')} pçs</td>
        <td class="num">${massaFmt}</td>
        <td style="text-align:center">${badgeEng}</td>
        <td style="text-align:center">${badgeCorte}</td>
        <td style="text-align:center">
          ${podeEmitir ? `<a href="ordem-corte.php?tpd=${item.tpd}" target="_blank" class="btn-ordem">Emitir F-29</a>` : `<button class="btn-ordem disabled">Bloqueado</button>`}
        </td>
      `;
      tbodyProj.appendChild(tr);
    });
  }

  // 3. Tabela de OFs Filhas
  const tbodyOfs = document.querySelector('#t-modal-ofs tbody');
  if (tbodyOfs) {
    tbodyOfs.innerHTML = '';
    rows.forEach(r => {
      const nomePeca = D.pecas[r[3]] || 'Peça Desconhecida';
      const stCorte = (r[13] !== undefined) ? r[13] : (D.tpd_status ? D.tpd_status[r[8]] : 0) || 0;
      const stEng = r[16] || (D.tpd_status_eng ? D.tpd_status_eng[r[8]] : 'LIBERADO');
      let badgeCorte = (stCorte === 2) ? '<span class="badge" style="background:#dcfce7;color:#15803d">🟢 Cortado</span>' : ((stCorte === 1) ? '<span class="badge" style="background:#dbeafe;color:#1e40af">🔵 Em Corte</span>' : '<span class="badge" style="background:#fef3c7;color:#78350f">🟡 A Cortar</span>');
      let badgeEng = (stEng === 'LIBERADO') ? '<span class="badge" style="background:#dcfce7;color:#15803d">🟢 Liberado</span>' : '<span class="badge" style="background:#fee2e2;color:#b91c1c">🔴 Bloqueado</span>';
      const espFmt = Number(r[5]).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' mm';

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td class="mono" style="font-weight:700;color:#1e293b">${r[9]}</td>
        <td class="mono" style="font-weight:700;color:#1a3d2a">${D.tpds[r[8]] || 'Projeto'}</td>
        <td style="font-weight:600">${nomePeca}</td>
        <td><span class="badge">${D.mats[r[4]]}</span> <span class="mono">${espFmt}</span></td>
        <td class="mono">${r[6]} × ${r[7]} mm</td>
        <td class="num" style="font-weight:700">${r[10].toLocaleString('pt-BR')}</td>
        <td>Sem ${r[0]}</td>
        <td style="text-align:center">${badgeEng}</td>
        <td style="text-align:center">${badgeCorte}</td>
        <td style="text-align:center">
          <a href="ordem-corte.php?tpd=${D.tpds[r[8]]}" target="_blank" class="btn-ordem">Emitir F-29</a>
        </td>
      `;
      tbodyOfs.appendChild(tr);
    });
  }
}

// Sincronização direta com o VSAT via API PHP
window.sincronizarComVsat = async function() {
  const btn = document.getElementById('btn-sync-vsat');
  if (!btn) return;
  const textoOriginal = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span>⏳ Sincronizando com VSAT...</span>';

  try {
    const res = await fetch('../../api/papel-sincronizar-vsat.php', { method: 'POST' });
    const data = await res.json();
    if (data.sucesso) {
      alert(`[SUCESSO] ${data.mensagem || 'Dados sincronizados com sucesso do ERP VSAT!'}`);
      window.location.reload();
    } else {
      alert(`[AVISO] ${data.mensagem || 'Não foi possível conectar ao SQL Server do VSAT. Exibindo base local sincronizada.'}`);
    }
  } catch (err) {
    alert('Erro de comunicação ao sincronizar: ' + err.message);
  } finally {
    btn.disabled = false;
    btn.innerHTML = textoOriginal;
  }
};
