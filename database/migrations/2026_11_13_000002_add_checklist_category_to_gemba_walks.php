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
        Schema::table('gemba_walks', function (Blueprint $table) {
            // The checklist category the walk was started for; null for older walks
            // (or if an admin later deletes the category), which show every category.
            $table->foreignId('checklist_category_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gemba_walks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checklist_category_id');
        });
    }
};
