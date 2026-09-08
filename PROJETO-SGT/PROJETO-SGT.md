# PROJETO SGT — Sistema de Gestão Trael (com PCP & SOMA Integrados)

> **Documento vivo e oficial de referência arquitetural, regras de negócio, engenharia de software e especificações técnicas.**  
> Qualquer decisão de arquitetura, fluxo, banco de dados ou integração fabril deve ser consultada e mantida estritamente alinhada com este documento.

---

## 1. Identidade do Produto & Contexto Operacional

O **SGT (Sistema de Gestão Trael)** é a plataforma integrada de inteligência industrial, planejamento, controle de produção e chão de fábrica da **Trael Transformadores Elétricos**. O sistema unifica a gestão de retrabalhos, rastreabilidade de ordens de fabricação (OFs e sub-OFs), controle de qualidade (IQF e Laboratório de Alta Tensão), visão computacional com OCR industrial (Paint Check), e incorpora formalmente todos os módulos de inteligência e painéis do **PCP (Planejamento e Controle da Produção)** e **SOMA (Cronoanálise Industrial & Tempos Padrão)** sob a aba e módulo de **Produção**.

### Usuários Principais
- **Supervisores de Produção & Líderes de Linha:** Acompanhamento de esteiras em tempo real, apontamentos operacionais, leitura de QR Codes por câmera/terminal e monitoramento de paradas de máquina.
- **Planejadores do PCP:** Monitoramento contínuo de metas diárias e mensais (Meta × Realizado), análise de atrasos do Plano Mestre, priorização de pedidos (FIFO com herança) e rastreamento da árvore de sub-montagens.
- **Inspetores de Qualidade & Laboratório de Ensaios (`LAB` / `IQF`):** Ensaios elétricos de rotina e tipo, inspeção dimensional e mecânica, emissão de reprovas, controle de reensaios e liberação para expedição.
- **Liderança Industrial & Diretoria:** Dashboards executivos de indicadores de produção por linha (Distribuição — TPD, Média Força — TPM, Transformadores a Seco — TPS), medidores de eficiência (*gauges*), gargalos por célula e balanço de produtividade.
- **Operadores de Máquina & Digitadores:** Terminais simplificados para apontamento de tempos de ciclo, paradas de linha e alimentação de ordens de fabricação.

### Princípios Fundamentais do Produto
1. **Visibilidade Imediata:** Informações críticas, desvios de meta e gargalos produtivos saltam aos olhos com contrastes calibrados e sem navegação profunda.
2. **Zero Fricção Operacional:** Topos travados (*sticky headers*), filtros instantâneos no padrão planilha, paginação ágil e rolagem única controlada no container principal.
3. **Fidelidade Real do Chão de Fábrica:** As regras do sistema espelham fielmente a física dos processos de manufatura, a lógica de montagem dos transformadores, os turnos reais e os dados do ERP.
4. **Precisão & Integridade:** Dados 100% auditados, rastreabilidade ponta a ponta sem duplicidades e integridade referencial com soft delete em todas as operações.

---

## 2. Stack Tecnológica & Padrões Arquiteturais

### Back-end
- **PHP 8.4 Vanilla:** Arquitetura limpa sem frameworks externos (sem Laravel, Symfony, etc.); uso obrigatório da declaração estrita `declare(strict_types=1)` em todos os arquivos PHP.
- **Conexão Dual de Banco de Dados:**
  - **MySQL (Aplicação Principal):** Conexão singleton gerenciada exclusivamente por `getDB()` em `config/conexao.php` (autenticação, sessões, retrabalho, metas mensais, catálogo e cronoanálise).
  - **SQL Server (ERP Trael / Kardex / piAudit):** Conexão singleton segura `getSqlServerDB(): ?PDO` via PDO ODBC (`vsat.trael.local` / `vsattrael`) em por que não atualizou?`config/conexao.php`, com timeout curto e tolerância a falhas na rede interna da fábrica.
- **Planilhas-Ponte & Leitura Server-Side:**
  - Leitura no servidor via `PharData` (sem dependência de extensões pesadas como ZipArchive ou Composer), cacheada em `storage/cache/`.
  - `PLANILHA QUE ATUALIZA/NS.OF.xlsx`: Índice de Ordens de Fabricação (~4,8 MB, atualizado por Power Query, lido por `includes/planilha-ns-of.php`).
  - `PLANILHA Q ATUALIZA/Relação Kardex.xlsx`: Histórico de movimentações de produção (~85 MB, 38 colunas, abas `dw vw_kardex_lotes` e `dw vw_ficha_espc_trafo`, lido por `includes/boletim-planilha.php`).
  - `PLANILHA QUE ATUALIZA/Item.csv`: Catálogo oficial de materiais (~23,7 mil itens, importado para `itens_catalogo` por `_inicial/importar-itens-catalogo.php`).

