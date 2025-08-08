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

class UnidadeConsumidoraController extends Controller
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->middleware('auth:api');
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
                $query->where('numero_cliente', $request->numero_cliente);
            }

            if ($request->filled('numero_unidade')) {
                $query->where('numero_unidade', $request->numero_unidade);
            }

            if ($request->filled('proposta_id')) {
                $query->where('proposta_id', $request->proposta_id);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('apelido', 'ILIKE', "%{$search}%")
                      ->orWhere('numero_cliente', 'ILIKE', "%{$search}%")
                      ->orWhere('numero_unidade', 'ILIKE', "%{$search}%")
                      ->orWhere('nome_usina', 'ILIKE', "%{$search}%");
                });
            }

            // Ordenação
            $orderBy = $request->get('order_by', 'created_at');
            $orderDirection = $request->get('order_direction', 'desc');
            
            $allowedOrderBy = [
                'created_at', 'numero_cliente', 'numero_unidade', 
                'consumo_medio', 'potencia_cc', 'capacidade_calculada'
            ];
            
            if (!in_array($orderBy, $allowedOrderBy)) {
                $orderBy = 'created_at';
            }
            
            $query->orderBy($orderBy, $orderDirection);

            // Paginação
            $perPage = min($request->get('per_page', 15), 100);
            $unidades = $query->paginate($perPage);

            // Transformar dados
            $unidades->getCollection()->transform(function ($unidade) use ($currentUser) {
                return $this->transformUnidadeForAPI($unidade, $currentUser);
            });

            // Estatísticas
            $estatisticas = UnidadeConsumidora::getEstatisticas(['usuario' => $currentUser]);

            return response()->json([
                'success' => true,
                'data' => $unidades,
                'statistics' => $estatisticas
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao listar unidades consumidoras', [
                'user_id' => $currentUser->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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

        $validator = Validator::make($request->all(), [
            'numero_cliente' => 'required|integer|min:1',
            'numero_unidade' => 'required|integer|min:1',
            'apelido' => 'nullable|string|max:100',
            'consumo_medio' => 'nullable|numeric|min:0',
            'tipo' => 'nullable|string|max:50',
            'distribuidora' => 'nullable|string|max:100',
            'tipo_ligacao' => 'nullable|string|max:50',
            'valor_fatura' => 'nullable|numeric|min:0',
            'proposta_id' => 'nullable|exists:propostas,id',
            
            // Dados específicos para gerador
            'gerador' => 'nullable|boolean',
            'geracao_prevista' => 'nullable|numeric|min:0',
            
            // Dados específicos para UG
            'is_ug' => 'nullable|boolean',
            'nome_usina' => 'nullable|string|max:200',
            'potencia_cc' => 'nullable|numeric|min:0',
            'fator_capacidade' => 'nullable|numeric|min:0|max:100',
            'localizacao' => 'nullable|string|max:255',
            'observacoes_ug' => 'nullable|string|max:1000',
            
            // Modalidades
            'nexus_clube' => 'nullable|boolean',
            'nexus_cativo' => 'nullable|boolean',
            'service' => 'nullable|boolean',
            'project' => 'nullable|boolean'
        ], [
            'numero_cliente.required' => 'Número do cliente é obrigatório',
            'numero_unidade.required' => 'Número da unidade é obrigatório',
            'fator_capacidade.max' => 'Fator de capacidade não pode ser maior que 100%',
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
            // Verificar se já existe UC com mesmo número
            $existeUC = UnidadeConsumidora::where('numero_cliente', $request->numero_cliente)
                                        ->where('numero_unidade', $request->numero_unidade)
                                        ->exists();

            if ($existeUC) {
                return response()->json([
                    'success' => false,
                    'message' => 'Já existe uma unidade com este número de cliente e unidade'
                ], 422);
            }

            // Verificar se proposta existe e pode ser vinculada
            if ($request->filled('proposta_id')) {
                $proposta = Proposta::find($request->proposta_id);
                if (!$proposta || !$currentUser->canAccessData(['consultor' => $proposta->consultor])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Proposta não encontrada ou acesso negado'
                    ], 403);
                }
            }

            $unidade = UnidadeConsumidora::create([
                'usuario_id' => $currentUser->id,
                'numero_cliente' => $request->numero_cliente,
                'numero_unidade' => $request->numero_unidade,
                'apelido' => $request->apelido ? trim($request->apelido) : null,
                'consumo_medio' => $request->consumo_medio,
                'tipo' => $request->tipo ?? 'Consumidora',
                'distribuidora' => $request->distribuidora ?? 'CEMIG',
                'tipo_ligacao' => $request->tipo_ligacao ?? 'Monofásica',
                'valor_fatura' => $request->valor_fatura,
                'proposta_id' => $request->proposta_id,
                
                // Dados de geração
                'gerador' => $request->boolean('gerador'),
                'geracao_prevista' => $request->geracao_prevista,
                
                // Dados UG
                'is_ug' => $request->boolean('is_ug'),
                'nome_usina' => $request->is_ug && $request->nome_usina ? trim($request->nome_usina) : null,
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
                'numero_cliente' => 'sometimes|required|integer|min:1',
                'numero_unidade' => 'sometimes|required|integer|min:1',
                'apelido' => 'nullable|string|max:100',
                'consumo_medio' => 'nullable|numeric|min:0',
                'tipo' => 'nullable|string|max:50',
                'distribuidora' => 'nullable|string|max:100',
                'tipo_ligacao' => 'nullable|string|max:50',
                'valor_fatura' => 'nullable|numeric|min:0',
                'proposta_id' => 'nullable|exists:propostas,id',
                
                'gerador' => 'nullable|boolean',
                'geracao_prevista' => 'nullable|numeric|min:0',
                
                'is_ug' => 'nullable|boolean',
                'nome_usina' => 'nullable|string|max:200',
                'potencia_cc' => 'nullable|numeric|min:0',
                'fator_capacidade' => 'nullable|numeric|min:0|max:100',
                'localizacao' => 'nullable|string|max:255',
                'observacoes_ug' => 'nullable|string|max:1000',
                
                'nexus_clube' => 'nullable|boolean',
                'nexus_cativo' => 'nullable|boolean',
                'service' => 'nullable|boolean',
                'project' => 'nullable|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar se está tentando alterar número para um já existente
            if ($request->filled('numero_cliente') || $request->filled('numero_unidade')) {
                $numeroCliente = $request->numero_cliente ?? $unidade->numero_cliente;
                $numeroUnidade = $request->numero_unidade ?? $unidade->numero_unidade;
                
                $existeOutra = UnidadeConsumidora::where('numero_cliente', $numeroCliente)
                                                ->where('numero_unidade', $numeroUnidade)
                                                ->where('id', '!=', $id)
                                                ->exists();

                if ($existeOutra) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Já existe outra unidade com este número de cliente e unidade'
                    ], 422);
                }
            }

            $dadosAtualizacao = [];
            
            // Campos básicos
            foreach (['numero_cliente', 'numero_unidade', 'consumo_medio', 'tipo', 
                     'distribuidora', 'tipo_ligacao', 'valor_fatura', 'proposta_id',
                     'geracao_prevista', 'potencia_cc', 'fator_capacidade', 'localizacao'] as $campo) {
                if ($request->filled($campo)) {
                    $dadosAtualizacao[$campo] = $request->$campo;
                }
            }
            
            // Campos de texto que podem ser null
            if ($request->has('apelido')) {
                $dadosAtualizacao['apelido'] = $request->apelido ? trim($request->apelido) : null;
            }
            
            if ($request->has('nome_usina')) {
                $dadosAtualizacao['nome_usina'] = $request->nome_usina ? trim($request->nome_usina) : null;
            }
            
            if ($request->has('observacoes_ug')) {
                $dadosAtualizacao['observacoes_ug'] = $request->observacoes_ug ? trim($request->observacoes_ug) : null;
            }
            
            // Campos boolean
            foreach (['gerador', 'is_ug', 'nexus_clube', 'nexus_cativo', 'service', 'project'] as $campo) {
                if ($request->has($campo)) {
                    $dadosAtualizacao[$campo] = $request->boolean($campo);
                }
            }

            $unidade->update($dadosAtualizacao);

            \Log::info('Unidade consumidora atualizada', [
                'unidade_id' => $unidade->id,
                'user_id' => $currentUser->id,
                'campos_alterados' => array_keys($dadosAtualizacao)
            ]);

            return response()->json([
                'success' => true,
                'message' => $unidade->is_ug ? 'UG atualizada com sucesso!' : 'UC atualizada com sucesso!',
                'data' => $this->transformUnidadeForAPI($unidade->load(['usuario', 'proposta']), $currentUser)
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
     * Converter UC para UG
     */
    public function convertToUG(Request $request, string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Verificar se usuário pode gerenciar UGs
        if (!$currentUser->isAdmin() && !$currentUser->isConsultor()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores e consultores podem gerenciar UGs'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome_usina' => 'required|string|min:3|max:200',
            'potencia_cc' => 'required|numeric|min:0.1',
            'fator_capacidade' => 'required|numeric|min:1|max:100',
            'localizacao' => 'nullable|string|max:255',
            'observacoes_ug' => 'nullable|string|max:1000'
        ], [
            'nome_usina.required' => 'Nome da usina é obrigatório',
            'potencia_cc.required' => 'Potência CC é obrigatória',
            'fator_capacidade.required' => 'Fator de capacidade é obrigatório',
            'fator_capacidade.max' => 'Fator de capacidade não pode ser maior que 100%'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
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

            if ($unidade->is_ug) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta unidade já é uma UG'
                ], 422);
            }

            $dadosUG = [
                'nome_usina' => trim($request->nome_usina),
                'potencia_cc' => $request->potencia_cc,
                'fator_capacidade' => $request->fator_capacidade,
                'localizacao' => $request->localizacao ? trim($request->localizacao) : null,
                'observacoes_ug' => $request->observacoes_ug ? trim($request->observacoes_ug) : null
            ];

            $unidade->converterParaUG($dadosUG);

            \Log::info('UC convertida para UG', [
                'unidade_id' => $unidade->id,
                'nome_usina' => $dadosUG['nome_usina'],
                'potencia_cc' => $dadosUG['potencia_cc'],
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'UC convertida para UG com sucesso!',
                'data' => $this->transformUnidadeForAPI($unidade->load(['usuario', 'proposta']), $currentUser)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unidade não encontrada'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erro ao converter UC para UG', [
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
     * Reverter UG para UC
     */
    public function revertToUC(string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Verificar se usuário pode gerenciar UGs
        if (!$currentUser->isAdmin() && !$currentUser->isConsultor()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores e consultores podem gerenciar UGs'
            ], 403);
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

            if (!$unidade->is_ug) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta unidade não é uma UG'
                ], 422);
            }

            // Verificar se UG está vinculada a controles ativos
            $controlesAtivos = $unidade->controleClube()->where('ativo', true)->count();
            if ($controlesAtivos > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'UG não pode ser revertida pois está vinculada a controles ativos'
                ], 422);
            }

            $nomeUsina = $unidade->nome_usina;
            $unidade->reverterParaUC();

            \Log::info('UG revertida para UC', [
                'unidade_id' => $unidade->id,
                'nome_usina_anterior' => $nomeUsina,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'UG revertida para UC com sucesso!',
                'data' => $this->transformUnidadeForAPI($unidade->load(['usuario', 'proposta']), $currentUser)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unidade não encontrada'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erro ao reverter UG para UC', [
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
     * Aplicar calibragem
     */
    public function applyCalibragem(Request $request, string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Verificar se usuário pode aplicar calibragem
        if (!$currentUser->isAdmin() && !$currentUser->isConsultor()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores e consultores podem aplicar calibragem'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'percentual_calibragem' => 'required|numeric|min:-50|max:100'
        ], [
            'percentual_calibragem.required' => 'Percentual de calibragem é obrigatório',
            'percentual_calibragem.min' => 'Percentual de calibragem não pode ser menor que -50%',
            'percentual_calibragem.max' => 'Percentual de calibragem não pode ser maior que 100%'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
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

            if (!$unidade->consumo_medio || $unidade->consumo_medio <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unidade deve ter consumo médio maior que zero para aplicar calibragem'
                ], 422);
            }

            $percentual = $request->percentual_calibragem;
            $consumoAnterior = $unidade->consumo_medio;
            
            $unidade->aplicarCalibragem($percentual);
            $consumoNovo = $unidade->consumo_medio;

            \Log::info('Calibragem aplicada', [
                'unidade_id' => $unidade->id,
                'percentual' => $percentual,
                'consumo_anterior' => $consumoAnterior,
                'consumo_novo' => $consumoNovo,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => "Calibragem de " . number_format($percentual, 2, ',', '.') . "% aplicada com sucesso!",
                'data' => [
                    'id' => $unidade->id,
                    'percentual_aplicado' => $percentual,
                    'consumo_anterior' => $consumoAnterior,
                    'consumo_novo' => $consumoNovo,
                    'diferenca' => $consumoNovo - $consumoAnterior,
                    'calibragem_percentual' => $unidade->calibragem_percentual
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unidade não encontrada'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erro ao aplicar calibragem', [
                'unidade_id' => $id,
                'user_id' => $currentUser->id,
                'percentual' => $request->percentual_calibragem,
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
            if ($unidade->is_ug) {
                $controlesAtivos = $unidade->controleClube()->where('ativo', true)->count();
                if ($controlesAtivos > 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'UG não pode ser excluída pois está vinculada a controles ativos'
                    ], 422);
                }
            }

            $numeroCliente = $unidade->numero_cliente;
            $numeroUnidade = $unidade->numero_unidade;
            $tipo = $unidade->is_ug ? 'UG' : 'UC';

            $unidade->delete();

            \Log::info('Unidade consumidora excluída', [
                'unidade_id' => $id,
                'numero_cliente' => $numeroCliente,
                'numero_unidade' => $numeroUnidade,
                'tipo' => $tipo,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => "{$tipo} {$numeroCliente}/{$numeroUnidade} excluída com sucesso!"
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

    // Métodos auxiliares para verificação de permissões
    private function canViewUnidade(Usuario $currentUser, UnidadeConsumidora $unidade): bool
    {
        if ($currentUser->isAdmin()) return true;
        
        if ($currentUser->isConsultor()) {
            $subordinadosIds = array_column($currentUser->getAllSubordinates(), 'id');
            $usuariosPermitidos = array_merge([$currentUser->id], $subordinadosIds);
            return in_array($unidade->usuario_id, $usuariosPermitidos);
        }
        
        return $unidade->usuario_id === $currentUser->id;
    }

    private function canEditUnidade(Usuario $currentUser, UnidadeConsumidora $unidade): bool
    {
        return $this->canViewUnidade($currentUser, $unidade);
    }

    private function canDeleteUnidade(Usuario $currentUser, UnidadeConsumidora $unidade): bool
    {
        if (!$currentUser->isAdmin() && !$currentUser->isConsultor()) {
            return false;
        }
        
        return $this->canViewUnidade($currentUser, $unidade);
    }

    // Métodos auxiliares para transformação de dados
    private function transformUnidadeForAPI(UnidadeConsumidora $unidade, Usuario $currentUser): array
    {
        return [
            'id' => $unidade->id,
            'numero_cliente' => $unidade->numero_cliente,
            'numero_unidade' => $unidade->numero_unidade,
            'apelido' => $unidade->apelido,
            'consumo_medio' => $unidade->consumo_medio,
            'consumo_formatado' => $unidade->consumo_formatado,
            'tipo' => $unidade->tipo,
            'tipo_display' => $unidade->tipo_display,
            'distribuidora' => $unidade->distribuidora,
            'tipo_ligacao' => $unidade->tipo_ligacao,
            'valor_fatura' => $unidade->valor_fatura,
            'valor_fatura_formatado' => $unidade->valor_fatura_formatado,
            
            // Status e modalidades
            'gerador' => $unidade->gerador,
            'geracao_prevista' => $unidade->geracao_prevista,
            'geracao_formatada' => $unidade->geracao_formatada,
            'nexus_clube' => $unidade->nexus_clube,
            'nexus_cativo' => $unidade->nexus_cativo,
            'service' => $unidade->service,
            'project' => $unidade->project,
            'status_display' => $unidade->status_display,
            
            // Dados UG
            'is_ug' => $unidade->is_ug,
            'nome_usina' => $unidade->nome_usina,
            'potencia_cc' => $unidade->potencia_cc,
            'potencia_cc_formatada' => $unidade->potencia_cc_formatada,
            'fator_capacidade' => $unidade->fator_capacidade,
            'fator_capacidade_formatado' => $unidade->fator_capacidade_formatado,
            'capacidade_calculada' => $unidade->capacidade_calculada,
            'capacidade_calculada_formatada' => $unidade->capacidade_calculada_formatada,
            'localizacao' => $unidade->localizacao,
            'observacoes_ug' => $unidade->observacoes_ug,
            
            // Calibragem
            'calibragem_percentual' => $unidade->calibragem_percentual,
            
            // Timestamps
            'created_at' => $unidade->created_at->format('d/m/Y H:i'),
            'updated_at' => $unidade->updated_at->format('d/m/Y H:i'),
            
            // Relacionamentos
            'usuario' => $unidade->usuario ? [
                'id' => $unidade->usuario->id,
                'nome' => $unidade->usuario->nome,
                'email' => $unidade->usuario->email
            ] : null,
            
            'proposta' => $unidade->proposta ? [
                'id' => $unidade->proposta->id,
                'numero_proposta' => $unidade->proposta->numero_proposta,
                'nome_cliente' => $unidade->proposta->nome_cliente,
                'status' => $unidade->proposta->status
            ] : null,
            
            // Permissões
            'permissions' => [
                'can_edit' => $this->canEditUnidade($currentUser, $unidade),
                'can_delete' => $this->canDeleteUnidade($currentUser, $unidade),
                'can_convert_to_ug' => (!$unidade->is_ug && ($currentUser->isAdmin() || $currentUser->isConsultor())),
                'can_revert_to_uc' => ($unidade->is_ug && ($currentUser->isAdmin() || $currentUser->isConsultor())),
                'can_apply_calibragem' => ($currentUser->isAdmin() || $currentUser->isConsultor())
            ]
        ];
    }

    private function transformUnidadeDetailForAPI(UnidadeConsumidora $unidade, Usuario $currentUser): array
    {
        $data = $this->transformUnidadeForAPI($unidade, $currentUser);
        
        // Adicionar dados detalhados se for UG
        if ($unidade->is_ug) {
            $data['validation_errors'] = $unidade->isValidForUG();
        }
        
        // Controles vinculados (para UGs)
        if ($unidade->is_ug && $unidade->controleClube) {
            $data['controles_vinculados'] = $unidade->controleClube->map(function ($controle) {
                return [
                    'id' => $controle->id,
                    'numero_proposta' => $controle->numero_proposta,
                    'numero_uc' => $controle->numero_uc,
                    'nome_cliente' => $controle->nome_cliente,
                    'ativo' => $controle->ativo,
                    'status_display' => $controle->status_display,
                    'data_inicio_clube' => $controle->data_inicio_clube ? $controle->data_inicio_clube->format('d/m/Y') : null
                ];
            });
        }
        
        return $data;
    }
}