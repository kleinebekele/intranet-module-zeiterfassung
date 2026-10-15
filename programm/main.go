// Zeiterfassung – kleines Programm für den PC der Mitarbeiter.
//
// Es misst nichts: Es fragt beim Start, ob die Arbeitszeit beginnen soll, und erinnert
// anhand von Kernzeit, Dienstplan und Arbeitszeitgesetz an Pause und Feierabend.
// Gebucht wird nur, was die Person anklickt. Jede Minute schreibt es ein Lebenszeichen
// in eine lokale Datei; war der Rechner aus, während man eingestempelt war, schlägt es
// beim nächsten Start das letzte Lebenszeichen als Arbeitsende vor.
package main

import (
	"errors"
	"fmt"
	"os"
	"slices"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"fyne.io/systray"
	"github.com/ncruces/zenity"
)

// Version setzt bauen.ps1 aus der Datei VERSION (-ldflags -X main.Version=…).
var Version = "dev"

const titel = "Zeiterfassung"

func main() {
	nachUpdate := slices.Contains(os.Args[1:], nachUpdateSchalter)
	if nachUpdate {
		// Neustart nach dem automatischen Update: warten, bis die alte Version beendet ist.
		for n := 0; n < 30 && !einzigeInstanz(); n++ {
			time.Sleep(500 * time.Millisecond)
		}
		go aufraeumenNachUpdate()
		a := &App{spaeter: map[string]time.Time{}, erledigt: map[string]string{}, nachUpdate: true}
		systray.Run(a.bereit, func() {})
		return
	}

	// Aus „Downloads" gestartet? Dann an den festen Ort kopieren und von dort starten.
	neu, err := installiereBeiBedarf()
	if neu {
		return
	}
	if err != nil {
		protokoll("Installation: " + err.Error())
		if !einzigeInstanz() {
			_ = zenity.Info("Die Zeiterfassung läuft bereits.\n\nZum Aktualisieren erst über das Uhr-Symbol unten rechts „Beenden“ wählen und diese Datei dann erneut starten.", zenity.Title(titel))
			return
		}
	} else if !einzigeInstanz() {
		_ = zenity.Info("Die Zeiterfassung läuft bereits – das Uhr-Symbol findest du unten rechts neben der Uhrzeit.", zenity.Title(titel))
		return
	}

	a := &App{spaeter: map[string]time.Time{}, erledigt: map[string]string{}}
	systray.Run(a.bereit, func() {})
}

type App struct {
	mu          sync.Mutex
	intranet    *Intranet
	status      *Status
	geholtAm    time.Time
	letzterTick time.Time

	dialog     atomic.Bool
	merkerMu   sync.Mutex
	spaeter    map[string]time.Time // Erinnerung ruht bis …
	erledigt   map[string]string    // Erinnerung für diesen Tag erledigt (Datum)
	startOffen atomic.Bool          // „Später" bei der Startfrage gewählt

	updateLaeuft atomic.Bool
	nachUpdate   bool
	abwesend     time.Time // Beginn der aktuellen Abwesenheit vom Rechner (unter merkerMu)

	mStatus, mKommen, mPause, mWeiter, mGehen, mOeffnen, mVerbindung, mAutostart, mBeenden *systray.MenuItem
}

func heute() string { return time.Now().Format("2006-01-02") }

func (a *App) bereit() {
	systray.SetIcon(symbol(farbeWeg))
	systray.SetTitle(titel)
	systray.SetTooltip(titel)

	a.mStatus = systray.AddMenuItem("Verbinde …", "")
	a.mStatus.Disable()
	systray.AddSeparator()
	a.mKommen = systray.AddMenuItem("Kommen", "Arbeitszeit starten")
	a.mPause = systray.AddMenuItem("Pause", "Pause beginnen")
	a.mWeiter = systray.AddMenuItem("Pause beenden", "Weiter arbeiten")
	a.mGehen = systray.AddMenuItem("Gehen", "Arbeitszeit beenden")
	systray.AddSeparator()
	a.mOeffnen = systray.AddMenuItem("Meine Zeiten im Intranet öffnen", "")
	a.mVerbindung = systray.AddMenuItem("Verbindung einrichten …", "Adresse und Schlüssel ändern")
	a.mAutostart = systray.AddMenuItemCheckbox("Mit Windows starten", "", autostartAn())
	systray.AddSeparator()
	a.mBeenden = systray.AddMenuItem("Beenden", "")
	a.menueSetzen()

	go a.klicks()
	go a.start()
}