### Front-end
- **Tailwind CSS (CDN Play):** Estilização utilitária combinada com o design system central em `assets/css/main.css`.
- **CSS Variables & Design Tokens Trael:**
  ```css
  --color-sidebar:        #1a3d2a;  --color-sidebar-hover:  #2d6e45;
  --color-sidebar-active: #3a8a58;  --color-sidebar-text:   rgba(255,255,255,.85);
  --color-accent:         #e8a020;  --color-accent-hover:   #d4911a;
  --color-accent-light:   #fef3dc;  --color-accent-text:    #7a4f08;
  --color-bg:             #f4f5f7;  --color-surface:        #ffffff;
  --color-surface-2:      #f8f9fb;  --color-border:         #e2e6ed;
  --color-text-primary:   #1a2133;  --color-text-secondary: #5a6480;
  --color-text-muted:     #9aa3b8;
  --color-success: #16a34a / bg #dcfce7   --color-warning: #d97706 / bg #fef3c7
  --color-danger:  #dc2626 / bg #fee2e2   --color-info:    #2563eb / bg #dbeafe
  ```
- **JavaScript Vanilla:** Scripts dedicados por módulo em `assets/js/` sem React, Vue ou Alpine.
- **Bibliotecas CDN Homologadas:**
  - **Chart.js + ChartDataLabels:** Gráficos diários, séries empilhadas, histogramas, metas lineares e medidores de eficiência (*gauges*).
  - **jsQR:** Decodificação em tempo real de QR Codes via stream de vídeo da câmera e upload de imagens no chão de fábrica.

### Padrão de Layout & Navegação
- Páginas autenticadas utilizam o encapsulamento `layoutHeader($pageTitle)` e `layoutFooter()` de `includes/layout.php`.
- Container principal com rolagem única (`<main class="main-content">`), header fixo com perfil/avatar e sidebar dinâmica que se ajusta automaticamente com base nas permissões RBAC do usuário.
- **Base de URL Dinâmica:** Chamadas fetch e redirecionamentos no front-end utilizam `window.__APP_BASE` para garantir funcionamento transparente entre ambiente local e deploy no Railway.

---

## 3. Regras de Ouro & Comportamento de Engenharia

1. **Execução Aprofundada & Sem Pressa (Zero-Rush):** Não abreviar código, não pular código boilerplate e nunca usar placeholders como `// TODO` ou `// resto do código aqui`. Arquivos entregues devem ser completos e prontos para produção.
2. **Escopo Estrito:** Fazer estritamente o que foi solicitado na tarefa. Não refatorar, renomear ou reorganizar código que não tenha sido expressamente pedido.
3. **Preservação de UI/UX:** Ao editar interfaces e scripts, preservar rigorosamente labels de botões, tags, elementos visuais e comportamentos do Design System Trael.
4. **Testes Ponta a Ponta Obrigatórios:** Nenhuma alteração é considerada concluída sem verificação de sintaxe (`php -l`), validação de queries e testes de execução.

---

## 4. Regras Críticas de Chão de Fábrica & PCP

### 4.1. Turno de Produção Industrial (07:30 às 02:48)
- A jornada diária de produção da fábrica tem início às **07:30** da manhã e se estende até as **02:48** da madrugada do dia posterior.
- Apontamentos realizados entre **00:00:00 e 07:29:59** pertencem juridicamente e operacionalmente à data do **turno anterior**.
- Na leitura direta do ERP (`SQL Server`), esses registros são cruzados com a tabela de auditoria `dbo.piAudit` (`OIDTable = 29708`) e recuados para a data do turno anterior através do deslocamento temporal:
  ```sql
  DATEADD(minute, -450, COALESCE(aud.DataHoraAudit, k.dt_Movimento))
  ```

### 4.2. Agregação de Produção de Fins de Semana na Sexta-Feira
- Transformadores apontados no **sábado** ou no **domingo** são automaticamente somados e agregados na **sexta-feira imediatamente anterior** através da função `boletimAjustarDataFimDeSemanaParaSexta()`. Isso reflete fielmente o fechamento da programação semanal.

### 4.3. Classificação de Núcleos na Linha de Distribuição (`boletimClassificarNucleoTrafo()`)
Na linha de Distribuição (TPD), cada transformador é classificado estritamente em uma das 3 famílias de núcleo:
- **`JC-TRIF`:** Exclusivamente transformadores com núcleo `JC` (`ds_TpEnrolamentoNucleo = 'JC'`) e que sejam **Trifásicos** (`nrofasesTrafo = 'TRI'` ou descrição do produto contendo `3F`).
- **`ENR`:** Inclui núcleos `ENR` e **todos os transformadores `JC` Monofásicos (`1F`/`MON`) ou Bifásicos (`2F`/`BIF`)**.
- **`EMP`:** Núcleos `EMP` e `EMP-LM` (Convencional / Empilhado de lâminas).

