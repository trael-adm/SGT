# PROJETO SGT — Sistema de Gestão Trael (com PCP, SOMA, Papel, Pintura & Qualidade Integrados)

> **Documento vivo e oficial de referência arquitetural, regras de negócio, engenharia de software e especificações técnicas.**  
> **Versão Oficial do Sistema:** `v1.2.3`  
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
- **Resiliência de Dados & Deploy Híbrido no Railway:**
  - **Sessões Persistidas em Banco:** Classe `TraelDbSessionHandler` implementando `SessionHandlerInterface` gravando na tabela `php_sessions`, garantindo persistência de logins em ambientes de containers efêmeros.
  - **Caches Universais em JSON UTF-8 (`storage/cache/`):** Arquivos consolidados e sanitizados (`kardex_mes_*.json`, `atraso_ns_suplementar.json`, caches de fluxo de pedidos e acompanhamento) que permitem à aplicação em produção no Railway renderizar dashboards completos mesmo sem conexão direta via VPN ao SQL Server da fábrica.
  - **Script e Endpoint de Sincronização Segura:** Script `scripts/sincronizar_producao_railway.php` que lê dados consolidados do SQL Server local e realiza push autenticado para o endpoint `api/sync-boletim.php` na nuvem através de token seguro `BOLETIM_SYNC_TOKEN`.
- **Planilhas-Ponte & Leitura Server-Side via `PharData`:**
  - `PLANILHA QUE ATUALIZA/NS.OF.xlsx`: Índice de Ordens de Fabricação (~4,8 MB, atualizado por Power Query, lido por `includes/planilha-ns-of.php`).
  - `PLANILHA Q ATUALIZA/Relação Kardex.xlsx`: Histórico de movimentações de produção (~85 MB, 38 colunas, abas `dw vw_kardex_lotes` e `dw vw_ficha_espc_trafo`, lido por `includes/boletim-planilha.php`).
  - `PLANILHA QUE ATUALIZA/Item.csv`: Catálogo oficial de materiais (~23,7 mil itens, importado para `itens_catalogo` por `_inicial/importar-itens-catalogo.php`).
  - `vsat_num_series_previo`: Cache local populado diretamente do VSAT via PowerShell (`scripts/sincronizar_vsat_num_series.ps1`), reduzindo a dependência de planilhas locais para identificação imediata no Paint Check.

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
- O cálculo de dias úteis e de produção (`boletimDiasUteisDoMes()`, `boletimObterFeriadosAno()`) integra de forma nativa:
  - **Feriados Nacionais Fixos:** 01/01, 21/04, 01/05, 07/09, 12/10, 02/11, 15/11, 20/11 (Consciência Negra) e 25/12;
  - **Feriados Móveis Oficiais:** Segunda e Terça de Carnaval, Sexta-feira Santa e Corpus Christi;
  - **Feriados Estaduais de Mato Grosso & Municipais de Cuiabá:** 08/04 (Aniversário de Cuiabá) e 08/12 (Nossa Senhora da Conceição).
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
- **Painel Superior de Indicadores (KPIs):** Meta do Mês, Realizado Acumulado, Saldo Restante, % Atingido, Média Diária Realizada e Ritmo Diário Necessário para bater a meta.
- **Gráficos Interativos (Chart.js):**
  - **Produção Diária por Núcleo:** Barras empilhadas (`ENR`, `JC-TRIF`, `EMP`) com linha de Meta Diária sobreposta.
  - **Mix de Produção:** Comparativo Donut/Pizza entre Mix Programado e Mix Realizado.
  - **Produção Acumulada:** Curva de área da produção realizada contra a curva linear da meta mensal.
