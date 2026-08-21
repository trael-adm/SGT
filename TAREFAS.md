# 📋 Lista de Tarefas e Melhorias — SGT (Sistema de Gestão Trael)

Este documento centraliza as tarefas, requisitos e melhorias a serem implementadas no projeto **SGT-dev**.

---

## 🎯 Backlog de Tarefas

### 1️⃣ Remoldar a Criação de Perfil e Permitir "Sem Perfil" (Permissões Manuais)
- **Objetivo:** Permitir que um usuário seja cadastrado como **"Sem perfil de acesso"**, possibilitando alocar manualmente setores e permissões diretamente ao usuário.
- **Telas Impactadas:** [pages/admin/perfis.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/admin/perfis.php), [pages/admin/usuarios.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/admin/usuarios.php), [api/admin-v2-acao.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/api/admin-v2-acao.php).
- **Status:** ✅ Concluído

---

### 2️⃣ Considerar "Geral" em Retrabalho Apenas para Revitalizações
- **Objetivo:** O escopo/aba **"Geral" (GER)** é mantido com o rótulo "Geral", mas restrito e vinculado exclusivamente aos transformadores de **Revitalizações** (Setor Causador: *Revitalização* / Código prefixo `R`).
- **Telas Impactadas:** [pages/retrabalho/relacao.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/retrabalho/relacao.php), [pages/retrabalho/dashboard.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/retrabalho/dashboard.php), [pages/retrabalho/acompanhamento.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/retrabalho/acompanhamento.php), [pages/retrabalho/historico.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/retrabalho/historico.php).
- **Status:** ✅ Concluído

---

### 3️⃣ Botão de "Expandir Todos / Recolher Todos" nas Listagens com Preferência Salva
- **Objetivo:** Botão `+ Expandir Todos` / `− Recolher Todos` integrado à barra de filtros e no cabeçalho das colunas com salvamento da preferência no `localStorage`.
- **Telas Impactadas (Todas as Listagens com Detalhes):**
  - [pages/retrabalho/relacao.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/retrabalho/relacao.php) *(Retrabalho - Relação)*
  - [pages/pintura/relacao.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/pintura/relacao.php) *(Pintura - Relação)*
  - [pages/retrabalho/acompanhamento.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/retrabalho/acompanhamento.php) *(Acompanhamento)*
  - [pages/retrabalho/historico.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/retrabalho/historico.php) *(Histórico)*
  - [pages/inspecao_final/retornos.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/inspecao_final/retornos.php) *(Inspeção Final - Retornos)*
  - [pages/producao/retornos.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/producao/retornos.php) *(Produção/Laboratório - Retornos)*
- **Status:** ✅ Concluído

---

### 4️⃣ Campo CPF (Opcional) no Cadastro de Usuário / Perfil
- **Objetivo:** Incluir o campo **CPF** como **opcional** no cadastro de usuários, com máscara `000.000.000-00` e validação quando preenchido.
- **Telas Impactadas:** [_inicial/migrar-usuarios-cpf.sql](file:///m:/APP/Sistema_SGT/www/SGT-dev/_inicial/migrar-usuarios-cpf.sql), [config/conexao.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/config/conexao.php), [pages/admin/usuarios.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/admin/usuarios.php), [api/admin-v2-acao.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/api/admin-v2-acao.php).
- **Status:** ✅ Concluído

---

### 5️⃣ Remoldar a Tela de Prioridades, Omitir "S/P" e Mover para a Aba "PCP"
- **Objetivo:**
  - Mover a opção do menu para a aba exclusiva **"PCP"** ([includes/sidebar.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/includes/sidebar.php)).
  - Omitir pedidos `"S/P"` (*Sem Pedido*).
  - **Reimaginação Completa da Interface:** Eliminação das 3 abas desconectadas e da repetição massiva de botões.
  - Implementação da **Visão Hierárquica Unificada (Pedido ➔ Projeto ➔ N° de Série)** com herança visual e do **Modo Lista Direta por NS**.
  - **Badge Popover Rápido (1 clique):** Seletor de prioridades em menu suspenso flutuante com atualização otimista em cascata e recálculo dinâmico de KPIs.
- **Telas Impactadas:** [pages/pedidos/prioridade.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/pedidos/prioridade.php), [assets/js/pedidos-prioridade.js](file:///m:/APP/Sistema_SGT/www/SGT-dev/assets/js/pedidos-prioridade.js), [includes/sidebar.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/includes/sidebar.php), [config/session.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/config/session.php), [pages/admin/perfis.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/admin/perfis.php), [pages/admin/usuarios.php](file:///m:/APP/Sistema_SGT/www/SGT-dev/pages/admin/usuarios.php).
- **Status:** ✅ Concluído

---

### ❄️ Tarefa Congelada (Futura):
- **Gráfico Gerencial de Custos de Retrabalho com Pareto 80x20 e Setor Agravante:** Congelada a pedido do usuário.

---

## 📌 Tabela de Progresso

| # | Tarefa | Status |
|---|---|:---:|
| 1 | Remoldar criação de perfil + opção "Sem Perfil" | ✅ Concluído |
| 2 | "Geral" apenas para Revitalizações (rótulo mantido) | ✅ Concluído |
| 3 | Botão "Expandir Todos" em todas as telas com detalhes | ✅ Concluído |
| 4 | Campo CPF opcional no cadastro de usuários | ✅ Concluído |
| 5 | Remoldar Prioridades (Hierarquia unificada + sem S/P + aba PCP) | ✅ Concluído |
| - | Gráfico Pareto 80/20 | ❄️ Congelado |
