package main

import (
	"time"

	"github.com/ncruces/zenity"
)

// Pausenpflicht (§ 4 ArbZG): 15 Min. vor Ablauf von 6 Std. am Stück fragt das Programm nach
// der Pause, 5 Min. vorher noch einmal. Reagiert niemand (oder „Später"), trägt es die Pause
// bei Erreichen der Grenze selbst ein – so lässt sich die Grenze mit dem Programm nicht
// überschreiten. Jede Stufe schließt alle offenen Fenster (a.dringend, fenster.go), es steht
// also immer nur die aktuelle Meldung da. Beendet wird die Pause wie immer nur auf Klick.

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
		// nur einmal anstoßen – sonst schlösse der nächste Takt den Hinweis gleich wieder
		if a.autoPauseLaeuft.CompareAndSwap(false, true) {
			go func() {
				defer a.autoPauseLaeuft.Store(false)
				a.dringend(func() { a.autoPause(block) })
			}()
		}
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
	go a.dringend(func() { a.pauseErinnern(block, maxBlock) }) // erste Warnung noch offen? Wird ersetzt.
	return true
}

// Warnung vor der Grenze; nennt die Uhrzeit der automatischen Pause.
func (a *App) pauseErinnern(block, maxBlock int) {
	um := time.Now().Add(time.Duration(maxBlock-1-block) * time.Minute)
	text := "Du arbeitest seit " + dauer(block) + " Std. ohne Pause.\nNach " + dauer(maxBlock) + " Std. ist eine Pause Pflicht.\n\n" +
		"Startest du sie nicht selbst, trägt das Programm die Pause um " + um.Format("15:04") + " Uhr automatisch ein.\n\nPause jetzt starten?"
	if a.frage(text, "Pause starten", "Später", "") == antwortJa {
		a.pauseStarten()
	}
}

// Grenze erreicht: Pause buchen, dann Bescheid geben.
func (a *App) autoPause(block int) {
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

	_ = zenity.Info("Du hast "+dauer(block)+" Std. ohne Pause gearbeitet.\n\n"+
		"Die Pause wurde um "+jetzt.Format("15:04")+" Uhr automatisch eingetragen (§ 4 ArbZG).\n"+
		"Damit sie zählt, beende sie frühestens nach 15 Minuten.", zenity.Title(titel), fensterSymbol(), a.kontext())
}
