<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabellen der Zeiterfassung.
 *
 * Grundsatz: Buchungen werden nie überschrieben oder gelöscht. Eine Änderung
 * legt eine neue Zeile an und setzt die alte auf „ersetzt" – so bleibt jede
 * Korrektur nachvollziehbar (ArbZG § 16: Aufzeichnungen mindestens zwei Jahre).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Welche Rollen nehmen an der Zeiterfassung teil – und wer leitet sie.
        if (! Schema::hasTable('zeit_gruppen')) {
            Schema::create('zeit_gruppen', function (Blueprint $table) {
                $table->id();
                $table->string('role_id', 100)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('zeit_gruppe_leitung')) {
            Schema::create('zeit_gruppe_leitung', function (Blueprint $table) {
                $table->foreignId('gruppe_id')->constrained('zeit_gruppen')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->primary(['gruppe_id', 'user_id']);
            });
        }

        // Arbeitszeitmodell je Person, historisiert über gueltig_ab.
        if (! Schema::hasTable('zeit_modelle')) {
            Schema::create('zeit_modelle', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->date('gueltig_ab');
                // Sollzeit je Wochentag in Minuten, Montag = 1 … Sonntag = 7.
                foreach (range(1, 7) as $tag) {
                    $table->unsignedSmallInteger("soll_{$tag}")->default(0);
                }
                $table->decimal('urlaubstage', 5, 1)->default(0);
                $table->string('bundesland', 4)->nullable();
                $table->string('notiz', 500)->nullable();
                $table->foreignId('erfasst_von')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['user_id', 'gueltig_ab']);
            });
        }

        // Übertrag ins Jahr (Resturlaub, Stundensaldo beim Start).
        if (! Schema::hasTable('zeit_konten')) {
            Schema::create('zeit_konten', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedSmallInteger('jahr');
                $table->decimal('urlaub_uebertrag', 5, 1)->default(0);
                $table->integer('saldo_uebertrag')->default(0); // Minuten
                $table->timestamps();
                $table->unique(['user_id', 'jahr']);
            });
        }

        if (! Schema::hasTable('zeit_terminals')) {
            Schema::create('zeit_terminals', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('token_hash', 64)->unique();
                $table->text('netze')->nullable();
                $table->boolean('aktiv')->default(true);
                $table->timestamp('zuletzt_am')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('zeit_buchungen')) {
            Schema::create('zeit_buchungen', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->dateTime('beginn');
                $table->dateTime('ende')->nullable();
                $table->string('ende_art', 20)->nullable();      // gehen | pause
                $table->string('quelle', 20);                     // terminal | web | nachtrag | leitung
                $table->string('status', 20)->default('gueltig'); // gueltig | beantragt | abgelehnt | ersetzt | geloescht | erledigt
                $table->string('antrag', 20)->nullable();         // neu | aendern | loeschen
                $table->foreignId('ersetzt_id')->nullable()->constrained('zeit_buchungen')->nullOnDelete();
                $table->foreignId('terminal_id')->nullable()->constrained('zeit_terminals')->nullOnDelete();
                $table->string('notiz', 500)->nullable();
                $table->foreignId('erfasst_von')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('entschieden_von')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('entschieden_am')->nullable();
                $table->string('entscheid_notiz', 500)->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status', 'beginn']);
                $table->index(['status', 'ende']);
            });
        }

        if (! Schema::hasTable('zeit_abwesenheiten')) {
            Schema::create('zeit_abwesenheiten', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('art', 30);
                $table->date('von');
                $table->date('bis');
                $table->boolean('halbtag')->default(false);
                $table->string('status', 20)->default('beantragt'); // beantragt | genehmigt | abgelehnt | storniert
                $table->string('notiz', 500)->nullable();
                $table->foreignId('erfasst_von')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('entschieden_von')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('entschieden_am')->nullable();
                $table->string('entscheid_notiz', 500)->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status', 'von']);
            });
        }

        // Chip-Kennungen und Terminal-Codes. Codes nur als HMAC (eindeutig suchbar, nicht lesbar).
        if (! Schema::hasTable('zeit_ausweise')) {
            Schema::create('zeit_ausweise', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('art', 10); // chip | code
                $table->string('wert', 128);
                $table->string('bezeichnung', 150)->nullable();
                $table->timestamps();
                $table->unique(['art', 'wert']);
            });
        }

        // Verstöße gegen das Arbeitszeitgesetz, vom nächtlichen Tagesabschluss festgehalten.
        if (! Schema::hasTable('zeit_verstoesse')) {
            Schema::create('zeit_verstoesse', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->date('datum');
                $table->string('regel', 30);
                $table->string('text', 500);
                $table->foreignId('gesehen_von')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('gesehen_am')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'datum', 'regel']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zeit_verstoesse');
        Schema::dropIfExists('zeit_ausweise');
        Schema::dropIfExists('zeit_abwesenheiten');
        Schema::dropIfExists('zeit_buchungen');
        Schema::dropIfExists('zeit_terminals');
        Schema::dropIfExists('zeit_konten');
        Schema::dropIfExists('zeit_modelle');
        Schema::dropIfExists('zeit_gruppe_leitung');
        Schema::dropIfExists('zeit_gruppen');
    }
};
