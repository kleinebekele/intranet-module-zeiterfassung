package main

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/ncruces/zenity"
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
	if dauerAbwesend >= abwesendAbEnde && von.Format("2006-01-02") != bis.Format("2006-01-02") {
		a.endeAutomatisch(von)
		return
	}
	if dauerAbwesend >= abwesendAbEnde {
		a.endeVorschlagen(von, grund)
		return
	}

	text := fmt.Sprintf("%s – von %s bis %s Uhr (%s Std.).\n\nWar das eine Pause?",
		grund, von.Format("15:04"), bis.Format("15:04"), dauer(int(dauerAbwesend.Minutes())))
	if a.frage(text, "Ja, als Pause eintragen", "Nein, das war Arbeitszeit", "") != antwortJa {
		protokoll("Abwesenheit " + von.Format("15:04") + "–" + bis.Format("15:04") + " bleibt Arbeitszeit")
		a.pflichtpauseNachtragen(von, bis)
		return
	}
	if i := a.verbindung(); i != nil {
		meldung, err := i.PauseNachtragen(von, bis)
		a.ergebnis(meldung, err)
	}
	a.aktualisieren()
}

// Die Abwesenheit bleibt Arbeitszeit, aber inzwischen war eine Pause Pflicht (§ 4 ArbZG):
// darauf hinweisen und den Beginn der Pause erfragen. Ohne Angabe beginnt sie mit der
// Abwesenheit (Rechner aus bzw. gesperrt). Die Dauer ist die fehlende Mindestpause.
func (a *App) pflichtpauseNachtragen(von, bis time.Time) {
	a.aktualisieren()
	s := a.aktuell()
	if s == nil || s.Zustand != "da" {
		return
	}
	minuten := fehlendePause(s, a.heuteBisher(s, time.Now()), s.BlockMinuten+int(time.Since(a.geholtAm).Minutes()))
	if minuten == 0 {
		return
	}

	a.dialog.Store(true)
	_ = zenity.Info(fmt.Sprintf("Bis jetzt war eine Pause von mindestens %d Minuten Pflicht (§ 4 ArbZG).\n\n"+
		"Gib im nächsten Fenster an, wann du sie gemacht hast.\nOhne Angabe beginnt sie um %s Uhr (Rechner weg).",
		minuten, von.Format("15:04")), zenity.Title(titel), fensterSymbol(), zenity.OKLabel("Weiter"))
	a.dialog.Store(false)

	for {
		beginn := von
		a.dialog.Store(true)
		eingabe, err := zenity.Entry("Beginn der Pause (HH:MM):", zenity.Title(titel), fensterSymbol(),
			zenity.EntryText(von.Format("15:04")), zenity.OKLabel("Pause eintragen"), zenity.CancelLabel("Abbrechen"))
		a.dialog.Store(false)
		if err == nil {
			t, ok := heuteUm(strings.TrimSpace(eingabe))
			if !ok {
				a.fehler("Bitte die Uhrzeit als HH:MM angeben, z. B. " + von.Format("15:04"))
				continue
			}
			beginn = t
		}
		ende := beginn.Add(time.Duration(minuten) * time.Minute)
		if ende.After(time.Now()) {
			if err == nil {
				a.fehler("Die Pause würde in der Zukunft enden – bitte einen früheren Beginn angeben.")
				continue
			}
			ende = time.Now() // Abbrechen bei kurzer Abwesenheit: Pause bis jetzt
		}
		i := a.verbindung()
		if i == nil {
			return
		}
		meldung, ferr := i.PauseNachtragen(beginn, ende, "Pflichtpause während der Abwesenheit vom Rechner – im PC-Programm nachgetragen")
		if ferr != nil {
			a.fehler(ferr.Error())
			if err != nil {
				return // schon „Abbrechen“ gewählt: nicht endlos fragen
			}
			continue
		}
		a.ergebnis(meldung, nil)
		protokoll(fmt.Sprintf("Pflichtpause nachgetragen: %s–%s", beginn.Format("15:04"), ende.Format("15:04")))
		a.aktualisieren()
		return
	}
}

// Fehlende Mindestpause in Minuten (0 = alles in Ordnung): 15 Min. nach 6 Std. am Stück,
// insgesamt 30 Min. ab 6 Std. und 45 Min. ab 9 Std. Arbeit; jede Pause mindestens 15 Min.
func fehlendePause(s *Status, heute, block int) int {
	pause := ruhepausen(s)
	fehlt := 0
	if s.Regeln.MaxBlock > 0 && block > s.Regeln.MaxBlock {
		fehlt = 15
	}
	if heute > 360 && pause < 30 {
		fehlt = max(fehlt, 30-pause)
	}
	if heute > 540 && pause < 45 {
		fehlt = max(fehlt, 45-pause)
	}
	if fehlt > 0 {
		fehlt = max(fehlt, 15)
	}
	return fehlt
}

// Rechner am Vortag ausgeschaltet, ohne zu gehen: Das Arbeitsende ist der Zeitpunkt des
// Ausschaltens (laut Lebenszeichen) – eintragen und Bescheid geben, dann die Startfrage.
func (a *App) endeAutomatisch(ende time.Time) {
	i := a.verbindung()
	if i == nil {
		return
	}
	meldung, minuten, err := i.gehenNachtragen(ende, true)
	if err != nil {
		a.ergebnis(meldung, err)
		return
	}
	protokoll("Arbeitsende automatisch: " + ende.Format("02.01. 15:04"))
	gesamt := ""
	if minuten >= 0 {
		gesamt = " nach insgesamt " + dauer(minuten) + " Std. Arbeitszeit"
	}
	tag := "gestern"
	if ende.Format("2006-01-02") != time.Now().AddDate(0, 0, -1).Format("2006-01-02") {
		tag = "am " + ende.Format("02.01.")
	}
	a.hinweis("Deine Arbeitszeit wurde " + tag + " mit dem Herunterfahren um " + ende.Format("15:04") + " Uhr" + gesamt + " automatisch beendet.\n\nStimmt das nicht, korrigiere es unter „Meine Zeiten“ im Intranet.")
	a.aktualisieren()
	if s := a.aktuell(); s != nil && s.Zustand == "weg" {
		a.merkerMu.Lock()
		delete(a.erledigt, "start")
		a.merkerMu.Unlock()
		a.frageStart()
	}
}