- **Painel "Produção por Linha":** Tabela analítica por núcleo com 4 linhas de métricas (`Executado`, `Meta`, `Diferença`, `% Executado`) e colunas de `MÉDIA` e `SOMA`.
- **Exportação & Impressão A4 Paisagem:** Botão de impressão com layout landscape otimizado sem quebra de página.
- **Modal de Métricas & Calendário Interativo (`#modal-metricas`):** Definição de metas diárias por núcleo e marcação de dias úteis, pontes e feriados, recalculando a meta mensal na tabela `boletim_config_metas`.

### 5.2. Dashboard Atraso Distribuição (`pages/atraso-distribuicao/index.php`)
- Persistência nativa em MySQL através da tabela `atraso_distribuicao_registros`, dispensando a leitura em tempo de execução de arquivos externos pesados.
- Cruzamento diário entre o snapshot das peças previstas no Plano Mestre e os apontamentos de conclusão no Laboratório (Kardex / SQL Server).
- Indicadores de atraso segmentados por família de núcleo (Monofásico, Convencional, JC-TRIF em quantidade de peças e dias médios de atraso).
- Visualização e filtro por **Gargalo Real (`setor_real`)** e **Tipo de Bloqueio (`tipo_bloqueio`)**, permitindo ao PCP identificar exatamente em qual célula fabril o equipamento está retido.
- Cálculo da Média Geral Simples de atraso e gráfico de evolução temporal diária.
- **Atualização automática do atraso (Distribuição e Média Força):** o painel lê a "foto" do MySQL (`atraso_distribuicao_registros` / `atraso_forca_registros`), extraída direto do SQL Server pelo `scripts/atualizar_atraso.php` (chamado por `scripts/atualizar_atraso.bat`). Uma tarefa do Agendador do Windows (`SGT - Atualizar Atraso`) o executa a cada **15 minutos** nesta máquina; a extração da Distribuição leva ~30 s (ERP ~3 s + classificação de gargalo ~25 s + gravação ~2 s; a 1ª execução a frio chegou a 108 s) e a da Média Força ~3 s. Regras:
  - A foto do **dia** é substituída no lugar (`forcarSobrescrita`); dias anteriores nunca são tocados, então a última atualização de cada dia é o fechamento dele. `atraso_historico_diario` continua sendo gravado no próprio dia.
  - `DELETE` + `INSERT` na mesma transação (a tela nunca vê a foto do dia vazia no meio da troca).
  - Extração vazia (ou sem a classificação de gargalo, na Distribuição) **não** sobrescreve a foto anterior do dia.
  - Sem execução concorrente (trava em `storage/cache/atualizar_atraso.lock`); log em `storage/logs/atraso-atualizar.log`.
  - A tela mostra, sob a data de referência, um **contador regressivo** "Próxima atualização em mm:ss m" (`boletimHtmlAtualizacaoAtraso()` + `assets/js/atraso-atualizacao.js`; o servidor manda o tempo restante e o navegador só conta a diferença). Ao zerar vira "Atualizando…" (âmbar) e consulta `api/atraso-atualizacao.php` a cada 10 s; quando a foto nova chega, recarrega a tela (mantém os filtros da URL e espera fechar qualquer modal `.modal-fluxo-overlay` aberto). Sem foto nova 5 min depois do previsto (tarefa agendada parada) vira o alerta vermelho "⚠ Atualização atrasada — última em dd/mm HH:MM". Foto antiga escolhida de propósito mostra só "Foto gravada em dd/mm HH:MM", sem contador. O intervalo (`ATRASO_INTERVALO_ATUALIZACAO_S` = 900 em `includes/boletim-atraso.php`) precisa ser igual ao gatilho da tarefa.
  - Contexto (2026-09-21): a foto estava parada em 09/09 porque o sincronizador antigo (`scripts/sincronizar_producao.bat`) apontava para o PHP do perfil de outro usuário do Windows e nada o agendava aqui. O atraso "real" (data de programação < hoje, OFs `AGU`/`RES`) bate com o Plano Mestre do VSAT; as peças de hoje não contam como atraso.

