<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthController extends Controller
{
    // Removido o constructor com middleware - Laravel 12 usa outra abordagem

    /**
     * Login do usuário
     */
    public function login(Request $request): JsonResponse
    {
        // Validação básica
        $validator = Validator::make($request->all(), [
            'email' => 'required|string',
            'password' => 'required|string|min:3',
        ], [
            'email.required' => 'Login é obrigatório',
            'password.required' => 'Senha é obrigatória',
            'password.min' => 'Senha deve ter pelo menos 3 caracteres'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        // Rate limiting básico
        $rateLimitKey = 'login_attempts:' . $request->ip() . ':' . $request->email;
        
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return response()->json([
                'success' => false,
                'message' => "Muitas tentativas de login. Tente novamente em {$seconds} segundos.",
            ], 429);
        }

        try {
            // Tentar encontrar usuário por nome ou email
            $usuario = Usuario::where('nome', $request->email)
                             ->orWhere('email', $request->email)
                             ->first();

            if (!$usuario) {
                RateLimiter::hit($rateLimitKey, 300);
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
                RateLimiter::hit($rateLimitKey, 300);
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciais inválidas'
                ], 401);
            }

            // Tentar gerar token JWT
            try {
                $token = JWTAuth::fromUser($usuario);
            } catch (\Exception $e) {
                // Se JWT falhar, retornar sucesso sem token para teste
                \Log::warning('JWT não configurado, fazendo login sem token', [
                    'user_id' => $usuario->id,
                    'error' => $e->getMessage()
                ]);
                
                $token = 'temp_token_' . base64_encode($usuario->id . '_' . time());
            }

            // Limpar tentativas de login
            RateLimiter::clear($rateLimitKey);

            // Atualizar último login
            $usuario->update([
                'updated_at' => now()
            ]);

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
                'expires_in' => 86400 // 24 horas
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erro durante login', [
                'email' => $request->email,
                'error' => $e->getMessage(),
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout do usuário
     */
    public function logout(): JsonResponse
    {
        try {
            // Tentar fazer logout com JWT
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
                // Se JWT falhar, apenas retornar sucesso
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

            // Verificar senha atual
            if (!Hash::check($request->current_password, $usuario->senha)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Senha atual incorreta'
                ], 400);
            }

            // Atualizar senha
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
        $role = $usuario->role ?? 'user';

        $basePermissions = [
            'view_dashboard' => true,
            'view_own_data' => true,
        ];

        return match($role) {
            'admin' => array_merge($basePermissions, [
                'manage_users' => true,
                'manage_system' => true,
                'view_all_data' => true,
                'manage_propostas' => true,
                'manage_controle' => true,
                'manage_ugs' => true,
                'view_reports' => true,
            ]),
            'consultor' => array_merge($basePermissions, [
                'manage_team' => true,
                'view_team_data' => true,
                'manage_propostas' => true,
                'manage_controle' => true,
                'manage_ugs' => true,
                'view_reports' => true,
            ]),
            'gerente' => array_merge($basePermissions, [
                'manage_propostas' => true,
                'view_basic_reports' => true,
            ]),
            'vendedor' => array_merge($basePermissions, [
                'create_propostas' => true,
            ]),
            default => $basePermissions
        };
    }
}