<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Notificacao;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthController extends Controller
{
    /**
     * Create a new AuthController instance.
     */
    public function __construct()
    {
        $this->middleware('auth:api', ['except' => ['login', 'register']]);
    }

    /**
     * Login do usuário
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:3',
        ], [
            'email.required' => 'Email é obrigatório',
            'email.email' => 'Email deve ter formato válido',
            'password.required' => 'Senha é obrigatória',
            'password.min' => 'Senha deve ter pelo menos 3 caracteres'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados de validação inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Buscar usuário pelo email
            $usuario = Usuario::where('email', $request->email)->first();
            
            if (!$usuario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciais inválidas'
                ], 401);
            }

            // Verificar se usuário está ativo
            if (!$usuario->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário inativo. Entre em contato com o administrador.'
                ], 403);
            }

            // Verificar senha
            if (!Hash::check($request->password, $usuario->senha)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciais inválidas'
                ], 401);
            }

            // Gerar token JWT
            $token = JWTAuth::fromUser($usuario);

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erro interno do servidor'
                ], 500);
            }

            // Dados do usuário para retorno
            $userData = [
                'id' => $usuario->id,
                'nome' => $usuario->nome,
                'email' => $usuario->email,
                'role' => $usuario->role,
                'is_active' => $usuario->is_active,
                'telefone' => $usuario->telefone,
                'created_at' => $usuario->created_at,
                'permissions' => $this->getUserPermissions($usuario)
            ];

            return response()->json([
                'success' => true,
                'message' => "Bem-vindo(a), {$usuario->nome}!",
                'data' => [
                    'user' => $userData,
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'expires_in' => config('jwt.ttl') * 60 // em segundos
                ]
            ]);

        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar token de acesso'
            ], 500);
        }
    }

    /**
     * Logout do usuário
     */
    public function logout(): JsonResponse
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            
            return response()->json([
                'success' => true,
                'message' => 'Logout realizado com sucesso'
            ]);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao realizar logout'
            ], 500);
        }
    }

    /**
     * Renovar token JWT
     */
    public function refresh(): JsonResponse
    {
        try {
            $token = JWTAuth::refresh();
            $usuario = JWTAuth::user();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'expires_in' => config('jwt.ttl') * 60,
                    'user' => [
                        'id' => $usuario->id,
                        'nome' => $usuario->nome,
                        'email' => $usuario->email,
                        'role' => $usuario->role
                    ]
                ]
            ]);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token não pode ser renovado'
            ], 401);
        }
    }

    /**
     * Obter dados do usuário atual
     */
    public function me(): JsonResponse
    {
        try {
            $usuario = JWTAuth::user();
            
            if (!$usuario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado'
                ], 404);
            }

            // Verificar se ainda está ativo
            if (!$usuario->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário foi inativado'
                ], 403);
            }

            // Carregar relacionamentos
            $usuario->load(['manager', 'subordinados']);

            // Obter estatísticas do dashboard
            $estatisticas = $this->getDashboardStats($usuario);

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $usuario->id,
                        'nome' => $usuario->nome,
                        'email' => $usuario->email,
                        'role' => $usuario->role,
                        'is_active' => $usuario->is_active,
                        'telefone' => $usuario->telefone,
                        'cidade' => $usuario->cidade,
                        'estado' => $usuario->estado,
                        'created_at' => $usuario->created_at,
                        'manager' => $usuario->manager ? [
                            'id' => $usuario->manager->id,
                            'nome' => $usuario->manager->nome,
                            'email' => $usuario->manager->email
                        ] : null,
                        'subordinados' => $usuario->subordinados->map(function ($sub) {
                            return [
                                'id' => $sub->id,
                                'nome' => $sub->nome,
                                'email' => $sub->email,
                                'role' => $sub->role
                            ];
                        })
                    ],
                    'permissions' => $this->getUserPermissions($usuario),
                    'statistics' => $estatisticas,
                    'notifications_count' => Notificacao::porUsuario($usuario->id)->naoLidas()->count()
                ]
            ]);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token inválido'
            ], 401);
        }
    }

    /**
     * Registrar novo usuário (apenas admins e consultores)
     */
    public function register(Request $request): JsonResponse
    {
        $currentUser = JWTAuth::user();

        // Verificar se usuário atual pode criar usuários
        if (!$currentUser || (!$currentUser->isAdmin() && !$currentUser->isConsultor())) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Apenas administradores e consultores podem criar usuários.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome' => 'required|string|min:3|max:200',
            'email' => 'required|email|unique:usuarios,email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:admin,consultor,gerente,vendedor',
            'telefone' => 'nullable|string|max:20',
            'cidade' => 'nullable|string|max:100',
            'estado' => 'nullable|string|max:2',
            'manager_id' => 'nullable|exists:usuarios,id'
        ], [
            'nome.required' => 'Nome é obrigatório',
            'email.required' => 'Email é obrigatório',
            'email.email' => 'Email deve ter formato válido',
            'email.unique' => 'Este email já está em uso',
            'password.required' => 'Senha é obrigatória',
            'password.min' => 'Senha deve ter pelo menos 6 caracteres',
            'role.required' => 'Role é obrigatório',
            'role.in' => 'Role deve ser: admin, consultor, gerente ou vendedor'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados de validação inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verificar hierarquia - Consultor não pode criar Admin ou Consultor
        if ($currentUser->isConsultor() && in_array($request->role, ['admin', 'consultor'])) {
            return response()->json([
                'success' => false,
                'message' => 'Consultores não podem criar administradores ou outros consultores'
            ], 403);
        }

        try {
            $novoUsuario = Usuario::create([
                'nome' => $request->nome,
                'email' => $request->email,
                'senha' => $request->password, // Será hasheado pelo mutator
                'role' => $request->role,
                'telefone' => $request->telefone,
                'cidade' => $request->cidade,
                'estado' => $request->estado,
                'manager_id' => $request->manager_id ?? $currentUser->id,
                'is_active' => true
            ]);

            // Criar notificação de boas-vindas
            Notificacao::criarUsuarioCriado($novoUsuario, $currentUser);

            return response()->json([
                'success' => true,
                'message' => 'Usuário criado com sucesso!',
                'data' => [
                    'user' => [
                        'id' => $novoUsuario->id,
                        'nome' => $novoUsuario->nome,
                        'email' => $novoUsuario->email,
                        'role' => $novoUsuario->role,
                        'is_active' => $novoUsuario->is_active,
                        'created_at' => $novoUsuario->created_at
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar usuário: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Alterar senha do usuário atual
     */
    public function changePassword(Request $request): JsonResponse
    {
        $usuario = JWTAuth::user();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ], [
            'current_password.required' => 'Senha atual é obrigatória',
            'new_password.required' => 'Nova senha é obrigatória',
            'new_password.min' => 'Nova senha deve ter pelo menos 6 caracteres',
            'new_password.confirmed' => 'Confirmação de senha não confere'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados de validação inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verificar senha atual
        if (!Hash::check($request->current_password, $usuario->senha)) {
            return response()->json([
                'success' => false,
                'message' => 'Senha atual incorreta'
            ], 400);
        }

        // Alterar senha
        $usuario->senha = $request->new_password;
        $usuario->save();

        return response()->json([
            'success' => true,
            'message' => 'Senha alterada com sucesso!'
        ]);
    }

    /**
     * Obter permissões do usuário baseadas na role
     */
    private function getUserPermissions(Usuario $usuario): array
    {
        // Baseado na hierarquia do frontend
        $basePermissions = [
            'canAccessDashboard' => true,
            'canAccessProspec' => true,
            'canAccessControle' => true,
            'canAccessRelatorios' => true
        ];

        switch ($usuario->role) {
            case 'admin':
                return array_merge($basePermissions, [
                    'canCreateUsers' => true,
                    'canManageUGs' => true,
                    'canManageCalibration' => true,
                    'canSeeAllData' => true,
                    'canAccessSettings' => true,
                    'canManageHierarchy' => true,
                    'canDeletePropostas' => true,
                    'canEditAllPropostas' => true
                ]);

            case 'consultor':
                return array_merge($basePermissions, [
                    'canCreateUsers' => true,
                    'canManageUGs' => true,
                    'canManageCalibration' => true,
                    'canSeeTeamData' => true,
                    'canEditTeamPropostas' => true
                ]);

            case 'gerente':
                return array_merge($basePermissions, [
                    'canCreateUsers' => true,
                    'canSeeTeamData' => true,
                    'canEditTeamPropostas' => true
                ]);

            case 'vendedor':
            default:
                return array_merge($basePermissions, [
                    'canEditOwnPropostas' => true
                ]);
        }
    }

    /**
     * Obter estatísticas do dashboard para o usuário
     */
    private function getDashboardStats(Usuario $usuario): array
    {
        // Implementar baseado nos models criados
        return [
            'propostas' => [
                'total' => 0,
                'aguardando' => 0,
                'fechado' => 0,
                'perdido' => 0
            ],
            'controle' => [
                'total' => 0,
                'ativos' => 0,
                'com_ug' => 0
            ],
            'ugs' => [
                'total' => 0,
                'capacidade_total' => 0
            ]
        ];
    }
}