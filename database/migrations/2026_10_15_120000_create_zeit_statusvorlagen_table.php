<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Eigene Teams-Statusvorlagen je Person (im PC-Programm per Rechtsklick wählbar). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zeit_statusvorlagen')) {
            Schema::create('zeit_statusvorlagen', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('name', 100);
                $table->unsignedSmallInteger('minuten');            // 15–300
                $table->string('verfuegbarkeit', 30);              // DoNotDisturb | Busy | Away | BeRightBack | Offline
                $table->string('text', 280)->nullable();           // Teams-Statusnachricht
                $table->string('rueckkehr', 20)->default('frage'); // automatisch | frage
                $table->boolean('pause')->default(false);          // zusätzlich Pause buchen
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();
                $table->index(['user_id', 'position']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zeit_statusvorlagen');
    }
};
