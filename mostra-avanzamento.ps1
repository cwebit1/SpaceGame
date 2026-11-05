# Legge l'output stream-json dell'agente e mostra a schermo cosa sta facendo.
$OutputEncoding = [Console]::OutputEncoding = [Text.Encoding]::UTF8
function Corto([string]$s, [int]$n) {
    if ($null -eq $s) { return '' }
    $s = ($s -replace '\s+', ' ').Trim()
    if ($s.Length -gt $n) { return $s.Substring(0, $n) + '...' }
    return $s
}
while ($null -ne ($riga = [Console]::In.ReadLine())) {
    try { $e = $riga | ConvertFrom-Json } catch { continue }
    $ora = Get-Date -Format 'HH:mm:ss'
    if ($e.type -eq 'assistant') {
        foreach ($c in $e.message.content) {
            if ($c.type -eq 'text' -and $c.text) {
                Write-Host "$ora  $(Corto $c.text 400)" -ForegroundColor White
            } elseif ($c.type -eq 'tool_use') {
                $i = $c.input
                $dettaglio = if ($i.command) { $i.command } elseif ($i.file_path) { $i.file_path } elseif ($i.pattern) { $i.pattern } else { '' }
                Write-Host "$ora  > $($c.name): $(Corto $dettaglio 150)" -ForegroundColor Cyan
            }
        }
    } elseif ($e.type -eq 'result') {
        Write-Host "$ora  FINE GIRO ($($e.subtype)): $(Corto $e.result 400)" -ForegroundColor Green
    }
}
