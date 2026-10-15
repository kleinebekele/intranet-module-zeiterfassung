<?php

use Illuminate\Support\Facades\Route;
use Intranet\Modules\Zeiterfassung\Http\Controllers\AbteilungController;
use Intranet\Modules\Zeiterfassung\Http\Controllers\AbwesenheitController;
use Intranet\Modules\Zeiterfassung\Http\Controllers\AuswertungController;
use Intranet\Modules\Zeiterfassung\Http\Controllers\DienstplanController;
use Intranet\Modules\Zeiterfassung\Http\Controllers\EinstellungenController;
use Intranet\Modules\Zeiterfassung\Http\Controllers\FreigabeController;
use Intranet\Modules\Zeiterfassung\Http\Controllers\MeineZeitenController;
use Intranet\Modules\Zeiterfassung\Http\Controllers\TeamController;
use Intranet\Modules\Zeiterfassung\Http\Controllers\TerminalController;
use Intranet\Modules\Zeiterfassung\Http\Controllers\AppController;
use Intranet\Modules\Zeiterfassung\Http\Middleware\GeraetZugang;
use Intranet\Modules\Zeiterfassung\Http\Middleware\TerminalZugang;

Route::middleware(['web', 'auth'])
    ->prefix('modules/zeiterfassung')
    ->name('module.zeiterfassung.')
    ->group(function () {
        Route::get('/', fn () => redirect()->route('module.zeiterfassung.meine.index'))->name('index');

        // Meine Zeiten: stempeln (Homeoffice), Monatsübersicht, Nachträge.
        Route::get('meine', [MeineZeitenController::class, 'index'])->name('meine.index');
        Route::post('meine/stempeln', [MeineZeitenController::class, 'stempeln'])->name('meine.stempeln');
        Route::post('meine/buchung', [MeineZeitenController::class, 'buchung'])->name('meine.buchung');
        Route::post('meine/loeschen', [MeineZeitenController::class, 'loeschen'])->name('meine.loeschen');
        Route::post('meine/code', [MeineZeitenController::class, 'code'])->name('meine.code');
        Route::get('meine/programm', [MeineZeitenController::class, 'programm'])->name('meine.programm');
        Route::post('meine/geraete', [MeineZeitenController::class, 'geraetAnlegen'])->name('meine.geraet');
        Route::post('meine/geraete/{geraet}/entfernen', [MeineZeitenController::class, 'geraetEntfernen'])->name('meine.geraet.entfernen');

        Route::get('abwesenheiten', [AbwesenheitController::class, 'index'])->name('abwesenheiten.index');
        Route::post('abwesenheiten', [AbwesenheitController::class, 'beantragen'])->name('abwesenheiten.beantragen');
        Route::post('abwesenheiten/{abwesenheit}/stornieren', [AbwesenheitController::class, 'stornieren'])->name('abwesenheiten.stornieren');
        Route::get('abwesenheiten/kalender', [AbwesenheitController::class, 'kalender'])->name('abwesenheiten.kalender');

        // Team: Leitung und Verwaltung.
        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::get('team/{person}', [TeamController::class, 'person'])->name('team.person');
        Route::post('team/{person}/buchung', [TeamController::class, 'buchung'])->name('team.buchung');
        Route::post('team/{person}/loeschen', [TeamController::class, 'loeschen'])->name('team.loeschen');
        Route::post('team/{person}/stempeln', [TeamController::class, 'stempeln'])->name('team.stempeln');
        Route::post('team/{person}/modell', [TeamController::class, 'modell'])->name('team.modell');
        Route::post('team/{person}/modell/{modell}/entfernen', [TeamController::class, 'modellEntfernen'])->name('team.modell.entfernen');
        Route::post('team/{person}/konto', [TeamController::class, 'konto'])->name('team.konto');
        Route::post('team/{person}/abwesenheit', [TeamController::class, 'abwesenheit'])->name('team.abwesenheit');
        Route::post('team/{person}/abwesenheit/{abwesenheit}/stornieren', [TeamController::class, 'abwesenheitStornieren'])->name('team.abwesenheit.stornieren');
        Route::post('team/{person}/chip', [TeamController::class, 'chip'])->name('team.chip');
        Route::post('team/{person}/code', [TeamController::class, 'code'])->name('team.code');
        Route::post('team/{person}/ausweis/{ausweis}/entfernen', [TeamController::class, 'ausweisEntfernen'])->name('team.ausweis.entfernen');
        Route::post('team/{person}/verstoesse/gesehen', [TeamController::class, 'verstoesseGesehen'])->name('team.verstoesse');

        Route::get('freigaben', [FreigabeController::class, 'index'])->name('freigaben.index');
        Route::post('freigaben/buchung/{buchung}', [FreigabeController::class, 'buchung'])->name('freigaben.buchung');
        Route::post('freigaben/abwesenheit/{abwesenheit}', [FreigabeController::class, 'abwesenheit'])->name('freigaben.abwesenheit');

        Route::get('auswertung', [AuswertungController::class, 'index'])->name('auswertung.index');
        Route::get('auswertung/csv', [AuswertungController::class, 'csv'])->name('auswertung.csv');
        Route::get('auswertung/stundenzettel', [AuswertungController::class, 'stundenzettel'])->name('auswertung.stundenzettel');

        // Dienstplan: Abteilungen mit Besetzung und Personen, Monatspläne.
        Route::get('dienstplan', [DienstplanController::class, 'index'])->name('dienstplan.index');
        Route::post('dienstplan/abteilungen', [AbteilungController::class, 'anlegen'])->name('dienstplan.abteilung.anlegen');
        Route::get('dienstplan/{abteilung}', [DienstplanController::class, 'zeigen'])->name('dienstplan.zeigen');
        Route::get('dienstplan/{abteilung}/druck', [DienstplanController::class, 'druck'])->name('dienstplan.druck');
        Route::post('dienstplan/{abteilung}/vorschlagen', [DienstplanController::class, 'vorschlagen'])->name('dienstplan.vorschlagen');
        Route::post('dienstplan/{abteilung}/schicht', [DienstplanController::class, 'schicht'])->name('dienstplan.schicht');
        Route::post('dienstplan/{abteilung}/status', [DienstplanController::class, 'status'])->name('dienstplan.status');
        Route::get('dienstplan/{abteilung}/abteilung', [AbteilungController::class, 'bearbeiten'])->name('dienstplan.abteilung');
        Route::post('dienstplan/{abteilung}/abteilung', [AbteilungController::class, 'speichern'])->name('dienstplan.abteilung.speichern');
        Route::post('dienstplan/{abteilung}/besetzung', [AbteilungController::class, 'besetzung'])->name('dienstplan.abteilung.besetzung');
        Route::post('dienstplan/{abteilung}/personen', [AbteilungController::class, 'personen'])->name('dienstplan.abteilung.personen');
        Route::post('dienstplan/{abteilung}/sondertage', [AbteilungController::class, 'sondertag'])->name('dienstplan.abteilung.sondertag');
        Route::post('dienstplan/{abteilung}/sondertage/{sondertag}/entfernen', [AbteilungController::class, 'sondertagEntfernen'])->name('dienstplan.abteilung.sondertag.entfernen');
        Route::post('dienstplan/{abteilung}/entfernen', [AbteilungController::class, 'entfernen'])->name('dienstplan.abteilung.entfernen');

        Route::get('einstellungen', [EinstellungenController::class, 'index'])->name('einstellungen.index');
        Route::post('einstellungen/regeln', [EinstellungenController::class, 'regeln'])->name('einstellungen.regeln');
        Route::post('einstellungen/gruppen', [EinstellungenController::class, 'gruppeAnlegen'])->name('einstellungen.gruppe.anlegen');
        Route::get('einstellungen/gruppen/{gruppe}', [EinstellungenController::class, 'gruppe'])->name('einstellungen.gruppe');
        Route::post('einstellungen/gruppen/{gruppe}/name', [EinstellungenController::class, 'gruppeName'])->name('einstellungen.gruppe.name');
        Route::post('einstellungen/gruppen/{gruppe}/mitglied', [EinstellungenController::class, 'gruppeMitglied'])->name('einstellungen.gruppe.mitglied');
        Route::post('einstellungen/gruppen/{gruppe}/mitglied/{person}/entfernen', [EinstellungenController::class, 'gruppeMitgliedEntfernen'])->name('einstellungen.gruppe.mitglied.entfernen');
        Route::post('einstellungen/gruppen/{gruppe}/leitung', [EinstellungenController::class, 'gruppeLeitung'])->name('einstellungen.gruppe.leitung');
        Route::post('einstellungen/gruppen/{gruppe}/leitung/{person}/entfernen', [EinstellungenController::class, 'gruppeLeitungEntfernen'])->name('einstellungen.gruppe.leitung.entfernen');
        Route::post('einstellungen/gruppen/{gruppe}/entfernen', [EinstellungenController::class, 'gruppeEntfernen'])->name('einstellungen.gruppe.entfernen');
        Route::post('einstellungen/terminals', [EinstellungenController::class, 'terminalAnlegen'])->name('einstellungen.terminal.anlegen');
        Route::post('einstellungen/terminals/{terminal}', [EinstellungenController::class, 'terminalSpeichern'])->name('einstellungen.terminal.speichern');
        Route::post('einstellungen/terminals/{terminal}/schluessel', [EinstellungenController::class, 'terminalSchluessel'])->name('einstellungen.terminal.schluessel');
        Route::post('einstellungen/terminals/{terminal}/entfernen', [EinstellungenController::class, 'terminalEntfernen'])->name('einstellungen.terminal.entfernen');
    });

