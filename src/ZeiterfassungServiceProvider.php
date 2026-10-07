<?php

namespace Intranet\Modules\Zeiterfassung;

use App\Models\User;
use App\Modules\Support\ModuleManifest;
use App\Modules\Support\ModuleServiceProvider;
use App\Modules\Support\Zugriffsstufe;
use Intranet\Modules\Zeiterfassung\Support\Hinweisgeber;
use Intranet\Modules\Zeiterfassung\Support\Rechte;

/**
 * Anmelde-Klasse der Zeiterfassung.
 *
 * Den Seitenzugang regelt der Core über Menüpunkt ↔ Rolle. Was jemand darüber
 * hinaus darf (wessen Zeiten, sofort oder mit Freigabe), entscheidet
 * Support\Rechte anhand der Gruppen-Leitung und der Modulrollen. Deshalb stehen
 * die Aktionsrouten auf „lesen": Die eigentliche Prüfung macht das Modul.
 */
class ZeiterfassungServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->singletonIf(\App\Ekkon\Support\TaskRegistry::class);
        $this->app->make(\App\Ekkon\Support\TaskRegistry::class)->addSource(
            $this->moduleBasePath().'/src/Tasks',
            __NAMESPACE__.'\\Tasks',
            'do1emu/module-zeiterfassung',
            'zeiterfassung',
        );
    }

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('zeiterfassung', 'Zeiterfassung', icon: 'history')
            ->rolle(Rechte::VERWALTUNG, 'Zeiterfassung: Verwaltung (alle Mitarbeiter)')
            ->rolle(Rechte::HOMEOFFICE, 'Zeiterfassung: Stempeln im Intranet (Homeoffice)')
            ->rolle(Rechte::NACHTRAG, 'Zeiterfassung: Nachträge mit Freigabe')
            ->rolle(Rechte::NACHTRAG_FREI, 'Zeiterfassung: Nachträge ohne Freigabe')
            ->item('meine', 'Meine Zeiten', 'module.zeiterfassung.meine.index', icon: 'history')
            ->item('abwesenheiten', 'Urlaub & Abwesenheit', 'module.zeiterfassung.abwesenheiten.index', icon: 'calendar')
            ->item('team', 'Team', 'module.zeiterfassung.team.index', icon: 'users',
                visibleWhen: fn () => Rechte::darfTeam(auth()->user()))
            ->item('freigaben', 'Freigaben', 'module.zeiterfassung.freigaben.index', icon: 'list',
                visibleWhen: fn () => Rechte::darfTeam(auth()->user()))
            ->item('auswertung', 'Auswertung & Export', 'module.zeiterfassung.auswertung.index', icon: 'download',
                visibleWhen: fn () => Rechte::darfTeam(auth()->user()))
            ->item('einstellungen', 'Einstellungen', 'module.zeiterfassung.einstellungen.index', icon: 'cog',
                visibleWhen: fn () => Rechte::istVerwaltung(auth()->user()))
            ->lesend(
                'meine.stempeln', 'meine.buchung', 'meine.loeschen', 'meine.code',
                'abwesenheiten.beantragen', 'abwesenheiten.stornieren',
                'team.buchung', 'team.loeschen', 'team.stempeln', 'team.modell', 'team.modell.entfernen', 'team.konto',
                'team.abwesenheit', 'team.abwesenheit.stornieren', 'team.chip', 'team.code', 'team.ausweis.entfernen',
                'team.verstoesse',
                'freigaben.buchung', 'freigaben.abwesenheit',
            )
            ->stufe(Zugriffsstufe::Verwalten,
                'einstellungen.regeln', 'einstellungen.gruppe.anlegen', 'einstellungen.gruppe.leitung',
                'einstellungen.gruppe.entfernen', 'einstellungen.terminal.anlegen', 'einstellungen.terminal.speichern',
                'einstellungen.terminal.schluessel', 'einstellungen.terminal.entfernen');
    }

    public function boot(): void
    {
        parent::boot();

        if (class_exists(\App\Support\Hinweise::class)) {
            \App\Support\Hinweise::anbieten(fn (User $user): iterable => Hinweisgeber::fuer($user));
        }

        if (class_exists(\App\Support\Profilbereiche::class)) {
            \App\Support\Profilbereiche::registrieren('zeiterfassung', fn (User $user) => Rechte::istTeilnehmer($user)
                ? view('zeiterfassung::profil', ['user' => $user])
                : null);
        }

        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::benennen([
                'zeiterfassung.buchung_geaendert' => 'Zeiterfassung: Buchung geändert',
                'zeiterfassung.buchung_geloescht' => 'Zeiterfassung: Buchung gelöscht',
                'zeiterfassung.antrag_gestellt' => 'Zeiterfassung: Antrag gestellt',
                'zeiterfassung.antrag_genehmigt' => 'Zeiterfassung: Antrag genehmigt',
                'zeiterfassung.antrag_abgelehnt' => 'Zeiterfassung: Antrag abgelehnt',
                'zeiterfassung.modell' => 'Zeiterfassung: Sollzeit geändert',
                'zeiterfassung.ausweis' => 'Zeiterfassung: Chip/Code geändert',
                'zeiterfassung.abwesenheit' => 'Zeiterfassung: Abwesenheit eingetragen',
                'zeiterfassung.einstellungen' => 'Zeiterfassung: Einstellungen geändert',
            ]);
        }
    }
}
