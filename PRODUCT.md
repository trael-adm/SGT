# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Supervisores de produção do chão de fábrica, planejadores do PCP (Planejamento e Controle da Produção), inspetores de qualidade/laboratório e liderança industrial da Trael Transformadores.

## Product Purpose

Plataforma de gestão operacional e inteligência de produção em tempo real para a fábrica da Trael. Centraliza o controle de pedidos, rastreabilidade de números de série em 10 células de manufatura, acompanhamento de sub-montagens (Tanque e Parte Ativa convergindo para Montagem Final), boletins diários de medição (Meta × Realizado) e controle de retrabalhos.

## Positioning

Sistema sob medida de alta performance e densidade de informação para a indústria de transformadores elétricos, integrando dados em tempo real do chão de fábrica e do ERP (SQL Server) com tolerância zero a atrasos e inconsistências operacionais.

## Operating Context

- Ambientes: Chão de fábrica (terminais de produção, monitores de supervisão) e escritórios do PCP/Engenharia/Qualidade.
- Fluxo de trabalho: Monitoramento contínuo de esteiras produtivas, conferência de ordens de fabricação (OFs mães e sub-OFs), priorização de pedidos, apontamentos de peças por célula e emissão de ordens de retrabalho.
- Dispositivos: Telas desktop com alta resolução e tablets operacionais.

## Capabilities and Constraints

- **Stack:** PHP 8.4 Vanilla (`declare(strict_types=1)`), Vanilla JavaScript e Vanilla CSS com tokens predefinidos (sem frameworks pesados).
- **Banco de Dados:** Conexão dual com MySQL (`getDB()`) e SQL Server ERP Trael via PDO ODBC (`getSqlServerDB()`).
- **Células Fabris (10 Setores):** Chassi (`CH`), Baixa Tensão (`BT`), Alta Tensão (`AT`), Corte CNC (`CNC`), Solda/Tanque (`SOL`), Montagem Núcleo (`MN`), Pintura (`PIN`), Montagem Elétrica / Parte Ativa (`ME`), Montagem Final (`MF`), Laboratório / Ensaios (`LAB`).
- **Turnos de Produção:** Turno das 07:30 às 02:48 (apontamentos entre 00:00 e 07:29:59 pertencem ao turno anterior com -450 min de deslocamento).
- **Consistência Visual:** Layouts com topo travado (*sticky top*), densidade de dados alta, tipografia tabular monospace para números de série/códigos e rolagem única controlada.

## Brand Commitments

- **Identidade Visual Trael:** Paleta primária verde industrial (`#16a34a` / `#1a3d2a`) e âmbar/ouro (`#e8a020`), complementada por badges funcionais de status (verde para `OK`, laranja para `Pendente`, azul para `Info`, vermelho para `Perigo`).
- **Design System:** Design sóbrio, limpo, responsivo e de alta legibilidade, com contraste calibrado para rápida tomada de decisão.

## Product Principles

1. **Visibilidade Imediata:** Informações críticas e gargalos de produção devem saltar aos olhos sem navegações profundas.
2. **Zero Fricção Operacional:** Filtros instantâneos (estilo Excel), buscas rápidas e paginação com topo travado.
3. **Fidelidade Real do Chão de Fábrica:** As regras do sistema devem espelhar fielmente a lógica física das etapas de produção e os apontamentos do ERP.
4. **Precisão e Integridade:** Dados 100% auditados, sem duplicações, produtos cartesianos ou atrasos de sincronização.
