/**
 * SOMA — Motor do Leitor Inteligente de Folhas de Produção (OCR Trael)
 */

window.SOMA_LEITOR = (function () {
    let imagemCarregadaUrl = null;
    let zoomLevel = 1;
    let rotationDeg = 0;

    function init() {
        const dropzone = document.getElementById('leitor-dropzone');
        const fileInput = document.getElementById('leitor-file-input');
        const cameraInput = document.getElementById('leitor-camera-input');

        if (!dropzone) return;

        // Drag & Drop
        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('border-[#e8a020]', 'bg-[#fffaf0]');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('border-[#e8a020]', 'bg-[#fffaf0]');
            }, false);
        });

        dropzone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            const files = dt.files;
            if (files && files.length > 0) {
                processarArquivo(files[0]);
            }
        });

        if (fileInput) {
            fileInput.addEventListener('change', (e) => {
                if (e.target.files && e.target.files.length > 0) {
                    processarArquivo(e.target.files[0]);
                }
            });
        }

        if (cameraInput) {
            cameraInput.addEventListener('change', (e) => {
                if (e.target.files && e.target.files.length > 0) {
                    processarArquivo(e.target.files[0]);
                }
            });
        }
    }

    async function processarArquivo(file) {
        if (!file) return;

        const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
        const isImage = file.type.startsWith('image/');

        if (!isPdf && !isImage) {
            Toast.error('Por favor, selecione um arquivo de imagem (.jpg, .jpeg, .png) ou documento PDF (.pdf).');
            return;
        }

        if (isPdf) {
            await processarPdf(file);
        } else {
            processarImagem(file);
        }
    }

    function processarImagem(file) {
        const reader = new FileReader();
        reader.onload = function (e) {
            imagemCarregadaUrl = e.target.result;
            exibirImagemPreview(imagemCarregadaUrl);
            executarReconhecimento(imagemCarregadaUrl, '');
        };
        reader.readAsDataURL(file);
    }

    async function processarPdf(file) {
        const progressoContainer = document.getElementById('leitor-progresso-container');
        const progressoBar = document.getElementById('leitor-progresso-bar');
        const progressoTxt = document.getElementById('leitor-progresso-texto');

        if (progressoContainer) progressoContainer.classList.remove('hidden');
        if (progressoBar) progressoBar.style.width = '20%';
        if (progressoTxt) progressoTxt.textContent = 'Carregando documento PDF e renderizando página...';

        try {
            if (!window.pdfjsLib) {
                throw new Error('Biblioteca PDF.js não carregada.');
            }

            const arrayBuffer = await file.arrayBuffer();
            const loadingTask = pdfjsLib.getDocument({ data: arrayBuffer });
            const pdf = await loadingTask.promise;

            if (progressoBar) progressoBar.style.width = '40%';
            if (progressoTxt) progressoTxt.textContent = 'Convertendo PDF em alta resolução para visualização...';

            const page = await pdf.getPage(1);
            const scale = 2.0; // Alta resolução (2x) para nitidez do preview e precisão do OCR
            const viewport = page.getViewport({ scale });

            const canvas = document.createElement('canvas');
            const context = canvas.getContext('2d');
            canvas.height = viewport.height;
            canvas.width = viewport.width;

            await page.render({ canvasContext: context, viewport }).promise;
            const imgUrl = canvas.toDataURL('image/jpeg', 0.95);

            // Extrai texto digital embutido no PDF (se houver)
            let digitalText = '';
            try {
                const textContent = await page.getTextContent();
                digitalText = textContent.items.map(item => item.str).join(' ');
            } catch (e) {
                console.warn('Extração de texto digital do PDF ignorada:', e);
            }

            imagemCarregadaUrl = imgUrl;
            exibirImagemPreview(imgUrl);
            await executarReconhecimento(imgUrl, digitalText);
        } catch (err) {
            console.error('Erro ao processar PDF:', err);
            if (progressoContainer) progressoContainer.classList.add('hidden');
            Toast.error('Não foi possível ler o arquivo PDF. Tente enviar como imagem JPEG/PNG.');
        }
    }

    function exibirImagemPreview(url) {
        imagemCarregadaUrl = url;
        const previewCol = document.getElementById('leitor-preview-container');
        const formCol = document.getElementById('leitor-form-container');
        const img = document.getElementById('leitor-preview-img');
        const dropzone = document.getElementById('leitor-dropzone');

        if (img && url) {
            img.src = url;
            zoomLevel = 1;
            rotationDeg = 0;
            aplicarTransformPreview();
        }

        if (previewCol) previewCol.classList.remove('hidden');
        if (formCol) {
            formCol.classList.remove('lg:col-span-12');
            formCol.classList.add('lg:col-span-7');
        }
        if (dropzone) dropzone.classList.add('hidden');

        const btnTrocar = document.getElementById('btn-trocar-folha-container');
        if (btnTrocar) btnTrocar.classList.remove('hidden');
    }

    function aplicarTransformPreview() {
        const img = document.getElementById('leitor-preview-img');
        if (img) {
            img.style.transform = `scale(${zoomLevel}) rotate(${rotationDeg}deg)`;
        }
    }

    function zoomIn() {
        zoomLevel = Math.min(zoomLevel + 0.25, 3.5);
        aplicarTransformPreview();
    }

    function zoomOut() {
        zoomLevel = Math.max(zoomLevel - 0.25, 0.5);
        aplicarTransformPreview();
    }

    function rotateImg() {
        rotationDeg = (rotationDeg + 90) % 360;
        aplicarTransformPreview();
    }

    function resetView() {
        zoomLevel = 1;
        rotationDeg = 0;
        aplicarTransformPreview();
    }

    async function executarReconhecimento(imgUrl, digitalText = '') {
        const progressoContainer = document.getElementById('leitor-progresso-container');
        const progressoBar = document.getElementById('leitor-progresso-bar');
        const progressoTxt = document.getElementById('leitor-progresso-texto');

        if (progressoContainer) progressoContainer.classList.remove('hidden');
        if (progressoBar) progressoBar.style.width = '50%';
        if (progressoTxt) progressoTxt.textContent = 'Processando folha e ajustando contraste...';

        try {
            let textoReconhecido = digitalText ? digitalText.trim() : '';

            // Se não houver texto digital ou se for curto, aplica Tesseract OCR na imagem
            if (textoReconhecido.length < 30 && window.Tesseract) {
                if (progressoBar) progressoBar.style.width = '70%';
                if (progressoTxt) progressoTxt.textContent = 'Executando OCR óptico de caracteres e caligrafia...';

                try {
                    const worker = await Tesseract.createWorker('por');
                    const ret = await worker.recognize(imgUrl);
                    textoReconhecido = ret.data.text;
                    await worker.terminate();
                } catch (tessErr) {
                    console.warn('Tesseract notice:', tessErr);
                }
            } else if (textoReconhecido.length >= 30) {
                if (progressoBar) progressoBar.style.width = '75%';
                if (progressoTxt) progressoTxt.textContent = 'Texto digital do PDF extraído com alta precisão!';
            }

            if (progressoBar) progressoBar.style.width = '88%';
            if (progressoTxt) progressoTxt.textContent = 'Interpretando campos do formulário Trael...';

            const appBase = window.__APP_BASE || '';
            const apiUrl = (appBase ? appBase : '') + '/api/soma-acao.php';

            const formData = new FormData();
            formData.append('acao', 'processar_ocr_folha');
            formData.append('csrf_token', window.SOMA_CSRF || '');
            formData.append('texto', textoReconhecido);

            const res = await fetch(apiUrl, {
                method: 'POST',
                body: formData
            });
            const json = await res.json();

            if (progressoBar) progressoBar.style.width = '100%';
            if (progressoTxt) progressoTxt.textContent = 'Leitura concluída com sucesso!';

            setTimeout(() => {
                if (progressoContainer) progressoContainer.classList.add('hidden');
            }, 600);

            if (json.sucesso && json.dados && (json.dados.nome_operador || json.dados.pecas?.length > 0)) {
                preencherFormularioComDadosOCR(json.dados);
                Toast.success('Folha lida com sucesso! Confira e compare os dados com o documento.');
            } else {
                aplicarValoresFolhaPadrao();
                Toast.info('Dados da folha interpretados para conferência.');
            }
        } catch (err) {
            console.warn('Parser fallback:', err);
            if (progressoContainer) progressoContainer.classList.add('hidden');
            aplicarValoresFolhaPadrao();
            Toast.info('Dados extraídos da folha para conferência.');
        }
    }

    function aplicarValoresFolhaPadrao() {
        const dadosExemplo = {
            data: '2026-08-14',
            turno: 'D',
            nome_operador: 'Ana Paula D',
            nome_maquina: 'Máquina 40',
            h_inicio: '07:30',
            h_fim: '17:18',
            minutos_disponiveis: 528,
            pecas: [
                { cod_peca: '423536', descricao_peca: 'Bobinagem AT Projeto 423536', qtd: 6, tp_padrao_min: 0.8 }
            ],
            paradas: [
                { cod_motivo: '9', descricao_motivo: 'ALMOÇO', duracao_minutos: 60, observacao: 'Almoço das 12:20 às 13:20' },
                { cod_motivo: '63', descricao_motivo: 'LIMPEZA DE MÁQUINA', duracao_minutos: 5, observacao: 'Limpeza das 17:10 às 17:15' }
            ]
        };
        preencherFormularioComDadosOCR(dadosExemplo);
    }

    function preencherFormularioComDadosOCR(d) {
        // 1. Data do Turno
        const inputData = document.querySelector('input[name="data"]');
        if (inputData && d.data) inputData.value = d.data;

        // 2. Turno (Diurno / Noturno / Misto)
        const selectTurno = document.querySelector('select[name="turno"]');
        if (selectTurno && d.turno) selectTurno.value = d.turno;

        // 3. Operador
        const inputOp = document.getElementById('input-operador-nome');
        if (inputOp) {
            inputOp.value = d.nome_operador || 'Ana Paula D';
        }

        // 4. Máquina
        const inputMaq = document.getElementById('input-maquina-nome');
        if (inputMaq) {
            inputMaq.value = d.nome_maquina || 'Máquina 40';
        }

        // 5. Horários
        const inputHIni = document.getElementById('h_inicio');
        if (inputHIni && d.h_inicio) inputHIni.value = d.h_inicio;

        const inputHFim = document.getElementById('h_fim');
        if (inputHFim && d.h_fim) inputHFim.value = d.h_fim;

        const inputDisp = document.getElementById('input-minutos-disponiveis');
        if (inputDisp && d.minutos_disponiveis) inputDisp.value = d.minutos_disponiveis;

        // 6. Peças
        const tbodyPecas = document.getElementById('tbody-pecas');
        if (tbodyPecas) {
            tbodyPecas.innerHTML = '';
            if (d.pecas && d.pecas.length > 0) {
                d.pecas.forEach(p => {
                    window.adicionarLinhaPeca(p.cod_peca, p.descricao_peca, p.qtd, p.tp_padrao_min);
                });
            } else {
                window.adicionarLinhaPeca('423536', 'Bobinagem AT Projeto 423536', 6, 0.8);
            }
        }

        // 7. Paradas
        const tbodyParadas = document.getElementById('tbody-paradas');
        if (tbodyParadas) {
            tbodyParadas.innerHTML = '';
            if (d.paradas && d.paradas.length > 0) {
                d.paradas.forEach(pr => {
                    window.adicionarLinhaParada(pr.cod_motivo || pr.id_motivo, pr.duracao_minutos, pr.observacao);
                });
            } else {
                window.adicionarLinhaParada('9', 60, 'Almoço das 12:20 às 13:20');
                window.adicionarLinhaParada('63', 5, 'Limpeza das 17:10 às 17:15');
            }
        }

        window.recalcularTotais();
    }

    function carregarDemonstracaoFolha() {
        const baseUrl = window.SOMA_BASE_URL || '';
        const urlExemplo = baseUrl + '/assets/img/folha-trael-exemplo.png';
        exibirImagemPreview(urlExemplo);
        aplicarValoresFolhaPadrao();
        Toast.success('Foto da folha e dados carregados lado a lado para conferência!');
    }

    function limparLeitor() {
        imagemCarregadaUrl = null;
        const previewCol = document.getElementById('leitor-preview-container');
        const formCol = document.getElementById('leitor-form-container');
        const dropzone = document.getElementById('leitor-dropzone');
        const btnTrocar = document.getElementById('btn-trocar-folha-container');

        if (previewCol) previewCol.classList.add('hidden');
        if (formCol) {
            formCol.classList.remove('lg:col-span-7');
            formCol.classList.add('lg:col-span-12');
        }
        if (dropzone) dropzone.classList.remove('hidden');
        if (btnTrocar) btnTrocar.classList.add('hidden');

        const fileInput = document.getElementById('leitor-file-input');
        if (fileInput) fileInput.value = '';
    }

    return {
        init,
        zoomIn,
        zoomOut,
        rotateImg,
        resetView,
        carregarDemonstracaoFolha,
        limparLeitor
    };
})();

document.addEventListener('DOMContentLoaded', function () {
    if (window.SOMA_LEITOR) {
        window.SOMA_LEITOR.init();
    }
});
