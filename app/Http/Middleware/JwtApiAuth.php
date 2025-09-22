<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;

/**
 * ✅ Middleware JWT personalizado que SEMPRE retorna JSON
 */
class JwtApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // ✅ Log da tentativa de autenticação
            \Log::info('JWT Auth Middleware - Inicio', [
                'url' => $request->url(),
                'method' => $request->method(),
                'has_auth_header' => $request->hasHeader('Authorization'),
                'user_agent' => $request->userAgent(),
                'ip' => $request->ip()
            ]);

            // ✅ Verificar se o header Authorization existe
            $authHeader = $request->header('Authorization');
            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
                \Log::warning('JWT Auth - Header Authorization ausente ou inválido', [
                    'auth_header' => $authHeader ? 'presente' : 'ausente',
                    'all_headers' => array_keys($request->headers->all())
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Token de acesso não fornecido.',
                    'error_type' => 'missing_token',
                    'requires_login' => true
                ], 401);
            }

            // ✅ Extrair e validar o token
            $token = substr($authHeader, 7);
            if (empty($token)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token vazio.',
                    'error_type' => 'empty_token',
                    'requires_login' => true
                ], 401);
            }

            // ✅ Autenticar usando JWT
            $user = JWTAuth::authenticate($token);
            
            if (!$user) {
                \Log::warning('JWT Auth - Usuário não encontrado para token válido');
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado.',
                    'error_type' => 'user_not_found',
                    'requires_login' => true
                ], 401);
            }

            // ✅ Verificar se usuário está ativo
            if (isset($user->is_active) && !$user->is_active) {
                \Log::warning('JWT Auth - Usuário inativo tentando acessar', [
                    'user_id' => $user->id,
                    'user_email' => $user->email ?? 'N/A'
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Usuário inativo.',
                    'error_type' => 'user_inactive',
                    'requires_login' => true
                ], 401);
            }

            // ✅ Log de sucesso
            \Log::info('JWT Auth - Autenticação bem-sucedida', [
                'user_id' => $user->id,
                'user_email' => $user->email ?? 'N/A',
                'user_role' => $user->role ?? 'N/A'
            ]);

            // ✅ Definir usuário autenticado
            auth()->setUser($user);
            $request->setUserResolver(function () use ($user) {
                return $user;
            });

        } catch (TokenExpiredException $e) {
            \Log::warning('JWT Auth - Token expirado', [
                'exception' => $e->getMessage(),
                'url' => $request->url()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Token expirado. Faça login novamente.',
                'error_type' => 'token_expired',
                'requires_login' => true
            ], 401);

        } catch (TokenInvalidException $e) {
            \Log::warning('JWT Auth - Token inválido', [
                'exception' => $e->getMessage(),
                'url' => $request->url()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Token inválido. Faça login novamente.',
                'error_type' => 'token_invalid',
                'requires_login' => true
            ], 401);

        } catch (JWTException $e) {
            \Log::error('JWT Auth - Erro JWT', [
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
                'url' => $request->url(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro de autenticação.',
                'error_type' => 'jwt_error',
                'requires_login' => true
            ], 401);

        } catch (\Exception $e) {
            \Log::error('JWT Auth - Erro inesperado', [
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
                'url' => $request->url(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno de autenticação.',
                'error_type' => 'auth_error',
                'requires_login' => true
            ], 500);
        }

        return $next($request);
    }
}