<?php

namespace App\Http\Controllers;

use App\Models\ControleClube;
use App\Models\Usuario;
use App\Models\Proposta;
use App\Models\UnidadeConsumidora;
use App\Models\Notificacao;
use App\Models\Configuracao;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;
use Carbon\Carbon;

class ControleController extends Controller
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Listar controles com filtros hierárquicos
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
            $query = ControleClube::query()
                                 ->with(['usuario', 'proposta', 'unidadeConsumidora', 'usinaGeradora'])
                                 ->comFiltroHierarquico($currentUser);

            // Filtros específicos
            if ($request->filled('ativo')) {
                $query->where('ativo', $request->boolean('ativo'));
            }

            if ($request->filled('com_ug')) {
                if ($request->boolean('com_ug')) {
                    $query->comUG();
                } else {
                    $query->semUG();
                }
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

            if ($request->filled('numero_uc')) {
                $query->where('numero_uc', 'ILIKE', "%{$request->numero_uc}%");
            }

            if ($request->filled('data_inicio') && $request->filled('data_fim')) {
                $query->whereBetween('data_inicio_clube', [
                    Carbon::parse($request->data_inicio)->format('Y-m-d'),
                    Carbon::parse($request->data_fim)->format('Y-m-d')
                ]);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('numero_proposta', 'ILIKE', "%{$search}%")
                      ->orWhere('numero_uc', 'ILIKE', "%{$search}%")
                      ->orWhere('nome_cliente', 'ILIKE', "%{$search}%")
                      ->orWhere('consultor', 'ILIKE', "%{$search}%");
                });
            }

            // Ordenação
            $orderBy = $request->get('order_by', 'created_at');
            $orderDirection = $request->get('order_direction', 'desc');
            
            $allowedOrderBy = [
                'created_at', 'data_inicio_clube', 'numero_proposta', 
                'numero_uc', 'nome_cliente', 'consultor', 'consumo_medio'
            ];
            
            if (!in_array($orderBy, $allowedOrderBy)) {
                $orderBy = 'created_at';
            }
            
            $query->orderBy($orderBy, $orderDirection);

            // Paginação
            $perPage = min($request->get('per_page', 15), 100);
            $controles = $query->paginate($perPage);

            // Transformar dados
            $controles->getCollection()->transform(function ($controle) use ($currentUser) {
                return $this->transformControleForAPI($controle, $currentUser);
            });

            // Estatísticas
            $estatisticas = ControleClube::getEstatisticas(['usuario' => $currentUser]);

            return response()->json([
                'success' => true,
                'data' => $controles,
                'statistics' => $estatisticas
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao listar controles', [
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
     * Criar novo controle
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
            'proposta_id' => 'required|exists:propostas,id',
            'uc_id' => 'nullable|exists:unidades_consumidoras,id',
            'numero_proposta' => 'required|string|max:50',
            'numero_uc' => 'nullable|string|max:50',
            'nome_cliente' => 'required|string|max:200',
            'consultor' => 'required|string|max:100',
            'consumo_medio' => 'nullable|numeric|min:0',
            'geracao_prevista' => 'nullable|numeric|min:0',
            'economia_percentual' => 'nullable|numeric|min:0|max:100',
            'desconto_bandeira' => 'nullable|numeric|min:0|max:100',
            'recorrencia' => 'nullable|string|max:10',
            'data_inicio_clube' => 'nullable|date',
            'observacoes' => 'nullable|string|max:1000',
            'ug_id' => 'nullable|exists:unidades_consumidoras,id'
        ], [
            'proposta_id.required' => 'Proposta é obrigatória',
            'proposta_id.exists' => 'Proposta não encontrada',
            'numero_proposta.required' => 'Número da proposta é obrigatório',
            'nome_cliente.required' => 'Nome do cliente é obrigatório',
            'consultor.required' => 'Consultor é obrigatório',
            'ug_id.exists' => 'UG não encontrada'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Verificar se proposta existe e está fechada
            $proposta = Proposta::find($request->proposta_id);
            if (!$proposta) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proposta não encontrada'
                ], 404);
            }

            if ($proposta->status !== 'Fechado') {
                return response()->json([
                    'success' => false,
                    'message' => 'Apenas propostas fechadas podem gerar controle'
                ], 422);
            }

            // Verificar se usuário pode acessar esta proposta
            if (!$currentUser->canAccessData(['consultor' => $proposta->consultor])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            // Verificar se já existe controle para esta proposta/UC
            if ($request->filled('numero_uc')) {
                $existeControle = ControleClube::where('numero_proposta', $request->numero_proposta)
                                             ->where('numero_uc', $request->numero_uc)
                                             ->exists();

                if ($existeControle) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Já existe controle para esta proposta/UC'
                    ], 422);
                }
            }

            // Verificar UG se fornecida
            $ug = null;
            if ($request->filled('ug_id')) {
                $ug = UnidadeConsumidora::find($request->ug_id);
                if (!$ug || !$ug->is_ug) {
                    return response()->json([
                        'success' => false,
                        'message' => 'UG inválida'
                    ], 422);
                }
            }

            $controle = ControleClube::create([
                'proposta_id' => $request->proposta_id,
                'uc_id' => $request->uc_id,
                'ug_id' => $request->ug_id,
                'usuario_id' => $currentUser->id,
                'numero_proposta' => $request->numero_proposta,
                'numero_uc' => $request->numero_uc,
                'nome_cliente' => trim($request->nome_cliente),
                'consultor' => trim($request->consultor),
                'consumo_medio' => $request->consumo_medio,
                'geracao_prevista' => $request->geracao_prevista ?? ($ug ? $ug->capacidade_calculada : null),
                'economia_percentual' => $request->economia_percentual ?? $proposta->economia,
                'desconto_bandeira' => $request->desconto_bandeira ?? $proposta->bandeira,
                'recorrencia' => $request->recorrencia ?? $proposta->recorrencia,
                'data_inicio_clube' => $request->data_inicio_clube ? 
                    Carbon::parse($request->data_inicio_clube)->format('Y-m-d') : 
                    Carbon::now()->format('Y-m-d'),
                'observacoes' => $request->observacoes ? trim($request->observacoes) : null,
                'ativo' => true
            ]);

            // Criar notificação se UG foi vinculada
            if ($ug) {
                Notificacao::criarUGVinculada($controle, $ug);
            }

            \Log::info('Controle clube criado', [
                'controle_id' => $controle->id,
                'numero_proposta' => $controle->numero_proposta,
                'numero_uc' => $controle->numero_uc,
                'user_id' => $currentUser->id,
                'tem_ug' => $ug ? true : false
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Controle criado com sucesso!',
                'data' => $this->transformControleForAPI($controle->load([
                    'usuario', 'proposta', 'unidadeConsumidora', 'usinaGeradora'
                ]), $currentUser)
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Erro ao criar controle', [
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
     * Exibir controle específico
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
            $controle = ControleClube::with([
                'usuario', 'proposta', 'unidadeConsumidora', 'usinaGeradora'
            ])->findOrFail($id);

            // Verificar permissão
            if (!$this->canViewControle($currentUser, $controle)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $this->transformControleDetailForAPI($controle, $currentUser)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Controle não encontrado'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erro ao buscar controle', [
                'controle_id' => $id,
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
     * Aplicar calibragem no controle
     */
    public function applyCalibragem(Request $request, string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Verificar se usuário pode aplicar calibragem
        if (!$currentUser->isAdmin() && !$currentUser->isConsultor()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores e consultores podem aplicar calibragem'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'percentual_calibragem' => 'required|numeric|min:-50|max:100'
        ], [
            'percentual_calibragem.required' => 'Percentual de calibragem é obrigatório',
            'percentual_calibragem.min' => 'Percentual não pode ser menor que -50%',
            'percentual_calibragem.max' => 'Percentual não pode ser maior que 100%'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $controle = ControleClube::findOrFail($id);

            // Verificar permissão
            if (!$this->canEditControle($currentUser, $controle)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            // Verificar se pode aplicar calibragem
            $errosValidacao = $controle->isValidForCalibragem();
            if (!empty($errosValidacao)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle não pode receber calibragem',
                    'errors' => $errosValidacao
                ], 422);
            }

            $percentual = $request->percentual_calibragem;
            $consumoAnterior = $controle->consumo_medio;

            $controle->aplicarCalibragem($percentual, $currentUser);
            $consumoNovo = $controle->consumo_medio;

            // Criar notificação
            Notificacao::criarCalibragemAplicada($controle, $percentual, $currentUser);

            \Log::info('Calibragem aplicada no controle', [
                'controle_id' => $controle->id,
                'numero_proposta' => $controle->numero_proposta,
                'numero_uc' => $controle->numero_uc,
                'percentual' => $percentual,
                'consumo_anterior' => $consumoAnterior,
                'consumo_novo' => $consumoNovo,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => "Calibragem de " . number_format($percentual, 2, ',', '.') . "% aplicada com sucesso!",
                'data' => [
                    'id' => $controle->id,
                    'percentual_aplicado' => $percentual,
                    'consumo_anterior' => $consumoAnterior,
                    'consumo_novo' => $consumoNovo,
                    'diferenca' => $consumoNovo - $consumoAnterior,
                    'data_aplicacao' => $controle->data_ultima_calibragem->format('d/m/Y H:i'),
                    'aplicado_por' => $currentUser->nome
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Controle não encontrado'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erro ao aplicar calibragem no controle', [
                'controle_id' => $id,
                'user_id' => $currentUser->id,
                'percentual' => $request->percentual_calibragem,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Aplicar calibragem global
     */
    public function applyGlobalCalibragem(Request $request): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Apenas admin pode aplicar calibragem global
        if (!$currentUser->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem aplicar calibragem global'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'percentual_calibragem' => 'required|numeric|min:-50|max:100',
            'aplicar_apenas_ativos' => 'nullable|boolean',
            'consultor_especifico' => 'nullable|string|max:100'
        ], [
            'percentual_calibragem.required' => 'Percentual de calibragem é obrigatório',
            'percentual_calibragem.min' => 'Percentual não pode ser menor que -50%',
            'percentual_calibragem.max' => 'Percentual não pode ser maior que 100%'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $percentual = $request->percentual_calibragem;
            $apenasAtivos = $request->boolean('aplicar_apenas_ativos', true);
            $consultorEspecifico = $request->consultor_especifico;

            $query = ControleClube::query()->where('consumo_medio', '>', 0);

            if ($apenasAtivos) {
                $query->ativos();
            }

            if ($consultorEspecifico) {
                $query->where('consultor', 'ILIKE', "%{$consultorEspecifico}%");
            }

            $controles = $query->get();

            if ($controles->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum controle encontrado para aplicar calibragem'
                ], 404);
            }

            DB::beginTransaction();

            $sucessos = 0;
            $falhas = 0;
            $detalhes = [];

            foreach ($controles as $controle) {
                try {
                    $consumoAnterior = $controle->consumo_medio;
                    $controle->aplicarCalibragem($percentual, $currentUser);
                    
                    $detalhes[] = [
                        'numero_proposta' => $controle->numero_proposta,
                        'numero_uc' => $controle->numero_uc,
                        'consumo_anterior' => $consumoAnterior,
                        'consumo_novo' => $controle->consumo_medio,
                        'sucesso' => true
                    ];
                    
                    $sucessos++;
                } catch (\Exception $e) {
                    $detalhes[] = [
                        'numero_proposta' => $controle->numero_proposta,
                        'numero_uc' => $controle->numero_uc,
                        'erro' => $e->getMessage(),
                        'sucesso' => false
                    ];
                    
                    $falhas++;
                }
            }

            // Salvar configuração de calibragem global
            Configuracao::setValor('calibragem_global', $percentual, $currentUser, 'number');

            // Notificar todos os usuários afetados
            if ($sucessos > 0) {
                $usuariosAfetados = $controles->pluck('usuario_id')->unique();
                foreach ($usuariosAfetados as $usuarioId) {
                    $usuario = Usuario::find($usuarioId);
                    if ($usuario) {
                        Notificacao::criarNotificacaoSistema(
                            $usuario,
                            'Calibragem global aplicada',
                            "Calibragem de " . number_format($percentual, 2, ',', '.') . "% foi aplicada em seus controles pelo administrador.",
                            'calibragem',
                            '/controle'
                        );
                    }
                }
            }

            DB::commit();

            \Log::info('Calibragem global aplicada', [
                'percentual' => $percentual,
                'total_controles' => $controles->count(),
                'sucessos' => $sucessos,
                'falhas' => $falhas,
                'apenas_ativos' => $apenasAtivos,
                'consultor_especifico' => $consultorEspecifico,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => "Calibragem global aplicada! {$sucessos} sucessos, {$falhas} falhas.",
                'data' => [
                    'percentual_aplicado' => $percentual,
                    'total_controles' => $controles->count(),
                    'sucessos' => $sucessos,
                    'falhas' => $falhas,
                    'detalhes' => $detalhes
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Erro ao aplicar calibragem global', [
                'user_id' => $currentUser->id,
                'percentual' => $request->percentual_calibragem,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Vincular UG ao controle
     */
    public function linkUG(Request $request, string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Verificar se usuário pode gerenciar UGs
        if (!$currentUser->isAdmin() && !$currentUser->isConsultor()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores e consultores podem gerenciar UGs'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'ug_id' => 'required|exists:unidades_consumidoras,id'
        ], [
            'ug_id.required' => 'UG é obrigatória',
            'ug_id.exists' => 'UG não encontrada'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $controle = ControleClube::findOrFail($id);

            // Verificar permissão
            if (!$this->canEditControle($currentUser, $controle)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            // Verificar se controle está ativo
            if (!$controle->ativo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle deve estar ativo para vincular UG'
                ], 422);
            }

            $ug = UnidadeConsumidora::findOrFail($request->ug_id);

            // Verificar se é realmente uma UG
            if (!$ug->is_ug) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unidade selecionada não é uma UG'
                ], 422);
            }

            // Verificar se UG já está vinculada
            if ($controle->ug_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle já possui UG vinculada'
                ], 422);
            }

            $controle->vincularUG($ug, $currentUser);

            // Criar notificação
            Notificacao::criarUGVinculada($controle, $ug);

            \Log::info('UG vinculada ao controle', [
                'controle_id' => $controle->id,
                'ug_id' => $ug->id,
                'nome_usina' => $ug->nome_usina,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => "UG {$ug->nome_usina} vinculada com sucesso!",
                'data' => $this->transformControleForAPI($controle->load([
                    'usuario', 'proposta', 'unidadeConsumidora', 'usinaGeradora'
                ]), $currentUser)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Controle ou UG não encontrado'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erro ao vincular UG', [
                'controle_id' => $id,
                'ug_id' => $request->ug_id,
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
     * Desvincular UG do controle
     */
    public function unlinkUG(Request $request, string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Verificar se usuário pode gerenciar UGs
        if (!$currentUser->isAdmin() && !$currentUser->isConsultor()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores e consultores podem gerenciar UGs'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'motivo' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $controle = ControleClube::with('usinaGeradora')->findOrFail($id);

            // Verificar permissão
            if (!$this->canEditControle($currentUser, $controle)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            // Verificar se tem UG vinculada
            if (!$controle->ug_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle não possui UG vinculada'
                ], 422);
            }

            $nomeUsina = $controle->usinaGeradora->nome_usina ?? 'UG Desconhecida';
            $controle->desvincularUG($currentUser, $request->motivo ?? '');

            \Log::info('UG desvinculada do controle', [
                'controle_id' => $controle->id,
                'nome_usina' => $nomeUsina,
                'motivo' => $request->motivo,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => "UG {$nomeUsina} desvinculada com sucesso!",
                'data' => $this->transformControleForAPI($controle->load([
                    'usuario', 'proposta', 'unidadeConsumidora', 'usinaGeradora'
                ]), $currentUser)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Controle não encontrado'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erro ao desvincular UG', [
                'controle_id' => $id,
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
     * Inativar controle
     */
    public function deactivate(Request $request, string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'motivo' => 'required|string|min:3|max:500'
        ], [
            'motivo.required' => 'Motivo é obrigatório para inativar controle',
            'motivo.min' => 'Motivo deve ter pelo menos 3 caracteres'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $controle = ControleClube::findOrFail($id);

            // Verificar permissão
            if (!$this->canEditControle($currentUser, $controle)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            if (!$controle->ativo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle já está inativo'
                ], 422);
            }

            $motivo = trim($request->motivo);
            $controle->inativar($motivo, $currentUser);

            // Criar notificação
            Notificacao::criarControleInativado($controle, $motivo);

            \Log::info('Controle inativado', [
                'controle_id' => $controle->id,
                'numero_proposta' => $controle->numero_proposta,
                'numero_uc' => $controle->numero_uc,
                'motivo' => $motivo,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Controle inativado com sucesso!',
                'data' => $this->transformControleForAPI($controle->load([
                    'usuario', 'proposta', 'unidadeConsumidora', 'usinaGeradora'
                ]), $currentUser)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Controle não encontrado'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erro ao inativar controle', [
                'controle_id' => $id,
                'motivo' => $request->motivo,
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
     * Reativar controle
     */
    public function reactivate(string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Apenas admins e consultores podem reativar
        if (!$currentUser->isAdmin() && !$currentUser->isConsultor()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores e consultores podem reativar controles'
            ], 403);
        }

        try {
            $controle = ControleClube::findOrFail($id);

            // Verificar permissão
            if (!$this->canEditControle($currentUser, $controle)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            if ($controle->ativo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle já está ativo'
                ], 422);
            }

            $controle->reativar($currentUser);

            \Log::info('Controle reativado', [
                'controle_id' => $controle->id,
                'numero_proposta' => $controle->numero_proposta,
                'numero_uc' => $controle->numero_uc,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Controle reativado com sucesso!',
                'data' => $this->transformControleForAPI($controle->load([
                    'usuario', 'proposta', 'unidadeConsumidora', 'usinaGeradora'
                ]), $currentUser)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Controle não encontrado'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erro ao reativar controle', [
                'controle_id' => $id,
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
     * Obter estatísticas dos controles
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

            $estatisticasGerais = ControleClube::getEstatisticas($filtros);
            $estatisticasPorMes = $this->getEstatisticasPorMes($currentUser, $request);

            return response()->json([
                'success' => true,
                'data' => [
                    'geral' => $estatisticasGerais,
                    'por_mes' => $estatisticasPorMes,
                    'calibragem_global' => [
                        'percentual_atual' => Configuracao::getCalibragemGlobal(),
                        'ultima_aplicacao' => $this->getUltimaCalibragemGlobal()
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao buscar estatísticas de controles', [
                'user_id' => $currentUser->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    // Métodos auxiliares para verificação de permissões
    private function canViewControle(Usuario $currentUser, ControleClube $controle): bool
    {
        return $currentUser->canAccessData(['consultor' => $controle->consultor]);
    }

    private function canEditControle(Usuario $currentUser, ControleClube $controle): bool
    {
        if ($currentUser->isAdmin()) return true;
        
        if ($currentUser->isConsultor()) {
            return $currentUser->canAccessData(['consultor' => $controle->consultor]);
        }
        
        return $controle->consultor === $currentUser->nome || $controle->usuario_id === $currentUser->id;
    }

    // Métodos auxiliares para transformação de dados
    private function transformControleForAPI(ControleClube $controle, Usuario $currentUser): array
    {
        return [
            'id' => $controle->id,
            'numero_proposta' => $controle->numero_proposta,
            'numero_uc' => $controle->numero_uc,
            'nome_cliente' => $controle->nome_cliente,
            'consultor' => $controle->consultor,
            'ativo' => $controle->ativo,
            'status_display' => $controle->status_display,
            'status_color' => $controle->status_color,
            
            // Dados operacionais
            'consumo_medio' => $controle->consumo_medio,
            'consumo_formatado' => $controle->consumo_formatado,
            'geracao_prevista' => $controle->geracao_prevista,
            'geracao_formatada' => $controle->geracao_formatada,
            'economia_percentual' => $controle->economia_percentual,
            'economia_formatada' => $controle->economia_formatada,
            'desconto_bandeira' => $controle->desconto_bandeira,
            'bandeira_formatada' => $controle->bandeira_formatada,
            'recorrencia' => $controle->recorrencia,
            
            // Economia calculada
            'economia_calculada' => $controle->economia_calculada,
            'desconto_bandeira_calculado' => $controle->desconto_bandeira_calculado,
            
            // Calibragem
            'calibragem_aplicada' => $controle->calibragem_aplicada,
            'calibragem_formatada' => $controle->calibragem_formatada,
            'data_ultima_calibragem' => $controle->data_ultima_calibragem ? 
                $controle->data_ultima_calibragem->format('d/m/Y H:i') : null,
            
            // Datas
            'data_inicio_clube' => $controle->data_inicio_clube ? 
                $controle->data_inicio_clube->format('d/m/Y') : null,
            'data_fim_clube' => $controle->data_fim_clube ? 
                $controle->data_fim_clube->format('d/m/Y') : null,
            'tempo_atividade' => $controle->tempo_atividade,
            'tempo_atividade_texto' => $controle->tempo_atividade_texto,
            
            // Status e observações
            'motivo_inativacao' => $controle->motivo_inativacao,
            'observacoes' => $controle->observacoes,
            
            // Timestamps
            'created_at' => $controle->created_at->format('d/m/Y H:i'),
            'updated_at' => $controle->updated_at->format('d/m/Y H:i'),
            
            // Relacionamentos
            'usuario' => $controle->usuario ? [
                'id' => $controle->usuario->id,
                'nome' => $controle->usuario->nome,
                'email' => $controle->usuario->email
            ] : null,
            
            'proposta' => $controle->proposta ? [
                'id' => $controle->proposta->id,
                'numero_proposta' => $controle->proposta->numero_proposta,
                'nome_cliente' => $controle->proposta->nome_cliente,
                'status' => $controle->proposta->status
            ] : null,
            
            'unidade_consumidora' => $controle->unidadeConsumidora ? [
                'id' => $controle->unidadeConsumidora->id,
                'numero_cliente' => $controle->unidadeConsumidora->numero_cliente,
                'numero_unidade' => $controle->unidadeConsumidora->numero_unidade,
                'apelido' => $controle->unidadeConsumidora->apelido
            ] : null,
            
            'usina_geradora' => $controle->usinaGeradora ? [
                'id' => $controle->usinaGeradora->id,
                'nome_usina' => $controle->usinaGeradora->nome_usina,
                'potencia_cc' => $controle->usinaGeradora->potencia_cc,
                'capacidade_calculada' => $controle->usinaGeradora->capacidade_calculada,
                'localizacao' => $controle->usinaGeradora->localizacao
            ] : null,
            
            // Permissões
            'permissions' => [
                'can_edit' => $this->canEditControle($currentUser, $controle),
                'can_apply_calibragem' => ($currentUser->isAdmin() || $currentUser->isConsultor()) && $controle->ativo,
                'can_link_ug' => ($currentUser->isAdmin() || $currentUser->isConsultor()) && $controle->ativo && !$controle->ug_id,
                'can_unlink_ug' => ($currentUser->isAdmin() || $currentUser->isConsultor()) && $controle->ativo && $controle->ug_id,
                'can_deactivate' => $this->canEditControle($currentUser, $controle) && $controle->ativo,
                'can_reactivate' => ($currentUser->isAdmin() || $currentUser->isConsultor()) && !$controle->ativo
            ]
        ];
    }

    private function transformControleDetailForAPI(ControleClube $controle, Usuario $currentUser): array
    {
        $data = $this->transformControleForAPI($controle, $currentUser);
        
        // Adicionar validações se necessário
        if ($controle->ativo) {
            $data['validation_errors'] = $controle->isValidForCalibragem();
        }
        
        return $data;
    }

    // Método auxiliar para estatísticas por mês
    private function getEstatisticasPorMes(Usuario $currentUser, Request $request): array
    {
        $mesesAtras = 6;
        $dataInicio = Carbon::now()->subMonths($mesesAtras)->startOfMonth();
        
        $query = ControleClube::query()
                             ->comFiltroHierarquico($currentUser)
                             ->where('created_at', '>=', $dataInicio);
        
        if ($request->filled('consultor')) {
            $query->where('consultor', $request->consultor);
        }
        
        $resultados = $query->selectRaw("
                DATE_TRUNC('month', created_at) as mes,
                COUNT(*) as total,
                COUNT(CASE WHEN ativo = true THEN 1 END) as ativos,
                COUNT(CASE WHEN ug_id IS NOT NULL THEN 1 END) as com_ug,
                AVG(consumo_medio) as consumo_medio_mes,
                SUM(geracao_prevista) as geracao_total_mes
            ")
            ->groupBy('mes')
            ->orderBy('mes')
            ->get();
        
        return $resultados->map(function ($item) {
            return [
                'mes' => Carbon::parse($item->mes)->format('m/Y'),
                'mes_nome' => Carbon::parse($item->mes)->locale('pt_BR')->format('M/Y'),
                'total' => (int) $item->total,
                'ativos' => (int) $item->ativos,
                'inativos' => (int) $item->total - (int) $item->ativos,
                'com_ug' => (int) $item->com_ug,
                'sem_ug' => (int) $item->total - (int) $item->com_ug,
                'consumo_medio_mes' => $item->consumo_medio_mes ? round((float) $item->consumo_medio_mes, 2) : 0,
                'geracao_total_mes' => $item->geracao_total_mes ? round((float) $item->geracao_total_mes, 2) : 0
            ];
        })->toArray();
    }

    // Método auxiliar para buscar última calibragem global
    private function getUltimaCalibragemGlobal(): ?string
    {
        $configuracao = Configuracao::where('chave', 'calibragem_global')->first();
        return $configuracao ? $configuracao->updated_at->format('d/m/Y H:i') : null;
    }
}