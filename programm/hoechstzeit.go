package main

import (
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/ncruces/zenity"
)

// Höchstarbeitszeit erreicht (10 Std.), Rechner noch an: Feierabend bestätigen – oder eine
// vergessene Pause nachtragen (15–300 Min.), dann geht es weiter. Ohne Antwort passiert nichts.
func (a *App) maxErreicht(heuteMin int) {
	if a.dialog.Load() {
		return
	}
	text := "Du hast heute " + dauer(heuteMin) + " Std. gearbeitet – das ist die gesetzliche Höchstarbeitszeit.\n\n" +
		"Feierabend bestätigen – oder hast du zwischendurch eine Pause gemacht und vergessen, sie zu buchen?"
	switch a.frage(text, "Feierabend (Gehen buchen)", "Später", "Pause vergessen") {
	case antwortJa:
		a.stempeln("gehen")
	case antwortExtra:
		if !a.vergessenePause() {
			a.ruhe("max", 5*time.Minute) // abgebrochen: gleich noch einmal fragen
		}
	default:
		a.ruhe("max", 15*time.Minute)
	}
}

// Dauer und Beginn der vergessenen Pause erfragen und nachtragen. true = eingetragen.
func (a *App) vergessenePause() bool {
	dauern := []string{}
	for m := 15; m <= 300; m += 15 {
		dauern = append(dauern, dauerText(m))
	}
	a.dialog.Store(true)
	wahl, err := zenity.List("Wie lange war die Pause?", dauern, zenity.Title(titel), fensterSymbol(),
		zenity.DefaultItems(dauerText(30)), zenity.OKLabel("Weiter"), zenity.CancelLabel("Abbrechen"))
	a.dialog.Store(false)
	if err != nil || wahl == "" {
		return false
	}
	minuten := 0
	for m := 15; m <= 300; m += 15 {
		if dauerText(m) == wahl {
			minuten = m
		}
	}

	vorschlag := "12:00"
	if s := a.aktuell(); s != nil {
		if beginn, err := time.Parse(time.RFC3339, s.OffenBeginn); err == nil {
			// Vorschlag: Mitte des laufenden Abschnitts
			mitte := beginn.Local().Add(time.Since(beginn) / 2).Truncate(15 * time.Minute)
			vorschlag = mitte.Format("15:04")
		}
	}
	for {
		a.dialog.Store(true)
		eingabe, err := zenity.Entry("Wann hat die Pause begonnen? (HH:MM)", zenity.Title(titel), fensterSymbol(),
			zenity.EntryText(vorschlag), zenity.OKLabel("Pause eintragen"), zenity.CancelLabel("Abbrechen"))
		a.dialog.Store(false)
		if err != nil {
			return false
		}
		von, ok := heuteUm(strings.TrimSpace(eingabe))
		if !ok {
			a.fehler("Bitte die Uhrzeit als HH:MM angeben, z. B. " + vorschlag)
			continue
		}
		bis := von.Add(time.Duration(minuten) * time.Minute)
		if bis.After(time.Now()) {
			a.fehler("Die Pause würde in der Zukunft enden – bitte einen früheren Beginn angeben.")
			continue
		}
		i := a.verbindung()
		if i == nil {
			return false
		}
		meldung, err := i.PauseNachtragen(von, bis, "Vergessene Pause – bei Erreichen der Höchstarbeitszeit im PC-Programm nachgetragen")
		if err != nil {
			a.fehler(err.Error())
			continue
		}
		a.ergebnis(meldung, nil)
		protokoll(fmt.Sprintf("Vergessene Pause nachgetragen: %s–%s", von.Format("15:04"), bis.Format("15:04")))
		a.aktualisieren()
		return true
	}
}

func dauerText(m int) string {
	if m < 60 {
		return strconv.Itoa(m) + " Minuten"
	}
	if m%60 == 0 {
		return strconv.Itoa(m/60) + " Std."
	}
	return fmt.Sprintf("%d Std. %d Min.", m/60, m%60)
}
