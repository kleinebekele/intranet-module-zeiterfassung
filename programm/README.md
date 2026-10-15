# Zeiterfassung – PC-Programm (Windows)

Kleines Programm im Infobereich (neben der Uhr). Es **misst nichts**: Es fragt beim Windows-Start
„Arbeitszeit starten?“, erinnert an Pause (vor 6 Std. am Stück), Feierabend (Schichtende laut Dienstplan,
sonst Tagessoll, sonst Ende der Kernzeit) und die Höchstarbeitszeit – gebucht wird nur, was man anklickt.

- **Einrichten:** Im Intranet unter „Meine Zeiten → PC-Programm“ herunterladen, „Gerät verbinden“, Adresse und
  Schlüssel beim ersten Start eintragen. Das Programm kopiert sich nach `%LOCALAPPDATA%\Programs\Zeiterfassung`
  und trägt sich in den Autostart des Benutzers ein (abschaltbar im Menü). Keine Adminrechte nötig.
- **Lebenszeichen:** jede Minute in `%APPDATA%\Zeiterfassung\lebenszeichen.txt`. War der Rechner aus oder länger
  als 2 Std. im Ruhezustand, während man eingestempelt war, schlägt es das letzte Lebenszeichen als Ende vor.
  „Beenden“ über das Menü fragt nach „Gehen“ und hinterlässt keinen Vorschlag.
- **Protokoll:** `%APPDATA%\Zeiterfassung\protokoll.txt` (Fehlersuche).
- **Aktualisieren:** Neue Version herunterladen, laufendes Programm über das Menü beenden, neue Datei starten.

Bauen: `.\bauen.ps1` (Go nötig). Die fertige Datei liegt danach in `resources/programm/` und kommt mit dem
Modul auf den Server. Schnittstelle: siehe README des Moduls („PC-Programm“).

Nicht signiert: Windows SmartScreen fragt beim ersten Start nach („Weitere Informationen → Trotzdem ausführen“).
