# PROJETO SGT — Sistema de Gestão Trael (com PCP, SOMA, Papel, Pintura & Qualidade Integrados)

> **Documento vivo e oficial de referência arquitetural, regras de negócio, engenharia de software e especificações técnicas.**  
> **Versão Oficial do Sistema:** `v1.4.0` (fonte: `config/versao.php`)  
> **Última revisão geral contra o código:** 29/09/2026 — divergências e pendências abertas na seção 19.  
> Qualquer decisão de arquitetura, fluxo produtivo, schema de banco de dados ou integração fabril deve ser consultada e mantida estritamente alinhada com este documento.

---

## 1. Identidade do Produto & Contexto Operacional

O **SGT (Sistema de Gestão Trael)** é a plataforma integrada de inteligência industrial, planejamento, controle de produção, rastreabilidade e chão de fábrica da **Trael Transformadores Elétricos**. O sistema consolida em um ecossistema corporativo único:
1. A gestão de retrabalhos e custos de não-conformidade fabril;
2. Rastreabilidade pontual e encadeada de Ordens de Fabricação (OFs mães e sub-OFs) em 10 células de manufatura;
3. Inteligência e controle de produção do **PCP (Planejamento e Controle da Produção)**, com medição contínua (Meta × Realizado), análise de aderência mensal/anual e acompanhamento de atrasos do Plano Mestre para Distribuição e Média Força;
4. Subsistema **SOMA (Cronoanálise Industrial & Tempos Padrão)** para medição de peça/hora, eficiência e paradas de linha;
5. Módulo industrial dedicado do **Setor de Papel** (mesa de corte, isolamento elétrico, aproveitamento de estoque, inventário do almoxarifado e emissão de Ordem de Corte F-29);
6. Linha dedicada de **Pintura (Estação PIN)** com apontamento em tempo real, retornos e rastreamento;
7. Inspeção da Qualidade e ensaios com estações dedicadas de **Laboratório de Alta Tensão (`LAB`)** e **Inspeção da Qualidade Final (`IQF`)**;
8. Sistema de visão computacional e OCR industrial híbrido **Paint Check 2.0** (validação de montagem, punção em tampas, tanques e orelhas de içamento, elo fusível, conferência contra concessionárias e geração de dataset para fine-tuning).

### Usuários Principais
- **Supervisores de Produção & Líderes de Linha:** Acompanhamento de esteiras em tempo real, apontamentos operacionais, leitura de QR Codes e códigos de barras (câmera/terminal USB), monitoramento de gargalos e paradas de máquina.
- **Planejadores do PCP:** Monitoramento contínuo de metas diárias, mensais e anuais, análise de atrasos e aging do Plano Mestre, priorização e sequenciamento de pedidos (FIFO com herança em 3 níveis) e rastreamento da árvore de sub-montagens.
- **Inspetores de Qualidade & Laboratório (`LAB` / `IQF`):** Ensaios elétricos de rotina e tipo, inspeção dimensional, mecânica e de pintura, emissão de reprovas, controle de reensaios e liberação para expedição.
- **Operadores de Máquinas do Setor de Papel:** Programação e corte de tiras, kits BT e cabeceiras isolantes, acompanhamento de ordens F-29, filas por máquina (Slitter, Guilhotinas, Dobradeiras, Sanfonadeiras) e apontamento de chão.
- **Inspetores de Pintura:** Validação visual e dimensional com o Paint Check Robô, conferência de puncionamento por concessionária e abertura de retrabalho com pré-preenchimento automático.
- **Analistas de Custos & Controladoria Industrial:** Auditoria de horas-homem gastas em retrabalho, valoração de materiais aplicados na triagem e relatórios gerenciais consolidados de perdas.
- **Liderança Industrial & Diretoria:** Dashboards executivos de indicadores de produção por linha (Distribuição — TPD, Média Força — TPM, Transformadores a Seco — TPS), velocímetros de eficiência (*gauges*), gargalos reais por célula e balanço de produtividade multiplanta (Empresas 1 e 4).
- **Operadores de Máquina & Digitadores do SOMA:** Terminais simplificados para apontamento de tempos de ciclo, paradas de linha e alimentação rápida em lote.

### Princípios Fundamentais do Produto
1. **Visibilidade Imediata:** Informações críticas, desvios de meta, divergências de serigrafia e gargalos produtivos saltam aos olhos com contrastes calibrados e sem navegação profunda.
2. **Zero Fricção Operacional:** Topos travados (*sticky headers*), filtros instantâneos no padrão planilha, paginação ágil, leitores de código escutando em segundo plano e rolagem única controlada no container principal.
3. **Fidelidade Real do Chão de Fábrica:** As regras do sistema espelham fielmente a física dos processos de manufatura, a lógica de montagem dos transformadores, os turnos industriais reais e os dados do ERP.
4. **Precisão & Integridade:** Dados 100% auditados, rastreabilidade ponta a ponta sem duplicidades ou produtos cartesianos, e integridade referencial com soft delete em todas as tabelas operacionais.

---

## 2. Stack Tecnológica & Padrões Arquiteturais

### Back-end
- **PHP 8.4 Vanilla:** Arquitetura limpa sem frameworks externos (sem Laravel, Symfony, etc.); uso obrigatório da declaração estrita `declare(strict_types=1)` no topo de todos os arquivos PHP.
- **Conexão Dual de Banco de Dados:**
  - **MySQL (Aplicação Principal):** Conexão singleton gerenciada exclusivamente por `getDB()` em `config/conexao.php` (autenticação, sessões, retrabalho, metas mensais, catálogo, cronoanálise, módulo papel, pintura, regras de concessionárias e validações).
  - **SQL Server (ERP Trael / PierServer / Kardex / piAudit):** Conexão singleton segura `getSqlServerDB(): ?PDO` via PDO ODBC (`vsat.trael.local` / `vsattrael`) em `config/conexao.php`, com timeout curto e tolerância a falhas de rede interna.
- **Resiliência de Dados (cache local):**
  - **Sessões Persistidas em Banco:** Classe `TraelDbSessionHandler` implementando `SessionHandlerInterface` gravando na tabela `php_sessions`.
  - **Caches Universais (`storage/cache/`):** Arquivos consolidados e sanitizados (`kardex_mes_*.cache`, `atraso_ns_suplementar.json`, caches de fluxo de pedidos e acompanhamento) que evitam reconsultar o SQL Server da fábrica a cada requisição e servem de fallback quando a conexão VSAT está momentaneamente indisponível.
    - Regerados por tarefa agendada: `fluxo_planilha.json` e `fluxo_encerramentos.json` (de hora em hora, ver 5.7) e a foto do atraso no MySQL (a cada 15 min, ver 5.2).
    - `acompanhamento.json` e `fluxo_pedidos.json` são **fallback congelado** (última gravação em 26/08/2026): nada os regrava, e quando o SQL Server cai `carregarAcompanhamentoProducao()` / `carregarEsteiraPedidos()` os devolvem como sucesso, sem aviso.
    - Se existir `kardex_mes_AAAA-MM.json`, ele tem prioridade sobre o `.cache` do mesmo mês (`boletim-planilha.php`), mesmo sendo mais antigo; a Aderência Anual lê só `.cache`.
    - `storage/cache/*` está no `.gitignore`, mas alguns arquivos antigos continuam versionados (foram adicionados antes da regra).
  - Desde 2026-09-21 a produção real é **só local + ngrok** (ver CLAUDE.md) — não existe mais deploy nem sincronização para o Railway; os scripts `scripts/sincronizar_producao_railway.php`, o endpoint `api/sync-boletim.php` e o `Dockerfile`/`.dockerignore` foram removidos por não terem mais uso.
- **Planilhas-Ponte & Leitura Server-Side via `PharData`:**
  - `PLANILHA QUE ATUALIZA/NS.OF.xlsx`: Índice de Ordens de Fabricação (~4,8 MB, atualizado por Power Query, lido por `includes/planilha-ns-of.php`).
  - `PLANILHA Q ATUALIZA/Relação Kardex.xlsx`: Histórico de movimentações de produção (~85 MB, 38 colunas, abas `dw vw_kardex_lotes` e `dw vw_ficha_espc_trafo`). **Hoje não é lida:** o Kardex vem do SQL Server + `kardex_mes_*.cache`; o fallback por planilha em `includes/boletim-planilha.php` devolve vazio e a constante `BOLETIM_KARDEX_ARQUIVO` não é usada.
  - `PLANILHA QUE ATUALIZA/Item.csv`: Catálogo oficial de materiais (~23,7 mil itens, importado para `itens_catalogo` por `_inicial/importar-itens-catalogo.php`).
  - `vsat_num_series_previo`: Cache local de números de série do VSAT para identificação imediata no Paint Check. O PowerShell (`scripts/sincronizar_vsat_num_series.ps1`) só gera um JSON; quem grava no MySQL é `api/vsat-num-series-sincronizar.php` (só Admin). Se o cache tiver mais de 30 dias, `includes/vsat-num-series.php` cai na planilha `NS.OF.xlsx` (`planilha-ns-of.php`).

### Front-end
- **Tailwind CSS (CDN Play):** Estilização utilitária combinada com o design system central em `assets/css/main.css` (sem etapa de build pesada como Webpack/Vite).
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
- **JavaScript Vanilla:** Scripts dedicados por tela em `assets/js/` (sem jQuery, React ou Vue).
- **Bibliotecas CDN Homologadas:**
  - **Chart.js + ChartDataLabels:** Gráficos diários, séries empilhadas, histogramas de células, curvas de tendência e medidores velocímetro (*gauges*).
  - **jsQR:** Decodificação de QR Codes via stream de vídeo da câmera e upload de imagens no chão de fábrica servida com fallback local.

### IA & Visão Computacional (Paint Check Robô)
- **Serviço Python FastAPI (`paint-check-robo/server.py`):**
  - Processamento de imagem industrial com **OpenCV** e filtros morfológicos calibrados para superfícies metálicas e puncionadas (CLAHE + TopHat).
  - Reconhecimento óptico de caracteres via **PaddleOCR** de alta precisão com suporte a rotação angular (0°, 180° e texto vertical em 90°/270°).
  - Pipeline de inferência com fallback e testes para Vision Language Models (VLM).

### Suíte de Testes Automatizados
- **PHPUnit (Dev-Only):** Testes unitários e de integração em `tests/` executáveis via `scripts/run-tests.ps1` e configurados em `phpunit.xml`:
  - `tests/Includes/ConcessionariaRegrasTest.php`: Validação de normalização de concessionárias, carregamento de regras e locais obrigatórios.
  - `tests/Includes/FeriadosTest.php`: Validação do motor de feriados nacionais, estaduais de MT e municipais de Cuiabá, e cálculo correto de dias úteis sem distorções no ritmo de produção.
  - `tests/Includes/PlanilhaPlanoMestreTest.php`: Processamento de registros do Plano Mestre e multiplicador de bobinas.
  - **Estado em 29/09/2026: a suíte não passa.** `PlanilhaPlanoMestreTest` chama `planoMestreProcessarRegistro` / `planoMestreCalcularMultiplicadorBobinas` e `FeriadosTest` chama `boletimIsFeriado` / `boletimObterNomeFeriado` — nenhuma dessas funções existe mais; e `FeriadosTest` espera 21 dias úteis em set/2026, mas `boletimDiasUteisDoMes()` não desconta feriados (dá 22). `tests/bootstrap.php` chama `getDB()`, que pode disparar a DDL automática (ver 19).

---

## 3. Regras de Ouro & Comportamento de Engenharia

1. **Execução Aprofundada & Sem Pressa (Zero-Rush):** Não abreviar código, não pular trechos boilerplate e nunca usar placeholders como `// TODO` ou `// resto do código aqui`. Arquivos entregues devem ser completos e prontos para produção.
2. **Escopo Estrito:** Fazer estritamente o que foi solicitado na tarefa. Não refatorar, renomear ou reorganizar código que não tenha sido expressamente pedido.
3. **Preservação de UI/UX:** Ao editar interfaces e scripts, preservar rigorosamente labels de botões, tags, elementos visuais e comportamentos do Design System Trael.
4. **Testes Ponta a Ponta Obrigatórios:** Nenhuma alteração é considerada concluída sem verificação de sintaxe (`php -l`), validação de queries e testes reais de execução.
5. **Migrações de Banco Manuais em `.sql`:** Toda alteração de schema deve ser entregue em arquivos SQL idempotentes (`CREATE TABLE IF NOT EXISTS`, scripts de migração seguros) para aplicação manual controlada em ambiente local e produção.

---

## 4. Regras Críticas de Chão de Fábrica & PCP

