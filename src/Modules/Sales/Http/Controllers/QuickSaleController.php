<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Application\CashAccounts;
use Modules\Sales\Application\OrderLineRequest;
use Modules\Sales\Application\QuickSaleService;
use Modules\Sales\Domain\PaymentMethod;
use Modules\Sales\Domain\PaymentTerms;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

final class QuickSaleController extends ApiController
{
    public const UNKNOWN_BUYER = 'غير معروف';

    public function store(Request $request, QuickSaleService $service, CashAccounts $cash): JsonResponse
    {
        $d = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'payment_method' => ['required', 'in:cash,transfer,credit'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:200'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', Decimal::RULE],
            'lines.*.serials' => ['nullable', 'array', 'max:500'],
            'lines.*.serials.*' => ['string', 'max:80'],
        ]);
        $this->warehouse($request, $d['warehouse_id']);
        $method = PaymentMethod::from($d['payment_method']);
        $walkIn = (int) DB::table('customers')->where('is_walk_in', true)->value('id');

        if ($method === PaymentMethod::Credit) {
            // a debt can only be owed by a customer we registered
            if (empty($d['customer_id']) || (int) $d['customer_id'] === $walkIn) {
                throw new DomainException('البيع بدين على العميل لا يتم إلا لعميل مسجَّل لدينا. أضفه أولاً من شاشة العملاء.');
            }
            $customerId = (int) $d['customer_id'];
            $buyerName = null;
        } else {
            $customerId = ! empty($d['customer_id']) ? (int) $d['customer_id'] : $walkIn;
            $typed = trim((string) ($d['customer_name'] ?? ''));
            $buyerName = $customerId === $walkIn ? ($typed !== '' ? $typed : self::UNKNOWN_BUYER) : null;
        }
        $terms = new PaymentTerms($method, $method === PaymentMethod::Cash ? $cash->resolve('cash', null) : ($method === PaymentMethod::Transfer ? $cash->resolve('transfer', isset($d['bank_account_id']) ? (int) $d['bank_account_id'] : null) : null));

        $serials = [];
        foreach ($d['lines'] as $i => $l) {
            if (! empty($l['serials'])) {
                $serials[$i] = $l['serials'];
            }
        }
        $result = $service->sell($customerId, $buyerName, $d['warehouse_id'],
            array_map(fn ($l) => new OrderLineRequest((int) $l['product_id'], Decimal::of($l['quantity'])), $d['lines']), $serials, $terms, $d['payment_reference'] ?? null, $this->userId($request));

        return response()->json($result, 201);
    }
}
