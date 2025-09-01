<?php
// app/Http/Middleware/JwtAutoRefresh.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class JwtAutoRefresh
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Verificar se o token existe
            $token = JWTAuth::getToken();
            
            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token não fornecido',
                    'requires_login' => true
                ], 401);
            }

            // Tentar autenticar com o token atual
            $user = JWTAuth::authenticate($token);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado',
                    'requires_login' => true
                ], 401);
            }

            // Verificar se o token está próximo do vencimento (30 minutos antes)
            $payload = JWTAuth::getPayload($token);
            $exp = $payload->get('exp');
            $now = time();
            $timeLeft = $exp - $now;
            
            // Se restam menos de 30 minutos (1800 segundos), tentar refresh
            if ($timeLeft < 1800) {
                try {
                    $newToken = JWTAuth::refresh($token);
                    
                    Log::info('🔄 Token auto-renovado', [
                        'user_id' => $user->id,
                        'time_left_old' => $timeLeft,
                        'new_token_ttl' => config('jwt.ttl') * 60
                    ]);

                    // Adicionar o novo token no header da resposta
                    $response = $next($request);
                    $response->headers->set('Authorization', 'Bearer ' . $newToken);
                    $response->headers->set('X-New-Token', $newToken);
                    $response->headers->set('X-Token-Refreshed', 'true');
                    
                    return $response;
                    
                } catch (JWTException $e) {
                    Log::warning('❌ Falha no auto-refresh do token', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage()
                    ]);
                    
                    // Se falhou o refresh, continuar com o token atual
                    // mas avisar que expira em breve
                    $response = $next($request);
                    $response->headers->set('X-Token-Expires-In', $timeLeft);
                    $response->headers->set('X-Token-Warning', 'true');
                    
                    return $response;
                }
            }

            // Token OK, continuar normalmente
            return $next($request);

        } catch (TokenExpiredException $e) {
            // Token expirado - tentar refresh uma última vez
            try {
                $newToken = JWTAuth::refresh();
                $user = JWTAuth::user();
                
                Log::info('🔄 Token expirado renovado no último momento', [
                    'user_id' => $user->id
                ]);

                $response = $next($request);
                $response->headers->set('Authorization', 'Bearer ' . $newToken);
                $response->headers->set('X-New-Token', $newToken);
                $response->headers->set('X-Token-Refreshed', 'true');
                
                return $response;
                
            } catch (JWTException $refreshException) {
                Log::warning('❌ Token expirado e refresh falhou', [
                    'error' => $refreshException->getMessage()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Sessão expirada. Faça login novamente.',
                    'requires_login' => true,
                    'error_type' => 'token_expired'
                ], 401);
            }

        } catch (TokenInvalidException $e) {
            Log::warning('❌ Token inválido', [
                'error' => $e->getMessage(),
                'ip' => $request->ip()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Token inválido. Faça login novamente.',
                'requires_login' => true,
                'error_type' => 'token_invalid'
            ], 401);

        } catch (JWTException $e) {
            Log::error('❌ Erro JWT genérico', [
                'error' => $e->getMessage(),
                'ip' => $request->ip()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro de autenticação. Faça login novamente.',
                'requires_login' => true,
                'error_type' => 'jwt_error'
            ], 401);
        }
    }
}