### 4.1. Turno de Produção Industrial (07:30 às 02:48)
- A jornada diária de produção da fábrica tem início às **07:30** da manhã e se estende até as **02:48** da madrugada do dia seguinte.
- Apontamentos realizados entre **00:00:00 e 07:29:59** pertencem jurídica e operacionalmente à data do **turno anterior**.
- Na leitura direta do ERP (`SQL Server`), esses registros são cruzados com a tabela de auditoria `dbo.piAudit` (`OIDTable = 29708`) e recuados para a data do turno anterior através do deslocamento temporal:
  ```sql
  DATEADD(minute, -450, COALESCE(aud.DataHoraAudit, k.dt_Movimento))
  ```

### 4.2. Agregação de Produção de Fins de Semana na Sexta-Feira
- Transformadores apontados no **sábado** ou no **domingo** são automaticamente somados e agregados na **sexta-feira imediatamente anterior** através da função `boletimAjustarDataFimDeSemanaParaSexta()`. Isso reflete fielmente o fechamento da programação semanal da fábrica.

### 4.3. Classificação de Núcleos na Linha de Distribuição (`boletimClassificarNucleoTrafo()`)
Na linha de Distribuição (TPD), cada transformador é classificado estritamente em uma das 3 famílias de núcleo:
- **`JC-TRIF`:** Exclusivamente transformadores com núcleo `JC` (`ds_TpEnrolamentoNucleo = 'JC'`) e que sejam **Trifásicos** (`nrofasesTrafo = 'TRI'` ou descrição contendo `3F`).
- **`ENR`:** Inclui núcleos `ENR` e **todos os transformadores `JC` Monofásicos (`1F`/`MON`) ou Bifásicos (`2F`/`BIF`)**.
- **`EMP`:** Núcleos `EMP` e `EMP-LM` (Convencional / Empilhado de lâminas).
- **Exceção por potência:** transformadores de **5, 10 e 15 kVA são sempre `ENR`**, qualquer que seja o núcleo cadastrado (regra aplicada no Kardex e no Plano Mestre).

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

### 4.8. Calendário Oficial de Feriados & Aderência Fabril
- O calendário de feriados é montado por `boletimObterFeriadosAno()` (`includes/boletim-painel-producao.php`) e usado pela **Aderência Mensal/Anual**:
  - **Feriados Nacionais Fixos:** 01/01, 21/04, 01/05, 07/09, 12/10, 02/11, 15/11, 20/11 (Consciência Negra) e 25/12;
  - **Feriados Móveis Oficiais:** Terça de Carnaval (Páscoa − 47 dias), Sexta-feira Santa e Corpus Christi. A **segunda de Carnaval não está no código**;
  - **Feriados Estaduais de Mato Grosso & Municipais de Cuiabá:** 08/04 (Aniversário de Cuiabá) e 08/12 (Nossa Senhora da Conceição).
  - Feriados extras cadastrados: o código lê a tabela `boletim_feriados`, que **não existe** — a migração `_inicial/migrar-feriados-local.sql` cria `feriados`. Na prática só valem os feriados calculados acima.
- **`boletimDiasUteisDoMes()` (`includes/helpers.php`) não desconta feriados** — conta só segunda a sexta. É o que usam Configurações, metas e a média da Aderência Anual. Distribuição, Média Força e Painel por Setor usam o calendário do mês gravado em `boletim_config_metas.dias_customizados` (ver 5.1) ou, sem ele, seg–sex.
- **Expurgo de Dias Inoperantes:** Dias de feriado em que a fábrica não operou (sem apontamentos) são automaticamente omitidos dos gráficos diários e descontados do saldo de dias úteis restantes. Isso evita a distorção do **Ritmo Diário Necessário** e impede quedas artificiais no percentual de aderência.

### 4.9. Rastreabilidade Real de Gargalos no Plano Mestre (`setor_real` & `tipo_bloqueio`)
- Para identificar com precisão o que trava uma Ordem de Fabricação em atraso no Plano Mestre, a sincronização de dados do SQL Server decompõe recursivamente a árvore de sub-OFs (`dbo.RlcProgramacao`):
  - `tipo_bloqueio = 'CELULA'`: A primeira célula real da sequência fabril (Chassi, Bobinagem, Solda, Pintura, Montagem, LAB) que ainda possui saldo pendente é gravada na coluna `setor_real`.
  - `tipo_bloqueio = 'MATERIAL'`: Quando a fábrica já concluiu todas as sub-OFs internas e a ordem aguarda apenas componentes de compra/almoxarifado para fechamento da montagem final.
- Esse mecanismo substitui inferências baseadas apenas no prefixo do código de referência da peça.

### 4.10. Multi-Planta Fabril (Empresas 1 e 4)
- **Empresa 1 (Distribuição):** Produção focada em transformadores de distribuição até 300 kVA, acompanhada prioritariamente por tipos de núcleo (`ENR`, `EMP`, `JC-TRIF`).
- **Empresa 4 (Média Força):** Produção focada em transformadores de média/alta potência e especiais, acompanhada pelos tipos construtivos:
  - `TPD`: Transformadores de Distribuição fabricados nas esteiras de força;
  - `TPM`: Transformadores de Média Força a óleo (> 300 kVA);
  - `TPS`: Transformadores a Seco encapsulados em resina epóxi.

---

## 5. Módulos de Produção & PCP (Especificação Detalhada)

O módulo de **Produção** no SGT centraliza a inteligência do PCP, dashboards gerenciais executivos, controle de esteiras e os apontamentos de chão de fábrica.

```
pages/
├── producao/
│   ├── index.php                ← Registro de Entrada / Apontamento (Card LAB/IQF, Leitor QR/Câmera)
│   ├── lista.php                ← Lista de Registros Operacionais & Ação Reprovar
│   ├── retornos.php             ← Fila de Retornos de Retrabalho (Pós-Correção)
│   ├── aderencia-mensal.php     ← Dashboard de Aderência Mensal (9 KPIs + Gráficos Diários + Feriados)
│   ├── aderencia-anual.php      ← Comparativo Plurianual de Aderência da Produção
│   ├── resumo-diario.php        ← Visão Consolidada Lado a Lado das 10 Células do Chão de Fábrica
│   ├── status-pecas.php         ← Matriz de OFs Apontadas e em Aberto por Célula do Fluxo
│   ├── distribuicao.php         ← Proxy para Indicador Distribuição (TPD)
│   ├── atraso-distribuicao.php   ← Proxy para Atraso Distribuição
│   ├── forca-seco.php           ← Proxy para Indicador Média Força / Seco
│   ├── atraso-media-forca.php   ← Proxy para Atraso Média Força
│   ├── painel-setor.php         ← Proxy para Painel por Setor
│   ├── fluxo-pedidos.php        ← Proxy para Rastreabilidade do Fluxo de Pedidos
│   ├── acompanhamento.php       ← Proxy para Acompanhamento Tanque/Parte Ativa
│   └── settings.php             ← Metas Mensais & Calendário Customizado
├── distribuicao/
│   └── index.php                ← Dashboard Principal de Distribuição (TPD)
├── atraso-distribuicao/
│   └── index.php                ← Análise de Atraso e Aging com Persistência em Banco MySQL
├── forca-seco/
│   └── index.php                ← Dashboard de Média Força e Transformadores a Seco
├── atraso-media-forca/
│   └── index.php                ← Dashboard de Backlog, Capacidade e Atraso de Média Força
├── painel-setor/
│   └── index.php                ← Painel Operacional por Célula / Histograma / Gargalos
├── fluxo-pedidos/
│   ├── index.php                ← Matriz de Rastreabilidade em 10 Células
│   └── setor.php                ← Visão Detalhada por Célula
└── acompanhamento/
    └── index.php                ← Convergência Tanque/Parte Ativa → Montagem Final
```

### 5.1. Dashboard Indicador Distribuição (TPD) (`pages/distribuicao/index.php`)
- **Painel Superior de Indicadores (KPIs):** Quantidade Total por núcleo (ENR / JC-TRIF / EMP), Reprovas (% do PCP), Produção Total (% da meta), Potência Média por núcleo e geral, e Média Executada. Não há KPI de Saldo Restante nem de Ritmo Diário Necessário nesta tela.
- **Gráficos Interativos (Chart.js):**
  - **Produção Diária por Núcleo:** Barras agrupadas (`ENR`, `JC-TRIF`, `EMP`) + série `REP` (reprovas LAB), com as linhas Executado Total e Meta Diária.
  - **Mix de Produção:** Donut entre Mix "Programado" (= meta diária configurada por núcleo, não o programado lançado) e Mix Realizado.
  - **Produção Acumulada:** Realizado acumulado contra a meta linear do mês e a **Tendência de Fábrica** (ritmo médio real projetado para o mês).
- **Painel "Produção por Linha":** Tabela analítica por núcleo com 4 linhas de métricas (`Executado`, `Meta`, `Diferença`, `% Executado`) e colunas de `MÉDIA` e `SOMA`.
- **Modal de detalhes de peças:** clique no gráfico ou nos totais abre a relação de peças do dia/mês, com quebra por núcleo e cliente e filtros cruzados.
- **Exportação & Impressão A4 Paisagem:** Botão de impressão com layout landscape otimizado sem quebra de página.
- **Modal de Métricas & Calendário Interativo (`#modal-metricas`):** Definição de metas diárias por núcleo e marcação de dias úteis, pontes e feriados, recalculando a meta mensal na tabela `boletim_config_metas`.
  - **Calendário único por mês:** `boletim_config_metas.dias_customizados` é um só por mês e é **compartilhado** por Distribuição, Média Força e Painel por Setor — salvar o calendário numa tela muda as outras.
  - **Intervalo personalizado:** a meta diária e o calendário do modal são sempre calculados sobre o **mês inteiro** de `data_inicio` (corrigido em 29/09/2026; antes a meta virava meta do mês ÷ dias do intervalo e salvar o modal gravava um calendário truncado). Intervalo que cruza meses ainda usa só o mês de `data_inicio` (metas, potência e clique do gráfico — pendente, ver 19).

### 5.2. Dashboard Atraso Distribuição (`pages/atraso-distribuicao/index.php`)
- Persistência nativa em MySQL através da tabela `atraso_distribuicao_registros`, dispensando a leitura em tempo de execução de arquivos externos pesados.
- Cruzamento diário entre o snapshot das peças previstas no Plano Mestre e os apontamentos de conclusão no Laboratório (Kardex / SQL Server).
- Indicadores de atraso segmentados por família de núcleo (Monofásico, Convencional, JC-TRIF em quantidade de peças e dias médios de atraso).
- **Gargalo por NS:** o modal mostra em qual célula o equipamento está retido a partir das células pendentes do Fluxo de Pedidos (`setores` / `atraso_distribuicao_celulas`). As colunas `setor_real` / `tipo_bloqueio` são gravadas na foto, mas esta tela não as lê — quem as usa são o Status de Peças e o Painel por Setor.
- Cálculo da Média Geral Simples de atraso e gráfico de evolução temporal diária.
- **Atualização automática do atraso (Distribuição e Média Força):** o painel lê a "foto" do MySQL (`atraso_distribuicao_registros` / `atraso_forca_registros`), extraída direto do SQL Server pelo `scripts/atualizar_atraso.php` (chamado por `scripts/atualizar_atraso.bat`). Uma tarefa do Agendador do Windows (`SGT - Atualizar Atraso`) o executa a cada **15 minutos** nesta máquina; a extração da Distribuição leva ~30 s (ERP ~3 s + classificação de gargalo ~25 s + gravação ~2 s; a 1ª execução a frio chegou a 108 s) e a da Média Força ~3 s. Regras:
  - A foto do **dia** é substituída no lugar (`forcarSobrescrita`); dias anteriores nunca são tocados pelo script agendado, então a última atualização de cada dia é o fechamento dele.
  - `atraso_historico_diario` (série "Média de dias em atraso") é gravado **pela tela**, não pelo script: só quando alguém abre o painel no dia, e (desde 29/09/2026) só com a foto mais recente e sem filtro de meses — antes, abrir com filtro ou com foto antiga sobrescrevia o histórico do dia com números parciais. Dia sem acesso fica sem histórico e o gráfico reconstrói por estimativa.
  - `DELETE` + `INSERT` na mesma transação (a tela nunca vê a foto do dia vazia no meio da troca).
  - Extração vazia (ou sem a classificação de gargalo, na Distribuição) **não** sobrescreve a foto anterior do dia.
  - Sem execução concorrente (trava em `storage/cache/atualizar_atraso.lock`); log em `storage/logs/atraso-atualizar.log`.
  - A tela mostra, sob a data de referência, um **contador regressivo** "Próxima atualização em mm:ss m" (`boletimHtmlAtualizacaoAtraso()` + `assets/js/atraso-atualizacao.js`; o servidor manda o tempo restante e o navegador só conta a diferença). Ao zerar vira "Atualizando…" (âmbar) e consulta `api/atraso-atualizacao.php` a cada 10 s; quando a foto nova chega, recarrega a tela (mantém os filtros da URL e espera fechar qualquer modal `.modal-fluxo-overlay` aberto). Sem foto nova 5 min depois do previsto (tarefa agendada parada) vira o alerta vermelho "⚠ Atualização atrasada — última em dd/mm HH:MM". Foto antiga escolhida de propósito mostra só "Foto gravada em dd/mm HH:MM", sem contador. O intervalo (`ATRASO_INTERVALO_ATUALIZACAO_S` = 900 em `includes/boletim-atraso.php`) precisa ser igual ao gatilho da tarefa.
  - Contexto (2026-09-21): a foto estava parada em 09/09 porque o sincronizador antigo (`scripts/sincronizar_producao.bat`) apontava para o PHP do perfil de outro usuário do Windows e nada o agendava aqui. O atraso "real" (data de programação < hoje, OFs `AGU`/`RES`) bate com o Plano Mestre do VSAT; as peças de hoje não contam como atraso.

