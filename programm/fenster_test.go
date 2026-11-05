package main

import (
	"testing"
	"time"
)

// Immer nur eine Folge; Takt-Erinnerungen entfallen, Ereignisse warten, Pausenpflicht schließt.
func TestFensterWache(t *testing.T) {
	a := &App{}
	offen := make(chan struct{})
	geschlossen := make(chan bool)
	go a.folge(false, func() {
		close(offen)
		ctx := a.wache.ctx
		select {
		case <-ctx.Done():
			geschlossen <- a.abgebrochen()
		case <-time.After(2 * time.Second):
			geschlossen <- false
		}
	})
	<-offen

	if a.folge(false, func() { t.Error("zweites Fenster trotz offenem") }) {
		t.Fatal("folge(false) hätte entfallen müssen")
	}
	wartend := make(chan string, 2)
	go a.folge(true, func() { wartend <- "ereignis" })

	go a.dringend(func() { wartend <- "pause" })
	if !<-geschlossen {
		t.Fatal("Pausenpflicht hat das offene Fenster nicht geschlossen")
	}
	if erst := <-wartend; erst != "pause" {
		t.Fatalf("zuerst %q statt der Pausenpflicht", erst)
	}
	if dann := <-wartend; dann != "ereignis" {
		t.Fatalf("danach %q", dann)
	}
	if a.fensterOffen() {
		t.Fatal("Wache nicht freigegeben")
	}
}
