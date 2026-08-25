# Plano de Melhorias — Telas de Setor (`fluxo_pedidos/`)

Documento de planejamento. Nenhuma mudança de código foi feita ainda — este arquivo descreve o diagnóstico dos problemas reportados e o desenho das mudanças propostas, para orientar uma implementação futura.

Todas as referências `arquivo:linha` abaixo foram conferidas diretamente no código em 2026-08-22.

---

## 1. Diagnóstico dos bugs reportados

### 1.1 Filtro "Fila = Em Aberto" (padrão) está trazendo peças já concluídas

**Não é um bug de SQL/PHP — é uma dessincronia de estado no frontend.**

- `assets/js/setor.js:17` inicializa `state.statusFila: 'todos'`.
- `assets/js/setor.js:66` — o `<select id="filtroStatusFila">` já vem no HTML com `<option value="em_aberto" selected>Em Aberto</option>` pré-selecionado.
- `assets/js/setor.js:33-34` — `carregarSetor()` é chamado no `DOMContentLoaded`, **antes** de qualquer clique do usuário.
- `assets/js/setor.js:85-91` — `state.statusFila` só é lido do DOM (`document.getElementById('filtroStatusFila').value`) dentro do handler de clique do botão **"Filtrar"**.
- `assets/js/setor.js:153` — a URL da primeira requisição usa `state.statusFila`, que nesse momento ainda vale `'todos'` (o valor de inicialização da linha 17, nunca sincronizado com o `<select>`).

**Resultado:** na primeira carga da tela, o combo já aparece mostrando "Em Aberto" visualmente selecionado, mas a requisição real dispara com `status_fila=todos` — todos os registros, inclusive os concluídos. O usuário só vê o filtro "de verdade" funcionar depois de clicar manualmente em "Filtrar" pelo menos uma vez.

Confirmado que o backend não tem culpa nessa parte: `motor_producao.php:42-46` (filtro SQL) e o flag `is_concluido` (linhas 280-310, calculado a partir das 11 células) usam exatamente o mesmo campo de origem, `ofp.StatusOF` da OF-mãe — não há inconsistência entre eles.

**Correção proposta:** inicializar `state.statusFila` como `'em_aberto'` em `setor.js:17` (para bater com o `selected` do HTML), garantindo que a primeira chamada a `carregarSetor()` já envie o filtro correto. Alternativa equivalente: ler o valor do `<select>` antes da primeira chamada, em vez de depender só do literal inicial.

### 1.2 Coluna SEQ mostra números "fora do esperado"

- `motor_producao.php:91` — `SEQ` vem direto de `prog.Ordenacao`, um campo da tabela `dbo.ProgramacaoProducao` do ERP (PCP).
- `motor_producao.php:325` — o valor só é convertido para `int` (`(int)($r['SEQ'] ?? 0)`), sem nenhuma normalização, clamp ou recontagem por lote.
- Existe, sim, uma "posição do transformador dentro do lote" calculada no código — `$ordemNoLote`, o índice do `foreach` em `motor_producao.php:278`, resultado de um `usort` por `NumSerie` crescente (`motor_producao.php:200-201`). **Mas esse valor nunca é exposto no JSON de saída** — é usado só internamente para decidir OK/PEND por célula (linhas 290-300).

**Causa raiz:** o usuário naturalmente espera que SEQ represente "1º, 2º, 3º transformador do lote", mas na verdade é um contador de programação do PCP no ERP (provavelmente contínuo/global na fábrica, não por lote), sem relação alguma com a posição real do trafo dentro do seu lote de produção. Por isso os números parecem "aleatórios" ou muito altos.

**Decisão tomada:** remover a coluna SEQ da tela — não é uma informação confiável/útil para o gestor de produção neste contexto.

**Pontos a tocar quando for implementado:**
- `motor_producao.php:91` — remover `prog.Ordenacao AS SEQ` do `SELECT` (opcional; pode ficar no SQL sem custo perceptível, mas é mais limpo remover junto).
- `motor_producao.php:325` — remover a chave `'seq'` do array de saída.
- `assets/js/setor.js:265-269` — remover o `<th>` de SEQ do header (`montarCabecalhoProducao()`).
- `assets/js/setor.js:413` — remover a `<td>` correspondente no corpo da tabela (`renderizarProducao()`).
- `setor.php` e `assets/js/setor.js:149,164,174,382` — ajustar `colspan="22"` para `colspan="21"` nos placeholders de loading/erro/vazio da tabela de produção (a tabela passa a ter 21 colunas: 10 fixas + 11 células).