### 5.3. Dashboard Indicador Média Força / Seco (`pages/forca-seco/index.php`)
- Acompanhamento das linhas **TPM (Média Força)** e **TPS (Transformadores a Seco)**.
- Exibição de Meta Mensal, Realizado Acumulado e % da meta por linha (não há KPI de saldo a produzir).
- Gráfico de barras diárias com linha de meta sobreposta; o TPD produzido na planta 4 (`cdEnt=4`) é sempre uma das três linhas (TPD / TPS / TPM), não uma série condicional.
- Mesmos blocos da Distribuição: Mix, Produção por Linha, modal de Métricas com o calendário compartilhado do mês (5.1) e modal de peças por tipo construtivo.
- Lançamento manual de reprova (`core_type='LAB'`) alimenta só a série de reprovas — até 29/09/2026 sobrescrevia a produção da linha quando o Kardex do dia vinha nulo.

### 5.4. Dashboard Atraso Média Força (`pages/atraso-media-forca/index.php`)
- Monitoramento de backlog e capacidade diária segmentado pelas 3 linhas de força:
  - `TPD` ($\le 300\text{ kVA}$);
  - `TPM` ($> 300\text{ kVA}$);
  - `TPS` (Seco).
- Distribuição mensal e semanal com drilldown, série temporal de 15 dias de evolução de atraso e relação analítica de ordens e números de série no chão.
- Motor de cálculo em `includes/boletim-atraso-forca.php`, tabela `atraso_forca_registros` (criada pelo próprio código — não há `.sql` dela). A extração é feita pelo `scripts/atualizar_atraso.php` (5.2); com a tabela vazia a tela extrai sozinha, e `?refresh=1` na URL força uma extração para a data da URL (sem botão, sem trava do script agendado — ver 19).
- Classificação (`boletimClassificarLinhaForca()`, `boletim-planilha.php`): com Tipo Construtivo informado — contém "SECO" → TPS; "SELADO" → TPD se ≤ 300 kVA, senão TPM; **qualquer outro tipo → TPM**. Sem Tipo Construtivo, cai na regra antiga por referência/kVA (`TPS…` → TPS; ≤ 300 kVA → TPD; > 300 → TPM).
- `PROJETO-SGT/ATUALIZAR_ATRASO.py` é **legado**: extrai a Distribuição (`CatGrupo=40`, `cdEnt='1'`) para um CSV num compartilhamento de rede que o PHP não lê. Contém credencial do SQL Server no código (ver 19).

### 5.5. Aderência Mensal & Anual da Produção (`pages/producao/aderencia-mensal.php` & `aderencia-anual.php`)
- **9 KPIs da Aderência Mensal** (como estão na tela): Média Programada, Programado Parcial, Programado Total, Dias Úteis, Aderência Anual, Média Produzida, Produzido Parcial, Alcance da Meta e Aderência Mensal.
  - ⚠ O KPI **"Aderência Anual" desta tela é um valor fixo (102,55%)** em `boletim-painel-producao.php` — não é calculado (pendente, ver 19).
- **Realizado por setor (Aderência Mensal e Resumo Diário):** BT = entradas ENR, AT = entradas EMP, CNC = ENR + EMP; Solda, Montagem Núcleo, Pintura, Parte Ativa, Montagem Final e LAB mostram **todos o mesmo total de entradas no Laboratório**. O programado de BT/AT é contado em **bobinas** (monofásico 2, trifásico 3 — `TRI` aceito desde 29/09/2026), enquanto o realizado é em transformadores. Nenhuma das duas segue ainda a métrica "sub-OF encerrada no ERP" usada no Painel por Setor (5.7) — pendente, ver 19.
- **Alternância Multi-Planta:** Suporte completo à Empresa 1 (Distribuição - ENR/EMP/JC) e Empresa 4 (Média Força - TPD/TPM/TPS).
- **Integração com Calendário Oficial de Feriados:** Feriados municipais de Cuiabá, estaduais de MT e nacionais expurgados automaticamente da evolução diária quando não há expediente fabril.
- **Aderência Anual:** Comparativo plurianual de produção mês a mês com gráficos de barras empilhadas e histórico de atingimento.

### 5.6. Resumo Diário & Status de Peças (`pages/producao/resumo-diario.php` & `status-pecas.php`)
- **Resumo Diário:** Exibição simultânea lado a lado das **9 células** fabris (Bobinagem BT, Bobinagem AT, Corte Núcleo, Solda, Montagem Núcleo, Pintura, Parte Ativa, Montagem Final e Laboratório — **sem Chassi**) com programado × realizado e aderência; recarrega sozinho a cada 10 min.
  - **Fábrica 4 (Média Força):** layout em blocos Óleo (TPD e TPM) e Seco (TPS), uma linha por tipo construtivo (`tabela_forca`, devolvida por `boletimCalcularResumoDiario()` desde 29/09/2026 — antes a chave não existia e a tela da Média Força não abria). A faixa "Células (Fluxo de Pedidos)" dessas linhas ainda vem vazia.
- **Status de Peças:** Rastreabilidade consolidada de OFs apontadas e em aberto distribuídas por setor fabril, com busca rápida por número de série, projeto ou pedido comercial.
- **Filtro de Fábrica (`?empresa=1|4|0`):** seletor (`<select>` "1 - Distribuição / 4 - Média Força / Todas as Fábricas", o mesmo da Aderência Anual; antes eram 3 pills), implementado em `boletimCalcularStatusPecas()`. No Status de Peças o seletor está hoje oculto e a tabela principal de OFs está com `display:none`. Filtro de Linha fora de MON/TRI/EPO/POT (ex.: AFO, BIF, ESP) voltava vazio até 29/09/2026 por parâmetro SQL repetido (HY093). A Distribuição (`atraso_distribuicao_registros`) tem decomposição de sub-OFs e por isso mostra gargalo real por **célula** do fluxo fabril; a Média Força (`atraso_forca_registros`) não tem essa decomposição nesta base — sem célula real pra mostrar, e repetir a linha (TPD/TPM/TPS) no gráfico seria redundante com o filtro "Linha" que já existe em cima. Por isso as barras da Média Força usam o único progresso real que essa base tem: **Semi-Acabada** (`qtd_produzida > 0`, já com alguma peça produzida) vs. **Não Iniciada** (`qtd_produzida = 0`) — rotuladas `Média Força — Semi-Acabada` / `Média Força — Não Iniciada` quando as duas fábricas estão juntas. O filtro de Linha (EPO/MON/POT/TRI vs. TPD/TPM/TPS) muda de vocabulário conforme a fábrica escolhida e fica indisponível com "Todas as Fábricas" (os dois vocabulários não se combinam numa consulta só — a tela força `linha=TODOS` nesse modo). Com as duas fábricas juntas, a tabela ganha uma coluna extra "FÁBRICA" (badge 🏭 DIST / ⚡ MF) para diferenciar a origem de cada OF.

### 5.7. Painel por Setor (`pages/painel-setor/index.php`)
- Visão operacional detalhada por célula fabril com ordenação lógica do fluxo produtivo: Laboratório ➔ Montagem Final ➔ Montagem Elétrica ➔ Pintura ➔ Solda ➔ Montagem Núcleo ➔ Corte CNC ➔ Bobinagens.
- **Medidor Velocímetro (Gauge) de Eficiência (%):** Eficiência em tempo real da célula contra a meta estabelecida.
- **Métricas Operacionais:** Meta do Dia, Produção Total, Saldo Restante, Fila Acumulada em Espera e análise de gargalos.
- **Modal de Métricas e Metas Individualizadas:** Edição de metas personalizadas por setor para o mês vigente.
- **Gráfico "Produção vs. Programado por Setor Fabril" — fonte do "Produzido" por barra:**
  - **Laboratório (LAB):** apontamento de chão de fábrica (`producao_etapas`, estação `LAB`); se vazio, entradas do Kardex. O filtro por `producao_etapas` usa `DATE(data_fim)` — sem turno das 07:30 e sem jogar sábado/domingo na sexta (pendente, ver 19).
  - **Montagem Final (MFL), Montagem Elétrica (ME) e Bobinagem (BOB):** transformadores cuja **sub-OF da célula foi encerrada no ERP** nos dias do período, via `boletimObterProducaoRealFluxoCelulas()` → `carregarEncerramentosCelulasFluxo()` (Motor 3 em `boletim-fluxo-pedidos.php`).
    - **Por que não o status do Fluxo (Motor 2):** `dbo.OrdemFabricacao` não tem data de conclusão, só o status atual. Até 25/09/2026 a barra contava "das peças com **data programada** (`DataHoraProducaoAux`) no período, quantas já estão OK" — em "Hoje" dava MF 0 / ME 1 / PIN 0 contra 137 / 213 / 110 sub-OFs realmente encerradas no dia (conferido ao vivo no VSAT), porque as peças que passam pela célula hoje foram programadas dias antes.
    - **Data real:** auditoria do ERP `piAudit` (`OIDTable = 12660` = `OrdemFabricacao`, `Coluna = 'StatusOF'`); a data de encerramento é a última troca vinda de status ≠ `ENC` numa OF hoje em `ENC`. Cada sub-OF (`MFL` → MF, `MTQ` → PIN, `ME-`/`PA-` → ME, `BT-`/`AT-`) é ligada ao NS subindo a cadeia de 5 níveis de `RlcProgramacao`; escopo só categoria 40–44 e `NumSerie > 0` — **não** tem os demais filtros do Motor 2 (pedido ativo, desde 2026, status ≠ CAN/ENT, saldo > 0) e **não filtra empresa**: o cache traz Distribuição e Média Força juntas (campo `empresa` em cada item) e o gráfico hoje soma as duas nas barras MF/ME/PIN/BOB, enquanto LAB e Acompanhamento contam só a Empresa 1 (pendente, ver 19). Regra referência → célula centralizada em `fluxoCelulaDaReferencia()`.
    - **Turno:** dia começa às 07:30 e fim de semana compõe a sexta — mesma regra do Kardex, pra bater com o Laboratório.
    - **Bobinagem:** conta o transformador quando as sub-OFs de BT **e** de AT dele foram encerradas; vale a data da última das duas. Uma OF de bobina é um lote (ex.: 6 OFs = 438 bobinas), por isso a unidade é transformador, igual ao Programado.
    - **Cache:** a consulta leva ~2 min (quase tudo na `piAudit`, custo parecido pra 1 dia ou 1 mês), então roda só em `scripts/atualizar_fluxo_planilha.php`, que grava `storage/cache/fluxo_encerramentos.json` com janela desde o dia 1º do mês anterior, independente do `fluxo_planilha.json` (falha de um não impede o outro). Agendado na tarefa do Windows **"SGT - Atualizar Fluxo Planilha"** (`scripts/atualizar_fluxo_planilha_oculto.vbs`) **de hora em hora, 24h** (desde 29/09/2026; antes era 1x/dia às 18:00 e o filtro "Hoje" mostrava 0 nas células do ERP até a noite), limite de 30 min por execução; só roda com o usuário logado. Log em `storage/logs/fluxo-planilha-atualizar.log` (~1 min por execução). Entre execuções, o "Produzido" dessas células fica até 1h atrasado.
  - **Pintura/Tanque (MTQ):** apontamento próprio (`producao_etapas`, estação `PIN`) quando há registro no período; **senão** as sub-OFs `MTQ` encerradas (mesma fonte acima). Motivo: a Pintura é apontada no ERP e `producao_etapas` estava vazia no banco local, deixando a barra sem valor. Na visão por núcleo (ENR / JC-TRIF / EMP) a precedência só vale para as sub-OFs encerradas: **com apontamento próprio de Pintura a visão por núcleo cai no rateio estimado** (🔶, relação vazia).
  - O modal "Produção Real" lista só itens produzidos (NS, data/hora do encerramento, pedido, projeto); a fila em aberto não entra.
  - Estimativa por fator (marcada com 🔶) só se o cache de encerramentos não existir ou não cobrir o período (ex.: intervalo anterior ao mês passado).
  - ⚠ Período **posterior** ao `gerado_em` do cache (ex.: "Hoje" antes da primeira execução do dia, ou tarefa parada) **não** cai na estimativa: `carregarEncerramentosCelulasFluxo()` devolve sucesso e as barras mostram 0 como dado real, sem aviso (pendente, ver 19).
