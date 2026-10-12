<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dienstplanung: Abteilungen mit Besetzungsregeln und Personen, daraus
 * Monatspläne mit Schichten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zeit_abteilungen')) {
            Schema::create('zeit_abteilungen', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('bundesland', 4)->nullable();               // leer = Standard aus den Einstellungen
                $table->unsignedSmallInteger('min_schicht')->default(180); // kürzeste sinnvolle Schicht in Minuten
                $table->string('notiz', 500)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('zeit_abteilung_personen')) {
            Schema::create('zeit_abteilung_personen', function (Blueprint $table) {
                $table->foreignId('abteilung_id')->constrained('zeit_abteilungen')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->boolean('aushilfe')->default(false);                // springt nur bei Lücken und Spitzen ein
                $table->unsignedSmallInteger('wochenstunden')->nullable();  // Minuten; leer = aus dem Zeitmodell
                $table->unsignedSmallInteger('max_monat')->nullable();      // Minuten; Stundenbudget (z. B. Minijob)
                $table->unsignedTinyInteger('max_tage_woche')->nullable();
                $table->string('freie_tage', 20)->nullable();               // feste freie Wochentage, z. B. "3,7"
                $table->unsignedSmallInteger('position')->default(0);
                $table->primary(['abteilung_id', 'user_id']);
            });
        }

        // Wann muss die Abteilung wie besetzt sein: je Wochentag ein oder mehrere Zeitfenster.
        if (! Schema::hasTable('zeit_abteilung_besetzung')) {
            Schema::create('zeit_abteilung_besetzung', function (Blueprint $table) {
                $table->id();
                $table->foreignId('abteilung_id')->constrained('zeit_abteilungen')->cascadeOnDelete();
                $table->unsignedTinyInteger('wochentag'); // 1 = Montag … 7 = Sonntag
                $table->string('von', 5);
                $table->string('bis', 5);
                $table->unsignedTinyInteger('mindestens')->default(1);
                $table->unsignedTinyInteger('optional')->default(0); // zusätzlich wünschenswert
                $table->timestamps();
                $table->index(['abteilung_id', 'wochentag']);
            });
        }

        // Abweichende Tage: geschlossen (z. B. Rosenmontag, Betriebsferien) oder andere Zeiten (Heiligabend, verkaufsoffener Sonntag).
        if (! Schema::hasTable('zeit_abteilung_sondertage')) {
            Schema::create('zeit_abteilung_sondertage', function (Blueprint $table) {
                $table->id();
                $table->foreignId('abteilung_id')->constrained('zeit_abteilungen')->cascadeOnDelete();
                $table->date('datum');
                $table->string('bezeichnung', 150);
                $table->boolean('geschlossen')->default(true);
                $table->string('von', 5)->nullable();
                $table->string('bis', 5)->nullable();
                $table->unsignedTinyInteger('mindestens')->default(1);
                $table->unsignedTinyInteger('optional')->default(0);
                $table->timestamps();
                $table->unique(['abteilung_id', 'datum']);
            });
        }

        if (! Schema::hasTable('zeit_dienstplaene')) {
            Schema::create('zeit_dienstplaene', function (Blueprint $table) {
                $table->id();
                $table->foreignId('abteilung_id')->constrained('zeit_abteilungen')->cascadeOnDelete();
                $table->date('monat'); // immer der Monatserste
                $table->string('status', 20)->default('entwurf'); // entwurf | veroeffentlicht
                $table->foreignId('erstellt_von')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('veroeffentlicht_am')->nullable();
                $table->timestamps();
                $table->unique(['abteilung_id', 'monat']);
            });
        }

        if (! Schema::hasTable('zeit_dienstplan_schichten')) {
            Schema::create('zeit_dienstplan_schichten', function (Blueprint $table) {
                $table->id();
                $table->foreignId('dienstplan_id')->constrained('zeit_dienstplaene')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->date('datum');
                $table->string('von', 5);
                $table->string('bis', 5);
                $table->unsignedSmallInteger('pause')->default(0); // Minuten
                $table->string('notiz', 200)->nullable();
                $table->timestamps();
                $table->index(['dienstplan_id', 'datum']);
                $table->index(['user_id', 'datum']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zeit_dienstplan_schichten');
        Schema::dropIfExists('zeit_dienstplaene');
        Schema::dropIfExists('zeit_abteilung_sondertage');
        Schema::dropIfExists('zeit_abteilung_besetzung');
        Schema::dropIfExists('zeit_abteilung_personen');
        Schema::dropIfExists('zeit_abteilungen');
    }
};
