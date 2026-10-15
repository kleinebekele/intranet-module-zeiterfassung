package main

import (
	"os"
	"testing"
	"time"
)

// Läuft nur mit ZE_ADRESSE und ZE_SCHLUESSEL (Testgerät an einer lokalen Instanz).
func TestSchnittstelle(t *testing.T) {
	adresse, schluessel := os.Getenv("ZE_ADRESSE"), os.Getenv("ZE_SCHLUESSEL")
	if adresse == "" || schluessel == "" {
		t.Skip("ZE_ADRESSE/ZE_SCHLUESSEL nicht gesetzt")
	}
	i := NeuesIntranet(adresse, schluessel)

	if err := NeuesIntranet(adresse, "zg_falsch").Pruefen(); err == nil {
		t.Fatal("falscher Schlüssel wurde angenommen")
	} else {
		t.Log("falscher Schlüssel:", err)
	}

	s, err := i.Status()
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("Status: %s, %s, heute %d Min., Soll %d, Kern %+v, Regeln %+v", s.Person, s.Zustand, s.HeuteMinuten, s.SollHeute, s.Kern, s.Regeln)

	if s.Programm == nil {
		t.Fatal("Status meldet keine Programmversion")
	}
	ziel := t.TempDir() + "/neu.exe"
	if err := i.ladeProgramm(ziel, s.Programm.Sha256); err != nil {
		t.Fatal("Update-Download:", err)
	}
	if err := i.ladeProgramm(ziel, "falsch"); err == nil {
		t.Fatal("falsche Prüfsumme wurde nicht erkannt")
	}
	t.Logf("Update: Version %s geladen, Prüfsumme stimmt; neuer als dev: %v", s.Programm.Version, neuer(s.Programm.Version, "dev"))

	m, err := i.Stempeln("kommen")
	t.Log("Kommen:", m, err)
	_, err = i.Stempeln("kommen")
	t.Log("Kommen doppelt (soll Fehler):", err)

	m, err = i.GehenNachtragen(time.Now().Add(-1*time.Minute), true)
	t.Log("Gehen nachtragen (Ende vor Beginn → Fehler erwartet):", m, err)
	m, err = i.Stempeln("gehen")
	t.Log("Gehen:", m, err)

	s, _ = i.Status()
	t.Logf("Danach: %s, Aktionen %v", s.Zustand, s.Aktionen)
	if s.Zustand != "weg" {
		t.Fatal("erwartet: weg")
	}
}

func TestSymbol(t *testing.T) {
	b := symbol(farbeDa)
	if len(b) < 100 || b[2] != 1 {
		t.Fatal("Symbol ungültig")
	}
}

func TestFeierabendGrund(t *testing.T) {
	s := &Status{SollHeute: 480}
	if feierabendGrund(s, 470, time.Now()) != "" {
		t.Fatal("zu früh")
	}
	if feierabendGrund(s, 480, time.Now()) == "" {
		t.Fatal("Soll erreicht, aber kein Hinweis")
	}
	s.Schicht = &Zeitraum{Von: "00:00", Bis: "23:59"}
	if feierabendGrund(s, 600, time.Now()) != "" {
		t.Fatal("Schicht läuft noch – kein Feierabend")
	}
}

func TestZerlegeCode(t *testing.T) {
	for code, erwartet := range map[string][2]string{
		"https://intranet.example.org#zg_abc": {"https://intranet.example.org", "zg_abc"},
		" intranet.example.org/#zg_abc ":      {"https://intranet.example.org/", "zg_abc"},
		"zg_abc":                              {"", "zg_abc"},
		"https://intranet.example.org":        {"https://intranet.example.org", ""},
	} {
		a, s := zerlegeCode(code)
		if a != erwartet[0] || s != erwartet[1] {
			t.Errorf("%q → %q, %q", code, a, s)
		}
	}
}

func TestNeuer(t *testing.T) {
	for _, f := range []struct {
		a, b string
		neu  bool
	}{
		{"1.1.0", "1.0.1", true},
		{"1.2.10", "1.2.9", true},
		{"1.0.1", "1.1.0", false},
		{"1.1.0", "1.1.0", false},
		{"1.1.0", "dev", true},
	} {
		if neuer(f.a, f.b) != f.neu {
			t.Errorf("neuer(%s, %s) sollte %v sein", f.a, f.b, f.neu)
		}
	}
}

func TestTeamsWechsel(t *testing.T) {
	a := &App{}
	st := func(teams, zustand string) *Status {
		return &Status{Zustand: zustand, Teams: &TeamsInfo{Verfuegbarkeit: teams}}
	}
	if w := a.teamsWechsel(st("Available", "da")); w != "" {
		t.Fatal("erster Wert darf nichts auslösen:", w)
	}
	if w := a.teamsWechsel(st("Offline", "da")); w != "offline" {
		t.Fatal("Wechsel auf offline während da:", w)
	}
	if w := a.teamsWechsel(st("Offline", "da")); w != "" {
		t.Fatal("kein zweites Mal:", w)
	}
	if w := a.teamsWechsel(st("Available", "weg")); w != "online" {
		t.Fatal("Wechsel auf online während weg:", w)
	}
	if w := a.teamsWechsel(st("Offline", "weg")); w != "" {
		t.Fatal("offline während weg: nichts zu fragen:", w)
	}
}
