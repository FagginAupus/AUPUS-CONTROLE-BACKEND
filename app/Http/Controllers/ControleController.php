<?php

namespace App\Http\Controllers;

use App\Models\Configuracao;
use App\Models\Usuario;
use App\Models\Notificacao;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ConfiguracaoController extends Controller implements HasMiddleware
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
     * Listar todas as configurações
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

        // Apenas admins podem visualizar configurações
        if ($currentUser->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem acessar configurações'
            ], 403);
        }

        try {
            $query = Configuracao::query()->with('updatedBy');

            // Filtro por grupo
            if ($request->filled('grupo')) {
                $query->porGrupo($request->grupo);
            }

            // Filtro por tipo
            if ($request->filled('tipo')) {
                $query->porTipo($request->tipo);
            }

            // Busca por chave ou descrição
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('chave', 'ILIKE', "%{$search}%")
                      ->orWhere('descricao', 'ILIKE', "%{$search}%");
                });
            }

            // Ordenação
            $orderBy = $request->get('order_by', 'grupo');
            $orderDirection = $request->get('order_direction', 'asc');
            $query->orderBy($orderBy, $orderDirection)->orderBy('chave', 'asc');

            $configuracoes = $query->get();

            return response()->json([
                'success' => true,
                'data' => $configuracoes->map(function ($config) {
                    return $this->transformConfiguracaoForAPI($config);
                })
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao listar configurações', [
                'user_id' => $currentUser->id,
                'error' => $e->getMessage(),
                'filters' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Buscar configuração por chave
     */
    public function show(string $chave): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Algumas configurações são públicas para consultores
        $configuracoesPúblicas = [
            'economia_padrao', 'bandeira_padrao', 'recorrencia_padrao', 
            'beneficios_padrao', 'empresa_nome', 'sistema_versao'
        ];

        if (!$currentUser->isAdmin() && !in_array($chave, $configuracoesPúblicas)) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado'
            ], 403);
        }

        try {
            $configuracao = Configuracao::with('updatedBy')->porChave($chave)->first();

            if (!$configuracao) {
                return response()->json([
                    'success' => false,
                    'message' => 'Configuração não encontrada'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->transformConfiguracaoForAPI($configuracao)
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao buscar configuração', [
                'user_id' => $currentUser->id,
                'chave' => $chave,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Criar nova configuração
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

        // Apenas admins podem criar configurações
        if (!$currentUser->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem criar configurações'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'chave' => 'required|string|max:100|unique:configuracoes,chave',
            'valor' => 'required',
            'tipo' => 'required|in:string,integer,float,boolean,json',
            'descricao' => 'required|string|max:500',
            'grupo' => 'nullable|string|max:50'
        ], [
            'chave.required' => 'Chave é obrigatória',
            'chave.unique' => 'Esta chave já existe',
            'valor.required' => 'Valor é obrigatório',
            'tipo.required' => 'Tipo é obrigatório',
            'tipo.in' => 'Tipo deve ser: string, integer, float, boolean ou json',
            'descricao.required' => 'Descrição é obrigatória'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Validar e converter valor conforme tipo
            $valor = $this->processValueByType($request->valor, $request->tipo);

            $configuracao = Configuracao::create([
                'chave' => $request->chave,
                'valor' => $valor,
                'tipo' => $request->tipo,
                'descricao' => $request->descricao,
                'grupo' => $request->grupo ?? 'geral',
                'updated_by' => $currentUser->id
            ]);

            \Log::info('Configuração criada', [
                'configuracao_id' => $configuracao->id,
                'chave' => $configuracao->chave,
                'tipo' => $configuracao->tipo,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Configuração criada com sucesso!',
                'data' => $this->transformConfiguracaoForAPI($configuracao->load('updatedBy'))
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Erro ao criar configuração', [
                'user_id' => $currentUser->id,
                'request_data' => $request->except(['password', 'token']),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor ao criar configuração'
            ], 500);
        }
    }

    /**
     * Atualizar configuração
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

        // Apenas admins podem atualizar configurações
        if (!$currentUser->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem atualizar configurações'
            ], 403);
        }

        try {
            $configuracao = Configuracao::findOrFail($id);

            if (!$configuracao) {
                return response()->json([
                    'success' => false,
                    'message' => 'Configuração não encontrada'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'valor' => 'required',
                'descricao' => 'sometimes|required|string|max:500',
                'grupo' => 'sometimes|nullable|string|max:50'
            ], [
                'valor.required' => 'Valor é obrigatório',
                'descricao.required' => 'Descrição é obrigatória'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Processar valor conforme tipo
            $valor = $this->processValueByType($request->valor, $configuracao->tipo);

            $dadosAtualizacao = [
                'valor' => $valor,
                'updated_by' => $currentUser->id
            ];

            if ($request->has('descricao')) {
                $dadosAtualizacao['descricao'] = $request->descricao;
            }

            if ($request->has('grupo')) {
                $dadosAtualizacao['grupo'] = $request->grupo ?? 'geral';
            }

            $configuracao->update($dadosAtualizacao);

            // Limpar cache se existir
            Cache::forget("config.{$chave}");

            \Log::info('Configuração atualizada', [
                'configuracao_id' => $configuracao->id,
                'chave' => $configuracao->chave,
                'valor_antigo' => $configuracao->getOriginal('valor'),
                'valor_novo' => $valor,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Configuração atualizada com sucesso!',
                'data' => $this->transformConfiguracaoForAPI($configuracao->fresh(['updatedBy']))
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao atualizar configuração', [
                'chave' => $chave,
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
     * Buscar configurações por grupo
     */
    public function getByGroup(string $grupo): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$currentUser->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem acessar configurações'
            ], 403);
        }

        try {
            $configuracoes = Configuracao::query()
                                        ->with('updatedBy')
                                        ->porGrupo($grupo)
                                        ->orderBy('chave')
                                        ->get();

            return response()->json([
                'success' => true,
                'data' => $configuracoes->map(function ($config) {
                    return $this->transformConfiguracaoForAPI($config);
                })
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao buscar configurações por grupo', [
                'grupo' => $grupo,
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
     * Atualizar múltiplas configurações
     */
    public function updateBulk(Request $request): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$currentUser->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem atualizar configurações'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'configuracoes' => 'required|array|min:1',
            'configuracoes.*.chave' => 'required|string',
            'configuracoes.*.valor' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        $atualizadas = 0;
        $erros = [];

        DB::beginTransaction();

        try {
            foreach ($request->configuracoes as $configData) {
                try {
                    $configuracao = Configuracao::porChave($configData['chave'])->first();
                    
                    if ($configuracao) {
                        $valor = $this->processValueByType($configData['valor'], $configuracao->tipo);
                        
                        $configuracao->update([
                            'valor' => $valor,
                            'updated_by' => $currentUser->id
                        ]);

                        Cache::forget("config.{$configData['chave']}");
                        $atualizadas++;
                    } else {
                        $erros[] = "Configuração '{$configData['chave']}' não encontrada";
                    }
                } catch (\Exception $e) {
                    $erros[] = "Erro na configuração '{$configData['chave']}': " . $e->getMessage();
                }
            }

            DB::commit();

            \Log::info('Atualização em lote de configurações', [
                'user_id' => $currentUser->id,
                'total_processadas' => count($request->configuracoes),
                'atualizadas' => $atualizadas,
                'erros' => count($erros)
            ]);

            return response()->json([
                'success' => true,
                'message' => "Atualização concluída! {$atualizadas} configurações atualizadas.",
                'data' => [
                    'atualizadas' => $atualizadas,
                    'erros' => $erros
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Erro na atualização em lote de configurações', [
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
     * Limpar cache de configurações
     */
    public function clearCache(): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$currentUser->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem limpar cache'
            ], 403);
        }

        try {
            // Buscar todas as chaves de configuração
            $chaves = Configuracao::pluck('chave');
            
            $cacheLimpo = 0;
            foreach ($chaves as $chave) {
                if (Cache::forget("config.{$chave}")) {
                    $cacheLimpo++;
                }
            }

            \Log::info('Cache de configurações limpo', [
                'user_id' => $currentUser->id,
                'total_chaves' => $chaves->count(),
                'cache_limpo' => $cacheLimpo
            ]);

            return response()->json([
                'success' => true,
                'message' => "Cache limpo! {$cacheLimpo} configurações removidas do cache."
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao limpar cache de configurações', [
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
     * Processar valor conforme tipo
     */
    private function processValueByType($valor, string $tipo)
    {
        switch ($tipo) {
            case 'integer':
                return (int) $valor;
            case 'float':
                return (float) $valor;
            case 'boolean':
                return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
            case 'json':
                if (is_string($valor)) {
                    $decoded = json_decode($valor, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \InvalidArgumentException('JSON inválido: ' . json_last_error_msg());
                    }
                    return $decoded;
                }
                return $valor;
            case 'string':
            default:
                return (string) $valor;
        }
    }

    /**
     * Transformar configuração para resposta da API
     */
    private function transformConfiguracaoForAPI(Configuracao $configuracao): array
    {
        return [
            'id' => $configuracao->id,
            'chave' => $configuracao->chave,
            'valor' => $configuracao->valor,
            'tipo' => $configuracao->tipo,
            'descricao' => $configuracao->descricao,
            'grupo' => $configuracao->grupo,
            'updated_by' => $configuracao->updatedBy ? [
                'id' => $configuracao->updatedBy->id,
                'nome' => $configuracao->updatedBy->nome
            ] : null,
            'created_at' => $configuracao->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $configuracao->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
    /**
     * Excluir configuração
     */
    public function destroy(string $chave): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if ($currentUser->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem excluir configurações'
            ], 403);
        }

        try {
            $configuracao = Configuracao::porChave($chave)->first();

            if (!$configuracao) {
                return response()->json([
                    'success' => false,
                    'message' => 'Configuração não encontrada'
                ], 404);
            }

            $configuracao->delete();
            Cache::forget("config.{$chave}");

            \Log::info('Configuração excluída', [
                'chave' => $chave,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Configuração excluída com sucesso!'
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao excluir configuração', [
                'chave' => $chave,
                'user_id' => $currentUser->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }
}