### 5.3. Dashboard Indicador Média Força / Seco (`pages/forca-seco/index.php`)
- Acompanhamento das linhas **TPM (Média Força)** e **TPS (Transformadores a Seco)**.
- Exibição de Meta Mensal, Realizado Acumulado, Saldo a Produzir e % de Eficiência.
- Gráfico de barras diárias com linha de meta sobreposta e série condicional de TPD produzido na linha de força (`cdEnt=4`).

### 5.4. Dashboard Atraso Média Força (`pages/atraso-media-forca/index.php`)
- Monitoramento de backlog e capacidade diária segmentado pelas 3 linhas de força:
  - `TPD` ($\le 300\text{ kVA}$);
  - `TPM` ($> 300\text{ kVA}$);
  - `TPS` (Seco).
- Distribuição mensal e semanal com drilldown, série temporal de 15 dias de evolução de atraso e relação analítica de ordens e números de série no chão.
- Motor de cálculo em `includes/boletim-atraso-forca.php` com suporte a script Python de extração (`PROJETO-SGT/ATUALIZAR_ATRASO.py`) e tabelas `atraso_forca_registros`.

### 5.5. Aderência Mensal & Anual da Produção (`pages/producao/aderencia-mensal.php` & `aderencia-anual.php`)
- **9 KPIs Executivos de Desempenho Fabril:**
  1. Meta do Mês (base calendário);
  2. Meta Ajustada (considerando capacidade proporcional e dias decorridos);
  3. Realizado Acumulado;
  4. % de Atingimento da Meta Global;
  5. % de Aderência Operacional;
  6. Saldo Restante de Produção;
  7. Média Diária Realizada;
  8. Ritmo Diário Necessário para bater a meta;
  9. Saldo de Dias Úteis Restantes.
- **Alternância Multi-Planta:** Suporte completo à Empresa 1 (Distribuição - ENR/EMP/JC) e Empresa 4 (Média Força - TPD/TPM/TPS).
- **Integração com Calendário Oficial de Feriados:** Feriados municipais de Cuiabá, estaduais de MT e nacionais expurgados automaticamente da evolução diária quando não há expediente fabril.
- **Aderência Anual:** Comparativo plurianual de produção mês a mês com gráficos de barras empilhadas e histórico de atingimento.

### 5.6. Resumo Diário & Status de Peças (`pages/producao/resumo-diario.php` & `status-pecas.php`)
- **Resumo Diário:** Exibição simultânea lado a lado das 10 células fabris (Chassi, Bobinagem BT, Bobinagem AT, Corte Núcleo, Solda, Montagem Núcleo, Pintura, Parte Ativa, Montagem Final e Laboratório) com volumes apontados e status de cumprimento diário.
- **Status de Peças:** Rastreabilidade consolidada de OFs apontadas e em aberto distribuídas por setor fabril, com busca rápida por número de série, projeto ou pedido comercial.

### 5.7. Painel por Setor (`pages/painel-setor/index.php`)
- Visão operacional detalhada por célula fabril com ordenação lógica do fluxo produtivo: Laboratório ➔ Montagem Final ➔ Montagem Elétrica ➔ Pintura ➔ Solda ➔ Montagem Núcleo ➔ Corte CNC ➔ Bobinagens.
- **Medidor Velocímetro (Gauge) de Eficiência (%):** Eficiência em tempo real da célula contra a meta estabelecida.
- **Métricas Operacionais:** Meta do Dia, Produção Total, Saldo Restante, Fila Acumulada em Espera e análise de gargalos.
- **Modal de Métricas e Metas Individualizadas:** Edição de metas personalizadas por setor para o mês vigente.
- **Gráfico "Produção vs. Programado por Setor Fabril" — fonte do "Produzido" por barra:**
  - **Laboratório (LAB):** apontamento de chão de fábrica (`producao_etapas`, estação `LAB`); se vazio, entradas do Kardex.
  - **Montagem Final (MFL), Montagem Elétrica (ME) e Bobinagem (BOB):** célula real do Fluxo de Pedidos (OK = finalizada), via `boletimObterProducaoRealFluxoCelulas()`.
  - **Pintura/Tanque (MTQ):** apontamento próprio (`producao_etapas`, estação `PIN`) quando há registro no período; **senão** a célula `PIN` (sub-OF `MTQ` concluída) do Fluxo de Pedidos. Motivo: a Pintura é apontada no ERP e `producao_etapas` estava vazia no banco local, deixando a barra sem valor. A mesma precedência vale na visão por núcleo (ENR / JC-TRIF / EMP).
  - Estimativa por fator (marcada com 🔶) só se o SQL Server do Fluxo estiver indisponível.