func (a *App) klicks() {
	for {
		select {
		case <-a.mKommen.ClickedCh:
			go a.stempeln("kommen")
		case <-a.mPause.ClickedCh:
			go a.stempeln("pause")
		case <-a.mWeiter.ClickedCh:
			go a.stempeln("weiter")
		case <-a.mGehen.ClickedCh:
			go a.stempeln("gehen")
		case <-a.mOeffnen.ClickedCh:
			if i := a.verbindung(); i != nil {
				oeffneImBrowser(i.Adresse + "/modules/zeiterfassung/meine")
			}
		case <-a.mVerbindung.ClickedCh:
			go func() {
				if a.einrichten(false) {
					a.aktualisieren()
				}
			}()
		case <-a.mAutostart.ClickedCh:
			an := !a.mAutostart.Checked()
			if err := setzeAutostart(an); err != nil {
				a.fehler("Autostart ließ sich nicht ändern: " + err.Error())
				continue
			}
			if an {
				a.mAutostart.Check()
			} else {
				a.mAutostart.Uncheck()
			}
		case <-a.mBeenden.ClickedCh:
			go a.beenden()
		}
	}
}

func (a *App) verbindung() *Intranet {
	a.mu.Lock()
	defer a.mu.Unlock()
	return a.intranet
}

// ── Start ──────────────────────────────────────────────────────────

func (a *App) start() {
	e, ok := ladeEinstellungen()
	if ok {
		a.mu.Lock()
		a.intranet = NeuesIntranet(e.Adresse, e.Schluessel)
		a.mu.Unlock()
	} else {
		if !a.einrichten(true) {
			systray.Quit()
			return
		}
		_ = setzeAutostart(true)
		a.mAutostart.Check()
	}

	beat, beatOk := letztesLebenszeichen()
	// War der Rechner vor dem Ausschalten schon gesperrt, beginnt die Abwesenheit dort.
	if seit, ok := gespeicherteAbwesenheit(); ok && (!beatOk || seit.Before(beat)) {
		beat, beatOk = seit, true
	}
	a.vergissAbwesend()
	jetzt := time.Now()
	schreibeLebenszeichen(jetzt)
	a.letzterTick = jetzt
	protokoll("Start, Version " + Version)

	a.aktualisieren()
	if a.nachUpdate {
		// Nach dem Update nichts fragen – es war ja nur ein Neustart im laufenden Betrieb.
		meldeUpdate()
	} else {
		a.startfragen(beat, beatOk)
	}

	go a.sperreBeobachten()

	takt := time.NewTicker(time.Minute)
	for t := range takt.C {
		a.takt(t)
	}
}

// Verbindung einrichten. Das Intranet zeigt nach „Gerät verbinden" einen Verbindungscode
// „Adresse#Schlüssel" – den fügt man hier in ein einziges Feld ein. Das Eingabefenster hat nur
// Platz für eine kurze Zeile, die Anleitung steht deshalb vorher in einem eigenen Hinweis.
func (a *App) einrichten(erstesMal bool) bool {
	a.dialog.Store(true)
	defer a.dialog.Store(false)

	if erstesMal {
		_ = zenity.Info("Willkommen bei der Zeiterfassung!\n\nEinmalig einrichten:\n"+
			"1. Im Intranet „Zeiterfassung → Meine Zeiten“ öffnen.\n"+
			"2. In der Karte „PC-Programm“ einen Gerätenamen eingeben und „Gerät verbinden“ klicken.\n"+
			"3. Den angezeigten Verbindungscode kopieren und im nächsten Fenster einfügen.",
			zenity.Title(titel), zenity.OKLabel("Weiter"))
	}

	for {
		code, err := zenity.Entry("Verbindungscode einfügen:", zenity.Title(titel), zenity.OKLabel("Verbinden"), zenity.CancelLabel("Abbrechen"))
		if err != nil {
			return false
		}
		adresse, schluessel := zerlegeCode(code)
		// Notfalls einzeln: nur Schlüssel oder nur Adresse eingefügt.
		if adresse == "" && schluessel != "" {
			if adresse, err = zenity.Entry("Adresse des Intranets:", zenity.Title(titel), zenity.OKLabel("Weiter"), zenity.CancelLabel("Abbrechen")); err != nil {
				return false
			}
			adresse, _ = zerlegeCode(adresse)
		}
		if schluessel == "" && adresse != "" {
			if schluessel, err = zenity.Entry("Schlüssel (beginnt mit zg_):", zenity.Title(titel), zenity.OKLabel("Verbinden"), zenity.CancelLabel("Abbrechen")); err != nil {
				return false
			}
			schluessel = strings.TrimSpace(schluessel)
		}

		i := NeuesIntranet(adresse, schluessel)
		if err := i.Pruefen(); err != nil {
			_ = zenity.Error("Verbindung hat nicht geklappt:\n\n"+err.Error()+"\n\nBitte den Verbindungscode im Intranet neu kopieren (ggf. Gerät trennen und neu verbinden).", zenity.Title(titel))
			continue
		}
		if err := speichereEinstellungen(Einstellungen{Adresse: i.Adresse, Schluessel: i.Schluessel}); err != nil {
			_ = zenity.Error("Einstellungen ließen sich nicht speichern: "+err.Error(), zenity.Title(titel))
			return false
		}
		a.mu.Lock()
		a.intranet = i
		a.mu.Unlock()
		_ = zenity.Info("Verbunden!\n\nDie Zeiterfassung startet ab jetzt mit Windows.\nDas Uhr-Symbol findest du unten rechts neben der Uhrzeit (ggf. unter dem kleinen Pfeil ^).", zenity.Title(titel))
		return true
	}
}

