@echo off
chcp 65001 > nul
title SGT - Sincronizador de Produção Trael -> Railway

echo Iniciando sincronização com o Railway...
"C:\Users\06688286173\laragon\bin\php\php-8.4.21-Win32-vs17-x64\php.exe" "%~dp0sincronizar_producao_railway.php"

if "%1"=="--no-pause" goto end
echo.
pause
:end