### 4.4. Segregação de Reprovas do Laboratório (`LAB`)
- Reprovas emitidas no Laboratório (`LAB`) são monitoradas em seus próprios KPIs de não-conformidade e retrabalho. Elas **nunca** são somadas à contagem de transformadores aprovados nos gráficos de Mix nem no Executado Total dos dashboards de produção.

### 4.5. Regra de Alternância da Linha TPD por Planta (`cdEnt`)
- A coluna `cdEnt` do Kardex define a unidade fabril onde o transformador foi produzido:
  - `cdEnt = 1`: Planta de **Distribuição**.
  - `cdEnt = 4`: Planta de **Média Força**.
- Linhas TPM e TPS são **sempre** alocadas em Média Força (`area='forca'`), independente de `cdEnt`.
- A produção de TPD realizada na planta 4 (`cdEnt=4`) entra no dashboard de Média Força como uma série dedicada e **nunca é somada com TPM no mesmo indicador** (segmentação rígida de famílias).

### 4.6. Exclusão de Itens RNS
- Itens cujo código de referência inicia com `RNS-` ("Reator de Núcleo Saturado") não são transformadores de potência/distribuição e são ignorados pelos dashboards de medição.

### 4.7. Priorização FIFO e Herança de Sequência em 3 Níveis
- A prioridade da fila operacional de fabricação e retrabalho segue o princípio **FIFO** (Primeiro a Entrar, Primeiro a Sair) com herança estrita em 3 níveis hierárquicos:
  $$\text{N° de Série} > \text{Projeto} > \text{Pedido}$$
- O nível mais específico cadastrado sempre sobrepõe os níveis mais genéricos. Níveis: **Emergente (1)**, **Urgente (2)**, **Importante (3)** e **Neutro (4)**.

---

## 5. Módulos de Produção & PCP (Especificação Detalhada)

O módulo de **Produção** no SGT centraliza a inteligência do PCP, dashboards gerenciais, controle de fluxo e os apontamentos de chão de fábrica.

```
pages/
├── producao/
│   ├── index.php                ← Registro de Entrada / Apontamento (Card LAB, Leitor QR, Câmera)
│   ├── lista.php                ← Lista de Registros Operacionais & Ação Reprovar
│   ├── retornos.php             ← Fila de Retornos de Retrabalho (Pós-Correção)
│   ├── distribuicao.php         ← Dashboard Indicador Distribuição (TPD) [Proxy]
│   ├── atraso-distribuicao.php   ← Dashboard de Atrasos do Plano Mestre [Proxy]
│   ├── forca-seco.php           ← Dashboard Indicador Média Força / Seco (TPM / TPS) [Proxy]
│   ├── painel-setor.php         ← Painel por Setor / Célula Fabril [Proxy]
│   ├── fluxo-pedidos.php        ← Rastreabilidade & Árvore de Sub-OFs por Célula [Proxy]
│   ├── acompanhamento.php       ← Acompanhamento Tanque/Parte Ativa → Montagem Final [Proxy]
│   └── settings.php             ← Configurações de Metas Mensais & Calendário
├── distribuicao/
│   └── index.php                ← Dashboard Principal de Distribuição (TPD)
├── atraso-distribuicao/
│   └── index.php                ← Análise de Atraso e Aging do Plano Mestre
├── forca-seco/
│   └── index.php                ← Dashboard de Média Força e Transformadores a Seco
├── painel-setor/
│   └── index.php                ← Visão Operacional por Célula / Histograma
├── fluxo-pedidos/
│   ├── index.php                ← Matriz de Rastreabilidade em 10 Células
│   └── setor.php                ← Visão Detalhada por Célula
├── acompanhamento/
│   └── index.php                ← Convergência Tanque/Parte Ativa → Montagem Final
```

### 5.1. Dashboard Indicador Distribuição (TPD) (`pages/distribuicao/index.php`)
- **Painel Superior de Indicadores (KPIs):** Meta do Mês, Realizado Acumulado, Saldo Restante, % Atingido, Média Diária Realizada e Ritmo Diário Necessário para bater a meta.
- **Gráficos Interativos (Chart.js):**
  - **Produção Diária por Núcleo:** Gráfico de barras empilhadas (`ENR`, `JC-TRIF`, `EMP`) com linha de Meta Diária sobreposta.
  - **Mix de Produção:** Comparativo Donut/Pizza entre o Mix Programado e o Mix Realizado.
  - **Produção Acumulada:** Curva de área da produção realizada contra a curva linear da meta mensal.
- **Painel "Produção por Linha":** Tabela analítica por núcleo com 4 linhas de métricas (`Executado`, `Meta`, `Diferença`, `% Executado`) e colunas de `MÉDIA` e `SOMA`.
- **Exportação & Impressão A4 Paisagem:** Botão "Imprimir Produção por Linha" (`imprimirProducaoPorLinha()`) com CSS específico para layout Landscape sem quebra de página.
- **Modal de Métricas & Calendário Interativo (`#modal-metricas`):** Definição de metas diárias por núcleo e calendário interativo para marcar dias úteis, pontes e feriados, recalculando a meta mensal automaticamente na tabela `boletim_config_metas`.

