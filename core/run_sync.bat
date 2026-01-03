@echo off
powershell -NoExit -ExecutionPolicy Bypass -File "%~dp0commit-push.ps1"
pause