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
        Schema::table('head_to_head', function (Blueprint $table) {
            // Add missing columns
            // if (!Schema::hasColumn('head_to_head', 'home_team_id')) {
            //     $table->foreignId('home_team_id')->constrained('teams')->after('match_id');
            // }
            if (!Schema::hasColumn('head_to_head', 'avg_goals_per_match')) {
                $table->float('avg_goals_per_match')->nullable()->after('away_goals');
            }
            if (!Schema::hasColumn('head_to_head', 'away_team_id')) {
                $table->foreignId('away_team_id')->constrained('teams')->after('home_team_id');
            }

        

            // // Rename if needed
            // if (Schema::hasColumn('head_to_head', 'last_meetings')) {
            //     // Column already exists with correct name
            // }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('head_head', function (Blueprint $table) {
            //
        });
    }
};
