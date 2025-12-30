<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// New fields for GeneratedSlip model:

// variation_type (string: core/hedge/balanced/opposite)

// edge_score (float)

// error (nullable text/JSON)

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('generated_slips', function (Blueprint $table) {
            //
            $table->string('variation_type')->nullable()->after('confidence_score');
            $table->float('edge_score')->default(0.0)->after('variation_type');
            $table->text('error')->nullable()->after('edge_score');
        }); 
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generated_slips', function (Blueprint $table) {
            //
        });
    }
};