### 5.8. Acompanhamento Tanque / Parte Ativa → Montagem Final (`pages/acompanhamento/index.php`)
- Rastreamento da convergência das 2 sub-montagens cruciais que alimentam a Montagem Final (restrito à **Empresa 1**):
  - **Pintura (`MTQ`):** Status de conclusão do Tanque;
  - **Montagem Elétrica (`ME-` / `PA-`):** Status de conclusão da Parte Ativa;
  - **Montagem Final (`MFL`):** Montagem Final do equipamento.
- **Matriz de Status e Badges de Ação:**
  - `Pintura OK + Montagem Elétrica OK` ➔ **"DESCER PARA MONTAGEM FINAL"** (Badge Verde);
  - `Montagem Elétrica OK + Pintura Pendente` ➔ **"PINTAR TANQUE"** (Badge Âmbar);
  - `Pintura OK + Montagem Elétrica Pendente` ➔ **"GUARDAR NA ESTUFA"** (Badge Azul);
  - `Montagem Final OK com etapas anteriores pendentes` ➔ **"VERIFICAR APONTAMENTO"** (Badge Vermelho — Alerta de Inconsistência).

### 5.9. Rastreabilidade & Fluxo de Pedidos (`pages/fluxo-pedidos/index.php`)
- Decomposição hierárquica recursiva de até **5 níveis de sub-OFs** no SQL Server (`dbo.RlcProgramacao`).
- Visão matricial em 10 células fabris: Chassi (`CH`), Bobinagem BT (`BT`), Bobinagem AT (`AT`), Corte CNC (`CNC`), Solda/Tanque (`SOL`), Montagem Núcleo (`MN`), Pintura (`PIN`), Montagem Elétrica (`ME`), Montagem Final (`MF`), Laboratório (`LAB`).
- Filtros por período, semana, mês, número do pedido, código do projeto e número de série com drill-down instantâneo para a lista de peças.

### 5.10. Apontamento de Chão de Fábrica & Lista de Registros (`pages/producao/index.php` e `lista.php`)
- Card interativo da estação (LAB/IQF/GER) com exibição do transformador em processo e cronômetro decorrido em tempo real atualizado a cada segundo via JavaScript.
- Leitor de QR Code modal em tela cheia com alternância dinâmica para upload de imagem e digitação manual de número de série.
- **Ação Reprovar:** Abertura de modal com Pedido, Projeto e N° de Série travados e blocos repetíveis de reprova do catálogo oficial. Ao confirmar, gera os lançamentos na tabela `retrabalhos` e conclui a etapa produtiva atual.

---

## 6. Módulo Setor de Papel (Corte de Isolamento Elétrico & Chaparia)

O **Módulo de Papel** gerencia todo o processo de preparação, corte, aproveitamento de sobras e programação dos materiais isolantes utilizados nos transformadores (papel Kraft, Presspan, Diamond Dot / Diamantado, etc.).

