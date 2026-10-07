# Zeiterfassung

Modul für die modulare Intranet-Plattform ([intranet-core](https://github.com/kleinebekele/intranet-core)).

- **Stempeln** am Terminal (Tablet im Kiosk-Modus: Chip per Web-NFC oder USB-Leser, alternativ persönlicher
  Code) und im Intranet (Homeoffice).
- **Monatsübersicht** mit Soll, Ist, Pausen, Saldo, Stundenkonto und Resturlaub.
- **Nachträge und Korrekturen** – je nach Rolle sofort gültig oder als Antrag mit Freigabe. Nichts wird
  überschrieben: Änderungen legen eine neue Buchung an, die alte bleibt als „ersetzt" erhalten.
- **Abwesenheiten**: Urlaub (mit Genehmigung), Krankheit, Sonderurlaub, Fortbildung, Überstundenabbau …;
  Teamkalender.
- **Arbeitszeitgesetz**: Pausen (§ 4), Höchstarbeitszeit (§ 3), Ruhezeit (§ 5), Sonn- und Feiertage (§ 9),
  48-Stunden-Woche. Warnungen live (Glocke, Terminal) und nachts per Ekkon-Aufgabe
  `Zeiterfassung/Tagesabschluss`; fehlende Pause wird auf Wunsch abgezogen.
- **Feiertage** aller Bundesländer, selbst berechnet.
- **Export**: CSV für den Anwesenheiten-Import von Personio, Tagessummen, Abwesenheiten; Stundenzettel zum Drucken.

## Rechte

Den Seitenzugang regelt der Core (Menüpunkt ↔ Rolle). Darüber hinaus:

| Wer | darf |
|---|---|
| Mitglied einer **Gruppe** (eine Rolle, die unter Einstellungen als Gruppe eingetragen ist) | nimmt teil, stempelt am Terminal |
| Leitung einer Gruppe | Zeiten der Mitglieder sehen und ändern, Anträge freigeben, Sollzeiten pflegen |
| `zeit-verwaltung` | alle Teilnehmer, Einstellungen, eigene Nachträge sofort gültig |
| `zeit-homeoffice` | Stempeln im Intranet |
| `zeit-nachtrag-frei` / `zeit-nachtrag` | eigene Nachträge sofort bzw. mit Freigabe |

## Terminal

Unter Einstellungen → Terminals anlegen; die angezeigte Adresse enthält den geheimen Schlüssel des Terminals und
wird am Tablet als Startseite geöffnet. Optional auf Netze beschränkbar. Chips, die im Kantinen-Modul
zugeordnet sind, gelten mit.

## Aufbewahrung

Das Arbeitszeitgesetz verlangt, Aufzeichnungen mindestens zwei Jahre aufzubewahren. Das Modul löscht nichts selbst.
