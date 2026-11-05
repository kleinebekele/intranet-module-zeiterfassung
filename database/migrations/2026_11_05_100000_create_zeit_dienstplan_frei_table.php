<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Freie Tage für die Dienstplanung (Wunschfrei): An diesen Tagen wird die Person nicht eingeplant. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zeit_dienstplan_frei')) {
            Schema::create('zeit_dienstplan_frei', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->date('datum');
                $table->string('notiz', 200)->nullable();
                $table->foreignId('erfasst_von')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'datum']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zeit_dienstplan_frei');
    }
};
