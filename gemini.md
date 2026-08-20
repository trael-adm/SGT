# Contexto do Projeto: SGT (Sistema de Gestão Trael)

Este arquivo define as regras de ouro para IAs (como o Gemini) atuarem no projeto `SGT-dev`. 
**REFERÊNCIA PRINCIPAL:** Antes de tomar qualquer decisão estrutural, leia sempre `PROJETO-SGT/PROJETO-SGT.md`, que é a referência viva e detalhada do projeto. As regras abaixo são um resumo diretivo e rigoroso retirado dele.

## 🎯 Missão & Protocolo de Comportamento

### 1. Execução Aprofundada & Sem Pressa (Zero-Rush)
- **Tome o tempo necessário:** Nunca tenha pressa para entregar soluções incompletas, improvisadas ou malfeitas.
- **Conservação de tokens é estritamente proibida:** Não abrevie código, não pule código boilerplate nem use placeholders como `// TODO` ou `// resto do código aqui`. Escreva arquivos completos e prontos para produção.
- **Previsão de cenários:** Pense detalhadamente em todos os casos extremos (edge cases), bugs potenciais e falhas de arquitetura antes de gerar o código.

### 2. Testes Ponta a Ponta Obrigatórios (End-to-End)
- **Critério de conclusão:** Uma tarefa NUNCA está concluída até ser totalmente testada e verificada.
- **Cobertura de testes:** Sempre escreva testes robustos de unidade, integração e casos extremos para qualquer lógica nova ou modificada.
- **Diagnóstico ativo:** Execute testes e linters ativamente. Se algo falhar ou emitir avisos, diagnostique e corrija a causa raiz imediatamente — não ignore erros nem aplique soluções paliativas ou preguiçosas.
- **Auto-validação:** Verifique o ciclo de vida de build e execução por conta própria antes de reportar a conclusão.

### 3. Utilização de Subagentes & Ferramentas
- **Delegação e paralelismo:** Aproveite ao máximo subagentes, processos em segundo plano (workers) e ferramentas para dividir tarefas complexas em etapas paralelas e especializadas (ex.: planejamento dedicado, implementação, revisão de código, verificação de testes).
- **Tratamento multicamadas:** Quando uma tarefa tiver múltiplas camadas (arquitetura, lógica, estilização, testes), delegue cada domínio a etapas de raciocínio/subagentes dedicados.

### 4. Padrões de Saída / Resposta
- **Comunicação direta:** Seja conciso, direto e vá direto ao ponto nas explicações da conversa — sem enrolação corporativa ou formalismo desnecessário.
- **Entregas completas:** Entregue implementações completas e 100% funcionais com tratamento de erros robusto e nenhum import ausente.

## ⚠️ Regras de Comportamento Obrigatórias
1. **Escopo Estrito:** Faça **apenas** o que for pedido na tarefa atual.
2. **Sem Criação Desnecessária:** Não crie arquivos além dos solicitados.
3. **Sem Refatoração Não Solicitada:** Não refatore, não renomeie e não reorganize código que não foi pedido expressamente.
4. **Sem Frameworks:** O projeto usa **PHP 8.4 Vanilla** e **Vanilla JS**. Não adicione jQuery, React, Vue, Alpine ou frameworks PHP (como Laravel).
5. **Preservação de UI/UX:** Ao editar telas e scripts, nunca altere textos de botões, labels ou remova elementos visuais/lógicos sem solicitação explícita. Faça edições cirúrgicas linha a linha para não apagar nada por acidente.

## 🛠️ Stack Tecnológica
- **Backend:** PHP 8.4 Vanilla. Todo arquivo PHP deve ter a declaração `declare(strict_types=1)`.
- **Banco de Dados:** MySQL via PDO. **Regra Absoluta:** a conexão é instanciada unicamente pelo singleton `getDB()` presente em `config/conexao.php`. Nunca instancie PDO diretamente.
- **Frontend:**
  - CSS: **Tailwind CSS via CDN play** (sem build step).
  - Design System: O sistema possui CSS nativo em `assets/css/main.css` com variáveis predefinidas.
  - JS: Scripts globais estão em `assets/js/app.js`.
  - Bibliotecas Permitidas (via CDN): Chart.js para gráficos e jsQR para leitores.
- **Deploy:** Railway (com `Dockerfile` na raiz). As variáveis locais ficam isoladas no `.env`.

## 📁 Estrutura de Arquivos e Padrões
- `_inicial/`: Contém `database.sql` (schema inicial) e scripts `migrar-*.sql` (migrações incrementais, aplicadas manualmente - nunca reescreva o `database.sql` original).
- `api/`: Scripts de endpoints e processamento de chamadas AJAX/fetch (ex: `producao-acao.php`).
- `config/`: Configurações de banco (`conexao.php`), sessão e controle (`session.php`), e versionamento (`versao.php`).
- `includes/`: Funções globais (`helpers.php`) e fragmentos visuais (`layout.php`, que engloba automaticamente `header`, `sidebar` e `footer`).
- `pages/`: Views de cada módulo organizadas por diretórios (ex: `/pages/producao/`, `/pages/retrabalho/`).

## 💾 Padrões de Banco de Dados
- **Soft Delete:** Toda exclusão é lógica com `deleted_at TIMESTAMP NULL DEFAULT NULL`. Todas as queries padrão devem filtrar `WHERE deleted_at IS NULL`.
- **Status Escapado:** A coluna `status` é uma palavra reservada, e toda query manual deve envolvê-la em crases: `` `status` ``.
- **Auditoria:** Use `id_criador`/`id_responsavel` nas tabelas novas, e inclua os clássicos `created_at` e `updated_at`.
- **Datas (Frontend):** Ao devolver dados JSON para o front-end contendo horas, use a função `isoComOffset()` de `helpers.php` para tratar fusos horários. Nunca passe a string de datetime puro.

## 🔒 Autenticação e Perfis
- Sessões PHP ficam armazenadas no banco de dados (`php_sessions`) para contornar perdas no deploy efêmero (Railway).
- Os IDs de perfil são fixos: 1=Administrador, 2=Planejador, 3=Executor, 4=Dashboard, 5=Cliente Interno.
- A flag `e_executor` na tabela de usuários dá permissão operacional de lançamento indepentente do Perfil master. Use `requirePerfil([...])` para restringir views.

## 🔗 Roteamento e Chamadas de Rede
- Requisições fetch no frontend devem usar `window.__APP_BASE` para gerar rotas absolutas (evita erro 404 entre local e prod). Exemplo: `fetch(window.__APP_BASE + '/api/exemplo.php')`.
