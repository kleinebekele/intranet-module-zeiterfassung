# Baut das PC-Programm nach resources/programm/Zeiterfassung.exe und schreibt version.json
# (Version + SHA-256) daneben. Das Intranet bietet die Datei zum Download an und meldet die
# Version im Status – laufende Programme aktualisieren sich dann selbst.
#
# Neue Version: Nummer in der Datei VERSION erhöhen, dann im Ordner programm:  .\bauen.ps1
# Voraussetzung: Go (https://go.dev).
$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
$version = (Get-Content VERSION -Raw).Trim()
if ($version -notmatch '^\d+\.\d+\.\d+$') { throw "VERSION ungültig: $version" }

$env:GOOS = 'windows'
$env:GOARCH = 'amd64'
go vet ./...
if ($LASTEXITCODE) { throw 'go vet fehlgeschlagen' }
go test ./...
if ($LASTEXITCODE) { throw 'Tests fehlgeschlagen' }

$ziel = Join-Path $PSScriptRoot '..\resources\programm\Zeiterfassung.exe'
go build -trimpath -ldflags "-s -w -H windowsgui -X main.Version=$version" -o $ziel .
if ($LASTEXITCODE) { throw 'Bauen fehlgeschlagen' }

$sha = (Get-FileHash $ziel -Algorithm SHA256).Hash.ToLower()
# Ohne BOM schreiben – sonst liest PHP (json_decode) die Datei nicht.
$json = '{"version": "' + $version + '", "sha256": "' + $sha + '"}'
[IO.File]::WriteAllText((Join-Path $PSScriptRoot '..\resources\programm\version.json'), $json, (New-Object Text.UTF8Encoding $false))
Write-Host "Fertig: Zeiterfassung.exe $version ($sha)"
