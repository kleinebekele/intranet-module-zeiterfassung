package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// Status, wie ihn das Intranet unter zeit-app/v1/status liefert.
type Status struct {
	OK              bool      `json:"ok"`
	Meldung         string    `json:"meldung"`
	Person          string    `json:"person"`
	Zustand         string    `json:"zustand"` // da | pause | weg
	Seit            string    `json:"seit"`
	Aktionen        []string  `json:"aktionen"`
	HeuteMinuten    int       `json:"heute_minuten"`
	BlockMinuten    int       `json:"block_minuten"`
	OffenSeitVortag bool      `json:"offen_seit_vortag"`
	OffenBeginn     string    `json:"offen_beginn"`
	SollHeute       int       `json:"soll_heute_minuten"`
	Kern            *Zeitraum `json:"kern"`
	Schicht         *Zeitraum `json:"schicht"`
	Regeln          struct {
		MaxBlock int `json:"max_block"`
		MaxTag   int `json:"max_tag"`
	} `json:"regeln"`
	Nachtrag string        `json:"nachtrag"`
	Programm *ProgrammInfo `json:"programm"`
	Teams    *TeamsInfo    `json:"teams"` // nur bei Kopplung
}

type TeamsInfo struct {
	Verfuegbarkeit string `json:"verfuegbarkeit"` // Available, Busy, Away, Offline …
	Aktivitaet     string `json:"aktivitaet"`
}

type Zeitraum struct {
	Von string `json:"von"`
	Bis string `json:"bis"`
}

type antwort struct {
	OK      bool   `json:"ok"`
	Meldung string `json:"meldung"`
}

// Fehler mit Klartext aus dem Intranet (z. B. „Du bist bereits eingestempelt.").
type Hinweis struct{ Text string }

func (h Hinweis) Error() string { return h.Text }

type Intranet struct {
	Adresse    string
	Schluessel string
	http       *http.Client
}

func NeuesIntranet(adresse, schluessel string) *Intranet {
	return &Intranet{
		Adresse:    strings.TrimRight(strings.TrimSpace(adresse), "/"),
		Schluessel: strings.TrimSpace(schluessel),
		http:       &http.Client{Timeout: 15 * time.Second},
	}
}

func (i *Intranet) anfrage(methode, pfad string, daten url.Values, ziel any) error {
	var koerper io.Reader
	if daten != nil {
		koerper = strings.NewReader(daten.Encode())
	}
	req, err := http.NewRequest(methode, i.Adresse+"/zeit-app/v1/"+pfad, koerper)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+i.Schluessel)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "Zeiterfassung-PC/"+Version)
	if daten != nil {
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	}

	res, err := i.http.Do(req)
	if err != nil {
		return fmt.Errorf("Intranet nicht erreichbar: %w", err)
	}
	defer res.Body.Close()
	inhalt, _ := io.ReadAll(io.LimitReader(res.Body, 1<<20))

	var a antwort
	if json.Unmarshal(inhalt, &a) != nil {
		return fmt.Errorf("unerwartete Antwort vom Intranet (HTTP %d) – stimmt die Adresse?", res.StatusCode)
	}
	if !a.OK {
		if a.Meldung == "" {
			a.Meldung = fmt.Sprintf("Fehler %d", res.StatusCode)
		}
		return Hinweis{a.Meldung}
	}
	if ziel != nil {
		return json.Unmarshal(inhalt, ziel)
	}
	return nil
}

func (i *Intranet) Status() (*Status, error) {
	var s Status
	if err := i.anfrage("GET", "status", nil, &s); err != nil {
		return nil, err
	}
	return &s, nil
}

func (i *Intranet) Stempeln(aktion string, extra ...url.Values) (string, error) {
	daten := url.Values{"aktion": {aktion}}
	for _, e := range extra {
		for k, v := range e {
			daten[k] = v
		}
	}
	var a antwort
	err := i.anfrage("POST", "stempeln", daten, &a)
	return a.Meldung, err
}

func (i *Intranet) PauseNachtragen(von, bis time.Time) (string, error) {
	var a antwort
	err := i.anfrage("POST", "pause-nachtragen", url.Values{
		"von": {von.Format(time.RFC3339)},
		"bis": {bis.Format(time.RFC3339)},
	}, &a)
	return a.Meldung, err
}

func (i *Intranet) GehenNachtragen(zeit time.Time, lautGeraet bool) (string, error) {
	laut := "0"
	if lautGeraet {
		laut = "1"
	}
	var a antwort
	err := i.anfrage("POST", "gehen-nachtragen", url.Values{
		"zeit":        {zeit.Format(time.RFC3339)},
		"laut_geraet": {laut},
	}, &a)
	return a.Meldung, err
}

// Verbindung pruefen: Fehler 401/403 sind Klartext vom Intranet.
func (i *Intranet) Pruefen() error {
	_, err := i.Status()
	var h Hinweis
	if errors.As(err, &h) {
		return h
	}
	return err
}

// Hilfsfunktion: "HH:MM" heute als Zeitpunkt.
func heuteUm(hhmm string) (time.Time, bool) {
	t, err := time.ParseInLocation("15:04", hhmm, time.Local)
	if err != nil {
		return time.Time{}, false
	}
	n := time.Now()
	return time.Date(n.Year(), n.Month(), n.Day(), t.Hour(), t.Minute(), 0, 0, time.Local), true
}
