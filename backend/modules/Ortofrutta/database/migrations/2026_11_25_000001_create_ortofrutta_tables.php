<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every table of the Ortofrutta module, prefixed `orto_` so nothing collides
 * with core. Single-company proof of concept: no tenant column and no soft
 * deletes, because no screen lets a user delete one of these records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orto_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->text('value')->nullable();
        });

        Schema::create('orto_lines', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('building', 40);
            $table->unsignedSmallInteger('required_people');
            $table->unsignedSmallInteger('startup_minutes')->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            // The matching core production line, when one could be created.
            $table->unsignedBigInteger('core_line_id')->nullable();
            $table->timestamps();
        });

        Schema::create('orto_line_capacities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_id')->constrained('orto_lines')->cascadeOnDelete();
            $table->string('format', 60);
            $table->decimal('pallets_per_hour', 6, 2);
            $table->unique(['line_id', 'format']);
        });

        Schema::create('orto_cells', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('building', 40);
            $table->string('block', 40)->nullable();
            $table->unsignedSmallInteger('row');
            $table->unsignedSmallInteger('col');
            $table->unsignedInteger('capacity_bins');
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->timestamps();
        });

        Schema::create('orto_customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('channel', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('orto_producers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('locality')->nullable();
            $table->boolean('is_member')->default(true);
            $table->timestamps();
        });

        Schema::create('orto_items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('description');
            $table->string('variety', 60);
            $table->string('size', 20);
            $table->string('format', 60);
            $table->string('packaging', 60);
            $table->foreignId('customer_id')->nullable()->constrained('orto_customers')->nullOnDelete();
            $table->decimal('kg_per_carton', 8, 2);
            $table->unsignedInteger('cartons_per_pallet');
            $table->timestamps();
        });

        Schema::create('orto_packaging_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('orto_items')->cascadeOnDelete();
            $table->string('component');
            $table->decimal('quantity', 8, 3);
            // Counted once per carton or once per pallet.
            $table->string('per', 10);
            $table->decimal('unit_cost', 10, 4);
        });

        Schema::create('orto_changeover_rules', function (Blueprint $table) {
            $table->id();
            // variety | format | packaging
            $table->string('type', 20);
            // Both null: the standard stop for this type of changeover.
            $table->foreignId('from_item_id')->nullable()->constrained('orto_items')->cascadeOnDelete();
            $table->foreignId('to_item_id')->nullable()->constrained('orto_items')->cascadeOnDelete();
            $table->unsignedSmallInteger('minutes');
        });

        Schema::create('orto_lots', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('producer_id')->constrained('orto_producers')->cascadeOnDelete();
            $table->string('variety', 60);
            $table->dateTime('received_at');
            $table->decimal('gross_kg', 12, 1);
            $table->decimal('tare_kg', 12, 1);
            $table->decimal('net_kg', 12, 1);
            $table->unsignedInteger('bins');
            $table->foreignId('cell_id')->nullable()->constrained('orto_cells')->nullOnDelete();
            $table->decimal('purchase_price_kg', 8, 3);
            $table->timestamps();
        });

        Schema::create('orto_people', function (Blueprint $table) {
            $table->id();
            $table->string('badge', 20)->unique();
            $table->string('name');
            $table->string('group_name', 60)->nullable();
            $table->string('qualification', 60);
            $table->decimal('hourly_cost', 8, 2);
            $table->json('enabled_lines');
            $table->timestamps();
        });

        Schema::create('orto_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('orto_people')->cascadeOnDelete();
            $table->date('day');
            // present | absent | early_exit
            $table->string('status', 20);
            $table->string('reason', 60)->nullable();
            $table->time('planned_in')->nullable();
            $table->time('planned_out')->nullable();
            $table->time('clock_in')->nullable();
            $table->time('clock_out')->nullable();
            $table->unique(['person_id', 'day']);
            $table->index('day');
        });

        Schema::create('orto_crew_assignments', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->foreignId('line_id')->constrained('orto_lines')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('orto_people')->cascadeOnDelete();
            // A person works on one line per day in this proof of concept.
            $table->unique(['day', 'person_id']);
            $table->index(['day', 'line_id']);
        });

        Schema::create('orto_work_sheets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->date('day');
            $table->foreignId('line_id')->constrained('orto_lines')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('orto_items')->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at');
            $table->unsignedInteger('pallets')->default(0);
            // open | closed
            $table->string('status', 10)->default('open');
            // Totals and costs, written by WorkSheetCosting every time the sheet is saved.
            $table->decimal('kg_in', 12, 1)->nullable();
            $table->decimal('kg_packed', 12, 1)->nullable();
            $table->decimal('kg_waste_cider', 12, 1)->nullable();
            $table->decimal('kg_waste_industry', 12, 1)->nullable();
            $table->decimal('labour_cost', 12, 2)->nullable();
            $table->decimal('packaging_cost', 12, 2)->nullable();
            $table->decimal('cost_per_kg', 10, 4)->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->index('day');
        });

        Schema::create('orto_work_sheet_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_sheet_id')->constrained('orto_work_sheets')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('orto_people')->cascadeOnDelete();
            $table->decimal('hours', 6, 2);
            // Copied from the person when the sheet is created, so a later change
            // of the hourly cost does not rewrite the cost of past sheets.
            $table->decimal('hourly_cost', 8, 2);
            $table->unique(['work_sheet_id', 'person_id']);
        });

        Schema::create('orto_work_sheet_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_sheet_id')->constrained('orto_work_sheets')->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('orto_lots')->cascadeOnDelete();
            $table->decimal('kg_in', 12, 1)->default(0);
            $table->decimal('kg_packed', 12, 1)->default(0);
            $table->decimal('kg_waste_cider', 12, 1)->default(0);
            $table->decimal('kg_waste_industry', 12, 1)->default(0);
            $table->unique(['work_sheet_id', 'lot_id']);
        });

        Schema::create('orto_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('day');
            $table->foreignId('customer_id')->constrained('orto_customers')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('orto_items')->cascadeOnDelete();
            $table->unsignedInteger('pallets');
            // axa (in and out the same day) | axb (in the evening before) | stock
            $table->string('type', 10);
            $table->dateTime('arrived_at');
            $table->dateTime('truck_at')->nullable();
            $table->unsignedSmallInteger('priority')->default(2);
            $table->string('notes')->nullable();
            // False until the order has "arrived" in the demo.
            $table->boolean('released')->default(true);
            // Where the order sits on the line plan.
            $table->foreignId('line_id')->nullable()->constrained('orto_lines')->nullOnDelete();
            $table->unsignedInteger('position')->nullable();
            // Set when the order is launched: from then on it cannot be moved.
            $table->foreignId('work_sheet_id')->nullable()->constrained('orto_work_sheets')->nullOnDelete();
            $table->timestamps();
            $table->index(['day', 'line_id', 'position']);
        });

        Schema::create('orto_price_list', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('orto_customers')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('orto_items')->cascadeOnDelete();
            // ISO week, e.g. 2026-W48.
            $table->string('week', 8);
            $table->decimal('price_kg', 8, 3);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->unique(['customer_id', 'item_id', 'week']);
        });
    }

    public function down(): void
    {
        foreach ([
            'orto_price_list', 'orto_orders', 'orto_work_sheet_lots', 'orto_work_sheet_people', 'orto_work_sheets',
            'orto_crew_assignments', 'orto_attendances', 'orto_people', 'orto_lots', 'orto_changeover_rules',
            'orto_packaging_components', 'orto_items', 'orto_producers', 'orto_customers', 'orto_cells',
            'orto_line_capacities', 'orto_lines', 'orto_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
