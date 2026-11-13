package main

import (
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync/atomic"
	"time"

	"fyne.io/systray"
	"github.com/ncruces/zenity"
)

// „Gehen in …": Gehen in 5–60 Minuten vormerken. Gebucht wird zur gewählten Zeit – auch wenn der
// Rechner dann gesperrt, im Ruhezustand oder aus ist: Die Zeit liegt in gehen-geplant.txt, und
// wer später zurückkommt (bzw. das Programm neu startet), bekommt das Gehen zur geplanten Zeit
// nachgetragen. Kommen oder Gehen von Hand hebt den Plan auf.

var gehenInMinuten = []int{5, 10, 15, 20, 30, 45, 60}

// Liegt die geplante Zeit länger zurück, war der Rechner aus bzw. schlief – dann nachtragen.
const gehenPuenktlich = 90 * time.Second

var gehenBuchtGerade atomic.Bool

func gehenPlanDatei() string { return filepath.Join(datenOrdner(), "gehen-geplant.txt") }

func (a *App) gehenMenueAnlegen() {
	a.mGehenIn = systray.AddMenuItem("Gehen in …", "Gehen automatisch zur gewählten Zeit buchen")
	for _, m := range gehenInMinuten {
		punkt := a.mGehenIn.AddSubMenuItem(strconv.Itoa(m)+" Minuten", "")
		go func() {
			for range punkt.ClickedCh {
				a.gehenPlanen(time.Now().Add(time.Duration(m) * time.Minute))
			}
		}()
	}
	a.mGehenAufheben = a.mGehenIn.AddSubMenuItem("Geplantes Gehen aufheben", "")
	a.mGehenAufheben.Hide()
	go func() {
		for range a.mGehenAufheben.ClickedCh {
			a.gehenPlanLoeschen()
			protokoll("Geplantes Gehen aufgehoben")
			_ = zenity.Notify("Geplantes Gehen aufgehoben.", zenity.Title(titel), fensterSymbol())
			a.menueSetzen()
		}
	}()
}

// Geplante Zeit (Null = kein Plan). Pläne von gestern oder älter gelten nicht mehr.
func (a *App) gehenGeplant() time.Time {
	inhalt, err := os.ReadFile(gehenPlanDatei())
	if err != nil {
		return time.Time{}
	}
	t, err := time.Parse(time.RFC3339, strings.TrimSpace(string(inhalt)))
	if err != nil || time.Since(t) > 12*time.Hour {
		return time.Time{}
	}
	return t.Local()
}

func (a *App) gehenPlanen(um time.Time) {
	um = um.Truncate(time.Minute)
	if err := os.WriteFile(gehenPlanDatei(), []byte(um.Format(time.RFC3339)), 0o600); err != nil {
		go a.folge(true, func() { a.fehler("Geplantes Gehen ließ sich nicht speichern: " + err.Error()) })
		return
	}
	protokoll("Gehen geplant für " + um.Format("15:04"))
	a.gehenWecker(um)
	_ = zenity.Notify("Gehen wird um "+um.Format("15:04")+" Uhr gebucht.\nAufheben: Rechtsklick → Gehen in … → Geplantes Gehen aufheben.", zenity.Title(titel), fensterSymbol())
	a.menueSetzen()
}

func (a *App) gehenPlanLoeschen() {
	_ = os.Remove(gehenPlanDatei())
}

// Pünktlich buchen, ohne auf den Minutentakt zu warten (der Takt prüft zusätzlich).
func (a *App) gehenWecker(um time.Time) {
	time.AfterFunc(time.Until(um), func() { a.gehenPruefen(time.Now()) })
}

// Fällig? Dann buchen. true = es wurde gebucht (oder versucht) – der Takt fragt dann nichts weiter.
func (a *App) gehenPruefen(jetzt time.Time) bool {
	um := a.gehenGeplant()
	if um.IsZero() || jetzt.Before(um) {
		return false
	}
	if !gehenBuchtGerade.CompareAndSwap(false, true) {
		return true
	}
	defer gehenBuchtGerade.Store(false)

	i := a.verbindung()
	if i == nil {
		return false // ohne Verbindung beim nächsten Takt erneut
	}
	a.aktualisieren()
	s := a.aktuell()
	if s == nil {
		return false
	}
	if s.Zustand == "weg" {
		a.gehenPlanLoeschen() // schon ausgestempelt
		a.menueSetzen()
		return false
	}

	var meldung string
	var err error
	if jetzt.Sub(um) <= gehenPuenktlich {
		meldung, err = i.Stempeln("gehen")
	} else {
		// Rechner war aus/gesperrt/im Ruhezustand: Ende zur geplanten Zeit eintragen.
		meldung, err = i.GehenGeplantNachtragen(um)
		if err != nil && s.Zustand == "pause" {
			meldung, err = i.Stempeln("gehen") // in der Pause endet der Tag mit der Pause
		}
	}
	if err != nil {
		protokoll("Geplantes Gehen: " + err.Error())
		if jetzt.Sub(um) < 10*time.Minute {
			return true // kurz darauf noch einmal versuchen (z. B. Netz noch nicht da)
		}
		a.gehenPlanLoeschen()
		go a.folge(true, func() {
			a.fehler("Das geplante Gehen um " + um.Format("15:04") + " Uhr ließ sich nicht buchen:\n" + err.Error())
		})
		a.aktualisieren()
		return true
	}
	a.gehenPlanLoeschen()
	protokoll("Gebucht: gehen (geplant " + um.Format("15:04") + ")")
	a.vergissAbwesend() // die Abwesenheit danach ist Feierabend, nichts mehr fragen
	a.merkerMu.Lock()
	delete(a.spaeter, "pause")
	delete(a.spaeter, "weiter")
	delete(a.spaeter, "feierabend")
	a.vorlageBis = time.Time{}
	a.merkerMu.Unlock()
	_ = zenity.Notify(meldung, zenity.Title(titel), fensterSymbol())
	a.aktualisieren()
	return true
}

// Menü: „Gehen in …" nur, wenn Gehen möglich ist; „aufheben" nur mit Plan.
func (a *App) gehenMenueSetzen(gehenMoeglich bool) {
	if gehenMoeglich {
		a.mGehenIn.Enable()
	} else {
		a.mGehenIn.Disable()
	}
	if um := a.gehenGeplant(); !um.IsZero() {
		a.mGehenIn.SetTitle("Gehen um " + um.Format("15:04") + " Uhr geplant")
		a.mGehenAufheben.Show()
	} else {
		a.mGehenIn.SetTitle("Gehen in …")
		a.mGehenAufheben.Hide()
	}
}

func (i *Intranet) GehenGeplantNachtragen(um time.Time) (string, error) {
	var a antwort
	err := i.anfrage("POST", "gehen-nachtragen", url.Values{
		"zeit":        {um.Format(time.RFC3339)},
		"laut_geraet": {"1"},
		"notiz":       {"Gehen im PC-Programm für " + um.Format("15:04") + " Uhr geplant"},
	}, &a)
	return a.Meldung, err
}
