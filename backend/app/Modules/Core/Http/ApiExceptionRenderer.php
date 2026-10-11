<?php

namespace App\Modules\Core\Http;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every exception on /api/* as {"error": {"code", "message", "details"}}.
 */
class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*', 'sanctum/*') || $request->expectsJson());

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*', 'sanctum/*')) {
                return null;
            }

            return self::toResponse($e);
        });
    }

    public static function toResponse(Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => self::json($e->errorCode, $e->getMessage(), $e->status, $e->details),
            $e instanceof ValidationException => self::json('VALIDATION_FAILED', BiMsg::of('提交的信息有误，请检查', 'Please check the submitted information'), 422, ['fields' => $e->errors()]),
            $e instanceof AuthenticationException => self::json('UNAUTHENTICATED', BiMsg::of('请先登录', 'Please log in first'), 401),
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => self::json('FORBIDDEN', BiMsg::of('没有权限执行此操作', 'You do not have permission for this action'), 403),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => self::json('NOT_FOUND', BiMsg::of('内容不存在', 'Not found'), 404),
            $e instanceof MethodNotAllowedHttpException => self::json('METHOD_NOT_ALLOWED', BiMsg::of('请求方式不正确', 'HTTP method not allowed'), 405),
            $e instanceof TokenMismatchException => self::json('CSRF_TOKEN_MISMATCH', BiMsg::of('页面已过期，请刷新后重试', 'Page expired, please refresh and try again'), 419),
            $e instanceof ThrottleRequestsException => self::json('TOO_MANY_ATTEMPTS', BiMsg::of('请求过于频繁，请稍后再试', 'Too many attempts, please try again later'), 429, [
                'retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 60),
            ]),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 419 => self::json('CSRF_TOKEN_MISMATCH', BiMsg::of('页面已过期，请刷新后重试', 'Page expired, please refresh and try again'), 419),
            $e instanceof HttpExceptionInterface => self::json('HTTP_ERROR', BiMsg::of('请求无法完成', 'Request could not be completed'), $e->getStatusCode()),
            default => config('app.debug') ? null : self::json('SERVER_ERROR', BiMsg::of('服务器开小差了，请稍后再试', 'Something went wrong, please try again later'), 500),
        };
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function json(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status);
    }
}