### 5.2. Dashboard Atraso Distribuição (`pages/atraso-distribuicao/index.php`)
- Cruzamento diário entre o snapshot das peças previstas no Plano Mestre e os apontamentos de conclusão no Laboratório (Kardex / SQL Server).
- Indicadores de atraso segmentados por família de núcleo (Monofásico, Convencional, JC-TRIF em quantidade de peças e dias médios de atraso).
- Cálculo da **Média Geral Simples** de atraso da fábrica e gráfico de evolução temporal diária.
- Tabela analítica com busca instantânea, ordenação por colunas e identificação visual de criticidade.

### 5.3. Dashboard Indicador Média Força / Seco (`pages/forca-seco/index.php`)
- Acompanhamento das linhas **TPM (Média Força)** e **TPS (Transformadores a Seco)**.
- Exibição de Meta Mensal, Realizado Acumulado, Saldo a Produzir e % de Eficiência.
- Gráfico de barras diárias com linha de meta sobreposta e série condicional de TPD produzido na linha de força (`cdEnt=4`).

### 5.4. Painel por Setor (`pages/painel-setor/index.php`)
- Visão por célula produtiva (Pintura, Montagem Elétrica, Montagem Final, Bobinagem, Laboratório ou Consolidado Geral).
- **Gauge de Eficiência (%):** Mostrador velocímetro de eficiência da célula em tempo real.
- **Métricas Operacionais:** Meta do Dia, Produção Total, Saldo Restante e Fila Acumulada em Espera.
- **Histograma Diário:** Gráfico com linha de meta dinâmica baseada na capacidade instalada da célula.

### 5.5. Acompanhamento Tanque / Parte Ativa → Montagem Final (`pages/acompanhamento/index.php`)
- Rastreamento da convergência das 2 sub-montagens cruciais que alimentam a Montagem Final (restrito à **Empresa 1**):
  - **Pintura (`MTQ`):** Status de conclusão do Tanque.
  - **Montagem Elétrica (`ME-` / `PA-`):** Status de conclusão da Parte Ativa.
  - **Montagem Final (`MFL`):** Montagem Final do equipamento.
- **Matriz de Status e Ação Operacional:**
  - `Pintura OK + Montagem Elétrica OK (Montagem Final Pendente)` ➔ **"DESCER PARA MONTAGEM FINAL"** (Badge Verde).
  - `Montagem Elétrica OK + Pintura Pendente` ➔ **"PINTAR TANQUE"** (Badge Âmbar).
  - `Pintura OK + Montagem Elétrica Pendente` ➔ **"GUARDAR NA ESTUFA"** (Badge Azul).
  - `Montagem Final OK com Pintura ou Montagem Elétrica Pendentes` ➔ **"VERIFICAR APONTAMENTO"** (Badge Vermelho — Alerta de Inconsistência).
  - *Peças com ciclo completo ou sem nenhuma etapa iniciada são ocultadas automaticamente da fila.*

### 5.6. Rastreabilidade & Fluxo de Pedidos (`pages/fluxo-pedidos/index.php`)
- Decomposição hierárquica recursiva de até **5 níveis de sub-OFs** no SQL Server (`dbo.RlcProgramacao`).
- Visão matricial em 10 células fabris: Chassi (`CH`), Baixa Tensão (`BT`), Alta Tensão (`AT`), Corte CNC (`CNC`), Solda/Tanque (`SOL`), Montagem Núcleo (`MN`), Pintura (`PIN`), Montagem Elétrica (`ME`), Montagem Final (`MF`), Laboratório (`LAB`).
- Filtros avançados por período, semana, mês, número do pedido, código do projeto e número de série com drill-down instantâneo para a lista de peças.

### 5.7. Apontamento de Chão de Fábrica & Registro (`pages/producao/index.php`)
- Card interativo da estação (LAB/IQF/GER) com exibição do transformador em processo e cronômetro decorrido em tempo real atualizado a cada segundo via JavaScript.
- Leitor de QR Code modal em tela cheia com alternância dinâmica para upload de imagem e digitação manual de número de série.
- Resolução e validação instantânea de projeto/pedido contra o índice de Ordens de Fabricação (`NS.OF.xlsx` / `includes/planilha-ns-of.php`).
- Prevenção de concorrência com trava de banco para evitar dois apontamentos em andamento para o mesmo equipamento.