// „https://intranet.firma.de#zg_…" → Adresse und Schlüssel; einzelne Teile gehen auch.
func zerlegeCode(code string) (adresse, schluessel string) {
	code = strings.TrimSpace(code)
	if i := strings.Index(code, "#zg_"); i >= 0 {
		adresse, schluessel = code[:i], code[i+1:]
	} else if strings.HasPrefix(code, "zg_") {
		schluessel = code
	} else {
		adresse = code
	}
	adresse = strings.TrimSpace(adresse)
	if adresse != "" && !strings.HasPrefix(adresse, "http") {
		adresse = "https://" + adresse
	}
	return adresse, strings.TrimSpace(schluessel)
}

// Fragen beim Start: Rechner war aus während eingestempelt? Pause von gestern? Arbeitszeit starten?
func (a *App) startfragen(beat time.Time, beatOk bool) {
	s := a.aktuell()
	if s == nil {
		return
	}
	beginn, _ := time.Parse(time.RFC3339, s.OffenBeginn)

	switch {
	case s.Zustand == "da" && beatOk && time.Since(beat) >= abwesendAbPause && beat.After(beginn):
		// Ab 15 Min. Pause anbieten, ab 2 Std. Arbeitsende vorschlagen.
		a.abwesenheitKlaeren(beat, time.Now(), "Der Rechner war aus")
	case s.Zustand == "da" && s.OffenSeitVortag:
		if a.frage(fmt.Sprintf("Du bist seit %s eingestempelt – vermutlich vergessen auszustempeln.\n\nIm Intranet unter „Meine Zeiten“ korrigieren?", beginn.Local().Format("02.01. 15:04")), "Intranet öffnen", "Später", "") == antwortJa {
			if i := a.verbindung(); i != nil {
				oeffneImBrowser(i.Adresse + "/modules/zeiterfassung/meine")
			}
		}
	case s.Zustand == "pause" && s.Seit != "" && !strings.HasPrefix(s.Seit, heute()):
		// Die Pause von gestern war der Feierabend – das Ende bleibt die Pausenzeit.
		if meldung, err := a.verbindung().Stempeln("gehen"); err == nil {
			a.hinweis("Gestern war deine letzte Buchung eine Pause – sie wurde als Feierabend eingetragen.\n" + meldung)
		}
		a.aktualisieren()
	}

	if s := a.aktuell(); s != nil && s.Zustand == "weg" {
		a.frageStart()
	}
}

// ── Takt: jede Minute ──────────────────────────────────────────────

func (a *App) takt(t time.Time) {
	vorher := a.letzterTick
	a.letzterTick = t
	schreibeLebenszeichen(t)

	// Lücke im Takt = Ruhezustand. Noch gesperrt? Dann klärt das Entsperren; sonst gleich nachfragen.
	if t.Sub(vorher) >= abwesendAbPause {
		if gesperrt() {
			a.merkeAbwesend(vorher)
		} else if seit, ok := a.abwesendSeit(); ok {
			a.vergissAbwesend()
			go a.abwesenheitKlaeren(seit, t, "Der Rechner war gesperrt bzw. im Ruhezustand")
		} else {
			go a.abwesenheitKlaeren(vorher, t, "Der Rechner war im Ruhezustand")
		}
		return
	}
	// Während der Rechner gesperrt ist, keine Erinnerungen – es sitzt ja niemand davor.
	if _, ok := a.abwesendSeit(); ok {
		return
	}

	if t.Sub(a.geholtAm) >= 5*time.Minute || a.aktuell() == nil {
		a.aktualisieren()
	}
	a.erinnern()
}

