# SGT — Contexto e Domínio Industrial (Trael Transformadores)

## Visão Geral
O **SGT (Sistema de Gestão de Transformadores)** é o ecossistema de controle operacional da Trael, responsável por monitorar o planejamento (PCP), a produção diária de núcleos e montagem, os ensaios laboratoriais e a rastreabilidade por número de série.

---

## Glossário de Domínio

### Linhas e Áreas de Produção
- **Distribuição (`distrib`)**: Transformadores de distribuição até classes médias (TPD monofásicos e trifásicos).
- **Média Força (`forca`)**: Transformadores de média e alta potência (TPD Força, TPS e TPM).

### Tipos de Núcleos
- **ENR (Enrolado)**: Núcleo enrolado (monofásico / trifásico de distribuição).
- **JC (Jato Cortado / JC-TRIF)**: Núcleo cortado trifásico.
- **EMP (Empilhado)**: Núcleo montado por empilhamento de lâminas de aço silício.
- **TPD / TPS / TPM**: Tipos construtivos da linha de força a seco e óleo.

### Turno Industrial e Auditoria
- **Deslocamento de Turno (-450 min)**: O turno da fábrica inicia às 07:30. Movimentações ou apontamentos ocorridos entre 00:00:00 e 07:29:59 pertencem ao dia produtivo anterior (`DATEADD(minute, -450, ...)`).
- **piAudit**: Tabela de auditoria do ERP PierServer para rastreio de horário real de liberação de lotes e reprovas.

### Classificação de Clientes e Mercado
- **Concessionárias**: Grandes concessionárias de energia (Copel, Cemig, Energisa, Equatorial, Neoenergia, CPFL, Enel, EDP, Celesc, etc.).
- **Mercado Particular (`VAR` / Varejo)**: Clientes privados comerciais, construtoras, cooperativas e indústrias terceiras.
- **Trael**: Estoque de fábrica ou ordens internas sem cliente direto associado.

### Laboratório e Reprovas
- **Reprovas LAB**: Transformadores rejeitados em testes de rotina ou ensaios dielétricos/elétricos no laboratório antes da expedição.