- **Gráfico "Acompanhamento de Produção: Em Aberto no Setor × Programado PCP"** — 6 relações (etapas da esteira), na ordem do fluxo, com selo de filtro, barra, modal de NS/projeto e linhas na tabela de OFs. Fonte: `carregarAcompanhamentoProducao()` (`boletim-acompanhamento.php`, status atual das sub-OFs, Empresa 1); configuração única em `boletimCalcularAcompanhamentoVsProgramado()`.
  - **Montar Núcleo (MN)** e **Montar Parte Ativa (PA)** (28/09/2026) seguem a árvore real do projeto no ERP, que muda com o tipo de núcleo:
    - Núcleo empilhado (`MNC-`, projetos EMP / EMP-LM / JC): `PA ← AT (← BT) + MNC (← CNC)`.
    - Núcleo `MN-` (ex.: ENR): `PA ← MN (← AT, CNC, MDA)`.
    - **Montar Parte Ativa:** AT encerrada **e** núcleo (`MNC-` ou `MN-`) encerrado, Parte Ativa (`PA-`/`ME-`) em aberto. Vale pros dois tipos.
    - **Montar Núcleo:** só projetos `MN-`; chassi `MDA` **e** `CNC` encerrados, núcleo `MN-` em aberto. Projetos `MNC-` ficam fora: não têm `MDA`, e o `MFU` (que o Fluxo chama de chassi) fica sob o tanque, não sob o núcleo.
    - Ficam em `itens_montagem`, lista separada de `itens`: o mesmo NS pode estar numa relação de montagem e numa da esteira Tanque × Parte Ativa. A tela de Acompanhamento (`pages/acompanhamento/`) continua só com as 4 ações originais.
  - Pintar Tanque / Guardar na Estufa / Descer Montagem Final / Verificar Apontamento: regras de `classificarAcompanhamento()` (ver 5.8).
  - Sem o SQL Server as contagens fixas inventadas (152 / 52 / 70 / 4, até 28/09/2026) não voltam, mas as barras **não ficam zeradas**: `carregarAcompanhamentoProducao()` cai no `storage/cache/acompanhamento.json` congelado de 26/08/2026 (formato antigo, sem `itens_montagem` e sem a regra da solda) e o apresenta como dado atual.
- **Tabela de OFs do painel:** limitada às 500 primeiras ordens; os itens da esteira do Acompanhamento ficam em cache de sessão por 120 s (`cache_itens_acomp_v2`).

### 5.8. Acompanhamento Tanque / Parte Ativa → Montagem Final (`pages/acompanhamento/index.php`)
- Rastreamento da convergência das 2 sub-montagens cruciais que alimentam a Montagem Final (restrito à **Empresa 1**):
  - **Pintura (`MTQ`):** Status de conclusão do Tanque;
  - **Montagem Elétrica (`ME-` / `PA-`):** Status de conclusão da Parte Ativa;
  - **Montagem Final (`MFL`):** Montagem Final do equipamento.
- **Matriz de Status e Badges de Ação:**
  - `Pintura OK + Montagem Elétrica OK` ➔ **"DESCER PARA MONTAGEM FINAL"** (Badge Verde);
  - `Montagem Elétrica OK + Solda (MTP) OK + Pintura Pendente` ➔ **"PINTAR TANQUE"** (Badge Âmbar);
  - `Solda (MTP) OK + Pintura OK + Montagem Elétrica Pendente` ➔ **"GUARDAR NA ESTUFA"** (Badge Azul) — o tanque soldado e pintado espera na estufa pela Parte Ativa;
  - `Montagem Final OK com etapas anteriores pendentes` ➔ **"VERIFICAR APONTAMENTO"** (Badge Vermelho — Alerta de Inconsistência).
  - Fora da esteira: Parte Ativa pronta com tanque ainda não soldado, ou nada pronto. Projeto sem sub-OF `MTP` conta como soldado.
  - Regras de Pintar Tanque e Estufa com solda: definidas pelo usuário em 28/09/2026. Na Parte Ativa, "apontada" = pronta: o ERP só tem `ENC` (encerrada e totalmente apontada) ou `RES` (sem apontamento), sem apontamento parcial.

### 5.9. Rastreabilidade & Fluxo de Pedidos (`pages/fluxo-pedidos/index.php`)
- Decomposição hierárquica recursiva de até **5 níveis de sub-OFs** no SQL Server (`dbo.RlcProgramacao`).
- Visão matricial em 10 células fabris: Chassi (`CH`), Bobinagem BT (`BT`), Bobinagem AT (`AT`), Corte CNC (`CNC`), Solda/Tanque (`SOL`), Montagem Núcleo (`MN`), Pintura (`PIN`), Montagem Elétrica (`ME`), Montagem Final (`MF`), Laboratório (`LAB`).
- Filtros por período, semana e mês na barra da tela; pedido, projeto e demais colunas só pelos popups de filtro das colunas. A busca do mapa (`#searchInput`) destaca células mas **não procura por NS**, apesar do placeholder. (Até 29/09/2026 a busca nem funcionava: `fluxo-mapa.js` quebrava por um botão ausente na página.)
- **Visão por célula (`setor.php` + `assets/js/fluxo-setor.js`):** lê só o cache `fluxo_planilha.json` (não consulta o VSAT ao vivo); Visão Pedidos / Individual com paginação de 200 e filtro de empresa. Esteira de 5 setores (Comercial → … → Logística) com a coluna ENGENHARIA derivada dos follow-ups do SAC.
- **Follow-ups do SAC:** `carregarFollowupsCSVFluxo()` procura `planilhas/Dados.csv` na raiz do projeto, que **não existe** (a única cópia estava em `fluxo_pedidos/planilhas/`, pasta removida). Resultado: a coluna ENGENHARIA fica sempre vazia e o modal não mostra histórico (pendente, ver 19).
- `api/boletim-fluxo.php` (`resumo_esteira`, `setor_pedidos`, `detalhe_pedido`) ainda roda `carregarEsteiraPedidos()` ao vivo no ERP a cada chamada.

### 5.10. Apontamento de Chão de Fábrica & Lista de Registros (`pages/producao/index.php` e `lista.php`)
- Card da estação (LAB/IQF/GER) para **registro de entrada**: hoje o card mostra sempre "Aguardando leitura" (o cronômetro do transformador em processo foi removido; o `pollStatus` a cada 20 s continua chamando a API e descarta o resultado).
- Leitor de QR Code modal em tela cheia com alternância dinâmica para upload de imagem e digitação manual de número de série; o leitor USB funciona com o overlay fechado (erros aparecem como toast desde 29/09/2026).
- **Ação Reprovar (em `lista.php`):** Abertura de modal com Pedido, Projeto e N° de Série travados e blocos repetíveis de reprova do catálogo oficial. Ao confirmar, gera os lançamentos na tabela `retrabalhos` e faz **soft delete** (`deleted_at`) da etapa `em_andamento` do NS.
- **Retornos (`retornos.php`):** fila de `producao_etapas` em `aguardando_retorno`, isolada por `retrabalhos.estacao` (LAB e PIN; a IQF não tem esse isolamento). Reapontar o NS resolve sozinho a pendência `aguardando_retorno`.

---

## 6. Módulo Setor de Papel (Corte de Isolamento Elétrico & Chaparia)

O **Módulo de Papel** gerencia todo o processo de preparação, corte, aproveitamento de sobras e programação dos materiais isolantes utilizados nos transformadores (papel Kraft, Presspan, Diamond Dot / Diamantado, etc.).

```
pages/papel/
├── mesa-de-corte.php        ← Terminal Operacional da Mesa de Corte & Aproveitamento
├── programacao.php          ← Programação de Corte da Demanda do PCP
├── inventario.php           ← Gestão e Inventário Físico do Almoxarifado de Papel
├── ordem-corte.php          ← Ficha de Ordem de Corte (F-29) para Produção
├── apontamento.php          ← Maquete estática (lotes fixos no HTML, sem gravação)
├── minha-maquina.php        ← Fila por Máquina (baixa ainda simulada)
├── maquinas.php             ← Cadastro e Gestão de Máquinas Operatrizes
├── pecas.php                ← Catálogo de Peças Padronizadas da Engenharia
└── peca-form.php            ← Edição de Peças (nome de engenharia e bloco)
```
(`sinonimia-print.php` e `api/papel/pecas-acao.php` foram removidos.)

### Funcionalidades do Módulo Papel:
- **Terminal Mesa de Corte (`mesa-de-corte.php`):** Interface otimizada com seletor superior de máquinas cadastradas, visualização das demandas do PCP, chips de status, modal interativo de match com estoque existente e rodapé com banner de rastreabilidade do ERP. O botão "Sincronizar com ERP VSAT" chama `api/papel-sincronizar-vsat.php` (a resposta é `success`/`error`; até 29/09/2026 o JS lia `sucesso` e sempre mostrava falha).
- **Programação de Demanda (`programacao.php`):** Cruzamento das ordens programadas (JSON do PCP em `pages/papel/dados_pcp_*.json`) com o cálculo de massa unitária (`calcMassaUnitJs`) e detecção automática de sobras ou materiais disponíveis no almoxarifado. A decisão de aproveitamento (aprovar/rejeitar) é gravada em `papel_aproveitamento_analise` por `peca_idx` — posição da peça no JSON, que muda a cada sincronização (pendente, ver 19). O "Corte Extra" ainda não persiste nada.
- **Liberação de Corte (`api/papel-liberacao-acao.php`):** grava em `papel_liberacao_corte` (migração `database/migrations/papel/2026-08-26_papel-liberacao-corte.sql`) a liberação por granularidade + chave natural.
- **Inventário de Almoxarifado (`inventario.php`):** Controle rigoroso por localização física (Rua, Prateleira, Caixa), categoria (`papel_tiras`, `kit_bt`, `cabeceiras`), dimensões (espessura, largura, comprimento) e saldos em quilogramas e peças físicas. A edição preserva quantidade, modelo e status quando o formulário não os envia (até 29/09/2026 editar zerava o saldo em unidades).
- **Ficha de Ordem de Corte F-29 (`ordem-corte.php`):** Geração e impressão padronizada da ordem de serviço (sem código de barras). Também aceita um **F-29 avulso** montado por POST a partir da Mesa de Corte.
- **Fila por Máquina (`minha-maquina.php`):** protótipo com 4 máquinas e operadores fixos no código; a fila não considera as liberações de corte e o botão de baixa só mostra um alerta, sem gravar.
- **Catálogo de Peças da Engenharia (`pecas.php` / `peca-form.php`):** listagem com categorização por bloco construtivo (`parte_ativa`, `nucleo`, `enrolamento_at`, `enrolamento_bt`, `mfl`); o formulário só edita `nome_engenharia` e `bloco` (não cria peças). Com o banco fora, `pecas.php`, `peca-form.php` e `maquinas.php` mostram dados fictícios.
- **Controle de Acesso Exclusivo:** O módulo de papel é restrito à credencial autorizada `admin@trael.com.br` através do guardião `hasAcessoPapel()`.

---

## 7. Módulo Linha Pintura (Estação `PIN`)

Para conferir autonomia ao processo de acabamento e tratamento superficial, a estação de **Pintura (`PIN`)** foi desmembrada em um fluxo dedicado, espelhando a robustez do Laboratório e da Inspeção Final, mas comunicando-se com endpoints e tabelas especializadas.

```
pages/pintura/
├── index.php                ← Registro de Entrada (apontamento) da Pintura
├── lista.php                ← Lista de Apontamentos, Histórico & Ação Reprovar
├── retornos.php             ← Fila de Retornos de Retrabalho Específicos de Pintura
├── relacao.php              ← Relação de Retrabalhos Filtrada para o Setor de Pintura
└── paint-check.php          ← Atalho Integrado para o Paint Check Robô
```