func (a *App) erinnern() {
	s := a.aktuell()
	if s == nil || a.dialog.Load() {
		return
	}
	jetzt := time.Now()
	vergangen := int(jetzt.Sub(a.geholtAm).Minutes())
	heuteMin, block := s.HeuteMinuten, s.BlockMinuten
	if s.Zustand == "da" {
		heuteMin += vergangen
		block += vergangen
	}

	switch s.Zustand {
	case "weg":
		if a.startOffen.Load() && a.faellig("start") {
			go a.frageStart()
		}

	case "pause":
		seit, err := time.Parse(time.RFC3339, s.Seit)
		if err == nil && jetzt.Sub(seit) >= 30*time.Minute && a.faellig("weiter") {
			go a.erinnerung("weiter", "Deine Pause läuft seit "+dauer(int(jetzt.Sub(seit).Minutes()))+" Std.\n\nWeiter arbeiten?", "Pause beenden", 10*time.Minute, "weiter")
		}

	case "da":
		maxTag := s.Regeln.MaxTag
		switch {
		case maxTag > 0 && heuteMin >= maxTag && a.faellig("max"):
			go a.erinnerung("max", "Du hast heute "+dauer(heuteMin)+" Std. gearbeitet – das ist die gesetzliche Höchstarbeitszeit.\n\nBitte jetzt Feierabend machen. Gehen buchen?", "Gehen", 15*time.Minute, "gehen")
		case maxTag > 0 && heuteMin >= maxTag-15 && a.einmal("max-bald"):
			a.hinweis("In etwa 15 Minuten erreichst du die Höchstarbeitszeit von " + dauer(maxTag) + " Std.")
		case s.Regeln.MaxBlock > 0 && block >= s.Regeln.MaxBlock-15 && a.faellig("pause"):
			go a.erinnerung("pause", "Du arbeitest seit "+dauer(block)+" Std. ohne Pause.\nNach 6 Std. ist eine Pause Pflicht.\n\nPause jetzt starten?", "Pause starten", 15*time.Minute, "pause")
		default:
			if grund := feierabendGrund(s, heuteMin, jetzt); grund != "" && a.faellig("feierabend") {
				go a.erinnerung("feierabend", grund+"\n\nFeierabend? Gehen buchen?", "Gehen", 30*time.Minute, "gehen")
			}
		}
	}
}

// Feierabend: Schichtende laut Dienstplan, sonst Tagessoll erreicht, sonst Ende der Kernzeit.
func feierabendGrund(s *Status, heuteMin int, jetzt time.Time) string {
	if s.Schicht != nil {
		if ende, ok := heuteUm(s.Schicht.Bis); ok && !jetzt.Before(ende) {
			return "Deine Schicht laut Dienstplan endet um " + s.Schicht.Bis + " Uhr."
		}
		return ""
	}
	if s.SollHeute > 0 && heuteMin >= s.SollHeute {
		return "Dein Tagessoll von " + dauer(s.SollHeute) + " Std. ist erreicht."
	}
	if s.Kern != nil && s.SollHeute == 0 {
		if ende, ok := heuteUm(s.Kern.Bis); ok && !jetzt.Before(ende) {
			return "Deine Kernarbeitszeit endete um " + s.Kern.Bis + " Uhr."
		}
	}
	return ""
}

// ── Dialoge ────────────────────────────────────────────────────────

type antwortArt int

const (
	antwortJa antwortArt = iota
	antwortNein
	antwortExtra
)

// Ja/Nein(/Extra)-Frage; nur eine gleichzeitig.
func (a *App) frage(text, ja, nein, extra string) antwortArt {
	a.dialog.Store(true)
	defer a.dialog.Store(false)
	opts := []zenity.Option{zenity.Title(titel), zenity.OKLabel(ja), zenity.CancelLabel(nein)}
	if extra != "" {
		opts = append(opts, zenity.ExtraButton(extra))
	}
	err := zenity.Question(text, opts...)
	switch {
	case err == nil:
		return antwortJa
	case errors.Is(err, zenity.ErrExtraButton):
		return antwortExtra
	default:
		return antwortNein
	}
}

