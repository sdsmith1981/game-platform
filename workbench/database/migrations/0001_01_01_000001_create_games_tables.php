<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('day')->default(0);
            $table->timestamps();
        });

        Schema::create('game_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->string('path')->nullable();
            $table->binary('state')->nullable();
            $table->timestamps();
        });
    }
};
