package main

import (
	"fmt"
	"net/url"
	"strconv"
	"strings"
	"time"

	"fyne.io/systray"
	"github.com/ncruces/zenity"
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
	a.mNeueVorlage = a.mVorlagen.AddSubMenuItem("Neue Statusvorlage …", "Vorlage hier anlegen (wie im Intranet)")
	go func() {
		for range a.mNeueVorlage.ClickedCh {
			go a.vorlageAnlegen()
		}
	}()
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

	// Auch ohne Vorlagen zeigen, wenn Teams gekoppelt ist – dann gibt es „Neue Statusvorlage …“.
	if len(liste) == 0 && (s == nil || s.Teams == nil) {
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
		if a.frage("Die Statusvorlage „"+name+"“ ist abgelaufen.\n\nTeams wieder auf „Verfügbar“ stellen – oder später erneut erinnern?", "Verfügbar", "Später …", "") == antwortJa {
			a.teamsZurueck()
			return
		}
		a.erneutErinnern("vorlage")
	}()
}

// Teams-Status zur Auswahl: Bezeichnung → Schlüssel im Intranet (Statusvorlage::STATUS).
var vorlagenStatus = []struct{ Name, Wert string }{
	{"Nicht stören", "DoNotDisturb"},
	{"Beschäftigt", "Busy"},
	{"Abwesend", "Away"},
	{"Bin gleich zurück", "BeRightBack"},
	{"Offline", "Offline"},
}

// Neue Statusvorlage Schritt für Schritt: Name, Dauer, Teams-Status, Text, Rückkehr, Pause.
// „Abbrechen“ in den ersten Schritten bricht ab, ohne etwas zu speichern.
func (a *App) vorlageAnlegen() {
	if a.dialog.Load() {
		return
	}
	a.dialog.Store(true)
	defer a.dialog.Store(false)
	opts := func(ok string, mehr ...zenity.Option) []zenity.Option {
		return append([]zenity.Option{zenity.Title(titel + " – Neue Statusvorlage"), fensterSymbol(), zenity.OKLabel(ok), zenity.CancelLabel("Abbrechen")}, mehr...)
	}

	name, err := zenity.Entry("Name der Vorlage (z. B. Telefonat):", opts("Weiter")...)
	name = strings.TrimSpace(name)
	if err != nil || name == "" {
		return
	}

	dauern := []string{dauerText(5)}
	for m := 15; m <= 300; m += 15 {
		dauern = append(dauern, dauerText(m))
	}
	wahl, err := zenity.List("Wie lange?", dauern, opts("Weiter", zenity.DefaultItems(dauerText(30)))...)
	if err != nil || wahl == "" {
		return
	}
	minuten := 5
	for m := 15; m <= 300; m += 15 {
		if dauerText(m) == wahl {
			minuten = m
		}
	}

	namen := []string{}
	for _, st := range vorlagenStatus {
		namen = append(namen, st.Name)
	}
	wahl, err = zenity.List("Teams-Status:", namen, opts("Weiter", zenity.DefaultItems(namen[0]))...)
	if err != nil || wahl == "" {
		return
	}
	status := ""
	for _, st := range vorlagenStatus {
		if st.Name == wahl {
			status = st.Wert
		}
	}

	text, err := zenity.Entry("Statustext für Teams (leer = keiner):", opts("Weiter")...)
	if err != nil {
		return
	}

	rueckkehr := "frage"
	err = zenity.Question("Nach "+dauerText(minuten)+" Teams wieder auf „Verfügbar“ stellen –\nautomatisch oder erst nach Rückfrage?",
		zenity.Title(titel+" – Neue Statusvorlage"), fensterSymbol(), zenity.OKLabel("Nachfragen"), zenity.CancelLabel("Automatisch"))
	if err != nil {
		rueckkehr = "automatisch"
	}

	pause := "0"
	if zenity.Question("Zugleich eine Pause buchen?", zenity.Title(titel+" – Neue Statusvorlage"), fensterSymbol(),
		zenity.OKLabel("Ja"), zenity.CancelLabel("Nein")) == nil {
		pause = "1"
	}

	i := a.verbindung()
	if i == nil {
		return
	}
	var antw antwort
	err = i.anfrage("POST", "vorlage-anlegen", url.Values{
		"name": {name}, "minuten": {strconv.Itoa(minuten)}, "verfuegbarkeit": {status},
		"text": {strings.TrimSpace(text)}, "rueckkehr": {rueckkehr}, "pause": {pause},
	}, &antw)
	a.dialog.Store(false)
	a.ergebnis(antw.Meldung, err)
	if err == nil {
		protokoll("Statusvorlage angelegt: " + name)
	}
	a.aktualisieren()
}
