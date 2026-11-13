package main

import (
	"strconv"
	"strings"
	"time"
	"unicode/utf16"

	"fyne.io/systray"
)

// Tagesübersicht: Stunden bisher, heutige Abschnitte untereinander (mit Dauer) und die Pausen
// dazwischen, nächste fällige Pause und Feierabend – vollständig im Rechtsklick-Menü,
// gekürzt im Mouseover am Uhr-Symbol.
const tooltipMax = 63

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
	if um := a.gehenGeplant(); !um.IsZero() && s.Zustand != "weg" {
		fuss = append(fuss, "Gehen geplant um "+um.Format("15:04")+" Uhr")
	} else {
		if f := a.feierabend(s, jetzt); f != "" {
			fuss = append(fuss, "Feierabend "+f)
		}
		if w := a.wochensoll(s, jetzt); w != "" {
			fuss = append(fuss, "Wochensoll "+w)
		}
	}
	return kopf, abschnitte, fuss
}

// Mouseover: Windows zeigt dort nur 63 Zeichen. Deshalb nur Summe, nächste Pause und
// Feierabend; die Abschnitte stehen vollständig im Rechtsklick-Menü.
func (a *App) tooltipText(s *Status, jetzt time.Time) string {
	zeilen := []string{"Heute " + dauer(a.heuteBisher(s, jetzt)) + " Std."}
	if p := a.naechstePause(s, jetzt); p != "" {
		zeilen = append(zeilen, "Pause fällig "+strings.TrimSuffix(p, " Uhr"))
	}
	if um := a.gehenGeplant(); !um.IsZero() && s.Zustand != "weg" {
		zeilen = append(zeilen, "Gehen geplant "+um.Format("15:04"))
	} else {
		if f := a.feierabend(s, jetzt); f != "" {
			zeilen = append(zeilen, "Feierabend "+strings.TrimSuffix(f, " Uhr"))
		}
		// Wochensoll nur, wenn es noch in die 63 Zeichen passt – vollständig steht es im Menü.
		if w := a.wochensoll(s, jetzt); w != "" {
			mit := strings.Join(append(zeilen, "Wochensoll "+strings.TrimSuffix(w, " Uhr")), "\n")
			if len(utf16.Encode([]rune(mit))) <= tooltipMax {
				return mit
			}
		}
	}
	return kuerze(strings.Join(zeilen, "\n"), tooltipMax)
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

// Frühester Feierabend: Schichtende laut Dienstplan, sonst Tagessoll erreicht – samt den
// Pflichtpausen, die bis dahin noch anfallen –, sonst Ende der Kernzeit. Ein Vorsprung aus den
// Vortagen dieser Woche verkürzt das Tagessoll (früher Feierabend).
func (a *App) feierabend(s *Status, jetzt time.Time) string {
	switch {
	case s.Schicht != nil:
		return "ab " + s.Schicht.Bis + " Uhr"
	case s.SollHeute > 0:
		return a.bisZiel(s, jetzt, tagesZiel(s), "– Soll erreicht")
	case s.Kern != nil:
		return "ab " + s.Kern.Bis + " Uhr"
	}
	return ""
}

// Heutiges Ziel in Minuten: Tagessoll abzüglich Vorsprung aus den Vortagen der Woche.
func tagesZiel(s *Status) int {
	return max(s.SollHeute-max(s.WocheSaldo, 0), 0)
}

// Fehlt aus den Vortagen der Woche noch Zeit: wann sie heute aufgeholt ist. Leer, wenn nichts
// fehlt, es schon aufgeholt ist oder man dafür über die Höchstarbeitszeit (10 Std.) käme –
// dann wird der Rest vermutlich an einem anderen Tag (z. B. Samstag) gearbeitet.
func (a *App) wochensoll(s *Status, jetzt time.Time) string {
	if s.Schicht != nil || s.SollHeute <= 0 || s.WocheSaldo >= 0 {
		return ""
	}
	ziel := s.SollHeute - s.WocheSaldo
	maxTag := s.Regeln.MaxTag
	if maxTag <= 0 {
		maxTag = 600
	}
	if ziel > maxTag || a.heuteBisher(s, jetzt) >= ziel {
		return ""
	}
	return a.bisZiel(s, jetzt, ziel, "")
}

// „ab 16:49 Uhr" (eingestempelt, samt noch fälliger Pflichtpausen) bzw. „in 2:10 Std. Arbeit".
func (a *App) bisZiel(s *Status, jetzt time.Time, ziel int, erreicht string) string {
	heute := a.heuteBisher(s, jetzt)
	switch {
	case heute >= ziel:
		return erreicht
	case s.Zustand == "da":
		rest := ziel - heute
		block := s.BlockMinuten + int(jetzt.Sub(a.geholtAm).Minutes())
		return "ab " + jetzt.Add(time.Duration(rest+nochPflichtpause(s, ziel, rest, block))*time.Minute).Format("15:04") + " Uhr"
	default:
		return "in " + dauer(ziel-heute) + " Std. Arbeit"
	}
}

// Pause in Minuten, die bis zum Erreichen des Solls noch Pflicht wird (§ 4 ArbZG): insgesamt
// 30 Min. über 6 Std. und 45 Min. über 9 Std. Arbeit, jede Pause mindestens 15 Min., und
// höchstens 6 Std. am Stück.
func nochPflichtpause(s *Status, ziel, rest, block int) int {
	noetig := 0
	switch soll := ziel; {
	case soll > 540:
		noetig = 45
	case soll > 360:
		noetig = 30
	}
	fehlt := max(noetig-ruhepausen(s), 0)
	if fehlt > 0 {
		fehlt = max(fehlt, 15)
	}
	if s.Regeln.MaxBlock > 0 && rest > s.Regeln.MaxBlock-block {
		fehlt = max(fehlt, 15)
	}
	return fehlt
}
