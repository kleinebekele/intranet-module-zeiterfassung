package main

import (
	"fmt"
	"net/url"
	"strconv"
	"time"

	"fyne.io/systray"
)

// Statusvorlagen aus dem Intranet (Meine Zeiten → Teams): Rechtsklick → Statusvorlagen.
// Das Menü hat einen festen Vorrat an Unterpunkten, die je nach Liste beschriftet,
// gezeigt oder versteckt werden – systray kann Menüpunkte nicht entfernen.

const vorlagenPlaetze = 20

type Vorlage struct {
	ID          int    `json:"id"`
	Name        string `json:"name"`
	Minuten     int    `json:"minuten"`
	Status      string `json:"status"`
	Text        string `json:"text"`
	Automatisch bool   `json:"automatisch"`
	Pause       bool   `json:"pause"`
}

type vorlageAntwort struct {
	antwort
	Bis        string `json:"bis"`
	Rueckfrage bool   `json:"rueckfrage"`
	Pause      bool   `json:"pause"`
}

func (i *Intranet) VorlageAnwenden(id int) (*vorlageAntwort, error) {
	var a vorlageAntwort
	err := i.anfrage("POST", "vorlage", url.Values{"id": {strconv.Itoa(id)}}, &a)
	return &a, err
}

func (i *Intranet) StatusZuruecksetzen() (string, error) {
	var a antwort
	err := i.anfrage("POST", "status-zuruecksetzen", url.Values{}, &a)
	return a.Meldung, err
}

func (a *App) vorlagenMenueAnlegen() {
	a.mVorlagen = systray.AddMenuItem("Statusvorlagen", "Teams-Status für eine Weile setzen")
	for n := 0; n < vorlagenPlaetze; n++ {
		punkt := a.mVorlagen.AddSubMenuItem("", "")
		punkt.Hide()
		a.vorlagePunkte = append(a.vorlagePunkte, punkt)
		go func(n int, punkt *systray.MenuItem) {
			for range punkt.ClickedCh {
				go a.vorlageWaehlen(n)
			}
		}(n, punkt)
	}
	a.mOnline = a.mVorlagen.AddSubMenuItem("Teams wieder auf „Verfügbar“", "Vorlage beenden")
	go func() {
		for range a.mOnline.ClickedCh {
			go a.teamsZurueck()
		}
	}()
	a.mVorlagen.Hide()
}

// Beschriftungen aus dem aktuellen Status übernehmen.
func (a *App) vorlagenMenueSetzen(s *Status) {
	if a.mVorlagen == nil {
		return
	}
	a.mu.Lock()
	// Das Intranet schickt Vorlagen nur bei gekoppeltem Teams. Ein Fehler beim Lesen des
	// Teams-Status (s.Teams leer) darf das Menü nicht verstecken.
	a.vorlagen = nil
	if s != nil {
		a.vorlagen = s.Vorlagen
	}
	liste := a.vorlagen
	a.mu.Unlock()

	if len(liste) == 0 {
		a.mVorlagen.Hide()
		return
	}
	a.mVorlagen.Show()
	for n, punkt := range a.vorlagePunkte {
		if n >= len(liste) {
			punkt.Hide()
			continue
		}
		v := liste[n]
		zusatz := ""
		if v.Pause {
			zusatz = ", Pause"
		}
		punkt.SetTitle(fmt.Sprintf("%s  (%s, %d Min.%s)", v.Name, v.Status, v.Minuten, zusatz))
		punkt.Show()
	}
}

func (a *App) vorlageWaehlen(n int) {
	a.mu.Lock()
	if n >= len(a.vorlagen) {
		a.mu.Unlock()
		return
	}
	v := a.vorlagen[n]
	a.mu.Unlock()

	i := a.verbindung()
	if i == nil {
		return
	}
	res, err := i.VorlageAnwenden(v.ID)
	a.ergebnis(res.Meldung, err)
	if err == nil {
		bis, _ := time.Parse(time.RFC3339, res.Bis)
		a.merkerMu.Lock()
		if res.Pause {
			// Die Pausen-Erinnerung fragt nach der Dauer „Pause beenden?" – das setzt Teams mit zurück.
			a.pauseBis = bis.Local()
			delete(a.spaeter, "weiter")
		} else if res.Rueckfrage {
			a.vorlageBis, a.vorlageName = bis.Local(), v.Name
			delete(a.spaeter, "vorlage")
		}
		a.merkerMu.Unlock()
		protokoll("Statusvorlage: " + v.Name)
	}
	a.aktualisieren()
}

func (a *App) teamsZurueck() {
	i := a.verbindung()
	if i == nil {
		return
	}
	meldung, err := i.StatusZuruecksetzen()
	a.ergebnis(meldung, err)
	if err == nil {
		a.merkerMu.Lock()
		a.vorlageBis = time.Time{}
		a.merkerMu.Unlock()
	}
	a.aktualisieren()
}

// Erinnerung: Vorlage mit Rückfrage ist abgelaufen → „Teams wieder auf verfügbar?"
func (a *App) vorlageErinnern() {
	a.merkerMu.Lock()
	bis, name := a.vorlageBis, a.vorlageName
	a.merkerMu.Unlock()
	if bis.IsZero() || time.Now().Before(bis) || !a.faellig("vorlage") || a.dialog.Load() {
		return
	}
	go func() {
		if a.frage("Die Statusvorlage „"+name+"“ ist abgelaufen.\n\nTeams wieder auf „Verfügbar“ stellen?", "Ja, verfügbar", "Erneut erinnern …", "") == antwortJa {
			a.teamsZurueck()
			return
		}
		a.erneutErinnern("vorlage")
	}()
}
