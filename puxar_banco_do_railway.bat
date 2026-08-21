@echo off
chcp 65001 > nul
title Puxar Banco de Dados do Railway
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0puxar_banco_do_railway.ps1"
pause
