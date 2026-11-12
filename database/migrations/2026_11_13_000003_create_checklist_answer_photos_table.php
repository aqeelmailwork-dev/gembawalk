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
        // A checklist answer can carry several evidence photos of its own.
        Schema::create('checklist_answer_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_answer_id')->constrained()->cascadeOnDelete();
            $table->string('photo_path');
            $table->string('thumbnail_path');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        // Photos now live on the answer, so the single linked finding is no longer used.
        Schema::table('checklist_answers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finding_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checklist_answers', function (Blueprint $table) {
            $table->foreignId('finding_id')->nullable()->after('checklist_item_id')->constrained()->nullOnDelete();
        });

        Schema::dropIfExists('checklist_answer_photos');
    }
};