func (a *App) frageStart() {
	if a.istErledigt("start") || a.dialog.Load() {
		return
	}
	vorname := ""
	if s := a.aktuell(); s != nil {
		vorname = strings.Fields(s.Person + " ")[0]
	}
	switch a.frage("Hallo "+vorname+"!\n\nArbeitszeit jetzt starten?", "Ja, Kommen", "Später", "Heute nicht") {
	case antwortJa:
		a.startOffen.Store(false)
		a.erledige("start")
		a.stempeln("kommen")
	case antwortExtra:
		a.startOffen.Store(false)
		a.erledige("start")
	default:
		a.startOffen.Store(true)
		a.ruhe("start", 15*time.Minute)
	}
}

// Erinnerung mit „Später": Ja bucht die Aktion, Später ruht für die angegebene Zeit.
func (a *App) erinnerung(schluessel, text, ja string, ruhe time.Duration, aktion string) {
	if a.dialog.Load() {
		return
	}
	if a.frage(text, ja, "Später", "") == antwortJa {
		a.stempeln(aktion)
		return
	}
	a.ruhe(schluessel, ruhe)
}

// Rechner war aus/im Ruhezustand während eingestempelt: Ende vorschlagen.
func (a *App) endeVorschlagen(ende time.Time, grund string) {
	i := a.verbindung()
	s := a.aktuell()
	if i == nil || s == nil {
		return
	}
	text := fmt.Sprintf("%s ab %s Uhr – du warst da noch eingestempelt.\n\nArbeitsende um %s Uhr eintragen?",
		grund, ende.Format("02.01. 15:04"), ende.Format("15:04"))
	switch a.frage(text, "Ja, so eintragen", "Nein, ich habe weitergearbeitet", "Andere Zeit") {
	case antwortJa:
		meldung, err := i.GehenNachtragen(ende, true)
		a.ergebnis(meldung, err)
	case antwortExtra:
		if s.Nachtrag != "frei" {
			a.hinweis("Eine andere Zeit kannst du hier nicht eintragen.\nÜbernimm die Zeit laut Rechner und beantrage die Korrektur unter „Meine Zeiten“ im Intranet.")
			a.endeVorschlagen(ende, grund)
			return
		}
		a.dialog.Store(true)
		eingabe, err := zenity.Entry("Wann hast du aufgehört? (TT.MM. HH:MM)", zenity.Title(titel), zenity.EntryText(ende.Format("02.01. 15:04")), zenity.OKLabel("Eintragen"), zenity.CancelLabel("Abbrechen"))
		a.dialog.Store(false)
		if err != nil {
			return
		}
		t, err := time.ParseInLocation("02.01. 15:04 2006", strings.TrimSpace(eingabe)+" "+ende.Format("2006"), time.Local)
		if err != nil {
			a.fehler("Die Zeit bitte als TT.MM. HH:MM angeben, z. B. " + ende.Format("02.01. 15:04"))
			a.endeVorschlagen(ende, grund)
			return
		}
		meldung, err := i.GehenNachtragen(t, false)
		a.ergebnis(meldung, err)
	}
	a.aktualisieren()
	if s := a.aktuell(); s != nil && s.Zustand == "weg" {
		a.merkerMu.Lock()
		delete(a.erledigt, "start")
		a.merkerMu.Unlock()
		a.frageStart()
	}
}

func (a *App) beenden() {
	if s := a.aktuell(); s != nil && s.Zustand != "weg" {
		switch a.frage("Du bist noch eingestempelt.\n\nVor dem Beenden Gehen buchen?", "Gehen buchen", "Nur beenden", "Abbrechen") {
		case antwortJa:
			a.stempeln("gehen")
		case antwortExtra:
			return
		}
	}
	markiereBeendet()
	systray.Quit()
}

// ── Buchen und Status ──────────────────────────────────────────────

func (a *App) stempeln(aktion string) {
	i := a.verbindung()
	if i == nil {
		return
	}
	meldung, err := i.Stempeln(aktion)
	a.ergebnis(meldung, err)
	if err == nil {
		protokoll("Gebucht: " + aktion)
		a.merkerMu.Lock()
		delete(a.spaeter, "pause")
		delete(a.spaeter, "weiter")
		delete(a.spaeter, "feierabend")
		a.merkerMu.Unlock()
		if aktion == "kommen" {
			a.erledige("start")
		}
	}
	a.aktualisieren()
}

