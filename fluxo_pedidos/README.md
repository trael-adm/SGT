# Módulo: Fluxo do Pedido & Acompanhamento de Produção (Standalone)

Este diretório contém o módulo de **Fluxo do Pedido e Acompanhamento Detalhado de Produção**, desenvolvido de forma isolada dentro do ecossistema SGT-dev.

## Estrutura Isolada do Módulo
- `index.php` — Painel do Caminho do Pedido + Planilha de Apontamentos por Número de Série.
- `api.php` — Processamento de dados e integração direta com o SQL Server / Planilhas.
- `planilhas/` — Pasta local dedicada para arquivos de exportação do VSat (FollowUps, etc.).
- `assets/` — Scripts JS e estilos específicos deste módulo.
