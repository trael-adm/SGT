@echo off
chcp 65001 > nul
title Sincronizador SGT-dev -^> SGT
echo Iniciando sincronizacao do SGT-dev para o SGT...
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0sincronizar_sgt_dev_para_sgt.ps1"
pause
