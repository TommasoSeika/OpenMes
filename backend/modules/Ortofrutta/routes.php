<?php

use Illuminate\Support\Facades\Route;
use Modules\Ortofrutta\Http\Controllers\CrewController;
use Modules\Ortofrutta\Http\Controllers\MarginController;
use Modules\Ortofrutta\Http\Controllers\OrderController;
use Modules\Ortofrutta\Http\Controllers\PlanController;
use Modules\Ortofrutta\Http\Controllers\PlantController;
use Modules\Ortofrutta\Http\Controllers\WorkSheetController;

/*
 * Loaded from the provider, so these routes exist only while the module is
 * enabled. They sit under /admin so the `tab.access` middleware guards them:
 * the provider registers the "ortofrutta" tab, whose permission is
 * `tab:ortofrutta` (administrators always pass).
 */
Route::middleware(['web', 'auth', 'tab.access'])->prefix('admin/ortofrutta')->name('ortofrutta.')->group(function () {
    // Plant map, deliveries, bin missions, demo reset.
    Route::get('/', [PlantController::class, 'index'])->name('plant');
    Route::get('/conferimenti', [PlantController::class, 'deliveries'])->name('deliveries');
    Route::post('/conferimenti', [PlantController::class, 'storeDelivery'])->name('deliveries.store');
    Route::get('/missioni', [PlantController::class, 'missions'])->name('missions');
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

    // 3. Crews.
    Route::get('/squadre', [CrewController::class, 'index'])->name('crews');
    Route::post('/squadre/assegna', [CrewController::class, 'assign'])->name('crews.assign');
    Route::post('/squadre/rimuovi', [CrewController::class, 'unassign'])->name('crews.unassign');
    Route::post('/squadre/proponi', [CrewController::class, 'propose'])->name('crews.propose');

    // 4. Work sheets.
    Route::get('/fogli', [WorkSheetController::class, 'index'])->name('sheets');
    Route::get('/fogli/{sheet}', [WorkSheetController::class, 'show'])->name('sheets.show');
    Route::put('/fogli/{sheet}', [WorkSheetController::class, 'update'])->name('sheets.update');
    Route::post('/fogli/{sheet}/chiudi', [WorkSheetController::class, 'close'])->name('sheets.close');

    // 5. Margins and the price simulator.
    Route::get('/margini', [MarginController::class, 'index'])->name('margins');
    Route::post('/margini/simula', [MarginController::class, 'simulate'])->name('margins.simulate');
});
