<?php

declare(strict_types=1);

namespace Modules\Sales\Application;

use DateTimeImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Domain\Events\CustomerPaid;
use Modules\Shared\Application\EventBus;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/** Customer receipts allocated to invoices, open-item exposure for the credit limit, and ageing. */
final class ReceivablesService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TransactionRunner $tx,
        private readonly EventBus $events,
    ) {}

    /** @param array<int,Fixed> $allocations sales invoice id => amount (base currency) */
    public function receive(int $customerId, DateTimeImmutable $date, string $method, string $reference, array $allocations, ?string $cashAccount = null): int
    {
        if ($allocations === []) {
            throw new DomainException('A receipt must be allocated to at least one invoice.');
        }

        return $this->tx->run(function () use ($customerId, $date, $method, $reference, $allocations, $cashAccount): int {
            $customer = $this->db->table('customers')->where('id', $customerId)->first() ?? throw new DomainException("Customer {$customerId} not found.");
            $invoices = $this->db->table('sales_invoices as i')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')
                ->where('o.customer_id', $customerId)->whereIn('i.id', array_keys($allocations))->orderBy('i.id')->lockForUpdate()
                ->get(['i.id', 'i.number', 'i.gross', 'i.paid', 'i.credited'])->keyBy('id');

            $total = Fixed::zero();
            foreach ($allocations as $invoiceId => $amount) {
                $inv = $invoices[$invoiceId] ?? throw new DomainException("Invoice {$invoiceId} does not belong to this customer.");
                $balance = Fixed::units((int) $inv->gross - (int) $inv->paid - (int) $inv->credited);
                if (! $amount->isPositive() || $amount->gt($balance)) {
                    throw new DomainException("Allocation to {$inv->number} must be positive and not exceed its balance {$balance}.");
                }
                $total = $total->add($amount);
            }

            $receiptId = $this->db->table('customer_receipts')->insertGetId([
                'customer_id' => $customerId, 'received_on' => $date->format('Y-m-d'), 'amount' => $total->units,
                'method' => $method, 'reference' => $reference, 'cash_account' => $cashAccount, 'created_at' => now(),
            ]);
            foreach ($allocations as $invoiceId => $amount) {
                $this->db->table('receipt_allocations')->insert(['customer_receipt_id' => $receiptId, 'sales_invoice_id' => $invoiceId, 'amount' => $amount->units]);
                $this->db->table('sales_invoices')->where('id', $invoiceId)->update(['paid' => (int) $invoices[$invoiceId]->paid + $amount->units]);
            }
            $this->events->dispatch(new CustomerPaid($receiptId, $customer->name, $total, $reference, $cashAccount));

            return $receiptId;
        });
    }

    /** Open invoices (gross - paid - credited) plus orders reserved or shipped but not yet invoiced, VAT included. */
    public function exposure(int $customerId): Fixed
    {
        $open = (int) $this->db->table('sales_invoices as i')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')
            ->where('o.customer_id', $customerId)->selectRaw('COALESCE(SUM(i.gross - i.paid - i.credited), 0) as open')->value('open');

        $vat = Fixed::parse((string) config('erp.vat_rate'));
        $pending = Fixed::zero();
        $orders = $this->db->table('sales_orders')->where('customer_id', $customerId)->whereIn('status', ['reserved', 'shipped'])->pluck('id');
        foreach ($this->db->table('sales_order_lines')->whereIn('sales_order_id', $orders)->get(['quantity', 'unit_price']) as $l) {
            $net = Fixed::units((int) $l->quantity)->mul(Fixed::units((int) $l->unit_price));
            $pending = $pending->add($net->add($net->mul($vat)));
        }

        return Fixed::units($open)->add($pending);
    }

    /**
     * Open invoices bucketed by days overdue.
     *
     * @return array{buckets: array<string,string>, customers: list<array<string,mixed>>}
     */
    /** @param list<int>|null $branches only invoices of these branches (null = the whole company) */
    public function ageing(DateTimeImmutable $asOf, ?array $branches = null): array
    {
        $labels = ['current', '1-30', '31-60', '61-90', '90+'];
        $buckets = array_fill_keys($labels, 0);
        $perCustomer = [];

        $rows = $this->db->table('sales_invoices as i')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->when($branches !== null, fn ($q) => $q->whereIn('o.warehouse_id', $branches === [] ? [0] : $branches))
            ->whereRaw('i.gross - i.paid - i.credited > 0')->orderBy('c.name')->orderBy('i.id')
            ->get(['c.id as customer_id', 'c.name', 'i.id', 'i.number', 'i.due_date', 'i.gross', 'i.paid', 'i.credited']);
        foreach ($rows as $r) {
            $balance = (int) $r->gross - (int) $r->paid - (int) $r->credited;
            $due = new DateTimeImmutable($r->due_date ?? $asOf->format('Y-m-d'));
            $late = max(0, (int) $due->diff($asOf)->format('%r%a'));
            $bucket = match (true) {
                $late === 0 => 'current', $late <= 30 => '1-30', $late <= 60 => '31-60', $late <= 90 => '61-90', default => '90+'
            };
            $buckets[$bucket] += $balance;
            $perCustomer[$r->customer_id]['customer'] = $r->name;
            $perCustomer[$r->customer_id]['customer_id'] = (int) $r->customer_id;
            $perCustomer[$r->customer_id]['buckets'][$bucket] = ($perCustomer[$r->customer_id]['buckets'][$bucket] ?? 0) + $balance;
            $perCustomer[$r->customer_id]['total'] = ($perCustomer[$r->customer_id]['total'] ?? 0) + $balance;
            $perCustomer[$r->customer_id]['invoices'][] = ['number' => $r->number, 'due_date' => $r->due_date, 'balance' => (string) Fixed::units($balance), 'days_overdue' => $late];
        }

        $fmt = static fn (array $b) => array_map(static fn ($u) => (string) Fixed::units($u), $b);

        return [
            'buckets' => $fmt($buckets),
            'customers' => array_values(array_map(function ($c) use ($fmt) {
                $c['buckets'] = $fmt($c['buckets']);
                $c['total'] = (string) Fixed::units($c['total']);

                return $c;
            }, $perCustomer)),
        ];
    }
}
