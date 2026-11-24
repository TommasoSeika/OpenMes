<?php

namespace Modules\Ortofrutta\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Ortofrutta\Http\Requests\LineActionRequest;
use Modules\Ortofrutta\Http\Requests\MoveOrderRequest;
use Modules\Ortofrutta\Models\Line;
use Modules\Ortofrutta\Models\Order;
use Modules\Ortofrutta\Services\PlanService;
use Modules\Ortofrutta\Support\Demo;

/**
 * Screen 2 — the line plan. Moves answer with the whole recalculated board, so
 * the screen redraws durations, changeovers and truck warnings at once.
 */
class PlanController extends OrtoController
{
    public function __construct(private PlanService $plan) {}

    public function index(Request $request): Response
    {
        $day = $this->day($request);

        return $this->page('Plan', [
            'board' => Demo::loaded() ? $this->plan->board($day) : null,
        ], $day);
    }

    public function move(MoveOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $order = Order::query()->with('item')->findOrFail($data['order_id']);
        $line = ! empty($data['line_id']) ? Line::query()->with('capacities')->findOrFail($data['line_id']) : null;

        try {
            $this->plan->move($order, $line, (int) $data['position']);
        } catch (DomainException $e) {
            return $this->refused($e, $order);
        }

        return $this->board($order);
    }

    public function propose(): JsonResponse
    {
        $day = Demo::day();
        $result = $this->plan->propose($day);

        return response()->json([
            'board' => $this->plan->board($day),
            'message' => $result['unplaced'] > 0
                ? sprintf('Sequenza proposta: %d ordini in linea, %d senza una linea adatta.', $result['placed'], $result['unplaced'])
                : sprintf('Sequenza proposta: %d ordini ordinati per camion, articoli uguali vicini.', $result['placed']),
        ]);
    }

    /** Launch the next group of a line: its work sheet is created from the plan. */
    public function launch(LineActionRequest $request): JsonResponse
    {
        $day = Demo::day();
        $line = Line::query()->with('capacities')->findOrFail((int) $request->validated('line_id'));

        try {
            $sheet = $this->plan->launch($line, $day);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'board' => $this->plan->board($day)], 422);
        }

        return response()->json([
            'board' => $this->plan->board($day),
            'message' => sprintf('Foglio di lavorazione %d creato per %s.', $sheet->number, $line->name),
            'sheet_id' => $sheet->id,
        ]);
    }

    private function board(Order $order): JsonResponse
    {
        return response()->json(['board' => $this->plan->board($order->day->toImmutable())]);
    }

    private function refused(DomainException $e, Order $order): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'board' => $this->plan->board($order->day->toImmutable()),
        ], 422);
    }
}
