<?php

declare(strict_types=1);

namespace Modules\Integrations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Application\AuditLogger;
use Modules\Integrations\Application\Webhooks;

final class WebhookController
{
    public function __construct(private readonly Webhooks $webhooks, private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $eps = DB::table('webhook_endpoints')->orderBy('id')->get()->map(fn ($e) => [
            'id' => (int) $e->id, 'name' => $e->name, 'url' => $e->url, 'events' => json_decode($e->events, true), 'active' => (bool) $e->active,
            'pending' => DB::table('webhook_deliveries')->where('webhook_endpoint_id', $e->id)->where('status', 'pending')->count(),
            'failed' => DB::table('webhook_deliveries')->where('webhook_endpoint_id', $e->id)->where('status', 'failed')->count(),
        ]);

        return response()->json(['data' => $eps, 'events' => array_keys(Webhooks::EVENTS)]);
    }

    /** The secret is returned once, at creation. */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'url' => ['required', 'url', 'max:500'], 'events' => ['required', 'array', 'min:1'], 'events.*' => ['in:'.implode(',', array_keys(Webhooks::EVENTS))]]);
        $this->webhooks->assertSafeUrl($d['url']);
        $secret = bin2hex(random_bytes(24));
        $id = DB::table('webhook_endpoints')->insertGetId(['name' => $d['name'], 'url' => $d['url'], 'secret' => $secret, 'events' => json_encode(array_values($d['events'])), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('webhook.created', 'webhook', $id, ['name' => $d['name'], 'url' => $d['url'], 'events' => $d['events']]);

        return response()->json(['id' => $id, 'secret' => $secret], 201);
    }

    public function toggle(Request $request, int $endpoint): JsonResponse
    {
        $d = $request->validate(['active' => ['required', 'boolean']]);
        abort_unless(DB::table('webhook_endpoints')->where('id', $endpoint)->exists(), 404);
        DB::table('webhook_endpoints')->where('id', $endpoint)->update(['active' => $d['active'], 'updated_at' => now()]);
        $this->audit->record('webhook.toggled', 'webhook', $endpoint, $d);

        return response()->json(null, 204);
    }

    public function deliveries(int $endpoint): JsonResponse
    {
        return response()->json(['data' => DB::table('webhook_deliveries')->where('webhook_endpoint_id', $endpoint)->orderByDesc('id')->limit(100)
            ->get(['id', 'event', 'status', 'attempts', 'last_code', 'last_error', 'created_at'])]);
    }
}
