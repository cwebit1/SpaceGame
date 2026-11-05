@echo off
rem EMERGENZA: chiude la sessione dell'agente bloccata. Se lavora-coda.cmd e' attivo riparte da solo col giro dopo;
rem se non e' attivo, lo avvia qui. Avvia anche il guardiano se manca.
setlocal
cd /d D:\CweAgent\projects\SpaceGame
powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='fcc-claude.exe'\" | Where-Object { $_.CommandLine -match 'LAVORO-IN-CODA|PROMPT-MILESTONE' } | ForEach-Object { taskkill /PID $_.ProcessId /T /F | Out-Null; 'Chiusa sessione agente PID ' + $_.ProcessId }"
powershell -NoProfile -Command "if (-not (Get-CimInstance Win32_Process -Filter \"Name='powershell.exe'\" | Where-Object { $_.CommandLine -match 'guardiano.ps1' })) { Start-Process powershell -WindowStyle Minimized -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','D:\CweAgent\projects\SpaceGame\guardiano.ps1'; 'Guardiano avviato' }"
powershell -NoProfile -Command "if (Get-CimInstance Win32_Process -Filter \"Name='cmd.exe'\" | Where-Object { $_.CommandLine -match 'lavora-coda' }) { exit 0 } else { exit 1 }"
if errorlevel 1 (
  echo lavora-coda non attivo: lo avvio qui.
  call lavora-coda.cmd
) else (
  echo lavora-coda attivo: ripartira' da solo col giro dopo.
)
