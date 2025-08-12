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
     * Constructor - Removido o middleware incorreto
     */
    public function __construct()
    {
        // Middleware será aplicado nas rotas, não no controller
    }

    /**
     * Listar propostas com filtros hierárquicos
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Se JWT está sendo usado, pegue o usuário assim
            $currentUser = auth('api')->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $query = Proposta::query()
                            ->with(['usuario', 'unidadesConsumidoras'])
                            ->orderBy('created_at', 'desc');

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

            // Paginação
            $perPage = $request->get('per_page', 20);
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
            \Log::error('Erro ao listar propostas', [
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
                'telefone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:100',
                'endereco' => 'nullable|string|max:255',
                'numero_proposta' => 'nullable|string|max:50|unique:propostas,numero_proposta',
                'economia' => 'nullable|numeric|min:0|max:100',
                'bandeira' => 'nullable|numeric|min:0|max:100',
                'recorrencia' => 'nullable|string|max:10',
                'observacoes' => 'nullable|string|max:1000',
                'valor_financiamento' => 'nullable|numeric|min:0',
                'prazo_financiamento' => 'nullable|integer|min:1',
                'kit' => 'nullable|array',
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
            if (!$request->numero_proposta) {
                $request->merge(['numero_proposta' => $this->gerarNumeroProposta()]);
            }

            $data = $request->all();
            $data['usuario_id'] = $currentUser->id;
            $data['concessionaria_id'] = $currentUser->concessionaria_atual_id;
            $data['organizacao_id'] = $currentUser->organizacao_atual_id;
            $data['status'] = 'Em Análise';
            
            // Converter arrays para JSON
            if (isset($data['kit'])) {
                $data['kit'] = json_encode($data['kit']);
            }
            if (isset($data['beneficios'])) {
                $data['beneficios'] = json_encode($data['beneficios']);
            }

            $proposta = Proposta::create($data);

            DB::commit();

            \Log::info('Proposta criada', [
                'proposta_id' => $proposta->id,
                'numero_proposta' => $proposta->numero_proposta,
                'nome_cliente' => $proposta->nome_cliente,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proposta criada com sucesso!',
                'data' => $proposta
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Erro ao criar proposta', [
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

            $proposta = Proposta::with(['usuario', 'unidadesConsumidoras'])->findOrFail($id);

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
            \Log::error('Erro ao buscar proposta', [
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

            $validator = Validator::make($request->all(), [
                'nome_cliente' => 'sometimes|required|string|min:3|max:200',
                'consultor' => 'sometimes|required|string|max:100',
                'data_proposta' => 'sometimes|required|date',
                'telefone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:100',
                'endereco' => 'nullable|string|max:255',
                'economia' => 'sometimes|numeric|min:0|max:100',
                'bandeira' => 'sometimes|numeric|min:0|max:100',
                'recorrencia' => 'sometimes|string|max:10',
                'observacoes' => 'nullable|string|max:1000',
                'valor_financiamento' => 'nullable|numeric|min:0',
                'prazo_financiamento' => 'nullable|integer|min:1',
                'kit' => 'nullable|array',
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

            $data = $request->all();
            
            // Converter arrays para JSON
            if (isset($data['kit'])) {
                $data['kit'] = json_encode($data['kit']);
            }
            if (isset($data['beneficios'])) {
                $data['beneficios'] = json_encode($data['beneficios']);
            }

            $proposta->update($data);

            DB::commit();

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
            
            \Log::error('Erro ao atualizar proposta', [
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

            $validator = Validator::make($request->all(), [
                'status' => 'required|string|in:Em Análise,Aprovado,Reprovado,Fechado,Cancelado',
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

            \Log::info('Status da proposta alterado', [
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
            
            \Log::error('Erro ao alterar status da proposta', [
                'proposta_id' => $id,
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
        try {
            $currentUser = auth('api')->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $proposta = Proposta::findOrFail($id);

            $numeroProposta = $proposta->numero_proposta;
            $nomeCliente = $proposta->nome_cliente;

            DB::beginTransaction();

            // Excluir UCs vinculadas se existirem
            if (method_exists($proposta, 'unidadesConsumidoras')) {
                $proposta->unidadesConsumidoras()->delete();
            }

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
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Gerar número único para proposta
     */
    private function gerarNumeroProposta(): string
    {
        $ano = date('Y');
        $ultimaProposta = Proposta::where('numero_proposta', 'LIKE', "{$ano}/%")
                                 ->orderBy('numero_proposta', 'desc')
                                 ->first();

        if ($ultimaProposta) {
            $ultimoNumero = (int) explode('/', $ultimaProposta->numero_proposta)[1];
            $novoNumero = str_pad($ultimoNumero + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $novoNumero = '001';
        }

        return "{$ano}/{$novoNumero}";
    }

    /**
     * Estatísticas das propostas
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $currentUser = auth('api')->user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $stats = [
                'total' => Proposta::count(),
                'em_analise' => Proposta::where('status', 'Em Análise')->count(),
                'aprovadas' => Proposta::where('status', 'Aprovado')->count(),
                'reprovadas' => Proposta::where('status', 'Reprovado')->count(),
                'fechadas' => Proposta::where('status', 'Fechado')->count(),
                'canceladas' => Proposta::where('status', 'Cancelado')->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao buscar estatísticas', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }
}