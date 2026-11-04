<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The game it's about, if any (see FeedbackContext).
            $table->unsignedBigInteger('game_id')->nullable()->index();
            $table->string('type');
            $table->string('title');
            $table->text('body');
            $table->json('meta');
            // The game's save at the time, gzipped, so a bug can be reproduced.
            $table->binary('state')->nullable();
            $table->string('status')->default('pending');
            $table->string('issue_url')->nullable();
            $table->timestamps();
        });

        // Saves can be larger than MySQL's 64 KB BLOB.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE feedback MODIFY state LONGBLOB NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
