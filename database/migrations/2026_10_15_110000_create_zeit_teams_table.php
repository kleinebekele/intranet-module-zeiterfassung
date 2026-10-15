<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teams-Status koppeln (freiwillig je Person): Refresh-Token der eigenen Microsoft-Anmeldung
 * mit der delegierten Berechtigung Presence.ReadWrite – verschlüsselt gespeichert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zeit_teams')) {
            Schema::create('zeit_teams', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->string('ms_konto', 255)->nullable();   // Anzeigename/Anmeldename bei Microsoft
                $table->text('refresh_token');                 // verschlüsselt
                $table->string('letzter_fehler', 500)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zeit_teams');
    }
};