### 5.8. Lista de Registros & Retornos (`pages/producao/lista.php` e `retornos.php`)
- Tabela geral de passagens pelas estações com abas de filtro por local (Todas, LAB, IQF, GER), busca textual e filtro mensal.
- **Ação Reprovar (Ponte Produção ➔ Retrabalho):** Botão que abre modal com Pedido/Projeto/N° de série travados e blocos repetíveis de reprova (catálogo `reprovas`). Ao confirmar, cria as entradas em `retrabalhos` e finaliza a etapa atual.
- **Fila de Retornos:** Gestão de transformadores que passaram por retrabalho e retornaram para reensaio no Laboratório ou Inspeção Final.

---

## 6. Módulo Cronoanálise & SOMA (Sistema Operacional de Manutenção e Apontamento)

O subsistema **SOMA** gerencia a cronoanálise de tempos padrão, medição de peça/hora, eficiência de operadores e apontamento de paradas de linha.

```
pages/soma/
├── index.php                ← Hub & Dashboard Geral do SOMA
├── digitador.php            ← Apontamento Rápido em Lote (Digitador do PCP)
├── operador.php             ← Terminal de Chão de Fábrica do Operador
├── paradas.php              ← Apontamento e Gestão de Paradas de Máquina
├── registros.php            ← Relação Geral de Tempos & Ciclos Medidos
├── relatorios.php           ← Relatórios de Produtividade & Peça/Hora
├── auditoria.php            ← Auditoria de Apontamentos & Inconsistências
└── settings.php             ← Configurações de Tempos Padrão & Cadastros
```

### Funcionalidades do SOMA:
- **Terminal do Operador (`operador.php`):** Interface otimizada para tablets industriais com botões largos de Iniciar Ciclo, Finalizar Ciclo e Registrar Parada (`assets/js/soma-leitor.js`).
- **Apontamento pelo Digitador (`digitador.php`):** Digitação acelerada por teclado de múltiplos apontamentos com cálculo automático de tempos decorridos.
- **Gestão de Paradas (`paradas.php`):** Cadastro de motivos de parada (Falta de Material, Manutenção Mecânica/Elétrica, Ajuste de Processo, Troca de Ferramental) com impacto direto no OEE da célula.
- **Classificação Industrial de Produtividade:**
  - `[DENTRO DO PADRÃO]` — Badge Verde (tempo executado $\le$ tempo padrão).
  - `[DESVIO MODERADO]` — Badge Âmbar (tempo executado até +20% do padrão).
  - `[GARGALO CRÍTICO]` — Badge Vermelho (tempo executado $>20\%$ acima do padrão).

---

## 7. Módulos Complementares do SGT

### 7.1. Módulo de Retrabalho (`pages/retrabalho/`)
- **Ciclo de Vida Automático dos Lançamentos:**
  $$\text{Aguardando Abertura} \longrightarrow \text{Aguardando Causa da Reprova} \longrightarrow \text{Finalizado}$$
  *(Status derivado diretamente da presença de data de término e causa raiz, sem seleção manual).*
- **Dashboard de Reprovas (`dashboard.php`):** Painel gerencial em tempo real com gráfico interativo de Principais Motivos de Reprova, contagem consolidada por peça física única e gráfico de status diário.
- **Painel Interativo de Retrabalho (`index.php`):** Mapa visual das esteiras fabris com filtros instantâneos (🚨 Urgentes, 🔄 Em Retorno, ⏳ Aguardando Triagem) e iluminação dinâmica dos postos de trabalho.
- **Relação de Retrabalhos (`relacao.php`):** Tabela mestre agrupada por número de série com múltiplas reprovas empilhadas, flags de urgência por dias úteis parados (`diasUteisEntre()`), contador de reincidências e herança de prioridade FIFO.
- **Triagem & Materiais Utilizados:** Registro obrigatório de causa raiz, setor causador, ação corretiva e materiais gastos consumidos diretamente de `itens_catalogo` com snapshot de preço médio.

### 7.2. Central de Administração & Usuários (`pages/admin/usuarios.php`)
- Painel administrativo unificado em 3 abas principais: **Usuários**, **Setores da Fábrica** e **Perfis/Permissões**.
- Árvore de permissões hierárquica em 3 níveis (**Sistema HUB ➔ Módulo ➔ Tela**) com accordions expansíveis, sticky header e ações em lote (Expandir/Recolher/Liberar/Bloquear Todos).
- Gestão de CPFs, e-mails, senhas com hash `BCRYPT`, flag operacional `e_executor` e exclusão lógica segura com confirmação modal.

### 7.3. Paint Check — Visão Computacional & OCR Industrial Híbrido (`pages/qualidade/paint-check.php` & `paint-check-robo/server.py`)
- Sistema de inteligência artificial para validação automatizada de montagem e identificação de placas de transformadores:
  - Detecção e correção de perspectiva angular via **YOLO-OBB** (`cv2.warpAffine`), square padding e realce morfológico para superfícies metálicas (CLAHE + TopHat).
  - Inferência dupla em 0° e 180° com eliminação de sobreposição via IoU ($>50\%$).
  - Inversão semântica bidirecional de caracteres ambíguos a 180° (`6 ↔ 9`, `2 ↔ 5`, `0 ↔ 0`, etc.) no Python e PHP, validada contra o catálogo oficial `NS.OF.xlsx`.

