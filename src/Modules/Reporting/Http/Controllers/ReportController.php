<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Domain\Permission;
use Modules\Reporting\Application\PlanningService;
use Modules\Reporting\Application\ReportQueries;
use Modules\Shared\Http\ApiController;

final class ReportController extends ApiController
{
    public function dashboard(ReportQueries $q): JsonResponse
    {
        return response()->json($q->dashboard(new DateTimeImmutable));
    }

    public function expiring(Request $request, ReportQueries $q): JsonResponse
    {
        $d = $request->validate(['days' => ['integer', 'between:1,730']]);

        return response()->json(['data' => $q->expiring($d['days'] ?? 90, new DateTimeImmutable)]);
    }

    public function slowMoving(Request $request, ReportQueries $q): JsonResponse
    {
        $d = $request->validate(['days' => ['integer', 'between:30,730']]);

        return response()->json(['data' => $q->slowMoving($d['days'] ?? 90, new DateTimeImmutable)]);
    }

    public function productProfit(Request $request, ReportQueries $q): JsonResponse
    {
        $this->access->authorize($this->userId($request), Permission::InventoryViewCost);   // margins reveal costs
        $d = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $q->productProfitability(new DateTimeImmutable($d['from'] ?? '-90 days'), new DateTimeImmutable($d['to'] ?? 'today'))]);
    }

    public function shipmentProfit(Request $request, ReportQueries $q): JsonResponse
    {
        $this->access->authorize($this->userId($request), Permission::InventoryViewCost);

        return response()->json(['data' => $q->shipmentProfitability()]);
    }

    public function forecast(Request $request, PlanningService $planning): JsonResponse
    {
        $d = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id'], 'horizon' => ['integer', 'between:1,12']]);

        return response()->json($planning->forecast($d['product_id'], $d['horizon'] ?? 3));
    }

    public function reorder(Request $request, PlanningService $planning): JsonResponse
    {
        $d = $request->validate(['warehouse_id' => ['nullable', 'integer']]);
        if (isset($d['warehouse_id'])) {
            $this->warehouse($request, $d['warehouse_id']);
        }

        return response()->json(['data' => $planning->reorderSuggestions($d['warehouse_id'] ?? null)]);
    }
}
