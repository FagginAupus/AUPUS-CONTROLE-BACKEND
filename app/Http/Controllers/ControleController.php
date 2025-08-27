<?php

namespace App\Http\Controllers;

use App\Models\ControleClube;
use App\Models\Proposta;
use App\Models\UnidadeConsumidora;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class ControleController extends Controller
{
    /**
     * ✅ LISTAR CONTROLES COM FILTROS E PAGINAÇÃO + DADOS EXPANDIDOS
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

            Log::info('Carregando controle clube', [
                'user_id' => $currentUser->id,
                'user_role' => $currentUser->role,
                'filters' => $request->all()
            ]);

            // Parâmetros de paginação
            $page = max(1, (int)$request->get('page', 1));
            $perPage = min(100, max(1, (int)$request->get('per_page', 50)));

            // ✅ QUERY COM NOVOS CAMPOS
            $query = "
                SELECT 
                    cc.id,
                    cc.proposta_id,
                    cc.uc_id,
                    cc.ug_id,
                    cc.calibragem,
                    cc.valor_calibrado,
                    cc.observacoes,
                    cc.status_troca,
                    cc.data_titularidade,
                    cc.data_entrada_controle,
                    cc.created_at,
                    cc.updated_at,
                    
                    -- Dados da proposta
                    p.numero_proposta,
                    p.nome_cliente,
                    p.consultor,
                    p.data_proposta,
                    p.usuario_id,
                    
                    -- Dados da UC
                    uc.numero_unidade,
                    uc.apelido,
                    uc.consumo_medio,
                    uc.ligacao,
                    
                    -- Dados da UG (se atribuída)
                    ug.nome_usina as ug_nome,
                    ug.potencia_cc as ug_potencia_cc,
                    ug.capacidade_calculada as ug_capacidade
                    
                FROM controle_clube cc
                LEFT JOIN propostas p ON cc.proposta_id = p.id
                LEFT JOIN unidades_consumidoras uc ON cc.uc_id = uc.id
                LEFT JOIN unidades_consumidoras ug ON cc.ug_id = ug.id AND ug.gerador = true
                WHERE cc.deleted_at IS NULL
            ";

            $params = [];

            // ✅ FILTROS HIERÁRQUICOS BASEADOS NO ROLE
            if ($currentUser->role === 'vendedor') {
                $query .= " AND p.usuario_id = ?";
                $params[] = $currentUser->id;
            } elseif ($currentUser->role === 'gerente') {
                // Implementar lógica de equipe se necessário
                $query .= " AND p.usuario_id = ?";
                $params[] = $currentUser->id;
            } elseif ($currentUser->role === 'consultor') {
                // Implementar lógica de subordinados se necessário
                $query .= " AND p.usuario_id = ?";
                $params[] = $currentUser->id;
            }

            // Filtros opcionais
            if ($request->filled('proposta_id')) {
                $query .= " AND cc.proposta_id = ?";
                $params[] = $request->proposta_id;
            }

            if ($request->filled('uc_id')) {
                $query .= " AND cc.uc_id = ?";
                $params[] = $request->uc_id;
            }

            if ($request->filled('ug_id')) {
                if ($request->ug_id === 'null' || $request->ug_id === 'sem-ug') {
                    $query .= " AND cc.ug_id IS NULL";
                } else {
                    $query .= " AND cc.ug_id = ?";
                    $params[] = $request->ug_id;
                }
            }

            if ($request->filled('consultor')) {
                $query .= " AND p.consultor ILIKE ?";
                $params[] = '%' . $request->consultor . '%';
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query .= " AND (p.nome_cliente ILIKE ? OR p.numero_proposta ILIKE ? OR uc.numero_unidade::text ILIKE ? OR uc.apelido ILIKE ?)";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }

            // Contar total
            $countQuery = str_replace(
                "SELECT cc.id, cc.proposta_id, cc.uc_id, cc.ug_id, cc.calibragem, cc.valor_calibrado, cc.observacoes, cc.status_troca, cc.data_titularidade, cc.data_entrada_controle, cc.created_at, cc.updated_at, p.numero_proposta, p.nome_cliente, p.consultor, p.data_proposta, p.usuario_id, uc.numero_unidade, uc.apelido, uc.consumo_medio, uc.ligacao, ug.nome_usina as ug_nome, ug.potencia_cc as ug_potencia_cc, ug.capacidade_calculada as ug_capacidade",
                "SELECT COUNT(*) as total",
                $query
            );

            $totalResult = DB::selectOne($countQuery, $params);
            $total = $totalResult->total ?? 0;

            // Ordenação e paginação
            $orderBy = $request->get('sort_by', 'created_at');
            $orderDirection = in_array($request->get('sort_direction', 'desc'), ['asc', 'desc']) 
                ? $request->get('sort_direction', 'desc') 
                : 'desc';

            $query .= " ORDER BY cc.{$orderBy} {$orderDirection}";
            $query .= " LIMIT ? OFFSET ?";
            $params[] = $perPage;
            $params[] = ($page - 1) * $perPage;

            $controles = DB::select($query, $params);

            // ✅ FORMATAR DADOS PARA O FRONTEND COM NOVOS CAMPOS
            $controlesFormatados = collect($controles)->map(function ($controle) {
                return [
                    // IDs
                    'id' => $controle->id,
                    'propostaId' => $controle->proposta_id,
                    'ucId' => $controle->uc_id,
                    'ugId' => $controle->ug_id,
                    
                    // Dados da proposta
                    'numeroProposta' => $controle->numero_proposta ?? 'N/A',
                    'nomeCliente' => $controle->nome_cliente ?? 'N/A',
                    'consultor' => $controle->consultor ?? 'N/A',
                    'dataProposta' => $controle->data_proposta,
                    
                    // Dados da UC
                    'numeroUC' => $controle->numero_unidade ?? 'N/A',
                    'apelido' => $controle->apelido ?? 'N/A',
                    'media' => floatval($controle->consumo_medio ?? 0),
                    'ligacao' => $controle->ligacao ?? 'N/A',
                    
                    // ✅ NOVOS CAMPOS: Status de troca
                    'statusTroca' => $controle->status_troca ?? 'Aguardando',
                    'dataTitularidade' => $controle->data_titularidade,
                    
                    // Dados da UG
                    'ug' => $controle->ug_nome,
                    'ugNome' => $controle->ug_nome,
                    'ugPotencia' => floatval($controle->ug_potencia_cc ?? 0),
                    'ugCapacidade' => floatval($controle->ug_capacidade ?? 0),
                    
                    // Calibragem
                    'calibragem' => floatval($controle->calibragem ?? 0),
                    'valorCalibrado' => $this->calcularValorCalibrado(
                        floatval($controle->consumo_medio ?? 0),
                        floatval($controle->calibragem ?? 0)
                    ),
                    
                    // Metadados
                    'observacoes' => $controle->observacoes,
                    'dataEntradaControle' => $controle->data_entrada_controle,
                    'createdAt' => $controle->created_at,
                    'updatedAt' => $controle->updated_at
                ];
            });

            Log::info('Controle clube carregado com sucesso', [
                'total' => $total,
                'returned' => $controlesFormatados->count(),
                'page' => $page,
                'per_page' => $perPage,
                'user_id' => $currentUser->id,
                'user_role' => $currentUser->role
            ]);

            return response()->json([
                'success' => true,
                'data' => $controlesFormatados,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                    'from' => ($page - 1) * $perPage + 1,
                    'to' => min($page * $perPage, $total),
                    'has_more_pages' => $page < ceil($total / $perPage)
                ],
                'filters' => [
                    'proposta_id' => $request->proposta_id,
                    'uc_id' => $request->uc_id,
                    'ug_id' => $request->ug_id,
                    'consultor' => $request->consultor,
                    'search' => $request->search
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao carregar controle clube', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'user_id' => $currentUser->id ?? 'desconhecido',
                'request_data' => $request->all()
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
     * ✅ ATUALIZAR STATUS DE TROCA DE TITULARIDADE
     */
    public function updateStatusTroca(Request $request, string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();
        
        if (!$currentUser) {
            return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
        }

        $validator = Validator::make($request->all(), [
            'status_troca' => 'required|in:Aguardando,Em andamento,Finalizado',
            'data_titularidade' => 'required|date|before_or_equal:today'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Buscar controle
            $controle = DB::selectOne(
                "SELECT cc.*, p.nome_cliente, uc.apelido, uc.numero_unidade,
                        ug.nome_usina as ug_nome
                 FROM controle_clube cc
                 LEFT JOIN propostas p ON cc.proposta_id = p.id
                 LEFT JOIN unidades_consumidoras uc ON cc.uc_id = uc.id  
                 LEFT JOIN unidades_consumidoras ug ON cc.ug_id = ug.id
                 WHERE cc.id = ? AND cc.deleted_at IS NULL",
                [$id]
            );

            if (!$controle) {
                return response()->json(['success' => false, 'message' => 'Controle não encontrado'], 404);
            }

            // Verificar se está tentando SAIR de "Finalizado" com UG atribuída
            $statusAnterior = $controle->status_troca;
            $novoStatus = $request->status_troca;
            $ugAtual = $controle->ug_id;

            if ($statusAnterior === 'Finalizado' && $novoStatus !== 'Finalizado' && $ugAtual) {
                // Desatribuir UG automaticamente
                Log::info('Desatribuindo UG por mudança de status', [
                    'controle_id' => $id,
                    'ug_id' => $ugAtual,
                    'ug_nome' => $controle->ug_nome,
                    'status_anterior' => $statusAnterior,
                    'status_novo' => $novoStatus
                ]);

                // Remover UC dos detalhes da UG
                $this->removerUcDaUg($ugAtual, $controle->uc_id);
                
                // Limpar UG do controle
                DB::update(
                    "UPDATE controle_clube SET ug_id = NULL WHERE id = ?",
                    [$id]
                );
            }

            // Atualizar status e data
            DB::update(
                "UPDATE controle_clube 
                 SET status_troca = ?, data_titularidade = ?, updated_at = NOW()
                 WHERE id = ?",
                [$novoStatus, $request->data_titularidade, $id]
            );

            DB::commit();

            $mensagem = $statusAnterior === 'Finalizado' && $novoStatus !== 'Finalizado' && $ugAtual
                ? "Status atualizado e UG '{$controle->ug_nome}' foi desatribuída"
                : 'Status de troca atualizado com sucesso';

            return response()->json([
                'success' => true,
                'message' => $mensagem,
                'data' => [
                    'status_troca' => $novoStatus,
                    'data_titularidade' => $request->data_titularidade,
                    'ug_desatribuida' => $statusAnterior === 'Finalizado' && $novoStatus !== 'Finalizado' && $ugAtual
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao atualizar status de troca', [
                'controle_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    public function ugsDisponiveis(Request $request): JsonResponse
    {
        $currentUser = JWTAuth::user();
        
        if (!$currentUser) {
            return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
        }

        // Para calibragem
        $ucId = $request->query('uc_id');
        $consumoUc = 0;
        $calibragem = 0;

        if ($ucId) {
            $controle = DB::selectOne("
                SELECT cc.calibragem, uc.consumo_medio
                FROM controle_clube cc
                LEFT JOIN unidades_consumidoras uc ON cc.uc_id = uc.id
                WHERE cc.uc_id = ? AND cc.deleted_at IS NULL
            ", [$ucId]);
            
            if ($controle) {
                $consumoUc = floatval($controle->consumo_medio ?? 0);
                $calibragem = floatval($controle->calibragem ?? 0);
            }
        }

        try {
            // Buscar calibragem global
            $calibragemGlobal = DB::selectOne(
                "SELECT valor FROM configuracoes WHERE chave = 'calibragem_global'"
            )->valor ?? 0;

            // ✅ QUERY SIMPLIFICADA - SEM colunas redundantes
            $ugs = DB::select("
                SELECT id, nome_usina, potencia_cc, fator_capacidade, capacidade_calculada
                FROM unidades_consumidoras 
                WHERE gerador = true 
                AND nexus_clube = true 
                AND deleted_at IS NULL
                ORDER BY nome_usina
            ");

            $ugsAnalise = [];
            
            foreach ($ugs as $ug) {
                $capacidadeTotal = floatval($ug->capacidade_calculada ?? 0);
                
                // ✅ CALCULAR DINAMICAMENTE
                $ucsAtribuidas = $this->obterUcsAtribuidas($ug->id);
                $consumoAtribuido = $this->obterMediaConsumoAtribuido($ug->id);
                
                // Calcular consumo disponível
                $consumoDisponivel = max(0, $capacidadeTotal - $consumoAtribuido);
                
                // Calcular se pode receber a UC específica
                $consumoUcCalibrado = $this->calcularValorCalibrado($consumoUc, $calibragem);
                $podeReceberUc = $consumoDisponivel >= $consumoUcCalibrado;
                
                // Status da UG
                $percentualUso = $capacidadeTotal > 0 ? ($consumoAtribuido / $capacidadeTotal) * 100 : 0;
                
                if ($percentualUso >= 95) {
                    $status = 'Cheia';
                    $statusColor = 'danger';
                } elseif ($percentualUso >= 80) {
                    $status = 'Quase Cheia';
                    $statusColor = 'warning';
                } else {
                    $status = 'Disponível';
                    $statusColor = 'success';
                }

                $ugsAnalise[] = [
                    'id' => $ug->id,
                    'nome_usina' => $ug->nome_usina,
                    'potencia_cc' => floatval($ug->potencia_cc ?? 0),
                    'capacidade_total' => $capacidadeTotal,
                    'consumo_atribuido' => $consumoAtribuido,
                    'consumo_disponivel' => $consumoDisponivel,
                    'ucs_atribuidas' => $ucsAtribuidas,
                    'percentual_uso' => round($percentualUso, 1),
                    'status' => $status,
                    'status_color' => $statusColor,
                    'pode_receber_uc' => $podeReceberUc,
                    'consumo_uc_calibrado' => $consumoUcCalibrado,
                    'calibragem_global' => floatval($calibragemGlobal)
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $ugsAnalise
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar UGs disponíveis', [
                'error' => $e->getMessage(),
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    public function atribuirUg(Request $request, string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();
        
        if (!$currentUser) {
            return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
        }

        $validator = Validator::make($request->all(), [
            'ug_id' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'UG inválida',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validação customizada para UG
        $ugExiste = DB::selectOne("
            SELECT id, nome_usina FROM unidades_consumidoras 
            WHERE id = ? AND gerador = true
        ", [$request->ug_id]);

        if (!$ugExiste) {
            return response()->json([
                'success' => false,
                'message' => 'UG não encontrada ou não é uma unidade geradora'
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Buscar controle
            $controle = DB::selectOne("
                SELECT cc.*, uc.consumo_medio, uc.apelido, uc.numero_unidade,
                    p.nome_cliente, p.numero_proposta
                FROM controle_clube cc
                LEFT JOIN unidades_consumidoras uc ON cc.uc_id = uc.id
                LEFT JOIN propostas p ON cc.proposta_id = p.id
                WHERE cc.id = ? AND cc.deleted_at IS NULL
            ", [$id]);

            if (!$controle) {
                return response()->json(['success' => false, 'message' => 'Controle não encontrado'], 404);
            }

            // Verificar se status permite atribuição
            if ($controle->status_troca !== 'Finalizado') {
                return response()->json([
                    'success' => false,
                    'message' => 'Status deve ser "Finalizado" para atribuir UG'
                ], 400);
            }

            // Buscar UG - SEM as colunas redundantes
            $ug = DB::selectOne("
                SELECT id, nome_usina, capacidade_calculada
                FROM unidades_consumidoras 
                WHERE id = ? AND gerador = true AND deleted_at IS NULL
            ", [$request->ug_id]);

            if (!$ug) {
                return response()->json(['success' => false, 'message' => 'UG não encontrada'], 404);
            }

            // Calcular consumo calibrado
            $calibragem = floatval($controle->calibragem ?? 0);
            $consumoMedio = floatval($controle->consumo_medio ?? 0);
            $consumoCalibrado = $this->calcularValorCalibrado($consumoMedio, $calibragem);

            // Verificar capacidade da UG - CALCULADO DINAMICAMENTE
            $capacidadeTotal = floatval($ug->capacidade_calculada ?? 0);
            $consumoAtualAtribuido = $this->obterMediaConsumoAtribuido($request->ug_id);
            
            if (($consumoAtualAtribuido + $consumoCalibrado) > $capacidadeTotal) {
                return response()->json([
                    'success' => false,
                    'message' => 'UG não tem capacidade suficiente para esta UC'
                ], 400);
            }

            // Atualizar controle - APENAS ISSO É NECESSÁRIO
            DB::update("
                UPDATE controle_clube 
                SET ug_id = ?, valor_calibrado = ?, updated_at = NOW()
                WHERE id = ?
            ", [$request->ug_id, $consumoCalibrado, $id]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "UG '{$ug->nome_usina}' atribuída com sucesso",
                'data' => [
                    'ug_id' => $request->ug_id,
                    'ug_nome' => $ug->nome_usina,
                    'consumo_calibrado' => $consumoCalibrado
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao atribuir UG', [
                'controle_id' => $id,
                'ug_id' => $request->ug_id,
                'error' => $e->getMessage(),
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    public function removerUg(string $id): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();

            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Verificar se é admin
            if ($currentUser->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Apenas administradores podem gerenciar UGs'
                ], 403);
            }

            DB::beginTransaction();

            // Buscar controle
            $controle = DB::selectOne("
                SELECT cc.*, ug.nome_usina as ug_nome
                FROM controle_clube cc
                LEFT JOIN unidades_consumidoras ug ON cc.ug_id = ug.id
                WHERE cc.id = ? AND cc.deleted_at IS NULL
            ", [$id]);

            if (!$controle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle não encontrado'
                ], 404);
            }

            if (!$controle->ug_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este controle não possui UG atribuída'
                ], 400);
            }

            // Remover UG do controle - SÓ ISSO É NECESSÁRIO
            DB::update("
                UPDATE controle_clube 
                SET ug_id = NULL, valor_calibrado = NULL, updated_at = NOW()
                WHERE id = ?
            ", [$id]);

            DB::commit();

            Log::info('UG removida com sucesso', [
                'controle_id' => $id,
                'ug_id' => $controle->ug_id,
                'ug_nome' => $controle->ug_nome,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => "UG '{$controle->ug_nome}' removida com sucesso",
                'data' => [
                    'ug_id' => null,
                    'ug_nome' => null
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao remover UG', [
                'controle_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $currentUser->id ?? 'desconhecido'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * ✅ CALCULAR VALOR CALIBRADO
     */
    private function calcularValorCalibrado($media, $calibragem)
    {
        $mediaNum = floatval($media);
        
        if (!$mediaNum) {
            return 0; // Se não tem média, retorna 0
        }
        
        $calibragemNum = floatval($calibragem);
        
        // ✅ CORREÇÃO: Se calibragem for 0, retorna apenas a média
        if ($calibragemNum == 0) {
            return $mediaNum;
        }
        
        return $mediaNum * (1 + ($calibragemNum / 100));
    }

    private function obterUcsAtribuidas(string $ugId): int
    {
        $result = DB::selectOne("
            SELECT COUNT(*) as total 
            FROM controle_clube 
            WHERE ug_id = ? AND deleted_at IS NULL
        ", [$ugId]);
        
        return intval($result->total ?? 0);
    }

    /**
     * ✅ OBTER MÉDIA DE CONSUMO ATRIBUÍDO A UMA UG
     */
    private function obterMediaConsumoAtribuido(string $ugId): float
    {
        $result = DB::selectOne("
            SELECT COALESCE(SUM(cc.valor_calibrado), 0) as total
            FROM controle_clube cc
            WHERE cc.ug_id = ? AND cc.deleted_at IS NULL
        ", [$ugId]);
        
        return floatval($result->total ?? 0);
    }

    /**
     * ✅ CRIAR NOVO CONTROLE
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

        // Validação
        $validator = Validator::make($request->all(), [
            'proposta_id' => 'required|string|exists:propostas,id',
            'uc_id' => 'required|string|exists:unidades_consumidoras,id',
            'ug_id' => 'nullable|string|exists:unidades_consumidoras,id',
            'calibragem' => 'nullable|numeric|min:0|max:100',
            'observacoes' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados de entrada inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Verificar se já existe controle para esta UC/Proposta
            $existeControle = ControleClube::where('proposta_id', $request->proposta_id)
                                         ->where('uc_id', $request->uc_id)
                                         ->first();

            if ($existeControle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Já existe controle para esta UC/Proposta'
                ], 409);
            }

            // Calcular valor calibrado
            $uc = UnidadeConsumidora::find($request->uc_id);
            $valorCalibrado = null;
            if ($request->calibragem && $uc && $uc->consumo_medio) {
                $valorCalibrado = $this->calcularValorCalibrado($uc->consumo_medio, $request->calibragem);
            }

            // Criar controle
            $controle = ControleClube::create([
                'id' => Str::ulid(),
                'proposta_id' => $request->proposta_id,
                'uc_id' => $request->uc_id,
                'ug_id' => $request->ug_id,
                'calibragem' => $request->calibragem,
                'valor_calibrado' => $valorCalibrado,
                'observacoes' => $request->observacoes,
                'data_entrada_controle' => now()
            ]);

            DB::commit();

            // Carregar com relacionamentos para resposta
            $controle->load(['proposta', 'unidadeConsumidora', 'unidadeGeradora']);

            $controleFormatado = [
                'id' => $controle->id,
                'proposta_id' => $controle->proposta_id,
                'uc_id' => $controle->uc_id,
                'ug_id' => $controle->ug_id,
                'calibragem' => $controle->calibragem,
                'valor_calibrado' => $controle->valor_calibrado,
                'observacoes' => $controle->observacoes,
                'data_entrada_controle' => $controle->data_entrada_controle,
                
                'proposta' => $controle->proposta ? [
                    'id' => $controle->proposta->id,
                    'numero_proposta' => $controle->proposta->numero_proposta,
                    'nome_cliente' => $controle->proposta->nome_cliente,
                    'consultor' => $controle->proposta->consultor
                ] : null,
                
                'unidade_consumidora' => $controle->unidadeConsumidora ? [
                    'id' => $controle->unidadeConsumidora->id,
                    'numero_unidade' => $controle->unidadeConsumidora->numero_unidade,
                    'apelido' => $controle->unidadeConsumidora->apelido,
                    'consumo_medio' => $controle->unidadeConsumidora->consumo_medio
                ] : null,
                
                'unidade_geradora' => $controle->unidadeGeradora ? [
                    'id' => $controle->unidadeGeradora->id,
                    'nome_usina' => $controle->unidadeGeradora->nome_usina,
                    'potencia_cc' => $controle->unidadeGeradora->potencia_cc
                ] : null
            ];

            Log::info('Controle criado com sucesso', [
                'controle_id' => $controle->id,
                'proposta_id' => $controle->proposta_id,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Controle criado com sucesso',
                'data' => $controleFormatado
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao criar controle', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'request_data' => $request->all(),
                'user_id' => $currentUser->id
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
     * ✅ ATUALIZAR CONTROLE (principalmente para UG e calibragem)
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

        // Validação
        $validator = Validator::make($request->all(), [
            'ug_id' => 'nullable|string|exists:unidades_consumidoras,id',
            'calibragem' => 'nullable|numeric|min:0|max:100',
            'observacoes' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados de entrada inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $controle = ControleClube::with(['proposta', 'unidadeConsumidora'])
                                   ->find($id);

            if (!$controle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle não encontrado'
                ], 404);
            }

            // Verificar permissões
            if ($currentUser->role === 'vendedor' && 
                $controle->proposta && 
                $controle->proposta->usuario_id !== $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sem permissão para editar este controle'
                ], 403);
            }

            // Atualizar campos
            if ($request->has('ug_id')) {
                $controle->ug_id = $request->ug_id;
            }

            if ($request->has('calibragem')) {
                $controle->calibragem = $request->calibragem;
                
                // Recalcular valor calibrado
                if ($controle->unidadeConsumidora && $controle->unidadeConsumidora->consumo_medio) {
                    $controle->valor_calibrado = $this->calcularValorCalibrado(
                        $controle->unidadeConsumidora->consumo_medio,
                        $request->calibragem
                    );
                }
            }

            if ($request->has('observacoes')) {
                $controle->observacoes = $request->observacoes;
            }

            $controle->save();

            DB::commit();

            Log::info('Controle atualizado com sucesso', [
                'controle_id' => $controle->id,
                'changes' => $controle->getChanges(),
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Controle atualizado com sucesso',
                'data' => [
                    'id' => $controle->id,
                    'ug_id' => $controle->ug_id,
                    'calibragem' => $controle->calibragem,
                    'valor_calibrado' => $controle->valor_calibrado,
                    'observacoes' => $controle->observacoes,
                    'updated_at' => $controle->updated_at
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao atualizar controle', [
                'controle_id' => $id,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'user_id' => $currentUser->id
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
     * ✅ EXIBIR UM CONTROLE ESPECÍFICO
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

            $controle = ControleClube::with(['proposta', 'unidadeConsumidora', 'unidadeGeradora'])
                                   ->find($id);

            if (!$controle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle não encontrado'
                ], 404);
            }

            // Verificar permissão
            if ($currentUser->role === 'vendedor' && 
                $controle->proposta && 
                $controle->proposta->usuario_id !== $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sem permissão para ver este controle'
                ], 403);
            }

            $controleFormatado = [
                'id' => $controle->id,
                'proposta_id' => $controle->proposta_id,
                'uc_id' => $controle->uc_id,
                'ug_id' => $controle->ug_id,
                'calibragem' => $controle->calibragem,
                'valor_calibrado' => $controle->valor_calibrado,
                'observacoes' => $controle->observacoes,
                'status_troca' => $controle->status_troca,
                'data_titularidade' => $controle->data_titularidade,
                'data_entrada_controle' => $controle->data_entrada_controle,
                'created_at' => $controle->created_at,
                'updated_at' => $controle->updated_at,
                
                'proposta' => $controle->proposta ? [
                    'id' => $controle->proposta->id,
                    'numero_proposta' => $controle->proposta->numero_proposta,
                    'nome_cliente' => $controle->proposta->nome_cliente,
                    'consultor' => $controle->proposta->consultor
                ] : null,
                                
                'unidade_consumidora' => $controle->unidadeConsumidora ? [
                    'id' => $controle->unidadeConsumidora->id,
                    'numero_unidade' => $controle->unidadeConsumidora->numero_unidade,
                    'apelido' => $controle->unidadeConsumidora->apelido,
                    'consumo_medio' => $controle->unidadeConsumidora->consumo_medio
                ] : null,
                
                'unidade_geradora' => $controle->unidadeGeradora ? [
                    'id' => $controle->unidadeGeradora->id,
                    'nome_usina' => $controle->unidadeGeradora->nome_usina,
                    'potencia_cc' => $controle->unidadeGeradora->potencia_cc
                ] : null
            ];

            return response()->json([
                'success' => true,
                'data' => $controleFormatado
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao carregar controle', [
                'controle_id' => $id,
                'error' => $e->getMessage(),
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
     * ✅ REMOVER CONTROLE
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

            $controle = ControleClube::with('proposta')->find($id);

            if (!$controle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle não encontrado'
                ], 404);
            }

            // Verificar permissões (apenas admin pode deletar)
            if ($currentUser->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Apenas administradores podem excluir controles'
                ], 403);
            }

            $controle->delete();

            Log::info('Controle excluído com sucesso', [
                'controle_id' => $id,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Controle excluído com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao excluir controle', [
                'controle_id' => $id,
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