### Funcionalidades da Linha de Pintura:
- **Estação `PIN` Nativamente Integrada:** Coluna `estacao` de `producao_etapas` ampliada para aceitar `'PIN'` ao lado de `IQF`, `LAB` e `GER`.
- **Apontamento Operacional (`index.php` & `api/pintura-acao.php`):** Monitoramento de peças em andamento no setor de pintura com cronômetro decorrido e leitor de identificação por código.
- **Fila de Retornos de Pintura (`retornos.php` & `api/pintura-retornos-acao.php`):** Controle de peças que sofreram retrabalho (lixamento, repintura, retoque de serigrafia) e retornam para conferência na cabine de pintura.
- **Reprova a partir do Paint Check:** feita num modal na própria tela do Paint Check, que chama `api/pintura-retornos-acao.php` (`reprovar_pintura`: cria a pendência PIN `aguardando_retorno` no lote ativo) e marca `paint_check_validacoes.status = 'reprovado'`. O antigo redirecionamento para `pages/pintura/relacao.php?abrir=novo&ns=...` não é mais usado por esse fluxo.
- **Catálogo de reprovas da Pintura:** Lista, Retornos e Paint Check filtram por `setor_causador IN ('PINTURA','CALDEIRARIA')`; a Relação filtra por `familia IN ('PINTURA','SERIGRAFIA','CAMADA')` — os dois critérios não coincidem.

---

## 8. Módulo Cronoanálise & SOMA (Sistema Operacional de Manutenção e Apontamento)

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
- **Painel do Operador (`operador.php`):** painel **somente leitura** de eficiência por operador (não é terminal de apontamento).
- **Apontamento pelo Digitador (`digitador.php`):** leitor OCR da folha de apontamento (`assets/js/soma-leitor.js`, com PDF.js + Tesseract) + formulário de **um turno** por vez (produção e paradas), gravado por `api/soma-acao.php`.
- **Paradas (`paradas.php`):** análise de Pareto das paradas. O cadastro de motivos fica em `settings.php`; 33 motivos oficiais são reimpostos pelo código (`somaGarantirTabelas()`) a cada requisição, desfazendo edições de descrição/tipo.
- **Auditoria (`auditoria.php`):** mostra todo o `logs_atividade` do sistema, não só o SOMA. As ações do SOMA são gravadas com `tipo='acao'` e o código entre colchetes no início da descrição.
- **Classificação de produtividade:** por **eficiência × meta do setor** (≥ meta = dentro do padrão; ≥ 75% da meta = desvio moderado; abaixo = crítico). Os dashboards usam faixas fixas de 80% / 60%.
- Até 29/09/2026 **nenhuma gravação do SOMA funcionava**: `api/soma-acao.php` chamava `verificarCsrf()` e `registrarLog()`, que não existiam (agora `validarCsrf()` de `config/session.php` e um `registrarLog()` local). Os gráficos de `index.php`, `paradas.php` e `operador.php` também não apareciam (Chart.js não era carregado).

---

## 9. Módulo de Retrabalho & Gestão Financeira de Custos

O módulo de **Retrabalho** centraliza o controle de peças não-conformes, esteiras de correção fabril, triagem e a valoração financeira dos custos de retrabalho.

```
pages/retrabalho/
├── dashboard.php            ← Dashboard Executivo de Não-Conformidades & Pareto
├── index.php                ← Painel Interativo de Esteiras Fabris
├── relacao.php              ← Tabela Mestre de Retrabalhos & Reincidências
├── custos.php               ← Parâmetros Globais & Catálogo de Tempos por Reprova
├── analise-custos.php       ← Análise Detalhada de Custos por Reprova / Família
├── dashboard-custos.php     ← Dashboard Executivo Financeiro de Retrabalho
├── relatorio.php            ← Relatório Operacional Financeiro Completo
├── detalhe.php              ← Ficha Individual de Triagem & Materiais Gastos
├── acompanhamento.php       ← Acompanhamento de Fila de Retrabalhos
├── confirmar-chegada.php    ← Leitura de Chegada no Setor de Retrabalho
├── iniciar-triagem.php      ← Início da Análise de Causa Raiz
└── historico.php            ← Histórico Geral de Reprovas Finalizadas
```

### 9.1. Ciclo de Vida dos Lançamentos
$$\text{Aguardando Abertura} \longrightarrow \text{Aguardando Causa da Reprova} \longrightarrow \text{Finalizado}$$
- O status é **gravado explicitamente** pelas ações de `api/retrabalho-acao.php` (não é derivado dos campos):
  - `registrar` já grava `agu_causa_raiz`;
  - preencher a causa raiz não finaliza nada;
  - a finalização só acontece em `aprovar_retorno`, com `vai_retrabalho = 0` na reprova ou com `finalizar_direto`.
- Fluxo operacional: confirmar chegada (`confirmar-chegada.php`) → leitura de início (`iniciar-triagem.php`) → triagem com materiais e anexos (`detalhe.php`) → retorno para a estação de origem (`producao_etapas.aguardando_retorno` em LAB/IQF/PIN) → aprovação/reprovação do retorno.
- Reprovas do mesmo ciclo são agrupadas por `retrabalhos.id_lote`; os materiais são lançados por lote.
- `mover_setor` (só admin) muda a peça de esteira; excluir só vale para reprovas de estação `RET`.
- `historico.php` tem um "auto-healing" que, a cada abertura da página, devolve para `agu_causa_raiz` os finalizados de um NS com retorno pendente (pendente de revisão, ver 19).

### 9.2. Engenharia de Custos de Não-Conformidade
- **Parâmetros de Custo de Mão de Obra (`custos.php`):**
  - Custo da Hora-Homem (`custo_hora_homem`, ex.: R$ 45,00/h);
  - Jornada diária padrão (`horas_trabalho_dia`, ex.: 8,80 h).
- **Tempos Padrão por Reprova (`tempo_padrao_minutos`):** Cada código de reprova do catálogo possui seu tempo padrão de reparo estimado em minutos.
- **Valoração de Materiais Aplicados:** cada material lançado na triagem guarda um snapshot `retrabalho_material_uso.preco_medio`, vindo do preço médio de `itens_catalogo`; `retrabalho_materiais_catalogo.custo_unitario` é só fallback por descrição para lançamentos antigos.
- **Onde se editam os parâmetros:** R$/hora-homem e horas/dia no dropdown "Mão de Obra" de `dashboard-custos.php`; `custos.php` edita só os tempos padrão por reprova.
- **Relatórios:** `analise-custos.php` e `dashboard-custos.php` usam `includes/retrabalho-relatorio-dados.php`. O arquivo novo `relatorio.php` duplica essa lógica e ainda não é linkado na sidebar. No consolidado de materiais, a 1ª ocorrência era contada 2× até 29/09/2026.
- **Fórmula de Custo Consolidado:**
  $$\text{Custo Total} = \left(\frac{\text{Tempo Padrão (min)}}{60} \times \text{Custo Hora-Homem}\right) + \sum_{i=1}^{n} (\text{Qtd}_i \times \text{Custo Unitário}_i)$$
- **Segregação de Visualização Financeira (intenção):** Usuários sem permissão `ret.cus` visualizam apenas contagens e tempos operacionais; valores monetários em Reais (R$) são protegidos.
  - ⚠ **Na prática não protege:** `$podeVerValores` aceita `ret.cus` em nível "view", e `getNivelAcesso('ret.cus')` herda de `ret.rel`/`ret.pan` — quem consulta a Relação vê R$. `dashboard.php` mostra o custo total sem checagem e o JSON do drilldown sempre leva os custos (pendente, ver 19).
  - `historico.php` e `acompanhamento.php` exigem o módulo `analise` (chaves `ana.his` / `ana.aco`), não `retrabalho`.

---

## 10. Módulo de Inspeção Final (`pages/inspecao_final/`)

Estação responsável pela auditoria da qualidade do transformador totalmente montado e pintado antes da liberação para expedição.

```
pages/inspecao_final/
├── index.php                ← Registro Operacional de Reprovas na Inspeção Final
├── lista.php                ← Listagem Geral de Apontamentos da Inspeção Final
└── retornos.php             ← Fila de Retornos de Transformadores Corrigidos
```
- Opera sob o código de estação `'IQF'`.
- Possui chaves de permissão dedicadas (`iqf.reg`, `iqf.lis`, `iqf.ret`).

---

## 11. Central de Administração & Regras de Concessionária

A área administrativa centraliza o controle de segurança, estrutura fabril e inteligência do Paint Check.

```
pages/admin/
├── usuarios.php             ← Central Unificada de Usuários, Setores e Perfis (a matriz de perfis é uma aba daqui)
└── concessionaria-regras.php← Gestão de Regras Técnicas do Paint Check
```
(`perfis.php` e `api/admin-acao.php` foram removidos; a API da central é `api/admin-v2-acao.php`.)

### 11.1. Gestão Unificada de Usuários, Setores e Perfis (`usuarios.php`)
- Interface em 3 abas principais com árvore de permissões hierárquica em 3 níveis (**HUB ➔ Módulo ➔ Tela**).
- Ações em lote (Expandir, Recolher, Liberar e Bloquear Todas as Telas).
- Senhas com hash criptográfico seguro `BCRYPT`, controle de e-mails e CPFs. Usuários têm soft delete; **setores e perfis são apagados de fato** (`DELETE`), apesar de terem `deleted_at`.
- Acesso: as telas de `pages/admin/` exigem `requireAcessoModulo('admin')` (chave `admin`, que só os perfis administradores têm); a API `admin-v2-acao.php` aceita também `adm.usu` / `adm.per` (divergência, ver 19). A API de regras de concessionária exige `isAdmin()`.
- O login decide só por `usuarios.status = 'ativo'`; a coluna `usuarios.ativo` não tem efeito hoje.

### 11.2. Regras de Puncionamento & Serigrafia por Concessionária (`concessionaria-regras.php`)
- Cadastro de normas de referência técnica (ex.: ABNT NBR 5440, NBR 5356, normas Copel, Cemig, Energisa, Equatorial, Neoenergia, CPFL, Celesc, Enel, Amazonas, EDP e Mercado Particular).
- **Locais Obrigatórios de Punção:** Definição por concessionária se a peça exige punção na `tampa`, `tanque`, `gancho` ou combinações.
- **Códigos Adicionais & Tombamento:** Configuração de expressões regulares (regex) para conferência de números de patrimônio e código do cliente, além da definição do local (`tampa`, `tanque` ou ambos).
- **Regras de Elo Fusível e Potência:** Indicação de obrigatoriedade e cálculo de valor esperado.
- **Apelidos de Reconhecimento:** Tabela `concessionaria_regras_apelidos` para matching flexível com nomes comerciais oriundos do ERP.
- ⚠ Em 29/09/2026 as tabelas `concessionaria_regras` e `concessionaria_regras_apelidos` **não existem no banco em uso** (`trael_db_dev`): a tela quebra ao abrir e `buscarRegraConcessionaria()` lança exceção. Migrações: `_inicial/migrar-concessionaria-regras.sql`, `-potencia.sql`, `-elo-fusivel.sql` e `migrar-remover-incluir-ano-tampa.sql` (ver 19).

---

## 12. Paint Check 2.0 — Visão Computacional & OCR Industrial Híbrido

O **Paint Check 2.0** (`pages/qualidade/paint-check.php`, `pages/qualidade/paint-check-historico.php` e `paint-check-robo/server.py`) é o sistema de inspeção automatizada de serigrafia e puncionamento mecânico de transformadores.

### 12.1. Arquitetura Operacional em 3 Etapas
1. **Passo 0 (Identificação Prévia):**
   - A identificação do transformador ocorre **antes** de qualquer captura de foto detalhada.
   - O operador realiza a leitura do código de barras da Ordem de Fabricação (`cd_of`) ou digita o Número de Série (com leitor USB escutando buffer global de teclado ou câmera de leitura QR Code via jsQR).
   - O sistema valida a peça instantaneamente contra a tabela `vsat_num_series_previo` e ativa o **Card de Contexto Persistente** (NS, Cliente, Concessionária, Projeto, Pedido Comercial e Potência).
2. **Passo 1 (Fotos Universais Obrigatórias):**
   - Captura das evidências obrigatórias para todas as classes de equipamentos: **Tampa** e **Orelha de Içamento (Gancho)**.
3. **Passo 2 (Fotos Dinâmicas da Concessionária):**
   - O sistema consulta a tabela `concessionaria_regras` e ativa apenas os campos e fotos exigidos por aquela concessionária específica (ex.: código patrimonial no tanque, elo fusível, etc.).