---

## 8. Estrutura de Diretórios do Projeto

```
SGT/
├── _inicial/                        ← Migrações incrementais e scripts CLI
│   ├── database.sql                 ← Schema inicial de infraestrutura
│   ├── migrar-*.sql                 ← Migrações incrementais idempotentes
│   └── importar-*.php               ← Scripts CLI de importação de dados
├── api/                             ← Endpoints chamados via fetch/AJAX
│   ├── producao-acao.php            ← Ações de apontamento de chão de fábrica
│   ├── boletim-acao.php             ← Ações de metas e lançamentos do PCP
│   ├── boletim-fluxo.php            ← Endpoint do Fluxo de Pedidos
│   ├── boletim-exportar.php         ← Exportação de dados do PCP
│   ├── retrabalho-acao.php          ← Ações de retrabalho e triagem
│   ├── soma-acao.php                ← Ações e apontamentos do SOMA
│   └── projetos-acao.php            ← Cadastro de apoio (pedidos/projetos)
├── config/
│   ├── conexao.php                  ← Conexão PDO MySQL (getDB) e SQL Server (getSqlServerDB)
│   ├── session.php                  ← Sessão segura em banco e controle de acessos
│   └── versao.php                   ← Versão global do sistema (APP_VERSION)
├── includes/
│   ├── layout.php                   ← Encapsulamento de cabeçalho, sidebar e rodapé
│   ├── header.php / sidebar.php / footer.php
│   ├── helpers.php                  ← Funções utilitárias globais (datas, cálculos, dias úteis)
│   ├── boletim-planilha.php         ← Leitor da planilha-ponte do Kardex
│   ├── boletim-acompanhamento.php   ← Motor de dados do Acompanhamento Tanque/Parte Ativa
│   ├── boletim-fluxo-pedidos.php    ← Árvore recursiva de sub-OFs do SQL Server
│   ├── boletim-atraso.php           ← Cruzamento de atrasos do Plano Mestre
│   ├── boletim-painel-setor.php     ← Métricas e histogramas por célula
│   ├── planilha-ns-of.php           ← Leitor do catálogo NS.OF.xlsx
│   ├── soma-helpers.php / soma-subnav.php
│   ├── modal-detalhes-pecas.php     ← Modal de detalhe de peças
│   └── modal-filtro-data.php        ← Modal de filtro de intervalo de datas
├── pages/
│   ├── producao/                    ← Módulo de Produção & Chão de Fábrica
│   ├── distribuicao/                ← Dashboard Indicador Distribuição (TPD)
│   ├── atraso-distribuicao/         ← Dashboard de Atraso Distribuição
│   ├── forca-seco/                  ← Dashboard Indicador Média Força / Seco (TPM/TPS)
│   ├── painel-setor/                ← Painel Operacional por Setor
│   ├── fluxo-pedidos/               ← Rastreabilidade e Fluxo de Pedidos em 10 Células
│   ├── acompanhamento/              ← Acompanhamento Tanque/Parte Ativa → Montagem Final
│   ├── soma/                        ← Subsistema de Cronoanálise SOMA
│   ├── retrabalho/                  ← Módulo de Gestão de Retrabalhos
│   ├── pedidos/                     ← Priorização e Sequenciamento de Pedidos
│   ├── projetos/                    ← Cadastro de Apoio de Pedidos e Projetos
│   ├── qualidade/                   ← Tipos de Reprova & Paint Check
│   ├── pintura/                     ← Paint Check e Relação de Retrabalhos da Pintura
│   └── admin/                       ← Central Unificada de Usuários, Setores e Perfis
├── assets/
│   ├── css/main.css                 ← Design System central e variáveis CSS
│   ├── css/fluxo-pedidos.css        ← Estilização da matriz de fluxo de pedidos
│   └── js/                          ← Scripts específicos por tela
├── PLANILHA QUE ATUALIZA/           ← Planilhas externas sincronizadas por Power Query
├── storage/cache/                   ← Cache de índices de leitura rápida (gitignored)
├── uploads/                         ← Arquivos e fotos enviados (gitignored)
├── .env                             ← Variáveis de ambiente locais (gitignored)
├── Dockerfile                       ← Build de deploy para produção no Railway
├── index.php                        ← Hub principal de seleção de sistemas
├── login.php / logout.php           ← Fluxo de autenticação
└── em-breve.php                     ← Placeholder de sistemas em desenvolvimento
```

---

## 9. Estrutura de Banco de Dados & Schema Unificado

