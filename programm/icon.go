package main

import (
	"bytes"
	"encoding/binary"
	"image"
	"image/color"
	"image/png"
	"math"
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
	img := image.NewRGBA(image.Rect(0, 0, g, g))
	mitte := float64(g-1) / 2
	weiss := color.RGBA{255, 255, 255, 255}

	for y := 0; y < g; y++ {
		for x := 0; x < g; x++ {
			d := math.Hypot(float64(x)-mitte, float64(y)-mitte)
			switch {
			case d <= 15.5 && d > 13.5:
				img.Set(x, y, farbe)
			case d <= 13.5:
				img.Set(x, y, weiss)
			}
		}
	}
	// Zeiger: 12 Uhr (lang) und 3 Uhr (kurz), dazu der Mittelpunkt.
	strich := func(x0, y0, x1, y1 float64) {
		for t := 0.0; t <= 1; t += 0.02 {
			x, y := x0+(x1-x0)*t, y0+(y1-y0)*t
			for dx := -1.0; dx <= 1; dx++ {
				for dy := -1.0; dy <= 1; dy++ {
					img.Set(int(math.Round(x+dx*0.6)), int(math.Round(y+dy*0.6)), farbe)
				}
			}
		}
	}
	strich(mitte, mitte, mitte, mitte-10)
	strich(mitte, mitte, mitte+7, mitte)

	var p bytes.Buffer
	_ = png.Encode(&p, img)

	var ico bytes.Buffer
	_ = binary.Write(&ico, binary.LittleEndian, []uint16{0, 1, 1})                 // Kopf: reserviert, Typ Icon, 1 Bild
	ico.Write([]byte{g, g, 0, 0})                                                  // Breite, Höhe, Farben, reserviert
	_ = binary.Write(&ico, binary.LittleEndian, []uint16{1, 32})                   // Ebenen, Bit je Pixel
	_ = binary.Write(&ico, binary.LittleEndian, []uint32{uint32(p.Len()), 6 + 16}) // Größe, Versatz
	ico.Write(p.Bytes())
	return ico.Bytes()
}
