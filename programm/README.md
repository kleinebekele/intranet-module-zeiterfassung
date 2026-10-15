# Zeiterfassung – PC-Programm (Windows)

Kleines Programm im Infobereich (neben der Uhr). Es **misst nichts**: Es fragt beim Windows-Start
„Arbeitszeit starten?“, erinnert an Pause (vor 6 Std. am Stück), Feierabend (Schichtende laut Dienstplan,
sonst Tagessoll, sonst Ende der Kernzeit) und die Höchstarbeitszeit – gebucht wird nur, was man anklickt.

- **Einrichten:** Im Intranet unter „Meine Zeiten → PC-Programm“ herunterladen, „Gerät verbinden“, den
  Verbindungscode beim ersten Start einfügen. Das Programm kopiert sich nach `%LOCALAPPDATA%\Programs\Zeiterfassung`
  und trägt sich in den Autostart des Benutzers ein (abschaltbar im Menü). Keine Adminrechte nötig.
- **Lebenszeichen:** jede Minute in `%APPDATA%\Zeiterfassung\lebenszeichen.txt`. War der Rechner aus oder länger
  als 2 Std. im Ruhezustand, während man eingestempelt war, schlägt es das letzte Lebenszeichen als Ende vor.
  „Beenden“ über das Menü fragt nach „Gehen“ und hinterlässt keinen Vorschlag.
- **Protokoll:** `%APPDATA%\Zeiterfassung\protokoll.txt` (Fehlersuche).
- **Automatisches Update:** Das Intranet meldet im Status die neueste Version (aus `resources/programm/version.json`).
  Ist sie neuer, lädt das Programm sie still nach, prüft die SHA-256-Prüfsumme, benennt die laufende Datei in
  `Zeiterfassung.alt.exe` um, setzt die neue ein und startet neu (`--nach-update`, ohne Startfragen).

**Neue Version veröffentlichen:** Nummer in `VERSION` erhöhen, `.\bauen.ps1` (Go nötig) – schreibt
`resources/programm/Zeiterfassung.exe` und `version.json`. Beides committen, Modul taggen, deployen: Die Programme
aktualisieren sich innerhalb von 5 Minuten selbst. Schnittstelle: siehe README des Moduls („PC-Programm“).

Nicht signiert: Windows SmartScreen fragt beim ersten Start nach („Weitere Informationen → Trotzdem ausführen“).
