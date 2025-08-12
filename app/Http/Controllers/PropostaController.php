<?php

namespace App\Http\Controllers;

use App\Models\Proposta;
use App\Models\UnidadeConsumidora;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class PropostaController extends Controller
{
    /**
     * Gerar número sequencial para proposta
     */
    private function gerarNumeroProposta(): string
    {
        $ano = now()->year;
        $ultimaProposta = Proposta::where('numero_proposta', 'like', $ano . '/%')
                                 ->orderBy('numero_proposta', 'desc')
                                 ->first();
        
        if ($ultimaProposta) {
            $ultimoNumero = intval(explode('/', $ultimaProposta->numero_proposta)[1]);
            $proximoNumero = $ultimoNumero + 1;
        } else {
            $proximoNumero = 1;
        }
        
        return sprintf('%s/%03d', $ano, $proximoNumero);
    }

    /**
     * Listar propostas
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

            $query = Proposta::with(['usuario']);

            // Filtros hierárquicos baseados no papel do usuário
            if ($currentUser->role !== 'admin') {
                if ($currentUser->role === 'gerente') {
                    // Gerente vê suas propostas + propostas de usuários subordinados
                    $subordinados = Usuario::where('manager_id', $currentUser->id)->pluck('id')->toArray();
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinados);
                    $query->whereIn('usuario_id', $usuariosPermitidos);
                } else {
                    // Usuário comum vê apenas suas próprias propostas
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            // Filtros de busca
            if ($request->filled('nome_cliente')) {
                $query->where('nome_cliente', 'ilike', '%' . $request->nome_cliente . '%');
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('consultor')) {
                $query->where('consultor', 'ilike', '%' . $request->consultor . '%');
            }

            if ($request->filled('data_inicio') && $request->filled('data_fim')) {
                $query->whereBetween('data_proposta', [$request->data_inicio, $request->data_fim]);
            }

            // Ordenação
            $sortBy = $request->get('sort_by', 'created_at');
            $sortDirection = $request->get('sort_direction', 'desc');
            $query->orderBy($sortBy, $sortDirection);

            // Paginação
            $perPage = min($request->get('per_page', 10), 50);
            $propostas = $query->paginate($perPage);

            // Transformar dados para resposta
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
                    'telefone' => $proposta->telefone,
                    'email' => $proposta->email,
                    'endereco' => $proposta->endereco,
                    'unidades_consumidoras' => is_string($proposta->unidades_consumidoras) ?
                        json_decode($proposta->unidades_consumidoras, true) :
                        ($proposta->unidades_consumidoras ?: []),
                    'distribuidora' => $proposta->distribuidora,
                    'created_at' => $proposta->created_at,
                    'updated_at' => $proposta->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $propostasFormatadas,
                'pagination' => [
                    'current_page' => $propostas->currentPage(),
                    'last_page' => $propostas->lastPage(),
                    'per_page' => $propostas->perPage(),
                    'total' => $propostas->total(),
                    'from' => $propostas->firstItem(),
                    'to' => $propostas->lastItem()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar propostas', [
                'user_id' => $currentUser->id ?? 'desconhecido',
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
     * Mostrar proposta específica
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

            $query = Proposta::with(['usuario']);

            // Aplicar filtros hierárquicos
            if ($currentUser->role !== 'admin') {
                if ($currentUser->role === 'gerente') {
                    $subordinados = Usuario::where('manager_id', $currentUser->id)->pluck('id')->toArray();
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinados);
                    $query->whereIn('usuario_id', $usuariosPermitidos);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            $proposta = $query->findOrFail($id);

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
                'telefone' => $proposta->telefone,
                'email' => $proposta->email,
                'endereco' => $proposta->endereco,
                'unidades_consumidoras' => is_string($proposta->unidades_consumidoras) ?
                    json_decode($proposta->unidades_consumidoras, true) :
                    ($proposta->unidades_consumidoras ?: []),
                'distribuidora' => $proposta->distribuidora,
                'created_at' => $proposta->created_at,
                'updated_at' => $proposta->updated_at,
                'numeroUC' => $proposta->numero_uc,
                'apelido' => $proposta->apelido,
                'media' => $proposta->media_consumo,
            ];

            return response()->json([
                'success' => true,
                'data' => $propostaFormatada
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar proposta', [
                'id' => $id,
                'user_id' => $currentUser->id ?? 'desconhecido',
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * ✅ MÉTODO STORE CORRIGIDO - Criar nova proposta
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

            // ✅ VALIDAÇÃO CORRIGIDA: Incluindo campos das unidades consumidoras
            $validator = Validator::make($request->all(), [
                'nome_cliente' => 'required|string|min:3|max:200',
                'consultor' => 'required|string|max:100',
                'data_proposta' => 'required|date',
                'numero_proposta' => 'nullable|string|max:50|unique:propostas,numero_proposta',
                'economia' => 'nullable|numeric|min:0|max:100',
                'bandeira' => 'nullable|numeric|min:0|max:100',
                'recorrencia' => 'nullable|string|max:50',
                'observacoes' => 'nullable|string|max:1000',
                'beneficios' => 'nullable|array',
                // ✅ CAMPOS ADICIONAIS DO CLIENTE
                'telefone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:200',
                'endereco' => 'nullable|string|max:500',
                // ✅ UNIDADES CONSUMIDORAS
                'unidades_consumidoras' => 'nullable|array',
                'unidades_consumidoras.*.numero_unidade' => 'required|integer',
                'unidades_consumidoras.*.apelido' => 'required|string|max:100',
                'unidades_consumidoras.*.consumo_medio' => 'required|numeric|min:0',
                'unidades_consumidoras.*.ligacao' => 'nullable|string|max:50',
                'unidades_consumidoras.*.distribuidora' => 'nullable|string|max:100', // ✅ ADICIONADO
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Gerar número da proposta se não fornecido
            $numeroProposta = $request->numero_proposta ?: $this->gerarNumeroProposta();

            // Garantir que o número é único
            while (Proposta::where('numero_proposta', $numeroProposta)->exists()) {
                $numeroProposta = $this->gerarNumeroProposta();
            }

            // ✅ EXTRAIR DADOS DA PRIMEIRA UNIDADE CONSUMIDORA PARA CAMPOS DE BUSCA RÁPIDA
            $primeiraUC = null;
            $unidadesConsumidoras = $request->unidades_consumidoras ?: [];
            
            if (!empty($unidadesConsumidoras)) {
                $primeiraUC = $unidadesConsumidoras[0];
            }

            // ✅ CRIAR PROPOSTA COM TODOS OS CAMPOS
            $proposta = new Proposta([
                'id' => (string) Str::uuid(),
                'numero_proposta' => $numeroProposta,
                'nome_cliente' => $request->nome_cliente,
                'consultor' => $request->consultor,
                'data_proposta' => $request->data_proposta,
                'usuario_id' => $currentUser->id,
                'economia' => $request->economia ?? 20.00,
                'bandeira' => $request->bandeira ?? 20.00,
                'recorrencia' => $request->recorrencia ?? '3%',
                'status' => 'Em Análise',
                'observacoes' => $request->observacoes,
                'beneficios' => $request->beneficios ? json_encode($request->beneficios) : null,
                
                // ✅ CAMPOS ADICIONAIS DO CLIENTE
                'telefone' => $request->telefone,
                'email' => $request->email,
                'endereco' => $request->endereco,
                
                // ✅ UNIDADES CONSUMIDORAS (JSON COMPLETO)
                'unidades_consumidoras' => !empty($unidadesConsumidoras) ? json_encode($unidadesConsumidoras) : null,
                
                // ✅ CAMPOS DERIVADOS DA PRIMEIRA UC PARA BUSCA RÁPIDA
                'numero_uc' => $primeiraUC['numero_unidade'] ?? null,
                'apelido' => $primeiraUC['apelido'] ?? null,
                'media_consumo' => $primeiraUC['consumo_medio'] ?? null,
                'ligacao' => $primeiraUC['ligacao'] ?? null,
                'distribuidora' => $primeiraUC['distribuidora'] ?? 'CEMIG', // ✅ VALOR PADRÃO
            ]);

            $proposta->save();

            // ✅ CRIAR UNIDADES CONSUMIDORAS SEPARADAMENTE (SE NECESSÁRIO)
            if (!empty($unidadesConsumidoras)) {
                foreach ($unidadesConsumidoras as $ucData) {
                    try {
                        // Verificar se já existe UC com esse número
                        $ucExistente = UnidadeConsumidora::where('numero_unidade', $ucData['numero_unidade'])
                                                      ->whereNull('deleted_at')
                                                      ->first();
                        
                        if (!$ucExistente) {
                            // Criar nova UC
                            UnidadeConsumidora::create([
                                'id' => (string) Str::uuid(),
                                'usuario_id' => $currentUser->id,
                                'concessionaria_id' => $currentUser->concessionaria_atual_id,
                                'proposta_id' => $proposta->id,
                                'numero_unidade' => $ucData['numero_unidade'],
                                'apelido' => $ucData['apelido'],
                                'consumo_medio' => $ucData['consumo_medio'],
                                'ligacao' => $ucData['ligacao'] ?? 'MONOFÁSICA',
                                'distribuidora' => $ucData['distribuidora'] ?? 'CEMIG', // ✅ VALOR PADRÃO
                                'tipo' => 'UC',
                                'is_ug' => false,
                                'nexus_clube' => false,
                                'nexus_cativo' => false,
                                'service' => false,
                                'project' => false,
                                'gerador' => false,
                            ]);
                        } else {
                            // Associar UC existente à proposta
                            $ucExistente->update(['proposta_id' => $proposta->id]);
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
                // ✅ CAMPOS ADICIONAIS
                'telefone' => $proposta->telefone,
                'email' => $proposta->email,
                'endereco' => $proposta->endereco,
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
                    'file' => basename($e->getFile())
                ] : null
            ], 500);
        }
    }

    /**
     * Atualizar proposta
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
                'status' => 'nullable|in:Em Análise,Aguardando,Fechado,Perdido',
                // Campos adicionais
                'telefone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:200',
                'endereco' => 'nullable|string|max:500',
                'unidades_consumidoras' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $query = Proposta::query();

            // Aplicar filtros hierárquicos
            if ($currentUser->role !== 'admin') {
                if ($currentUser->role === 'gerente') {
                    $subordinados = Usuario::where('manager_id', $currentUser->id)->pluck('id')->toArray();
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinados);
                    $query->whereIn('usuario_id', $usuariosPermitidos);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            $proposta = $query->findOrFail($id);

            DB::beginTransaction();

            // Atualizar campos básicos
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
                // Campos adicionais
                'telefone' => $request->telefone,
                'email' => $request->email,
                'endereco' => $request->endereco,
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
                'telefone' => $proposta->telefone,
                'email' => $proposta->email,
                'endereco' => $proposta->endereco,
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

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);

        } catch (ValidationException $e) {
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
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Atualizar apenas o status da proposta
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
                'status' => 'required|in:Em Análise,Aguardando,Fechado,Perdido'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Status inválido',
                    'errors' => $validator->errors()
                ], 422);
            }

            $query = Proposta::query();

            // Aplicar filtros hierárquicos
            if ($currentUser->role !== 'admin') {
                if ($currentUser->role === 'gerente') {
                    $subordinados = Usuario::where('manager_id', $currentUser->id)->pluck('id')->toArray();
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinados);
                    $query->whereIn('usuario_id', $usuariosPermitidos);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            $proposta = $query->findOrFail($id);
            $proposta->update(['status' => $request->status]);

            return response()->json([
                'success' => true,
                'message' => 'Status atualizado com sucesso',
                'data' => ['status' => $proposta->status]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Erro ao atualizar status da proposta', [
                'id' => $id,
                'user_id' => $currentUser->id ?? 'desconhecido',
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Excluir proposta (soft delete)
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

            $query = Proposta::query();

            // Aplicar filtros hierárquicos
            if ($currentUser->role !== 'admin') {
                if ($currentUser->role === 'gerente') {
                    $subordinados = Usuario::where('manager_id', $currentUser->id)->pluck('id')->toArray();
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinados);
                    $query->whereIn('usuario_id', $usuariosPermitidos);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            $proposta = $query->findOrFail($id);

            DB::beginTransaction();

            $proposta->delete(); // Soft delete

            // Desassociar UCs desta proposta
            UnidadeConsumidora::where('proposta_id', $id)->update(['proposta_id' => null]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Proposta excluída com sucesso'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao excluir proposta', [
                'id' => $id,
                'user_id' => $currentUser->id ?? 'desconhecido',
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Obter estatísticas das propostas
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

            $query = Proposta::query();

            // Aplicar filtros hierárquicos
            if ($currentUser->role !== 'admin') {
                if ($currentUser->role === 'gerente') {
                    $subordinados = Usuario::where('manager_id', $currentUser->id)->pluck('id')->toArray();
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinados);
                    $query->whereIn('usuario_id', $usuariosPermitidos);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            // Filtro por período se fornecido
            if ($request->filled('data_inicio') && $request->filled('data_fim')) {
                $query->whereBetween('data_proposta', [$request->data_inicio, $request->data_fim]);
            }

            $stats = [
                'total_propostas' => $query->count(),
                'por_status' => [
                    'em_analise' => $query->where('status', 'Em Análise')->count(),
                    'aguardando' => $query->where('status', 'Aguardando')->count(),
                    'fechado' => $query->where('status', 'Fechado')->count(),
                    'perdido' => $query->where('status', 'Perdido')->count(),
                ],
                'economia_media' => round($query->avg('economia'), 2),
                'bandeira_media' => round($query->avg('bandeira'), 2),
                'propostas_mes_atual' => $query->whereMonth('data_proposta', now()->month)
                                             ->whereYear('data_proposta', now()->year)
                                             ->count(),
                'taxa_fechamento' => $query->count() > 0 ? 
                    round(($query->where('status', 'Fechado')->count() / $query->count()) * 100, 2) : 0,
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao obter estatísticas das propostas', [
                'user_id' => $currentUser->id ?? 'desconhecido',
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Duplicar proposta
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

            $query = Proposta::query();

            // Aplicar filtros hierárquicos
            if ($currentUser->role !== 'admin') {
                if ($currentUser->role === 'gerente') {
                    $subordinados = Usuario::where('manager_id', $currentUser->id)->pluck('id')->toArray();
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinados);
                    $query->whereIn('usuario_id', $usuariosPermitidos);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            $propostaOriginal = $query->findOrFail($id);

            DB::beginTransaction();

            // Criar nova proposta baseada na original
            $novaProposta = $propostaOriginal->replicate();
            $novaProposta->id = (string) Str::uuid();
            $novaProposta->numero_proposta = $this->gerarNumeroProposta();
            $novaProposta->status = 'Em Análise';
            $novaProposta->data_proposta = now()->format('Y-m-d');
            $novaProposta->created_at = now();
            $novaProposta->updated_at = now();
            $novaProposta->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Proposta duplicada com sucesso',
                'data' => [
                    'id' => $novaProposta->id,
                    'numero_proposta' => $novaProposta->numero_proposta
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao duplicar proposta', [
                'id' => $id,
                'user_id' => $currentUser->id ?? 'desconhecido',
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Atualização em lote de status
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
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
                'proposta_ids' => 'required|array',
                'proposta_ids.*' => 'required|string',
                'status' => 'required|in:Em Análise,Aguardando,Fechado,Perdido'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $query = Proposta::whereIn('id', $request->proposta_ids);

            // Aplicar filtros hierárquicos
            if ($currentUser->role !== 'admin') {
                if ($currentUser->role === 'gerente') {
                    $subordinados = Usuario::where('manager_id', $currentUser->id)->pluck('id')->toArray();
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinados);
                    $query->whereIn('usuario_id', $usuariosPermitidos);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            $atualizadas = $query->update(['status' => $request->status]);

            return response()->json([
                'success' => true,
                'message' => "Status de {$atualizadas} proposta(s) atualizado(s) com sucesso"
            ]);

        } catch (\Exception $e) {
            Log::error('Erro na atualização em lote de status', [
                'user_id' => $currentUser->id ?? 'desconhecido',
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Exportar propostas
     */
    public function export(Request $request): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Por enquanto, retornar dados em JSON
            // No futuro, implementar exportação para Excel/CSV
            $query = Proposta::with(['usuario']);

            // Aplicar filtros hierárquicos
            if ($currentUser->role !== 'admin') {
                if ($currentUser->role === 'gerente') {
                    $subordinados = Usuario::where('manager_id', $currentUser->id)->pluck('id')->toArray();
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinados);
                    $query->whereIn('usuario_id', $usuariosPermitidos);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            $propostas = $query->get();

            return response()->json([
                'success' => true,
                'message' => 'Dados exportados com sucesso',
                'data' => $propostas
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao exportar propostas', [
                'user_id' => $currentUser->id ?? 'desconhecido',
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }
}