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

            // Admin e Analista sempre têm acesso
            if ($user->isAdminOrAnalista()) {
                return $next($request);
            }

            // Verificar permissões específicas
            if (!empty($permissions)) {
                $hasPermission = false;
                
                foreach ($permissions as $permission) {
                    if ($this->userHasPermission($user, $permission)) {
                        $hasPermission = true;
                        break;
                    }
                }
                
                if (!$hasPermission) {
                    \Log::warning('Acesso negado por falta de permissão', [
                        'user_id' => $user->id,
                        'permissions_required' => $permissions,
                        'user_role' => $user->role,
                        'route' => $request->route()->getName(),
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

    /**
     * Verificar se usuário tem permissão específica
     */
    private function userHasPermission($user, string $permission): bool
    {
        // Mapeamento de permissões por role baseado no sistema atual
        $rolePermissions = [
            'admin' => [
                // Admin tem todas as permissões
                'dashboard.view', 'usuarios.view', 'usuarios.create', 'usuarios.edit', 'usuarios.delete',
                'propostas.view', 'propostas.create', 'propostas.edit', 'propostas.delete', 'propostas.change_status',
                'unidades.view', 'unidades.create', 'unidades.edit', 'unidades.delete', 'unidades.convert_ug',
                'prospec.view', 'prospec.create', 'prospec.edit', 'prospec.delete',
                'controle.view', 'controle.create', 'controle.edit', 'controle.calibragem', 'controle.manage_ug',
                'configuracoes.view', 'configuracoes.edit', 'relatorios.view', 'relatorios.export',
                'notificacoes.view'
            ],
            
            'analista' => [
                // Analista tem acesso similar ao admin, exceto gestão de usuários crítica
                'dashboard.view', 'usuarios.view', 'usuarios.create', 'usuarios.edit',
                'propostas.view', 'propostas.create', 'propostas.edit', 'propostas.delete', 'propostas.change_status',
                'unidades.view', 'unidades.create', 'unidades.edit', 'unidades.delete', 'unidades.convert_ug',
                'prospec.view', 'prospec.create', 'prospec.edit', 'prospec.delete',
                'controle.view', 'controle.create', 'controle.edit', 'controle.calibragem', 'controle.manage_ug',
                'configuracoes.view', 'relatorios.view', 'relatorios.export',
                'notificacoes.view'
            ],

            'consultor' => [
                'dashboard.view', 'usuarios.view', 'usuarios.create', 'usuarios.edit',
                'propostas.view', 'propostas.create', 'propostas.edit', 'propostas.change_status',
                'unidades.view', 'unidades.create', 'unidades.edit', 'unidades.convert_ug',
                'controle.view', 'controle.create', 'controle.edit', 'controle.calibragem', 'controle.manage_ug',
                'prospec.view', 'prospec.create', 'prospec.edit',
                'configuracoes.view',
                'notificacoes.view'
            ],

            'gerente' => [
                'dashboard.view', 'usuarios.view', 'usuarios.create',
                'propostas.view', 'propostas.create', 'propostas.edit',
                'unidades.view', 'unidades.create', 'unidades.edit',
                'prospec.view', 'prospec.create', 'prospec.edit',
                'controle.view',
                'notificacoes.view'
            ],

            'vendedor' => [
                'dashboard.view', 'usuarios.view',
                'propostas.view', 'propostas.create', 'propostas.edit',
                'unidades.view', 'unidades.create', 'unidades.edit',
                'prospec.view', 'prospec.create', 'prospec.edit',
                'controle.view',
                'notificacoes.view'
            ]
        ];

        $userPermissions = $rolePermissions[$user->role] ?? [];
        
        return in_array($permission, $userPermissions);
    }
}