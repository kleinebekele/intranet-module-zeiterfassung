package main

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"golang.org/x/sys/windows"
)

// Abwesenheit vom Rechner: gesperrt (Win+L), Ruhezustand, abgemeldet oder ausgeschaltet.
// Das Programm bucht dabei nichts – es merkt sich nur den Beginn (lokal) und fragt beim
// Zurückkommen: ab 15 Min. „War das eine Pause?", ab 2 Std. „Arbeitsende um …?".
// Kürzere Unterbrechungen bleiben Arbeitszeit (Besprechung, Telefon, Drucker …).

const (
	abwesendAbPause = 15 * time.Minute
	abwesendAbEnde  = 2 * time.Hour
	// Rechner aus bzw. neu gestartet (z. B. Windows-Updates): bis 30 Min. bleibt es Arbeitszeit.
	neustartAbPause = 30 * time.Minute
)

var (
	user32               = windows.NewLazySystemDLL("user32.dll")
	procOpenInputDesktop = user32.NewProc("OpenInputDesktop")
	procSwitchDesktop    = user32.NewProc("SwitchDesktop")
	procCloseDesktop     = user32.NewProc("CloseDesktop")
)

// Bildschirm gesperrt? Gesperrt ist der Eingabe-Desktop nicht erreichbar bzw. nicht umschaltbar.
func gesperrt() bool {
	const desktopSwitchDesktop = 0x0100
	h, _, _ := procOpenInputDesktop.Call(0, 0, desktopSwitchDesktop)
	if h == 0 {
		return true
	}
	defer procCloseDesktop.Call(h)
	ok, _, _ := procSwitchDesktop.Call(h)
	return ok == 0
}

func abwesendDatei() string { return filepath.Join(datenOrdner(), "abwesend-seit.txt") }

// Beginn der aktuellen Abwesenheit (übersteht Abmelden/Ausschalten).
func (a *App) abwesendSeit() (time.Time, bool) {
	a.merkerMu.Lock()
	defer a.merkerMu.Unlock()
	return a.abwesend, !a.abwesend.IsZero()
}

func (a *App) merkeAbwesend(seit time.Time) {
	a.merkerMu.Lock()
	defer a.merkerMu.Unlock()
	if !a.abwesend.IsZero() {
		return
	}
	a.abwesend = seit
	_ = os.WriteFile(abwesendDatei(), []byte(seit.Format(time.RFC3339)), 0o600)
}

func (a *App) vergissAbwesend() {
	a.merkerMu.Lock()
	defer a.merkerMu.Unlock()
	a.abwesend = time.Time{}
	_ = os.Remove(abwesendDatei())
}

// Vom letzten Lauf übrig: Rechner wurde gesperrt und dann ausgeschaltet/abgemeldet.
func gespeicherteAbwesenheit() (time.Time, bool) {
	inhalt, err := os.ReadFile(abwesendDatei())
	if err != nil {
		return time.Time{}, false
	}
	t, err := time.Parse(time.RFC3339, strings.TrimSpace(string(inhalt)))
	return t.Local(), err == nil
}

// Alle 10 Sekunden: gesperrt → Beginn merken; entsperrt → nachfragen.
func (a *App) sperreBeobachten() {
	for range time.Tick(10 * time.Second) {
		_, abwesend := a.abwesendSeit()
		switch zu := gesperrt(); {
		case zu && !abwesend:
			a.merkeAbwesend(time.Now())
		case !zu && abwesend:
			seit, _ := a.abwesendSeit()
			a.vergissAbwesend()
			go a.abwesenheitKlaeren(seit, time.Now(), "Der Rechner war gesperrt", abwesendAbPause)
		}
	}
}

// Zurück am Rechner: je nach Dauer nichts tun, Pause anbieten oder Arbeitsende vorschlagen.
func (a *App) abwesenheitKlaeren(von, bis time.Time, grund string, abPause time.Duration) {
	dauerAbwesend := bis.Sub(von)
	if dauerAbwesend < abPause {
		return
	}
	a.aktualisieren()
	s := a.aktuell()
	if s == nil {
		return
	}
	if s.Zustand != "da" {
		if s.Zustand == "weg" {
			a.frageStart()
		}
		return
	}
	if beginn, err := time.Parse(time.RFC3339, s.OffenBeginn); err == nil && !von.After(beginn) {
		return // eingestempelt erst nach Beginn der Abwesenheit – nichts zu klären
	}
	if dauerAbwesend >= abwesendAbEnde {
		a.endeVorschlagen(von, grund)
		return
	}

	text := fmt.Sprintf("%s – von %s bis %s Uhr (%s Std.).\n\nWar das eine Pause?",
		grund, von.Format("15:04"), bis.Format("15:04"), dauer(int(dauerAbwesend.Minutes())))
	if a.frage(text, "Ja, als Pause eintragen", "Nein, das war Arbeitszeit", "") != antwortJa {
		protokoll("Abwesenheit " + von.Format("15:04") + "–" + bis.Format("15:04") + " bleibt Arbeitszeit")
		return
	}
	if i := a.verbindung(); i != nil {
		meldung, err := i.PauseNachtragen(von, bis)
		a.ergebnis(meldung, err)
	}
	a.aktualisieren()
}
