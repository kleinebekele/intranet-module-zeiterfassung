# Baut das PC-Programm nach resources/programm/Zeiterfassung.exe (von dort bietet es das Intranet zum Download an).
# Voraussetzung: Go (https://go.dev). Aufruf im Ordner programm:  .\bauen.ps1
$ErrorActionPreference = 'Stop'
$env:GOOS = 'windows'
$env:GOARCH = 'amd64'
go vet ./...
go test ./...
go build -trimpath -ldflags "-s -w -H windowsgui" -o ..\resources\programm\Zeiterfassung.exe .
Write-Host 'Fertig: resources\programm\Zeiterfassung.exe'
