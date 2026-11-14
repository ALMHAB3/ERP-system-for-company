<?php

declare(strict_types=1);

namespace Modules\Sales\Application;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Shared\Domain\Fixed;

/**
 * Electronic invoice: a tamper-evident chain (each invoice's SHA-256 covers its content and the previous invoice's
 * fingerprint), a machine-readable document, UBL 2.1 XML and a QR payload.
 * It is a generic, standards-based format. It is NOT certified by any tax authority: if one publishes a mandatory
 * schema, the mapping in ubl() is the single place to adapt.
 */
final class EInvoiceService
{
    /** Called inside the transaction that creates the invoice. */
    public function seal(int $invoiceId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(7301)');   // one chain: two invoices must never share a predecessor
        }
        $prev = DB::table('sales_invoices')->where('id', '<', $invoiceId)->whereNotNull('fingerprint')->orderByDesc('id')->value('fingerprint');
        DB::table('sales_invoices')->where('id', $invoiceId)->update([
            'prev_fingerprint' => $prev, 'fingerprint' => $this->compute($invoiceId, $prev),
        ]);
    }

    /** @return array<string,mixed> */
    public function document(int $invoiceId): array
    {
        $i = DB::table('sales_invoices as i')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->where('i.id', $invoiceId)
            ->first(['i.*', 'o.number as order_number', 'o.payment_method', 'o.payment_reference', 'o.customer_name', 'c.name as customer', 'c.tax_id as customer_tax_id', 'c.address as customer_address'])
            ?? throw new DomainException("Invoice {$invoiceId} not found.");
        $lines = $this->lines((int) $i->sales_order_id)->map(fn ($l) => [
            'sku' => $l->sku, 'name' => $l->name, 'uom' => $l->base_uom, 'quantity' => (string) Fixed::units((int) $l->quantity),
            'unit_price' => (string) Fixed::units((int) $l->unit_price), 'net' => (string) Fixed::units((int) $l->quantity)->mul(Fixed::units((int) $l->unit_price)),
        ])->all();
        $issued = (new \DateTimeImmutable((string) $i->created_at))->format('c');
        $company = (array) config('erp.company');

        return [
            'number' => $i->number, 'type' => 'invoice', 'issued_at' => $issued, 'due_date' => $i->due_date, 'currency' => config('erp.base_currency'),
            'order' => $i->order_number, 'payment_method' => $i->payment_method, 'payment_reference' => $i->payment_reference,
            'seller' => ['name' => config('erp.company_name'), 'tax_id' => $company['tax_id'] ?? '', 'address' => $company['address'] ?? '', 'phone' => $company['phone'] ?? ''],
            'buyer' => ['name' => $i->customer_name ?? $i->customer, 'tax_id' => $i->customer_tax_id, 'address' => $i->customer_address],
            'lines' => $lines,
            'totals' => ['net' => (string) Fixed::units((int) $i->net), 'tax' => (string) Fixed::units((int) $i->tax), 'gross' => (string) Fixed::units((int) $i->gross), 'vat_rate' => config('erp.vat_rate')],
            'fingerprint' => $i->fingerprint, 'previous_fingerprint' => $i->prev_fingerprint,
            'qr' => $this->qrPayload((string) config('erp.company_name'), (string) ($company['tax_id'] ?? ''), $issued, Fixed::units((int) $i->gross), Fixed::units((int) $i->tax), (string) $i->fingerprint),
        ];
    }

    /** UBL 2.1 Invoice (generic profile). */
    public function ubl(int $invoiceId): string
    {
        $d = $this->document($invoiceId);
        $e = fn (?string $v): string => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $c = $d['currency'];
        $amt = fn (string $v): string => number_format((float) $v, 4, '.', '');
        $party = fn (array $p): string => '<cac:Party>'
            .($p['tax_id'] ? '<cac:PartyTaxScheme><cbc:CompanyID>'.$e($p['tax_id']).'</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>' : '')
            .'<cac:PartyLegalEntity><cbc:RegistrationName>'.$e($p['name']).'</cbc:RegistrationName></cac:PartyLegalEntity>'
            .($p['address'] ? '<cac:PostalAddress><cbc:StreetName>'.$e($p['address']).'</cbc:StreetName></cac:PostalAddress>' : '').'</cac:Party>';
        $lines = '';
        foreach ($d['lines'] as $n => $l) {
            $lines .= '<cac:InvoiceLine><cbc:ID>'.($n + 1).'</cbc:ID><cbc:InvoicedQuantity unitCode="'.$e($l['uom']).'">'.$amt($l['quantity']).'</cbc:InvoicedQuantity>'
                .'<cbc:LineExtensionAmount currencyID="'.$c.'">'.$amt($l['net']).'</cbc:LineExtensionAmount>'
                .'<cac:Item><cbc:Name>'.$e($l['name']).'</cbc:Name><cac:SellersItemIdentification><cbc:ID>'.$e($l['sku']).'</cbc:ID></cac:SellersItemIdentification></cac:Item>'
                .'<cac:Price><cbc:PriceAmount currencyID="'.$c.'">'.$amt($l['unit_price']).'</cbc:PriceAmount></cac:Price></cac:InvoiceLine>';
        }
        $t = $d['totals'];

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            .'<cbc:UBLVersionID>2.1</cbc:UBLVersionID><cbc:ID>'.$e($d['number']).'</cbc:ID>'
            .'<cbc:IssueDate>'.substr($d['issued_at'], 0, 10).'</cbc:IssueDate><cbc:IssueTime>'.substr($d['issued_at'], 11, 8).'</cbc:IssueTime>'
            .($d['due_date'] ? '<cbc:DueDate>'.$e($d['due_date']).'</cbc:DueDate>' : '')
            .'<cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode><cbc:DocumentCurrencyCode>'.$c.'</cbc:DocumentCurrencyCode>'
            .'<cac:AdditionalDocumentReference><cbc:ID>fingerprint</cbc:ID><cbc:UUID>'.$e($d['fingerprint']).'</cbc:UUID></cac:AdditionalDocumentReference>'
            .'<cac:AccountingSupplierParty>'.$party($d['seller']).'</cac:AccountingSupplierParty>'
            .'<cac:AccountingCustomerParty>'.$party($d['buyer']).'</cac:AccountingCustomerParty>'
            .'<cac:TaxTotal><cbc:TaxAmount currencyID="'.$c.'">'.$amt($t['tax']).'</cbc:TaxAmount></cac:TaxTotal>'
            .'<cac:LegalMonetaryTotal><cbc:LineExtensionAmount currencyID="'.$c.'">'.$amt($t['net']).'</cbc:LineExtensionAmount>'
            .'<cbc:TaxExclusiveAmount currencyID="'.$c.'">'.$amt($t['net']).'</cbc:TaxExclusiveAmount><cbc:TaxInclusiveAmount currencyID="'.$c.'">'.$amt($t['gross']).'</cbc:TaxInclusiveAmount>'
            .'<cbc:PayableAmount currencyID="'.$c.'">'.$amt($t['gross']).'</cbc:PayableAmount></cac:LegalMonetaryTotal>'
            .$lines.'</Invoice>';
    }

    /** @return array{checked:int, broken:list<string>} invoices whose stored fingerprint no longer matches (or chain link broken) */
    public function verifyChain(): array
    {
        $broken = [];
        $prev = null;
        $checked = 0;
        foreach (DB::table('sales_invoices')->whereNotNull('fingerprint')->orderBy('id')->cursor() as $i) {
            $checked++;
            if ($i->prev_fingerprint !== $prev || $i->fingerprint !== $this->compute((int) $i->id, $prev)) {
                $broken[] = $i->number;
            }
            $prev = $i->fingerprint;
        }

        return ['checked' => $checked, 'broken' => $broken];
    }

    private function compute(int $invoiceId, ?string $prev): string
    {
        $i = DB::table('sales_invoices as i')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')->where('i.id', $invoiceId)
            ->first(['i.number', 'i.net', 'i.tax', 'i.gross', 'i.created_at', 'o.number as order_number', 'o.customer_id', 'i.sales_order_id']);
        $lines = $this->lines((int) $i->sales_order_id)->map(fn ($l) => [(int) $l->product_id, (int) $l->quantity, (int) $l->unit_price])->all();

        return hash('sha256', json_encode([$i->number, (string) $i->created_at, $i->order_number, (int) $i->customer_id, $lines, (int) $i->net, (int) $i->tax, (int) $i->gross, $prev], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function lines(int $orderId): Collection
    {
        return DB::table('sales_order_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->where('l.sales_order_id', $orderId)->orderBy('l.id')
            ->get(['l.product_id', 'l.quantity', 'l.unit_price', 'p.sku', 'p.name', 'p.base_uom']);
    }

    /** Base64 TLV (seller, tax id, time, total, tax, fingerprint): the layout used by common QR e-invoice schemes. */
    private function qrPayload(string $seller, string $taxId, string $time, Fixed $gross, Fixed $tax, string $fingerprint): string
    {
        $tlv = '';
        foreach ([1 => $seller, 2 => $taxId, 3 => $time, 4 => (string) $gross, 5 => (string) $tax, 6 => $fingerprint] as $tag => $value) {
            $tlv .= chr($tag).chr(strlen($value)).$value;
        }

        return base64_encode($tlv);
    }
}
