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
- **Dienstplan**: Abteilungen mit Besetzung je Wochentag (mindestens / optional zusätzlich), Personen (Stammkraft
  oder Aushilfe mit Monatsbudget, Wochenstunden, feste freie Tage) und Sondertagen. Daraus ein Monatsvorschlag, der
  Feiertage, Abwesenheiten und das Arbeitszeitgesetz beachtet; danach von Hand anpassen, prüfen, veröffentlichen, drucken.
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
| `zeit-dienstplan` | Abteilungen einrichten, Dienstpläne aller Abteilungen erstellen (Verwaltung darf das auch) |
| Person einer Abteilung | sieht deren veröffentlichte Dienstpläne |

## Terminal

Unter Einstellungen → Terminals anlegen; die angezeigte Adresse enthält den geheimen Schlüssel des Terminals und
wird am Tablet als Startseite geöffnet. Optional auf Netze beschränkbar. Chips, die im Kantinen-Modul
zugeordnet sind, gelten mit.

## Dienstplan-Vorschlag

1. **Arbeitstage** der Stammkräfte: so viele, wie das Monatssoll hergibt (Zeitmodell bzw. Wochenstunden; Feiertage und
   Abwesenheiten zählen nicht), höchstens die Tage je Woche – jeweils dort, wo noch am meisten Bedarf offen ist.
   So liegen freie Tage versetzt; Samstage rotieren.
2. **Uhrzeiten**: jede Stammkraft bekommt ihre Tageslänge dort, wo sie die meisten offenen Viertelstunden abdeckt
   (früh/spät im Wechsel).
3. **Lücken**: erst die Mindestbesetzung mit Aushilfen (im Budget), notfalls mit freien Stammkräften (Überstunden);
   danach die optionale Besetzung nur noch mit Aushilfen-Budget.

Dabei gelten Ruhezeit, Tageshöchstzeit und Pausen aus den Einstellungen, höchstens sechs Tage am Stück. Beantragter
Urlaub blockiert wie genehmigter. Jede Änderung von Hand wird sofort neu geprüft (Unterbesetzung, Soll, Ruhezeit …).

## Aufbewahrung

Das Arbeitszeitgesetz verlangt, Aufzeichnungen mindestens zwei Jahre aufzubewahren. Das Modul löscht nichts selbst.
