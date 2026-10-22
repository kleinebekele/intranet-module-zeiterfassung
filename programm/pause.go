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
	ctx, abbruch := a.pauseDialogKontext() // schließt die automatische Pause
	defer abbruch()
	a.dialog.Store(true)
	wahl, err := zenity.List("Wie lange machst du Pause?",
		[]string{"15 Minuten", "30 Minuten", "45 Minuten", "60 Minuten"},
		zenity.Title(titel), fensterSymbol(), zenity.DefaultItems("30 Minuten"), zenity.OKLabel("Starten"), zenity.CancelLabel("Abbrechen"), zenity.Context(ctx))
	a.dialog.Store(false)
	if err != nil || strings.TrimSpace(wahl) == "" {
		return
	}
	minuten, _ := strconv.Atoi(strings.Fields(wahl)[0])
	bis := time.Now().Add(time.Duration(minuten) * time.Minute)

	extra := url.Values{}
	if s := a.aktuell(); s != nil && s.Teams != nil {
		a.dialog.Store(true)
		nachricht, err := zenity.Entry("Statusnachricht für Teams (leer = keine):", zenity.Title(titel), fensterSymbol(),
			zenity.EntryText("In der Pause – zurück um "+bis.Format("15:04")),
			zenity.OKLabel("Starten"), zenity.CancelLabel("Ohne Text"), zenity.Context(ctx))
		a.dialog.Store(false)
		if ctx.Err() != nil {
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