// Das Stempel-Terminal: ohne Intranet-Anmeldung, nur mit gültigem Terminal-Schlüssel.
Route::middleware(['web', TerminalZugang::class])
    ->prefix('zeit-terminal/{schluessel}')
    ->name('zeiterfassung.terminal.')
    ->group(function () {
        Route::get('/', [TerminalController::class, 'index'])->name('index');
        // Drossel je IP; falsche Codes sperren zusätzlich je Terminal (TerminalController::fehlversuch).
        Route::post('erkennen', [TerminalController::class, 'erkennen'])->middleware('throttle:30,1')->name('erkennen');
        Route::post('buchen', [TerminalController::class, 'buchen'])->middleware('throttle:60,1')->name('buchen');
    });

// Schnittstelle für das PC-Programm: ohne Sitzung, mit Geräteschlüssel (Authorization: Bearer …).
Route::middleware([GeraetZugang::class, 'throttle:120,1'])
    ->prefix('zeit-app/v1')
    ->name('zeiterfassung.app.')
    ->group(function () {
        Route::get('status', [AppController::class, 'status'])->name('status');
        Route::post('stempeln', [AppController::class, 'stempeln'])->name('stempeln');
        Route::post('gehen-nachtragen', [AppController::class, 'gehenNachtragen'])->name('gehen');
    });
