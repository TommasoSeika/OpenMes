<?php

use Illuminate\Support\Facades\Route;
use Modules\Ortofrutta\Http\Controllers\CrewController;
use Modules\Ortofrutta\Http\Controllers\MarginController;
use Modules\Ortofrutta\Http\Controllers\MissionController;
use Modules\Ortofrutta\Http\Controllers\OrderController;
use Modules\Ortofrutta\Http\Controllers\PlanController;
use Modules\Ortofrutta\Http\Controllers\PlantController;
use Modules\Ortofrutta\Http\Controllers\TerminalController;
use Modules\Ortofrutta\Http\Controllers\TraceController;
use Modules\Ortofrutta\Http\Controllers\WorkSheetController;

/*
 * Loaded from the provider, so these routes exist only while the module is
 * enabled. They sit under /admin so the `tab.access` middleware guards them:
 * the provider registers the "ortofrutta" tab, whose permission is
 * `tab:ortofrutta` (administrators always pass).
 */

// The address the screens had before: old links and open tabs still land.
Route::middleware(['web', 'auth', 'tab.access'])->get('admin/ortofrutta/{path?}', [PlantController::class, 'moved'])
    ->where('path', '.*')->name('ortofrutta.moved');

Route::middleware(['web', 'auth', 'tab.access'])->prefix('admin/stabilimento')->name('ortofrutta.')->group(function () {
    // Plant map, deliveries, demo reset.
    Route::get('/', [PlantController::class, 'index'])->name('plant');
    Route::get('/conferimenti', [PlantController::class, 'deliveries'])->name('deliveries');
    Route::post('/conferimenti', [PlantController::class, 'storeDelivery'])->name('deliveries.store');
    Route::post('/demo/ripristina', [PlantController::class, 'reset'])->name('demo.reset');

    // 1. Orders of the day.
    Route::get('/ordini', [OrderController::class, 'index'])->name('orders');
    Route::post('/ordini/simula', [OrderController::class, 'simulate'])->name('orders.simulate');
    Route::post('/ordini/scorta', [OrderController::class, 'stock'])->name('orders.stock');
    Route::patch('/ordini/{order}/priorita', [OrderController::class, 'priority'])->name('orders.priority');

    // 2. Line plan.
    Route::get('/piano', [PlanController::class, 'index'])->name('plan');
    Route::post('/piano/sposta', [PlanController::class, 'move'])->name('plan.move');
    Route::post('/piano/proponi', [PlanController::class, 'propose'])->name('plan.propose');
    Route::post('/piano/lancia', [PlanController::class, 'launch'])->name('plan.launch');

    // 3. Crews, one per production.
    Route::get('/squadre', [CrewController::class, 'index'])->name('crews');
    Route::post('/squadre/assegna', [CrewController::class, 'assign'])->name('crews.assign');
    Route::post('/squadre/rimuovi', [CrewController::class, 'unassign'])->name('crews.unassign');
    Route::post('/squadre/copia', [CrewController::class, 'copy'])->name('crews.copy');
    Route::post('/squadre/svuota', [CrewController::class, 'clear'])->name('crews.clear');
    Route::post('/squadre/proponi', [CrewController::class, 'propose'])->name('crews.propose');

    // Work lists of the forklift drivers, the labels, and the terminal that carries the missions out.
    Route::get('/missioni', [MissionController::class, 'index'])->name('missions');
    Route::post('/missioni/genera', [MissionController::class, 'generate'])->name('missions.generate');
    Route::post('/missioni/{mission}/assegna', [MissionController::class, 'assign'])->name('missions.assign');
    Route::get('/etichette', [MissionController::class, 'labels'])->name('labels');
    Route::get('/terminale', [TerminalController::class, 'index'])->name('terminal');
    Route::post('/terminale/scansiona', [TerminalController::class, 'scan'])->name('terminal.scan');

    // 4. Work sheets.
    Route::get('/fogli', [WorkSheetController::class, 'index'])->name('sheets');
    Route::get('/fogli/{sheet}', [WorkSheetController::class, 'show'])->name('sheets.show');
    Route::put('/fogli/{sheet}', [WorkSheetController::class, 'update'])->name('sheets.update');
    Route::post('/fogli/{sheet}/chiudi', [WorkSheetController::class, 'close'])->name('sheets.close');

    // 5. Traceability: customer → producer and raw lot → customers.
    Route::get('/tracciabilita', [TraceController::class, 'index'])->name('trace');

    // 6. Margins and the price simulator.
    Route::get('/margini', [MarginController::class, 'index'])->name('margins');
    Route::post('/margini/simula', [MarginController::class, 'simulate'])->name('margins.simulate');
});
