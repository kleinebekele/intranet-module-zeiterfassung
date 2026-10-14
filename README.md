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
| Mitglied einer **Gruppe** (eine Rolle; unter Einstellungen neu anlegen oder vorhandene übernehmen, dort auch Personen, Leitung, Sollzeit) | nimmt teil, stempelt am Terminal |
| Leitung einer Gruppe | Zeiten der Mitglieder sehen und ändern, Anträge freigeben, Sollzeiten pflegen |
| `zeit-verwaltung` | alle Teilnehmer, Einstellungen, eigene Nachträge sofort gültig |
| `zeit-homeoffice` | Stempeln im Intranet |
| `zeit-nachtrag-frei` / `zeit-nachtrag` | eigene Nachträge sofort bzw. mit Freigabe |
| `zeit-dienstplan` | Abteilungen einrichten, Dienstpläne aller Abteilungen erstellen (Verwaltung darf das auch) |
| Person einer Abteilung | sieht deren veröffentlichte Dienstpläne |

## Terminal

Unter Einstellungen → Terminals anlegen; die angezeigte Adresse enthält den geheimen Schlüssel des Terminals und
wird am Tablet als Startseite geöffnet. Zusätzlich muss mindestens ein Netz eingetragen sein, aus dem das Terminal
kommt – ohne Netz ist es gesperrt. Chips, die im Kantinen-Modul zugeordnet sind, gelten mit.

Persönliche Codes werden immer zufällig erzeugt (mindestens 6 Ziffern; ältere kürzere Codes bleiben gültig). Nach
10 falschen Codes in 5 Minuten sperrt ein Terminal die Code-Eingabe für 5 Minuten; Chips funktionieren weiter.

## Dienstplan-Vorschlag

Soll je Tag aus dem Zeitmodell bzw. den Wochenstunden. Feiertage und Abwesenheiten werden gutgeschrieben (mindern
das Soll), dem Mitarbeiter fehlt dadurch nichts. Geplant wird je Kalenderwoche:

1. **Tage und Stunden** der Stammkräfte: das Wochensoll auf so viele Tage, wie die Person je Woche arbeitet – dort,
   wo am meisten Bedarf offen ist; Samstage reihum. Kurze Tage (Samstag) gleichen längere Tage aus, bis zur
   Öffnungszeit bzw. Tageshöchstzeit.
2. **Früh/Spät im Wechsel**: Wer bisher seltener früh war, öffnet; wer seltener spät war, schließt.
3. **Öffnungszeit immer besetzt**: Aushilfe im Budget, sonst angrenzende Schicht verlängern, sonst Stammkraft an
   ihrem freien Tag. Was über das Wochensoll geht, wird als Überstunden markiert. Optionale Besetzung nur mit
   Aushilfen-Budget.

4. **Aushilfen** (je Abteilung einstellbar): immer ihre volle Zeit (Monatsbudget bzw. Wochenstunden) dort, wo am
   wenigsten los ist – oder nur bei Lücken.

**Sollzeit und Kernarbeitszeit** je Person stehen im Zeitmodell (Einstellungen → Gruppe); automatisch geplante
Schichten der Stammkräfte decken die Kernarbeitszeit ab. In der Abteilung lässt sich festlegen, wie viele Stunden der
Sollzeit dort zur Verfügung stehen (leer = alle).

Gleich gute Möglichkeiten entscheidet das Los – „Neu vorschlagen" liefert jedes Mal eine andere Variante.
Ruhezeit, Tageshöchstzeit und Pausen aus den Einstellungen gelten immer; beantragter Urlaub blockiert wie genehmigter.
Von Hand: Zelle anklicken, optional für alle gleichen Wochentage des Monats. Je Woche zeigt „Σ KW" Plan und Soll.

## Aufbewahrung

Das Arbeitszeitgesetz verlangt, Aufzeichnungen mindestens zwei Jahre aufzubewahren. Das Modul löscht nichts selbst.
