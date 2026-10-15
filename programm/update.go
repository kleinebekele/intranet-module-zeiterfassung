package main

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"fyne.io/systray"
	"github.com/ncruces/zenity"
)

// Automatisches Update: Das Intranet meldet im Status die neueste Version samt Prüfsumme.
// Ist sie neuer, lädt das Programm sie im Hintergrund, prüft die Prüfsumme und tauscht
// sich selbst aus. Unter Windows lässt sich eine laufende .exe umbenennen (nicht
// überschreiben): alt → Zeiterfassung.alt.exe, neu → Zeiterfassung.exe, neu starten, beenden.

const nachUpdateSchalter = "--nach-update"

type ProgrammInfo struct {
	Version string `json:"version"`
	Sha256  string `json:"sha256"`
}

// neuer meldet, ob Version a neuer ist als b („1.2.10" > „1.2.9").
func neuer(a, b string) bool {
	ta, tb := strings.Split(a, "."), strings.Split(b, ".")
	for i := 0; i < len(ta) || i < len(tb); i++ {
		var x, y int
		if i < len(ta) {
			x, _ = strconv.Atoi(ta[i])
		}
		if i < len(tb) {
			y, _ = strconv.Atoi(tb[i])
		}
		if x != y {
			return x > y
		}
	}
	return false
}

func (a *App) updatePruefen(s *Status) {
	if s == nil || s.Programm == nil || !neuer(s.Programm.Version, Version) || a.dialog.Load() {
		return
	}
	if !a.updateLaeuft.CompareAndSwap(false, true) {
		return
	}
	go func() {
		defer a.updateLaeuft.Store(false)
		if err := a.aktualisiereProgramm(*s.Programm); err != nil {
			protokoll("Update auf " + s.Programm.Version + " fehlgeschlagen: " + err.Error())
		}
	}()
}

func (a *App) aktualisiereProgramm(info ProgrammInfo) error {
	i := a.verbindung()
	if i == nil {
		return fmt.Errorf("keine Verbindung")
	}
	exe, err := os.Executable()
	if err != nil {
		return err
	}
	ordner := filepath.Dir(exe)
	neu := filepath.Join(ordner, "Zeiterfassung.neu.exe")
	alt := filepath.Join(ordner, "Zeiterfassung.alt.exe")

	if err := i.ladeProgramm(neu, info.Sha256); err != nil {
		_ = os.Remove(neu)
		return err
	}

	// Tauschen: laufende Datei beiseite, neue an ihren Platz.
	_ = os.Remove(alt)
	if err := os.Rename(exe, alt); err != nil {
		_ = os.Remove(neu)
		return fmt.Errorf("alte Version ließ sich nicht umbenennen: %w", err)
	}
	if err := os.Rename(neu, exe); err != nil {
		_ = os.Rename(alt, exe) // zurück auf die alte Version
		return fmt.Errorf("neue Version ließ sich nicht einsetzen: %w", err)
	}

	protokoll("Update " + Version + " → " + info.Version)
	if err := exec.Command(exe, nachUpdateSchalter).Start(); err != nil {
		_ = os.Remove(exe)
		_ = os.Rename(alt, exe)
		return fmt.Errorf("neue Version startet nicht: %w", err)
	}
	// Kein „beendet"-Vermerk: Das Lebenszeichen läuft in der neuen Version nahtlos weiter.
	systray.Quit()
	return nil
}

func (i *Intranet) ladeProgramm(ziel, sha string) error {
	req, err := http.NewRequest("GET", i.Adresse+"/zeit-app/v1/programm", nil)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+i.Schluessel)
	req.Header.Set("User-Agent", "Zeiterfassung-PC/"+Version)
	client := &http.Client{Timeout: 5 * time.Minute}
	res, err := client.Do(req)
	if err != nil {
		return err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return fmt.Errorf("Download: HTTP %d", res.StatusCode)
	}

	f, err := os.Create(ziel)
	if err != nil {
		return err
	}
	h := sha256.New()
	_, err = io.Copy(io.MultiWriter(f, h), io.LimitReader(res.Body, 100<<20))
	f.Close()
	if err != nil {
		return err
	}
	if got := hex.EncodeToString(h.Sum(nil)); !strings.EqualFold(got, sha) {
		return fmt.Errorf("Prüfsumme passt nicht (%s statt %s)", got, sha)
	}
	return nil
}

// Nach dem Neustart: alte Datei wegräumen und kurz Bescheid geben.
func aufraeumenNachUpdate() {
	exe, err := os.Executable()
	if err != nil {
		return
	}
	alt := filepath.Join(filepath.Dir(exe), "Zeiterfassung.alt.exe")
	for n := 0; n < 20; n++ {
		if err := os.Remove(alt); err == nil || os.IsNotExist(err) {
			return
		}
		time.Sleep(500 * time.Millisecond) // die alte Version beendet sich gerade noch
	}
}

func meldeUpdate() {
	_ = zenity.Notify("Die Zeiterfassung wurde auf Version "+Version+" aktualisiert.", zenity.Title(titel))
}