### 12.2. Checklist Granular por Campo
Em vez de uma validação de "tudo ou nada", o backend (`api/paint-check-multi.php`) avalia campo a campo contra os dados esperados do VSAT e classifica:
- **`confirmado` (Badge Verde):** Leitura de alta confiança compatível com o valor oficial do ERP;
- **`divergente` (Badge Vermelho):** Leitura clara pela IA, porém divergente do esperado (distância de Levenshtein $\le 2$), sinalizando **possível erro grave de gravação física**;
- **`ilegivel` (Badge Âmbar):** Foto sem nitidez suficiente, reflexo na tinta fresca ou ângulo inadequado, exigindo conferência visual do operador.
- A distância de Levenshtein só é calculada quando o valor esperado tem 4 ou mais caracteres. O casamento por substring (`checkMatchCampo`) vale nos dois sentidos e sem tamanho mínimo — uma leitura de 1 caractere pode "confirmar" um campo (pendente, ver 19).
- **Identificação sem etiqueta:** o Passo 1 também identifica o transformador por OCR reverso das fotos de tampa + gancho.
- Estados no histórico: Confirmado, Manual, Reprovado e Pendente. Salvar exige que os campos ilegíveis sejam confirmados manualmente.
- Permissões efetivas: a identificação e a validação exigem `pin.pai` ou `tab:pintura`; salvar exige `pin.pai` em nível total.

### 12.3. Validações Especiais: Elo Fusível e Texto Vertical
- **Elo Fusível:** Para a Equatorial, o sistema calcula o valor esperado cruzando a potência do transformador e o número de fases conforme tabela técnica oficial. Para a Amazonas Energia, valida a presença física do componente.
- **Texto Vertical (Patrimônio):** Suporte nativo no OCR a sequências de dígitos empilhadas verticalmente no tanque através de rotações de teste em 90° e 270°.

### 12.4. Persistência de Auditoria e Dataset para Treino
- Toda validação concluída é persistida no banco nas tabelas `paint_check_validacoes` e `paint_check_validacao_campos`.
- O sistema grava o recorte exato da área de puncionamento pareado com o rótulo correto verificado no ERP, formando um dataset contínuo para ciclos periódicos de fine-tuning do modelo PaddleOCR.
- Tela de consulta histórica disponível em `pages/qualidade/paint-check-historico.php`.

---

## 13. Estrutura Completa de Diretórios do Projeto

```
SGT/
├── _inicial/                        ← Migrações incrementais idempotentes (.sql) e scripts CLI
│   ├── database.sql                 ← Schema base do banco de dados MySQL
│   ├── migrar-*.sql                 ← Scripts de migração de versões e módulos
│   └── importar-*.php               ← Importadores de catálogos e planilhas
├── api/                             ← Endpoints assíncronos chamados via fetch/AJAX
│   ├── admin-v2-acao.php
│   ├── atraso-atualizacao.php       ← Status da foto do atraso (contador regressivo)
│   ├── boletim-acao.php / boletim-fluxo.php / boletim-exportar.php
│   ├── concessionaria-regras-acao.php
│   ├── paint-check-identificar.php / paint-check-multi.php / paint-check-salvar-validacao.php
│   ├── papel-inventario-acao.php / papel-liberacao-acao.php / papel-sincronizar-vsat.php
│   ├── pintura-acao.php / pintura-retornos-acao.php
│   ├── producao-acao.php / producao-aderencia-pecas.php / producao-status-pecas-celula.php
│   ├── projetos-acao.php / qualidade-acao.php
│   ├── retrabalho-acao.php / retrabalho-custos-salvar.php / retrabalho-mapa-api.php / retrabalho-relatorio-atualizar.php
│   ├── soma-acao.php
│   └── vsat-num-series-sincronizar.php
├── assets/
│   ├── css/main.css                 ← Design System central, tokens e variáveis CSS
│   ├── css/fluxo-pedidos.css        ← Estilos da matriz de fluxo de pedidos
│   └── js/                          ← Scripts JS vanilla modulares por tela
├── config/
│   ├── conexao.php                  ← Conexões PDO MySQL (getDB) e SQL Server (getSqlServerDB)
│   ├── session.php                  ← Handler de sessões em MySQL e funções RBAC
│   └── versao.php                   ← Versão oficial do sistema (APP_VERSION = '1.4.0')
├── includes/
│   ├── layout.php                   ← Encapsulamento geral (header, sidebar, footer)
│   ├── header.php / sidebar.php / footer.php
│   ├── helpers.php                  ← Helpers globais, datas, dias úteis e calendário de feriados
│   ├── boletim-painel-producao.php   ← Motor de aderência mensal, anual, resumo diário e status
│   ├── boletim-atraso.php           ← Motor de atraso da Distribuição com setor real
│   ├── boletim-atraso-forca.php     ← Motor de atraso de Média Força e Seco
│   ├── boletim-painel-setor.php     ← Métricas e histogramas por célula
│   ├── boletim-fluxo-pedidos.php    ← Árvore recursiva de sub-OFs do SQL Server
│   ├── boletim-acompanhamento.php   ← Motor de convergência Tanque / Parte Ativa
│   ├── boletim-planilha.php         ← Kardex (SQL Server + caches mensais), turno e classificação de linha
│   ├── planilha-plano-mestre.php    ← Plano Mestre (programado por dia/setor, bobinas BT/AT)
│   ├── planilha-ns-of.php           ← Índice NS → OF (NS.OF.xlsx)
│   ├── concessionaria-regras.php    ← Regras de puncionamento por concessionária
│   ├── vsat-num-series.php          ← Leitor de números de série do VSAT
│   ├── soma-helpers.php / soma-subnav.php ← Tabelas e navegação do SOMA
│   ├── modal-detalhes-pecas.php / modal-filtro-data.php ← Modais compartilhados
│   └── retrabalho-relatorio-*.php   ← Parciais de cálculo financeiro de retrabalho
├── pages/
│   ├── producao/                    ← Aderência mensal/anual, resumo diário, status e apontamento
│   ├── distribuicao/                ← Dashboard Indicador Distribuição (TPD)
│   ├── atraso-distribuicao/         ← Dashboard de Atraso Distribuição (MySQL)
│   ├── forca-seco/                  ← Dashboard Indicador Média Força / Seco (TPM/TPS)
│   ├── atraso-media-forca/          ← Dashboard de Atraso Média Força
│   ├── painel-setor/                ← Painel Operacional por Setor Fabril
│   ├── fluxo-pedidos/               ← Rastreabilidade em 10 Células
│   ├── acompanhamento/              ← Convergência Tanque/Parte Ativa
│   ├── papel/                       ← Módulo Setor de Papel, Corte e Almoxarifado
│   ├── pintura/                     ← Linha de Pintura Dedicada (Estação PIN)
│   ├── inspecao_final/              ← Linha de Inspeção Final (Estação IQF)
│   ├── retrabalho/                  ← Gestão de Retrabalhos e Custos Financeiros
│   ├── soma/                        ← Subsistema de Cronoanálise SOMA
│   ├── qualidade/                   ← Tipos de Reprova, Paint Check e Histórico
│   ├── pedidos/                     ← Sequenciamento e Prioridades FIFO
│   ├── projetos/                    ← Cadastro de Apoio de Projetos
│   ├── settings/                    ← Proxy para producao/settings.php
│   └── admin/                       ← Gestão de Usuários, Setores e Regras Paint-Check
├── paint-check-robo/
│   └── server.py                    ← Microsserviço Python de OCR e processamento de imagens
├── scripts/
│   ├── run-tests.ps1                ← Execução de testes automatizados PHPUnit
│   ├── atualizar_atraso.php / .bat / _oculto.vbs          ← Atraso Distribuição+Força: SQL Server -> MySQL (tarefa "SGT - Atualizar Atraso", 15 min)
│   ├── atualizar_fluxo_planilha.php / .bat / _oculto.vbs  ← fluxo_planilha.json + fluxo_encerramentos.json (tarefa "SGT - Atualizar Fluxo Planilha", 1 h)
│   └── sincronizar_vsat_num_series.ps1 / sincronizar_vsat_pcp.ps1 ← Extração do ERP para JSON
├── database/migrations/             ← Migrações .sql por módulo (atraso/, papel/), além das de _inicial/
├── storage/cache/                   ← Caches JSON UTF-8 e snapshots do sistema (gitignored; alguns antigos ainda versionados)
├── storage/logs/                    ← Logs das tarefas agendadas (atraso-atualizar.log, fluxo-planilha-atualizar.log)
├── tests/                           ← Suíte de testes PHPUnit (quebrada em 29/09/2026 — ver seção 2)
│   ├── Includes/ConcessionariaRegrasTest.php
│   ├── Includes/FeriadosTest.php
│   ├── Includes/PlanilhaPlanoMestreTest.php
│   ├── TestCase.php
│   └── bootstrap.php
├── index.php                        ← Hub principal de seleção de sistemas
├── login.php / logout.php           ← Fluxo de autenticação segura
└── phpunit.xml                      ← Configuração oficial da suíte de testes
```

---

## 14. Estrutura de Banco de Dados & Schema Unificado

### 14.1. Infraestrutura, Acesso & Sessão
| Tabela | Descrição |
|---|---|
| `perfis` | Perfis com matriz de permissões em JSON (`perms`). No banco em uso: 1=ADM (Administrador), 2=COORD (Coordenação da Produção), 3=GER (Gerente), 4=INSP (Inspetor Final), 5=OPRET (Operador de Retrabalho), 6=PCPDIST (PCP Distribuição), 7=ADMLAB (Administrativo Laboratório). ⚠ Partes do código ainda assumem o mapa antigo (1=Admin, 2=Planejador, 3=Executor, 4=Dashboard/Visualizador; e IDs 201–212) — ver 19 |
| `usuarios` | Cadastro de colaboradores, CPF, e-mail, senha criptografada (`BCRYPT`), `id_perfil`, `id_setor`, `ativo`, `e_executor` e `deleted_at` |
| `setores` | Setores da fábrica (Bobinagem, Solda, Pintura, Montagem Elétrica, LAB, IQF, etc.) |
| `usuario_acessos` | Exceções de permissão por usuário e tela (`total`, `view`, `off`) |
| `php_sessions` | Sessões persistidas em MySQL (`TraelDbSessionHandler`) para suportar deploys efêmeros |
| `logs_atividade` | Auditoria de acessos (`login_ok`, `login_erro`, `logout`) e operações críticas |

### 14.2. Retrabalho & Gestão Financeira
| Tabela | Descrição |
|---|---|
| `pedidos` | Cadastro de pedidos comerciais com prioridade e sequência FIFO |
| `projetos` | Projetos de engenharia vinculados a pedidos |
| `reprovas` | Catálogo de reprovas com código, descrição, família, local, setor causador e `tempo_padrao_minutos` |
| `retrabalhos` | Lançamentos de não-conformidade: datas de início/fim, causa, setor causador, `estacao` de origem, `id_lote` (ciclo), `setores_destino`, `correcao`, `prioridade`/`sequencia` |
| `retrabalho_anexos` | Evidências anexadas na triagem (`detalhe.php`, campo `anexos[]`) |
| `retrabalho_materiais_catalogo` | Catálogo antigo de materiais com `custo_unitario` (hoje só fallback) |
| `retrabalho_material_uso` | Materiais consumidos no lote, com snapshot de quantidade e `preco_medio` (de `itens_catalogo`) |
| `retrabalho_configuracoes` | Parâmetros globais (`custo_hora_homem`, `horas_trabalho_dia`) |
| `itens_catalogo` | Catálogo geral de materiais importado de `Item.csv` |

### 14.3. Chão de Fábrica (Produção & Pintura)
| Tabela | Descrição |
|---|---|
| `producao_transformadores` | Vínculo do número de série ao projeto e pedido comercial |
| `producao_etapas` | Passagens pelas estações (`estacao` ENUM: `'IQF'`, `'LAB'`, `'GER'`, `'PIN'`), `status` ENUM (`em_andamento`, `aguardando_retorno`, `finalizado`), `metodo_insercao`, e coluna/índice `ns_ativo` (considera `deleted_at`). ⚠ No banco em uso o ENUM de `estacao` ainda não tem `'PIN'` e `metodo_insercao` não existe (ver 19) |

