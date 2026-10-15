<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verbundene Geräte (PC-Programm „Zeiterfassung“) – je Gerät ein geheimer Schlüssel,
 * gespeichert nur als Hash. Das Programm stempelt damit für genau diese Person.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zeit_geraete')) {
            Schema::create('zeit_geraete', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('name', 150);
                $table->string('token_hash', 64)->unique();
                $table->timestamp('zuletzt_am')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zeit_geraete');
    }
};
