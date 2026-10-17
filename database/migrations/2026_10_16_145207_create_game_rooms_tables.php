<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_rooms', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('status', 16)->default('OPEN');
            $table->unsignedSmallInteger('team_capacity')->default(5);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['game_id', 'status', 'created_at']);
        });

        Schema::create('game_room_teams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_room_id')->constrained('game_rooms')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('joined_at');
            $table->unique(['game_room_id', 'team_id']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_room_teams');
        Schema::dropIfExists('game_rooms');
    }
};
