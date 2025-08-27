<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Notificacao;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class UsuarioController extends Controller implements HasMiddleware
{
    /**
     * Get the middleware that should be assigned to the controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('auth:api'),
        ];
    }

    /**
     * Listar usuários com filtros hierárquicos
     */
    public function index(Request $request): JsonResponse
    {
        $currentUser = JWTAuth::user();

        try {
            $query = Usuario::query()->with(['manager', 'subordinados']);

            // Aplicar filtros hierárquicos
            if (!$currentUser->isAdmin()) {
                if ($currentUser->isConsultor()) {
                    // Consultor vê seus subordinados + ele mesmo
                    $subordinadosIds = array_column($currentUser->getAllSubordinates(), 'id');
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinadosIds);
                    $query->whereIn('id', $usuariosPermitidos);
                } else {
                    // Gerente e Vendedor veem apenas a si mesmos
                    $query->where('id', $currentUser->id);
                }
            }

            // Filtros opcionais
            if ($request->filled('role')) {
                $query->where('role', $request->role);
            }

            if ($request->filled('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('nome', 'ILIKE', "%{$search}%")
                      ->orWhere('email', 'ILIKE', "%{$search}%")
                      ->orWhere('telefone', 'ILIKE', "%{$search}%");
                });
            }

            // Ordenação
            $orderBy = $request->get('order_by', 'created_at');
            $orderDirection = $request->get('order_direction', 'desc');
            $query->orderBy($orderBy, $orderDirection);

            // Paginação
            $perPage = min($request->get('per_page', 15), 100);
            $usuarios = $query->paginate($perPage);

            // Transformar dados para o frontend
            $usuarios->getCollection()->transform(function ($usuario) {
                return [
                    'id' => $usuario->id,
                    'nome' => $usuario->nome,
                    'email' => $usuario->email,
                    'role' => $usuario->role,
                    'is_active' => $usuario->is_active,
                    'telefone' => $usuario->telefone,
                    'cidade' => $usuario->cidade,
                    'estado' => $usuario->estado,
                    'created_at' => $usuario->created_at,
                    'updated_at' => $usuario->updated_at,
                    'manager' => $usuario->manager ? [
                        'id' => $usuario->manager->id,
                        'nome' => $usuario->manager->nome,
                        'email' => $usuario->manager->email,
                        'role' => $usuario->manager->role
                    ] : null,
                    'subordinados_count' => $usuario->subordinados->count(),
                    'status_display' => $usuario->is_active ? 'Ativo' : 'Inativo',
                    'hierarchy_level' => $usuario->getHierarchyLevel(),
                    'can_be_edited' => $this->canEditUser($usuario),
                    'can_be_deleted' => $this->canDeleteUser($usuario)
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $usuarios,
                'meta' => [
                    'total_users' => Usuario::count(),
                    'active_users' => Usuario::where('is_active', true)->count(),
                    'roles_count' => Usuario::selectRaw('role, COUNT(*) as total')
                                           ->groupBy('role')
                                           ->pluck('total', 'role')
                                           ->toArray()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar usuários: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Criar novo usuário
     */
    public function store(Request $request): JsonResponse
    {
        $currentUser = JWTAuth::user();

        // Verificar permissões
        if (!$currentUser->isAdmin() && !$currentUser->isConsultor() && !$currentUser->isGerente()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado para criar usuários'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome' => 'required|string|min:3|max:200',
            'email' => 'required|email|unique:usuarios,email',
            'password' => 'required|string|min:8', // Mudou para 8 caracteres mínimo
            'role' => 'required|in:admin,consultor,gerente,vendedor',
            'telefone' => 'required|string|max:20', // Agora obrigatório
            'cidade' => 'required|string|max:100', // Agora obrigatório
            'estado' => 'required|string|max:2', // Agora obrigatório
            'cpf_cnpj' => 'required|string|max:20', // Agora obrigatório
            'endereco' => 'required|string|max:255', // Agora obrigatório
            'cep' => 'required|string|max:10', // Agora obrigatório
            'pix' => $request->role === 'consultor' ? 'required|string|max:255' : 'nullable|string|max:255',
            'manager_id' => 'nullable|exists:usuarios,id'
        ], [
            'nome.required' => 'Nome completo é obrigatório',
            'email.required' => 'Email é obrigatório',
            'email.email' => 'Email deve ter formato válido',
            'email.unique' => 'Este email já está em uso',
            'password.required' => 'Senha é obrigatória',
            'password.min' => 'Senha deve ter pelo menos 8 caracteres',
            'telefone.required' => 'Celular é obrigatório',
            'cidade.required' => 'Cidade é obrigatória',
            'estado.required' => 'Estado é obrigatório', 
            'cpf_cnpj.required' => 'CPF é obrigatório',
            'endereco.required' => 'Endereço é obrigatório',
            'cep.required' => 'CEP é obrigatório',
            'pix.required' => 'Chave PIX é obrigatória para consultores',
            'role.required' => 'Tipo de usuário é obrigatório'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados de validação inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verificar hierarquia de criação
        if (!$this->canCreateRole($currentUser, $request->role)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para criar usuários com este role'
            ], 403);
        }

        try {
            $novoUsuario = Usuario::create([
                'nome' => $request->nome,
                'email' => $request->email,
                'senha' => $request->password,
                'role' => $request->role,
                'telefone' => $request->telefone,
                'cidade' => $request->cidade,
                'estado' => $request->estado,
                'cpf_cnpj' => $request->cpf_cnpj,
                'endereco' => $request->endereco,
                'cep' => $request->cep,
                'pix' => $request->pix, // ADICIONAR ESTA LINHA
                'manager_id' => $request->manager_id ?? null,
                'is_active' => true
            ]);

            // Criar notificação de boas-vindas se a classe existir
            try {
                if (class_exists('App\Models\Notificacao')) {
                    Notificacao::criarUsuarioCriado($novoUsuario, $currentUser);
                }
            } catch (\Exception $e) {
                // Ignorar erro de notificação
            }

            return response()->json([
                'success' => true,
                'message' => 'Usuário criado com sucesso!',
                'data' => [
                    'id' => $novoUsuario->id,
                    'nome' => $novoUsuario->nome,
                    'email' => $novoUsuario->email,
                    'role' => $novoUsuario->role,
                    'is_active' => $novoUsuario->is_active,
                    'created_at' => $novoUsuario->created_at
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
     * Exibir usuário específico
     */
    public function show(string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        try {
            $usuario = Usuario::with(['manager', 'subordinados', 'propostas', 'unidadesConsumidoras'])
                             ->findOrFail($id);

            // Verificar se pode visualizar este usuário
            if (!$this->canViewUser($currentUser, $usuario)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado para visualizar este usuário'
                ], 403);
            }

            // Estatísticas do usuário
            $estatisticas = [
                'propostas_count' => $usuario->propostas()->count(),
                'propostas_fechadas' => $usuario->propostas()->fechado()->count(),
                'ucs_count' => $usuario->unidadesConsumidoras()->UCs()->count(),
                'ugs_count' => $usuario->unidadesConsumidoras()->UGs()->count(),
                'subordinados_count' => $usuario->subordinados()->count()
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $usuario->id,
                    'nome' => $usuario->nome,
                    'email' => $usuario->email,
                    'role' => $usuario->role,
                    'is_active' => $usuario->is_active,
                    'telefone' => $usuario->telefone,
                    'instagram' => $usuario->instagram,
                    'cidade' => $usuario->cidade,
                    'estado' => $usuario->estado,
                    'cpf_cnpj' => $usuario->cpf_cnpj,
                    'endereco' => $usuario->endereco,
                    'cep' => $usuario->cep,
                    'created_at' => $usuario->created_at,
                    'updated_at' => $usuario->updated_at,
                    'manager' => $usuario->manager ? [
                        'id' => $usuario->manager->id,
                        'nome' => $usuario->manager->nome,
                        'email' => $usuario->manager->email,
                        'role' => $usuario->manager->role
                    ] : null,
                    'subordinados' => $usuario->subordinados->map(function ($sub) {
                        return [
                            'id' => $sub->id,
                            'nome' => $sub->nome,
                            'email' => $sub->email,
                            'role' => $sub->role,
                            'is_active' => $sub->is_active
                        ];
                    }),
                    'statistics' => $estatisticas,
                    'permissions' => [
                        'can_edit' => $this->canEditUser($currentUser, $usuario),
                        'can_delete' => $this->canDeleteUser($currentUser, $usuario),
                        'can_activate_deactivate' => $this->canActivateDeactivateUser($currentUser, $usuario)
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não encontrado'
            ], 404);
        }
    }

    /**
     * Atualizar usuário
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        try {
            $usuario = Usuario::findOrFail($id);

            // Verificar se pode editar este usuário
            if (!$this->canEditUser($currentUser, $usuario)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado para editar este usuário'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'nome' => 'sometimes|required|string|min:3|max:200',
                'email' => 'sometimes|required|email|unique:usuarios,email,' . $id,
                'role' => 'sometimes|required|in:admin,consultor,gerente,vendedor',
                'telefone' => 'nullable|string|max:20',
                'instagram' => 'nullable|string|max:100',
                'cidade' => 'nullable|string|max:100',
                'estado' => 'nullable|string|max:2',
                'cpf_cnpj' => 'nullable|string|max:20',
                'endereco' => 'nullable|string|max:255',
                'cep' => 'nullable|string|max:10',
                'manager_id' => 'nullable|exists:usuarios,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados de validação inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar se pode alterar role
            if ($request->filled('role') && $request->role !== $usuario->role) {
                if (!$this->canChangeRole($currentUser, $usuario, $request->role)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Você não tem permissão para alterar este role'
                    ], 403);
                }
            }

            $usuario->update($request->only([
                'nome', 'email', 'role', 'telefone', 'instagram',
                'cidade', 'estado', 'cpf_cnpj', 'endereco', 'cep', 'manager_id'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Usuário atualizado com sucesso!',
                'data' => [
                    'id' => $usuario->id,
                    'nome' => $usuario->nome,
                    'email' => $usuario->email,
                    'role' => $usuario->role,
                    'is_active' => $usuario->is_active,
                    'updated_at' => $usuario->updated_at
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar usuário: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ativar/Desativar usuário
     */
    public function toggleActive(string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        try {
            $usuario = Usuario::findOrFail($id);

            // Verificar se pode ativar/desativar este usuário
            if (!$this->canActivateDeactivateUser($currentUser, $usuario)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado para ativar/desativar este usuário'
                ], 403);
            }

            $usuario->is_active = !$usuario->is_active;
            $usuario->save();

            $status = $usuario->is_active ? 'ativado' : 'desativado';

            // Criar notificação para o usuário afetado (se não for ele mesmo)
            if ($usuario->id !== $currentUser->id) {
                $titulo = $usuario->is_active ? 'Conta reativada' : 'Conta desativada';
                $descricao = $usuario->is_active 
                    ? "Sua conta foi reativada por {$currentUser->nome}"
                    : "Sua conta foi desativada por {$currentUser->nome}";
                
                Notificacao::criarNotificacaoSistema($usuario, $titulo, $descricao, 'sistema');
            }

            return response()->json([
                'success' => true,
                'message' => "Usuário {$status} com sucesso!",
                'data' => [
                    'id' => $usuario->id,
                    'nome' => $usuario->nome,
                    'is_active' => $usuario->is_active,
                    'status_display' => $usuario->is_active ? 'Ativo' : 'Inativo'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao alterar status do usuário: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obter equipe do usuário (subordinados)
     */
    public function getTeam(): JsonResponse
    {
        $currentUser = JWTAuth::user();

        try {
            $subordinados = $currentUser->getAllSubordinates();

            $equipe = collect($subordinados)->map(function ($subordinado) {
                return [
                    'id' => $subordinado['id'],
                    'nome' => $subordinado['nome'],
                    'email' => $subordinado['email'],
                    'role' => $subordinado['role'],
                    'is_active' => $subordinado['is_active'],
                    'telefone' => $subordinado['telefone'],
                    'hierarchy_level' => $subordinado->getHierarchyLevel()
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $equipe,
                'meta' => [
                    'total_team_members' => $equipe->count(),
                    'active_members' => $equipe->where('is_active', true)->count(),
                    'roles_distribution' => $equipe->groupBy('role')->map->count()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar equipe: ' . $e->getMessage()
            ], 500);
        }
    }

    // Métodos auxiliares para verificação de permissões
    private function canViewUser(Usuario $currentUser, Usuario $targetUser): bool
    {
        if ($currentUser->isAdmin()) return true;
        if ($currentUser->id === $targetUser->id) return true;
        
        if ($currentUser->isConsultor()) {
            $subordinadosIds = array_column($currentUser->getAllSubordinates(), 'id');
            return in_array($targetUser->id, $subordinadosIds);
        }
        
        return false;
    }

    private function canEditUser(Usuario $currentUser, Usuario $targetUser = null): bool
    {
        if (!$targetUser) return false;
        if ($currentUser->isAdmin()) return true;
        if ($currentUser->id === $targetUser->id) return true;
        
        return $currentUser->canManageUser($targetUser);
    }

    private function canDeleteUser(Usuario $currentUser, Usuario $targetUser = null): bool
    {
        if (!$targetUser) return false;
        if ($currentUser->id === $targetUser->id) return false; // Não pode deletar a si mesmo
        
        return $currentUser->isAdmin() && $targetUser->role !== 'admin';
    }

    private function canActivateDeactivateUser(Usuario $currentUser, Usuario $targetUser): bool
    {
        return $this->canEditUser($currentUser, $targetUser);
    }

    private function canCreateRole(Usuario $currentUser, string $role): bool
    {
        if ($currentUser->isAdmin()) return true;
        
        if ($currentUser->isConsultor()) {
            return !in_array($role, ['admin', 'consultor']);
        }
        
        if ($currentUser->isGerente()) {
            return $role === 'vendedor';
        }
        
        return false;
    }

    private function canChangeRole(Usuario $currentUser, Usuario $targetUser, string $newRole): bool
    {
        if (!$this->canEditUser($currentUser, $targetUser)) return false;
        
        // Não pode alterar o próprio role
        if ($currentUser->id === $targetUser->id) return false;
        
        return $this->canCreateRole($currentUser, $newRole);
    }
}