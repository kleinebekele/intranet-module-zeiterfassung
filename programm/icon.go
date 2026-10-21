package main

import (
	"bytes"
	"encoding/binary"
	"image"
	"image/color"
	"image/png"
	"math"
	"os"
	"path/filepath"
	"sync"

	"github.com/ncruces/zenity"
)

// Symbol im Infobereich: Uhr auf farbigem Kreis – grün = da, gelb = Pause, grau = weg,
// rot = keine Verbindung. Wird zur Laufzeit gezeichnet (ICO mit eingebettetem PNG).

var (
	farbeDa     = color.RGBA{22, 163, 74, 255}
	farbePause  = color.RGBA{234, 179, 8, 255}
	farbeWeg    = color.RGBA{107, 114, 128, 255}
	farbeFehler = color.RGBA{220, 38, 38, 255}
)

func symbol(farbe color.RGBA) []byte {
	const g = 32
	var p bytes.Buffer
	_ = png.Encode(&p, uhrBild(farbe, g))

	var ico bytes.Buffer
	_ = binary.Write(&ico, binary.LittleEndian, []uint16{0, 1, 1})                 // Kopf: reserviert, Typ Icon, 1 Bild
	ico.Write([]byte{g, g, 0, 0})                                                  // Breite, Höhe, Farben, reserviert
	_ = binary.Write(&ico, binary.LittleEndian, []uint16{1, 32})                   // Ebenen, Bit je Pixel
	_ = binary.Write(&ico, binary.LittleEndian, []uint32{uint32(p.Len()), 6 + 16}) // Größe, Versatz
	ico.Write(p.Bytes())
	return ico.Bytes()
}

// Fenstersymbol für alle Dialoge (Taskleiste): die Uhr als PNG im Datenordner. Ohne Angabe
// zeigte Windows dort das leere Standardsymbol. Das ICO aus symbol() trägt das PNG ab Byte 22.
var fensterSymbolPfad = sync.OnceValue(func() string {
	pfad := filepath.Join(datenOrdner(), "symbol.png")
	if err := os.WriteFile(pfad, symbol(farbeDa)[22:], 0o600); err != nil {
		return ""
	}
	return pfad
})

func fensterSymbol() zenity.Option {
	if p := fensterSymbolPfad(); p != "" {
		return zenity.WindowIcon(p)
	}
	return zenity.WindowIcon(zenity.NoIcon)
}

// Die Uhr in beliebiger Größe (gezeichnet für 32 Pixel, skaliert).
func uhrBild(farbe color.RGBA, g int) *image.RGBA {
	img := image.NewRGBA(image.Rect(0, 0, g, g))
	f := float64(g) / 32
	mitte := float64(g-1) / 2
	weiss := color.RGBA{255, 255, 255, 255}

	for y := 0; y < g; y++ {
		for x := 0; x < g; x++ {
			d := math.Hypot(float64(x)-mitte, float64(y)-mitte)
			switch {
			case d <= 15.5*f && d > 13.5*f:
				img.Set(x, y, farbe)
			case d <= 13.5*f:
				img.Set(x, y, weiss)
			}
		}
	}
	// Zeiger: 12 Uhr (lang) und 3 Uhr (kurz), dazu der Mittelpunkt.
	strich := func(x0, y0, x1, y1 float64) {
		for t := 0.0; t <= 1; t += 0.02 / f {
			x, y := x0+(x1-x0)*t, y0+(y1-y0)*t
			for dx := -f; dx <= f; dx += 0.5 {
				for dy := -f; dy <= f; dy += 0.5 {
					img.Set(int(math.Round(x+dx*0.6)), int(math.Round(y+dy*0.6)), farbe)
				}
			}
		}
	}
	strich(mitte, mitte, mitte, mitte-10*f)
	strich(mitte, mitte, mitte+7*f, mitte)
	return img
}
