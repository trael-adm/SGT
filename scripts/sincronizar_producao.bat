@echo off
title SGT - Sincronizador de Producao Trael - Railway

echo ===============================================================
echo SGT - Sincronizando producao com o Railway...
echo ===============================================================

"C:\Users\06688286173\laragon\bin\php\php-8.4.21-Win32-vs17-x64\php.exe" "%~dp0sincronizar_producao_railway.php"

if "%1"=="--no-pause" goto end
echo.
pause
:end
