<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') {
            throw new ApiException('IDEMPOTENCY_KEY_REQUIRED', BiMsg::of('此操作需要 Idempotency-Key 请求头', 'This request requires an Idempotency-Key header'), 400);
        }
        if (strlen($key) > 64) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('Idempotency-Key 过长', 'Idempotency-Key is too long'), 422);
        }

        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $methodPath = $request->method().':'.$request->path();
        $hash = hash('sha256', $request->getContent());

        $record = IdempotencyKey::query()
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->first();

        if ($record !== null) {
            if ($record->method_path !== $methodPath || $record->request_hash !== $hash) {
                throw new ApiException('IDEMPOTENCY_KEY_REUSED', BiMsg::of('幂等键已被用于不同请求', 'Idempotency key was already used for a different request'), 422);
            }
            if ($record->status === 'completed' && $record->response_body !== null) {
                return response()->json($record->response_body, $record->response_code ?? 200);
            }
        } else {
            IdempotencyKey::query()->create([
                'user_id' => $user->id,
                'key' => $key,
                'method_path' => $methodPath,
                'request_hash' => $hash,
                'status' => 'processing',
                'created_at' => now(),
            ]);
        }

        $response = $next($request);

        if ($response->isSuccessful() || $response->isClientError()) {
            $body = json_decode($response->getContent(), true);
            IdempotencyKey::query()
                ->where('user_id', $user->id)
                ->where('key', $key)
                ->update([
                    'status' => 'completed',
                    'response_code' => $response->getStatusCode(),
                    'response_body' => is_array($body) ? $body : ['raw' => $response->getContent()],
                ]);
        }

        return $response;
    }
}
