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
        Schema::create('checklist_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_category_id')->constrained()->cascadeOnDelete();
            $table->string('question', 200);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // Answers keep a copy of the category name and question so past walks and
        // reports stay accurate after an admin renames or deletes a check.
        Schema::create('checklist_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gemba_walk_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('checklist_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finding_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category_name', 120);
            $table->string('question', 200);
            $table->string('answer', 10)->index();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['gemba_walk_id', 'checklist_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checklist_answers');
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('checklist_categories');
    }
};
