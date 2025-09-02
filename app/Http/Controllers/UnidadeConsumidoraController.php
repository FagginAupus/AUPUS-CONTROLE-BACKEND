<?php

namespace App\Http\Controllers;

use App\Models\UnidadeConsumidora;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\DB;

class UnidadeConsumidoraController extends Controller
{
    /**
     * Listar UCs (Unidades Consumidoras normais)
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
            $query = UnidadeConsumidora::with(['usuario', 'proposta'])
                                    ->where('gerador', false) // CORRIGIDO: usar 'gerador' ao invés de 'is_ug'
                                    ->whereNull('deleted_at');

            // Filtros baseados no papel do usuário
            if (!$currentUser->isAdmin()) {
                if ($currentUser->isGerente()) {
                    $query->where('concessionaria_id', $currentUser->concessionaria_atual_id);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            // Filtros de pesquisa
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('apelido', 'ilike', '%' . $search . '%')
                      ->orWhere('numero_unidade', 'like', '%' . $search . '%')
                      ->orWhere('distribuidora', 'ilike', '%' . $search . '%');
                });
            }

            if ($request->filled('distribuidora')) {
                $query->where('distribuidora', 'ilike', '%' . $request->distribuidora . '%');
            }

            if ($request->filled('modalidade')) {
                $modalidade = $request->modalidade;
                if ($modalidade === 'nexus_clube') {
                    $query->where('nexus_clube', true);
                } elseif ($modalidade === 'nexus_cativo') {
                    $query->where('nexus_cativo', true);
                } elseif ($modalidade === 'service') {
                    $query->where('service', true);
                } elseif ($modalidade === 'project') {
                    $query->where('project', true);
                }
            }

            $unidades = $query->orderBy('created_at', 'desc')
                             ->paginate(50);

            $dadosTransformados = $unidades->getCollection()->map(function($unidade) use ($currentUser) {
                return $this->transformUnidadeForAPI($unidade, $currentUser);
            });

            return response()->json([
                'success' => true,
                'data' => $dadosTransformados,
                'pagination' => [
                    'current_page' => $unidades->currentPage(),
                    'last_page' => $unidades->lastPage(),
                    'per_page' => $unidades->perPage(),
                    'total' => $unidades->total(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar unidades consumidoras', [
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
     * ✅ MÉTODO CRIADO: Listar UGs (Usinas Geradoras)
     */
    public function indexUGs(Request $request): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        try {
            $query = UnidadeConsumidora::with(['usuario'])
                                    ->where('gerador', true)
                                    ->where('nexus_clube', true) // ADICIONADO: só UGs do clube
                                    ->whereNull('deleted_at');

            // Filtros baseados no papel do usuário
            if (!$currentUser->isAdmin()) {
                if ($currentUser->isGerente()) {
                    $query->where('concessionaria_id', $currentUser->concessionaria_atual_id);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            // Filtros de pesquisa
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where('nome_usina', 'ilike', '%' . $search . '%');
            }

            $ugs = $query->orderBy('nome_usina', 'asc')->get();

            $dadosTransformados = $ugs->map(function($ug) use ($currentUser) {
                return $this->transformUGForAPI($ug, $currentUser);
            });

            return response()->json([
                'success' => true,
                'data' => $dadosTransformados,
                'total' => $dadosTransformados->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar UGs', [
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

        try {
            // Verificar se é UG
            $isUG = $request->boolean('gerador') || $request->has('nome_usina');

            // Validação básica
            $baseRules = [
                'apelido' => 'required|string|max:100',
                'numero_unidade' => 'required|string|max:50',
                'consumo_medio' => 'required|numeric|min:0',
                'distribuidora' => 'nullable|string|max:100',
                'ligacao' => 'nullable|string|max:50',
            ];

            // Validações específicas para UGs
            if ($isUG) {
                $baseRules += [
                    'nome_usina' => 'required|string|max:200',
                    'potencia_cc' => 'required|numeric|min:0',
                    'fator_capacidade' => 'required|numeric|min:0|max:100',
                    'localizacao' => 'nullable|string|max:300',
                    'observacoes_ug' => 'nullable|string|max:1000'
                ];
            }

            $validator = Validator::make($request->all(), $baseRules);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Criar unidade
            $dadosUnidade = [
                'id' => (string) Str::uuid(),
                'usuario_id' => $currentUser->id,
                'concessionaria_id' => $currentUser->concessionaria_atual_id,
                'endereco_id' => null, // Para ser implementado posteriormente
                'mesmo_titular' => true,
                'apelido' => trim($request->apelido),
                'numero_unidade' => $request->numero_unidade,
                'consumo_medio' => $request->consumo_medio,
                'distribuidora' => $request->distribuidora ? trim($request->distribuidora) : 'CEMIG',
                'ligacao' => $request->ligacao ?: 'MONOFÁSICA',
                'tipo' => $isUG ? 'UG' : 'UC',
                'gerador' => $isUG,
                
                // Modalidades
                'nexus_clube' => $isUG ? true : $request->boolean('nexus_clube'), // CORRIGIDO: Se for UG, sempre true
                'nexus_cativo' => $request->boolean('nexus_cativo'),
                'service' => $request->boolean('service'),
                'project' => $request->boolean('project')
            ];

            // Dados específicos para UGs
            if ($isUG) {
                $dadosUnidade += [
                    'nome_usina' => trim($request->nome_usina),
                    'potencia_cc' => $request->potencia_cc,
                    'fator_capacidade' => $request->fator_capacidade,
                    'capacidade_calculada' => 720 * $request->potencia_cc * ($request->fator_capacidade),
                    'localizacao' => $request->localizacao ? trim($request->localizacao) : null,
                    'observacoes_ug' => $request->observacoes_ug ? trim($request->observacoes_ug) : null,
                ];
            }

            $unidade = UnidadeConsumidora::create($dadosUnidade);

            Log::info('Unidade consumidora criada', [
                'unidade_id' => $unidade->id,
                'numero_unidade' => $unidade->numero_unidade,
                'gerador' => $unidade->gerador, // CORRIGIDO: usar 'gerador' ao invés de 'is_ug'
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => $unidade->gerador ? 'UG criada com sucesso!' : 'UC criada com sucesso!', // CORRIGIDO
                'data' => $isUG ? 
                    $this->transformUGForAPI($unidade->load(['usuario']), $currentUser) :
                    $this->transformUnidadeForAPI($unidade->load(['usuario', 'proposta']), $currentUser)
            ], 201);

        } catch (\Exception $e) {
            Log::error('Erro ao criar unidade consumidora', [
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
     * ✅ MÉTODO CRIADO: Criar nova UG
     */
    public function storeUG(Request $request): JsonResponse
    {
        // Adicionar gerador = true e nexus_clube = true automaticamente
        $request->merge(['gerador' => true, 'nexus_clube' => true]); // CORRIGIDO
        return $this->store($request);
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
            $unidade = UnidadeConsumidora::with(['usuario', 'proposta'])
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
                'data' => $unidade->gerador ? // CORRIGIDO: usar 'gerador' ao invés de 'is_ug'
                    $this->transformUGDetailForAPI($unidade, $currentUser) :
                    $this->transformUnidadeDetailForAPI($unidade, $currentUser)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unidade não encontrada'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar unidade consumidora', [
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
     * ✅ MÉTODO CRIADO: Exibir UG específica
     */
    public function showUG(string $id): JsonResponse
    {
        return $this->show($id);
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

            // Validação
            $validator = Validator::make($request->all(), [
                'apelido' => 'sometimes|required|string|max:100',
                'consumo_medio' => 'sometimes|required|numeric|min:0',
                'distribuidora' => 'sometimes|nullable|string|max:100',
                'ligacao' => 'sometimes|nullable|string|max:50',
                'nome_usina' => 'sometimes|nullable|string|max:200',
                'potencia_cc' => 'sometimes|nullable|numeric|min:0',
                'fator_capacidade' => 'sometimes|nullable|numeric|min:0|max:100',
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

            // Recalcular capacidade se for UG e dados de potência mudaram
            if ($unidade->gerador && ($request->has('potencia_cc') || $request->has('fator_capacidade'))) { // CORRIGIDO
                $potencia = $request->potencia_cc ?? $unidade->potencia_cc;
                $fator = $request->fator_capacidade ?? $unidade->fator_capacidade;
                $dadosAtualizacao['capacidade_calculada'] = 720 * $potencia * ($fator / 100);
            }

            $unidade->update($dadosAtualizacao);

            Log::info('Unidade consumidora atualizada', [
                'unidade_id' => $unidade->id,
                'numero_unidade' => $unidade->numero_unidade,
                'user_id' => $currentUser->id,
                'campos_alterados' => array_keys($dadosAtualizacao)
            ]);

            return response()->json([
                'success' => true,
                'message' => $unidade->gerador ? 'UG atualizada com sucesso!' : 'UC atualizada com sucesso!', // CORRIGIDO
                'data' => $unidade->gerador ? // CORRIGIDO
                    $this->transformUGForAPI($unidade->load(['usuario']), $currentUser) :
                    $this->transformUnidadeForAPI($unidade->load(['usuario', 'proposta']), $currentUser)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unidade não encontrada'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Erro ao atualizar unidade consumidora', [
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
     * ✅ MÉTODO CRIADO: Atualizar UG específica
     */
    public function updateUG(Request $request, string $id): JsonResponse
    {
        return $this->update($request, $id);
    }

    /**
     * Excluir UC/UG (soft delete)
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

            // Soft delete
            $unidade->update([
                'deleted_at' => now(),
                'deleted_by' => $currentUser->id
            ]);

            Log::info('Unidade consumidora excluída', [
                'unidade_id' => $unidade->id,
                'numero_unidade' => $unidade->numero_unidade,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => $unidade->gerador ? 'UG excluída com sucesso!' : 'UC excluída com sucesso!' // CORRIGIDO
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unidade não encontrada'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Erro ao excluir unidade consumidora', [
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
     * ✅ MÉTODO CRIADO: Excluir UG específica
     */
    public function destroyUG(string $id): JsonResponse
    {
        return $this->destroy($id);
    }


    public function statistics(Request $request): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
            }

            $query = DB::table('unidades_consumidoras')
                    ->where('gerador', true)
                    ->where('nexus_clube', true) 
                    ->whereNull('deleted_at');

            // Aplicar filtros baseados na role do usuário
            if ($currentUser->role === 'admin') {
                // Admin vê tudo
            } else {
                if ($currentUser->role === 'gerente') {
                    $query->where('concessionaria_id', $currentUser->concessionaria_atual_id);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            $ugs = $query->get();
            
            // Calcular estatísticas dinamicamente
            $totalUcsAtribuidas = 0;
            $totalMediaConsumo = 0;
            
            foreach ($ugs as $ug) {
                $totalUcsAtribuidas += $this->obterUcsAtribuidas($ug->id);
                $totalMediaConsumo += $this->obterMediaConsumoAtribuido($ug->id);
            }

            $stats = [
                'total' => $ugs->count(),
                'capacidadeTotal' => $ugs->sum('capacidade_calculada'),
                'ucsAtribuidas' => $totalUcsAtribuidas, // Calculado dinamicamente
                'mediaConsumo' => $ugs->count() > 0 ? $totalMediaConsumo / $ugs->count() : 0, // Calculado dinamicamente
                'potenciaTotal' => $ugs->sum('potencia_cc'),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao obter estatísticas UGs', [
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
     * Obter quantidade de UCs atribuídas a uma UG
     */
    private function obterUcsAtribuidas(string $ugId): int
    {
        $result = DB::selectOne("
            SELECT COUNT(*) as total 
            FROM controle_clube 
            WHERE ug_id = ? AND deleted_at IS NULL
        ", [$ugId]);
        
        return intval($result->total ?? 0);
    }

    /**
     * Obter média de consumo atribuído a uma UG
     */
    private function obterMediaConsumoAtribuido(string $ugId): float
    {
        $result = DB::selectOne("
            SELECT COALESCE(SUM(cc.valor_calibrado), 0) as total
            FROM controle_clube cc
            WHERE cc.ug_id = ? AND cc.deleted_at IS NULL
        ", [$ugId]);
        
        return floatval($result->total ?? 0);
    }

    // ========================================
    // MÉTODOS DE PERMISSÃO
    // ========================================

    private function canViewUnidade(Usuario $user, UnidadeConsumidora $unidade): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isGerente()) {
            return $unidade->concessionaria_id === $user->concessionaria_atual_id;
        }

        return $unidade->usuario_id === $user->id;
    }

    private function canEditUnidade(Usuario $user, UnidadeConsumidora $unidade): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $unidade->usuario_id === $user->id;
    }

    private function canDeleteUnidade(Usuario $user, UnidadeConsumidora $unidade): bool
    {
        return $user->isAdmin();
    }

    // ========================================
    // MÉTODOS DE TRANSFORMAÇÃO
    // ========================================

    /**
     * Transformar unidade para resposta da API
     */
    private function transformUnidadeForAPI(UnidadeConsumidora $unidade, Usuario $currentUser): array
    {
        return [
            'id' => $unidade->id,
            'apelido' => $unidade->apelido,
            'numero_unidade' => $unidade->numero_unidade,
            'numero_cliente' => $unidade->numero_cliente,
            'consumo_medio' => $unidade->consumo_medio,
            'distribuidora' => $unidade->distribuidora,
            'ligacao' => $unidade->ligacao,
            'tipo' => $unidade->tipo,
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
     * ✅ MÉTODO CRIADO: Transformar UG para resposta da API
     */
    private function transformUGForAPI(UnidadeConsumidora $ug, Usuario $currentUser): array
    {
        // ✅ LOG TEMPORÁRIO PARA DEBUG
        Log::info('DEBUG transformUGForAPI', [
            'ug_id' => $ug->id,
            'nome_usina' => $ug->nome_usina,
            'numero_unidade' => $ug->numero_unidade, // Verificar se existe
            'numero_unidade_exists' => isset($ug->numero_unidade),
            'all_attributes' => $ug->getAttributes()
        ]);

        return [
            'id' => $ug->id,
            'nomeUsina' => $ug->nome_usina,
            'apelido' => $ug->apelido,
            'numeroUnidade' => $ug->numero_unidade,
            'potenciaCC' => (float) $ug->potencia_cc,
            'fatorCapacidade' => (float) $ug->fator_capacidade,
            'capacidade' => (float) $ug->capacidade_calculada,
            'localizacao' => $ug->localizacao,
            'observacoes' => $ug->observacoes_ug,
            'nexusClube' => (bool) $ug->nexus_clube,
            'nexusCativo' => (bool) $ug->nexus_cativo,
            'service' => (bool) $ug->service,
            'project' => (bool) $ug->project,
            'dataCadastro' => $ug->created_at?->format('Y-m-d H:i:s'),
            'dataAtualizacao' => $ug->updated_at?->format('Y-m-d H:i:s'),
            'permissions' => [
                'can_view' => $this->canViewUnidade($currentUser, $ug),
                'can_edit' => $this->canEditUnidade($currentUser, $ug),
                'can_delete' => $this->canDeleteUnidade($currentUser, $ug),
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

        return $base;
    }

    /**
     * ✅ MÉTODO CRIADO: Transformar UG para resposta detalhada da API
     */
    private function transformUGDetailForAPI(UnidadeConsumidora $ug, Usuario $currentUser): array
    {
        return $this->transformUGForAPI($ug, $currentUser);
    }
}