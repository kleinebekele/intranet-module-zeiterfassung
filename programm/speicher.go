package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// Alles liegt im Benutzerprofil: %APPDATA%\Zeiterfassung (Einstellungen, Lebenszeichen)
// und %LOCALAPPDATA%\Programs\Zeiterfassung (das Programm selbst, für den Autostart).

type Einstellungen struct {
	Adresse    string `json:"adresse"`
	Schluessel string `json:"schluessel"`
}

func datenOrdner() string {
	basis, err := os.UserConfigDir()
	if err != nil {
		basis = os.TempDir()
	}
	ordner := filepath.Join(basis, "Zeiterfassung")
	_ = os.MkdirAll(ordner, 0o700)
	return ordner
}

func ladeEinstellungen() (Einstellungen, bool) {
	var e Einstellungen
	inhalt, err := os.ReadFile(filepath.Join(datenOrdner(), "einstellungen.json"))
	if err != nil || json.Unmarshal(inhalt, &e) != nil || e.Adresse == "" || e.Schluessel == "" {
		return e, false
	}
	return e, true
}

func speichereEinstellungen(e Einstellungen) error {
	inhalt, _ := json.MarshalIndent(e, "", "  ")
	return os.WriteFile(filepath.Join(datenOrdner(), "einstellungen.json"), inhalt, 0o600)
}

// Lebenszeichen: jede Minute die aktuelle Uhrzeit. Fehlt es eine Weile, war der Rechner
// aus oder im Ruhezustand. „beendet" heißt: das Programm wurde ordentlich geschlossen –
// dann wird beim nächsten Start nichts vorgeschlagen.

func lebenszeichenDatei() string { return filepath.Join(datenOrdner(), "lebenszeichen.txt") }

func schreibeLebenszeichen(t time.Time) {
	_ = os.WriteFile(lebenszeichenDatei(), []byte(t.Format(time.RFC3339)), 0o600)
}

func markiereBeendet() {
	_ = os.WriteFile(lebenszeichenDatei(), []byte("beendet"), 0o600)
}

// Letztes Lebenszeichen – ok=false, wenn keins da ist oder das Programm ordentlich beendet wurde.
func letztesLebenszeichen() (time.Time, bool) {
	inhalt, err := os.ReadFile(lebenszeichenDatei())
	if err != nil {
		return time.Time{}, false
	}
	t, err := time.Parse(time.RFC3339, strings.TrimSpace(string(inhalt)))
	if err != nil {
		return time.Time{}, false
	}
	return t.Local(), true
}

// Kleines Protokoll für die Fehlersuche (letzte ~200 KB).
func protokoll(text string) {
	datei := filepath.Join(datenOrdner(), "protokoll.txt")
	if info, err := os.Stat(datei); err == nil && info.Size() > 200_000 {
		_ = os.Rename(datei, datei+".alt")
	}
	f, err := os.OpenFile(datei, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600)
	if err != nil {
		return
	}
	defer f.Close()
	_, _ = f.WriteString(time.Now().Format("2006-01-02 15:04:05") + "  " + text + "\r\n")
}
