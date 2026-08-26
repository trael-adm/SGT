# 1. Desenvolvimento Estritamente Local e Rastreabilidade

Data: 2026-08-26

## Status
Aceito

## Contexto
O desenvolvimento e testes do sistema SGT no ambiente `SGT-dev` devem operar estritamente em ambiente local (`m:\APP\Sistema_SGT\www\SGT-dev`), sem dependência de comandos `git push` remotos para pipelines intermediárias durante iterações de prototipagem e refinamento.

## Decisão
1. Rastrear issues, especificações e triagem de tarefas diretamente no diretório `scratch/` em formato Markdown local.
2. Manter integridade do banco MySQL local e consultas tolerantes a falhas no SQL Server da fábrica (`vsat.trael.local`).
3. Utilizar o glossário e modelos em `CONTEXT.md` e `PROJETO-SGT/PROJETO-SGT.md` como autoridade canônica de regras de negócio.

## Consequências
- Ciclo de feedback instantâneo sem risco de interferir em produção.
- Documentação e histórico de tarefas preservados no próprio repositório.
