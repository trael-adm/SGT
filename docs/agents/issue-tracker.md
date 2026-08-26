# Issue tracker: Local Markdown

Issues, specs e tarefas deste projeto são gerenciados como arquivos markdown locais no diretório `scratch/`.

## Convenções

- Uma funcionalidade/tarefa por diretório: `scratch/<feature-slug>/`
- A especificação técnica é `scratch/<feature-slug>/spec.md`
- As tarefas e issues de implementação ficam em arquivos individuais em `scratch/<feature-slug>/issues/<NN>-<slug>.md`, numerados a partir de `01`
- O estado de triagem é registrado no topo do arquivo como `Status:` (conforme `docs/agents/triage-labels.md`)
- Histórico de comentários e decisões são adicionados no rodapé sob o cabeçalho `## Comments`
- Ambiente estritamente local: não requer comandos remotos (`gh` / git push)

## Quando uma skill solicitar "publicar no issue tracker"

Criar o arquivo correspondente sob `scratch/<feature-slug>/` (criando o diretório se necessário).

## Quando uma skill solicitar "buscar o ticket relevante"

Ler o arquivo no caminho correspondente em `scratch/<feature-slug>/issues/`.
