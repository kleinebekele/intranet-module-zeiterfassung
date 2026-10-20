package main

import (
	"strconv"
	"strings"
	"time"
	"unicode/utf16"

	"fyne.io/systray"
)

// Tagesübersicht: Stunden bisher, heutige Abschnitte untereinander (mit Dauer) und die Pausen
// dazwischen, nächste fällige Pause und Feierabend. Steht im Mouseover am Uhr-Symbol und
// vollständig im Rechtsklick-Menü – Windows zeigt im Mouseover höchstens 127 Zeichen, dort
// fallen deshalb die ältesten Abschnitte weg („…").
const tooltipMax = 127

const tagesPlaetze = 24 // Menüzeilen auf Vorrat (systray kann Punkte nur verstecken)

func (a *App) tagesMenueAnlegen() {
	for n := 0; n < tagesPlaetze; n++ {
		punkt := systray.AddMenuItem("", "")
		punkt.Disable()
		punkt.Hide()
		a.tagesPunkte = append(a.tagesPunkte, punkt)
	}
}

func (a *App) tagesMenueSetzen(zeilen []string) {
	for n, punkt := range a.tagesPunkte {
		if n < len(zeilen) {
			punkt.SetTitle(zeilen[n])
			punkt.Show()
		} else {
			punkt.Hide()
		}
	}
}

// Kopf, Abschnitte (mit Pausen) und Fuß der Übersicht.
func (a *App) tagesZeilen(s *Status, jetzt time.Time) (kopf string, abschnitte, fuss []string) {
	kopf = "Heute bisher " + dauer(a.heuteBisher(s, jetzt)) + " Std."

	var letztesEnde time.Time
	for n, z := range s.Abschnitte {
		von, okVon := heuteUm(z.Von)
		if n > 0 && okVon && !letztesEnde.IsZero() {
			if p := int(von.Sub(letztesEnde).Minutes()); p > 0 {
				abschnitte = append(abschnitte, "Pause "+pauseText(p))
			}
		}
		bis, okBis := heuteUm(z.Bis)
		if z.Bis == "" {
			bis, okBis = jetzt, true
		}
		zeile := z.Von + "–" + z.Bis
		if z.Bis == "" {
			zeile = z.Von + "–jetzt"
		}
		if okVon && okBis && !bis.Before(von) {
			zeile += " (" + dauerKurz(int(bis.Sub(von).Minutes())) + ")"
		}
		abschnitte = append(abschnitte, zeile)
		if z.Bis != "" {
			letztesEnde = bis
		}
	}
	if s.Zustand == "pause" && !letztesEnde.IsZero() {
		abschnitte = append(abschnitte, "Pause seit "+letztesEnde.Format("15:04")+" Uhr")
	}

	if p := a.naechstePause(s, jetzt); p != "" {
		fuss = append(fuss, "nächste Pause fällig "+p)
	}
	if f := a.feierabend(s, jetzt); f != "" {
		fuss = append(fuss, "Feierabend "+f)
	}
	return kopf, abschnitte, fuss
}

func (a *App) tooltipText(kopf string, abschnitte, fuss []string) string {
	for weg := 0; ; weg++ {
		zeilen := []string{kopf}
		if weg > 0 {
			zeilen = append(zeilen, "…")
		}
		zeilen = append(zeilen, abschnitte[min(weg, len(abschnitte)):]...)
		zeilen = append(zeilen, fuss...)
		text := strings.Join(zeilen, "\n")
		if len(utf16.Encode([]rune(text))) <= tooltipMax || weg >= len(abschnitte) {
			return kuerze(text, tooltipMax)
		}
	}
}

// „22 Min." bzw. „4:07 Std."
func dauerKurz(m int) string {
	if m < 60 {
		return strconv.Itoa(m) + " Min."
	}
	return dauer(m) + " Std."
}

func pauseText(m int) string {
	text := dauerKurz(m)
	if m < 15 {
		text += " (zu kurz)" // zählt nicht als Ruhepause (§ 4 ArbZG)
	}
	return text
}

// Arbeitsminuten heute bis jetzt (der Status ist bis zu zwei Minuten alt).
func (a *App) heuteBisher(s *Status, jetzt time.Time) int {
	if s.Zustand == "da" {
		return s.HeuteMinuten + int(jetzt.Sub(a.geholtAm).Minutes())
	}
	return s.HeuteMinuten
}

// Ruhepausen heute: Lücken ab 15 Minuten zwischen den Abschnitten.
func ruhepausen(s *Status) int {
	summe := 0
	for n := 1; n < len(s.Abschnitte); n++ {
		ende, ok1 := heuteUm(s.Abschnitte[n-1].Bis)
		von, ok2 := heuteUm(s.Abschnitte[n].Von)
		if p := int(von.Sub(ende).Minutes()); ok1 && ok2 && p >= 15 {
			summe += p
		}
	}
	return summe
}

// Nächste fällige Pause (§ 4 ArbZG), nur wenn eingestempelt – das früheste von:
// 6 Std. am Stück, 6 Std. am Tag ohne 30 Min. Pause, 9 Std. am Tag ohne 45 Min. Pause.
func (a *App) naechstePause(s *Status, jetzt time.Time) string {
	if s.Zustand != "da" {
		return ""
	}
	heute, pause := a.heuteBisher(s, jetzt), ruhepausen(s)
	rest := -1
	nimm := func(m int) {
		if rest < 0 || m < rest {
			rest = max(m, 0)
		}
	}
	if s.Regeln.MaxBlock > 0 {
		nimm(s.Regeln.MaxBlock - (s.BlockMinuten + int(jetzt.Sub(a.geholtAm).Minutes())))
	}
	if pause < 30 {
		nimm(360 - heute)
	}
	if pause < 45 {
		nimm(540 - heute)
	}
	if rest < 0 {
		return ""
	}
	if rest == 0 {
		return "jetzt"
	}
	return jetzt.Add(time.Duration(rest)*time.Minute).Format("15:04") + " Uhr"
}

// Schichtende laut Dienstplan, sonst Tagessoll erreicht, sonst Ende der Kernzeit.
func (a *App) feierabend(s *Status, jetzt time.Time) string {
	heute := a.heuteBisher(s, jetzt)
	switch {
	case s.Schicht != nil:
		return s.Schicht.Bis + " Uhr"
	case s.SollHeute > 0 && heute >= s.SollHeute:
		return "– Soll erreicht"
	case s.SollHeute > 0 && s.Zustand == "da":
		return jetzt.Add(time.Duration(s.SollHeute-heute)*time.Minute).Format("15:04") + " Uhr"
	case s.SollHeute > 0:
		return "in " + dauer(s.SollHeute-heute) + " Std."
	case s.Kern != nil:
		return s.Kern.Bis + " Uhr"
	}
	return ""
}
