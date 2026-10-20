package main

import (
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
)

const autostartSchluessel = `Software\Microsoft\Windows\CurrentVersion\Run`
const autostartName = "Zeiterfassung"

// Nur eine Instanz je Benutzer. Der Mutex bleibt bis zum Programmende offen.
func einzigeInstanz() bool {
	name, _ := windows.UTF16PtrFromString(`Local\Zeiterfassung-PC`)
	h, err := windows.CreateMutex(nil, false, name)
	if err == windows.ERROR_ALREADY_EXISTS {
		// Handle schließen – sonst hielte er den Mutex am Leben und ein späterer Versuch
		// (Neustart nach Update) würde ewig „läuft bereits" sehen.
		_ = windows.CloseHandle(h)
		return false
	}
	return true
}

// Zielort ohne Adminrechte: %LOCALAPPDATA%\Programs\Zeiterfassung\Zeiterfassung.exe
func installationsPfad() string {
	basis := os.Getenv("LOCALAPPDATA")
	if basis == "" {
		basis, _ = os.UserConfigDir()
	}
	return filepath.Join(basis, "Programs", "Zeiterfassung", "Zeiterfassung.exe")
}

// Läuft das Programm von woanders (z. B. aus „Downloads"), kopiert es sich an den festen
// Ort, startet von dort und gibt true zurück – dann soll diese Instanz sich beenden.
func installiereBeiBedarf() (neuGestartet bool, err error) {
	exe, err := os.Executable()
	if err != nil {
		return false, err
	}
	ziel := installationsPfad()
	if strings.EqualFold(filepath.Clean(exe), filepath.Clean(ziel)) {
		merkeVersion()
		return false, nil
	}
	// Ein alter Download darf eine neuere Installation nicht überschreiben (sie aktualisiert
	// sich selbst). Dann einfach die installierte Fassung starten.
	if _, err := os.Stat(ziel); err == nil && !neuer(Version, installierteVersion()) {
		protokoll("Gestartet aus " + exe + " (" + Version + "), installiert ist " + installierteVersion() + " – starte die installierte")
		if err := exec.Command(ziel).Start(); err != nil {
			return false, err
		}
		return true, nil
	}
	if err := os.MkdirAll(filepath.Dir(ziel), 0o755); err != nil {
		return false, err
	}
	if err := kopiere(exe, ziel); err != nil {
		return false, err
	}
	merkeVersion()
	if err := exec.Command(ziel).Start(); err != nil {
		return false, err
	}
	return true, nil
}

// Version der installierten Fassung, als Datei daneben. Fehlt sie (vor 1.5.4 installiert),
// gilt „0" – dann darf jede neuere Datei drüber.
func versionsDatei() string { return filepath.Join(filepath.Dir(installationsPfad()), "version.txt") }

func merkeVersion() { _ = os.WriteFile(versionsDatei(), []byte(Version), 0o644) }

func installierteVersion() string {
	inhalt, err := os.ReadFile(versionsDatei())
	if err != nil {
		return "0"
	}
	return strings.TrimSpace(string(inhalt))
}

func kopiere(von, nach string) error {
	q, err := os.Open(von)
	if err != nil {
		return err
	}
	defer q.Close()
	z, err := os.Create(nach + ".neu")
	if err != nil {
		return err
	}
	if _, err := io.Copy(z, q); err != nil {
		z.Close()
		return err
	}
	z.Close()
	_ = os.Remove(nach)
	return os.Rename(nach+".neu", nach)
}

func autostartAn() bool {
	k, err := registry.OpenKey(registry.CURRENT_USER, autostartSchluessel, registry.QUERY_VALUE)
	if err != nil {
		return false
	}
	defer k.Close()
	_, _, err = k.GetStringValue(autostartName)
	return err == nil
}

func setzeAutostart(an bool) error {
	k, _, err := registry.CreateKey(registry.CURRENT_USER, autostartSchluessel, registry.SET_VALUE)
	if err != nil {
		return err
	}
	defer k.Close()
	if !an {
		err := k.DeleteValue(autostartName)
		if err == registry.ErrNotExist {
			return nil
		}
		return err
	}
	return k.SetStringValue(autostartName, `"`+installationsPfad()+`"`)
}

func oeffneImBrowser(adresse string) {
	_ = exec.Command("rundll32", "url.dll,FileProtocolHandler", adresse).Start()
}
