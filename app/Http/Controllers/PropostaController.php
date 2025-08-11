<?php

namespace App\Http\Controllers;

use App\Models\Proposta;
use App\Models\Usuario;
use App\Models\Notificacao;
use App\Models\Configuracao;
use App\Models\UnidadeConsumidora;
use App\Models\ControleClube;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;
use Carbon\Carbon;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PropostaController extends Controller implements HasMiddleware
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

            return response()->json([
                'success' => true,
                'data' => $propostas
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
     * Criar nova proposta
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
            'nome_cliente' => 'required|string|min:3|max:200',
            'consultor' => 'required|string|max:100',
            'data_proposta' => 'nullable|date',
            'economia' => 'nullable|numeric|min:0|max:100',
            'bandeira' => 'nullable|numeric|min:0|max:100',
            'recorrencia' => 'nullable|string|max:10',
            'observacoes' => 'nullable|string|max:1000',
            'beneficios' => 'nullable|array',
            'beneficios.*' => 'string|max:200',
            'unidades_consumidoras' => 'nullable|array',
            'unidades_consumidoras.*.numero_unidade' => 'required_with:unidades_consumidoras|integer|min:1',
            'unidades_consumidoras.*.numero_cliente' => 'required_with:unidades_consumidoras|integer|min:1',
            'unidades_consumidoras.*.consumo_medio' => 'nullable|numeric|min:0',
            'unidades_consumidoras.*.apelido' => 'nullable|string|max:100'
        ], [
            'nome_cliente.required' => 'Nome do cliente é obrigatório',
            'nome_cliente.min' => 'Nome do cliente deve ter pelo menos 3 caracteres',
            'consultor.required' => 'Consultor é obrigatório',
            'economia.numeric' => 'Economia deve ser um número',
            'economia.max' => 'Economia não pode ser maior que 100%',
            'bandeira.numeric' => 'Desconto bandeira deve ser um número',
            'bandeira.max' => 'Desconto bandeira não pode ser maior que 100%',
            'unidades_consumidoras.*.numero_unidade.required_with' => 'Número da unidade é obrigatório',
            'unidades_consumidoras.*.numero_cliente.required_with' => 'Número do cliente é obrigatório'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Criar a proposta
            $proposta = Proposta::create([
                'nome_cliente' => trim($request->nome_cliente),
                'consultor' => trim($request->consultor),
                'usuario_id' => $currentUser->id,
                'data_proposta' => $request->data_proposta ? 
                    Carbon::parse($request->data_proposta)->format('Y-m-d') : 
                    Carbon::now()->format('Y-m-d'),
                'economia' => $request->economia ?? 5.0,
                'bandeira' => $request->bandeira ?? 10.0,
                'recorrencia' => $request->recorrencia ?? 'Mensal',
                'observacoes' => $request->observacoes ? trim($request->observacoes) : null,
                'beneficios' => $request->beneficios ?? [],
                'status' => 'Aguardando'
            ]);

            // Criar UCs vinculadas
            if ($request->filled('unidades_consumidoras') && is_array($request->unidades_consumidoras)) {
                foreach ($request->unidades_consumidoras as $ucData) {
                    UnidadeConsumidora::create([
                        'usuario_id' => $currentUser->id,
                        'proposta_id' => $proposta->id,
                        'numero_unidade' => $ucData['numero_unidade'],
                        'numero_cliente' => $ucData['numero_cliente'],
                        'consumo_medio' => $ucData['consumo_medio'] ?? null,
                        'apelido' => $ucData['apelido'] ?? null,
                        'tipo' => 'Consumidora',
                        'gerador' => false,
                        'is_ug' => false
                    ]);
                }
            }

            // Criar notificação
            if (class_exists('App\Models\Notificacao')) {
                Notificacao::criarPropostaCriada($proposta);
            }

            DB::commit();

            \Log::info('Proposta criada com sucesso', [
                'proposta_id' => $proposta->id,
                'numero_proposta' => $proposta->numero_proposta,
                'user_id' => $currentUser->id,
                'nome_cliente' => $proposta->nome_cliente
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
                'request_data' => $request->all(),
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

            // Verificar se usuário pode acessar esta proposta
            if (!$this->canAccessProposta($currentUser, $proposta)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $this->transformPropostaDetailForAPI($proposta, $currentUser)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);
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

            // Verificar permissões
            if (!$this->canEditProposta($currentUser, $proposta)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado para editar esta proposta'
                ], 403);
            }

            // Validação
            $validator = Validator::make($request->all(), [
                'nome_cliente' => 'sometimes|required|string|min:3|max:200',
                'consultor' => 'sometimes|required|string|max:100',
                'data_proposta' => 'sometimes|nullable|date',
                'economia' => 'sometimes|nullable|numeric|min:0|max:100',
                'bandeira' => 'sometimes|nullable|numeric|min:0|max:100',
                'recorrencia' => 'sometimes|nullable|string|max:10',
                'observacoes' => 'sometimes|nullable|string|max:1000',
                'beneficios' => 'sometimes|nullable|array',
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

        } catch (\Exception $e) {
            \Log::error('Erro ao atualizar proposta', [
                'proposta_id' => $id,
                'user_id' => $currentUser->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar proposta'
            ], 500);
        }
    }

    /**
     * Atualizar status da proposta
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:Aguardando,Fechado,Cancelado'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Status inválido',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $proposta = Proposta::findOrFail($id);

            // Verificar permissões
            if (!$this->canChangeStatus($currentUser, $proposta)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado para alterar status desta proposta'
                ], 403);
            }

            $statusAnterior = $proposta->status;
            $proposta->update(['status' => $request->status]);

            // Log da alteração
            \Log::info('Status da proposta alterado', [
                'proposta_id' => $proposta->id,
                'numero_proposta' => $proposta->numero_proposta,
                'status_anterior' => $statusAnterior,
                'status_novo' => $request->status,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Status atualizado com sucesso',
                'data' => $this->transformPropostaForAPI($proposta->load(['usuario', 'unidadesConsumidoras']), $currentUser)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);
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

            // Verificar permissões
            if (!$this->canDeleteProposta($currentUser, $proposta)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado para excluir esta proposta'
                ], 403);
            }

            $numeroProposta = $proposta->numero_proposta;
            $proposta->delete();

            \Log::info('Proposta excluída', [
                'proposta_id' => $id,
                'numero_proposta' => $numeroProposta,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proposta excluída com sucesso'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);
        }
    }

    /**
     * Duplicar proposta
     */
    public function duplicate(string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        try {
            $propostaOriginal = Proposta::with(['unidadesConsumidoras'])->findOrFail($id);

            // Verificar permissões
            if (!$this->canAccessProposta($currentUser, $propostaOriginal)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            DB::beginTransaction();

            // Criar cópia da proposta
            $novaProposta = Proposta::create([
                'nome_cliente' => $propostaOriginal->nome_cliente . ' (Cópia)',
                'consultor' => $propostaOriginal->consultor,
                'usuario_id' => $currentUser->id,
                'data_proposta' => Carbon::now()->format('Y-m-d'),
                'economia' => $propostaOriginal->economia,
                'bandeira' => $propostaOriginal->bandeira,
                'recorrencia' => $propostaOriginal->recorrencia,
                'observacoes' => $propostaOriginal->observacoes,
                'beneficios' => $propostaOriginal->beneficios,
                'status' => 'Aguardando'
            ]);

            // Duplicar UCs
            foreach ($propostaOriginal->unidadesConsumidoras as $uc) {
                UnidadeConsumidora::create([
                    'usuario_id' => $currentUser->id,
                    'proposta_id' => $novaProposta->id,
                    'numero_unidade' => $uc->numero_unidade,
                    'numero_cliente' => $uc->numero_cliente,
                    'consumo_medio' => $uc->consumo_medio,
                    'apelido' => $uc->apelido,
                    'tipo' => $uc->tipo,
                    'gerador' => $uc->gerador,
                    'is_ug' => false
                ]);
            }

            DB::commit();

            \Log::info('Proposta duplicada', [
                'proposta_original_id' => $propostaOriginal->id,
                'nova_proposta_id' => $novaProposta->id,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proposta duplicada com sucesso',
                'data' => $this->transformPropostaForAPI($novaProposta->load(['usuario', 'unidadesConsumidoras']), $currentUser)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Erro ao duplicar proposta', [
                'proposta_id' => $id,
                'user_id' => $currentUser->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao duplicar proposta'
            ], 500);
        }
    }

    /**
     * Converter proposta para controle
     */
    public function convertToControle(string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Verificar se usuário pode criar controles
        if (!$currentUser->isAdmin() && !$currentUser->isConsultor()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores e consultores podem converter propostas para controle'
            ], 403);
        }

        try {
            $proposta = Proposta::with(['unidadesConsumidoras'])->findOrFail($id);

            // Verificar se usuário pode acessar esta proposta
            if (!$this->canAccessProposta($currentUser, $proposta)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            // Verificar se proposta está fechada
            if ($proposta->status !== 'Fechado') {
                return response()->json([
                    'success' => false,
                    'message' => 'Apenas propostas fechadas podem ser convertidas para controle'
                ], 422);
            }

            // Verificar se já existe controle para esta proposta
            if (ControleClube::where('proposta_id', $proposta->id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta proposta já possui um controle associado'
                ], 422);
            }

            DB::beginTransaction();

            // Criar controle
            $controle = ControleClube::create([
                'proposta_id' => $proposta->id,
                'usuario_id' => $currentUser->id,
                'numero_proposta' => $proposta->numero_proposta,
                'nome_cliente' => $proposta->nome_cliente,
                'consultor' => $proposta->consultor,
                'economia_mensal' => 0, // Será calculado posteriormente
                'economia_percentual' => $proposta->economia ?? 0,
                'desconto_bandeira' => $proposta->bandeira ?? 0,
                'recorrencia' => $proposta->recorrencia ?? 'Mensal',
                'data_inicio_clube' => Carbon::now()->format('Y-m-d'),
                'observacoes' => $proposta->observacoes,
                'ativo' => true
            ]);

            DB::commit();

            \Log::info('Proposta convertida para controle', [
                'proposta_id' => $proposta->id,
                'controle_id' => $controle->id,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proposta convertida para controle com sucesso',
                'data' => [
                    'controle_id' => $controle->id,
                    'numero_proposta' => $controle->numero_proposta,
                    'nome_cliente' => $controle->nome_cliente
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Erro ao converter proposta para controle', [
                'proposta_id' => $id,
                'user_id' => $currentUser->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao converter proposta para controle'
            ], 500);
        }
    }

    /**
     * Upload de documento para proposta
     */
    public function uploadDocumento(Request $request, string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx',
            'tipo' => 'required|in:documento,contrato,comprovante,outro'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Arquivo inválido',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $proposta = Proposta::findOrFail($id);

            // Verificar permissões
            if (!$this->canEditProposta($currentUser, $proposta)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            $file = $request->file('file');
            $tipo = $request->tipo;
            
            // Gerar nome único para o arquivo
            $filename = time() . '_' . $tipo . '_' . $file->getClientOriginalName();
            
            // Armazenar arquivo
            $path = $file->storeAs("propostas/{$proposta->id}/documentos", $filename, 'public');

            // Atualizar proposta com informação do documento
            $documentos = $proposta->documentos ?? [];
            $documentos[$tipo] = [
                'filename' => $filename,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'uploaded_at' => now()->toISOString(),
                'uploaded_by' => $currentUser->id
            ];
            
            $proposta->update(['documentos' => $documentos]);

            return response()->json([
                'success' => true,
                'message' => 'Documento enviado com sucesso',
                'data' => [
                    'tipo' => $tipo,
                    'filename' => $filename,
                    'original_name' => $file->getClientOriginalName()
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao fazer upload de documento', [
                'proposta_id' => $id,
                'user_id' => $currentUser->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao enviar documento'
            ], 500);
        }
    }

    /**
     * Remover documento da proposta
     */
    public function removeDocumento(string $id, string $tipo): JsonResponse
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

            // Verificar permissões
            if (!$this->canEditProposta($currentUser, $proposta)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            $documentos = $proposta->documentos ?? [];
            
            if (!isset($documentos[$tipo])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Documento não encontrado'
                ], 404);
            }

            // Remover arquivo do storage
            if (isset($documentos[$tipo]['path'])) {
                Storage::disk('public')->delete($documentos[$tipo]['path']);
            }

            // Remover documento do array
            unset($documentos[$tipo]);
            $proposta->update(['documentos' => $documentos]);

            return response()->json([
                'success' => true,
                'message' => 'Documento removido com sucesso'
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao remover documento', [
                'proposta_id' => $id,
                'tipo' => $tipo,
                'user_id' => $currentUser->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao remover documento'
            ], 500);
        }
    }

    /**
     * Atualização em lote de status
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:propostas,id',
            'status' => 'required|in:Aguardando,Fechado,Cancelado'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $ids = $request->ids;
            $status = $request->status;
            $sucessos = 0;
            $falhas = 0;

            DB::beginTransaction();

            foreach ($ids as $id) {
                try {
                    $proposta = Proposta::find($id);
                    
                    if (!$proposta || !$this->canChangeStatus($currentUser, $proposta)) {
                        $falhas++;
                        continue;
                    }

                    $proposta->update(['status' => $status]);
                    $sucessos++;
                    
                } catch (\Exception $e) {
                    \Log::warning('Erro ao atualizar status da proposta', [
                        'proposta_id' => $id,
                        'status' => $status,
                        'error' => $e->getMessage()
                    ]);
                    $falhas++;
                }
            }

            DB::commit();

            \Log::info('Atualização em lote de status concluída', [
                'user_id' => $currentUser->id,
                'status' => $status,
                'total' => count($ids),
                'sucessos' => $sucessos,
                'falhas' => $falhas
            ]);

            return response()->json([
                'success' => true,
                'message' => "Atualização concluída: {$sucessos} sucessos, {$falhas} falhas.",
                'data' => [
                    'status_aplicado' => $status,
                    'total_propostas' => count($ids),
                    'sucessos' => $sucessos,
                    'falhas' => $falhas
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Erro na atualização em lote de status', [
                'user_id' => $currentUser->id,
                'status' => $request->status,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Estatísticas das propostas
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
            $query = Proposta::query()->comFiltroHierarquico($currentUser);

            // Aplicar filtros se fornecidos
            if ($request->filled('data_inicio') && $request->filled('data_fim')) {
                $query->whereBetween('data_proposta', [
                    Carbon::parse($request->data_inicio)->format('Y-m-d'),
                    Carbon::parse($request->data_fim)->format('Y-m-d')
                ]);
            }

            if ($request->filled('consultor')) {
                $query->where('consultor', 'ILIKE', "%{$request->consultor}%");
            }

            $estatisticas = [
                'total' => $query->count(),
                'por_status' => [
                    'aguardando' => (clone $query)->where('status', 'Aguardando')->count(),
                    'fechado' => (clone $query)->where('status', 'Fechado')->count(),
                    'cancelado' => (clone $query)->where('status', 'Cancelado')->count(),
                ],
                'por_consultor' => $query->groupBy('consultor')
                                       ->selectRaw('consultor, count(*) as total')
                                       ->pluck('total', 'consultor')
                                       ->toArray(),
                'por_mes' => $query->groupBy(DB::raw('EXTRACT(YEAR FROM data_proposta), EXTRACT(MONTH FROM data_proposta)'))
                                 ->selectRaw('EXTRACT(YEAR FROM data_proposta) as ano, EXTRACT(MONTH FROM data_proposta) as mes, count(*) as total')
                                 ->get()
                                 ->groupBy('ano')
                                 ->map(function ($anoData) {
                                     return $anoData->pluck('total', 'mes')->toArray();
                                 })
                                 ->toArray(),
                'economia_media' => round($query->avg('economia') ?? 0, 2),
                'bandeira_media' => round($query->avg('bandeira') ?? 0, 2)
            ];

            return response()->json([
                'success' => true,
                'data' => $estatisticas
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao gerar estatísticas de propostas', [
                'user_id' => $currentUser->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao gerar estatísticas'
            ], 500);
        }
    }

    /**
     * Exportar propostas para CSV
     */
    public function export(Request $request): JsonResponse
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

            // Aplicar filtros
            if ($request->filled('status')) {
                $statusList = is_array($request->status) ? $request->status : [$request->status];
                $query->whereIn('status', $statusList);
            }

            if ($request->filled('consultor')) {
                $query->where('consultor', 'ILIKE', "%{$request->consultor}%");
            }

            if ($request->filled('data_inicio') && $request->filled('data_fim')) {
                $query->whereBetween('data_proposta', [
                    Carbon::parse($request->data_inicio)->format('Y-m-d'),
                    Carbon::parse($request->data_fim)->format('Y-m-d')
                ]);
            }

            $propostas = $query->orderBy('created_at', 'desc')->get();

            // Preparar dados para exportação
            $dadosExport = [];
            $dadosExport[] = [
                'ID',
                'Número da Proposta',
                'Cliente',
                'Consultor',
                'Data da Proposta',
                'Status',
                'Economia (%)',
                'Desconto Bandeira (%)',
                'Recorrência',
                'Observações',
                'Quantidade UCs',
                'Usuário Responsável',
                'Data de Criação',
                'Última Atualização'
            ];

            foreach ($propostas as $proposta) {
                $dadosExport[] = [
                    $proposta->id,
                    $proposta->numero_proposta,
                    $proposta->nome_cliente,
                    $proposta->consultor,
                    $proposta->data_proposta ? $proposta->data_proposta->format('d/m/Y') : '',
                    $proposta->status,
                    $proposta->economia ?? 0,
                    $proposta->bandeira ?? 0,
                    $proposta->recorrencia ?? '',
                    $proposta->observacoes ?? '',
                    $proposta->unidadesConsumidoras ? $proposta->unidadesConsumidoras->count() : 0,
                    $proposta->usuario ? $proposta->usuario->nome : '',
                    $proposta->created_at->format('d/m/Y H:i'),
                    $proposta->updated_at->format('d/m/Y H:i')
                ];
            }

            // Gerar CSV
            $filename = 'propostas_' . date('Y-m-d_H-i-s') . '.csv';
            $path = storage_path("app/exports/{$filename}");
            
            // Garantir que o diretório existe
            if (!file_exists(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            $handle = fopen($path, 'w');
            
            // Adicionar BOM para UTF-8
            fputs($handle, "\xEF\xBB\xBF");
            
            foreach ($dadosExport as $linha) {
                fputcsv($handle, $linha, ';');
            }
            
            fclose($handle);

            \Log::info('Exportação de propostas realizada', [
                'user_id' => $currentUser->id,
                'total_propostas' => count($propostas),
                'filename' => $filename
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Exportação realizada com sucesso',
                'data' => [
                    'filename' => $filename,
                    'download_url' => route('download.export', ['filename' => $filename]),
                    'total_registros' => count($propostas)
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro na exportação de propostas', [
                'user_id' => $currentUser->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro na exportação'
            ], 500);
        }
    }

    // ========================================
    // MÉTODOS AUXILIARES
    // ========================================

    private function transformPropostaForAPI($proposta, $currentUser)
    {
        return [
            'id' => $proposta->id,
            'numero_proposta' => $proposta->numero_proposta,
            'nome_cliente' => $proposta->nome_cliente,
            'consultor' => $proposta->consultor,
            'data_proposta' => $proposta->data_proposta ? $proposta->data_proposta->format('Y-m-d') : null,
            'economia' => $proposta->economia,
            'bandeira' => $proposta->bandeira,
            'recorrencia' => $proposta->recorrencia,
            'observacoes' => $proposta->observacoes,
            'beneficios' => $proposta->beneficios ?? [],
            'status' => $proposta->status,
            'status_display' => $this->getStatusDisplay($proposta->status),
            'tempo_aguardando' => $this->calcularTempoAguardando($proposta),
            'tempo_aguardando_texto' => $this->getTempoAguardandoTexto($proposta),
            'ucs_count' => $proposta->unidadesConsumidoras ? $proposta->unidadesConsumidoras->count() : 0,
            'created_at' => $proposta->created_at ? $proposta->created_at->format('d/m/Y H:i') : null,
            'updated_at' => $proposta->updated_at ? $proposta->updated_at->format('d/m/Y H:i') : null,
            'usuario' => $proposta->usuario ? [
                'id' => $proposta->usuario->id,
                'nome' => $proposta->usuario->nome,
                'email' => $proposta->usuario->email,
                'role' => $proposta->usuario->role
            ] : null,
            'permissions' => [
                'can_edit' => $this->canEditProposta($currentUser, $proposta),
                'can_delete' => $this->canDeleteProposta($currentUser, $proposta),
                'can_change_status' => $this->canChangeStatus($currentUser, $proposta),
                'can_duplicate' => $this->canAccessProposta($currentUser, $proposta),
                'can_convert_to_controle' => $this->canConvertToControle($currentUser, $proposta)
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
                'apelido' => $uc->apelido,
                'tipo' => $uc->tipo,
                'created_at' => $uc->created_at ? $uc->created_at->format('d/m/Y H:i') : null
            ];
        }) : [];

        $data['controle_clube'] = $proposta->controleClube ? $proposta->controleClube->map(function ($controle) {
            return [
                'id' => $controle->id,
                'ativo' => $controle->ativo,
                'data_inicio_clube' => $controle->data_inicio_clube ? $controle->data_inicio_clube->format('d/m/Y') : null,
                'economia_mensal' => $controle->economia_mensal,
                'economia_percentual' => $controle->economia_percentual
            ];
        }) : [];

        $data['documentos'] = $proposta->documentos ?? [];

        return $data;
    }

    private function canAccessProposta($currentUser, $proposta)
    {
        // Admin pode ver tudo
        if ($currentUser->isAdmin()) {
            return true;
        }
        
        // Consultor pode ver propostas da sua hierarquia
        if ($currentUser->isConsultor()) {
            return $proposta->consultor === $currentUser->nome || $proposta->usuario_id === $currentUser->id;
        }
        
        // Outros podem ver apenas suas próprias propostas
        return $proposta->usuario_id === $currentUser->id;
    }

    private function canEditProposta($currentUser, $proposta)
    {
        // Admin pode editar tudo
        if ($currentUser->isAdmin()) {
            return true;
        }
        
        // Consultor pode editar propostas da sua hierarquia
        if ($currentUser->isConsultor()) {
            return $proposta->consultor === $currentUser->nome || $proposta->usuario_id === $currentUser->id;
        }
        
        // Outros podem editar apenas suas próprias propostas
        return $proposta->usuario_id === $currentUser->id;
    }

    private function canDeleteProposta($currentUser, $proposta)
    {
        // Apenas admin pode excluir
        if ($currentUser->isAdmin()) {
            return true;
        }
        
        return false;
    }

    private function canChangeStatus($currentUser, $proposta)
    {
        // Admin pode alterar qualquer status
        if ($currentUser->isAdmin()) {
            return true;
        }
        
        // Consultor pode alterar status das propostas da sua hierarquia
        if ($currentUser->isConsultor()) {
            return $proposta->consultor === $currentUser->nome || $proposta->usuario_id === $currentUser->id;
        }
        
        // Vendedores podem alterar status das suas próprias propostas
        return $proposta->usuario_id === $currentUser->id;
    }

    private function canConvertToControle($currentUser, $proposta)
    {
        // Apenas admin e consultor podem converter para controle
        if (!$currentUser->isAdmin() && !$currentUser->isConsultor()) {
            return false;
        }
        
        // Proposta deve estar fechada
        if ($proposta->status !== 'Fechado') {
            return false;
        }
        
        // Não deve já ter controle
        return !ControleClube::where('proposta_id', $proposta->id)->exists();
    }

    private function getStatusDisplay($status)
    {
        $statusMap = [
            'Aguardando' => 'Aguardando',
            'Fechado' => 'Fechado',
            'Cancelado' => 'Cancelado'
        ];
        
        return $statusMap[$status] ?? $status;
    }

    private function calcularTempoAguardando($proposta)
    {
        if ($proposta->status !== 'Aguardando') {
            return 0;
        }
        
        return $proposta->created_at ? $proposta->created_at->diffInDays(Carbon::now()) : 0;
    }

    private function getTempoAguardandoTexto($proposta)
    {
        $dias = $this->calcularTempoAguardando($proposta);
        
        if ($dias == 0) {
            return 'Hoje';
        } elseif ($dias == 1) {
            return '1 dia';
        } else {
            return "{$dias} dias";
        }
    }
}