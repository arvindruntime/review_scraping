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
         Schema::table('reviews', function (Blueprint $table) {
        // New fields for Apify-based scraping
        $table->string('place_id')->nullable()->after('source');
        $table->string('profile_url')->nullable()->after('place_id');

        // Change date field to proper datetime
        $table->renameColumn('date', 'review_date');

        // Make rating float instead of integer
        $table->float('rating')->change();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
        $table->dropColumn(['place_id', 'profile_url']);
        $table->renameColumn('review_date', 'date');
        $table->integer('rating')->change();
    });
    }
};
