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
use Illuminate\Support\Str;

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

            // ✅ CORRIGIDO: Buscar todas as propostas sem paginação primeiro
            $propostas = $query->get();

            // ✅ CORRIGIDO: Usar collect() para criar uma Collection e usar map()
            $propostasFormatadas = collect($propostas)->map(function($proposta) {
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
                    'created_at' => $proposta->created_at,
                    'updated_at' => $proposta->updated_at,
                    // Campos adicionais para compatibilidade com o frontend
                    'numeroUC' => null, // Será preenchido quando houver relação com UC
                    'apelido' => null,   // Será preenchido quando houver relação com UC
                    'media' => null,     // Será preenchido quando houver relação com UC
                ];
            })->toArray();

            return response()->json([
                'success' => true,
                'data' => $propostasFormatadas,
                'meta' => [
                    'total' => count($propostasFormatadas),
                    'count' => count($propostasFormatadas)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar propostas', [
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
     * Exibir uma proposta específica
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

            $query = Proposta::with(['usuario']);

            // Aplicar filtros hierárquicos
            if ($currentUser->role !== 'admin') {
                if ($currentUser->role === 'manager') {
                    $subordinados = Usuario::where('manager_id', $currentUser->id)->pluck('id')->toArray();
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinados);
                    $query->whereIn('usuario_id', $usuariosPermitidos);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            $proposta = $query->findOrFail($id);

            // Formatar dados para o frontend
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
                'created_at' => $proposta->created_at,
                'updated_at' => $proposta->updated_at,
                'numeroUC' => null,
                'apelido' => null,
                'media' => null,
            ];

            return response()->json([
                'success' => true,
                'data' => $propostaFormatada
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar proposta', [
                'id' => $id,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Proposta não encontrada'
            ], 404);
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

            // Garantir que o número é único
            while (Proposta::where('numero_proposta', $numeroProposta)->exists()) {
                $numeroProposta = $this->gerarNumeroProposta();
            }

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
                'status' => 'Aguardando',
                'observacoes' => $request->observacoes,
                'beneficios' => $request->beneficios ? json_encode($request->beneficios) : null,
            ]);

            $proposta->save();

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
                'created_at' => $proposta->created_at,
                'updated_at' => $proposta->updated_at,
            ];

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
                'status' => 'nullable|in:Aguardando,Fechado,Perdido',
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
                if ($currentUser->role === 'manager') {
                    $subordinados = Usuario::where('manager_id', $currentUser->id)->pluck('id')->toArray();
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinados);
                    $query->whereIn('usuario_id', $usuariosPermitidos);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            $proposta = $query->findOrFail($id);

            DB::beginTransaction();

            $proposta->update([
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
            ]);

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
                'created_at' => $proposta->created_at,
                'updated_at' => $proposta->updated_at,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Proposta atualizada com sucesso',
                'data' => $propostaFormatada
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao atualizar proposta', [
                'id' => $id,
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

            $query = Proposta::query();

            // Aplicar filtros hierárquicos
            if ($currentUser->role !== 'admin') {
                if ($currentUser->role === 'manager') {
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

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Proposta excluída com sucesso'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao excluir proposta', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Atualizar apenas o status da proposta
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

            $validator = Validator::make($request->all(), [
                'status' => 'required|in:Aguardando,Fechado,Perdido',
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
                if ($currentUser->role === 'manager') {
                    $subordinados = Usuario::where('manager_id', $currentUser->id)->pluck('id')->toArray();
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinados);
                    $query->whereIn('usuario_id', $usuariosPermitidos);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            $proposta = $query->findOrFail($id);

            DB::beginTransaction();

            $proposta->update(['status' => $request->status]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Status atualizado com sucesso',
                'data' => ['status' => $proposta->status]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao atualizar status da proposta', [
                'id' => $id,
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
    public function statistics(): JsonResponse
    {
        try {
            $currentUser = auth('api')->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $query = Proposta::query();

            // Aplicar filtros hierárquicos
            if ($currentUser->role !== 'admin') {
                if ($currentUser->role === 'manager') {
                    $subordinados = Usuario::where('manager_id', $currentUser->id)->pluck('id')->toArray();
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinados);
                    $query->whereIn('usuario_id', $usuariosPermitidos);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            $stats = [
                'total' => $query->count(),
                'aguardando' => (clone $query)->where('status', 'Aguardando')->count(),
                'fechadas' => (clone $query)->where('status', 'Fechado')->count(),
                'perdidas' => (clone $query)->where('status', 'Perdido')->count(),
                'este_mes' => (clone $query)->whereMonth('created_at', now()->month)->count(),
                'por_consultor' => $query->groupBy('consultor')
                    ->selectRaw('consultor, count(*) as total')
                    ->pluck('total', 'consultor'),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao obter estatísticas das propostas', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }
}