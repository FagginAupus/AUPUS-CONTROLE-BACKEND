<?php

namespace App\Http\Controllers;

use App\Models\Proposta;
use App\Models\UnidadeConsumidora;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\ValidationException;

class PropostaController extends Controller
{
    /**
     * ✅ LISTAR PROPOSTAS COM FILTROS E PAGINAÇÃO
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

            Log::info('Carregando propostas', [
                'user_id' => $currentUser->id,
                'user_role' => $currentUser->role,
                'filters' => $request->all()
            ]);

            // Parâmetros de paginação
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 50);

            // Query base
            $query = Proposta::query();

            // Filtros baseados no role do usuário
            if ($currentUser->role === 'vendedor') {
                // Vendedor vê apenas suas próprias propostas
                $query->where('usuario_id', $currentUser->id);
            } elseif ($currentUser->role === 'gerente') {
                // Gerente vê propostas de sua equipe
                $teamUserIds = DB::table('usuarios')
                              ->where('manager_id', $currentUser->id)
                              ->pluck('id')
                              ->toArray();
                $teamUserIds[] = $currentUser->id;
                $query->whereIn('usuario_id', $teamUserIds);
            }
            // Admin e consultor veem todas as propostas (sem filtro adicional)

            // Filtros opcionais
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where(function($q) use ($search) {
                    $q->where('nome_cliente', 'ILIKE', "%{$search}%")
                      ->orWhere('numero_proposta', 'ILIKE', "%{$search}%")
                      ->orWhere('apelido', 'ILIKE', "%{$search}%")
                      ->orWhere('numero_uc', 'LIKE', "%{$search}%");
                });
            }

            if ($request->filled('consultor')) {
                $query->where('consultor', 'ILIKE', "%{$request->get('consultor')}%");
            }

            if ($request->filled('status')) {
                $query->where('status', $request->get('status'));
            }

            if ($request->filled('distribuidora')) {
                $query->where('distribuidora', $request->get('distribuidora'));
            }

            if ($request->filled('data_inicio')) {
                $query->where('data_proposta', '>=', $request->get('data_inicio'));
            }

            if ($request->filled('data_fim')) {
                $query->where('data_proposta', '<=', $request->get('data_fim'));
            }

            // Ordenação
            $sortBy = $request->get('sort_by', 'created_at');
            $sortDirection = $request->get('sort_direction', 'desc');
            
            $allowedSorts = ['created_at', 'data_proposta', 'nome_cliente', 'numero_proposta', 'status', 'consultor'];
            if (!in_array($sortBy, $allowedSorts)) {
                $sortBy = 'created_at';
            }
            
            $query->orderBy($sortBy, $sortDirection);

            // Executar query com paginação
            $totalRecords = $query->count();
            $propostas = $query->offset(($page - 1) * $perPage)
                              ->limit($perPage)
                              ->get();

            // Formatar dados
            $propostasFormatadas = $propostas->map(function($proposta) {
                return [
                    'id' => $proposta->id,
                    'numeroProposta' => $proposta->numero_proposta,
                    'nomeCliente' => $proposta->nome_cliente,
                    'consultor' => $proposta->consultor,
                    'data' => $proposta->data_proposta,
                    'status' => $proposta->status,
                    'economia' => $proposta->economia,
                    'bandeira' => $proposta->bandeira,
                    'recorrencia' => $proposta->recorrencia,
                    'observacoes' => $proposta->observacoes,
                    'beneficios' => is_string($proposta->beneficios) ? 
                        json_decode($proposta->beneficios, true) : 
                        ($proposta->beneficios ?: []),
                    'unidades_consumidoras' => is_string($proposta->unidades_consumidoras) ?
                        json_decode($proposta->unidades_consumidoras, true) :
                        ($proposta->unidades_consumidoras ?: []),
                    // Campos de busca rápida
                    'numeroUC' => $proposta->numero_uc,
                    'apelido' => $proposta->apelido,
                    'mediaConsumo' => $proposta->media_consumo,
                    'ligacao' => $proposta->ligacao,
                    'distribuidora' => $proposta->distribuidora,
                    'created_at' => $proposta->created_at,
                    'updated_at' => $proposta->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $propostasFormatadas,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total_records' => $totalRecords,
                    'total_pages' => ceil($totalRecords / $perPage),
                    'has_next' => ($page * $perPage) < $totalRecords,
                    'has_previous' => $page > 1
                ],
                'filters' => [
                    'search' => $request->get('search'),
                    'consultor' => $request->get('consultor'),
                    'status' => $request->get('status'),
                    'distribuidora' => $request->get('distribuidora'),
                    'data_inicio' => $request->get('data_inicio'),
                    'data_fim' => $request->get('data_fim')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar propostas', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
     * ✅ MÉTODO STORE COMPLETAMENTE CORRIGIDO - Criar nova proposta
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

            Log::info('Dados recebidos para criar proposta', [
                'user_id' => $currentUser->id,
                'request_data' => $request->all()
            ]);

            // ✅ VALIDAÇÃO CORRIGIDA E COMPLETA
            $validator = Validator::make($request->all(), [
                // ✅ CAMPOS OBRIGATÓRIOS BÁSICOS
                'nome_cliente' => 'required|string|min:3|max:200',
                'consultor' => 'required|string|max:100',
                'data_proposta' => 'required|date',
                
                // ✅ CAMPOS OPCIONAIS
                'numero_proposta' => 'nullable|string|max:50|unique:propostas,numero_proposta',
                'economia' => 'nullable|numeric|min:0|max:100',
                'bandeira' => 'nullable|numeric|min:0|max:100',
                'recorrencia' => 'nullable|string|max:50',
                'observacoes' => 'nullable|string|max:1000',
                'beneficios' => 'nullable|array',
                
                // ✅ UNIDADES CONSUMIDORAS - VALIDAÇÃO CORRIGIDA
                'unidades_consumidoras' => 'nullable|array|min:1', // ✅ Pelo menos 1 UC se presente
                'unidades_consumidoras.*.numero_unidade' => 'required_with:unidades_consumidoras|integer|min:1',
                'unidades_consumidoras.*.apelido' => 'required_with:unidades_consumidoras|string|min:1|max:100',
                'unidades_consumidoras.*.consumo_medio' => 'required_with:unidades_consumidoras|numeric|min:0',
                'unidades_consumidoras.*.ligacao' => 'nullable|string|max:50',
                'unidades_consumidoras.*.distribuidora' => 'nullable|string|max:100',
            ], [
                // ✅ MENSAGENS PERSONALIZADAS
                'nome_cliente.required' => 'Nome do cliente é obrigatório',
                'nome_cliente.min' => 'Nome do cliente deve ter pelo menos 3 caracteres',
                'consultor.required' => 'Consultor é obrigatório',
                'data_proposta.required' => 'Data da proposta é obrigatória',
                'unidades_consumidoras.min' => 'Pelo menos uma unidade consumidora deve ser informada',
                'unidades_consumidoras.*.numero_unidade.required_with' => 'Número da unidade consumidora é obrigatório',
                'unidades_consumidoras.*.numero_unidade.min' => 'Número da unidade deve ser maior que 0',
                'unidades_consumidoras.*.apelido.required_with' => 'Apelido da unidade consumidora é obrigatório',
                'unidades_consumidoras.*.consumo_medio.required_with' => 'Consumo médio é obrigatório',
                'unidades_consumidoras.*.consumo_medio.min' => 'Consumo médio deve ser maior ou igual a 0',
            ]);

            if ($validator->fails()) {
                Log::warning('Validação falhou ao criar proposta', [
                    'errors' => $validator->errors(),
                    'request_data' => $request->all()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors(),
                    'debug' => [
                        'received_fields' => array_keys($request->all()),
                        'first_error' => $validator->errors()->first()
                    ]
                ], 422);
            }

            DB::beginTransaction();

            // ✅ GERAR NÚMERO DA PROPOSTA SE NÃO FORNECIDO
            $numeroProposta = $request->numero_proposta ?: $this->gerarNumeroProposta();

            // ✅ GARANTIR QUE O NÚMERO É ÚNICO
            $tentativas = 0;
            while (Proposta::where('numero_proposta', $numeroProposta)->exists() && $tentativas < 10) {
                $numeroProposta = $this->gerarNumeroProposta();
                $tentativas++;
            }

            // ✅ PROCESSAR UNIDADES CONSUMIDORAS
            $unidadesConsumidoras = $request->unidades_consumidoras ?: [];
            $primeiraUC = null;
            
            if (!empty($unidadesConsumidoras)) {
                $primeiraUC = $unidadesConsumidoras[0];
                
                // ✅ VALIDAR CADA UC INDIVIDUALMENTE
                foreach ($unidadesConsumidoras as $index => $ucData) {
                    if (!isset($ucData['numero_unidade']) || !$ucData['numero_unidade']) {
                        throw new \InvalidArgumentException("UC " . ($index + 1) . ": Número da unidade é obrigatório");
                    }
                    if (!isset($ucData['apelido']) || !trim($ucData['apelido'])) {
                        throw new \InvalidArgumentException("UC " . ($index + 1) . ": Apelido é obrigatório");
                    }
                    if (!isset($ucData['consumo_medio']) || $ucData['consumo_medio'] < 0) {
                        throw new \InvalidArgumentException("UC " . ($index + 1) . ": Consumo médio deve ser maior ou igual a 0");
                    }
                }
            }

            // ✅ CRIAR PROPOSTA COM TODOS OS CAMPOS
            $proposta = new Proposta([
                'id' => (string) Str::uuid(),
                'numero_proposta' => $numeroProposta,
                'nome_cliente' => trim($request->nome_cliente),
                'consultor' => trim($request->consultor),
                'data_proposta' => $request->data_proposta,
                'usuario_id' => $currentUser->id,
                'economia' => $request->economia ?? 20.00,
                'bandeira' => $request->bandeira ?? 20.00,
                'recorrencia' => $request->recorrencia ?? '3%',
                'status' => 'Em Análise',
                'observacoes' => $request->observacoes ? trim($request->observacoes) : null,
                'beneficios' => $request->beneficios ? json_encode($request->beneficios) : null,
                
                // ✅ UNIDADES CONSUMIDORAS (JSON COMPLETO)
                'unidades_consumidoras' => !empty($unidadesConsumidoras) ? json_encode($unidadesConsumidoras) : null,
                
                // ✅ CAMPOS DERIVADOS DA PRIMEIRA UC PARA BUSCA RÁPIDA
                'numero_uc' => $primeiraUC['numero_unidade'] ?? null,
                'apelido' => $primeiraUC['apelido'] ?? null,
                'media_consumo' => $primeiraUC['consumo_medio'] ?? null,
                'ligacao' => $primeiraUC['ligacao'] ?? null,
                'distribuidora' => $primeiraUC['distribuidora'] ?? 'CEMIG',
            ]);

            $proposta->save();

            // ✅ CRIAR/ATUALIZAR UNIDADES CONSUMIDORAS NA TABELA DEDICADA
            if (!empty($unidadesConsumidoras)) {
                foreach ($unidadesConsumidoras as $ucData) {
                    try {
                        // Verificar se UC já existe
                        $ucExistente = UnidadeConsumidora::where('numero_unidade', $ucData['numero_unidade'])
                                                         ->where('numero_cliente', $ucData['numero_unidade'])
                                                         ->first();

                        if (!$ucExistente) {
                            // Criar nova UC
                            UnidadeConsumidora::create([
                                'id' => (string) Str::uuid(),
                                'usuario_id' => $currentUser->id,
                                'proposta_id' => $proposta->id,
                                'numero_cliente' => intval($ucData['numero_unidade']),
                                'numero_unidade' => intval($ucData['numero_unidade']),
                                'apelido' => trim($ucData['apelido']),
                                'consumo_medio' => floatval($ucData['consumo_medio']),
                                'ligacao' => $ucData['ligacao'] ?? 'MONOFÁSICA',
                                'distribuidora' => $ucData['distribuidora'] ?? 'CEMIG',
                                'tipo' => 'UC',
                                'is_ug' => false,
                                'nexus_clube' => false,
                                'nexus_cativo' => false,
                                'service' => false,
                                'project' => false,
                                'gerador' => false,
                                'mesmo_titular' => true,
                            ]);
                        } else {
                            // Atualizar UC existente
                            $ucExistente->update([
                                'proposta_id' => $proposta->id,
                                'apelido' => trim($ucData['apelido']),
                                'consumo_medio' => floatval($ucData['consumo_medio']),
                                'ligacao' => $ucData['ligacao'] ?? $ucExistente->ligacao,
                                'distribuidora' => $ucData['distribuidora'] ?? $ucExistente->distribuidora,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Erro ao processar UC', [
                            'proposta_id' => $proposta->id,
                            'uc_data' => $ucData,
                            'error' => $e->getMessage()
                        ]);
                        // Continuar processamento mesmo se uma UC falhar
                    }
                }
            }

            DB::commit();

            // ✅ RETORNAR DADOS FORMATADOS
            $propostaFormatada = [
                'id' => $proposta->id,
                'numeroProposta' => $proposta->numero_proposta,
                'nomeCliente' => $proposta->nome_cliente,
                'consultor' => $proposta->consultor,
                'data' => $proposta->data_proposta,
                'status' => $proposta->status,
                'economia' => $proposta->economia,
                'bandeira' => $proposta->bandeira,
                'recorrencia' => $proposta->recorrencia,
                'observacoes' => $proposta->observacoes,
                'beneficios' => is_string($proposta->beneficios) ? 
                    json_decode($proposta->beneficios, true) : 
                    ($proposta->beneficios ?: []),
                'unidades_consumidoras' => is_string($proposta->unidades_consumidoras) ?
                    json_decode($proposta->unidades_consumidoras, true) :
                    ($proposta->unidades_consumidoras ?: []),
                'distribuidora' => $proposta->distribuidora,
                'created_at' => $proposta->created_at,
                'updated_at' => $proposta->updated_at,
            ];

            Log::info('Proposta criada com sucesso', [
                'proposta_id' => $proposta->id,
                'numero_proposta' => $proposta->numero_proposta,
                'user_id' => $currentUser->id,
                'total_ucs' => count($unidadesConsumidoras)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proposta criada com sucesso',
                'data' => $propostaFormatada
            ], 201);

        } catch (\InvalidArgumentException $e) {
            DB::rollBack();
            
            Log::warning('Erro de validação ao criar proposta', [
                'error' => $e->getMessage(),
                'user_id' => $currentUser->id ?? 'desconhecido'
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao criar proposta', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'request_data' => $request->all(),
                'user_id' => $currentUser->id ?? 'desconhecido'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor',
                'debug' => config('app.debug') ? [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => basename($e->getFile()),
                    'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 5)
                ] : null
            ], 500);
        }
    }

    /**
     * ✅ MOSTRAR UMA PROPOSTA ESPECÍFICA
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

            $proposta = Proposta::find($id);

            if (!$proposta) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proposta não encontrada'
                ], 404);
            }

            // Verificar permissão
            if ($currentUser->role === 'vendedor' && $proposta->usuario_id !== $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sem permissão para acessar esta proposta'
                ], 403);
            }

            // Formatar dados
            $propostaFormatada = [
                'id' => $proposta->id,
                'numeroProposta' => $proposta->numero_proposta,
                'nomeCliente' => $proposta->nome_cliente,
                'consultor' => $proposta->consultor,
                'data' => $proposta->data_proposta,
                'status' => $proposta->status,
                'economia' => $proposta->economia,
                'bandeira' => $proposta->bandeira,
                'recorrencia' => $proposta->recorrencia,
                'observacoes' => $proposta->observacoes,
                'beneficios' => is_string($proposta->beneficios) ? 
                    json_decode($proposta->beneficios, true) : 
                    ($proposta->beneficios ?: []),
                'unidades_consumidoras' => is_string($proposta->unidades_consumidoras) ?
                    json_decode($proposta->unidades_consumidoras, true) :
                    ($proposta->unidades_consumidoras ?: []),
                'distribuidora' => $proposta->distribuidora,
                'created_at' => $proposta->created_at,
                'updated_at' => $proposta->updated_at,
            ];

            return response()->json([
                'success' => true,
                'data' => $propostaFormatada
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar proposta', [
                'proposta_id' => $id,
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
     * ✅ ATUALIZAR UMA PROPOSTA
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

            $proposta = Proposta::find($id);

            if (!$proposta) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proposta não encontrada'
                ], 404);
            }

            // Verificar permissão
            if ($currentUser->role === 'vendedor' && $proposta->usuario_id !== $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sem permissão para editar esta proposta'
                ], 403);
            }

            // Validação
            $validator = Validator::make($request->all(), [
                'nome_cliente' => 'required|string|min:3|max:200',
                'consultor' => 'required|string|max:100',
                'data_proposta' => 'required|date',
                'numero_proposta' => 'nullable|string|max:50|unique:propostas,numero_proposta,' . $id,
                'economia' => 'nullable|numeric|min:0|max:100',
                'bandeira' => 'nullable|numeric|min:0|max:100',
                'recorrencia' => 'nullable|string|max:50',
                'observacoes' => 'nullable|string|max:1000',
                'beneficios' => 'nullable|array',
                'status' => 'nullable|string|in:Em Análise,Aguardando,Fechado,Perdido,Cancelado',
                'unidades_consumidoras' => 'nullable|array',
                'unidades_consumidoras.*.numero_unidade' => 'required_with:unidades_consumidoras|integer|min:1',
                'unidades_consumidoras.*.apelido' => 'required_with:unidades_consumidoras|string|min:1|max:100',
                'unidades_consumidoras.*.consumo_medio' => 'required_with:unidades_consumidoras|numeric|min:0',
                'unidades_consumidoras.*.ligacao' => 'nullable|string|max:50',
                'unidades_consumidoras.*.distribuidora' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Dados para atualização
            $dadosAtualizacao = [
                'nome_cliente' => $request->nome_cliente,
                'consultor' => $request->consultor,
                'data_proposta' => $request->data_proposta,
                'numero_proposta' => $request->numero_proposta ?: $proposta->numero_proposta,
                'economia' => $request->economia ?? $proposta->economia,
                'bandeira' => $request->bandeira ?? $proposta->bandeira,
                'recorrencia' => $request->recorrencia ?? $proposta->recorrencia,
                'status' => $request->status ?? $proposta->status,
                'observacoes' => $request->observacoes,
                'beneficios' => $request->beneficios ? json_encode($request->beneficios) : $proposta->beneficios,
                'unidades_consumidoras' => $request->unidades_consumidoras ? 
                    json_encode($request->unidades_consumidoras) : $proposta->unidades_consumidoras,
            ];

            // Atualizar campos derivados se unidades_consumidoras mudaram
            if ($request->has('unidades_consumidoras') && !empty($request->unidades_consumidoras)) {
                $primeiraUC = $request->unidades_consumidoras[0];
                $dadosAtualizacao += [
                    'numero_uc' => $primeiraUC['numero_unidade'] ?? null,
                    'apelido' => $primeiraUC['apelido'] ?? null,
                    'media_consumo' => $primeiraUC['consumo_medio'] ?? null,
                    'ligacao' => $primeiraUC['ligacao'] ?? null,
                    'distribuidora' => $primeiraUC['distribuidora'] ?? 'CEMIG',
                ];
            }

            $proposta->update($dadosAtualizacao);

            DB::commit();

            // Retornar dados formatados
            $propostaFormatada = [
                'id' => $proposta->id,
                'numeroProposta' => $proposta->numero_proposta,
                'nomeCliente' => $proposta->nome_cliente,
                'consultor' => $proposta->consultor,
                'data' => $proposta->data_proposta,
                'status' => $proposta->status,
                'economia' => $proposta->economia,
                'bandeira' => $proposta->bandeira,
                'recorrencia' => $proposta->recorrencia,
                'observacoes' => $proposta->observacoes,
                'beneficios' => is_string($proposta->beneficios) ? 
                    json_decode($proposta->beneficios, true) : 
                    ($proposta->beneficios ?: []),
                'unidades_consumidoras' => is_string($proposta->unidades_consumidoras) ?
                    json_decode($proposta->unidades_consumidoras, true) :
                    ($proposta->unidades_consumidoras ?: []),
                'distribuidora' => $proposta->distribuidora,
                'created_at' => $proposta->created_at,
                'updated_at' => $proposta->updated_at,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Proposta atualizada com sucesso',
                'data' => $propostaFormatada
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao atualizar proposta', [
                'id' => $id,
                'user_id' => $currentUser->id ?? 'desconhecido',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
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
     * ✅ DELETAR UMA PROPOSTA
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

            $proposta = Proposta::find($id);

            if (!$proposta) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proposta não encontrada'
                ], 404);
            }

            // Verificar permissão
            if ($currentUser->role === 'vendedor' && $proposta->usuario_id !== $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sem permissão para excluir esta proposta'
                ], 403);
            }

            DB::beginTransaction();

            // Soft delete da proposta
            $proposta->delete();

            // Opcional: também fazer soft delete das UCs relacionadas
            UnidadeConsumidora::where('proposta_id', $id)->delete();

            DB::commit();

            Log::info('Proposta deletada', [
                'proposta_id' => $id,
                'numero_proposta' => $proposta->numero_proposta,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proposta excluída com sucesso'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao deletar proposta', [
                'proposta_id' => $id,
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
     * ✅ ESTATÍSTICAS DAS PROPOSTAS
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

            // Query base com filtros de permissão
            $query = Proposta::query();

            if ($currentUser->role === 'vendedor') {
                $query->where('usuario_id', $currentUser->id);
            } elseif ($currentUser->role === 'gerente') {
                $teamUserIds = DB::table('usuarios')
                              ->where('manager_id', $currentUser->id)
                              ->pluck('id')
                              ->toArray();
                $teamUserIds[] = $currentUser->id;
                $query->whereIn('usuario_id', $teamUserIds);
            }

            // Estatísticas básicas
            $total = $query->count();
            $emAnalise = (clone $query)->where('status', 'Em Análise')->count();
            $aguardando = (clone $query)->where('status', 'Aguardando')->count();
            $fechadas = (clone $query)->where('status', 'Fechado')->count();
            $perdidas = (clone $query)->where('status', 'Perdido')->count();
            $canceladas = (clone $query)->where('status', 'Cancelado')->count();

            // Estatísticas por consultor
            $porConsultor = (clone $query)
                ->select('consultor', DB::raw('count(*) as total'))
                ->groupBy('consultor')
                ->orderBy('total', 'desc')
                ->get();

            // Estatísticas por distribuidora
            $porDistribuidora = (clone $query)
                ->select('distribuidora', DB::raw('count(*) as total'))
                ->whereNotNull('distribuidora')
                ->groupBy('distribuidora')
                ->orderBy('total', 'desc')
                ->get();

            // Propostas recentes (últimos 30 dias)
            $recentes = (clone $query)
                ->where('created_at', '>=', now()->subDays(30))
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'totais' => [
                        'total' => $total,
                        'em_analise' => $emAnalise,
                        'aguardando' => $aguardando,
                        'fechadas' => $fechadas,
                        'perdidas' => $perdidas,
                        'canceladas' => $canceladas
                    ],
                    'taxa_conversao' => $total > 0 ? round(($fechadas / $total) * 100, 2) : 0,
                    'recentes_30_dias' => $recentes,
                    'por_consultor' => $porConsultor,
                    'por_distribuidora' => $porDistribuidora,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar estatísticas de propostas', [
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
     * ✅ ATUALIZAR STATUS DE UMA PROPOSTA
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'status' => 'required|string|in:Em Análise,Aguardando,Fechado,Perdido,Cancelado',
                'observacoes' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $proposta = Proposta::find($id);

            if (!$proposta) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proposta não encontrada'
                ], 404);
            }

            // Verificar permissão
            if ($currentUser->role === 'vendedor' && $proposta->usuario_id !== $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sem permissão para alterar status desta proposta'
                ], 403);
            }

            $statusAnterior = $proposta->status;
            $proposta->status = $request->status;
            
            if ($request->filled('observacoes')) {
                $observacoesAtuais = $proposta->observacoes ?: '';
                $novaObservacao = "\n[" . now()->format('d/m/Y H:i') . "] Status alterado de '{$statusAnterior}' para '{$request->status}': " . $request->observacoes;
                $proposta->observacoes = $observacoesAtuais . $novaObservacao;
            }

            $proposta->save();

            Log::info('Status da proposta alterado', [
                'proposta_id' => $id,
                'status_anterior' => $statusAnterior,
                'status_novo' => $request->status,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Status atualizado com sucesso',
                'data' => [
                    'id' => $proposta->id,
                    'status' => $proposta->status,
                    'observacoes' => $proposta->observacoes
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao atualizar status da proposta', [
                'proposta_id' => $id,
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
     * ✅ MÉTODO PARA GERAR NÚMERO DA PROPOSTA
     */
    private function gerarNumeroProposta(): string
    {
        $ano = date('Y');
        $ultimaProposta = Proposta::where('numero_proposta', 'LIKE', "$ano/%")
                                 ->orderBy('numero_proposta', 'desc')
                                 ->first();

        if ($ultimaProposta && preg_match("/^$ano\/(\d+)$/", $ultimaProposta->numero_proposta, $matches)) {
            $proximoNumero = intval($matches[1]) + 1;
        } else {
            $proximoNumero = 1;
        }

        return "$ano/" . str_pad($proximoNumero, 3, '0', STR_PAD_LEFT);
    }

