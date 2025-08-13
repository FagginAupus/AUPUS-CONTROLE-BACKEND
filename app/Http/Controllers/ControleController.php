<?php

namespace App\Http\Controllers;

use App\Models\ControleClube;
use App\Models\Proposta;
use App\Models\UnidadeConsumidora;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class ControleController extends Controller
{
    /**
     * ✅ LISTAR CONTROLES COM FILTROS E PAGINAÇÃO
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            Log::info('Carregando controle clube', [
                'user_id' => $currentUser->id,
                'user_role' => $currentUser->role,
                'filters' => $request->all()
            ]);

            // Parâmetros de paginação
            $page = max(1, (int)$request->get('page', 1));
            $perPage = min(100, max(1, (int)$request->get('per_page', 50)));

            // Query base
            $query = ControleClube::with(['proposta', 'unidadeConsumidora', 'unidadeGeradora']);

            // Filtros baseados no role do usuário
            if ($currentUser->role === 'vendedor') {
                $query->whereHas('proposta', function ($q) use ($currentUser) {
                    $q->where('usuario_id', $currentUser->id);
                });
            } elseif ($currentUser->role === 'gerente') {
                $teamIds = collect([$currentUser->id]);
                if (method_exists($currentUser, 'team')) {
                    $teamIds = $teamIds->merge($currentUser->team()->pluck('id'));
                }
                $query->whereHas('proposta', function ($q) use ($teamIds) {
                    $q->whereIn('usuario_id', $teamIds);
                });
            }

            // Filtros opcionais
            if ($request->filled('proposta_id')) {
                $query->where('proposta_id', $request->proposta_id);
            }

            if ($request->filled('uc_id')) {
                $query->where('uc_id', $request->uc_id);
            }

            if ($request->filled('ug_id')) {
                $query->where('ug_id', $request->ug_id);
            }

            // Ordenação
            $sortBy = $request->get('sort_by', 'created_at');
            $sortDir = $request->get('sort_dir', 'desc');
            
            $allowedSorts = ['created_at', 'data_entrada_controle', 'calibragem', 'valor_calibrado'];
            if (in_array($sortBy, $allowedSorts)) {
                $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            // Paginação
            $total = $query->count();
            $controles = $query->offset(($page - 1) * $perPage)
                              ->limit($perPage)
                              ->get();

            // Formatar dados
            $controlesFormatados = $controles->map(function ($controle) {
                return [
                    'id' => $controle->id,
                    'proposta_id' => $controle->proposta_id,
                    'uc_id' => $controle->uc_id,
                    'ug_id' => $controle->ug_id,
                    'calibragem' => $controle->calibragem,
                    'valor_calibrado' => $controle->valor_calibrado,
                    'observacoes' => $controle->observacoes,
                    'data_entrada_controle' => $controle->data_entrada_controle,
                    'created_at' => $controle->created_at,
                    'updated_at' => $controle->updated_at,
                    
                    // Relacionamentos
                    'proposta' => $controle->proposta ? [
                        'id' => $controle->proposta->id,
                        'numero_proposta' => $controle->proposta->numero_proposta,
                        'nome_cliente' => $controle->proposta->nome_cliente,
                        'consultor' => $controle->proposta->consultor,
                        'status' => $controle->proposta->status
                    ] : null,
                    
                    'unidade_consumidora' => $controle->unidadeConsumidora ? [
                        'id' => $controle->unidadeConsumidora->id,
                        'numero_unidade' => $controle->unidadeConsumidora->numero_unidade,
                        'apelido' => $controle->unidadeConsumidora->apelido,
                        'consumo_medio' => $controle->unidadeConsumidora->consumo_medio
                    ] : null,
                    
                    'unidade_geradora' => $controle->unidadeGeradora ? [
                        'id' => $controle->unidadeGeradora->id,
                        'nome_usina' => $controle->unidadeGeradora->nome_usina,
                        'potencia_cc' => $controle->unidadeGeradora->potencia_cc
                    ] : null
                ];
            });

            Log::info('Controle clube carregado com sucesso', [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'data' => $controlesFormatados,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                    'from' => ($page - 1) * $perPage + 1,
                    'to' => min($page * $perPage, $total)
                ],
                'filters' => [
                    'proposta_id' => $request->proposta_id,
                    'uc_id' => $request->uc_id,
                    'ug_id' => $request->ug_id
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao carregar controle clube', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'user_id' => $currentUser->id ?? 'desconhecido'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor',
                'debug' => config('app.debug') ? [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => basename($e->getFile())
                ] : null
            ], 500);
        }
    }

    /**
     * ✅ CRIAR NOVO CONTROLE
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            Log::info('Dados recebidos para criar controle', [
                'user_id' => $currentUser->id,
                'request_data' => $request->all()
            ]);

            // Validação
            $validator = Validator::make($request->all(), [
                'proposta_id' => 'required|string|exists:propostas,id',
                'uc_id' => 'required|string|exists:unidades_consumidoras,id',
                'ug_id' => 'nullable|string|exists:unidades_consumidoras,id',
                'calibragem' => 'nullable|numeric|min:-100|max:100',
                'valor_calibrado' => 'nullable|numeric|min:0',
                'observacoes' => 'nullable|string|max:1000',
                'data_entrada_controle' => 'nullable|date'
            ], [
                'proposta_id.required' => 'ID da proposta é obrigatório',
                'proposta_id.exists' => 'Proposta não encontrada',
                'uc_id.required' => 'ID da unidade consumidora é obrigatório',
                'uc_id.exists' => 'Unidade consumidora não encontrada',
                'ug_id.exists' => 'Unidade geradora não encontrada',
                'calibragem.numeric' => 'Calibragem deve ser um número',
                'calibragem.min' => 'Calibragem deve ser maior que -100%',
                'calibragem.max' => 'Calibragem deve ser menor que 100%'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Verificar se já existe controle para essa UC na proposta
            $controleExistente = ControleClube::where('proposta_id', $request->proposta_id)
                                            ->where('uc_id', $request->uc_id)
                                            ->first();

            if ($controleExistente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Já existe um controle para esta UC nesta proposta'
                ], 409);
            }

            // Criar controle
            $controle = new ControleClube();
            $controle->id = (string) Str::uuid();
            $controle->proposta_id = $request->proposta_id;
            $controle->uc_id = $request->uc_id;
            $controle->ug_id = $request->ug_id;
            $controle->calibragem = $request->calibragem ?? 0.00;
            $controle->valor_calibrado = $request->valor_calibrado;
            $controle->observacoes = $request->observacoes;
            $controle->data_entrada_controle = $request->data_entrada_controle ?? now();

            $controle->save();

            // Carregar relacionamentos para resposta
            $controle->load(['proposta', 'unidadeConsumidora', 'unidadeGeradora']);

            DB::commit();

            $controleFormatado = [
                'id' => $controle->id,
                'proposta_id' => $controle->proposta_id,
                'uc_id' => $controle->uc_id,
                'ug_id' => $controle->ug_id,
                'calibragem' => $controle->calibragem,
                'valor_calibrado' => $controle->valor_calibrado,
                'observacoes' => $controle->observacoes,
                'data_entrada_controle' => $controle->data_entrada_controle,
                'created_at' => $controle->created_at,
                'updated_at' => $controle->updated_at,
                
                'proposta' => $controle->proposta ? [
                    'id' => $controle->proposta->id,
                    'numero_proposta' => $controle->proposta->numero_proposta,
                    'nome_cliente' => $controle->proposta->nome_cliente
                ] : null,
                
                'unidade_consumidora' => $controle->unidadeConsumidora ? [
                    'id' => $controle->unidadeConsumidora->id,
                    'numero_unidade' => $controle->unidadeConsumidora->numero_unidade,
                    'apelido' => $controle->unidadeConsumidora->apelido
                ] : null
            ];

            Log::info('Controle criado com sucesso', [
                'controle_id' => $controle->id,
                'proposta_id' => $controle->proposta_id,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Controle criado com sucesso',
                'data' => $controleFormatado
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao criar controle', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'request_data' => $request->all(),
                'user_id' => $currentUser->id ?? 'desconhecido'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor',
                'debug' => config('app.debug') ? [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => basename($e->getFile())
                ] : null
            ], 500);
        }
    }

    /**
     * ✅ EXIBIR UM CONTROLE ESPECÍFICO
     */
    public function show(string $id): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $controle = ControleClube::with(['proposta', 'unidadeConsumidora', 'unidadeGeradora'])
                                   ->find($id);

            if (!$controle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle não encontrado'
                ], 404);
            }

            // Verificar permissão
            if ($currentUser->role === 'vendedor' && 
                $controle->proposta && 
                $controle->proposta->usuario_id !== $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sem permissão para ver este controle'
                ], 403);
            }

            $controleFormatado = [
                'id' => $controle->id,
                'proposta_id' => $controle->proposta_id,
                'uc_id' => $controle->uc_id,
                'ug_id' => $controle->ug_id,
                'calibragem' => $controle->calibragem,
                'valor_calibrado' => $controle->valor_calibrado,
                'observacoes' => $controle->observacoes,
                'data_entrada_controle' => $controle->data_entrada_controle,
                'created_at' => $controle->created_at,
                'updated_at' => $controle->updated_at,
                
                'proposta' => $controle->proposta ? [
                    'id' => $controle->proposta->id,
                    'numero_proposta' => $controle->proposta->numero_proposta,
                    'nome_cliente' => $controle->proposta->nome_cliente,
                    'consultor' => $controle->proposta->consultor,
                    'status' => $controle->proposta->status
                ] : null,
                
                'unidade_consumidora' => $controle->unidadeConsumidora ? [
                    'id' => $controle->unidadeConsumidora->id,
                    'numero_unidade' => $controle->unidadeConsumidora->numero_unidade,
                    'apelido' => $controle->unidadeConsumidora->apelido,
                    'consumo_medio' => $controle->unidadeConsumidora->consumo_medio
                ] : null,
                
                'unidade_geradora' => $controle->unidadeGeradora ? [
                    'id' => $controle->unidadeGeradora->id,
                    'nome_usina' => $controle->unidadeGeradora->nome_usina,
                    'potencia_cc' => $controle->unidadeGeradora->potencia_cc
                ] : null
            ];

            return response()->json([
                'success' => true,
                'data' => $controleFormatado
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao carregar controle', [
                'controle_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => $currentUser->id ?? 'desconhecido'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * ✅ ATUALIZAR CONTROLE
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $controle = ControleClube::find($id);

            if (!$controle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle não encontrado'
                ], 404);
            }

            // Verificar permissão
            if ($currentUser->role === 'vendedor') {
                $proposta = Proposta::find($controle->proposta_id);
                if (!$proposta || $proposta->usuario_id !== $currentUser->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Sem permissão para editar este controle'
                    ], 403);
                }
            }

            // Validação
            $validator = Validator::make($request->all(), [
                'ug_id' => 'sometimes|nullable|string|exists:unidades_consumidoras,id',
                'calibragem' => 'sometimes|nullable|numeric|min:-100|max:100',
                'valor_calibrado' => 'sometimes|nullable|numeric|min:0',
                'observacoes' => 'sometimes|nullable|string|max:1000',
                'data_entrada_controle' => 'sometimes|nullable|date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Atualizar campos
            if ($request->has('ug_id')) $controle->ug_id = $request->ug_id;
            if ($request->has('calibragem')) $controle->calibragem = $request->calibragem ?? 0.00;
            if ($request->has('valor_calibrado')) $controle->valor_calibrado = $request->valor_calibrado;
            if ($request->has('observacoes')) $controle->observacoes = $request->observacoes;
            if ($request->has('data_entrada_controle')) $controle->data_entrada_controle = $request->data_entrada_controle;

            $controle->save();

            // Carregar relacionamentos
            $controle->load(['proposta', 'unidadeConsumidora', 'unidadeGeradora']);

            DB::commit();

            $controleFormatado = [
                'id' => $controle->id,
                'proposta_id' => $controle->proposta_id,
                'uc_id' => $controle->uc_id,
                'ug_id' => $controle->ug_id,
                'calibragem' => $controle->calibragem,
                'valor_calibrado' => $controle->valor_calibrado,
                'observacoes' => $controle->observacoes,
                'data_entrada_controle' => $controle->data_entrada_controle,
                'created_at' => $controle->created_at,
                'updated_at' => $controle->updated_at
            ];

            Log::info('Controle atualizado com sucesso', [
                'controle_id' => $controle->id,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Controle atualizado com sucesso',
                'data' => $controleFormatado
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao atualizar controle', [
                'controle_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => $currentUser->id ?? 'desconhecido'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * ✅ EXCLUIR CONTROLE
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $controle = ControleClube::find($id);

            if (!$controle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle não encontrado'
                ], 404);
            }

            // Verificar permissão (apenas admin ou dono da proposta)
            if ($currentUser->role !== 'admin') {
                $proposta = Proposta::find($controle->proposta_id);
                if (!$proposta || $proposta->usuario_id !== $currentUser->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Sem permissão para excluir este controle'
                    ], 403);
                }
            }

            // Soft delete
            $controle->deleted_at = now();
            $controle->save();

            Log::info('Controle excluído', [
                'controle_id' => $controle->id,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Controle excluído com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao excluir controle', [
                'controle_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => $currentUser->id ?? 'desconhecido'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * ✅ ESTATÍSTICAS DO CONTROLE
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $query = ControleClube::query();

            // Filtro por role
            if ($currentUser->role === 'vendedor') {
                $query->whereHas('proposta', function ($q) use ($currentUser) {
                    $q->where('usuario_id', $currentUser->id);
                });
            } elseif ($currentUser->role === 'gerente') {
                $teamIds = collect([$currentUser->id]);
                if (method_exists($currentUser, 'team')) {
                    $teamIds = $teamIds->merge($currentUser->team()->pluck('id'));
                }
                $query->whereHas('proposta', function ($q) use ($teamIds) {
                    $q->whereIn('usuario_id', $teamIds);
                });
            }

            $totalControles = $query->count();
            $controlesComCalibragem = $query->where('calibragem', '!=', 0)->count();
            $controlesComUG = $query->whereNotNull('ug_id')->count();
            $mediaCalibragem = $query->avg('calibragem') ?? 0;

            $estatisticas = [
                'total_controles' => $totalControles,
                'controles_com_calibragem' => $controlesComCalibragem,
                'controles_com_ug' => $controlesComUG,
                'media_calibragem' => round($mediaCalibragem, 2),
                'percentual_com_calibragem' => $totalControles > 0 ? 
                    round(($controlesComCalibragem / $totalControles) * 100, 2) : 0,
                'percentual_com_ug' => $totalControles > 0 ? 
                    round(($controlesComUG / $totalControles) * 100, 2) : 0
            ];

            return response()->json([
                'success' => true,
                'data' => $estatisticas
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar estatísticas do controle', [
                'error' => $e->getMessage(),
                'user_id' => $currentUser->id ?? 'desconhecido'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }
}