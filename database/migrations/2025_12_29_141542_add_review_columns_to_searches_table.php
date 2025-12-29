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
        Schema::table('searches', function (Blueprint $table) {
            $table->integer('google_reviews')->default(0)->after('ratings');
            $table->integer('trustpilot_reviews')->default(0)->after('google_reviews');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('searches', function (Blueprint $table) {
            $table->dropColumn(['google_reviews', 'trustpilot_reviews']);
        });
    }
};
