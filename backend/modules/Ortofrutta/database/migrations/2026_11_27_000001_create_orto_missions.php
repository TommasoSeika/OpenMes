<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forklift missions: the bins a line needs, from which cell, by when, who
 * brings them, and how far they have got. They are generated from the line
 * plan and carried out by scanning the label of the bins (pick) and the code
 * of the line (delivery).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orto_missions', function (Blueprint $table) {
            $table->id();
            // Progressive of the day, shown as M-012.
            $table->unsignedInteger('number');
            $table->date('day');
            $table->foreignId('order_id')->constrained('orto_orders')->cascadeOnDelete();
            $table->foreignId('line_id')->constrained('orto_lines')->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('orto_lots')->cascadeOnDelete();
            $table->foreignId('cell_id')->nullable()->constrained('orto_cells')->nullOnDelete();
            $table->unsignedInteger('bins');
            $table->decimal('kg', 12, 1);
            $table->dateTime('due_at');
            // The priority the office gave the order: breaks ties between missions due together.
            $table->unsignedSmallInteger('priority')->default(2);
            // The forklift driver it is given to.
            $table->foreignId('person_id')->nullable()->constrained('orto_people')->nullOnDelete();
            // todo | picked (bins scanned at the cell) | delivered (line scanned)
            $table->string('status', 12)->default('todo');
            $table->dateTime('picked_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['day', 'status']);
            $table->index(['day', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orto_missions');
    }
};
