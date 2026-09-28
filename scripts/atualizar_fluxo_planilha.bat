@echo off
rem SGT - Atualiza o cache da Planilha de Producao do Fluxo de Pedidos direto do SQL Server.
rem Chamado pelo Agendador de Tarefas do Windows (tarefa "SGT - Atualizar Fluxo Planilha").
rem Usa o PHP mais novo instalado no Laragon desta maquina (nao depende do perfil de nenhum usuario).

set "PHP="
for /d %%D in ("C:\laragon\bin\php\php-*") do set "PHP=%%~D\php.exe"

if not exist "%PHP%" (
    echo PHP do Laragon nao encontrado em C:\laragon\bin\php
    exit /b 1
)

"%PHP%" "%~dp0atualizar_fluxo_planilha.php"
exit /b %errorlevel%
