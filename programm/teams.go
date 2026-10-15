package main

// Teams-Status: Ist Teams gekoppelt (unter „Meine Zeiten"), meldet der Status die aktuelle
// Verfügbarkeit. Stellt die Person Teams selbst auf „Als offline anzeigen", während sie
// eingestempelt ist, fragt das Programm nach dem Arbeitsende – und umgekehrt beim Wechsel auf
// online nach dem Arbeitsbeginn. Nur bei einem Wechsel, nur nach Bestätigung.
// (Gehen/Kommen setzen Teams selbst – das Intranet erledigt das bei jeder Buchung.)

// teamsWechsel wird unter a.mu aufgerufen: "offline", "online" oder "".
func (a *App) teamsWechsel(s *Status) string {
	if s.Teams == nil || s.Teams.Verfuegbarkeit == "" {
		a.teamsVorher = ""
		return ""
	}
	vorher, jetzt := a.teamsVorher, s.Teams.Verfuegbarkeit
	a.teamsVorher = jetzt
	if vorher == "" || vorher == jetzt {
		return ""
	}
	switch {
	case jetzt == "Offline" && vorher != "Offline" && s.Zustand == "da":
		return "offline"
	case vorher == "Offline" && jetzt != "Offline" && s.Zustand == "weg":
		return "online"
	}
	return ""
}

func (a *App) teamsNachfragen(wechsel string) {
	if a.dialog.Load() {
		return
	}
	switch wechsel {
	case "offline":
		if a.frage("Dein Teams steht jetzt auf „Offline“.\n\nArbeitszeit beenden (Gehen buchen)?", "Ja, Gehen buchen", "Nein, ich arbeite weiter", "") == antwortJa {
			a.stempeln("gehen")
		}
	case "online":
		if a.frage("Dein Teams ist wieder online.\n\nArbeitszeit starten (Kommen buchen)?", "Ja, Kommen buchen", "Nein", "") == antwortJa {
			a.stempeln("kommen")
		}
	}
}
