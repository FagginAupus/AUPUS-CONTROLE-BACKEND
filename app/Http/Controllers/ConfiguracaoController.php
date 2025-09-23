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

class ConfiguracaoController extends Controller
{
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
        if (!$currentUser->isAdminOrAnalista()) {
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
            
            $allowedOrderBy = ['grupo', 'chave', 'tipo', 'created_at', 'updated_at'];
            if (!in_array($orderBy, $allowedOrderBy)) {
                $orderBy = 'grupo';
            }
            
            $query->orderBy($orderBy, $orderDirection);

            $configuracoes = $query->get();

            // Agrupar por grupo para melhor organização
            $configuracoesPorGrupo = $configuracoes->groupBy('grupo')->map(function ($configs, $grupo) {
                return [
                    'grupo' => $grupo,
                    'total' => $configs->count(),
                    'configuracoes' => $configs->map(function ($config) {
                        return $this->transformConfiguracaoForAPI($config);
                    })->values()
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => $configuracoesPorGrupo,
                'meta' => [
                    'total_configuracoes' => $configuracoes->count(),
                    'grupos_disponiveis' => $configuracoes->pluck('grupo')->unique()->values(),
                    'tipos_disponiveis' => $configuracoes->pluck('tipo')->unique()->values()
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao listar configurações', [
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
     * Obter configurações por grupo
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

        // Apenas admins podem visualizar configurações
        if (!$currentUser->isAdminOrAnalista()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem acessar configurações'
            ], 403);
        }

        try {
            $configuracoes = Configuracao::getPorGrupo($grupo);

            if (empty($configuracoes)) {
                return response()->json([
                    'success' => false,
                    'message' => "Nenhuma configuração encontrada para o grupo: {$grupo}"
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'grupo' => $grupo,
                    'configuracoes' => $configuracoes
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao buscar configurações por grupo', [
                'user_id' => $currentUser->id,
                'grupo' => $grupo,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Obter configuração específica
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
            'beneficios_padrao', 'empresa_nome', 'sistema_versao', 'calibragem_global'
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
     * ✅ NOVO: Buscar apenas o valor da calibragem global
     */
    public function getCalibragemGlobal(): JsonResponse
    {
        try {
            $configuracao = Configuracao::porChave('calibragem_global')->first();
            
            $valor = $configuracao ? floatval($configuracao->valor) : 0.0;

            return response()->json([
                'success' => true,
                'valor' => $valor
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar calibragem global',
                'valor' => 0.0
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
        if (!$currentUser->isAdminOrAnalista()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem criar configurações'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'chave' => 'required|string|max:50|unique:configuracoes,chave',
            'valor' => 'required',
            'tipo' => 'required|in:string,number,boolean,json',
            'descricao' => 'nullable|string|max:500',
            'grupo' => 'nullable|string|max:50'
        ], [
            'chave.required' => 'Chave é obrigatória',
            'chave.unique' => 'Esta chave já existe',
            'valor.required' => 'Valor é obrigatório',
            'tipo.required' => 'Tipo é obrigatório',
            'tipo.in' => 'Tipo deve ser: string, number, boolean ou json'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Validar valor baseado no tipo
            $valor = $request->valor;
            $tipo = $request->tipo;

            switch ($tipo) {
                case 'number':
                    if (!is_numeric($valor)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Valor deve ser numérico para o tipo number'
                        ], 422);
                    }
                    $valor = (string) $valor;
                    break;

                case 'boolean':
                    $valor = $request->boolean('valor') ? '1' : '0';
                    break;

                case 'json':
                    if (is_array($valor)) {
                        $valor = json_encode($valor);
                    } elseif (is_string($valor)) {
                        $decoded = json_decode($valor);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Valor deve ser um JSON válido'
                            ], 422);
                        }
                    }
                    break;

                default:
                    $valor = (string) $valor;
                    break;
            }

            $configuracao = Configuracao::create([
                'chave' => $request->chave,
                'valor' => $valor,
                'tipo' => $tipo,
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
     * Atualizar configuração
     */
    public function update(Request $request, string $chave): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Apenas admins podem editar configurações
        if (!$currentUser->isAdminOrAnalista()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem editar configurações'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'valor' => 'required',
            'descricao' => 'nullable|string|max:500'
        ], [
            'valor.required' => 'Valor é obrigatório'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $configuracao = Configuracao::with('updatedBy')->porChave($chave)->first();

            if (!$configuracao) {
                return response()->json([
                    'success' => false,
                    'message' => 'Configuração não encontrada'
                ], 404);
            }

            // ✅ NOVA VALIDAÇÃO: Especial para calibragem global
            if ($chave === 'calibragem_global') {
                $valor = floatval($request->valor);
                if ($valor < 0 || $valor > 100) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Calibragem deve estar entre 0 e 100%'
                    ], 422);
                }
            }

            $valorOriginal = $configuracao->valor;
            $valor = $request->valor;
            $tipo = $configuracao->tipo;

            // Validar e converter valor baseado no tipo
            switch ($tipo) {
                case 'number':
                    if (!is_numeric($valor)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Valor deve ser numérico para o tipo number'
                        ], 422);
                    }
                    $valor = (string) $valor;
                    break;

                case 'boolean':
                    $valor = $request->boolean('valor') ? '1' : '0';
                    break;

                case 'json':
                    if (is_array($valor)) {
                        $valor = json_encode($valor);
                    } elseif (is_string($valor)) {
                        $decoded = json_decode($valor);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Valor deve ser um JSON válido'
                            ], 422);
                        }
                    }
                    break;

                default:
                    $valor = (string) $valor;
                    break;
            }

            $configuracao->valor = $valor;
            $configuracao->updated_by = $currentUser->id;
            
            if ($request->has('descricao')) {
                $configuracao->descricao = $request->descricao;
            }

            $configuracao->save();

            // Limpar cache relacionado
            Cache::forget("config_{$chave}");

            \Log::info('Configuração atualizada', [
                'configuracao_id' => $configuracao->id,
                'chave' => $chave,
                'valor_anterior' => $valorOriginal,
                'valor_novo' => $valor,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Configuração atualizada com sucesso!',
                'data' => $this->transformConfiguracaoForAPI($configuracao->load('updatedBy'))
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

        // Apenas admins podem editar configurações
        if (!$currentUser->isAdminOrAnalista()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem editar configurações'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'configuracoes' => 'required|array|min:1',
            'configuracoes.*.chave' => 'required|string',
            'configuracoes.*.valor' => 'required'
        ], [
            'configuracoes.required' => 'Lista de configurações é obrigatória',
            'configuracoes.array' => 'Configurações devem ser um array',
            'configuracoes.min' => 'Pelo menos uma configuração deve ser fornecida'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $configuracoesSolicitadas = collect($request->configuracoes)->keyBy('chave');
            $chaves = $configuracoesSolicitadas->keys()->toArray();

            // Buscar configurações existentes
            $configuracoesExistentes = Configuracao::whereIn('chave', $chaves)->get()->keyBy('chave');

            $resultados = [];
            $sucessos = 0;
            $falhas = 0;

            foreach ($configuracoesSolicitadas as $chave => $dados) {
                try {
                    $configuracao = $configuracoesExistentes->get($chave);

                    if (!$configuracao) {
                        $resultados[$chave] = [
                            'sucesso' => false,
                            'erro' => 'Configuração não encontrada'
                        ];
                        $falhas++;
                        continue;
                    }

                    $valorOriginal = $configuracao->valor;
                    $valor = $dados['valor'];
                    $tipo = $configuracao->tipo;

                    // Validar e converter valor
                    switch ($tipo) {
                        case 'number':
                            if (!is_numeric($valor)) {
                                throw new \Exception('Valor deve ser numérico');
                            }
                            $valor = (string) $valor;
                            break;

                        case 'boolean':
                            $valor = $valor ? '1' : '0';
                            break;

                        case 'json':
                            if (is_array($valor)) {
                                $valor = json_encode($valor);
                            } elseif (is_string($valor)) {
                                $decoded = json_decode($valor);
                                if (json_last_error() !== JSON_ERROR_NONE) {
                                    throw new \Exception('Valor deve ser um JSON válido');
                                }
                            }
                            break;

                        default:
                            $valor = (string) $valor;
                            break;
                    }

                    $configuracao->valor = $valor;
                    $configuracao->updated_by = $currentUser->id;
                    $configuracao->save();

                    // Limpar cache
                    Cache::forget("config_{$chave}");

                    $resultados[$chave] = [
                        'sucesso' => true,
                        'valor_anterior' => $valorOriginal,
                        'valor_novo' => $valor
                    ];

                    $sucessos++;

                } catch (\Exception $e) {
                    $resultados[$chave] = [
                        'sucesso' => false,
                        'erro' => $e->getMessage()
                    ];
                    $falhas++;
                }
            }

            // Notificar consultores sobre mudanças importantes
            if ($sucessos > 0) {
                $this->notificarMudancasConfiguracoes($chaves, $currentUser);
            }

            \Log::info('Configurações atualizadas em lote', [
                'total_solicitadas' => count($configuracoesSolicitadas),
                'sucessos' => $sucessos,
                'falhas' => $falhas,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => "Configurações atualizadas: {$sucessos} sucessos, {$falhas} falhas",
                'data' => [
                    'total' => count($configuracoesSolicitadas),
                    'sucessos' => $sucessos,
                    'falhas' => $falhas,
                    'resultados' => $resultados
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao atualizar configurações em lote', [
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
     * Resetar configurações para padrão
     */
    public function resetToDefault(Request $request): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Apenas admins podem resetar configurações
        if (!$currentUser->isAdminOrAnalista()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem resetar configurações'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'chaves' => 'nullable|array',
            'chaves.*' => 'string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $chaves = $request->chaves ?? [];
            $configuracoesReset = Configuracao::resetarParaPadrao($chaves, $currentUser);

            // Limpar cache
            if ($configuracoesReset > 0) {
                $gruposAfetados = ['geral', 'calibragem', 'propostas', 'sistema'];
                foreach ($gruposAfetados as $grupo) {
                    Cache::forget("config_grupo_{$grupo}");
                }
            }

            \Log::info('Configurações resetadas para padrão', [
                'chaves_solicitadas' => $chaves,
                'configuracoes_reset' => $configuracoesReset,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => "{$configuracoesReset} configuração(ões) resetada(s) para o padrão!",
                'data' => [
                    'configuracoes_reset' => $configuracoesReset,
                    'chaves_resetadas' => $chaves ?: ['todas as configurações padrão']
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao resetar configurações', [
                'user_id' => $currentUser->id,
                'chaves' => $request->chaves,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Exportar configurações
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

        // Apenas admins podem exportar configurações
        if (!$currentUser->isAdminOrAnalista()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem exportar configurações'
            ], 403);
        }

        try {
            $grupo = $request->grupo;
            $configuracoes = Configuracao::exportar($grupo);

            return response()->json([
                'success' => true,
                'data' => $configuracoes,
                'meta' => [
                    'total_configuracoes' => count($configuracoes),
                    'grupo_filtrado' => $grupo,
                    'data_exportacao' => now()->format('d/m/Y H:i:s'),
                    'exportado_por' => $currentUser->nome
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao exportar configurações', [
                'user_id' => $currentUser->id,
                'grupo' => $request->grupo,
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

        // Apenas admins podem limpar cache
        if (!$currentUser->isAdminOrAnalista()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem limpar cache'
            ], 403);
        }

        try {
            // Limpar cache de todas as configurações
            $chaves = Configuracao::pluck('chave');
            foreach ($chaves as $chave) {
                Cache::forget("config_{$chave}");
            }

            // Limpar cache de grupos
            $grupos = ['geral', 'calibragem', 'propostas', 'sistema'];
            foreach ($grupos as $grupo) {
                Cache::forget("config_grupo_{$grupo}");
            }

            \Log::info('Cache de configurações limpo', [
                'user_id' => $currentUser->id,
                'chaves_limpas' => $chaves->count(),
                'grupos_limpos' => count($grupos)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cache de configurações limpo com sucesso!',
                'data' => [
                    'chaves_limpas' => $chaves->count(),
                    'grupos_limpos' => count($grupos)
                ]
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

    // Método auxiliar para transformação de dados
    private function transformConfiguracaoForAPI(Configuracao $configuracao): array
    {
        return [
            'id' => $configuracao->id,
            'chave' => $configuracao->chave,
            'valor' => $configuracao->valor,
            'valor_tipado' => $configuracao->getValorTipado(),
            'valor_formatado' => $configuracao->getValorFormatado(),
            'tipo' => $configuracao->tipo,
            'descricao' => $configuracao->descricao,
            'grupo' => $configuracao->grupo,
            'created_at' => $configuracao->created_at->format('d/m/Y H:i'),
            'updated_at' => $configuracao->updated_at->format('d/m/Y H:i'),
            'updated_by' => $configuracao->updatedBy ? [
                'id' => $configuracao->updatedBy->id,
                'nome' => $configuracao->updatedBy->nome,
                'email' => $configuracao->updatedBy->email
            ] : null
        ];
    }

    // Método auxiliar para notificar mudanças importantes
    private function notificarMudancasConfiguracoes(array $chaves, Usuario $currentUser): void
    {
        $chavesImportantes = [
            'economia_padrao', 'bandeira_padrao', 'recorrencia_padrao',
            'calibragem_global', 'beneficios_padrao'
        ];

        $chavesAfetadas = array_intersect($chaves, $chavesImportantes);

        if (!empty($chavesAfetadas)) {
            // Notificar consultores
            Notificacao::notificarHierarquia(
                ['consultor'],
                'Configurações do sistema alteradas',
                'O administrador alterou configurações importantes do sistema: ' . implode(', ', $chavesAfetadas),
                'sistema',
                '/dashboard'
            );
        }
    }
}