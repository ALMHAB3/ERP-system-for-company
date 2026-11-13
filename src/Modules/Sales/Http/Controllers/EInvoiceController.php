<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Sales\Application\EInvoiceService;

final class EInvoiceController
{
    public function show(int $invoice, EInvoiceService $service): JsonResponse
    {
        return response()->json($this->guard(fn () => $service->document($invoice)));
    }

    public function ubl(int $invoice, EInvoiceService $service): Response
    {
        return response($this->guard(fn () => $service->ubl($invoice)), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"invoice-{$invoice}.xml\""]);
    }

    public function verify(EInvoiceService $service): JsonResponse
    {
        return response()->json($service->verifyChain());
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
