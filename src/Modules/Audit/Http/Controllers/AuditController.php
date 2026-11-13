<?php

declare(strict_types=1);

namespace Modules\Audit\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Application\AuditVerifier;

final class AuditController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'user_id' => ['integer'], 'action' => ['string', 'max:80'], 'subject_type' => ['string', 'max:40'],
            'subject_id' => ['string', 'max:64'], 'per_page' => ['integer', 'between:1,200'],
        ]);
        $query = DB::table('audit_logs')->orderByDesc('id');
        foreach (['user_id', 'subject_type', 'subject_id'] as $f) {
            if (isset($filters[$f])) {
                $query->where($f, $filters[$f]);
            }
        }
        if (isset($filters['action'])) {
            $query->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $filters['action']).'%');
        }

        return response()->json($query->paginate($filters['per_page'] ?? 50)->through(function ($row) {
            $row->data = json_decode($row->data, true);

            return $row;
        }));
    }

    public function verify(AuditVerifier $verifier): JsonResponse
    {
        $result = $verifier->verify();

        return response()->json($result, $result['ok'] ? 200 : 409);
    }
}
