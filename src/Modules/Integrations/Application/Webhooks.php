<?php

declare(strict_types=1);

namespace Modules\Integrations\Application;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Sales\Domain\Events\CreditNoteIssued;
use Modules\Sales\Domain\Events\CustomerPaid;
use Modules\Sales\Domain\Events\InvoiceIssued;
use Modules\Shared\Domain\DomainEvent;

/**
 * Outgoing webhooks with a transactional outbox: the delivery row is written inside the business transaction
 * (no event is lost or sent for a rolled-back sale); `webhooks:deliver` sends it with retries and backoff.
 * Each request carries X-ERP-Signature: sha256=HMAC(secret, "<timestamp>.<body>") and X-ERP-Timestamp.
 */
final class Webhooks
{
    /** event name => domain event class */
    public const EVENTS = ['invoice.issued' => InvoiceIssued::class, 'payment.received' => CustomerPaid::class, 'credit_note.issued' => CreditNoteIssued::class];

    public function enqueue(DomainEvent $event): void
    {
        $name = array_search($event::class, self::EVENTS, true);
        if ($name === false) {
            return;
        }
        $payload = json_encode(['event' => $name, 'occurred_at' => now()->toIso8601String(), 'data' => $this->data($event)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (DB::table('webhook_endpoints')->where('active', true)->get() as $ep) {
            if (in_array($name, json_decode($ep->events, true), true)) {
                DB::table('webhook_deliveries')->insert(['webhook_endpoint_id' => $ep->id, 'event' => $name, 'payload' => $payload, 'next_attempt_at' => now(), 'created_at' => now()]);
            }
        }
    }

    /** Sends every due delivery once. @return array{sent:int, failed:int} */
    public function deliverDue(int $limit = 50): array
    {
        $sent = $failed = 0;
        $max = (int) config('erp.webhooks.max_attempts');
        $due = DB::table('webhook_deliveries as d')->join('webhook_endpoints as e', 'e.id', '=', 'd.webhook_endpoint_id')
            ->where('d.status', 'pending')->where('d.next_attempt_at', '<=', now())->orderBy('d.id')->limit($limit)
            ->get(['d.id', 'd.payload', 'd.attempts', 'e.url', 'e.secret']);
        foreach ($due as $d) {
            [$ok, $code, $err] = $this->send($d->url, $d->secret, $d->payload);
            $attempts = (int) $d->attempts + 1;
            if ($ok) {
                $sent++;
                DB::table('webhook_deliveries')->where('id', $d->id)->update(['status' => 'delivered', 'attempts' => $attempts, 'last_code' => $code, 'last_error' => null, 'next_attempt_at' => null]);
            } else {
                $failed++;
                $giveUp = $attempts >= $max;
                DB::table('webhook_deliveries')->where('id', $d->id)->update([
                    'status' => $giveUp ? 'failed' : 'pending', 'attempts' => $attempts, 'last_code' => $code, 'last_error' => $err,
                    'next_attempt_at' => $giveUp ? null : now()->addMinutes(min(240, 2 ** $attempts)),   // 2,4,8,16,32 min...
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /** Rejects URLs that are not http(s) or that point at link-local/metadata addresses (and private ones when configured). */
    public function assertSafeUrl(string $url): void
    {
        $p = parse_url($url);
        if (! in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host'])) {
            throw new DomainException('Webhook URL must be http(s).');
        }
        $ips = gethostbynamel($p['host']) ?: [];
        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            if (! $public && (str_starts_with($ip, '169.254.') || ! config('erp.webhooks.allow_private'))) {
                throw new DomainException("Webhook target {$ip} is not allowed.");
            }
        }
    }

    /** @return array{0:bool,1:?int,2:?string} */
    private function send(string $url, string $secret, string $body): array
    {
        try {
            $this->assertSafeUrl($url);
            $ts = (string) time();
            $r = Http::timeout(8)->withoutRedirecting()->withBody($body, 'application/json')->withHeaders([
                'X-ERP-Timestamp' => $ts, 'X-ERP-Signature' => 'sha256='.hash_hmac('sha256', $ts.'.'.$body, $secret), 'User-Agent' => 'ERP-Webhook/1',
            ])->post($url);

            return [$r->successful(), $r->status(), $r->successful() ? null : 'HTTP '.$r->status()];
        } catch (\Throwable $e) {
            return [false, null, mb_substr($e->getMessage(), 0, 240)];
        }
    }

    /** @return array<string,mixed> */
    private function data(DomainEvent $e): array
    {
        return match (true) {
            $e instanceof InvoiceIssued => ['invoice_id' => $e->invoiceId, 'number' => $e->number, 'order_id' => $e->orderId, 'net' => (string) $e->net, 'tax' => (string) $e->tax, 'gross' => (string) $e->gross, 'currency' => config('erp.base_currency')],
            $e instanceof CustomerPaid => ['receipt_id' => $e->receiptId, 'customer' => $e->customerName, 'amount' => (string) $e->amount, 'reference' => $e->reference, 'currency' => config('erp.base_currency')],
            $e instanceof CreditNoteIssued => ['credit_note_id' => $e->creditNoteId, 'number' => $e->number, 'invoice_id' => $e->invoiceId, 'net' => (string) $e->net, 'tax' => (string) $e->tax, 'gross' => (string) $e->gross],
            default => [],
        };
    }
}