```
pages/papel/
├── mesa-de-corte.php        ← Terminal Operacional da Mesa de Corte & Aproveitamento
├── programacao.php          ← Programação de Corte da Demanda do PCP
├── inventario.php           ← Gestão e Inventário Físico do Almoxarifado de Papel
├── ordem-corte.php          ← Ficha de Ordem de Corte (F-29) para Produção
├── apontamento.php          ← Apontamento Operacional do Chão de Fábrica
├── minha-maquina.php        ← Fila de Trabalho Exclusiva por Máquina Operatriz
├── maquinas.php             ← Cadastro e Gestão de Máquinas Operatrizes
├── pecas.php                ← Catálogo de Peças Padronizadas da Engenharia
├── peca-form.php            ← Cadastro / Edição de Peças e Dimensões
└── sinonimia-print.php      ← Ficha de Sinonímias e Equivalências de Peças
```

### Funcionalidades do Módulo Papel:
- **Terminal Mesa de Corte (`mesa-de-corte.php`):** Interface otimizada com seletor superior de máquinas cadastradas, visualização das demandas do PCP, chips de status, modal interativo de match com estoque existente e rodapé com banner de rastreabilidade do ERP.
- **Programação de Demanda (`programacao.php`):** Cruzamento das ordens programadas com o cálculo de massa unitária (`calcMassaUnitJs`) e detecção automática de sobras ou materiais disponíveis no almoxarifado.
- **Inventário de Almoxarifado (`inventario.php`):** Controle rigoroso por localização física (Rua, Prateleira, Caixa), categoria (`papel_tiras`, `kit_bt`, `cabeceiras`), dimensões (espessura, largura, comprimento) e saldos em quilogramas e peças físicas.
- **Ficha de Ordem de Corte F-29 (`ordem-corte.php`):** Geração e impressão padronizada da ordem de serviço com código de barras para liberação fabril.
- **Fila por Máquina (`minha-maquina.php`):** Interface de chão de fábrica direcionada para o operador de cada posto (Slitter, Guilhotinas Manuais e Motorizadas, Dobradeiras de Papel, Sanfonadeiras, Mesas de Colagem de Talisca).
- **Catálogo de Peças da Engenharia (`pecas.php`):** Cadastro com categorização por bloco construtivo (`parte_ativa`, `nucleo`, `enrolamento_at`, `enrolamento_bt`, `mfl`), código de matéria-prima e amarração ao VSAT.
- **Controle de Acesso Exclusivo:** O módulo de papel é restrito à credencial autorizada `admin@trael.com.br` através do guardião `hasAcessoPapel()`.

---

## 7. Módulo Linha Pintura (Estação `PIN`)

Para conferir autonomia ao processo de acabamento e tratamento superficial, a estação de **Pintura (`PIN`)** foi desmembrada em um fluxo dedicado, espelhando a robustez do Laboratório e da Inspeção Final, mas comunicando-se com endpoints e tabelas especializadas.

```
pages/pintura/
├── index.php                ← Registro de Reprova em Tempo Real da Pintura
├── lista.php                ← Lista de Apontamentos & Histórico da Linha de Pintura
├── retornos.php             ← Fila de Retornos de Retrabalho Específicos de Pintura
├── relacao.php              ← Relação de Retrabalhos Filtrada para o Setor de Pintura
└── paint-check.php          ← Atalho Integrado para o Paint Check Robô
```