### 9.1. Tabelas de Infraestrutura & Acesso
| Tabela | Descrição |
|---|---|
| `perfis` | Papéis mestres: 1=Administrador, 2=Planejador, 3=Executor, 4=Dashboard, 5=Cliente Interno |
| `usuarios` | Cadastro de usuários, CPF, e-mail, senha criptografada (`BCRYPT`), `id_perfil`, `id_setor`, `ativo`, `e_executor` e `deleted_at` |
| `setores` | Setores da fábrica (Bobinagem, Montagem, Solda, Pintura, LAB, IQF, etc.) |
| `php_sessions` | Sessões persistidas em banco (`TraelDbSessionHandler`) para suportar deploy efêmero |
| `logs_atividade` | Auditoria de acessos (`login_ok`, `login_erro`, `logout`) e operações críticas |

### 9.2. Tabelas de Retrabalho & Catálogo
| Tabela | Descrição |
|---|---|
| `pedidos` | Cadastro de pedidos comerciais com prioridade e sequência |
| `projetos` | Projetos de engenharia vinculados a pedidos |
| `reprovas` | Catálogo de tipos de reprova com código, descrição, família, local e setor causador |
| `retrabalhos` | Lançamentos de reprova e retrabalho |
| `retrabalho_material_uso` | Materiais consumidos na triagem com snapshot de código, descrição, unidade e preço médio |
| `itens_catalogo` | Catálogo geral de materiais importado de `Item.csv` |

### 9.3. Tabelas de Chão de Fábrica (Produção)
| Tabela | Descrição |
|---|---|
| `producao_transformadores` | Vínculo de número de série ao projeto e pedido |
| `producao_etapas` | Passagens por estações (IQF, LAB, GER) com índice único de etapa ativa (`ns_ativo`) |

### 9.4. Tabelas do PCP (Boletim)
| Tabela | Descrição |
|---|---|
| `boletim_config_metas` | Metas mensais (`meta_tpm`, `meta_tpd_distribuicao`, `meta_enrolado`, `meta_convencional`, `meta_jctrif`, `meta_tpd_forca`, `meta_tps`), dias úteis, trabalhados e dias customizados |
| `boletim_registros` | Registro diário de metas e produções por área e linha |
| `boletim_equipamentos` | Cadastro e status semáforo de equipamentos críticos |

### 9.5. Tabelas do SOMA (Cronoanálise)
| Tabela | Descrição |
|---|---|
| `soma_empresas` | Unidades fabris cadastradas (Matriz Cuiabá, Filial PA, Filial SP) |
| `soma_setores` | Setores fabris com metas percentuais de produtividade |
| `soma_operadores` | Operadores de produção |
| `soma_maquinas` | Máquinas e postos de trabalho cadastrados |
| `soma_motivos_parada` | Motivos de parada de linha (Manutenção, Processo, Material, etc.) |
| `soma_produtos` | Tempos padrão de ciclo por produto e etapa |
| `soma_registros` | Apontamentos de tempos e ciclos realizados |
| `soma_paradas` | Registro de ocorrências de paradas de máquina com duração |

---

## 10. Matriz de Permissões RBAC

| Chave | Módulo / Tela | Descrição |
|---|---|---|
| `hub:producao` | Produção | Acesso geral ao card de Produção no Hub |
| `prod.dis` | Indicador Distribuição | Dashboard de metas e realizado de TPD |
| `prod.atr` | Atraso Distribuição | Dashboard de atraso do Plano Mestre |
| `prod.for` | Indicador Média Força | Dashboard de TPM e TPS |
| `prod.set` | Painel por Setor | Visão e histograma por célula |
| `prod.flu` | Fluxo de Pedidos | Matriz de rastreabilidade de pedidos |
| `prod.aco` | Acompanhamento | Acompanhamento Tanque / Parte Ativa |
| `prod.met` | Configurações PCP | Edição de metas e calendário de produção |
| `prod.reg` / `lab.reg` | Registro LAB | Card de apontamento de entrada no Laboratório |
| `prod.lis` / `lab.lis` | Lista Produção | Listagem de passagens e ação Reprovar |
| `prod.ret` / `lab.ret` | Retornos LAB | Fila de retornos pós-retrabalho |
| `hub:soma` | SOMA | Acesso geral ao card de SOMA no Hub |
| `soma.hub` | Dashboard SOMA | Visão gerencial de cronoanálise |
| `soma.dig` | Digitador SOMA | Interface de digitação rápida de tempos |
| `soma.ope` | Operador SOMA | Terminal do operador de máquina |
| `soma.par` | Paradas SOMA | Registro e gestão de paradas de linha |
| `soma.reg` | Relação SOMA | Tabela detalhada de ciclos |
| `soma.rel` | Relatórios SOMA | Relatórios de peça/hora e produtividade |
| `soma.aud` | Auditoria SOMA | Auditoria de apontamentos |
| `soma.cfg` | Configurações SOMA | Cadastro de tempos padrão e máquinas |
| `ret.dash` | Dashboard Retrabalho | Visão executiva de não-conformidades |
| `ret.pan` | Painel Retrabalho | Mapa interativo das esteiras fabris |
| `ret.rel` | Relação Retrabalhos | Tabela mestre de retrabalhos |
| `pcp.pri` | Prioridades | Sequenciamento e prioridades de pedidos |
| `pin.pai` | Paint Check | Visão computacional e inspeção OCR |
| `adm.usu` | Central Admin | Gestão de usuários, setores e permissões |

