<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token inválido ou usuário não encontrado'
                ], 401);
            }

            // Verificar se usuário está ativo
            if (!$user->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário inativo'
                ], 403);
            }

            // ✅ NOVA LÓGICA SIMPLIFICADA - USAR SPATIE
            
            // Admin sempre tem acesso (compatibilidade)
            if ($user->isAdmin()) {
                return $next($request);
            }

            // Verificar permissões específicas usando Spatie
            if (!empty($permissions)) {
                $hasPermission = false;
                
                foreach ($permissions as $permission) {
                    // ✅ USAR MÉTODO DO SPATIE
                    if ($user->can($permission)) {
                        $hasPermission = true;
                        break;
                    }
                }
                
                if (!$hasPermission) {
                    \Log::warning('Acesso negado por falta de permissão', [
                        'user_id' => $user->id,
                        'user_role' => $user->role,
                        'permissions_required' => $permissions,
                        'user_permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                        'route' => $request->route() ? $request->route()->getName() : 'unknown',
                        'ip' => $request->ip()
                    ]);
                    
                    return response()->json([
                        'success' => false,
                        'message' => 'Acesso negado. Permissão insuficiente.'
                    ], 403);
                }
            }

            return $next($request);
            
        } catch (\Exception $e) {
            \Log::error('Erro no middleware de permissão', [
                'error' => $e->getMessage(),
                'route' => $request->route() ? $request->route()->getName() : 'unknown',
                'ip' => $request->ip()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro de autenticação'
            ], 401);
        }
    }

}