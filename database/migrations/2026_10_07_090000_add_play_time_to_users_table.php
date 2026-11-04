<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Seconds of play-time used, and bought on top of the free allowance (config/billing.php).
            $table->unsignedInteger('play_seconds_used')->default(0);
            $table->unsignedInteger('play_seconds_bought')->default(0);
            // Unlimited play until this time, from an old unlimited pass.
            $table->timestamp('unlimited_until')->nullable();
            // When the game last checked in, to measure the time since.
            $table->timestamp('last_played_at')->nullable();
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('product');
            $table->unsignedInteger('amount');
            $table->string('currency', 3);
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('status')->default('pending');
            $table->string('terms_version');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['play_seconds_used', 'play_seconds_bought', 'unlimited_until', 'last_played_at']);
        });
    }
};
