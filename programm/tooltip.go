package main

import (
	"strconv"
	"strings"
	"time"
	"unicode/utf16"
)

// Mouseover am Uhr-Symbol: Zustand, heutige Abschnitte untereinander mit den Pausen dazwischen,
// nächste Pflichtpause und Feierabend. Windows zeigt höchstens 127 Zeichen – reicht der Platz
// nicht, fallen die ältesten Abschnitte weg („…").
const tooltipMax = 127

func (a *App) tooltipText(s *Status, kopf string) string {
	var abschnitte []string
	var letztesEnde time.Time
	for n, z := range s.Abschnitte {
		von, okVon := heuteUm(z.Von)
		if n > 0 && okVon && !letztesEnde.IsZero() {
			if p := int(von.Sub(letztesEnde).Minutes()); p > 0 {
				abschnitte = append(abschnitte, "Pause "+pauseText(p))
			}
		}
		bis := z.Bis
		if bis == "" {
			bis = "jetzt"
		} else if t, ok := heuteUm(z.Bis); ok {
			letztesEnde = t
		}
		if s.Zustand == "pause" && n == len(s.Abschnitte)-1 && z.Bis != "" {
			abschnitte = append(abschnitte, z.Von+"–"+bis, "Pause seit "+z.Bis)
			continue
		}
		abschnitte = append(abschnitte, z.Von+"–"+bis)
	}

	var fuss []string
	if p := a.naechstePause(s); p != "" {
		fuss = append(fuss, "Pause fällig "+p)
	}
	if f := a.feierabend(s); f != "" {
		fuss = append(fuss, "Feierabend "+f)
	}

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

func pauseText(m int) string {
	text := strconv.Itoa(m) + " Min."
	if m >= 60 {
		text = dauer(m) + " Std."
	}
	if m < 15 {
		text += " (zu kurz)" // zählt nicht als Ruhepause (§ 4 ArbZG)
	}
	return text
}

// Uhrzeit, zu der 6 Std. am Stück erreicht sind (nur wenn eingestempelt).
func (a *App) naechstePause(s *Status) string {
	if s.Zustand != "da" || s.Regeln.MaxBlock <= 0 {
		return ""
	}
	return a.geholtAm.Add(time.Duration(s.Regeln.MaxBlock-s.BlockMinuten) * time.Minute).Format("15:04")
}

// Schichtende laut Dienstplan, sonst Tagessoll erreicht, sonst Ende der Kernzeit.
func (a *App) feierabend(s *Status) string {
	switch {
	case s.Schicht != nil:
		return s.Schicht.Bis
	case s.SollHeute > 0 && s.HeuteMinuten >= s.SollHeute:
		return "Soll erreicht"
	case s.SollHeute > 0 && s.Zustand == "da":
		return a.geholtAm.Add(time.Duration(s.SollHeute-s.HeuteMinuten) * time.Minute).Format("15:04")
	case s.SollHeute > 0:
		return "in " + dauer(s.SollHeute-s.HeuteMinuten) + " Std."
	case s.Kern != nil:
		return s.Kern.Bis
	}
	return ""
}
