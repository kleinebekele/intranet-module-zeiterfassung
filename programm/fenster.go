package main

import (
	"context"
	"sync"
	"time"
	"unsafe"

	"github.com/ncruces/zenity"
	"golang.org/x/sys/windows"
)

// Fensterwache: Es ist immer nur EIN Fenster offen – genauer eine Folge zusammengehöriger
// Fenster (z. B. „Pause starten?" → „Wie lange?" → Teams-Text). Wer ein Fenster zeigen will,
// belegt die Wache:
//   - folge(false, …)  Erinnerungen im Takt: ist schon etwas offen, entfällt sie diesmal
//                      und kommt beim nächsten Takt wieder (sie ist ja noch fällig).
//   - folge(true, …)   Ereignisse (Rechner zurück, Teams-Wechsel, Hinweise): warten, bis frei.
//   - dringend(…)      Pausenpflicht: schließt die offene Folge samt Folgefenstern und
//                      kommt sofort. Was dabei geschlossen wurde, fragt das Programm erneut.
// Alle Fenster laufen mit dem Kontext der aktuellen Folge (a.kontext()); geschlossene
// Fenster liefern antwortWeg bzw. a.abgebrochen() == true – dann nichts buchen.

type fensterWache struct {
	mu             sync.Mutex
	frei           *sync.Cond
	belegt         bool
	dringendWartet int
	ctx            context.Context
	abbruch        context.CancelFunc
}

func (w *fensterWache) init() {
	if w.frei == nil {
		w.frei = sync.NewCond(&w.mu)
	}
}

func (w *fensterWache) belegen(warten, dringend bool) bool {
	w.mu.Lock()
	defer w.mu.Unlock()
	w.init()
	if dringend {
		w.dringendWartet++
		if w.belegt && w.abbruch != nil {
			w.abbruch() // offene Folge schließen
		}
		for w.belegt {
			w.frei.Wait()
		}
		w.dringendWartet--
	} else {
		for w.belegt || w.dringendWartet > 0 {
			if !warten {
				return false
			}
			w.frei.Wait()
		}
	}
	w.ctx, w.abbruch = context.WithCancel(context.Background())
	w.belegt = true
	return true
}

func (w *fensterWache) freigeben() {
	w.mu.Lock()
	defer w.mu.Unlock()
	w.init()
	if w.abbruch != nil {
		w.abbruch()
	}
	w.belegt, w.ctx, w.abbruch = false, nil, nil
	w.frei.Broadcast()
}

// Eine Fensterfolge zeigen. false = nicht gezeigt (schon etwas offen, warten == false).
func (a *App) folge(warten bool, f func()) bool {
	if !a.wache.belegen(warten, false) {
		return false
	}
	defer a.wache.freigeben()
	f()
	return true
}

// Pausenpflicht: offene Fenster schließen und sofort zeigen.
func (a *App) dringend(f func()) {
	a.wache.belegen(true, true)
	defer a.wache.freigeben()
	f()
}

// Aus dem Menü: Ist schon ein Fenster offen, erst das beantworten lassen.
func (a *App) ausMenue(f func()) {
	if !a.folge(false, f) {
		_ = zenity.Notify("Bitte zuerst das offene Fenster der Zeiterfassung beantworten.", zenity.Title(titel), fensterSymbol())
	}
}

func (a *App) fensterOffen() bool {
	a.wache.mu.Lock()
	defer a.wache.mu.Unlock()
	return a.wache.belegt || a.wache.dringendWartet > 0
}

// Kontext der laufenden Folge für zenity – wird beim Schließen der Folge abgebrochen.
func (a *App) kontext() zenity.Option {
	a.wache.mu.Lock()
	ctx := a.wache.ctx
	a.wache.mu.Unlock()
	if ctx == nil {
		ctx = context.Background()
	}
	return zenity.Context(ctx)
}

// Wurde die laufende Folge von der Pausenpflicht geschlossen?
func (a *App) abgebrochen() bool {
	a.wache.mu.Lock()
	defer a.wache.mu.Unlock()
	return a.wache.ctx != nil && a.wache.ctx.Err() != nil
}

// ── Immer oben ─────────────────────────────────────────────────────
// Windows lässt ein Programm im Hintergrund nicht die Tastatur an sich reißen (dann blinkt
// nur die Taskleiste). Erlaubt ist „immer oben": Das Fenster liegt über allen anderen, die
// Eingabe bleibt aber, wo sie ist – wer gerade tippt, bestätigt nichts aus Versehen.

var (
	procSetWindowPos   = user32.NewProc("SetWindowPos")
	procGetWindowLongW = user32.NewProc("GetWindowLongW")
)

func immerObenBeobachten() {
	const (
		gwlExStyle    = ^uintptr(19) // -20
		wsExTopmost   = 0x00000008
		hwndTopmost   = ^uintptr(0) // -1
		swpNoSize     = 0x0001
		swpNoMove     = 0x0002
		swpNoActivate = 0x0010
	)
	pid := uint32(windows.GetCurrentProcessId())
	klasse := make([]uint16, 64)
	rueckruf := windows.NewCallback(func(h windows.HWND, _ uintptr) uintptr {
		var eigner uint32
		if _, err := windows.GetWindowThreadProcessId(h, &eigner); err != nil || eigner != pid || !windows.IsWindowVisible(h) {
			return 1
		}
		if n, err := windows.GetClassName(h, &klasse[0], int32(len(klasse))); err == nil && windows.UTF16ToString(klasse[:n]) == "#32768" {
			return 1 // Menü des Uhr-Symbols
		}
		if stil, _, _ := procGetWindowLongW.Call(uintptr(h), gwlExStyle); stil&wsExTopmost == 0 {
			procSetWindowPos.Call(uintptr(h), hwndTopmost, 0, 0, 0, 0, swpNoMove|swpNoSize|swpNoActivate)
		}
		return 1
	})
	for range time.Tick(300 * time.Millisecond) {
		_ = windows.EnumWindows(rueckruf, unsafe.Pointer(nil))
	}
}
