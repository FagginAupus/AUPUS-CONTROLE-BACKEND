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
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthController extends Controller
{
    /**
     * Login do usuário - VERSÃO CORRIGIDA FINAL
     */
    public function login(Request $request): JsonResponse
    {
        // Log da tentativa de login
        Log::info('🔐 Tentativa de login iniciada', [
            'email' => $request->email,
            'ip' => $request->ip(),
            'timestamp' => now()
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
            Log::warning('❌ Dados inválidos no login', [
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
            Log::warning('⚠️ Rate limit excedido', [
                'ip' => $request->ip(),
                'attempts' => $attempts
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Muitas tentativas de login. Tente novamente em alguns minutos.'
            ], 429);
        }

        try {
            // Buscar usuário pelo email
            $usuario = Usuario::where('email', $request->email)->first();

            if (!$usuario) {
                // Incrementar tentativas
                Cache::put($rateLimitKey, $attempts + 1, now()->addMinutes(5));
                
                Log::warning('❌ Usuário não encontrado', [
                    'email' => $request->email,
                    'ip' => $request->ip()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Credenciais inválidas'
                ], 401);
            }

            // Verificar se usuário está ativo
            if (!$usuario->is_active) {
                Log::warning('❌ Usuário inativo tentou fazer login', [
                    'user_id' => $usuario->id,
                    'email' => $request->email,
                    'ip' => $request->ip()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Usuário inativo. Entre em contato com o administrador.'
                ], 401);
            }

            // CORREÇÃO: Usar o método checkPassword em vez de Hash::check direto
            if (!$usuario->checkPassword($request->password)) {
                // Incrementar tentativas
                Cache::put($rateLimitKey, $attempts + 1, now()->addMinutes(5));
                
                Log::warning('❌ Senha incorreta', [
                    'user_id' => $usuario->id,
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

            // Gerar token JWT
            try {
                $token = JWTAuth::fromUser($usuario);
                
                if (!$token) {
                    throw new \Exception('Falha na geração do token');
                }

                // Log do sucesso
                Log::info('✅ Login realizado com sucesso', [
                    'user_id' => $usuario->id,
                    'user_name' => $usuario->nome,
                    'user_role' => $usuario->role,
                    'ip' => $request->ip(),
                    'token_generated' => true
                ]);

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
                        'permissions' => $usuario->getPermissoes()
                    ],
                    'token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => 86400
                ], 200);

            } catch (JWTException $e) {
                Log::error('❌ Erro ao gerar token JWT', [
                    'user_id' => $usuario->id,
                    'error' => $e->getMessage(),
                    'ip' => $request->ip()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Erro na geração do token de acesso'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('❌ Erro crítico durante login', [
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
            $token = JWTAuth::getToken();
            
            if ($token) {
                $usuario = JWTAuth::user();
                
                if ($usuario) {
                    Log::info('🚪 Logout realizado', [
                        'user_id' => $usuario->id,
                        'user_name' => $usuario->nome
                    ]);
                }
                
                JWTAuth::invalidate($token);
            }

            return response()->json([
                'success' => true,
                'message' => 'Logout realizado com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::warning('⚠️ Erro no logout', ['error' => $e->getMessage()]);
            
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

            if (!$usuario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado'
                ], 401);
            }

            Log::info('🔄 Token renovado', ['user_id' => $usuario->id]);

            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $usuario->id,
                    'name' => $usuario->nome,
                    'email' => $usuario->email,
                    'role' => $usuario->role ?? 'user',
                    'permissions' => $usuario->getPermissoes()
                ],
                'token' => $newToken,
                'token_type' => 'bearer',
                'expires_in' => 86400
            ]);

        } catch (\Exception $e) {
            Log::warning('❌ Erro ao renovar token', ['error' => $e->getMessage()]);

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
                    'permissions' => $usuario->getPermissoes()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao obter dados do usuário', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao obter dados do usuário'
            ], 500);
        }
    }

    /**
     * Registrar novo usuário (apenas para admins)
     */
    public function register(Request $request): JsonResponse
    {
        // Verificar se é admin (em ambiente de produção)
        if (config('app.env') === 'production') {
            $currentUser = JWTAuth::parseToken()->authenticate();
            
            if (!$currentUser || !$currentUser->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Apenas administradores podem registrar usuários'
                ], 403);
            }
        }

        $validator = Validator::make($request->all(), [
            'nome' => 'required|string|min:3|max:255',
            'email' => 'required|email|unique:usuarios,email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:admin,gestor,consultor'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // CORREÇÃO: Usar senha sem hash manual, o modelo vai hashear
            $usuario = Usuario::create([
                'nome' => $request->nome,
                'email' => $request->email,
                'senha' => $request->password, // Sem Hash::make aqui
                'role' => $request->role,
                'is_active' => true
            ]);

            Log::info('👤 Novo usuário criado', [
                'user_id' => $usuario->id,
                'email' => $usuario->email,
                'role' => $usuario->role
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Usuário criado com sucesso',
                'user' => [
                    'id' => $usuario->id,
                    'name' => $usuario->nome,
                    'email' => $usuario->email,
                    'role' => $usuario->role
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao criar usuário', [
                'error' => $e->getMessage(),
                'email' => $request->email
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar usuário'
            ], 500);
        }
    }

    /**
     * Alterar senha
     */
    public function changePassword(Request $request): JsonResponse
    {
        $usuario = JWTAuth::user();

        if (!$usuario) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verificar senha atual usando o método correto
        if (!$usuario->checkPassword($request->current_password)) {
            return response()->json([
                'success' => false,
                'message' => 'Senha atual incorreta'
            ], 400);
        }

        try {
            // CORREÇÃO: Usar senha direta, o modelo vai hashear
            $usuario->update([
                'senha' => $request->new_password // Sem Hash::make aqui
            ]);

            Log::info('🔐 Senha alterada', [
                'user_id' => $usuario->id,
                'email' => $usuario->email
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Senha alterada com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao alterar senha', [
                'user_id' => $usuario->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao alterar senha'
            ], 500);
        }
    }
}