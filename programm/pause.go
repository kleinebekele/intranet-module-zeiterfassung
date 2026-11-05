package main

import (
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/ncruces/zenity"
)

// Pausenfenster: Dauer wählen (15/30/45/60 Min.) und – bei gekoppeltem Teams – eine
// Statusnachricht. Die Dauer steuert nur, wann das Programm „Pause beenden?" fragt;
// beendet wird die Pause nie von selbst.
func (a *App) pauseStarten() {
	wahl, err := zenity.List("Wie lange machst du Pause?",
		[]string{"15 Minuten", "30 Minuten", "45 Minuten", "60 Minuten"},
		zenity.Title(titel), fensterSymbol(), a.kontext(), zenity.DefaultItems("30 Minuten"), zenity.OKLabel("Starten"), zenity.CancelLabel("Abbrechen"))
	if err != nil || strings.TrimSpace(wahl) == "" {
		return
	}
	minuten, _ := strconv.Atoi(strings.Fields(wahl)[0])
	bis := time.Now().Add(time.Duration(minuten) * time.Minute)

	extra := url.Values{}
	if s := a.aktuell(); s != nil && s.Teams != nil {
		nachricht, err := zenity.Entry("Statusnachricht für Teams (leer = keine):", zenity.Title(titel), fensterSymbol(), a.kontext(),
			zenity.EntryText("In der Pause – zurück um "+bis.Format("15:04")),
			zenity.OKLabel("Starten"), zenity.CancelLabel("Ohne Text"))
		if a.abgebrochen() {
			return // inzwischen automatisch eingetragen
		}
		if err == nil && strings.TrimSpace(nachricht) != "" {
			extra.Set("nachricht", strings.TrimSpace(nachricht))
		}
	}

	i := a.verbindung()
	if i == nil {
		return
	}
	meldung, err := i.Stempeln("pause", extra)
	a.ergebnis(meldung, err)
	if err == nil {
		a.merkerMu.Lock()
		a.pauseBis = bis
		delete(a.spaeter, "weiter")
		delete(a.spaeter, "pause")
		a.merkerMu.Unlock()
		protokoll("Gebucht: pause bis " + bis.Format("15:04"))
	}
	a.aktualisieren()
}
