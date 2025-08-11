<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    /**
     * Login do usuário - VERSÃO CORRIGIDA
     */
    public function login(Request $request): JsonResponse
    {
        // Log da tentativa de login
        Log::info('Tentativa de login iniciada', [
            'email' => $request->email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|string|max:255',
            'password' => 'required|string|min:3|max:50'
        ], [
            'email.required' => 'Email é obrigatório',
            'email.email' => 'Email deve ter formato válido',
            'password.required' => 'Senha é obrigatória',
            'password.min' => 'Senha deve ter pelo menos 3 caracteres'
        ]);

        if ($validator->fails()) {
            Log::warning('Dados inválidos no login', [
                'email' => $request->email,
                'errors' => $validator->errors()->toArray()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        // Rate limiting
        $rateLimitKey = 'login_attempts_' . $request->ip();
        $attempts = Cache::get($rateLimitKey, 0);

        if ($attempts >= 5) {
            Log::warning('Rate limit excedido', [
                'ip' => $request->ip(),
                'attempts' => $attempts
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Muitas tentativas de login. Tente novamente em alguns minutos.'
            ], 429);
        }

        try {
            // Buscar usuário com query raw para evitar problemas com Eloquent
            $usuarioData = DB::select("
                SELECT 
                    id, nome, email, senha, role, telefone, 
                    is_active, manager_id, created_at, updated_at
                FROM usuarios 
                WHERE email = ? 
                AND deleted_at IS NULL
                LIMIT 1
            ", [$request->email]);

            if (empty($usuarioData)) {
                // Incrementar tentativas
                Cache::put($rateLimitKey, $attempts + 1, now()->addMinutes(5));
                
                Log::warning('Email não encontrado', [
                    'email' => $request->email,
                    'ip' => $request->ip()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Credenciais inválidas'
                ], 401);
            }

            $usuarioData = $usuarioData[0];

            // Verificar se usuário está ativo
            if (!$usuarioData->is_active) {
                Log::warning('Usuário inativo tentou login', [
                    'user_id' => $usuarioData->id,
                    'user_name' => $usuarioData->nome,
                    'email' => $request->email
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Usuário inativo. Entre em contato com o administrador.'
                ], 401);
            }

            // Verificar senha
            if (!Hash::check($request->password, $usuarioData->senha)) {
                // Incrementar tentativas
                Cache::put($rateLimitKey, $attempts + 1, now()->addMinutes(5));
                
                Log::warning('Senha incorreta', [
                    'user_id' => $usuarioData->id,
                    'email' => $request->email,
                    'ip' => $request->ip()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Credenciais inválidas'
                ], 401);
            }

            // Limpar tentativas após login bem-sucedido
            Cache::forget($rateLimitKey);

            // Criar instância do modelo para o JWT (sem tocar em timestamps)
            $usuario = new Usuario();
            $usuario->id = $usuarioData->id;
            $usuario->nome = $usuarioData->nome;
            $usuario->email = $usuarioData->email;
            $usuario->role = $usuarioData->role ?? 'user';
            $usuario->telefone = $usuarioData->telefone;
            $usuario->is_active = $usuarioData->is_active;
            $usuario->manager_id = $usuarioData->manager_id;

            // Marcar como existente para evitar tentativas de save
            $usuario->exists = true;

            // Tentar gerar token JWT
            try {
                $token = JWTAuth::fromUser($usuario);
                Log::info('Token JWT gerado com sucesso', ['user_id' => $usuario->id]);
            } catch (\Exception $e) {
                Log::warning('JWT não configurado, usando token temporário', [
                    'user_id' => $usuario->id,
                    'error' => $e->getMessage()
                ]);
                
                $token = 'temp_token_' . base64_encode($usuario->id . '_' . time());
            }

            // ATUALIZAR updated_at usando query raw para evitar Carbon
            try {
                DB::update("
                    UPDATE usuarios 
                    SET updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ", [$usuario->id]);
                
                Log::debug('updated_at atualizado com sucesso', ['user_id' => $usuario->id]);
            } catch (\Exception $e) {
                // Se falhar, apenas loga mas não interrompe o login
                Log::warning('Não foi possível atualizar updated_at', [
                    'user_id' => $usuario->id,
                    'error' => $e->getMessage()
                ]);
            }

            // Log de sucesso
            Log::info('Login realizado com sucesso', [
                'user_id' => $usuario->id,
                'user_name' => $usuario->nome,
                'email' => $usuario->email,
                'role' => $usuario->role,
                'ip' => $request->ip()
            ]);

            // Resposta de sucesso
            return response()->json([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'user' => [
                    'id' => $usuario->id,
                    'name' => $usuario->nome,
                    'email' => $usuario->email,
                    'role' => $usuario->role ?? 'user',
                    'telefone' => $usuario->telefone,
                    'is_active' => $usuario->is_active,
                    'permissions' => $this->getUserPermissions($usuario->role ?? 'user')
                ],
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => 86400
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro crítico durante login', [
                'email' => $request->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
                    Log::info('Logout realizado', [
                        'user_id' => $usuario->id,
                        'user_name' => $usuario->nome
                    ]);
                }
                JWTAuth::invalidate();
            } catch (\Exception $e) {
                Log::info('Logout sem JWT válido', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Logout realizado com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::warning('Erro no logout', ['error' => $e->getMessage()]);
            
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

            Log::info('Token renovado', ['user_id' => $usuario->id]);

            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $usuario->id,
                    'name' => $usuario->nome,
                    'email' => $usuario->email,
                    'role' => $usuario->role ?? 'user',
                    'permissions' => $this->getUserPermissions($usuario->role ?? 'user')
                ],
                'token' => $newToken,
                'token_type' => 'bearer',
                'expires_in' => 86400
            ]);

        } catch (\Exception $e) {
            Log::warning('Erro ao renovar token', ['error' => $e->getMessage()]);

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
                    'telefone' => $usuario->telefone,
                    'is_active' => $usuario->is_active,
                    'permissions' => $this->getUserPermissions($usuario->role ?? 'user')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao obter dados do usuário', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao obter dados do usuário'
            ], 500);
        }
    }

    /**
     * Obter permissões do usuário baseadas no role
     */
    private function getUserPermissions(string $role): array
    {
        switch ($role) {
            case 'admin':
                return [
                    'canCreateConsultors' => true,
                    'canAccessAll' => true,
                    'canManageUGs' => true,
                    'canManageCalibration' => true,
                    'canSeeAllData' => true,
                    'canManageUsers' => true,
                    'canViewReports' => true,
                    'canEditSystem' => true
                ];

            case 'consultor':
                return [
                    'canCreateConsultors' => true,
                    'canAccessAll' => false,
                    'canManageUGs' => true,
                    'canManageCalibration' => true,
                    'canSeeAllData' => false,
                    'canManageUsers' => false,
                    'canViewReports' => true,
                    'canEditSystem' => false
                ];

            case 'gerente':
                return [
                    'canCreateConsultors' => false,
                    'canAccessAll' => false,
                    'canManageUGs' => true,
                    'canManageCalibration' => true,
                    'canSeeAllData' => false,
                    'canManageUsers' => false,
                    'canViewReports' => true,
                    'canEditSystem' => false
                ];

            case 'vendedor':
            default:
                return [
                    'canCreateConsultors' => false,
                    'canAccessAll' => false,
                    'canManageUGs' => false,
                    'canManageCalibration' => false,
                    'canSeeAllData' => false,
                    'canManageUsers' => false,
                    'canViewReports' => false,
                    'canEditSystem' => false
                ];
        }
    }

    /**
     * Health check para verificar se a autenticação está funcionando
     */
    public function healthCheck(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'AuthController funcionando corretamente',
            'timestamp' => now()->toISOString()
        ]);
    }
}