**Nota lateral (não é bug, mas vale documentar):** as células `ME` e `LAB` (`motor_producao.php:298,300`) não têm OF de componente mapeada em `$mapPrefixos` (`motor_producao.php:239-249`) — elas só viram `OK` quando a OF-mãe é encerrada (`$isMaeEnc`). Ou seja, um transformador pode ter as outras 9 células prontas e ainda assim nunca aparecer como "concluído" até a OF-mãe fechar. Isso é esperado pelo desenho atual, mas pode confundir quem olha a tela sem saber disso — vale um tooltip ou nota na UI explicando por que ME/LAB não acompanham o restante do rateio.

---

## 2. Redesenho: produtos/quantidades por pedido (COMERCIAL/ENGENHARIA/PCP/LOGISTICA)

### Situação atual

- `assets/js/setor.js:683` — na tela COMERCIAL, pedidos com múltiplos produtos viram uma lista de texto empilhada dentro de uma única `<td>`, via `.map(...).join('<br>')`. Para o pedido #68588 (5 produtos diferentes), isso resulta em 5 linhas de texto comprimidas numa única célula.
- A coluna QTD (`setor.js:691`) não é somada no cliente — vem pronta do backend como `p.qtd_total_itens` (`motor_pedidos.php:211`), então o número agregado está correto, mas a composição por trás dele fica ilegível.
- Nos demais setores (ENGENHARIA/PCP/LOGISTICA), a lista de produtos vira uma string curta só com referências separadas por vírgula (`setor.js:697,712,728`), perdendo quantidade/potência por item.
- Não existe hoje nenhuma forma de expandir, filtrar ou ordenar por produto individual — o único drill-down é o clique na linha inteira, que abre o modal de detalhe do pedido (`setor.js:745-799`). Esse modal já recebe `pedido.produtos` completo (com `qtd`, `cd_referencia`, `potencia_kva`, `classe_tensao`, `preco_unitario` — `motor_pedidos.php:216-229`), mas **nunca renderiza essa lista** — é dado "morto" no frontend hoje.

### Solução proposta: linha do pedido expansível (accordion)

- Manter a linha-resumo do pedido como está hoje (Pedido, Emissão, Prazo, Cliente, Qtd total, Status ERP, Situação).
- Adicionar um controle de expandir/recolher (ícone `▸`/`▾`) na linha-resumo. Ao expandir, abre uma sub-linha (ou sub-tabela) logo abaixo, listando cada produto do pedido individualmente: referência (`cd_referencia`), quantidade, potência (kVA), classe de tensão.
- Aplicar o mesmo padrão nos 4 setores (COMERCIAL/ENGENHARIA/PCP/LOGISTICA), já que todos sofrem da mesma limitação hoje.
- Reaproveitar essa mesma sub-tabela de produtos dentro do modal de detalhe do pedido (`setor.js:745-799`), já que o dado (`pedido.produtos`) já chega até lá sem uso.
- Como consequência, a coluna de produtos passa a ter uma granularidade que permite filtro/ordenação por produto — ver seção 3.

---

## 3. Filtros dinâmicos universais

### Situação atual

- O padrão de filtro Excel-style (botão `▾` no header, popup com checkboxes multi-seleção, busca textual, ordenação A-Z/Z-A) já existe e funciona bem — implementado em `assets/js/setor.js:433-537` (`abrirPopupFiltro`, `aplicarFiltroColuna`).
- Na tela PRODUÇÃO, quase todas as colunas já têm esse filtro (`setor.js:194-269`).
- Nas telas de setor (COMERCIAL/ENG/PCP/LOG), **só `Pedido`, `Data`/`Emissão` e `Cliente` têm filtro** (`setor.js:575-578, 588-590, 602-604, 616-618`). Colunas como "Produtos/Especificação", "Situação do Pedido" e "Status ERP" não têm filtro nem ordenação.
- Mecânica é 100% client-side (filtra o array já carregado em memória, sem nova consulta ao servidor) — replicar o padrão para novas colunas é uma extensão direta do que já existe, não uma nova arquitetura.
- Hoje o estado de filtro (`state.filtrosColuna`, `state.filtroUrgencia`) não é persistido — um F5 zera tudo.

