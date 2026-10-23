package main

import (
	"strings"
	"testing"
	"time"
	"unicode/utf16"
)

func um(hhmm string) time.Time { t, _ := heuteUm(hhmm); return t }

// Der Tag vom 20.10.2026 um 15:15 Uhr: 30 Min. Ruhepause, also fällt nach 9 Std. weitere Pause an.
func TestTagesZeilen(t *testing.T) {
	jetzt := um("15:15")
	a := &App{geholtAm: jetzt}
	s := &Status{Zustand: "da", HeuteMinuten: 386, BlockMinuten: 22, SollHeute: 480,
		Abschnitte: []Zeitraum{{"08:13", "11:22"}, {"11:28", "14:23"}, {"14:53", ""}}}
	s.Regeln.MaxBlock = 360
	kopf, abschnitte, fuss := a.tagesZeilen(s, jetzt)
	alles := strings.Join(append(append([]string{kopf}, abschnitte...), fuss...), "\n")
	t.Log("\n" + alles)
	for _, soll := range []string{"Heute bisher 6:26 Std.", "08:13–11:22 (3:09 Std.)", "Pause 6 Min. (zu kurz)",
		"11:28–14:23 (2:55 Std.)", "Pause 30 Min.", "14:53–jetzt (22 Min.)", "nächste Pause fällig 17:49 Uhr", "Feierabend ab 16:49 Uhr"} {
		if !strings.Contains(alles, soll) {
			t.Errorf("fehlt: %s", soll)
		}
	}
	tip := a.tooltipText(s, jetzt)
	t.Log("\n" + tip)
	if n := len(utf16.Encode([]rune(tip))); n > tooltipMax || tip != "Heute 6:26 Std.\nPause fällig 17:49\nFeierabend ab 16:49" {
		t.Fatalf("Mouseover falsch (%d Zeichen)", n)
	}
}

func TestFehlendePause(t *testing.T) {
	s := &Status{Abschnitte: []Zeitraum{{"08:00", "11:00"}, {"11:30", ""}}}
	s.Regeln.MaxBlock = 360
	if m := fehlendePause(s, 400, 200); m != 0 {
		t.Errorf("30 Min. Pause bei 6:40 Std. reicht, aber %d", m)
	}
	if m := fehlendePause(s, 560, 260); m != 15 {
		t.Errorf("ab 9 Std. fehlen 15 Min., aber %d", m)
	}
	ohne := &Status{Abschnitte: []Zeitraum{{"08:00", ""}}}
	ohne.Regeln.MaxBlock = 360
	if m := fehlendePause(ohne, 370, 370); m != 30 {
		t.Errorf("6:10 Std. ohne Pause: 30 Min., aber %d", m)
	}
}

// Morgens ohne Pause: Feierabend erst nach Soll plus 30 Min. Pflichtpause.
func TestFeierabendMitPflichtpause(t *testing.T) {
	jetzt := um("11:00")
	a := &App{geholtAm: jetzt}
	s := &Status{Zustand: "da", HeuteMinuten: 180, BlockMinuten: 180, SollHeute: 480,
		Abschnitte: []Zeitraum{{"08:00", ""}}}
	s.Regeln.MaxBlock = 360
	if f := a.feierabend(s, jetzt); f != "ab 16:30 Uhr" {
		t.Errorf("erwartet ab 16:30 Uhr, nicht %s", f)
	}
	kurz := &Status{Zustand: "da", HeuteMinuten: 60, BlockMinuten: 60, SollHeute: 300,
		Abschnitte: []Zeitraum{{"10:00", ""}}}
	kurz.Regeln.MaxBlock = 360
	if f := a.feierabend(kurz, jetzt); f != "ab 15:00 Uhr" {
		t.Errorf("5 Std. Soll ohne Pflichtpause: ab 15:00 Uhr, nicht %s", f)
	}
}
