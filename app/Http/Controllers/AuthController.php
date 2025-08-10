<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    /**
     * Login do usuário
     */
    public function login(Request $request): JsonResponse
    {
        // Validação básica
        $validator = Validator::make($request->all(), [
            'email' => 'required|string',
            'password' => 'required|string|min:3',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Rate limiting manual (sem usar RateLimiter que está causando problema)
            $rateLimitKey = 'login_attempts:' . $request->ip() . ':' . $request->email;
            $attempts = Cache::get($rateLimitKey, 0);
            
            if ($attempts >= 5) {
                return response()->json([
                    'success' => false,
                    'message' => 'Muitas tentativas de login. Tente novamente em alguns minutos.'
                ], 429);
            }

            // Buscar usuário por email
            $usuario = Usuario::where('email', $request->email)->first();

            if (!$usuario) {
                // Incrementar tentativas
                Cache::put($rateLimitKey, $attempts + 1, now()->addMinutes(5));
                
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciais inválidas'
                ], 401);
            }

            // Verificar se usuário está ativo
            if (isset($usuario->is_active) && !$usuario->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário inativo. Entre em contato com o administrador.'
                ], 401);
            }

            // Verificar senha
            if (!Hash::check($request->password, $usuario->senha)) {
                // Incrementar tentativas
                Cache::put($rateLimitKey, $attempts + 1, now()->addMinutes(5));
                
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciais inválidas'
                ], 401);
            }

            // Limpar tentativas após login bem-sucedido
            Cache::forget($rateLimitKey);

            // Tentar gerar token JWT
            try {
                $token = JWTAuth::fromUser($usuario);
            } catch (\Exception $e) {
                \Log::warning('JWT não configurado, usando token temporário', [
                    'user_id' => $usuario->id,
                    'error' => $e->getMessage()
                ]);
                
                $token = 'temp_token_' . base64_encode($usuario->id . '_' . time());
            }

            // Atualizar último login
            $usuario->updated_at = now();
            $usuario->save();

            // Log da atividade
            \Log::info('Login realizado com sucesso', [
                'user_id' => $usuario->id,
                'user_name' => $usuario->nome,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'user' => [
                    'id' => $usuario->id,
                    'name' => $usuario->nome,
                    'email' => $usuario->email,
                    'role' => $usuario->role ?? 'user',
                    'telefone' => $usuario->telefone ?? null,
                    'is_active' => $usuario->is_active ?? true,
                    'permissions' => $this->getUserPermissions($usuario)
                ],
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => 86400
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erro durante login', [
                'email' => $request->email,
                'error' => $e->getMessage(),
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Logout do usuário
     */
    public function logout(): JsonResponse
    {
        try {
            try {
                $usuario = JWTAuth::user();
                if ($usuario) {
                    \Log::info('Logout realizado', [
                        'user_id' => $usuario->id,
                        'user_name' => $usuario->nome
                    ]);
                }
                JWTAuth::invalidate();
            } catch (\Exception $e) {
                \Log::info('Logout sem JWT');
            }

            return response()->json([
                'success' => true,
                'message' => 'Logout realizado com sucesso'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => true,
                'message' => 'Logout realizado'
            ]);
        }
    }

    /**
     * Renovar token
     */
    public function refresh(): JsonResponse
    {
        try {
            $newToken = JWTAuth::refresh();
            $usuario = JWTAuth::user();

            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $usuario->id,
                    'name' => $usuario->nome,
                    'email' => $usuario->email,
                    'role' => $usuario->role ?? 'user',
                    'permissions' => $this->getUserPermissions($usuario)
                ],
                'token' => $newToken,
                'token_type' => 'bearer',
                'expires_in' => 86400
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao renovar token. Faça login novamente.'
            ], 401);
        }
    }

    /**
     * Obter dados do usuário autenticado
     */
    public function me(): JsonResponse
    {
        try {
            $usuario = JWTAuth::user();

            if (!$usuario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $usuario->id,
                    'name' => $usuario->nome,
                    'email' => $usuario->email,
                    'role' => $usuario->role ?? 'user',
                    'telefone' => $usuario->telefone ?? null,
                    'instagram' => $usuario->instagram ?? null,
                    'cidade' => $usuario->cidade ?? null,
                    'estado' => $usuario->estado ?? null,
                    'is_active' => $usuario->is_active ?? true,
                    'permissions' => $this->getUserPermissions($usuario),
                    'created_at' => $usuario->created_at
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token inválido'
            ], 401);
        }
    }

    /**
     * Alterar senha
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $usuario = JWTAuth::user();

            if (!$usuario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            if (!Hash::check($request->current_password, $usuario->senha)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Senha atual incorreta'
                ], 400);
            }

            $usuario->update([
                'senha' => Hash::make($request->new_password)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Senha alterada com sucesso'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Registro de novo usuário
     */
    public function register(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Registro direto não permitido. Entre em contato com o administrador.'
        ], 403);
    }

    /**
     * Obter permissões do usuário baseado no role
     */
    private function getUserPermissions($usuario): array
    {
        $role = $usuario->role ?? 'vendedor';
        
        $permissions = [
            'admin' => [
                'canCreateConsultors' => true,
                'canAccessAll' => true,
                'canManageUGs' => true,
                'canManageCalibration' => true,
                'canSeeAllData' => true,
                'canManageUsers' => true,
                'canAccessReports' => true,
                'canEditConfigurations' => true
            ],
            'consultor' => [
                'canCreateConsultors' => false,
                'canAccessAll' => true,
                'canManageUGs' => true,
                'canManageCalibration' => false,
                'canSeeAllData' => true,
                'canManageUsers' => true,
                'canAccessReports' => true,
                'canEditConfigurations' => false
            ],
            'gerente' => [
                'canCreateConsultors' => false,
                'canAccessAll' => false,
                'canManageUGs' => false,
                'canManageCalibration' => false,
                'canSeeAllData' => false,
                'canManageUsers' => false,
                'canAccessReports' => true,
                'canEditConfigurations' => false
            ],
            'vendedor' => [
                'canCreateConsultors' => false,
                'canAccessAll' => false,
                'canManageUGs' => false,
                'canManageCalibration' => false,
                'canSeeAllData' => false,
                'canManageUsers' => false,
                'canAccessReports' => false,
                'canEditConfigurations' => false
            ]
        ];

        return $permissions[$role] ?? $permissions['vendedor'];
    }
}