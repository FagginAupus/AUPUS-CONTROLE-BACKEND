<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\TokenBlacklistedException;
use Illuminate\Http\JsonResponse;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            // Log adicional para uploads
            if (request()->is('api/propostas/*/upload-documento')) {
                \Log::error('Erro em upload de documento', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'url' => request()->url(),
                    'method' => request()->method(),
                    'user_id' => auth()->id(),
                    'request_size' => request()->server('CONTENT_LENGTH'),
                    'memory_usage' => memory_get_usage(true)
                ]);
            }
        });
    }

    /**
     * ✅ CORREÇÃO PRINCIPAL: Render exceptions para API
     */
    public function render($request, Throwable $e): JsonResponse|\Illuminate\Http\Response|\Symfony\Component\HttpFoundation\Response
    {
        // ✅ SEMPRE retornar JSON para rotas da API
        if ($request->is('api/*') || $request->expectsJson()) {
            return $this->renderApiException($request, $e);
        }

        return parent::render($request, $e);
    }

    /**
     * ✅ Renderizar exceções da API sempre como JSON
     */
    protected function renderApiException(Request $request, Throwable $e): JsonResponse
    {
        \Log::error('API Exception', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'url' => $request->url(),
            'method' => $request->method(),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
            'user_id' => auth()->id() ?? 'guest',
            'trace' => config('app.debug') ? $e->getTraceAsString() : 'Hidden in production'
        ]);

        // ✅ JWT Exceptions - Token expirado/inválido
        if ($e instanceof TokenExpiredException) {
            return response()->json([
                'success' => false,
                'message' => 'Token expirado. Faça login novamente.',
                'error_type' => 'token_expired',
                'requires_login' => true
            ], 401);
        }

        if ($e instanceof TokenInvalidException) {
            return response()->json([
                'success' => false,
                'message' => 'Token inválido. Faça login novamente.',
                'error_type' => 'token_invalid', 
                'requires_login' => true
            ], 401);
        }

        if ($e instanceof TokenBlacklistedException) {
            return response()->json([
                'success' => false,
                'message' => 'Token foi invalidado. Faça login novamente.',
                'error_type' => 'token_blacklisted',
                'requires_login' => true
            ], 401);
        }

        if ($e instanceof JWTException) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de autenticação JWT. Faça login novamente.',
                'error_type' => 'jwt_error',
                'requires_login' => true
            ], 401);
        }

        // ✅ Authentication Exception
        if ($e instanceof AuthenticationException) {
            return response()->json([
                'success' => false,
                'message' => 'Não autenticado. Faça login.',
                'error_type' => 'unauthenticated',
                'requires_login' => true
            ], 401);
        }

        // ✅ Validation Exception
        if ($e instanceof ValidationException) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos.',
                'error_type' => 'validation_error',
                'errors' => $e->errors()
            ], 422);
        }

        // ✅ Model Not Found
        if ($e instanceof ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Registro não encontrado.',
                'error_type' => 'not_found'
            ], 404);
        }

        // ✅ Route Not Found
        if ($e instanceof NotFoundHttpException) {
            return response()->json([
                'success' => false,
                'message' => 'Rota não encontrada.',
                'error_type' => 'route_not_found'
            ], 404);
        }

        // ✅ Method Not Allowed
        if ($e instanceof MethodNotAllowedHttpException) {
            return response()->json([
                'success' => false,
                'message' => 'Método HTTP não permitido.',
                'error_type' => 'method_not_allowed',
                'allowed_methods' => $e->getHeaders()['Allow'] ?? []
            ], 405);
        }

        // ✅ Upload/File Errors
        if (str_contains($e->getMessage(), 'upload') || str_contains($e->getMessage(), 'file') || str_contains($e->getMessage(), 'storage')) {
            return response()->json([
                'success' => false,
                'message' => 'Erro no upload do arquivo: ' . $e->getMessage(),
                'error_type' => 'upload_error'
            ], 500);
        }

        // ✅ Database Errors
        if (str_contains(get_class($e), 'Database') || str_contains($e->getMessage(), 'database') || str_contains($e->getMessage(), 'SQL')) {
            return response()->json([
                'success' => false,
                'message' => 'Erro interno do banco de dados.',
                'error_type' => 'database_error'
            ], 500);
        }

        // ✅ Generic Server Error
        $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
        
        return response()->json([
            'success' => false,
            'message' => config('app.debug') 
                ? $e->getMessage() 
                : 'Erro interno do servidor.',
            'error_type' => 'server_error',
            'debug_info' => config('app.debug') ? [
                'exception' => get_class($e),
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ] : null
        ], $statusCode >= 100 && $statusCode < 600 ? $statusCode : 500);
    }

    /**
     * ✅ Handle unauthenticated users
     */
    protected function unauthenticated($request, AuthenticationException $exception): JsonResponse|\Illuminate\Http\Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => 'Não autenticado. Faça login.',
                'error_type' => 'unauthenticated',
                'requires_login' => true
            ], 401);
        }

        return redirect()->guest(route('login'));
    }
}