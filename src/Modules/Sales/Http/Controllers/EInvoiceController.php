<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Application\EInvoiceService;
use Modules\Shared\Http\ApiController;

final class EInvoiceController extends ApiController
{
    public function show(Request $request, int $invoice, EInvoiceService $service): JsonResponse
    {
        $this->branchOf($request, $invoice);

        return response()->json($this->guard(fn () => $service->document($invoice)));
    }

    public function ubl(Request $request, int $invoice, EInvoiceService $service): Response
    {
        $this->branchOf($request, $invoice);

        return response($this->guard(fn () => $service->ubl($invoice)), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"invoice-{$invoice}.xml\""]);
    }

    public function verify(EInvoiceService $service): JsonResponse
    {
        return response()->json($service->verifyChain());
    }

    /** Staff limited to some branches only see the invoices those branches issued. */
    private function branchOf(Request $request, int $invoice): void
    {
        $warehouse = DB::table('sales_invoices as i')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')->where('i.id', $invoice)->value('o.warehouse_id');
        if ($warehouse !== null) {
            $this->warehouse($request, (int) $warehouse);
        }
    }

    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (DomainException) {
            abort(404);
        }
    }
}
