<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gemba_walk_id')->constrained()->cascadeOnDelete();
            $table->string('photo_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('category')->default('other')->index();
            $table->string('severity')->default('low')->index();
            $table->text('observation')->nullable();
            $table->text('action_required')->nullable();
            $table->string('action_owner', 120)->nullable();
            $table->date('due_on')->nullable();
            $table->string('action_status')->default('open')->index();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