---

## 11. Roteiro de Sprints Atualizado

| Sprint | Escopo | Status |
|---|---|---|
| **1 — Fundação & Autenticação** | Banco MySQL unificado, login seguro bcrypt, perfis e persistência de sessões | ✅ |
| **2 — Hub Central de Sistemas** | Tela "Selecione um sistema" com roteamento dinâmico baseado em RBAC | ✅ |
| **3 — Módulo de Retrabalho** | Dashboard executivo, mapa fabril, relação mestre e triagem de materiais | ✅ |
| **4 — Apontamento de Chão de Fábrica** | Card LAB em tempo real, leitor de QR Code (câmera/imagem/manual) e Lista com ação Reprovar | ✅ |
| **5 — Central Admin & Paint Check** | Gestão unificada em 3 abas (usuários/setores/perfis) + OCR industrial com inversão semântica | ✅ |
| **6 — PCP & SOMA Integrados na Produção** | Incorporação dos Dashboards de Distribuição (TPD), Atrasos, Média Força, Painel por Setor, Fluxo de Pedidos, Acompanhamento Tanque/Parte Ativa e Cronoanálise SOMA | ✅ |
| **7 — Demais Módulos do Hub** | Desenvolvimento progressivo dos sistemas planejados (SGE, 5S, Ausências, Incidentes, Perdas, Paradas) | 🗓 Planejada |

---

## 12. Glossário Unificado de Termos Fabris

| Termo | Significado |
|---|---|
| **PCP** | Planejamento e Controle da Produção |
| **TPD** | Transformadores de Distribuição (linha padrão até 300 kVA) |
| **TPM** | Transformadores de Média Força |
| **TPS** | Transformadores a Seco |
| **OF / cd_of** | Ordem de Fabricação impressa na placa do transformador |
| **Sub-OF** | Ordem de fabricação filha vinculada a uma sub-montagem (Tanque, Parte Ativa, Bobina) |
| **Parte Ativa** | Conjunto mecânico e elétrico formado pelo núcleo de lâminas de silício montado com as bobinas de AT e BT (Montagem Elétrica `ME`/`PA`) |
| **Tanque** | Estrutura metálica de caldeiraria e pintura (`MTQ`) onde a parte ativa é enclausurada e imersa em óleo mineral |
| **Montagem Final (`MFL`)** | Posto de montagem final onde a parte ativa é encaixada no tanque com fechamento de tampa e conexões |
| **Núcleo ENR** | Núcleo enrolado contínuo + transformadores JC monofásicos/bifásicos |
| **Núcleo JC-TRIF** | Núcleo modelo JC exclusivamente trifásico |
| **Núcleo EMP** | Núcleo empilhado convencional de lâminas |
| **IQF** | Inspeção da Qualidade Final |
| **LAB** | Laboratório de Ensaios Elétricos de Alta Tensão |
| **SOMA** | Sistema Operacional de Manutenção e Apontamento (Cronoanálise e Tempos) |
| **Turno Fabril** | Período de 07:30 às 02:48 (-450 min de deslocamento para apontamentos da madrugada) |
| **FIFO** | First In, First Out — Regra de priorização onde peças mais antigas têm precedência |

---

## 13. Controle de Versão & Atualização de Changelog
- Versão do sistema gerenciada em `config/versao.php` (`APP_VERSION`).
- Histórico de versões mantido em `pages/changelog.php`.
- Padrão oficial de mensagens de commit: `feat: vX.Y.Z — <descrição>` ou `fix: vX.Y.Z — <descrição>`.

---

## 14. Agent Skills & Automação de Engenharia

### Issue Tracker
Tarefas, especificações e tickets de trabalho são gerenciados localmente no diretório `scratch/`. Consulte [`docs/agents/issue-tracker.md`](file:///m:/APP/Sistema_SGT/www/SGT-dev/docs/agents/issue-tracker.md).

### Triage Labels
Vocabulário canônico de triagem de demandas: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. Consulte [`docs/agents/triage-labels.md`](file:///m:/APP/Sistema_SGT/www/SGT-dev/docs/agents/triage-labels.md).

### Domain Docs
Estrutura de documentação single-context baseada no glossário do [`CONTEXT.md`](file:///m:/APP/Sistema_SGT/www/SGT-dev/CONTEXT.md) e registros de decisões em `docs/adr/`. Consulte [`docs/agents/domain.md`](file:///m:/APP/Sistema_SGT/www/SGT-dev/docs/agents/domain.md).