### 14.4. PCP, Metas & Atrasos
| Tabela | Descrição |
|---|---|
| `boletim_config_metas` | Metas mensais (`meta_tpm`, `meta_tpd_distribuicao`, `meta_enrolado`, `meta_convencional`, `meta_jctrif`, `meta_tpd_forca`, `meta_tps`), metas por setor e `dias_customizados` — **um calendário por mês, compartilhado** por Distribuição, Média Força e Painel por Setor |
| `boletim_registros` | Registro diário de metas e produções por área e linha |
| `boletim_equipamentos` / `boletim_potencia_diaria` | Equipamentos e potência diária usados nos indicadores |
| `feriados` | Criada por `migrar-feriados-local.sql`, mas o código lê `boletim_feriados` (inexistente) — ver 4.8 |
| `atraso_distribuicao_registros` | Snapshot do plano mestre de distribuição, uma linha por NS (`num_serie`), com `setor_real`, `tipo_bloqueio` ('CELULA'/'MATERIAL') e `setores_pendentes` |
| `atraso_distribuicao_celulas` | Células pendentes por NS da foto da Distribuição (gargalo do modal) |
| `atraso_historico_diario` | Série diária da média de dias em atraso (gravada pela tela, ver 5.2) |
| `atraso_forca_registros` | Snapshot de atrasos das linhas de Média Força e Seco (DDL só no código, sem `.sql`) |
| `atraso_metas` | Criada pelo código, mas **não é lida nem gravada** por nenhuma tela |
| `vsat_num_series_previo` | Cache sincronizado de números de série do ERP para resolução rápida |

### 14.5. Módulo Setor de Papel
| Tabela | Descrição |
|---|---|
| `papel_maquinas` | Cadastro de máquinas operatrizes do setor de papel (código VSAT, apelido de chão, status) |
| `papel_pecas` | Peças padronizadas da engenharia com bloco construtivo, material e espessura |
| `papel_estoque_inventario` | Almoxarifado físico de papel/papelão (rua, prateleira, caixa, material, espessura, saldos kg/qtd) |
| `papel_aproveitamento_analise` | Análise e aprovação de aproveitamento de sobras contra a demanda do PCP |
| `papel_estoque_movimentacoes` | Auditoria de entradas, saídas, reservas e ajustes de estoque |
| `papel_liberacao_corte` | Liberações de corte por granularidade + chave natural (sem UNIQUE nesse par) |

⚠ Em 29/09/2026 **nenhuma tabela `papel_*` existe no banco em uso** (`trael_db_dev`) — migrações em `database/migrations/papel/` e `_inicial/migrar-papel-inventario.sql` (ver 19).

### 14.6. Regras de Concessionária & Paint Check
| Tabela | Descrição |
|---|---|
| `concessionaria_regras` | Regras técnicas de validação por concessionária (locais obrigatórios, regex, elo fusível, potência) |
| `concessionaria_regras_apelidos` | Apelidos comerciais para matching flexível contra clientes do ERP |
| `paint_check_validacoes` | Cabeçalho das inspeções concluídas pelo robô (NS, cliente, concessionária, status geral) |
| `paint_check_validacao_campos` | Checklist de validação por campo (esperado, lido, status, foto do recorte para dataset) |

### 14.7. Subsistema SOMA (Cronoanálise)
| Tabela | Descrição |
|---|---|
| `soma_empresas` / `soma_setores` | Unidades fabris e setores com metas percentuais de produtividade |
| `soma_operadores` / `soma_maquinas` | Operadores e postos de trabalho cadastrados (o lançamento de turno cria cadastros automaticamente quando não acha por `LIKE`) |
| `soma_paradas_motivos` | Motivos de parada (33 oficiais reimpostos pelo código) |
| `soma_turnos` | Cabeçalho do turno: data, operador, máquina, setor, horários, minutos disponíveis/produzidos/parados e eficiência |
| `soma_registros_producao` | Peças do turno com tempo padrão e tempo total produzido |
| `soma_registros_paradas` | Paradas do turno com motivo e duração |
| `soma_observacoes` | Observações livres do turno |

Durações do SOMA são `DECIMAL(8,2)` em minutos (exceção à regra "minutos em `INT`"). Tabelas criadas/ajustadas em tempo de execução por `somaGarantirTabelas()` (`includes/soma-helpers.php`).

---

## 15. Matriz de Permissões RBAC

| Chave | Módulo / Tela | Descrição |
|---|---|---|
| `hub:producao` | Produção | Acesso geral ao card de Produção no Hub |
| `prod.dis` | Indicador Distribuição | Dashboard de metas e realizado de TPD |
| `prod.atr` | Atraso Distribuição | Dashboard de atraso do Plano Mestre de Distribuição |
| `prod.for` | Indicador Média Força | Dashboard de TPM, TPS e Atraso Força |
| `prod.set` | Painel por Setor & Aderência | Painel por célula, Aderência Mensal, Anual, Resumo Diário e Status Peças |
| `prod.flu` | Fluxo de Pedidos | Matriz de rastreabilidade de pedidos em 10 células |
| `prod.aco` | Acompanhamento | Acompanhamento Tanque / Parte Ativa → Montagem Final |
| `prod.met` | Configurações PCP | Edição de metas mensais e calendário de produção |
| `prod.reg` / `lab.reg` | Registro LAB | Card de apontamento de entrada no Laboratório |
| `prod.lis` / `lab.lis` | Lista Produção | Listagem de passagens e ação Reprovar do LAB |
| `prod.ret` / `lab.ret` | Retornos LAB | Fila de retornos pós-retrabalho do LAB |
| `iqf.reg` | Registro IQF | Card de apontamento de entrada na Inspeção Final |
| `iqf.lis` | Lista IQF | Listagem de registros da Inspeção Final |
| `iqf.ret` | Retornos IQF | Fila de retornos pós-retrabalho da Inspeção Final |
| `pin.reg` | Registro Pintura | Card de apontamento de entrada na Linha de Pintura |
| `pin.lis` | Lista Pintura | Listagem de registros da Linha de Pintura |
| `pin.retornos` | Retornos Pintura | Fila de retornos pós-retrabalho de Pintura |
| `pin.ret` | Relação Pintura | Tabela de retrabalhos filtrada para o setor de Pintura |
| `pin.pai` | Paint Check | Visão computacional e histórico de validações OCR |
| `ret.dash` | Dashboard Retrabalho | Visão executiva de não-conformidades e Pareto |
| `ret.pan` | Painel Retrabalho | Mapa interativo das esteiras fabris |
| `ret.rel` | Relação Retrabalhos | Tabela mestre de retrabalhos e reincidências |
| `ret.cus` | Custos Retrabalho | Acesso e visualização financeira de custos (R$) e parâmetros |
| `pcp.pri` | Prioridades | Sequenciamento e prioridades de pedidos FIFO |
| `adm.usu` | Central Admin | Gestão de usuários, setores, perfis e regras de concessionária |
| `hasAcessoPapel` | Módulo Papel | Restrito exclusivamente à conta `admin@trael.com.br` |
| `qua.tip` | Tipos de Reprova | Catálogo de reprovas; escrita exige nível total (ou admin) — até 29/09/2026 a trava da API checava nomes de ação inexistentes e qualquer consulta editava |
| `ana.his` / `ana.aco` | Histórico / Acompanhamento de retrabalho | Módulo `analise` |
| `admin` | Telas de `pages/admin/` | Chave exigida por `requireAcessoModulo('admin')`; não aparece na árvore de permissões |

**Como as chaves são aplicadas hoje (29/09/2026):**
- `getNivelAcesso()` devolve `total`, `view` ou `off`; `hasAcesso()` = diferente de `off`; `podeEditar()` = `total`. Chaves de tela herdam do módulo quando não há valor próprio (ex.: `ret.cus` herda de `ret.rel`/`ret.pan`).
- As chaves `prod.*` (`prod.dis`, `prod.atr`, `prod.for`, `prod.set`, `prod.flu`, `prod.aco`) **só escondem itens do sidebar e do Hub**: as páginas e APIs de Produção/PCP fazem apenas `requireLogin()`. Idem nas telas do SOMA.
- `prod.met` não é checada pela API de metas (`api/boletim-acao.php`), que libera escrita para qualquer perfil diferente de 4 e 211 — pelo mapa atual, isso bloqueia o **Inspetor Final** e libera todos os outros.
- `api/pintura-retornos-acao.php` não checa `podeEditar` nas ações de escrita.
- Cadastro de Projetos (`pages/projetos/`) usa `requireAcessoModulo('retrabalho')` e não tem chave própria.

---

## 16. Roteiro de Sprints Atualizado

| Sprint | Escopo | Status |
|---|---|---|
| **1 — Fundação & Autenticação** | Banco MySQL unificado, login seguro bcrypt, perfis e persistência de sessões em banco | ✅ Concluída |
| **2 — Hub Central de Sistemas** | Tela "Selecione um sistema" com roteamento dinâmico baseado em RBAC | ✅ Concluída |
| **3 — Módulo de Retrabalho** | Dashboard executivo, mapa fabril, relação mestre e triagem de materiais | ✅ Concluída |
| **4 — Apontamento de Chão de Fábrica** | Card LAB em tempo real, leitor de QR Code (câmera/imagem/manual) e Lista com ação Reprovar | ✅ Concluída |
| **5 — Central Admin & Paint Check** | Gestão unificada em 3 abas (usuários/setores/perfis) + OCR industrial inicial | ✅ Concluída |
| **6 — PCP & SOMA Integrados na Produção** | Dashboards de Distribuição (TPD), Atrasos, Média Força, Painel por Setor, Fluxo de Pedidos, Acompanhamento Tanque/Parte Ativa e Cronoanálise SOMA | ✅ Concluída |
| **7 — Consolidação Fabril & Inteligência Avançada (v1.2.2)** | Módulo Papel (mesa de corte, F-29, inventário), Linha de Pintura dedicada (estação PIN), Atraso Média Força, Paint Check 2.0 (fluxo em 3 etapas, checklist por campo, persistência para fine-tuning), Regras de Concessionária, Custos Financeiros de Retrabalho, Feriados Municipais/Estaduais na Aderência e Resiliência de Deploy (Railway, desativado em 21/09/2026) | ✅ Concluída |
| **7.1 — Automação local (v1.2.3 → v1.3.0)** | Atualização automática do atraso (15 min), produção real por célula via encerramento de sub-OF no ERP, relações Montar Núcleo / Montar Parte Ativa, cache do Fluxo por tarefa agendada (1 h) | ✅ Concluída |
| **7.2 — Auditoria dos módulos de Produção (v1.4.0)** | Distribuição, Média Força, Aderência, Status Peças, Resumo Diário, Painel por Setor, Fluxo de Pedidos e Acompanhamento: regra de Solda (MTP) no Acompanhamento, Média Força no Status de Peças / Aderência Anual e correções da auditoria de 29/09/2026 (seção 19) | ✅ Concluída |
| **8 — Demais Módulos do Hub Corporativo** | Desenvolvimento progressivo dos módulos adicionais previstos (SGE Engenharia, 5S, Ausências, Incidentes, Perdas e Paradas) | 🗓 Planejada |

---

## 17. Glossário Unificado de Termos Fabris

| Termo | Significado |
|---|---|
| **PCP** | Planejamento e Controle da Produção |
| **TPD** | Transformadores de Distribuição (linha padrão até 300 kVA a óleo) |
| **TPM** | Transformadores de Média Força a óleo (> 300 kVA) |
| **TPS** | Transformadores a Seco encapsulados em resina epóxi |
| **OF / cd_of** | Ordem de Fabricação impressa na placa e etiqueta do transformador |
| **Sub-OF** | Ordem de fabricação filha vinculada a uma sub-montagem (Tanque, Parte Ativa, Bobina) |
| **Parte Ativa** | Conjunto mecânico e elétrico formado pelo núcleo de lâminas montado com as bobinas de AT e BT (Montagem Elétrica `ME`/`PA`) |
| **Tanque** | Estrutura metálica de caldeiraria e pintura (`MTQ`) onde a parte ativa é enclausurada e imersa em óleo mineral |
| **Montagem Final (`MFL`)** | Posto de montagem final onde a parte ativa é encaixada no tanque com fechamento de tampa e conexões |
| **Núcleo ENR** | Núcleo enrolado contínuo + transformadores JC monofásicos/bifásicos |
| **Núcleo JC-TRIF** | Núcleo modelo JC exclusivamente trifásico |
| **Núcleo EMP** | Núcleo empilhado convencional de lâminas de aço silício |
| **IQF** | Inspeção da Qualidade Final (estação antes do despacho) |
| **LAB** | Laboratório de Ensaios Elétricos de Alta Tensão |
| **PIN** | Estação de Pintura e Tratamento de Superfície |
| **F-29** | Ficha padrão de Ordem de Corte do Setor de Papel |
| **SOMA** | Sistema Operacional de Manutenção e Apontamento (Cronoanálise e Tempos) |
| **Turno Fabril** | Período de 07:30 às 02:48 (-450 min de deslocamento para apontamentos da madrugada) |
| **FIFO** | First In, First Out — Regra de priorização onde ordens e peças mais antigas têm precedência |
| **piAudit** | Tabela de auditoria do ERP PierServer para rastreio exato do horário de movimentações |

