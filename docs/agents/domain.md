# Domain Docs

Estrutura de documentação de domínio para as skills de engenharia no repositório SGT (Sistema de Gestão de Transformadores).

## Arquivos de Contexto e Decisão

- **`CONTEXT.md`** na raiz do projeto: glossário canônico de termos industriais (PCP, núcleos, turnos, concessionárias, ensaios de laboratório).
- **`PROJETO-SGT/PROJETO-SGT.md`**: especificação arquitetural, endpoints, regras de cálculo e fluxos industriais.
- **`gemini.md`**: regras de engenharia, stack tecnológica e diretrizes de ambiente local.
- **`docs/adr/`**: registros de decisões arquiteturais (ADRs).

## Estrutura Single-Context

```
/
├── CONTEXT.md                 ← Glossário e modelo de domínio SGT
├── gemini.md                  ← Diretrizes e stack técnica
├── PROJETO-SGT/               ← Arquitetura e especificações de negócio
├── docs/adr/                  ← Architecture Decision Records
└── docs/agents/               ← Configurações de automação das skills
```
