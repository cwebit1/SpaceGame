# Guardiano dell'agente: se la sessione della coda non scrive niente per $Minuti
# (es. ferma su un API error), la chiude; lavora-coda.cmd riparte da solo col giro dopo.
param([int]$Minuti = 25)
$cartella = "$env:USERPROFILE\.claude\projects\D--CweAgent-projects-SpaceGame"
$log = "D:\CweAgent\projects\SpaceGame\.tmp\guardiano.log"
New-Item -ItemType Directory -Force (Split-Path $log) | Out-Null
function Scrivi($m) { $r = "$(Get-Date -Format 'dd/MM HH:mm:ss')  $m"; Add-Content $log $r; Write-Host $r }

Scrivi "Guardiano avviato (soglia $Minuti minuti)"
while ($true) {
    Start-Sleep -Seconds 300
    if (Test-Path "D:\CweAgent\projects\SpaceGame\STOP") { Scrivi "STOP trovato, esco"; break }
    $coda = Get-CimInstance Win32_Process -Filter "Name='cmd.exe'" | Where-Object { $_.CommandLine -match 'lavora-coda' }
    if (-not $coda) { Scrivi "lavora-coda non attivo, esco"; break }
    $agente = Get-CimInstance Win32_Process -Filter "Name='fcc-claude.exe'" | Where-Object { $_.CommandLine -match 'LAVORO-IN-CODA' }
    if (-not $agente) { continue }
    $avvio = ($agente | Sort-Object CreationDate | Select-Object -First 1).CreationDate
    $trascr = Get-ChildItem $cartella -Filter *.jsonl | Where-Object { $_.CreationTime -ge $avvio.AddSeconds(-10) } |
              Sort-Object LastWriteTime -Descending | Select-Object -First 1
    $ultimo = if ($trascr) { $trascr.LastWriteTime } else { $avvio }
    $fermo = ((Get-Date) - $ultimo).TotalMinutes
    if ($fermo -gt $Minuti) {
        Scrivi ("Agente fermo da {0:N0} minuti: lo chiudo, lavora-coda riparte col giro dopo" -f $fermo)
        foreach ($p in $agente) { taskkill /PID $p.ProcessId /T /F | Out-Null }
    }
}
