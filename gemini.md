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

## 🧪 Protocolo de Validação Real (Banco de Dados & HTTP)

Para mudanças que tocam cálculo, banco de dados ou autenticação, `php -l` e leitura de código **não bastam**. Valide contra dados reais, seguindo este protocolo:

1. **Achar as ferramentas mesmo sem PATH.** Nem `php` nem `mysql` costumam estar no `PATH` deste ambiente — não desista no primeiro `command not found`:
   - **PHP:** procure em `~/Downloads/php-*-Win32-*/php.exe` (build portátil). Vem sem extensões carregadas por padrão — habilite via flags na chamada: `-d extension_dir=".../ext" -d extension=pdo_mysql -d extension=mysqli -d extension=mbstring -d extension=session`.
   - **MySQL:** o servidor roda via Laragon como processo `mysqld` (confirme com `Get-NetTCPConnection -LocalPort 3306` no PowerShell → pegue `OwningProcess` → `Get-Process -Id`). O cliente `mysql.exe` fica dentro da instalação do Laragon (`~/laragon/bin/mysql/mysql-*/bin/mysql.exe`).
   - **Credenciais do banco:** sempre no `.env` da raiz do projeto (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).

2. **Teste de lógica isolado (sem HTTP).** Escreva um script PHP avulso (fora do repo, em scratchpad) que dá `require` direto no arquivo/função alterada e chama com dados reais do banco de dev. Para mudanças em valores configuráveis (taxas, custos, parâmetros): leia o valor atual, rode o cálculo, altere o valor temporariamente no banco, rode de novo, confira que a proporção do resultado bate com o esperado, e **restaure o valor original antes de terminar**.

3. **Teste HTTP ponta a ponta.** Suba um servidor local (`php -S 127.0.0.1:PORTA` a partir da raiz do projeto, com as extensões do passo 1). Teste:
   - Endpoint sem sessão → deve recusar corretamente (401/403 em JSON), nunca estourar erro fatal.
   - Com sessão: **nunca altere a senha de um usuário real pra logar.** Insira uma linha temporária direto na tabela `php_sessions` (sessões ficam no banco, não em arquivo) com um `PHPSESSID` inventado e os dados serializados manualmente, ex: `usuario|a:3:{s:2:"id";i:1;s:9:"id_perfil";i:201;s:4:"nome";s:13:"Administrador";}` — perfis `1`, `201` ou `202` já dão acesso total de administrador sem precisar montar permissões granulares. Autentique as chamadas com `curl -b "PHPSESSID=..."`.
   - Confira o corpo da resposta por `Fatal error`/`Warning`/`Notice`/`Deprecated` do PHP — não valide só pelo status HTTP.

4. **Limpeza obrigatória ao final.** Apague a sessão de teste da tabela `php_sessions`, restaure qualquer valor de configuração alterado no banco, e encerre o servidor local (ache o processo pela porta que está ouvindo e mate pelo PID — um `kill %1` do bash não segura processos iniciados em chamadas de ferramenta anteriores).

---

## 🤔 Protocolo de Dúvidas e Decisões (Sem Pressa)

Quando surgir incerteza — sobre uma decisão do usuário, o estado real do sistema, ou o que fazer diante de um risco — siga esta ordem, sempre sem pressa:

1. **Verifique antes de perguntar.** Dúvida não é motivo para parar e perguntar de cara — é motivo para investigar primeiro. Leia o arquivo, rode a query, confira o schema, compare o histórico do git. Só pergunte ao usuário o que **não dá pra descobrir sozinho** (intenção, prioridade de negócio, uma decisão que só ele pode tomar).
2. **Nunca presuma "provavelmente é isso".** Se uma memória, um comentário antigo ou um nome de variável sugere algo, confirme contra o estado atual (arquivo, banco, remoto) antes de agir — o que era verdade ontem pode não ser hoje.
3. **Risco alto ou irreversível → pare e explique em termos concretos, não abstratos.** Nunca diga só "isso é destrutivo" — diga exatamente o que se perde, quantas linhas, quais arquivos, se é recuperável e por quanto tempo. Ex.: "isso vai reescrever a branch main do GitHub — os 11 commits de lá deixam de aparecer, mas não são apagados na hora; alguém com o hash ainda consegue recuperar por um tempo".
4. **Dê uma recomendação, não um questionário.** Ao perguntar, já venha com a opção que você recomendaria e por quê (trade-off principal em 1–2 frases). O usuário decide, mas não deve ter que reconstruir sua análise do zero.
5. **Escrita em produção (banco, deploy, remoto compartilhado) sempre passa por dry-run primeiro.** Simule, mostre os números (quantos registros, quais tabelas, o que seria criado/pulado/ignorado), e só depois da confirmação explícita rode em modo real (`--live`).
6. **Divergência inesperada (remoto com commits que você não tem, dado que não bate com a memória) é sinal de alerta, não obstáculo a contornar.** Pare, investigue a origem, e traga o achado ao usuário antes de decidir sozinho qual lado "vence".
7. **Nunca force/descarte (force-push, hard delete, sobrescrever) sem confirmação explícita e específica para aquela ação** — uma aprovação anterior não vale para uma ação parecida depois.

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

## 🤖 Agent skills

### Issue tracker
Tarefas e specs rastreadas localmente em arquivos markdown em `scratch/`. Consulte `docs/agents/issue-tracker.md`.

### Triage labels
Rótulos canônicos de triagem (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). Consulte `docs/agents/triage-labels.md`.

### Domain docs
Estrutura single-context orientada pelo glossário industrial em `CONTEXT.md` e decisões em `docs/adr/`. Consulte `docs/agents/domain.md`.

