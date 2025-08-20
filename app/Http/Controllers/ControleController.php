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
     * ✅ LISTAR CONTROLES COM FILTROS E PAGINAÇÃO + DADOS EXPANDIDOS
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

            // Query base com relacionamentos EXPANDIDOS
            $query = ControleClube::with([
                'proposta' => function($q) {
                    $q->select(['id', 'numero_proposta', 'nome_cliente', 'consultor', 'data_proposta', 'usuario_id']);
                },
                'unidadeConsumidora' => function($q) {
                    $q->select(['id', 'numero_unidade', 'apelido', 'consumo_medio', 'distribuidora', 'ligacao']);
                },
                'unidadeGeradora' => function($q) {
                    $q->where('is_ug', true)
                      ->select(['id', 'nome_usina', 'potencia_cc', 'capacidade_calculada']);
                }
            ]);

            // ✅ FILTROS HIERÁRQUICOS BASEADOS NO ROLE
            if ($currentUser->role === 'vendedor') {
                // Vendedor vê apenas seus próprios controles
                $query->whereHas('proposta', function ($q) use ($currentUser) {
                    $q->where('usuario_id', $currentUser->id);
                });
            } elseif ($currentUser->role === 'gerente') {
                // Gerente vê controles da sua equipe
                $teamIds = collect([$currentUser->id]);
                if (method_exists($currentUser, 'team')) {
                    $teamIds = $teamIds->merge($currentUser->team->pluck('id'));
                }
                
                $query->whereHas('proposta', function ($q) use ($teamIds) {
                    $q->whereIn('usuario_id', $teamIds->toArray());
                });
            } elseif ($currentUser->role === 'consultor') {
                // Consultor vê controles de toda sua hierarquia
                $subordinadosIds = [];
                if (method_exists($currentUser, 'getAllSubordinates')) {
                    $subordinadosIds = array_column($currentUser->getAllSubordinates(), 'id');
                }
                $usuariosPermitidos = array_merge([$currentUser->id], $subordinadosIds);
                
                $query->whereHas('proposta', function ($q) use ($usuariosPermitidos) {
                    $q->whereIn('usuario_id', $usuariosPermitidos);
                });
            }
            // Admin vê todos - sem filtro

            // Filtros opcionais
            if ($request->filled('proposta_id')) {
                $query->where('proposta_id', $request->proposta_id);
            }

            if ($request->filled('uc_id')) {
                $query->where('uc_id', $request->uc_id);
            }

            if ($request->filled('ug_id')) {
                if ($request->ug_id === 'null' || $request->ug_id === 'sem-ug') {
                    $query->whereNull('ug_id');
                } else {
                    $query->where('ug_id', $request->ug_id);
                }
            }

            if ($request->filled('consultor')) {
                $query->whereHas('proposta', function ($q) use ($request) {
                    $q->where('consultor', 'ILIKE', '%' . $request->consultor . '%');
                });
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('proposta', function ($subQ) use ($search) {
                        $subQ->where('nome_cliente', 'ILIKE', "%{$search}%")
                             ->orWhere('numero_proposta', 'ILIKE', "%{$search}%");
                    })->orWhereHas('unidadeConsumidora', function ($subQ) use ($search) {
                        $subQ->where('numero_unidade', 'ILIKE', "%{$search}%")
                             ->orWhere('apelido', 'ILIKE', "%{$search}%");
                    });
                });
            }

            // Ordenação
            $orderBy = $request->get('sort_by', 'created_at');
            $orderDirection = in_array($request->get('sort_direction', 'desc'), ['asc', 'desc']) 
                ? $request->get('sort_direction', 'desc') 
                : 'desc';

            $query->orderBy($orderBy, $orderDirection);

            // Executar paginação
            $total = $query->count();
            $controles = $query->offset(($page - 1) * $perPage)
                              ->limit($perPage)
                              ->get();

            // ✅ FORMATAR DADOS PARA O FRONTEND (DADOS EXPANDIDOS)
            $controlesFormatados = $controles->map(function ($controle) {
                // Buscar nome da UG se existir
                $nomeUG = null;
                if ($controle->unidadeGeradora) {
                    $nomeUG = $controle->unidadeGeradora->nome_usina;
                } elseif ($controle->ug_id) {
                    // Fallback se o relacionamento não carregou
                    $ug = UnidadeConsumidora::where('id', $controle->ug_id)->where('is_ug', true)->first();
                    $nomeUG = $ug ? $ug->nome_usina : null;
                }

                return [
                    // ✅ DADOS DO CONTROLE
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
                    
                    // ✅ DADOS EXPANDIDOS DA PROPOSTA (CAMPOS NECESSÁRIOS PARA O FRONTEND)
                    'numeroProposta' => $controle->proposta?->numero_proposta ?? 'N/A',
                    'nomeCliente' => $controle->proposta?->nome_cliente ?? 'N/A',
                    'consultor' => $controle->proposta?->consultor ?? 'N/A',
                    'dataEntrada' => $controle->data_entrada_controle ?? $controle->created_at,
                    
                    // ✅ DADOS EXPANDIDOS DA UC (CAMPOS NECESSÁRIOS PARA O FRONTEND)
                    'numeroUC' => $controle->unidadeConsumidora?->numero_unidade ?? 'N/A',
                    'apelido' => $controle->unidadeConsumidora?->apelido ?? 'N/A',
                    'media' => $controle->unidadeConsumidora?->consumo_medio ?? 0,
                    'distribuidora' => $controle->unidadeConsumidora?->distribuidora ?? 'N/A',
                    'ligacao' => $controle->unidadeConsumidora?->ligacao ?? 'N/A',
                    
                    // ✅ DADOS EXPANDIDOS DA UG (SE EXISTIR)
                    'ug' => $nomeUG,
                    'ug_nome' => $nomeUG, // Alias para compatibilidade
                    'potenciaUG' => $controle->unidadeGeradora?->potencia_cc ?? null,
                    'capacidadeUG' => $controle->unidadeGeradora?->capacidade_calculada ?? null,
                    
                    // ✅ DADOS CALCULADOS
                    'valorCalibrado' => $this->calcularValorCalibrado(
                        $controle->unidadeConsumidora?->consumo_medio ?? 0,
                        $controle->calibragem ?? 0
                    ),
                    
                    // ✅ COMPATIBILIDADE COM FRONTEND ANTIGO
                    'celular' => null, // Pode ser adicionado se existir no modelo
                ];
            });

            Log::info('Controle clube carregado com sucesso', [
                'total' => $total,
                'returned' => $controlesFormatados->count(),
                'page' => $page,
                'per_page' => $perPage,
                'user_id' => $currentUser->id,
                'user_role' => $currentUser->role
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
                    'to' => min($page * $perPage, $total),
                    'has_more_pages' => $page < ceil($total / $perPage)
                ],
                'filters' => [
                    'proposta_id' => $request->proposta_id,
                    'uc_id' => $request->uc_id,
                    'ug_id' => $request->ug_id,
                    'consultor' => $request->consultor,
                    'search' => $request->search
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao carregar controle clube', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'user_id' => $currentUser->id ?? 'desconhecido',
                'request_data' => $request->all()
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
     * ✅ CALCULAR VALOR CALIBRADO
     */
    private function calcularValorCalibrado($media, $calibragem)
    {
        if (!$media || !$calibragem || $calibragem == 0) {
            return 0;
        }
        
        $mediaNum = floatval($media);
        $calibragemNum = floatval($calibragem);
        
        return $mediaNum * (1 + ($calibragemNum / 100));
    }

    /**
     * ✅ CRIAR NOVO CONTROLE
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

        // Validação
        $validator = Validator::make($request->all(), [
            'proposta_id' => 'required|string|exists:propostas,id',
            'uc_id' => 'required|string|exists:unidades_consumidoras,id',
            'ug_id' => 'nullable|string|exists:unidades_consumidoras,id',
            'calibragem' => 'nullable|numeric|min:0|max:100',
            'observacoes' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados de entrada inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Verificar se já existe controle para esta UC/Proposta
            $existeControle = ControleClube::where('proposta_id', $request->proposta_id)
                                         ->where('uc_id', $request->uc_id)
                                         ->first();

            if ($existeControle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Já existe controle para esta UC/Proposta'
                ], 409);
            }

            // Calcular valor calibrado
            $uc = UnidadeConsumidora::find($request->uc_id);
            $valorCalibrado = null;
            if ($request->calibragem && $uc && $uc->consumo_medio) {
                $valorCalibrado = $this->calcularValorCalibrado($uc->consumo_medio, $request->calibragem);
            }

            // Criar controle
            $controle = ControleClube::create([
                'id' => Str::ulid(),
                'proposta_id' => $request->proposta_id,
                'uc_id' => $request->uc_id,
                'ug_id' => $request->ug_id,
                'calibragem' => $request->calibragem,
                'valor_calibrado' => $valorCalibrado,
                'observacoes' => $request->observacoes,
                'data_entrada_controle' => now()
            ]);

            DB::commit();

            // Carregar com relacionamentos para resposta
            $controle->load(['proposta', 'unidadeConsumidora', 'unidadeGeradora']);

            $controleFormatado = [
                'id' => $controle->id,
                'proposta_id' => $controle->proposta_id,
                'uc_id' => $controle->uc_id,
                'ug_id' => $controle->ug_id,
                'calibragem' => $controle->calibragem,
                'valor_calibrado' => $controle->valor_calibrado,
                'observacoes' => $controle->observacoes,
                'data_entrada_controle' => $controle->data_entrada_controle,
                
                'proposta' => $controle->proposta ? [
                    'id' => $controle->proposta->id,
                    'numero_proposta' => $controle->proposta->numero_proposta,
                    'nome_cliente' => $controle->proposta->nome_cliente,
                    'consultor' => $controle->proposta->consultor
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
                'user_id' => $currentUser->id
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
     * ✅ ATUALIZAR CONTROLE (principalmente para UG e calibragem)
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

        // Validação
        $validator = Validator::make($request->all(), [
            'ug_id' => 'nullable|string|exists:unidades_consumidoras,id',
            'calibragem' => 'nullable|numeric|min:0|max:100',
            'observacoes' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados de entrada inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $controle = ControleClube::with(['proposta', 'unidadeConsumidora'])
                                   ->find($id);

            if (!$controle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle não encontrado'
                ], 404);
            }

            // Verificar permissões
            if ($currentUser->role === 'vendedor' && 
                $controle->proposta && 
                $controle->proposta->usuario_id !== $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sem permissão para editar este controle'
                ], 403);
            }

            // Atualizar campos
            if ($request->has('ug_id')) {
                $controle->ug_id = $request->ug_id;
            }

            if ($request->has('calibragem')) {
                $controle->calibragem = $request->calibragem;
                
                // Recalcular valor calibrado
                if ($controle->unidadeConsumidora && $controle->unidadeConsumidora->consumo_medio) {
                    $controle->valor_calibrado = $this->calcularValorCalibrado(
                        $controle->unidadeConsumidora->consumo_medio,
                        $request->calibragem
                    );
                }
            }

            if ($request->has('observacoes')) {
                $controle->observacoes = $request->observacoes;
            }

            $controle->save();

            DB::commit();

            Log::info('Controle atualizado com sucesso', [
                'controle_id' => $controle->id,
                'changes' => $controle->getChanges(),
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Controle atualizado com sucesso',
                'data' => [
                    'id' => $controle->id,
                    'ug_id' => $controle->ug_id,
                    'calibragem' => $controle->calibragem,
                    'valor_calibrado' => $controle->valor_calibrado,
                    'observacoes' => $controle->observacoes,
                    'updated_at' => $controle->updated_at
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao atualizar controle', [
                'controle_id' => $id,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'user_id' => $currentUser->id
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
                    'consultor' => $controle->proposta->consultor
                ] : null,
                                
                'unidade_consumidora' => $controle->unidadeConsumidora ? [
                    'id' => $controle->unidadeConsumidora->id,
                    'numero_unidade' => $controle->unidadeConsumidora->numero_unidade,
                    'apelido' => $controle->unidadeConsumidora->apelido,
                    'consumo_medio' => $controle->unidadeConsumidora->consumo_medio,
                    'distribuidora' => $controle->unidadeConsumidora->distribuidora
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
     * ✅ REMOVER CONTROLE
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

            $controle = ControleClube::with('proposta')->find($id);

            if (!$controle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle não encontrado'
                ], 404);
            }

            // Verificar permissões (apenas admin pode deletar)
            if ($currentUser->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Apenas administradores podem excluir controles'
                ], 403);
            }

            $controle->delete();

            Log::info('Controle excluído com sucesso', [
                'controle_id' => $id,
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
}