<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Counts of what happens each day, with no link to who did it (see GamePlatform\Ops\Stats).
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('metric', 40);
            // What the count is broken down by, if anything: a product, or a sign-up day.
            $table->string('dimension', 40)->default('');
            $table->unsignedInteger('tally')->default(0);
            // A running sum alongside the count, such as pence taken.
            $table->unsignedBigInteger('amount')->default(0);
            $table->unique(['date', 'metric', 'dimension']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