### Funcionalidades da Linha de Pintura:
- **Estação `PIN` Nativamente Integrada:** Coluna `estacao` de `producao_etapas` ampliada para aceitar `'PIN'` ao lado de `IQF`, `LAB` e `GER`.
- **Apontamento Operacional (`index.php` & `api/pintura-acao.php`):** Monitoramento de peças em andamento no setor de pintura com cronômetro decorrido e leitor de identificação por código.
- **Fila de Retornos de Pintura (`retornos.php` & `api/pintura-retornos-acao.php`):** Controle de peças que sofreram retrabalho (lixamento, repintura, retoque de serigrafia) e retornam para conferência na cabine de pintura.
- **Abertura Ágil Pré-Preenchida:** Ao registrar uma reprova a partir do Paint Check, o operador é direcionado automaticamente para `pages/pintura/relacao.php?abrir=novo&ns=...&projeto=...` com os dados da peça já preenchidos no formulário.

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
- **Terminal do Operador (`operador.php`):** Interface para tablets com botões de Iniciar Ciclo, Finalizar Ciclo e Registrar Parada (`assets/js/soma-leitor.js`).
- **Apontamento pelo Digitador (`digitador.php`):** Digitação acelerada por teclado de múltiplos apontamentos com cálculo automático de tempos decorridos.
- **Gestão de Paradas (`paradas.php`):** Cadastro de motivos de parada (Falta de Material, Manutenção Mecânica/Elétrica, Ajuste de Processo, Troca de Ferramental) com impacto no OEE da célula.
- **Classificação Industrial de Produtividade:**
  - `[DENTRO DO PADRÃO]` — Badge Verde (tempo executado $\le$ tempo padrão);
  - `[DESVIO MODERADO]` — Badge Âmbar (tempo executado até +20% do padrão);
  - `[GARGALO CRÍTICO]` — Badge Vermelho (tempo executado $> 20\%$ acima do padrão).

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

### 9.1. Ciclo de Vida Automático dos Lançamentos
$$\text{Aguardando Abertura} \longrightarrow \text{Aguardando Causa da Reprova} \longrightarrow \text{Finalizado}$$
*(Status derivado diretamente da presença de data de término e causa raiz preenchidas).*

### 9.2. Engenharia de Custos de Não-Conformidade
- **Parâmetros de Custo de Mão de Obra (`custos.php`):**
  - Custo da Hora-Homem (`custo_hora_homem`, ex.: R$ 45,00/h);
  - Jornada diária padrão (`horas_trabalho_dia`, ex.: 8,80 h).
- **Tempos Padrão por Reprova (`tempo_padrao_minutos`):** Cada código de reprova do catálogo possui seu tempo padrão de reparo estimado em minutos.
- **Valoração de Materiais Aplicados (`custo_unitario`):** Peças consumidas na triagem são valoradas conforme tabela de custo unitário em `retrabalho_materiais_catalogo`.
- **Fórmula de Custo Consolidado:**
  $$\text{Custo Total} = \left(\frac{\text{Tempo Padrão (min)}}{60} \times \text{Custo Hora-Homem}\right) + \sum_{i=1}^{n} (\text{Qtd}_i \times \text{Custo Unitário}_i)$$
