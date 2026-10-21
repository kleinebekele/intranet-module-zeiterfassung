package main

import (
	"image/png"
	"os"
	"path/filepath"
	"testing"
)

// Erzeugt winres/symbol.png für das Programmsymbol der exe (bauen.ps1 setzt ZE_SYMBOL).
func TestSymbolDatei(t *testing.T) {
	if os.Getenv("ZE_SYMBOL") == "" {
		t.Skip("nur beim Bauen")
	}
	_ = os.MkdirAll("winres", 0o755)
	f, err := os.Create(filepath.Join("winres", "symbol.png"))
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	if err := png.Encode(f, uhrBild(farbeDa, 256)); err != nil {
		t.Fatal(err)
	}
}
