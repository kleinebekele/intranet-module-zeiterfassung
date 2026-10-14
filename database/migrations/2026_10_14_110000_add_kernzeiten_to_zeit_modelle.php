<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kernarbeitszeit je Person und Wochentag, Teil des Zeitmodells (historisiert über gueltig_ab).
 * JSON {"1": {"von": "10:00", "bis": "17:00"}, …}
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('zeit_modelle', 'kernzeiten')) {
            Schema::table('zeit_modelle', function (Blueprint $table) {
                $table->text('kernzeiten')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('zeit_modelle', function (Blueprint $table) {
            $table->dropColumn('kernzeiten');
        });
    }
};
