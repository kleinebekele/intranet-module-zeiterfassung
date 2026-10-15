# Zeiterfassung – PC-Programm (Windows)

Kleines Programm im Infobereich (neben der Uhr). Es **misst nichts**: Es fragt beim Windows-Start
„Arbeitszeit starten?“, erinnert an Pause (vor 6 Std. am Stück), Feierabend (Schichtende laut Dienstplan,
sonst Tagessoll, sonst Ende der Kernzeit) und die Höchstarbeitszeit – gebucht wird nur, was man anklickt.

- **Einrichten:** Im Intranet unter „Meine Zeiten → PC-Programm“ herunterladen, „Gerät verbinden“, den
  Verbindungscode beim ersten Start einfügen. Das Programm kopiert sich nach `%LOCALAPPDATA%\Programs\Zeiterfassung`
  und trägt sich in den Autostart des Benutzers ein (abschaltbar im Menü). Keine Adminrechte nötig.
- **Weg vom Rechner** (gesperrt, Ruhezustand, abgemeldet, aus): Das Programm merkt sich nur den Beginn (lokal,
  `lebenszeichen.txt` jede Minute, `abwesend-seit.txt` beim Sperren) und bucht nichts. Beim Zurückkommen:
  unter 15 Min. nichts (bleibt Arbeitszeit), ab 15 Min. „War das eine Pause?“ (Ja → Pause rückwirkend), ab 2 Std.
  „Arbeitsende um …?“. Solange gesperrt, keine Erinnerungen. „Beenden“ über das Menü fragt nach „Gehen“.
- **Pause:** Fenster mit 15/30/45/60 Min. und – bei gekoppeltem Teams – Statusnachricht. Nach der gewählten Dauer
  fragt das Programm nur „Pause beenden?“; ohne Antwort bleibt es Pause (nichts wird automatisch gebucht).
- **Statusvorlagen:** Rechtsklick → „Statusvorlagen“ zeigt die eigenen Vorlagen aus dem Intranet (Meine Zeiten → Teams):
  Teams-Status + Text für 5 Min. oder 15–300 Min., danach automatisch zurück oder nach Rückfrage; optional zugleich Pause buchen.
  „Teams wieder auf Verfügbar“ beendet eine Vorlage vorzeitig.
  Bei abgelaufener Vorlage bzw. Pause: „Erneut erinnern …“ in 5/10/15/20/30/45/60 Min.
- **Höchstarbeitszeit (10 Std.):** Fenster „Feierabend (Gehen buchen)“ oder „Pause vergessen“ – dann Dauer (15–300 Min.)
  und Beginn wählen, die Pause wird nachgetragen und es geht weiter.
- **Teams:** Ist Teams unter „Meine Zeiten“ gekoppelt, fragt das Programm bei einem Wechsel auf „Offline“ (während
  eingestempelt) nach dem Arbeitsende und beim Wechsel zurück auf online nach dem Arbeitsbeginn.
- **Protokoll:** `%APPDATA%\Zeiterfassung\protokoll.txt` (Fehlersuche).
- **Automatisches Update:** Das Intranet meldet im Status die neueste Version (aus `resources/programm/version.json`).
  Ist sie neuer, lädt das Programm sie still nach, prüft die SHA-256-Prüfsumme, benennt die laufende Datei in
  `Zeiterfassung.alt.exe` um, setzt die neue ein und startet neu (`--nach-update`, ohne Startfragen).

**Neue Version veröffentlichen:** Nummer in `VERSION` erhöhen, `.\bauen.ps1` (Go nötig) – schreibt
`resources/programm/Zeiterfassung.exe` und `version.json`. Beides committen, Modul taggen, deployen: Die Programme
aktualisieren sich innerhalb von 5 Minuten selbst. Schnittstelle: siehe README des Moduls („PC-Programm“).

Nicht signiert: Windows SmartScreen fragt beim ersten Start nach („Weitere Informationen → Trotzdem ausführen“).
