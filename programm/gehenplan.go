package main

import (
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"time"

	"fyne.io/systray"
	"github.com/ncruces/zenity"
)

// „Gehen in …": Gehen in 5–60 Minuten vormerken. Der Plan liegt im Intranet (Status-Feld
// vormerkung.gehen_um), und gebucht wird dort: beim nächsten Status-Abruf des Programms oder
// spätestens durch den Minuten-Task – auch wenn der Rechner dann aus ist. Längere Verspätung
// trägt das Intranet zur geplanten Zeit nach. Kommen oder Gehen hebt den Plan auf.

var gehenInMinuten = []int{5, 10, 15, 20, 30, 45, 60}

func (a *App) gehenMenueAnlegen() {
	_ = os.Remove(filepath.Join(datenOrdner(), "gehen-geplant.txt")) // bis 1.15: Plan lag lokal
	a.mGehenIn = systray.AddMenuItem("Gehen in …", "Gehen automatisch zur gewählten Zeit buchen")
	for _, m := range gehenInMinuten {
		punkt := a.mGehenIn.AddSubMenuItem(strconv.Itoa(m)+" Minuten", "")
		go func() {
			for range punkt.ClickedCh {
				a.gehenPlanen(m)
			}
		}()
	}
	a.mGehenAufheben = a.mGehenIn.AddSubMenuItem("Geplantes Gehen aufheben", "")
	a.mGehenAufheben.Hide()
	go func() {
		for range a.mGehenAufheben.ClickedCh {
			a.gehenPlanSenden(0)
		}
	}()
}

// Geplante Zeit laut Intranet (Null = kein Plan).
func gehenGeplantIn(s *Status) time.Time {
	if s == nil || s.Vormerkung.GehenUm == "" {
		return time.Time{}
	}
	t, err := time.Parse(time.RFC3339, s.Vormerkung.GehenUm)
	if err != nil {
		return time.Time{}
	}
	return t.Local()
}

func (a *App) gehenGeplant() time.Time { return gehenGeplantIn(a.aktuell()) }

func (a *App) gehenPlanen(minuten int) {
	if um := a.gehenPlanSenden(minuten); !um.IsZero() {
		a.gehenWecker(um)
	}
}

// Plan im Intranet setzen (minuten = 0: aufheben). Gibt die geplante Zeit zurück.
func (a *App) gehenPlanSenden(minuten int) time.Time {
	i := a.verbindung()
	if i == nil {
		return time.Time{}
	}
	meldung, um, err := i.GehenPlanen(minuten)
	if err != nil {
		go a.folge(true, func() { a.fehler(err.Error()) })
		return time.Time{}
	}
	if minuten == 0 {
		protokoll("Geplantes Gehen aufgehoben")
	} else {
		protokoll("Gehen geplant für " + um.Format("15:04"))
		meldung += "\nAufheben: Rechtsklick → Gehen in … → Geplantes Gehen aufheben."
	}
	_ = zenity.Notify(meldung, zenity.Title(titel), fensterSymbol())
	a.aktualisieren()
	return um
}

// Pünktlich nachsehen, ohne auf den Minutentakt zu warten: Der Status-Abruf bucht im Intranet.
func (a *App) gehenWecker(um time.Time) {
	time.AfterFunc(time.Until(um)+2*time.Second, func() { a.gehenPruefen(time.Now()) })
}

// Fällig? Dann den Status holen – das Intranet bucht dabei. true = fällig gewesen.
func (a *App) gehenPruefen(jetzt time.Time) bool {
	um := a.gehenGeplant()
	if um.IsZero() || jetzt.Before(um) {
		return false
	}
	a.aktualisieren()
	return true
}

// Das Intranet hat das geplante Gehen gebucht (Status-Abruf oder Task): melden und aufräumen.
func (a *App) gehenGebucht(meldung string) {
	protokoll("Gebucht: gehen (geplant) – " + meldung)
	a.vergissAbwesend() // die Abwesenheit danach ist Feierabend, nichts mehr fragen
	a.merkerMu.Lock()
	delete(a.spaeter, "pause")
	delete(a.spaeter, "weiter")
	delete(a.spaeter, "feierabend")
	a.vorlageBis = time.Time{}
	a.merkerMu.Unlock()
	_ = zenity.Notify(meldung, zenity.Title(titel), fensterSymbol())
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

func (i *Intranet) GehenPlanen(minuten int) (string, time.Time, error) {
	var a struct {
		antwort
		Um string `json:"um"`
	}
	err := i.anfrage("POST", "gehen-planen", url.Values{"minuten": {strconv.Itoa(minuten)}}, &a)
	um, _ := time.Parse(time.RFC3339, a.Um)
	if !um.IsZero() {
		um = um.Local()
	}
	return a.Meldung, um, err
}