---

## 18. Controle de Versão & Qualidade Contínua

- **Versão Global do Sistema:** Gerenciada em `config/versao.php` (`APP_VERSION = '1.4.0'`).
- **Padrão de Mensagens de Commit:**
  - `feat(modulo): vX.Y.Z — <descrição da funcionalidade>`
  - `fix(modulo): vX.Y.Z — <descrição da correção>`
- **Suíte de Testes Automatizados:**
  - Execução local via PowerShell: `.\scripts\run-tests.ps1`
  - Configuração XML: `phpunit.xml`
  - Cobertura obrigatória para cálculos de datas, dias úteis, feriados, regras de concessionária e consistência de schemas.
- **Auditoria de Código & Linhas de Programação:**
  - Toda modificação em arquivos de backend deve passar por validação estrita de sintaxe (`php -l`) e respeitar a tipagem estrita `declare(strict_types=1)`.
  - Não seguem ainda: `config/versao.php`, `includes/header.php`, `includes/sidebar.php` e `includes/modal-*.php` estão sem `declare(strict_types=1)`.

---

## 19. Auditoria Geral de 29/09/2026 — Pendências Abertas

Revisão do projeto inteiro, módulo a módulo, contra o código e o banco em uso (`trael_db_dev`, o do `.env` que o Apache serve). As correções locais e sem mudança de regra já foram aplicadas no código (resumo em 19.5). Abaixo, o que **depende de decisão**, por prioridade.

### 19.1. Banco em uso com migrações pendentes (crítico)
O `trael_db_dev` não tem vários objetos que o código usa. As telas afetadas quebram com erro 500 ou mostram vazio. Migrações existentes, **ainda não aplicadas**:

| Objeto ausente | Efeito | Migração |
|---|---|---|
| `'PIN'` no ENUM `producao_etapas.estacao` | apontamento e retornos da Pintura falham | `_inicial/migrar-pintura-estacao-pin.sql` |
| `producao_etapas.metodo_insercao` | registro de entrada | `_inicial/migrar-producao-metodo-insercao.sql` |
| `retrabalhos.estacao` | `registrar`, mapa, Relação e relatórios de retrabalho | `_inicial/migrar-retrabalho-estacao-origem.sql` |
| `retrabalhos.prioridade` / `sequencia` | tela de Prioridades | **sem `.sql`** (só no dump antigo do Railway) — criar |
| `reprovas.vai_retrabalho` | roteamento de reprova sem retrabalho | `_inicial/migrar-reprovas-vai-retrabalho.sql` |
| `itens_catalogo` + colunas `codigo`/`descricao`/`unidade` em `retrabalho_material_uso` | materiais da triagem | `_inicial/migrar-itens-catalogo.sql`, `migrar-retrabalho-materiais-catalogo.sql`, depois `importar-itens-catalogo.php` |
| `concessionaria_regras` / `_apelidos` | tela de regras e Paint Check | `_inicial/migrar-concessionaria-regras*.sql`, `migrar-remover-incluir-ano-tampa.sql` |
| `paint_check_validacoes` / `_campos` | salvar e histórico do Paint Check | `_inicial/migrar-paint-check-validacoes.sql`, `-status-reprovado.sql`, `-config.sql` |
| `vsat_num_series_previo` | identificação do Paint Check | `_inicial/migrar-vsat-num-series.sql` |
| todas as `papel_*` | módulo Papel inteiro | `database/migrations/papel/*.sql`, `_inicial/migrar-papel-inventario.sql` |

A ordem entre elas e a conferência de cada uma contra o banco ficam para quando forem aplicadas (rodar à mão, como manda a seção 3). Enquanto isso, vários módulos criam/alteram tabelas sozinhos em tempo de execução (`getDB()` com o marcador `.schema_verificado`, `somaGarantirTabelas()`, atraso, retrabalho, custos) — contra a regra de migração manual e, no retrabalho, dentro de transação (commit implícito).

### 19.2. Segurança
- **Credenciais do SQL Server no código versionado:** `PROJETO-SGT/ATUALIZAR_ATRASO.py` (usuário `bi_consulta` + senha, no git desde o commit 861c0d9) e `scripts/sincronizar_vsat_num_series.ps1`. Trocar a senha e passar a ler de `.env`; tirar do arquivo não apaga do histórico.
- **Guard de perfil ausente:** telas e APIs de Produção/PCP e do SOMA só exigem login (ver 15) — qualquer perfil logado abre por URL, inclusive via ngrok.
- **Metas editáveis por qualquer perfil** (`api/boletim-acao.php:27`, regra "≠ 4 e ≠ 211"); trocar por `podeEditar('prod.met')`.
- **Admin:** `adm.usu` não abre as telas, mas a API aceita — quem tem `adm.usu` pode chamar `admin-v2-acao.php` direto e criar perfil com tudo `total`. Sem CSRF nessa API (só `SameSite=Strict`).
- **Mapa de perfis obsoleto** em `config/session.php` e `index.php` (IDs 201–212 e 1=Admin/2=Planejador/3=Executor): `requirePerfil([1,2,3])` hoje libera ADM, Coordenação e Gerente.
- **Custos em R$ visíveis sem `ret.cus`** (ver 9.2).
- **`api/pintura-retornos-acao.php`** sem checagem de escrita.
- **JSONs do Papel públicos:** `pages/papel/dados_pcp_*.json` e `inventario_importado.json` são servidos direto pelo Apache (sem `hasAcessoPapel`) — mover para `storage/` ou negar por `.htaccess`.
- **`?refresh=1` no Atraso Média Força:** GET sem botão que reextrai e sobrescreve a foto de qualquer data, sem trava do script agendado.
- Sem CSRF também no módulo Papel e no login; logout por GET; cookie de sessão sem `Secure` atrás do ngrok (o Apache recebe HTTP).
- Restam `innerHTML` com dados do ERP sem escape em telas não corrigidas (modais do atraso, `programacao.js`, `mesa-de-corte.js`, `inventario.php`, partes do `fluxo-setor.js` e do Paint Check).

### 19.3. Regras de negócio e números
- **Painel por Setor soma a Média Força** nas barras MF/ME/PIN/BOB (5.7). Correção prevista: filtrar `empresa = '1'` em `boletimObterProducaoRealFluxoCelulas()` — muda os números visíveis.
- **Cache vencido mostra 0 como real** no Painel por Setor (5.7); o mesmo para `acompanhamento.json` / `fluxo_pedidos.json` congelados (seção 2). Decidir: aviso "dados até dd/mm HH:mm" ou estimativa 🔶.
- **Aderência Mensal / Resumo Diário:** realizado por setor não segue a métrica "sub-OF encerrada"; BT/AT comparam bobinas × transformadores (ex.: 03–07/08: BT 2.454 × 843, AT 2.454 × 189); KPI "Aderência Anual" fixo em 102,55% (5.5).
- **Deslocamento de turno (−450 min) aplicado a `dt_Movimento` sem hora** quando não há piAudit (`boletim-planilha.php`): a data recua um dia (jul/2026: 1.406 de 4.676 peças; 150 caíram em 30/06). Aplicar só com auditoria e regerar os caches.
- **Fim de semana na virada do mês** vai para a sexta do mês anterior mas fica no cache do mês novo — some das duas somas.
- **Modais de peças da Aderência** filtram por `data_mov` (sem turno nem fim de semana → sexta) e não batem com a barra do dia.
- **Atraso:** janela "programação < hoje" implementada de dois jeitos (`DATEADD(day,-1,GETDATE())` × `DATEADD(day,-1,'Y-m-d')`); denominador da média diária inclui hoje na Distribuição e não na Média Força; nenhum desconta feriados; Média Força soma o lote inteiro (`Quantidade`) em vez do saldo (`QtdAproduzir`).
- **Feriados:** segunda de Carnaval ausente; `boletim_feriados` × `feriados`; `boletimDiasUteisDoMes()` ignora feriados (4.8).
- **Retrabalho:** materiais de um lote multiplicados pelo nº de reprovas do lote nos relatórios; roteamento automático para Pintura (famílias PINTURA/SERIGRAFIA/CAMADA) sobrescrito logo após o INSERT; "auto-healing" do `historico.php` reabre ciclos antigos a cada GET; `aguardando_retorno` criado em LAB/IQF mesmo sem "Laboratório" marcado; `confirmar_chegada` grava a chegada só no id clicado do lote.
- **Paint Check:** casamento por substring sem tamanho mínimo (leitura de 1 caractere confirma; esperado vazio sempre casa; no modo identificar pode pegar o transformador errado).
- **Papel:** aprovar aproveitamento duas vezes desconta o saldo duas vezes; rejeitar depois de aprovar não devolve; `peca_idx` instável; telas simuladas que dizem "sucesso" sem gravar (`apontamento.php`, `minha-maquina.php`, Corte Extra).
- **SOMA:** operador/máquina resolvidos por `LIKE '%texto%'` e criados automaticamente; turno sem parada não pode ser salvo; o seletor "período" sobrescreve as datas manuais; produtividade conta as paradas duas vezes.
- **Follow-ups do SAC** sem fonte (`planilhas/Dados.csv` ausente) — ENGENHARIA sempre vazia no Fluxo (5.9).
- **Intervalos que cruzam meses** usam só o mês de `data_inicio` (Distribuição, Média Força, Painel por Setor); ano 2026 fixo na Aderência Anual e em `FLUXO_ANO_BASE`.

### 19.4. Técnico / manutenção
- Suíte de testes quebrada (seção 2).
- Código morto herdado do GFT em `includes/helpers.php` (`calcularPrazoLMC`, `gftEngenharia*`, `calcularSegmentosPHP` etc.) e em `config/session.php` (`requirePodeEditar`, `validarCsrfToken`).
- `scripts/*.vbs` não repassam o código de saída do `.bat` — o Agendador sempre registra sucesso; `atualizar_fluxo_planilha.php` grava o cache mesmo com extração vazia.
- Defaults `mysql.railway.internal` em `config/conexao.php` e card do SGE apontando para uma URL do Railway em `index.php`.
- UNIQUE que inclui registros soft-deleted (`usuarios.email`, `concessionaria_regras.nome_grupo`, códigos do SOMA) → recriar dá erro 500 com a mensagem SQL crua.

### 19.5. Correções aplicadas nesta auditoria (resumo)
- **SOMA:** API voltou a gravar (`validarCsrf`, `registrarLog`); Chart.js nas telas; XSS e botões de excluir com apóstrofo; paginação da auditoria.
- **Papel:** INSERT de rejeição de aproveitamento (faltava um `?`); edição de estoque não zera mais qtd/modelo/status; `desativado_em` invertido em Máquinas; botão Sincronizar VSAT; badge após aprovar/rejeitar; filtros `deleted_at`.
- **Produção/Aderência:** HY093 no filtro de Linha do Status de Peças e na API da célula; bobinas trifásicas (`TRI`) contadas como 3; Resumo Diário da Média Força (`tabela_forca`); XSS e links com `$base`.
- **Distribuição / Média Força / Prioridades:** meta diária e calendário no intervalo personalizado; reprova LAB não sobrescreve mais a produção da linha na Média Força; página de Prioridades não cai com NS nulo; Árvore × Lista Direta sincronizadas; gráfico destruído no modal de peças.
- **Painel por Setor / Fluxo / Acompanhamento:** busca do mapa; escape nos modais e na impressão; `onclick` com JSON trocado por `data-*`; `JSON_INVALID_UTF8_SUBSTITUTE` no Acompanhamento; chaves ausentes no retorno vazio do Fluxo.
- **Atraso:** histórico diário só com foto atual e sem filtro; `rollBack` em `Throwable` na Média Força; link com `$base`.
- **Retrabalho:** `mover_setor` gravava status inexistente; mapa exigia só login e pegava a etapa mais antiga; polling do mapa em loop de 1 s após erro; anexos da triagem nunca enviados (`name="anexos[]"`); materiais contados 2× e sumindo sem `id_lote`; HY093 na busca do relatório; XSS nos drilldowns; datas UTC.
- **Pintura / Qualidade / Produção:** trava de escrita do catálogo de reprovas; `onclick` do código da reprova; ordenação por Descrição; data da reprova em UTC; erros do leitor USB visíveis; fotos do Paint Check preservadas; escape no OCR; recortes validados como imagem; `</span>` faltando.
- **Núcleo / Admin:** sidebar do Atraso Média Força; XSS nas mensagens de exclusão (usuários, setores, perfis, regras); `?datas_especificas=` e "Últimos N dias" em UTC no filtro de datas; crases em `status`.