func (a *App) ergebnis(meldung string, err error) {
	if err != nil {
		a.fehler(err.Error())
		return
	}
	_ = zenity.Notify(meldung, zenity.Title(titel))
}

func (a *App) hinweis(text string) {
	a.dialog.Store(true)
	defer a.dialog.Store(false)
	_ = zenity.Info(text, zenity.Title(titel))
}

func (a *App) fehler(text string) {
	protokoll("Fehler: " + text)
	a.dialog.Store(true)
	defer a.dialog.Store(false)
	_ = zenity.Error(text, zenity.Title(titel))
}

func (a *App) aktuell() *Status {
	a.mu.Lock()
	defer a.mu.Unlock()
	return a.status
}

func (a *App) aktualisieren() {
	i := a.verbindung()
	if i == nil {
		return
	}
	s, err := i.Status()
	a.mu.Lock()
	if err == nil {
		a.status = s
		a.geholtAm = time.Now()
		defer a.updatePruefen(s)
	} else {
		protokoll("Status: " + err.Error())
		var h Hinweis
		if errors.As(err, &h) {
			a.status = nil // Gerät getrennt oder nicht mehr freigeschaltet
		}
	}
	a.mu.Unlock()
	a.menueSetzen()
	if err != nil {
		a.mStatus.SetTitle("Keine Verbindung: " + kuerze(err.Error(), 60))
		systray.SetIcon(symbol(farbeFehler))
		systray.SetTooltip(titel + " – keine Verbindung")
	}
}

func (a *App) menueSetzen() {
	s := a.aktuell()
	for _, m := range []*systray.MenuItem{a.mKommen, a.mPause, a.mWeiter, a.mGehen} {
		m.Disable()
	}
	if s == nil {
		return
	}
	for _, akt := range s.Aktionen {
		switch akt {
		case "kommen":
			a.mKommen.Enable()
		case "pause":
			a.mPause.Enable()
		case "weiter":
			a.mWeiter.Enable()
		case "gehen":
			a.mGehen.Enable()
		}
	}
	seit := ""
	if t, err := time.Parse(time.RFC3339, s.Seit); err == nil {
		seit = " seit " + t.Local().Format("15:04")
	}
	text := map[string]string{"da": "Da" + seit, "pause": "Pause" + seit, "weg": "Nicht eingestempelt"}[s.Zustand]
	text += " · heute " + dauer(s.HeuteMinuten) + " Std."
	a.mStatus.SetTitle(text)
	systray.SetTooltip(titel + " – " + text)
	switch s.Zustand {
	case "da":
		systray.SetIcon(symbol(farbeDa))
	case "pause":
		systray.SetIcon(symbol(farbePause))
	default:
		systray.SetIcon(symbol(farbeWeg))
	}
}

// ── Kleinkram ──────────────────────────────────────────────────────

// Erinnerung fällig: nicht für heute erledigt und nicht gerade auf „Später".
func (a *App) faellig(schluessel string) bool {
	a.merkerMu.Lock()
	defer a.merkerMu.Unlock()
	return a.erledigt[schluessel] != heute() && time.Now().After(a.spaeter[schluessel])
}

// Nur einmal am Tag.
func (a *App) einmal(schluessel string) bool {
	a.merkerMu.Lock()
	defer a.merkerMu.Unlock()
	if a.erledigt[schluessel] == heute() {
		return false
	}
	a.erledigt[schluessel] = heute()
	return true
}

func (a *App) istErledigt(schluessel string) bool {
	a.merkerMu.Lock()
	defer a.merkerMu.Unlock()
	return a.erledigt[schluessel] == heute()
}

func (a *App) erledige(schluessel string) {
	a.merkerMu.Lock()
	defer a.merkerMu.Unlock()
	a.erledigt[schluessel] = heute()
}

func (a *App) ruhe(schluessel string, d time.Duration) {
	a.merkerMu.Lock()
	defer a.merkerMu.Unlock()
	a.spaeter[schluessel] = time.Now().Add(d)
}

func dauer(min int) string {
	if min < 0 {
		min = 0
	}
	return fmt.Sprintf("%d:%02d", min/60, min%60)
}

func kuerze(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return string(r[:n-1]) + "…"
}
