<?php

namespace App\Http\Controllers;

use App\Models\Proposta;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class PropostaController extends Controller
{
    /**
     * Gerar número sequencial para proposta
     */
    private function gerarNumeroProposta(): string
    {
        $ano = now()->year;
        $ultimaProposta = Proposta::whereYear('created_at', $ano)
            ->orderBy('created_at', 'desc')
            ->first();

        $numeroSequencial = $ultimaProposta ? 
            (intval(substr($ultimaProposta->numero_proposta, -4)) + 1) : 1;

        return sprintf('%d-%04d', $ano, $numeroSequencial);
    }

    /**
     * Listar propostas
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $currentUser = auth('api')->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $query = Proposta::with(['usuario']);

            // Filtros hierárquicos baseados no papel do usuário
            if ($currentUser->role !== 'admin') {
                // Usuários não-admin veem apenas suas próprias propostas e da sua equipe
                if ($currentUser->role === 'manager') {
                    // Manager vê suas propostas + propostas de usuários subordinados
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
            $perPage = min($request->get('per_page', 15), 100);
            $propostas = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $propostas->items(),
                'meta' => [
                    'current_page' => $propostas->currentPage(),
                    'last_page' => $propostas->lastPage(),
                    'per_page' => $propostas->perPage(),
                    'total' => $propostas->total(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar propostas', [
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
        try {
            $currentUser = auth('api')->user();
            
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
                'numero_proposta' => 'nullable|string|max:50|unique:propostas,numero_proposta',
                'economia' => 'nullable|numeric|min:0|max:100',
                'bandeira' => 'nullable|numeric|min:0|max:100',
                'recorrencia' => 'nullable|string|max:50',
                'observacoes' => 'nullable|string|max:1000',
                'beneficios' => 'nullable|array',
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

            $proposta = new Proposta();
            $proposta->numero_proposta = $numeroProposta;
            $proposta->data_proposta = $request->data_proposta;
            $proposta->nome_cliente = $request->nome_cliente;
            $proposta->consultor = $request->consultor;
            $proposta->usuario_id = $currentUser->id;
            $proposta->recorrencia = $request->recorrencia ?? '3%';
            $proposta->economia = $request->economia ?? 20.00;
            $proposta->bandeira = $request->bandeira ?? 20.00;
            $proposta->status = 'Aguardando';
            $proposta->observacoes = $request->observacoes;
            
            // Processar benefícios se fornecidos
            if ($request->has('beneficios') && is_array($request->beneficios)) {
                $proposta->beneficios = $request->beneficios;
            }

            $proposta->save();

            DB::commit();

            Log::info('Proposta criada com sucesso', [
                'proposta_id' => $proposta->id,
                'numero_proposta' => $proposta->numero_proposta,
                'nome_cliente' => $proposta->nome_cliente,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proposta criada com sucesso!',
                'data' => $proposta->fresh()
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao criar proposta', [
                'request_data' => $request->all(),
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
     * Buscar proposta específica
     */
    public function show(string $id): JsonResponse
    {
        try {
            $currentUser = auth('api')->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $proposta = Proposta::with(['usuario'])->findOrFail($id);

            // Verificar permissões
            if ($currentUser->role !== 'admin' && $proposta->usuario_id !== $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não autorizado'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $proposta
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar proposta', [
                'proposta_id' => $id,
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
        try {
            $currentUser = auth('api')->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $proposta = Proposta::findOrFail($id);

            // Verificar permissões
            if ($currentUser->role !== 'admin' && $proposta->usuario_id !== $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não autorizado'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'nome_cliente' => 'sometimes|required|string|min:3|max:200',
                'consultor' => 'sometimes|required|string|max:100',
                'data_proposta' => 'sometimes|required|date',
                'economia' => 'nullable|numeric|min:0|max:100',
                'bandeira' => 'nullable|numeric|min:0|max:100',
                'recorrencia' => 'nullable|string|max:50',
                'observacoes' => 'nullable|string|max:1000',
                'beneficios' => 'nullable|array',
                'status' => 'nullable|string|in:Aguardando,Fechado,Perdido,Não Fechado,Cancelado'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Atualizar campos fornecidos
            $campos = ['nome_cliente', 'consultor', 'data_proposta', 'economia', 'bandeira', 'recorrencia', 'observacoes', 'status'];
            
            foreach ($campos as $campo) {
                if ($request->has($campo)) {
                    $proposta->{$campo} = $request->{$campo};
                }
            }

            // Processar benefícios se fornecidos
            if ($request->has('beneficios')) {
                $proposta->beneficios = is_array($request->beneficios) ? $request->beneficios : [];
            }

            $proposta->save();

            DB::commit();

            Log::info('Proposta atualizada com sucesso', [
                'proposta_id' => $id,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proposta atualizada com sucesso!',
                'data' => $proposta->fresh()
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao atualizar proposta', [
                'proposta_id' => $id,
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
     * Atualizar status da proposta
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        try {
            $currentUser = auth('api')->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $proposta = Proposta::findOrFail($id);

            // Verificar permissões
            if ($currentUser->role !== 'admin' && $proposta->usuario_id !== $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não autorizado'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'status' => 'required|string|in:Aguardando,Fechado,Perdido,Não Fechado,Cancelado',
                'observacoes' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $statusAnterior = $proposta->status;
            $proposta->status = $request->status;
            
            if ($request->filled('observacoes')) {
                $proposta->observacoes = $request->observacoes;
            }

            $proposta->save();

            DB::commit();

            Log::info('Status da proposta alterado', [
                'proposta_id' => $id,
                'status_anterior' => $statusAnterior,
                'status_novo' => $request->status,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Status atualizado com sucesso!',
                'data' => $proposta->fresh()
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao atualizar status', [
                'proposta_id' => $id,
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
            $currentUser = auth('api')->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $proposta = Proposta::findOrFail($id);

            // Verificar permissões
            if ($currentUser->role !== 'admin' && $proposta->usuario_id !== $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não autorizado'
                ], 403);
            }

            $proposta->delete();

            Log::info('Proposta excluída', [
                'proposta_id' => $id,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proposta excluída com sucesso!'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Erro ao excluir proposta', [
                'proposta_id' => $id,
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
            $currentUser = auth('api')->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $propostaOriginal = Proposta::findOrFail($id);

            // Verificar permissões
            if ($currentUser->role !== 'admin' && $propostaOriginal->usuario_id !== $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não autorizado'
                ], 403);
            }

            DB::beginTransaction();

            $novaProposta = new Proposta();
            $novaProposta->numero_proposta = $this->gerarNumeroProposta();
            $novaProposta->data_proposta = now();
            $novaProposta->nome_cliente = $propostaOriginal->nome_cliente . ' (Cópia)';
            $novaProposta->consultor = $propostaOriginal->consultor;
            $novaProposta->usuario_id = $currentUser->id;
            $novaProposta->recorrencia = $propostaOriginal->recorrencia;
            $novaProposta->economia = $propostaOriginal->economia;
            $novaProposta->bandeira = $propostaOriginal->bandeira;
            $novaProposta->status = 'Aguardando';
            $novaProposta->observacoes = 'Cópia da proposta ' . $propostaOriginal->numero_proposta;
            $novaProposta->beneficios = $propostaOriginal->beneficios;

            $novaProposta->save();

            DB::commit();

            Log::info('Proposta duplicada', [
                'proposta_original_id' => $id,
                'nova_proposta_id' => $novaProposta->id,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proposta duplicada com sucesso!',
                'data' => $novaProposta->fresh()
            ], 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao duplicar proposta', [
                'proposta_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }
}