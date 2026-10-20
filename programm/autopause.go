package main

import (
	"context"
	"time"

	"github.com/ncruces/zenity"
)

// Pausenpflicht (§ 4 ArbZG): 15 Min. vor Ablauf von 6 Std. am Stück fragt das Programm nach
// der Pause. Reagiert niemand (oder „Später"), trägt es die Pause bei Erreichen der Grenze
// selbst ein – so lässt sich die Grenze mit dem Programm nicht überschreiten. Offene
// Pausenfenster werden dabei geschlossen. Beendet wird die Pause wie immer nur auf Klick.

// Kontext für Pausen-Dialoge: Die automatische Pause schließt sie.
func (a *App) pauseDialogKontext() (context.Context, context.CancelFunc) {
	ctx, abbruch := context.WithCancel(context.Background())
	a.merkerMu.Lock()
	a.pauseAbbruch = abbruch
	a.merkerMu.Unlock()
	return ctx, abbruch
}

func (a *App) pauseDialogeSchliessen() {
	a.merkerMu.Lock()
	abbruch := a.pauseAbbruch
	a.pauseAbbruch = nil
	a.merkerMu.Unlock()
	if abbruch != nil {
		abbruch()
	}
}

// Stufen: 15 Min. vor der Grenze warnen, 5 Min. vorher erneut (die erste Warnung wird dabei
// ersetzt, falls sie noch offen ist), an der Grenze die Pause eintragen. Liefert true, wenn
// es um die Pause ging – dann keine weiteren Erinnerungen in diesem Takt.
func (a *App) pausenpflicht(s *Status, block int) bool {
	a.merkerMu.Lock()
	if s.Zustand != "da" {
		a.pauseStufe = 0 // nach einer Pause beginnt ein neuer Block
	}
	stufe := a.pauseStufe
	a.merkerMu.Unlock()

	maxBlock := s.Regeln.MaxBlock
	if s.Zustand != "da" || maxBlock <= 0 {
		return false
	}
	neueStufe := 0
	switch {
	case block >= maxBlock-1:
		go a.autoPause(block)
		return true
	case block >= maxBlock-5 && stufe < 2:
		neueStufe = 2
	case block >= maxBlock-15 && stufe < 1:
		neueStufe = 1
	default:
		return false
	}
	a.merkerMu.Lock()
	a.pauseStufe = neueStufe
	a.merkerMu.Unlock()
	a.pauseDialogeSchliessen() // erste Warnung noch offen? Durch die zweite ersetzen.
	go a.pauseErinnern(block, maxBlock)
	return true
}

// Warnung vor der Grenze; nennt die Uhrzeit der automatischen Pause.
func (a *App) pauseErinnern(block, maxBlock int) {
	um := time.Now().Add(time.Duration(maxBlock-1-block) * time.Minute)
	ctx, abbruch := a.pauseDialogKontext()
	defer abbruch()
	text := "Du arbeitest seit " + dauer(block) + " Std. ohne Pause.\nNach " + dauer(maxBlock) + " Std. ist eine Pause Pflicht.\n\n" +
		"Startest du sie nicht selbst, trägt das Programm die Pause um " + um.Format("15:04") + " Uhr automatisch ein.\n\nPause jetzt starten?"
	if a.frage(text, "Pause starten", "Später", "", zenity.Context(ctx)) == antwortJa {
		a.pauseStarten()
	}
}

// Grenze erreicht: Pause buchen, dann Bescheid geben.
func (a *App) autoPause(block int) {
	if !a.autoPauseLaeuft.CompareAndSwap(false, true) {
		return
	}
	defer a.autoPauseLaeuft.Store(false)

	a.pauseDialogeSchliessen()
	i := a.verbindung()
	if i == nil {
		return
	}
	_, err := i.Stempeln("pause")
	if err != nil {
		protokoll("Automatische Pause: " + err.Error())
		a.aktualisieren()
		return
	}
	jetzt := time.Now()
	a.merkerMu.Lock()
	a.pauseBis = jetzt.Add(30 * time.Minute)
	delete(a.spaeter, "pause")
	delete(a.spaeter, "weiter")
	a.merkerMu.Unlock()
	protokoll("Automatische Pause nach " + dauer(block) + " Std. am Stück")
	a.aktualisieren()

	a.dialog.Store(true)
	_ = zenity.Info("Du hast "+dauer(block)+" Std. ohne Pause gearbeitet.\n\n"+
		"Die Pause wurde um "+jetzt.Format("15:04")+" Uhr automatisch eingetragen (§ 4 ArbZG).\n"+
		"Damit sie zählt, beende sie frühestens nach 15 Minuten.", zenity.Title(titel))
	a.dialog.Store(false)
}
