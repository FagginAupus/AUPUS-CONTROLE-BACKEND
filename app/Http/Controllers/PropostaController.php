<?php

namespace App\Http\Controllers;

use App\Models\Proposta;
use App\Models\Usuario;
use App\Models\Notificacao;
use App\Models\Configuracao;
use App\Models\UnidadeConsumidora;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;
use Carbon\Carbon;

class PropostaController extends Controller
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Listar propostas com filtros hierárquicos
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
            $query = Proposta::query()
                            ->with(['usuario', 'unidadesConsumidoras'])
                            ->comFiltroHierarquico($currentUser);

            // Filtros de busca
            if ($request->filled('status')) {
                $statusList = is_array($request->status) ? $request->status : [$request->status];
                $query->whereIn('status', $statusList);
            }

            if ($request->filled('consultor')) {
                $query->where('consultor', 'ILIKE', "%{$request->consultor}%");
            }

            if ($request->filled('nome_cliente')) {
                $query->where('nome_cliente', 'ILIKE', "%{$request->nome_cliente}%");
            }

            if ($request->filled('numero_proposta')) {
                $query->where('numero_proposta', 'ILIKE', "%{$request->numero_proposta}%");
            }

            if ($request->filled('data_inicio') && $request->filled('data_fim')) {
                $query->whereBetween('data_proposta', [
                    Carbon::parse($request->data_inicio)->format('Y-m-d'),
                    Carbon::parse($request->data_fim)->format('Y-m-d')
                ]);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('numero_proposta', 'ILIKE', "%{$search}%")
                      ->orWhere('nome_cliente', 'ILIKE', "%{$search}%")
                      ->orWhere('consultor', 'ILIKE', "%{$search}%");
                });
            }

            // Ordenação
            $orderBy = $request->get('order_by', 'created_at');
            $orderDirection = $request->get('order_direction', 'desc');
            
            $allowedOrderBy = ['created_at', 'data_proposta', 'numero_proposta', 'nome_cliente', 'consultor', 'status'];
            if (!in_array($orderBy, $allowedOrderBy)) {
                $orderBy = 'created_at';
            }
            
            $query->orderBy($orderBy, $orderDirection);

            // Paginação
            $perPage = min($request->get('per_page', 15), 100);
            $propostas = $query->paginate($perPage);

            // Transformar dados
            $propostas->getCollection()->transform(function ($proposta) use ($currentUser) {
                return $this->transformPropostaForAPI($proposta, $currentUser);
            });

            // Estatísticas
            $estatisticas = Proposta::getEstatisticas(['usuario' => $currentUser]);

            return response()->json([
                'success' => true,
                'data' => $propostas,
                'statistics' => $estatisticas
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao listar propostas', [
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
     * Criar nova proposta - CORRIGIDO PARA COMPATIBILIDADE COM FRONTEND
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

        // VALIDAÇÃO CORRIGIDA - APENAS CAMPOS ESSENCIAIS
        $validator = Validator::make($request->all(), [
            // Campos obrigatórios básicos
            'nome_cliente' => 'required|string|min:3|max:200',
            'consultor' => 'required|string|max:100',
            
            // Campos opcionais com validação mínima
            'data_proposta' => 'nullable|date',
            'numero_proposta' => 'nullable|string|max:50',
            'telefone' => 'nullable|string|max:20',
            'status' => 'nullable|in:Aguardando,Fechado,Perdido,Não Fechado,Cancelado',
            
            // Percentuais (aceitar tanto decimal quanto inteiro)
            'economia' => 'nullable|numeric|min:0|max:100',
            'bandeira' => 'nullable|numeric|min:0|max:100',
            'recorrencia' => 'nullable|string|max:10',
            
            // Arrays opcionais
            'beneficios' => 'nullable|array',
            'beneficios.*' => 'nullable|string|max:200',
            
            // UCs opcionais
            'unidades_consumidoras' => 'nullable|array',
            'unidades_consumidoras.*.numero_unidade' => 'nullable|integer|min:1',
            'unidades_consumidoras.*.numero_cliente' => 'nullable|integer|min:1',
            'unidades_consumidoras.*.apelido' => 'nullable|string|max:100',
            'unidades_consumidoras.*.ligacao' => 'nullable|string|max:50',
            'unidades_consumidoras.*.consumo_medio' => 'nullable|numeric|min:0',
            'unidades_consumidoras.*.distribuidora' => 'nullable|string|max:100'
        ], [
            // Mensagens básicas
            'nome_cliente.required' => 'Nome do cliente é obrigatório',
            'nome_cliente.min' => 'Nome do cliente deve ter pelo menos 3 caracteres',
            'consultor.required' => 'Consultor é obrigatório',
            'economia.numeric' => 'Economia deve ser um número',
            'economia.max' => 'Economia não pode ser maior que 100%',
            'bandeira.numeric' => 'Desconto bandeira deve ser um número',
            'bandeira.max' => 'Desconto bandeira não pode ser maior que 100%'
        ]);

        if ($validator->fails()) {
            \Log::warning('Validação falhou na criação da proposta', [
                'user_id' => $currentUser->id,
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->except(['password', 'token'])
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Gerar número da proposta se não fornecido
            $numeroProposta = $request->numero_proposta;
            if (!$numeroProposta) {
                $ano = Carbon::now()->year;
                $ultimaPropostaDesteAno = Proposta::whereYear('created_at', $ano)->count();
                $proximoNumero = $ultimaPropostaDesteAno + 1;
                $numeroProposta = $ano . '/' . str_pad($proximoNumero, 3, '0', STR_PAD_LEFT);
            }

            // Criar a proposta com campos compatíveis
            $proposta = Proposta::create([
                // Campos obrigatórios
                'nome_cliente' => trim($request->nome_cliente),
                'consultor' => trim($request->consultor),
                'usuario_id' => $currentUser->id,
                
                // Número da proposta
                'numero_proposta' => $numeroProposta,
                
                // Data
                'data_proposta' => $request->data_proposta ? 
                    Carbon::parse($request->data_proposta)->format('Y-m-d') : 
                    Carbon::now()->format('Y-m-d'),
                
                // Status
                'status' => $request->status ?? 'Aguardando',
                
                // Telefone
                'telefone' => $request->telefone,
                
                // Descontos (usar valores padrão das configurações se disponível)
                'economia' => $request->economia ?? $this->getEconomiaPadrao(),
                'bandeira' => $request->bandeira ?? $this->getBandeiraPadrao(),
                'recorrencia' => $request->recorrencia ?? $this->getRecorrenciaPadrao(),
                
                // Benefícios (array)
                'beneficios' => $request->beneficios ?? [],
                
                // Observações padrão
                'observacoes' => 'Proposta criada via sistema web'
            ]);

            // Criar UCs vinculadas se fornecidas
            if ($request->filled('unidades_consumidoras') && is_array($request->unidades_consumidoras)) {
                foreach ($request->unidades_consumidoras as $ucData) {
                    // Validar se tem pelo menos numero_unidade
                    if (!empty($ucData['numero_unidade'])) {
                        UnidadeConsumidora::create([
                            'usuario_id' => $currentUser->id,
                            'proposta_id' => $proposta->id,
                            
                            // Campos da UC
                            'numero_unidade' => (int) $ucData['numero_unidade'],
                            'numero_cliente' => (int) ($ucData['numero_cliente'] ?? $ucData['numero_unidade']),
                            'apelido' => $ucData['apelido'] ?? "UC {$ucData['numero_unidade']}",
                            'ligacao' => $ucData['ligacao'] ?? '',
                            'consumo_medio' => (float) ($ucData['consumo_medio'] ?? 0),
                            'distribuidora' => $ucData['distribuidora'] ?? '',
                            
                            // Defaults obrigatórios
                            'mesmo_titular' => true,
                            'tipo' => 'Consumidora',
                            'gerador' => false,
                            'service' => false,
                            'project' => false,
                            'nexus_clube' => false,
                            'nexus_cativo' => false,
                            'proprietario' => false,
                            'is_ug' => false
                        ]);
                    }
                }
            }

            // Criar notificação se método existir
            if (method_exists(Notificacao::class, 'criarPropostaCriada')) {
                Notificacao::criarPropostaCriada($proposta);
            }

            DB::commit();

            \Log::info('Proposta criada com sucesso', [
                'proposta_id' => $proposta->id,
                'numero_proposta' => $proposta->numero_proposta,
                'user_id' => $currentUser->id,
                'nome_cliente' => $proposta->nome_cliente,
                'total_ucs' => $request->filled('unidades_consumidoras') ? count($request->unidades_consumidoras) : 0
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proposta criada com sucesso!',
                'data' => $this->transformPropostaForAPI($proposta->load(['usuario', 'unidadesConsumidoras']), $currentUser)
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Erro ao criar proposta', [
                'user_id' => $currentUser->id,
                'request_data' => $request->except(['password', 'token']),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor ao criar proposta'
            ], 500);
        }
    }

    /**
     * Exibir proposta específica
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
            $proposta = Proposta::with(['usuario', 'unidadesConsumidoras', 'controleClube'])
                              ->findOrFail($id);

            // Verificar permissão
            if (!$this->canViewProposta($currentUser, $proposta)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $this->transformPropostaDetailForAPI($proposta, $currentUser)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erro ao buscar proposta', [
                'proposta_id' => $id,
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
     * Atualizar proposta
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
            $proposta = Proposta::findOrFail($id);

            // Verificar permissão
            if (!$this->canEditProposta($currentUser, $proposta)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'nome_cliente' => 'sometimes|required|string|min:3|max:200',
                'consultor' => 'sometimes|required|string|max:100',
                'data_proposta' => 'sometimes|required|date',
                'economia' => 'sometimes|numeric|min:0|max:100',
                'bandeira' => 'sometimes|numeric|min:0|max:100',
                'recorrencia' => 'sometimes|string|max:10',
                'observacoes' => 'nullable|string|max:1000',
                'beneficios' => 'sometimes|array',
                'beneficios.*' => 'string|max:200'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $dadosAtualizacao = [];
            
            if ($request->filled('nome_cliente')) {
                $dadosAtualizacao['nome_cliente'] = trim($request->nome_cliente);
            }
            
            if ($request->filled('consultor')) {
                $dadosAtualizacao['consultor'] = trim($request->consultor);
            }
            
            if ($request->filled('data_proposta')) {
                $dadosAtualizacao['data_proposta'] = Carbon::parse($request->data_proposta)->format('Y-m-d');
            }
            
            if ($request->filled('economia')) {
                $dadosAtualizacao['economia'] = $request->economia;
            }
            
            if ($request->filled('bandeira')) {
                $dadosAtualizacao['bandeira'] = $request->bandeira;
            }
            
            if ($request->filled('recorrencia')) {
                $dadosAtualizacao['recorrencia'] = $request->recorrencia;
            }
            
            if ($request->has('observacoes')) {
                $dadosAtualizacao['observacoes'] = $request->observacoes ? trim($request->observacoes) : null;
            }
            
            if ($request->filled('beneficios')) {
                $dadosAtualizacao['beneficios'] = $request->beneficios;
            }

            $proposta->update($dadosAtualizacao);

            \Log::info('Proposta atualizada', [
                'proposta_id' => $proposta->id,
                'numero_proposta' => $proposta->numero_proposta,
                'user_id' => $currentUser->id,
                'campos_alterados' => array_keys($dadosAtualizacao)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proposta atualizada com sucesso!',
                'data' => $this->transformPropostaForAPI($proposta->load(['usuario', 'unidadesConsumidoras']), $currentUser)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erro ao atualizar proposta', [
                'proposta_id' => $id,
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
     * Alterar status da proposta
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        return $this->changeStatus($request, $id);
    }

    /**
     * Alterar status da proposta
     */
    public function changeStatus(Request $request, string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:Aguardando,Fechado,Perdido,Não Fechado,Cancelado',
            'motivo' => 'nullable|string|max:500'
        ], [
            'status.required' => 'Status é obrigatório',
            'status.in' => 'Status inválido'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $proposta = Proposta::findOrFail($id);

            // Verificar permissão
            if (!$this->canChangeStatus($currentUser, $proposta)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            $statusOriginal = $proposta->status;
            $novoStatus = $request->status;

            // Se o status não mudou, não fazer nada
            if ($statusOriginal === $novoStatus) {
                return response()->json([
                    'success' => true,
                    'message' => 'Status já está definido como: ' . $novoStatus,
                    'data' => $this->transformPropostaForAPI($proposta, $currentUser)
                ]);
            }

            DB::beginTransaction();

            switch ($novoStatus) {
                case 'Fechado':
                    if (method_exists($proposta, 'isValidForFechamento')) {
                        $errosValidacao = $proposta->isValidForFechamento();
                        if (!empty($errosValidacao)) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Proposta não pode ser fechada',
                                'errors' => $errosValidacao
                            ], 422);
                        }
                    }
                    
                    if (method_exists($proposta, 'fecharProposta')) {
                        $proposta->fecharProposta();
                    } else {
                        $proposta->status = $novoStatus;
                        $proposta->save();
                    }
                    
                    if (method_exists(Notificacao::class, 'criarPropostaFechada')) {
                        Notificacao::criarPropostaFechada($proposta);
                    }
                    break;

                case 'Perdido':
                    if (method_exists($proposta, 'perderProposta')) {
                        $proposta->perderProposta($request->motivo ?? '');
                    } else {
                        $proposta->status = $novoStatus;
                        if ($request->motivo) {
                            $proposta->observacoes = ($proposta->observacoes ?? '') . "\n\nMotivo - Perdido: " . $request->motivo;
                        }
                        $proposta->save();
                    }
                    break;

                case 'Cancelado':
                    if (method_exists($proposta, 'cancelarProposta')) {
                        $proposta->cancelarProposta($request->motivo ?? '');
                    } else {
                        $proposta->status = $novoStatus;
                        if ($request->motivo) {
                            $proposta->observacoes = ($proposta->observacoes ?? '') . "\n\nMotivo - Cancelado: " . $request->motivo;
                        }
                        $proposta->save();
                    }
                    break;

                case 'Não Fechado':
                    $proposta->status = $novoStatus;
                    if ($request->motivo) {
                        $observacoesAtuais = $proposta->observacoes ?? '';
                        $proposta->observacoes = $observacoesAtuais . "\n\nMotivo - Não Fechado: " . $request->motivo;
                    }
                    $proposta->save();
                    break;

                case 'Aguardando':
                    if (method_exists($proposta, 'reativarProposta')) {
                        $proposta->reativarProposta();
                    } else {
                        $proposta->status = $novoStatus;
                        $proposta->save();
                    }
                    break;

                default:
                    $proposta->status = $novoStatus;
                    $proposta->save();
                    break;
            }

            // Criar notificação de alteração de status (exceto para fechamento que já tem notificação específica)
            if ($novoStatus !== 'Fechado' && method_exists(Notificacao::class, 'criarPropostaAlterada')) {
                Notificacao::criarPropostaAlterada($proposta, $statusOriginal);
            }

            DB::commit();

            \Log::info('Status da proposta alterado', [
                'proposta_id' => $proposta->id,
                'numero_proposta' => $proposta->numero_proposta,
                'user_id' => $currentUser->id,
                'status_anterior' => $statusOriginal,
                'status_novo' => $novoStatus,
                'motivo' => $request->motivo
            ]);

            return response()->json([
                'success' => true,
                'message' => "Status alterado para '{$novoStatus}' com sucesso!",
                'data' => $this->transformPropostaForAPI($proposta, $currentUser)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Erro ao alterar status da proposta', [
                'proposta_id' => $id,
                'user_id' => $currentUser->id,
                'status_novo' => $request->status,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Excluir proposta
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
            $proposta = Proposta::findOrFail($id);

            // Verificar permissão
            if (!$this->canDeleteProposta($currentUser, $proposta)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            // Verificar se pode ser excluída
            if ($proposta->status === 'Fechado' && $proposta->controleClube()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proposta fechada com controle ativo não pode ser excluída'
                ], 422);
            }

            $numeroProposta = $proposta->numero_proposta;
            $nomeCliente = $proposta->nome_cliente;

            DB::beginTransaction();

            // Excluir UCs vinculadas
            $proposta->unidadesConsumidoras()->delete();

            // Excluir a proposta (soft delete)
            $proposta->delete();

            DB::commit();

            \Log::info('Proposta excluída', [
                'proposta_id' => $id,
                'numero_proposta' => $numeroProposta,
                'nome_cliente' => $nomeCliente,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => "Proposta {$numeroProposta} excluída com sucesso!"
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Erro ao excluir proposta', [
                'proposta_id' => $id,
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
     * Obter estatísticas das propostas
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
            $filtros = ['usuario' => $currentUser];

            // Filtros opcionais
            if ($request->filled('periodo_inicio') && $request->filled('periodo_fim')) {
                $filtros['periodo'] = [
                    Carbon::parse($request->periodo_inicio)->format('Y-m-d'),
                    Carbon::parse($request->periodo_fim)->format('Y-m-d')
                ];
            }

            if ($request->filled('consultor')) {
                $filtros['consultor'] = $request->consultor;
            }

            $estatisticasGerais = method_exists(Proposta::class, 'getEstatisticas') 
                ? Proposta::getEstatisticas($filtros)
                : $this->getEstatisticasBasicas($currentUser);
            
            $estatisticasPorMes = $this->getEstatisticasPorMes($currentUser, $request);

            return response()->json([
                'success' => true,
                'data' => [
                    'geral' => $estatisticasGerais,
                    'por_mes' => $estatisticasPorMes,
                    'periodo' => [
                        'inicio' => $request->periodo_inicio ?? Carbon::now()->startOfYear()->format('Y-m-d'),
                        'fim' => $request->periodo_fim ?? Carbon::now()->format('Y-m-d')
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao buscar estatísticas', [
                'user_id' => $currentUser->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    // ========================================
    // MÉTODOS AUXILIARES
    // ========================================

    /**
     * Obter valores padrão das configurações
     */
    private function getEconomiaPadrao(): float
    {
        return method_exists(Configuracao::class, 'getEconomiaPadrao') 
            ? (float) Configuracao::getEconomiaPadrao()
            : 5.0;
    }

    private function getBandeiraPadrao(): float
    {
        return method_exists(Configuracao::class, 'getBandeiraPadrao')
            ? (float) Configuracao::getBandeiraPadrao()
            : 10.0;
    }

    private function getRecorrenciaPadrao(): string
    {
        return method_exists(Configuracao::class, 'getRecorrenciaPadrao')
            ? Configuracao::getRecorrenciaPadrao()
            : '3%';
    }

    // Métodos auxiliares para verificação de permissões
    private function canViewProposta(Usuario $currentUser, Proposta $proposta): bool
    {
        return method_exists($currentUser, 'canAccessData')
            ? $currentUser->canAccessData(['consultor' => $proposta->consultor])
            : true; // Fallback permissivo
    }

    private function canEditProposta(Usuario $currentUser, Proposta $proposta): bool
    {
        if (method_exists($currentUser, 'isAdmin') && $currentUser->isAdmin()) return true;
        
        if (method_exists($currentUser, 'isConsultor') && $currentUser->isConsultor()) {
            return method_exists($currentUser, 'canAccessData')
                ? $currentUser->canAccessData(['consultor' => $proposta->consultor])
                : true;
        }
        
        return $proposta->consultor === $currentUser->nome || $proposta->usuario_id === $currentUser->id;
    }

    private function canDeleteProposta(Usuario $currentUser, Proposta $proposta): bool
    {
        $isAdmin = method_exists($currentUser, 'isAdmin') ? $currentUser->isAdmin() : false;
        $isConsultor = method_exists($currentUser, 'isConsultor') ? $currentUser->isConsultor() : false;
        
        if (!$isAdmin && !$isConsultor) {
            return false;
        }
        
        return $this->canEditProposta($currentUser, $proposta);
    }

    private function canChangeStatus(Usuario $currentUser, Proposta $proposta): bool
    {
        return $this->canEditProposta($currentUser, $proposta);
    }

    // Métodos auxiliares para transformação de dados
    private function transformPropostaForAPI(Proposta $proposta, Usuario $currentUser): array
    {
        return [
            'id' => $proposta->id,
            'numero_proposta' => $proposta->numero_proposta,
            'data_proposta' => $proposta->data_proposta ? $proposta->data_proposta->format('d/m/Y') : null,
            'data_proposta_iso' => $proposta->data_proposta ? $proposta->data_proposta->format('Y-m-d') : null,
            'nome_cliente' => $proposta->nome_cliente,
            'consultor' => $proposta->consultor,
            'telefone' => $proposta->telefone ?? null,
            'status' => $proposta->status,
            'status_color' => property_exists($proposta, 'status_color') ? $proposta->status_color : $this->getStatusColor($proposta->status),
            'status_icon' => property_exists($proposta, 'status_icon') ? $proposta->status_icon : $this->getStatusIcon($proposta->status),
            'economia' => (float) $proposta->economia,
            'economia_formatada' => number_format($proposta->economia, 1) . '%',
            'bandeira' => (float) $proposta->bandeira,
            'bandeira_formatada' => number_format($proposta->bandeira, 1) . '%',
            'recorrencia' => $proposta->recorrencia,
            'observacoes' => $proposta->observacoes,
            'beneficios' => $proposta->beneficios ?? [],
            'tempo_aguardando' => $proposta->created_at->diffInDays(Carbon::now()),
            'tempo_aguardando_texto' => $proposta->created_at->diffForHumans(),
            'ucs_count' => $proposta->unidadesConsumidoras ? $proposta->unidadesConsumidoras->count() : 0,
            'created_at' => $proposta->created_at->format('d/m/Y H:i'),
            'updated_at' => $proposta->updated_at->format('d/m/Y H:i'),
            'usuario' => $proposta->usuario ? [
                'id' => $proposta->usuario->id,
                'nome' => $proposta->usuario->nome,
                'email' => $proposta->usuario->email,
                'role' => $proposta->usuario->role
            ] : null,
            'permissions' => [
                'can_edit' => $this->canEditProposta($currentUser, $proposta),
                'can_delete' => $this->canDeleteProposta($currentUser, $proposta),
                'can_change_status' => $this->canChangeStatus($currentUser, $proposta)
            ]
        ];
    }

    private function transformPropostaDetailForAPI(Proposta $proposta, Usuario $currentUser): array
    {
        $data = $this->transformPropostaForAPI($proposta, $currentUser);
        
        // Adicionar dados detalhados
        $data['unidades_consumidoras'] = $proposta->unidadesConsumidoras ? $proposta->unidadesConsumidoras->map(function ($uc) {
            return [
                'id' => $uc->id,
                'numero_unidade' => $uc->numero_unidade,
                'numero_cliente' => $uc->numero_cliente,
                'consumo_medio' => $uc->consumo_medio,
                'consumo_formatado' => number_format($uc->consumo_medio, 0) . ' kWh',
                'apelido' => $uc->apelido,
                'ligacao' => $uc->ligacao ?? '',
                'distribuidora' => $uc->distribuidora ?? '',
                'tipo_display' => $uc->is_ug ? 'UG' : 'UC',
                'status_display' => 'Ativa',
                'created_at' => $uc->created_at->format('d/m/Y H:i')
            ];
        }) : [];

        $data['controle_clube'] = property_exists($proposta, 'controleClube') && $proposta->controleClube ? $proposta->controleClube->map(function ($controle) {
            return [
                'id' => $controle->id,
                'numero_uc' => $controle->numero_uc ?? '',
                'ativo' => $controle->ativo ?? true,
                'status_display' => ($controle->ativo ?? true) ? 'Ativo' : 'Inativo',
                'data_inicio_clube' => property_exists($controle, 'data_inicio_clube') && $controle->data_inicio_clube 
                    ? $controle->data_inicio_clube->format('d/m/Y') : null
            ];
        }) : [];

        $data['validation_errors'] = method_exists($proposta, 'isValidForFechamento') 
            ? $proposta->isValidForFechamento()
            : [];

        return $data;
    }

    // Método auxiliar para estatísticas por mês
    private function getEstatisticasPorMes(Usuario $currentUser, Request $request): array
    {
        $mesesAtras = 6;
        $dataInicio = Carbon::now()->subMonths($mesesAtras)->startOfMonth();
        
        $query = Proposta::query()
                        ->where('created_at', '>=', $dataInicio);
        
        // Aplicar filtro hierárquico se método existir
        if (method_exists($query, 'comFiltroHierarquico')) {
            $query->comFiltroHierarquico($currentUser);
        } else {
            // Fallback simples
            $query->where('usuario_id', $currentUser->id);
        }
        
        if ($request->filled('consultor')) {
            $query->where('consultor', $request->consultor);
        }
        
        $resultados = $query->selectRaw("
                DATE_TRUNC('month', created_at) as mes,
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'Fechado' THEN 1 END) as fechado,
                COUNT(CASE WHEN status = 'Aguardando' THEN 1 END) as aguardando,
                COUNT(CASE WHEN status = 'Perdido' THEN 1 END) as perdido
            ")
            ->groupBy('mes')
            ->orderBy('mes')
            ->get();
        
        return $resultados->map(function ($item) {
            $total = (int) $item->total;
            $fechado = (int) $item->fechado;
            
            return [
                'mes' => Carbon::parse($item->mes)->format('m/Y'),
                'mes_nome' => Carbon::parse($item->mes)->locale('pt_BR')->format('M/Y'),
                'total' => $total,
                'fechado' => $fechado,
                'aguardando' => (int) $item->aguardando,
                'perdido' => (int) $item->perdido,
                'taxa_fechamento' => $total > 0 ? round(($fechado / $total) * 100, 2) : 0
            ];
        })->toArray();
    }

    /**
     * Obter estatísticas básicas (fallback)
     */
    private function getEstatisticasBasicas(Usuario $currentUser): array
    {
        $query = Proposta::query()->where('usuario_id', $currentUser->id);
        
        $total = $query->count();
        $aguardando = $query->where('status', 'Aguardando')->count();
        $fechado = $query->where('status', 'Fechado')->count();
        $perdido = $query->where('status', 'Perdido')->count();
        
        return [
            'total' => $total,
            'aguardando' => $aguardando,
            'fechado' => $fechado,
            'perdido' => $perdido,
            'cancelado' => $query->where('status', 'Cancelado')->count(),
            'taxa_fechamento' => $total > 0 ? round(($fechado / $total) * 100, 2) : 0
        ];
    }

    /**
     * Obter cor do status
     */
    private function getStatusColor(string $status): string
    {
        return match($status) {
            'Aguardando' => '#ffa500',
            'Fechado' => '#28a745',
            'Perdido' => '#dc3545',
            'Cancelado' => '#6c757d',
            'Não Fechado' => '#fd7e14',
            default => '#6c757d'
        };
    }

    /**
     * Obter ícone do status
     */
    private function getStatusIcon(string $status): string
    {
        return match($status) {
            'Aguardando' => '⏳',
            'Fechado' => '✅',
            'Perdido' => '❌',
            'Cancelado' => '🚫',
            'Não Fechado' => '⚠️',
            default => '❓'
        };
    }
}