    /**
     * ✅ DUPLICAR UMA PROPOSTA
     */
    public function duplicate(string $id): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $proposta = Proposta::find($id);

            if (!$proposta) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proposta não encontrada'
                ], 404);
            }

            DB::beginTransaction();

            // Criar nova proposta baseada na existente
            $novaProposta = new Proposta([
                'id' => (string) Str::uuid(),
                'numero_proposta' => $this->gerarNumeroProposta(),
                'nome_cliente' => $proposta->nome_cliente . ' (Cópia)',
                'consultor' => $proposta->consultor,
                'data_proposta' => now()->format('Y-m-d'),
                'usuario_id' => $currentUser->id,
                'economia' => $proposta->economia,
                'bandeira' => $proposta->bandeira,
                'recorrencia' => $proposta->recorrencia,
                'status' => 'Em Análise',
                'observacoes' => 'Proposta duplicada de: ' . $proposta->numero_proposta,
                'beneficios' => $proposta->beneficios,
                'unidades_consumidoras' => $proposta->unidades_consumidoras,
                'numero_uc' => $proposta->numero_uc,
                'apelido' => $proposta->apelido,
                'media_consumo' => $proposta->media_consumo,
                'ligacao' => $proposta->ligacao,
                'distribuidora' => $proposta->distribuidora,
            ]);

            $novaProposta->save();

            DB::commit();

            Log::info('Proposta duplicada', [
                'proposta_original_id' => $id,
                'nova_proposta_id' => $novaProposta->id,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proposta duplicada com sucesso',
                'data' => [
                    'id' => $novaProposta->id,
                    'numero_proposta' => $novaProposta->numero_proposta
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao duplicar proposta', [
                'proposta_id' => $id,
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