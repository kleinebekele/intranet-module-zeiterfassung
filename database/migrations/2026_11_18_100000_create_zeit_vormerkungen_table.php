<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vormerkungen des PC-Programms je Person: geplantes Pausenende, geplantes Gehen
 * („Gehen in …") und Ablauf einer Statusvorlage mit Rückfrage. Liegen auf dem Server,
 * damit sie Neustarts und Gerätewechsel überstehen; geplantes Gehen bucht der Server selbst.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zeit_vormerkungen')) {
            Schema::create('zeit_vormerkungen', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->timestamp('pause_bis')->nullable();
                $table->timestamp('gehen_um')->nullable()->index();
                $table->timestamp('vorlage_bis')->nullable();
                $table->string('vorlage_name', 100)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zeit_vormerkungen');
    }
};
