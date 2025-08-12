<?php

namespace App\Http\Controllers;

use App\Models\UnidadeConsumidora;
use App\Models\Usuario;
use App\Models\Proposta;
use App\Models\Notificacao;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class UnidadeConsumidoraController extends Controller implements HasMiddleware
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
     * Listar UCs/UGs com filtros hierárquicos
     */
    public function index(Request $request): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        try {
            $query = UnidadeConsumidora::query()
                                     ->with(['usuario', 'proposta'])
                                     ->comFiltroHierarquico($currentUser);

            // Filtros específicos
            if ($request->filled('tipo')) {
                if ($request->tipo === 'uc') {
                    $query->UCs();
                } elseif ($request->tipo === 'ug') {
                    $query->UGs();
                }
            }

            if ($request->filled('gerador')) {
                $query->where('gerador', $request->boolean('gerador'));
            }

            if ($request->filled('nexus_clube')) {
                $query->where('nexus_clube', $request->boolean('nexus_clube'));
            }

            if ($request->filled('nexus_cativo')) {
                $query->where('nexus_cativo', $request->boolean('nexus_cativo'));
            }

            if ($request->filled('distribuidora')) {
                $query->where('distribuidora', 'ILIKE', "%{$request->distribuidora}%");
            }

            if ($request->filled('numero_cliente')) {
                $query->where('numero_cliente', 'ILIKE', "%{$request->numero_cliente}%");
            }

            if ($request->filled('numero_unidade')) {
                $query->where('numero_unidade', $request->numero_unidade);
            }

            if ($request->filled('apelido')) {
                $query->where('apelido', 'ILIKE', "%{$request->apelido}%");
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('apelido', 'ILIKE', "%{$search}%")
                      ->orWhere('numero_cliente', 'ILIKE', "%{$search}%")
                      ->orWhere('numero_unidade', '=', $search)
                      ->orWhere('distribuidora', 'ILIKE', "%{$search}%")
                      ->orWhere('nome_usina', 'ILIKE', "%{$search}%");
                });
            }

            // Ordenação
            $orderBy = $request->get('order_by', 'created_at');
            $orderDirection = $request->get('order_direction', 'desc');
            $query->orderBy($orderBy, $orderDirection);

            // Paginação
            $perPage = min($request->get('per_page', 15), 100);
            $unidades = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $unidades->through(function ($unidade) use ($currentUser) {
                    return $this->transformUnidadeForAPI($unidade, $currentUser);
                }),
                'meta' => [
                    'current_page' => $unidades->currentPage(),
                    'last_page' => $unidades->lastPage(),
                    'per_page' => $unidades->perPage(),
                    'total' => $unidades->total(),
                    'from' => $unidades->firstItem(),
                    'to' => $unidades->lastItem(),
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao listar unidades consumidoras', [
                'user_id' => $currentUser->id,
                'error' => $e->getMessage(),
                'filters' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Criar nova UC/UG
     */
    public function store(Request $request): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Validação base
        $rules = [
            'numero_cliente' => 'required|integer',
            'numero_unidade' => 'required|integer',
            'apelido' => 'nullable|string|max:100',
            'consumo_medio' => 'nullable|numeric|min:0',
            'distribuidora' => 'nullable|string|max:100',
            'ligacao' => 'nullable|string|max:50',
            'is_ug' => 'boolean',
            
            // Campos para UGs
            'nome_usina' => 'nullable|string|max:200',
            'potencia_cc' => 'nullable|numeric|min:0',
            'fator_capacidade' => 'nullable|numeric|min:0|max:1',
            'localizacao' => 'nullable|string|max:300',
            'observacoes_ug' => 'nullable|string|max:1000',
            
            // Modalidades
            'nexus_clube' => 'boolean',
            'nexus_cativo' => 'boolean',
            'service' => 'boolean',
            'project' => 'boolean',
            
            // Relacionamentos opcionais
            'proposta_id' => 'nullable|exists:propostas,id'
        ];

        // Validação específica para UGs
        if ($request->boolean('is_ug')) {
            $rules['nome_usina'] = 'required|string|max:200';
            $rules['potencia_cc'] = 'required|numeric|min:0';
        }

        $validator = Validator::make($request->all(), $rules, [
            'numero_cliente.required' => 'Número do cliente é obrigatório',
            'numero_unidade.required' => 'Número da unidade é obrigatório',
            'nome_usina.required' => 'Nome da usina é obrigatório para UGs',
            'potencia_cc.required' => 'Potência CC é obrigatória para UGs',
            'proposta_id.exists' => 'Proposta não encontrada'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Verificar duplicação
            $exists = UnidadeConsumidora::where('numero_cliente', $request->numero_cliente)
                                       ->where('numero_unidade', $request->numero_unidade)
                                       ->first();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Já existe uma unidade com estes números'
                ], 422);
            }

            $unidade = UnidadeConsumidora::create([
                'usuario_id' => $currentUser->id,
                'concessionaria_id' => $currentUser->concessionaria_atual_id,
                'proposta_id' => $request->proposta_id,
                
                // Dados básicos
                'numero_cliente' => $request->numero_cliente,
                'numero_unidade' => $request->numero_unidade,
                'apelido' => $request->apelido ?? "UC {$request->numero_unidade}",
                'consumo_medio' => $request->consumo_medio ?? 0,
                'distribuidora' => $request->distribuidora,
                'ligacao' => $request->ligacao,
                
                // Flags básicas
                'mesmo_titular' => true,
                'tipo' => $request->boolean('is_ug') ? 'Geradora' : 'Consumidora',
                'gerador' => $request->boolean('is_ug'),
                'proprietario' => true,
                'is_ug' => $request->boolean('is_ug'),
                
                // Campos específicos para UGs
                'nome_usina' => $request->nome_usina ? trim($request->nome_usina) : null,
                'potencia_cc' => $request->potencia_cc,
                'fator_capacidade' => $request->fator_capacidade,
                'localizacao' => $request->localizacao ? trim($request->localizacao) : null,
                'observacoes_ug' => $request->observacoes_ug ? trim($request->observacoes_ug) : null,
                
                // Modalidades
                'nexus_clube' => $request->boolean('nexus_clube'),
                'nexus_cativo' => $request->boolean('nexus_cativo'),
                'service' => $request->boolean('service'),
                'project' => $request->boolean('project')
            ]);

            \Log::info('Unidade consumidora criada', [
                'unidade_id' => $unidade->id,
                'numero_cliente' => $unidade->numero_cliente,
                'numero_unidade' => $unidade->numero_unidade,
                'is_ug' => $unidade->is_ug,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => $unidade->is_ug ? 'UG criada com sucesso!' : 'UC criada com sucesso!',
                'data' => $this->transformUnidadeForAPI($unidade->load(['usuario', 'proposta']), $currentUser)
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Erro ao criar unidade consumidora', [
                'user_id' => $currentUser->id,
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Exibir UC/UG específica
     */
    public function show(string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        try {
            $unidade = UnidadeConsumidora::with(['usuario', 'proposta', 'controleClube'])
                                        ->findOrFail($id);

            // Verificar permissão
            if (!$this->canViewUnidade($currentUser, $unidade)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $this->transformUnidadeDetailForAPI($unidade, $currentUser)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unidade não encontrada'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erro ao buscar unidade consumidora', [
                'unidade_id' => $id,
                'user_id' => $currentUser->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Atualizar UC/UG
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        try {
            $unidade = UnidadeConsumidora::findOrFail($id);

            // Verificar permissão
            if (!$this->canEditUnidade($currentUser, $unidade)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'apelido' => 'sometimes|nullable|string|max:100',
                'consumo_medio' => 'sometimes|nullable|numeric|min:0',
                'distribuidora' => 'sometimes|nullable|string|max:100',
                'ligacao' => 'sometimes|nullable|string|max:50',
                'nome_usina' => 'sometimes|nullable|string|max:200',
                'potencia_cc' => 'sometimes|nullable|numeric|min:0',
                'fator_capacidade' => 'sometimes|nullable|numeric|min:0|max:1',
                'localizacao' => 'sometimes|nullable|string|max:300',
                'observacoes_ug' => 'sometimes|nullable|string|max:1000',
                'nexus_clube' => 'sometimes|boolean',
                'nexus_cativo' => 'sometimes|boolean',
                'service' => 'sometimes|boolean',
                'project' => 'sometimes|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $dadosAtualizacao = $request->only([
                'apelido', 'consumo_medio', 'distribuidora', 'ligacao',
                'nome_usina', 'potencia_cc', 'fator_capacidade', 
                'localizacao', 'observacoes_ug', 'nexus_clube', 
                'nexus_cativo', 'service', 'project'
            ]);

            $unidade->update($dadosAtualizacao);

            \Log::info('Unidade consumidora atualizada', [
                'unidade_id' => $unidade->id,
                'numero_unidade' => $unidade->numero_unidade,
                'user_id' => $currentUser->id,
                'campos_alterados' => array_keys($dadosAtualizacao)
            ]);

            return response()->json([
                'success' => true,
                'message' => $unidade->is_ug ? 'UG atualizada com sucesso!' : 'UC atualizada com sucesso!',
                'data' => $this->transformUnidadeForAPI($unidade->fresh(['usuario', 'proposta']), $currentUser)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unidade não encontrada'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erro ao atualizar unidade consumidora', [
                'unidade_id' => $id,
                'user_id' => $currentUser->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Excluir UC/UG
     */
    public function destroy(string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        try {
            $unidade = UnidadeConsumidora::findOrFail($id);

            // Verificar permissão
            if (!$this->canDeleteUnidade($currentUser, $unidade)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            // Verificar se pode ser excluída
            if ($unidade->controleClube()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unidade com controle ativo não pode ser excluída'
                ], 422);
            }

            $numeroUnidade = $unidade->numero_unidade;
            $tipoUnidade = $unidade->is_ug ? 'UG' : 'UC';

            $unidade->delete();

            \Log::info('Unidade consumidora excluída', [
                'unidade_id' => $id,
                'numero_unidade' => $numeroUnidade,
                'tipo' => $tipoUnidade,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => "{$tipoUnidade} {$numeroUnidade} excluída com sucesso!"
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unidade não encontrada'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erro ao excluir unidade consumidora', [
                'unidade_id' => $id,
                'user_id' => $currentUser->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Estatísticas de unidades
     */
    public function statistics(Request $request): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        try {
            $query = UnidadeConsumidora::query()->comFiltroHierarquico($currentUser);

            $stats = [
                'total_unidades' => $query->count(),
                'total_ucs' => $query->UCs()->count(),
                'total_ugs' => $query->UGs()->count(),
                'por_distribuidora' => $query->selectRaw('distribuidora, COUNT(*) as total')
                                           ->whereNotNull('distribuidora')
                                           ->groupBy('distribuidora')
                                           ->orderByDesc('total')
                                           ->pluck('total', 'distribuidora')
                                           ->toArray(),
                'modalidades' => [
                    'nexus_clube' => $query->where('nexus_clube', true)->count(),
                    'nexus_cativo' => $query->where('nexus_cativo', true)->count(),
                    'service' => $query->where('service', true)->count(),
                    'project' => $query->where('project', true)->count(),
                ],
                'consumo_total' => $query->sum('consumo_medio'),
                'potencia_total_ugs' => $query->UGs()->sum('potencia_cc')
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao buscar estatísticas de unidades', [
                'user_id' => $currentUser->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Verificar se usuário pode visualizar unidade
     */
    private function canViewUnidade(Usuario $user, UnidadeConsumidora $unidade): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isGerente()) return $unidade->concessionaria_id === $user->concessionaria_atual_id;
        return $unidade->usuario_id === $user->id;
    }

    /**
     * Verificar se usuário pode editar unidade
     */
    private function canEditUnidade(Usuario $user, UnidadeConsumidora $unidade): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isGerente()) return $unidade->concessionaria_id === $user->concessionaria_atual_id;
        return $unidade->usuario_id === $user->id;
    }

    /**
     * Verificar se usuário pode excluir unidade
     */
    private function canDeleteUnidade(Usuario $user, UnidadeConsumidora $unidade): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isGerente()) return $unidade->concessionaria_id === $user->concessionaria_atual_id;
        return $unidade->usuario_id === $user->id;
    }

    /**
     * Transformar unidade para resposta da API
     */
    private function transformUnidadeForAPI(UnidadeConsumidora $unidade, Usuario $currentUser): array
    {
        return [
            'id' => $unidade->id,
            'numero_cliente' => $unidade->numero_cliente,
            'numero_unidade' => $unidade->numero_unidade,
            'apelido' => $unidade->apelido,
            'tipo' => $unidade->tipo,
            'is_ug' => $unidade->is_ug,
            'consumo_medio' => $unidade->consumo_medio,
            'distribuidora' => $unidade->distribuidora,
            'ligacao' => $unidade->ligacao,
            'nome_usina' => $unidade->nome_usina,
            'potencia_cc' => $unidade->potencia_cc,
            'fator_capacidade' => $unidade->fator_capacidade,
            'localizacao' => $unidade->localizacao,
            'observacoes_ug' => $unidade->observacoes_ug,
            'nexus_clube' => $unidade->nexus_clube,
            'nexus_cativo' => $unidade->nexus_cativo,
            'service' => $unidade->service,
            'project' => $unidade->project,
            'gerador' => $unidade->gerador,
            'proposta_id' => $unidade->proposta_id,
            'created_at' => $unidade->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $unidade->updated_at?->format('Y-m-d H:i:s'),
            'permissions' => [
                'can_view' => $this->canViewUnidade($currentUser, $unidade),
                'can_edit' => $this->canEditUnidade($currentUser, $unidade),
                'can_delete' => $this->canDeleteUnidade($currentUser, $unidade),
            ]
        ];
    }

    /**
     * Transformar unidade para resposta detalhada da API
     */
    private function transformUnidadeDetailForAPI(UnidadeConsumidora $unidade, Usuario $currentUser): array
    {
        $base = $this->transformUnidadeForAPI($unidade, $currentUser);
        
        $base['proposta'] = $unidade->proposta ? [
            'id' => $unidade->proposta->id,
            'numero_proposta' => $unidade->proposta->numero_proposta,
            'nome_cliente' => $unidade->proposta->nome_cliente,
            'status' => $unidade->proposta->status,
        ] : null;

        $base['controle_ativo'] = $unidade->controleClube ? [
            'id' => $unidade->controleClube->id,
            'ativo' => $unidade->controleClube->ativo,
            'data_inicio_clube' => $unidade->controleClube->data_inicio_clube?->format('Y-m-d'),
        ] : null;

        return $base;
    }
}