<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crews are organised per production, not per line and day.
 *
 * A production is one run of a line: the consecutive orders of the same item,
 * which become one work sheet. Its crew is kept on its orders, so it follows
 * them when the plan is rearranged, and a person can work on several
 * productions in a day as long as their times do not overlap.
 *
 * How many people a production needs depends on the format as well as on the
 * line, so the requirement moves next to the line's capacity for that format.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orto_line_capacities', function (Blueprint $table) {
            // Null: the line's own requirement applies.
            $table->unsignedSmallInteger('required_people')->nullable();
        });

        Schema::create('orto_production_crew', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orto_orders')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('orto_people')->cascadeOnDelete();
            $table->unique(['order_id', 'person_id']);
            $table->index('person_id');
        });

        // One line per person per day: replaced by the table above.
        Schema::dropIfExists('orto_crew_assignments');
    }

    public function down(): void
    {
        Schema::create('orto_crew_assignments', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->foreignId('line_id')->constrained('orto_lines')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('orto_people')->cascadeOnDelete();
            $table->unique(['day', 'person_id']);
            $table->index(['day', 'line_id']);
        });

        Schema::dropIfExists('orto_production_crew');

        Schema::table('orto_line_capacities', function (Blueprint $table) {
            $table->dropColumn('required_people');
        });
    }
};
