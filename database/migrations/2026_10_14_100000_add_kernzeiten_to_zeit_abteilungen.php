<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abteilung: Kernarbeitszeit je Wochentag (JSON {"1": {"von": "09:00", "bis": "17:00"}, …})
 * und ob Aushilfen ihre volle Zeit bekommen oder nur Lücken füllen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zeit_abteilungen', function (Blueprint $table) {
            if (! Schema::hasColumn('zeit_abteilungen', 'kernzeiten')) {
                $table->text('kernzeiten')->nullable();
            }
            if (! Schema::hasColumn('zeit_abteilungen', 'aushilfen_voll')) {
                $table->boolean('aushilfen_voll')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('zeit_abteilungen', function (Blueprint $table) {
            $table->dropColumn(['kernzeiten', 'aushilfen_voll']);
        });
    }
};
