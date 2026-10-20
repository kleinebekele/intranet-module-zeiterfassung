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
		"11:28–14:23 (2:55 Std.)", "Pause 30 Min.", "14:53–jetzt (22 Min.)", "nächste Pause fällig 17:49 Uhr", "Feierabend 16:49 Uhr"} {
		if !strings.Contains(alles, soll) {
			t.Errorf("fehlt: %s", soll)
		}
	}
	tip := a.tooltipText(kopf, abschnitte, fuss)
	t.Log("\n" + tip)
	if n := len(utf16.Encode([]rune(tip))); n > tooltipMax || !strings.Contains(tip, "14:53–jetzt") {
		t.Fatalf("Mouseover falsch (%d Zeichen)", n)
	}
}
