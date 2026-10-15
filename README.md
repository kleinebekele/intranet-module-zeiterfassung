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

## PC-Programm

Unter „Meine Zeiten“ verbindet man ein Gerät (PC/Mac) und bekommt einmalig Adresse + Schlüssel für das Programm.
Das Programm misst nichts: Es fragt beim Start „Arbeitszeit starten?“, erinnert anhand von Kernzeit, Dienstplan und
Arbeitszeitgesetz an Pause und Feierabend und bucht nur, was man anklickt. War man weg vom Rechner (gesperrt,
Ruhezustand, aus), fragt es beim Zurückkommen: ab 15 Min. „War das eine Pause?“, ab 2 Std. „Arbeitsende um …?“ –
die Zeiten stammen aus dem lokalen Protokoll des Programms. Bestätigt gilt das sofort; eine andere Endzeit nur mit
Nachtragsrecht „sofort gültig“.

Voraussetzung: Rolle `zeit-homeoffice` (Stempeln im Intranet) bzw. Verwaltung. Schnittstelle (`Authorization: Bearer …`):

| Aufruf | Zweck |
|---|---|
| `GET zeit-app/v1/status` | Zustand, heutige Arbeitszeit, Soll, Kernzeit, Schicht laut Dienstplan, Regeln |
| `POST zeit-app/v1/stempeln` | `aktion` = kommen, pause, weiter, gehen |
| `POST zeit-app/v1/gehen-nachtragen` | `zeit` (ISO 8601), `laut_geraet` (0/1), optional `notiz` |
| `POST zeit-app/v1/pause-nachtragen` | `von`, `bis` (ISO 8601) – Abschnitt endet am Pausenbeginn, neuer beginnt am Ende |
| `GET zeit-app/v1/programm` | die aktuelle `Zeiterfassung.exe` (automatisches Update; Version + SHA-256 stehen im Status) |

## Teams-Status (nur mit Microsoft-Anmeldung)

Freiwillig je Person unter „Meine Zeiten → Teams koppeln“: Die Person stimmt mit ihrem Microsoft-Konto der delegierten
Berechtigung `Presence.ReadWrite` zu (nur der eigene Status). Danach stellt **Gehen** Teams auf „Offline“
(`setUserPreferredPresence`), **Pause** auf „Bin gleich zurück“, **Kommen** bzw. Pause beenden zurück auf den automatischen
Status (`clearUserPreferredPresence`). Im PC-Programm lässt sich zur Pause eine Teams-Statusnachricht mitgeben
(`setStatusMessage`); sie bleibt wie „Bin gleich zurück“ bis zum Pausenende stehen und wird dann entfernt – egal ob am
Terminal, im Intranet oder im PC-Programm gebucht. Stellt die Person Teams selbst auf „Als offline anzeigen“ bzw. wieder
auf verfügbar, fragt das PC-Programm, ob die Arbeitszeit enden bzw. beginnen soll.

**Statusvorlagen** (je Person, unter Meine Zeiten → Teams): Name, Dauer 15–300 Min., Teams-Status (Nicht stören,
Beschäftigt, Abwesend, Bin gleich zurück, Offline), Statustext, zurück auf online automatisch oder nach Rückfrage, optional
zugleich Pause buchen. Im PC-Programm per Rechtsklick → „Statusvorlagen“ (`zeit-app/v1/vorlage`, `status-zuruecksetzen`).

Einrichtung in der Entra-App (dieselbe wie die Microsoft-Anmeldung): delegierte Berechtigung **Presence.ReadWrite** und
Umleitungs-URI `{APP_URL}/modules/zeiterfassung/meine/teams/zurueck`. Der Refresh-Token liegt verschlüsselt in `zeit_teams`.

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

**Sollzeit** je Person = Wochenstunden (Einstellungen → Gruppe). Die Tage mit **Kernarbeitszeit** sind die
Arbeitstage, auf sie verteilt die Zeiterfassung die Wochenstunden (ohne Kernzeit: Mo–Fr). Für den Dienstplan ist die
Kernzeit eine Orientierung: bevorzugte Tage, und Schichten decken sie möglichst zu 80 % ab. In der Abteilung lässt
sich festlegen, wie viele Stunden der Sollzeit dort zur Verfügung stehen (leer = alle).

Gleich gute Möglichkeiten entscheidet das Los – „Neu vorschlagen" liefert jedes Mal eine andere Variante.
Ruhezeit, Tageshöchstzeit und Pausen aus den Einstellungen gelten immer; beantragter Urlaub blockiert wie genehmigter.
Von Hand: Zelle anklicken, optional für alle gleichen Wochentage des Monats. Je Woche zeigt „Σ KW" Plan und Soll.

## Aufbewahrung

Das Arbeitszeitgesetz verlangt, Aufzeichnungen mindestens zwei Jahre aufzubewahren. Das Modul löscht nichts selbst.
