<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Domain\Permission;
use Modules\Sales\Application\PriceSuggestionService;
use Modules\Sales\Domain\CustomerCategory;
use Modules\Shared\Domain\Fixed;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

final class PricingController extends ApiController
{
    public function suggestions(Request $request, PriceSuggestionService $service): JsonResponse
    {
        $this->access->authorize($this->userId($request), Permission::InventoryViewCost);   // suggestions reveal costs
        $d = $request->validate(['category' => ['required', 'in:wholesale,retail,direct'], 'margin_pct' => ['nullable', Decimal::RULE], 'basis' => ['nullable', 'in:last,average']]);
        $category = CustomerCategory::from($d['category']);
        $margin = isset($d['margin_pct']) ? Decimal::of($d['margin_pct']) : Fixed::parse((string) config('erp.pricing.margins.'.$category->value));

        return response()->json([
            'currency' => config('erp.base_currency'), 'step' => (string) $service->rounder()->step(), 'margin_pct' => (string) $margin, 'basis' => $d['basis'] ?? 'last',
            'data' => $service->suggest($category, $margin, $d['basis'] ?? 'last'),
        ]);
    }

    public function apply(Request $request, PriceSuggestionService $service): JsonResponse
    {
        $d = $request->validate([
            'category' => ['required', 'in:wholesale,retail,direct'],
            'prices' => ['required', 'array', 'min:1', 'max:500'], 'prices.*.product_id' => ['required', 'integer', 'distinct'], 'prices.*.price' => ['required', Decimal::RULE],
        ]);
        $prices = [];
        foreach ($d['prices'] as $p) {
            $prices[(int) $p['product_id']] = Decimal::of($p['price']);
        }

        return response()->json(['applied' => $service->apply(CustomerCategory::from($d['category']), $prices)]);
    }
}