- **Segregação de Visualização Financeira:** Usuários sem permissão `ret.cus` visualizam apenas contagens e tempos operacionais; valores monetários em Reais (R$) são protegidos.

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
├── usuarios.php             ← Central Unificada de Usuários, Setores e Perfis
├── concessionaria-regras.php← Gestão de Regras Técnicas do Paint Check
└── perfis.php               ← Visualização de Matriz de Permissões
```

### 11.1. Gestão Unificada de Usuários, Setores e Perfis (`usuarios.php`)
- Interface em 3 abas principais com árvore de permissões hierárquica em 3 níveis (**HUB ➔ Módulo ➔ Tela**).
- Ações em lote (Expandir, Recolher, Liberar e Bloquear Todas as Telas).
- Senhas com hash criptográfico seguro `BCRYPT`, controle de e-mails, CPFs e soft delete.

### 11.2. Regras de Puncionamento & Serigrafia por Concessionária (`concessionaria-regras.php`)
- Cadastro de normas de referência técnica (ex.: ABNT NBR 5440, NBR 5356, normas Copel, Cemig, Energisa, Equatorial, Neoenergia, CPFL, Celesc, Enel, Amazonas, EDP e Mercado Particular).
- **Locais Obrigatórios de Punção:** Definição por concessionária se a peça exige punção na `tampa`, `tanque`, `gancho` ou combinações.
- **Códigos Adicionais & Tombamento:** Configuração de expressões regulares (regex) para conferência de números de patrimônio e código do cliente, além da definição do local (`tampa`, `tanque` ou ambos).
- **Regras de Elo Fusível e Potência:** Indicação de obrigatoriedade e cálculo de valor esperado.
- **Apelidos de Reconhecimento:** Tabela `concessionaria_regras_apelidos` para matching flexível com nomes comerciais oriundos do ERP.

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
│   ├── admin-acao.php / admin-v2-acao.php
│   ├── boletim-acao.php / boletim-fluxo.php / boletim-exportar.php
│   ├── concessionaria-regras-acao.php
│   ├── paint-check-identificar.php / paint-check-multi.php / paint-check-salvar-validacao.php
│   ├── papel-inventario-acao.php / papel-liberacao-acao.php / papel-sincronizar-vsat.php
│   ├── pintura-acao.php / pintura-retornos-acao.php
│   ├── producao-acao.php / producao-aderencia-pecas.php
│   ├── projetos-acao.php / qualidade-acao.php
│   ├── retrabalho-acao.php / retrabalho-custos-salvar.php / retrabalho-relatorio-atualizar.php
│   ├── soma-acao.php
│   ├── sync-boletim.php             ← Endpoint de recebimento de cache para deploy Railway
│   └── vsat-num-series-sincronizar.php
├── assets/
│   ├── css/main.css                 ← Design System central, tokens e variáveis CSS
│   ├── css/fluxo-pedidos.css        ← Estilos da matriz de fluxo de pedidos
│   └── js/                          ← Scripts JS vanilla modulares por tela
├── config/
│   ├── conexao.php                  ← Conexões PDO MySQL (getDB) e SQL Server (getSqlServerDB)
│   ├── session.php                  ← Handler de sessões em MySQL e funções RBAC
│   └── versao.php                   ← Versão oficial do sistema (APP_VERSION = '1.2.2')
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
│   ├── boletim-planilha.php         ← Leitor de planilhas e caches universais
│   ├── concessionaria-regras.php    ← Regras de puncionamento por concessionária
│   ├── vsat-num-series.php          ← Leitor de números de série do VSAT
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
│   └── admin/                       ← Gestão de Usuários, Setores e Regras Paint-Check
├── paint-check-robo/
│   └── server.py                    ← Microsserviço Python de OCR e processamento de imagens
├── scripts/
│   ├── run-tests.ps1                ← Execução de testes automatizados PHPUnit
│   ├── sincronizar_producao_railway.php ← Push de caches para o deploy no Railway
│   └── sincronizar_vsat_*.ps1       ← Scripts de extração do ERP
├── storage/cache/                   ← Caches JSON UTF-8 e snapshots do sistema (gitignored)
├── tests/                           ← Suíte de testes PHPUnit
│   ├── Includes/ConcessionariaRegrasTest.php
│   ├── Includes/FeriadosTest.php
│   ├── TestCase.php
│   └── bootstrap.php
├── Dockerfile                       ← Build de deploy para produção no Railway
├── index.php                        ← Hub principal de seleção de sistemas
├── login.php / logout.php           ← Fluxo de autenticação segura
└── phpunit.xml                      ← Configuração oficial da suíte de testes
```

---

## 14. Estrutura de Banco de Dados & Schema Unificado

### 14.1. Infraestrutura, Acesso & Sessão
| Tabela | Descrição |
|---|---|
| `perfis` | Papéis mestres: 1=Administrador, 2=Planejador, 3=Executor, 4=Dashboard, 5=Cliente Interno |
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
| `retrabalhos` | Lançamentos de não-conformidade, datas de início/fim, causa e setor causador |
| `retrabalho_materiais_catalogo` | Catálogo de materiais com descrição, unidade e `custo_unitario` |
| `retrabalho_material_uso` | Materiais consumidos na triagem com snapshot de quantidade e custo unitário |
| `retrabalho_configuracoes` | Parâmetros globais (`custo_hora_homem`, `horas_trabalho_dia`) |
| `itens_catalogo` | Catálogo geral de materiais importado de `Item.csv` |

