@echo off
rem Avvia l'ambiente di sviluppo di SpaceGame e una NUOVA sessione dell'agente (una per milestone).
rem Auto mode: i comandi innocui passano senza domande, quelli rischiosi li blocca il classificatore; i deny di .claude/settings.json restano validi.
setlocal
cd /d D:\CweAgent\projects\SpaceGame

rem 1) Database
tasklist /fi "imagename eq mysqld.exe" | find /i "mysqld.exe" >nul
if errorlevel 1 (
  echo Avvio MySQL...
  start "MySQL - non chiudere" /min "D:\xampp\mysql\bin\mysqld.exe" --defaults-file="D:\xampp\mysql\bin\my.ini" --standalone
) else (
  echo MySQL gia' attivo.
)

rem 2) Proxy Free Claude Code
call :fcc_up
if errorlevel 1 (
  echo Avvio Free Claude Code...
  start "FCC server - non chiudere" /min "D:\CweAgent\tools\free-claude-code\fcc-server.cmd"
  rem attesa massima 2 minuti (60 x 2 s)
  for /l %%i in (1,1,60) do (
    call :fcc_up && goto fcc_ok
    timeout /t 2 /nobreak >nul
  )
  echo ERRORE: Free Claude Code non risponde su http://127.0.0.1:8082
  pause
  exit /b 1
)
:fcc_ok
echo Free Claude Code attivo.

rem 3) File temporanei dell'agente dentro il progetto (letture senza richiesta di permesso)
if not exist "D:\CweAgent\projects\SpaceGame\.tmp" mkdir "D:\CweAgent\projects\SpaceGame\.tmp"
set "TEMP=D:\CweAgent\projects\SpaceGame\.tmp"
set "TMP=D:\CweAgent\projects\SpaceGame\.tmp"
set "CLAUDE_CODE_TMPDIR=D:\CweAgent\projects\SpaceGame\.tmp"

rem 4) Nuova sessione dell'agente sulla prossima milestone
call "D:\CweAgent\tools\free-claude-code\fcc-claude.cmd" --permission-mode auto %* "Leggi docs/PROMPT-MILESTONE.md e seguilo alla lettera."
exit /b 0

:fcc_up
powershell -NoProfile -Command "try { Invoke-WebRequest http://127.0.0.1:8082/admin -UseBasicParsing -TimeoutSec 3 | Out-Null; exit 0 } catch { exit 1 }"
exit /b %errorlevel%
