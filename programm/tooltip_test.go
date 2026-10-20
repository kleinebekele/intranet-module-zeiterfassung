package main

import (
	"strings"
	"testing"
	"time"
	"unicode/utf16"
)

func TestTooltipText(t *testing.T) {
	a := &App{geholtAm: time.Now()}
	s := &Status{Zustand: "da", HeuteMinuten: 385, BlockMinuten: 17, SollHeute: 480,
		Abschnitte: []Zeitraum{{"08:13", "11:22"}, {"11:28", "14:23"}, {"14:53", ""}}}
	s.Regeln.MaxBlock = 360
	text := a.tooltipText(s, "Heute 6:25 Std.")
	t.Log("\n" + text)
	if n := len(utf16.Encode([]rune(text))); n > tooltipMax {
		t.Fatalf("zu lang: %d", n)
	}
	if !strings.Contains(text, "Pause 6 Min. (zu kurz)") || !strings.Contains(text, "14:53–jetzt") {
		t.Fatal("Abschnitte/Pausen fehlen")
	}
	viele := &Status{Zustand: "da", Abschnitte: []Zeitraum{{"07:00", "07:30"}, {"07:45", "08:30"}, {"08:45", "09:30"}, {"09:45", "10:30"}, {"10:45", "11:30"}, {"11:45", ""}}}
	viele.Regeln.MaxBlock = 360
	lang := a.tooltipText(viele, "Heute 4:00 Std.")
	t.Log("\n" + lang)
	if n := len(utf16.Encode([]rune(lang))); n > tooltipMax || !strings.Contains(lang, "…") || !strings.Contains(lang, "11:45–jetzt") {
		t.Fatalf("Kürzung falsch (%d)", n)
	}
}