### 14.3. Chão de Fábrica (Produção & Pintura)
| Tabela | Descrição |
|---|---|
| `producao_transformadores` | Vínculo do número de série ao projeto e pedido comercial |
| `producao_etapas` | Passagens pelas estações (`estacao` ENUM: `'IQF'`, `'LAB'`, `'GER'`, `'PIN'`) com índice `ns_ativo` |

### 14.4. PCP, Metas & Atrasos
| Tabela | Descrição |
|---|---|
| `boletim_config_metas` | Metas mensais (`meta_tpm`, `meta_tpd_distribuicao`, `meta_enrolado`, `meta_convencional`, `meta_jctrif`, `meta_tpd_forca`, `meta_tps`), dias úteis e calendário customizado |
| `boletim_registros` | Registro diário de metas e produções por área e linha |
| `atraso_distribuicao_registros` | Snapshot do plano mestre de distribuição com `setor_real` e `tipo_bloqueio` ('CELULA'/'MATERIAL') |
| `atraso_forca_registros` | Snapshot de atrasos das linhas de Média Força e Seco |
| `atraso_metas` | Metas mensais de tolerância de atraso em dias por linha |
| `vsat_num_series_previo` | Cache sincronizado de números de série do ERP para resolução rápida |

### 14.5. Módulo Setor de Papel
| Tabela | Descrição |
|---|---|
| `papel_maquinas` | Cadastro de máquinas operatrizes do setor de papel (código VSAT, apelido de chão, status) |
| `papel_pecas` | Peças padronizadas da engenharia com bloco construtivo, material e espessura |
| `papel_estoque_inventario` | Almoxarifado físico de papel/papelão (rua, prateleira, caixa, material, espessura, saldos kg/qtd) |
| `papel_aproveitamento_analise` | Análise e aprovação de aproveitamento de sobras contra a demanda do PCP |
| `papel_estoque_movimentacoes` | Auditoria de entradas, saídas, reservas e ajustes de estoque |

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
| `soma_operadores` / `soma_maquinas` | Operadores e postos de trabalho cadastrados |
| `soma_motivos_parada` | Motivos de parada de linha com impacto no OEE |
| `soma_produtos` / `soma_registros` | Tempos padrão de ciclo e apontamentos realizados |
| `soma_paradas` | Registro de paradas de máquina com duração e operador |

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
| **7 — Consolidação Fabril & Inteligência Avançada (v1.2.2)** | Módulo Papel (mesa de corte, F-29, inventário), Linha de Pintura dedicada (estação PIN), Atraso Média Força, Paint Check 2.0 (fluxo em 3 etapas, checklist por campo, persistência para fine-tuning), Regras de Concessionária, Custos Financeiros de Retrabalho, Feriados Municipais/Estaduais na Aderência e Resiliência de Deploy no Railway | ✅ Concluída |
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

- **Versão Global do Sistema:** Gerenciada em `config/versao.php` (`APP_VERSION = '1.2.2'`).
- **Padrão de Mensagens de Commit:**
  - `feat(modulo): vX.Y.Z — <descrição da funcionalidade>`
  - `fix(modulo): vX.Y.Z — <descrição da correção>`
- **Suíte de Testes Automatizados:**
  - Execução local via PowerShell: `.\scripts\run-tests.ps1`
  - Configuração XML: `phpunit.xml`
  - Cobertura obrigatória para cálculos de datas, dias úteis, feriados, regras de concessionária e consistência de schemas.
- **Auditoria de Código & Linhas de Programação:**
  - Toda modificação em arquivos de backend deve passar por validação estrita de sintaxe (`php -l`) e respeitar a tipagem estrita `declare(strict_types=1)`.