### Solução proposta

- Estender o filtro Excel-style para as colunas hoje sem filtro: Situação do Pedido, Status ERP, e a granularidade de produto/referência que passa a existir com a linha expansível da seção 2.
- Persistir o estado de filtros (querystring da URL, ou `localStorage` por setor) para sobreviver a um F5 — hoje qualquer recarregamento de página obriga o usuário a refazer os filtros do zero.

---

## 4. Filtro Mês/Ano na tela Produção

### Situação atual

- Os únicos controles temporais na tela Produção hoje são "Data Auxiliar (de/até)" e "Semana" (`setor.js:52-61`). Não existe seletor de Mês/Ano.
- Ambos operam sobre o mesmo campo: `prog.DataHoraProducaoAux`, da tabela `dbo.ProgramacaoProducao` (`motor_producao.php:48-59`) — que é a **data de programação de produção do PCP**, não a data de emissão comercial do pedido (`p.dt_Pedido`, usada só no `WHERE`/`ORDER BY` do SQL e nunca exposta na tela de Produção).
- O rótulo atual "DATA AUX" (`setor.js:213`) é ambíguo para quem não conhece o motor por trás — fácil de confundir com "data do pedido".

### Solução proposta

- Adicionar dois novos `<select>` ("Mês" 1-12, "Ano") ao lado do combo "Semana" existente em `montarBarraSuperior()` (`setor.js:57-61`), seguindo o mesmo padrão de geração de opções já usado por `gerarOpcoesSemanas()` (`setor.js:139-145`).
- Backend: acrescentar parâmetros `mes`/`ano` à assinatura de `carregarPlanilhaProducao()` (`motor_producao.php:15-24`), aplicando `DATEPART(MONTH, prog.DataHoraProducaoAux) = ?` e `DATEPART(YEAR, prog.DataHoraProducaoAux) = ?` — mesmo campo já usado pelos filtros de `dt_inicio`/`dt_fim`/`semana`.
- Repassar os novos parâmetros em `api.php:29-39` (ação `planilha_producao`), seguindo o padrão já usado para `semana`.
- Aproveitar a mudança para renomear o rótulo "DATA AUX" para algo mais claro (ex: "Data Prog. PCP") ou adicionar um tooltip explicando que é a data de programação de produção, não a data comercial do pedido — evita confusão quando o novo filtro de Mês/Ano for usado.

---

## 5. Melhorias adicionais identificadas (baixa prioridade / backlog)

- **Performance/paginação**: a tabela de Produção pode carregar até 3000 registros (`limite=3000`, `setor.js:153`) e cada filtro/ordenação reconstrói o DOM inteiro via `innerHTML` (`setor.js:203,388`), sem paginação nem virtualização. Pode ficar perceptivelmente lento em máquinas mais fracas — candidato a paginação client-side ou infinite scroll numa fase futura.
- **Inconsistência de defaults de `limite`**: `api.php:30` usa fallback `2000` quando o parâmetro está ausente, enquanto a assinatura de `carregarPlanilhaProducao()` (`motor_producao.php:16`) tem default `3000`. Sem impacto prático hoje (o frontend sempre envia `3000` explicitamente), mas vale unificar os dois valores.
- **Documentação desatualizada**: o `CLAUDE.md` do módulo afirma que os dois motores (`motor_pedidos.php` e `motor_producao.php`) usam cache `static $cache` por request. Isso só é verdade para `motor_pedidos.php` (e `followup_parser.php`) — `motor_producao.php` não tem nenhum cache; toda chamada bate direto no SQL Server. Vale corrigir essa afirmação no `CLAUDE.md`.

---

## 6. Priorização sugerida

1. **Fase 1 — bugs e itens bem definidos, baixo risco**: correção do bug da Fila (seção 1.1), remoção da coluna SEQ (seção 1.2), filtro de Mês/Ano em Produção (seção 4).
2. **Fase 2 — filtros dinâmicos universais** (seção 3): estender o padrão Excel-style já existente para as colunas que faltam nas telas de setor.
3. **Fase 3 — produtos expansíveis** (seção 2): redesenho da apresentação de produtos por pedido, incluindo o reaproveitamento no modal de detalhe.
4. **Fase 4 — backlog** (seção 5): performance/paginação, persistência de filtros, ajustes de documentação.
