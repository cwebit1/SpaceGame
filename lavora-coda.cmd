@echo off
rem Fa lavorare l'agente sulla coda docs\LAVORO-IN-CODA.md senza presidio.
rem Ogni giro e' una sessione FCC interattiva (si vede tutto). Quando la sessione si chiude (/exit, crash, Ctrl+C due volte), parte il giro dopo dal primo punto non spuntato.
rem Si ferma quando la coda e' finita, dopo 30 giri, oppure se esiste il file STOP nella cartella del progetto.
setlocal
cd /d D:\CweAgent\projects\SpaceGame

tasklist /fi "imagename eq mysqld.exe" | find /i "mysqld.exe" >nul
if errorlevel 1 start "MySQL - non chiudere" /min "D:\xampp\mysql\bin\mysqld.exe" --defaults-file="D:\xampp\mysql\bin\my.ini" --standalone

for /l %%i in (1,1,30) do (
  if exist STOP goto fine
  findstr /b /c:"CODA-FINITA" docs\LAVORO-IN-CODA.md >nul && goto fine
  echo.
  echo ===== Giro %%i - %date% %time% =====
  call :fcc_up || start "FCC server - non chiudere" /min "D:\CweAgent\tools\free-claude-code\fcc-server.cmd"
  call :attendi_fcc
  call "D:\CweAgent\tools\free-claude-code\fcc-claude.cmd" --permission-mode auto "Leggi docs/LAVORO-IN-CODA.md e segui le sue regole: fai il primo punto non spuntato, poi il successivo, finche' puoi."
  timeout /t 30 /nobreak >nul
)

:fine
echo Coda terminata o fermata. Controlla docs\LAVORO-IN-CODA.md
exit /b 0

:attendi_fcc
for /l %%j in (1,1,60) do (
  call :fcc_up && exit /b 0
  timeout /t 2 /nobreak >nul
)
exit /b 1

:fcc_up
powershell -NoProfile -Command "try { Invoke-WebRequest http://127.0.0.1:8082/admin -UseBasicParsing -TimeoutSec 3 | Out-Null; exit 0 } catch { exit 1 }"
exit /b %errorlevel%
