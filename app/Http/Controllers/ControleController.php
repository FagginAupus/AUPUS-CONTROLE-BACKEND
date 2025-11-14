<?php

namespace App\Http\Controllers;

use App\Models\ControleClube;
use App\Models\Proposta;
use App\Models\UnidadeConsumidora;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Services\AuditoriaService;

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
            $perPage = $request->get('per_page') === 'all' 
                ? PHP_INT_MAX 
                : min(1000, max(1, (int)$request->get('per_page', 50)));

            // ✅ QUERY COM NOVOS CAMPOS + DATAS DE STATUS
            $query = "SELECT
                cc.id,
                cc.proposta_id,
                cc.uc_id,
                cc.ug_id,
                cc.calibragem_individual,
                cc.observacoes,
                cc.whatsapp,
                cc.email,
                cc.status_troca,
                cc.observacao_status,
                cc.data_titularidade,
                cc.data_entrada_controle,
                cc.data_em_andamento,
                cc.data_assinatura,
                cc.data_alocacao_ug,
                cc.created_at,
                cc.updated_at,
                p.numero_proposta,
                p.nome_cliente,
                COALESCE(u_consultor.nome, 'Sem consultor') as consultor_nome,
                p.data_proposta,
                p.usuario_id,
                uc.numero_unidade,
                uc.apelido,
                uc.consumo_medio,
                uc.ligacao,
                ug.nome_usina as ug_nome,
                ug.potencia_cc as ug_potencia_cc,
                ug.capacidade_calculada as ug_capacidade,
                -- ✅ CORREÇÃO: Adicionar descontos do controle e da proposta
                cc.desconto_tarifa,
                cc.desconto_bandeira,
                p.desconto_tarifa as proposta_desconto_tarifa,
                p.desconto_bandeira as proposta_desconto_bandeira,
                -- ✅ NOVO: Extrair CPF/CNPJ do JSON documentacao (preferir cpf, senão cnpj)
                COALESCE(
                    NULLIF(p.documentacao->uc.numero_unidade::text->>'cpf', 'null'),
                    NULLIF(p.documentacao->uc.numero_unidade::text->>'cnpj', 'null'),
                    ''
                ) as cpf_cnpj
            FROM controle_clube cc
            LEFT JOIN propostas p ON cc.proposta_id = p.id
            LEFT JOIN usuarios u_consultor ON p.consultor_id = u_consultor.id
            LEFT JOIN unidades_consumidoras uc ON cc.uc_id = uc.id
            LEFT JOIN unidades_consumidoras ug ON cc.ug_id = ug.id
            WHERE cc.deleted_at IS NULL";

            $params = [];

            // ✅ FILTROS HIERÁRQUICOS BASEADOS NO ROLE
            if ($currentUser->role === 'vendedor') {
                $query .= " AND p.usuario_id = ?";
                $params[] = $currentUser->id;
            } elseif ($currentUser->role === 'gerente') {
                $subordinados = $currentUser->getAllSubordinates();
                $subordinadosIds = array_column($subordinados, 'id');
                $usuariosPermitidos = array_merge([$currentUser->id], $subordinadosIds);

                if (!empty($usuariosPermitidos)) {
                    $placeholders = str_repeat('?,', count($usuariosPermitidos) - 1) . '?';
                    $query .= " AND p.usuario_id IN ({$placeholders})";
                    $params = array_merge($params, $usuariosPermitidos);
                } else {
                    $query .= " AND p.usuario_id = ?";
                    $params[] = $currentUser->id;
                }
            } elseif ($currentUser->role === 'consultor') {
                $subordinados = $currentUser->getAllSubordinates();
                $subordinadosIds = array_column($subordinados, 'id');

                if (!empty($subordinadosIds)) {
                    $placeholders = str_repeat('?,', count($subordinadosIds) - 1) . '?';
                    $query .= " AND (p.usuario_id = ? OR p.consultor_id = ? OR p.usuario_id IN ({$placeholders}))";
                    $params[] = $currentUser->id;
                    $params[] = $currentUser->id;
                    $params = array_merge($params, $subordinadosIds);
                } else {
                    $query .= " AND (p.usuario_id = ? OR p.consultor_id = ?)";
                    $params[] = $currentUser->id;
                    $params[] = $currentUser->id;
                }
            }
            // ADMIN e ANALISTA veem todos os dados (sem filtro adicional)

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
                $query .= " AND p.consultor_id = ?";
                $params[] = $request->consultor;
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
                "SELECT cc.id, cc.proposta_id, cc.uc_id, cc.ug_id, cc.calibragem_individual, cc.valor_calibrado, cc.observacoes, cc.status_troca, cc.data_titularidade, cc.data_entrada_controle, cc.created_at, cc.updated_at, p.numero_proposta, p.nome_cliente, u_consultor.nome as consultor, p.data_proposta, p.usuario_id, uc.numero_unidade, uc.apelido, uc.consumo_medio, uc.ligacao, ug.nome_usina as ug_nome, ug.potencia_cc as ug_potencia_cc, ug.capacidade_calculada as ug_capacidade",
                "SELECT COUNT(*) as total",
                $query
            );

            $totalResult = DB::selectOne($countQuery, $params);
            $total = $totalResult->total ?? 0;

            // Ordenação e paginação
            $orderBy = $request->get('sort_by', 'urgencia');
            $orderDirection = in_array($request->get('sort_direction', 'desc'), ['asc', 'desc'])
                ? $request->get('sort_direction', 'desc')
                : 'desc';

            // Ordenação por urgência (mais dias = mais urgente)
            if ($orderBy === 'urgencia') {
                $query .= " ORDER BY
                    CASE
                        -- Associados sem UG: dias desde data_titularidade
                        WHEN cc.status_troca = 'Associado' AND cc.ug_id IS NULL AND cc.data_titularidade IS NOT NULL
                            THEN EXTRACT(DAY FROM (NOW() - cc.data_titularidade))
                        -- Em andamento: dias desde data_em_andamento
                        WHEN cc.status_troca = 'Em andamento' AND cc.data_em_andamento IS NOT NULL
                            THEN EXTRACT(DAY FROM (NOW() - cc.data_em_andamento))
                        -- Esteira: dias desde data_assinatura ou data_entrada_controle
                        WHEN cc.status_troca = 'Esteira' AND cc.data_assinatura IS NOT NULL
                            THEN EXTRACT(DAY FROM (NOW() - cc.data_assinatura))
                        WHEN cc.status_troca = 'Esteira' AND cc.data_entrada_controle IS NOT NULL
                            THEN EXTRACT(DAY FROM (NOW() - cc.data_entrada_controle))
                        -- Outros casos: 0 dias (menor prioridade)
                        ELSE 0
                    END DESC NULLS LAST";
            } else {
                $query .= " ORDER BY cc.{$orderBy} {$orderDirection}";
            }
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
                    'consultor' => $controle->consultor_nome ?? $controle->consultor ?? 'Sem consultor',
                    'consultor_nome' => $controle->consultor_nome ?? $controle->consultor ?? 'Sem consultor',
                    'dataProposta' => $controle->data_proposta,

                    // Dados da UC
                    'numeroUC' => $controle->numero_unidade ?? 'N/A',
                    'numero_unidade' => $controle->numero_unidade ?? 'N/A',
                    'apelido' => $controle->apelido ?? 'N/A',
                    'media' => floatval($controle->consumo_medio ?? 0),
                    'consumo_medio' => floatval($controle->consumo_medio ?? 0),
                    'ligacao' => $controle->ligacao ?? 'N/A',
                    'cpf_cnpj' => $this->formatarCpfCnpj($controle->cpf_cnpj ?? ''),

                    // ✅ NOVOS CAMPOS: Status de troca
                    'statusTroca' => $controle->status_troca ?? 'Aguardando',
                    'status_troca' => $controle->status_troca ?? 'Aguardando',
                    'dataTitularidade' => $controle->data_titularidade,
                    'data_titularidade' => $controle->data_titularidade,
                    'dataEmAndamento' => $controle->data_em_andamento,
                    'data_em_andamento' => $controle->data_em_andamento,
                    'dataAssinatura' => $controle->data_assinatura,
                    'data_assinatura' => $controle->data_assinatura,

                    // ✅ NOVO: Indicadores de tempo em cada status
                    'diasNoStatus' => $this->calcularDiasNoStatus($controle),
                    'alertaStatus' => $this->verificarAlertaStatus($controle),

                    // Dados da UG
                    'ug' => $controle->ug_nome,
                    'ugNome' => $controle->ug_nome,
                    'ug_nome' => $controle->ug_nome,
                    'ugPotencia' => floatval($controle->ug_potencia_cc ?? 0),
                    'ugCapacidade' => floatval($controle->ug_capacidade ?? 0),

                    // ✅ DESCONTOS
                    'desconto_tarifa' => $controle->desconto_tarifa ?? $controle->proposta_desconto_tarifa ?? '20%',
                    'desconto_bandeira' => $controle->desconto_bandeira ?? $controle->proposta_desconto_bandeira ?? '20%',
                    'proposta_desconto_tarifa' => $controle->proposta_desconto_tarifa ?? '20%',
                    'proposta_desconto_bandeira' => $controle->proposta_desconto_bandeira ?? '20%',

                    // Calibragem

                    'calibragemIndividual' => $controle->calibragem_individual ? floatval($controle->calibragem_individual) : null,
                    'calibragem_efetiva' => $controle->calibragem_individual !== null
                        ? (float) $controle->calibragem_individual
                        : \App\Models\Configuracao::getCalibragemGlobal(),
                    'usa_calibragem_global' => $controle->calibragem_individual === null,
                    'valorCalibrado' => $this->calcularValorCalibrado(
                        floatval($controle->consumo_medio ?? 0),
                        $controle->calibragem_individual !== null
                            ? (float) $controle->calibragem_individual
                            : \App\Models\Configuracao::getCalibragemGlobal()
                    ),

                    // Metadados
                    'observacoes' => $controle->observacoes,
                    'whatsapp' => $controle->whatsapp,
                    'email' => $controle->email,
                    'dataEntradaControle' => $controle->data_entrada_controle,
                    'data_entrada_controle' => $controle->data_entrada_controle,
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
            'status_troca' => 'required|in:Esteira,Em andamento,Associado',
            'data_titularidade' => 'required|date|before_or_equal:today',
            'observacao_status' => 'nullable|string|max:100',
            'limpar_observacao' => 'nullable|boolean'
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

            // Verificar se está tentando SAIR de "Associado" com UG atribuída
            $statusAnterior = $controle->status_troca;
            $novoStatus = $request->status_troca;
            $ugAtual = $controle->ug_id;

            if ($statusAnterior === 'Associado' && $novoStatus !== 'Associado' && $ugAtual) {
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

                // Limpar UG do controle e zerar data de alocação (para reiniciar contagem)
                DB::update(
                    "UPDATE controle_clube SET ug_id = NULL, data_alocacao_ug = NULL WHERE id = ?",
                    [$id]
                );
            }

            // ✅ ATUALIZAR STATUS E DATA + REGISTRAR DATA_EM_ANDAMENTO
            $updateQuery = "UPDATE controle_clube SET status_troca = ?, data_titularidade = ?, updated_at = NOW()";
            $updateParams = [$novoStatus, $request->data_titularidade];

            // ✅ Se mudou para "Em andamento" pela primeira vez, registrar data
            if ($novoStatus === 'Em andamento' && $statusAnterior !== 'Em andamento') {
                $updateQuery .= ", data_em_andamento = NOW()";
                Log::info('Registrando data_em_andamento', [
                    'controle_id' => $id,
                    'status_anterior' => $statusAnterior,
                    'status_novo' => $novoStatus
                ]);
            }

            // ✅ Atualizar observação do status
            if ($request->has('limpar_observacao') && $request->limpar_observacao) {
                $updateQuery .= ", observacao_status = NULL";
            } elseif ($request->has('observacao_status')) {
                $updateQuery .= ", observacao_status = ?";
                $updateParams[] = $request->observacao_status;
            }

            $updateQuery .= " WHERE id = ?";
            $updateParams[] = $id;

            DB::update($updateQuery, $updateParams);

            // ✅ REGISTRAR EVENTO DE AUDITORIA - MUDANÇA DE STATUS
            $eventoData = [
                'evento_tipo' => 'STATUS_ALTERADO',
                'descricao_evento' => "Status alterado de '{$statusAnterior}' para '{$novoStatus}'",
                'modulo' => 'controle',
                'sub_acao' => 'MUDANCA_STATUS_TROCA',
                'dados_anteriores' => [
                    'status_troca' => $statusAnterior,
                    'ug_id' => $ugAtual
                ],
                'dados_novos' => [
                    'status_troca' => $novoStatus,
                    'data_titularidade' => $request->data_titularidade,
                    'ug_desatribuida' => $statusAnterior === 'Associado' && $novoStatus !== 'Associado' && $ugAtual
                ],
                'dados_contexto' => [
                    'nome_cliente' => $controle->nome_cliente ?? null,
                    'numero_uc' => $controle->numero_unidade ?? null,
                    'ug_nome_anterior' => $controle->ug_nome ?? null,
                    'timestamp' => now()->toISOString()
                ]
            ];

            // Crítico apenas quando UC sai do controle (desatribuição de UG por mudança de status)
            if ($statusAnterior === 'Associado' && $novoStatus !== 'Associado' && $ugAtual) {
                $eventoData['evento_critico'] = true;
            }

            AuditoriaService::registrar('controle_clube', $id, 'ALTERADO', $eventoData);

            // ✅ CRIAR NOTIFICAÇÃO DE TROCA DE TITULARIDADE
            try {
                \App\Models\Notificacao::criarTrocaTitularidade(
                    $controle->nome_cliente ?? 'Cliente',
                    $controle->numero_unidade ?? 'UC',
                    $statusAnterior,
                    $novoStatus,
                    $currentUser->nome
                );
                Log::info('Notificação de troca de titularidade criada', [
                    'controle_id' => $id,
                    'status_anterior' => $statusAnterior,
                    'status_novo' => $novoStatus
                ]);
            } catch (\Exception $e) {
                Log::warning('Erro ao criar notificação de troca de titularidade', [
                    'controle_id' => $id,
                    'error' => $e->getMessage()
                ]);
                // Não falhar a operação por causa da notificação
            }

            DB::commit();

            $mensagem = $statusAnterior === 'Associado' && $novoStatus !== 'Associado' && $ugAtual
                ? "Status atualizado e UG '{$controle->ug_nome}' foi desatribuída"
                : 'Status de troca atualizado com sucesso';

            return response()->json([
                'success' => true,
                'message' => $mensagem,
                'data' => [
                    'status_troca' => $novoStatus,
                    'data_titularidade' => $request->data_titularidade,
                    'ug_desatribuida' => $statusAnterior === 'Associado' && $novoStatus !== 'Associado' && $ugAtual
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

        // Para calibragem da UC específica (se fornecida)
        $ucId = $request->query('uc_id');
        $consumoUc = 0;

        if ($ucId) {
            $uc = DB::selectOne("
                SELECT uc.consumo_medio
                FROM unidades_consumidoras uc
                WHERE uc.id = ? AND uc.deleted_at IS NULL
            ", [$ucId]);
            
            if ($uc) {
                $consumoUc = floatval($uc->consumo_medio ?? 0);
            }
        }

        try {
            // Buscar calibragem global
            $calibragemGlobal = DB::selectOne(
                "SELECT valor FROM configuracoes WHERE chave = 'calibragem_global'"
            )->valor ?? 0;

            // Query simplificada - SEM colunas redundantes
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
                
                // Calcular dinamicamente
                $ucsAtribuidas = $this->obterUcsAtribuidas($ug->id);
                $consumoAtribuido = $this->obterMediaConsumoAtribuido($ug->id);
                
                // Calcular consumo disponível
                $consumoDisponivel = max(0, $capacidadeTotal - $consumoAtribuido);
                
                // Calcular se pode receber a UC específica (usando calibragem global)
                $consumoUcCalibrado = $this->calcularValorCalibrado($consumoUc);
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
            $consumoCalibrado = $this->calcularValorCalibrado($consumoMedio); // Sem parâmetro de calibragem

            // Verificar capacidade da UG - CALCULADO DINAMICAMENTE
            $capacidadeTotal = floatval($ug->capacidade_calculada ?? 0);
            $consumoAtualAtribuido = $this->obterMediaConsumoAtribuido($request->ug_id);

            if (($consumoAtualAtribuido + $consumoCalibrado) > $capacidadeTotal) {
                return response()->json([
                    'success' => false,
                    'message' => 'UG não tem capacidade suficiente para esta UC'
                ], 400);
            }

            // Atualizar controle - setar UG e registrar data de alocação
            DB::update("
                UPDATE controle_clube
                SET ug_id = ?, data_alocacao_ug = NOW(), updated_at = NOW()
                WHERE id = ?
            ", [$request->ug_id, $id]);

            // ✅ REGISTRAR EVENTO DE AUDITORIA - ATRIBUIÇÃO DE UG
            AuditoriaService::registrar('controle_clube', $id, 'ALTERADO', [
                'evento_tipo' => 'UG_ATRIBUIDA',
                'descricao_evento' => "UG '{$ug->nome_usina}' atribuída ao controle",
                'modulo' => 'controle',
                'sub_acao' => 'ATRIBUIR_UG',
                'dados_novos' => [
                    'ug_id' => $request->ug_id,
                    'ug_nome' => $ug->nome_usina,
                    'consumo_calibrado' => $consumoCalibrado
                ],
                'dados_contexto' => [
                    'timestamp' => now()->toISOString()
                ]
            ]);

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

            // Verificar se é admin ou analista
            if (!in_array($currentUser->role, ['admin', 'analista'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Apenas administradores e analistas podem gerenciar UGs'
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

            // ✅ REGISTRAR EVENTO DE AUDITORIA - REMOÇÃO DE UG
            AuditoriaService::registrar('controle_clube', $id, 'ALTERADO', [
                'evento_tipo' => 'UG_REMOVIDA',
                'descricao_evento' => "UG '{$controle->ug_nome}' removida do controle",
                'modulo' => 'controle',
                'sub_acao' => 'REMOVER_UG',
                'evento_critico' => true,
                'dados_anteriores' => [
                    'ug_id' => $controle->ug_id,
                    'ug_nome' => $controle->ug_nome,
                    'valor_calibrado' => $controle->valor_calibrado
                ],
                'dados_contexto' => [
                    'timestamp' => now()->toISOString()
                ]
            ]);

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
    private function calcularValorCalibrado($media, $calibragemPersonalizada = null)
    {
        $mediaNum = floatval($media);
        
        if (!$mediaNum) {
            return 0;
        }
        
        // Se não passou calibragem personalizada, buscar a global
        if ($calibragemPersonalizada === null) {
            $calibragemGlobal = DB::selectOne(
                "SELECT valor FROM configuracoes WHERE chave = 'calibragem_global'"
            );
            $calibragem = floatval($calibragemGlobal->valor ?? 0);
        } else {
            $calibragem = floatval($calibragemPersonalizada);
        }
        
        // Retorna: consumo × (1 + calibragem/100)
        return $mediaNum * (1 + ($calibragem / 100));
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
     * ✅ OBTER MÉDIA DE CONSUMO ATRIBUÍDO A UMA UG - CORRIGIDO
     */
    private function obterMediaConsumoAtribuido(string $ugId): float
    {
        // Buscar calibragem global
        $calibragemGlobal = DB::selectOne(
            "SELECT valor FROM configuracoes WHERE chave = 'calibragem_global'"
        );
        
        $calibragemGlobalValor = floatval($calibragemGlobal->valor ?? 0);
        
        // Buscar todas as UCs atribuídas a esta UG
        $ucsAtribuidas = DB::select("
            SELECT uc.consumo_medio
            FROM controle_clube cc
            INNER JOIN unidades_consumidoras uc ON cc.uc_id = uc.id
            WHERE cc.ug_id = ? AND cc.deleted_at IS NULL
        ", [$ugId]);
        
        $totalCalibrado = 0;
        
        foreach ($ucsAtribuidas as $uc) {
            $consumoMedio = floatval($uc->consumo_medio ?? 0);
            
            if ($consumoMedio > 0) {
                // Aplicar calibragem: consumo × (1 + calibragem/100)
                $consumoCalibrado = $consumoMedio * (1 + ($calibragemGlobalValor / 100));
                $totalCalibrado += $consumoCalibrado;
            }
        }
        
        return $totalCalibrado;
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

            // ✅ BUSCAR PROPOSTA PARA COPIAR DESCONTOS
            $proposta = Proposta::find($request->proposta_id);

            if (!$proposta) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proposta não encontrada'
                ], 404);
            }

            // Calcular valor calibrado
            $uc = UnidadeConsumidora::find($request->uc_id);
            $valorCalibrado = null;
            if ($request->calibragem && $uc && $uc->consumo_medio) {
                $valorCalibrado = $this->calcularValorCalibrado($uc->consumo_medio, $request->calibragem);
            }

            // ✅ CRIAR CONTROLE COM DESCONTOS DA PROPOSTA
            $controle = ControleClube::create([
                'id' => Str::ulid(),
                'proposta_id' => $request->proposta_id,
                'uc_id' => $request->uc_id,
                'ug_id' => $request->ug_id,
                'calibragem' => $request->calibragem,
                'valor_calibrado' => $valorCalibrado,
                'observacoes' => $request->observacoes,
                'data_entrada_controle' => now(),
                // ✅ CORREÇÃO: Copiar descontos da proposta ao criar
                'desconto_tarifa' => $proposta->desconto_tarifa ?? '20%',
                'desconto_bandeira' => $proposta->desconto_bandeira ?? '20%'
            ]);

            // ✅ REGISTRAR EVENTO DE AUDITORIA - CRIAÇÃO DE CONTROLE
            AuditoriaService::registrar('controle_clube', $controle->id, 'CRIADO', [
                'evento_tipo' => 'CONTROLE_CRIADO',
                'descricao_evento' => 'Novo controle criado',
                'modulo' => 'controle',
                'dados_novos' => [
                    'proposta_id' => $controle->proposta_id,
                    'uc_id' => $controle->uc_id,
                    'ug_id' => $controle->ug_id,
                    'calibragem' => $controle->calibragem,
                    'valor_calibrado' => $valorCalibrado,
                    'desconto_tarifa' => $controle->desconto_tarifa,
                    'desconto_bandeira' => $controle->desconto_bandeira
                ],
                'dados_contexto' => [
                    'numero_proposta' => $proposta->numero_proposta ?? null,
                    'nome_cliente' => $proposta->nome_cliente ?? null,
                    'timestamp' => now()->toISOString()
                ]
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

            // ✅ REGISTRAR EVENTO DE AUDITORIA - EDIÇÃO DE CONTROLE
            if (!empty($controle->getChanges())) {
                AuditoriaService::registrar('controle_clube', $controle->id, 'ALTERADO', [
                    'evento_tipo' => 'CONTROLE_EDITADO',
                    'descricao_evento' => 'Controle editado pelo usuário',
                    'modulo' => 'controle',
                    'dados_novos' => $controle->getChanges(),
                    'dados_contexto' => [
                        'campos_alterados' => array_keys($controle->getChanges()),
                        'timestamp' => now()->toISOString()
                    ]
                ]);
            }

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

            // Verificar permissões (apenas admin e analista podem deletar)
            if (!in_array($currentUser->role, ['admin', 'analista'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Apenas administradores e analistas podem excluir controles'
                ], 403);
            }

            // ✅ VOLTAR STATUS DA UC PARA PENDENTE NO JSON
            if ($controle->proposta_id) {
                // Buscar a UC no controle para obter o número
                $uc = DB::selectOne("
                    SELECT numero_unidade
                    FROM unidades_consumidoras
                    WHERE id = ? AND deleted_at IS NULL
                ", [$controle->uc_id]);

                if ($uc) {
                    $numeroUC = $uc->numero_unidade;

                    // Atualizar o status da UC específica no JSON
                    DB::update("
                        UPDATE propostas
                        SET unidades_consumidoras = (
                            SELECT jsonb_agg(
                                CASE
                                    WHEN uc_data->>'numero_unidade' = ? OR uc_data->>'numeroUC' = ?
                                    THEN jsonb_set(uc_data, '{status}', '\"Aguardando\"')
                                    ELSE uc_data
                                END
                            )
                            FROM jsonb_array_elements(unidades_consumidoras::jsonb) as uc_data
                        )
                        WHERE id = ?
                    ", [$numeroUC, $numeroUC, $controle->proposta_id]);

                    Log::info('Status da UC atualizado para Aguardando no JSON', [
                        'proposta_id' => $controle->proposta_id,
                        'numero_uc' => $numeroUC,
                        'controle_id' => $id
                    ]);
                }
            }

            // ✅ SOFT DELETE DO CONTROLE
            $controle->delete();

            // ✅ REGISTRAR EVENTO DE AUDITORIA - EXCLUSÃO DE CONTROLE
            AuditoriaService::registrar('controle_clube', $controle->id, 'EXCLUIDO', [
                'evento_tipo' => 'CONTROLE_EXCLUIDO',
                'descricao_evento' => 'Controle removido do sistema',
                'modulo' => 'controle',
                'evento_critico' => true,
                'dados_anteriores' => [
                    'proposta_id' => $controle->proposta_id,
                    'uc_id' => $controle->uc_id,
                    'ug_id' => $controle->ug_id,
                    'calibragem' => $controle->calibragem,
                    'valor_calibrado' => $controle->valor_calibrado,
                    'observacoes' => $controle->observacoes
                ],
                'dados_contexto' => [
                    'numero_proposta' => $controle->proposta->numero_proposta ?? null,
                    'nome_cliente' => $controle->proposta->nome_cliente ?? null,
                    'numero_uc' => $numeroUC ?? null,
                    'motivo_exclusao' => 'Exclusão manual pelo usuário',
                    'timestamp' => now()->toISOString()
                ]
            ]);

            Log::info('Controle excluído com sucesso', [
                'controle_id' => $id,
                'proposta_id' => $controle->proposta_id,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'UC removida do controle com sucesso. Status da UC voltou para Aguardando.'
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

    /**
     * Buscar detalhes de múltiplos controles de uma vez (para relatórios)
     */
    public function getBulkUCDetalhes(Request $request): JsonResponse
    {
        try {
            $controleIds = $request->input('controle_ids', []);

            if (empty($controleIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum ID de controle fornecido'
                ], 400);
            }

            // Limitar a 500 por requisição para evitar sobrecarga
            $controleIds = array_slice($controleIds, 0, 500);

            $placeholders = implode(',', array_fill(0, count($controleIds), '?'));

            $controles = DB::select("
                SELECT
                    cc.id as controle_id,
                    cc.desconto_tarifa,
                    cc.desconto_bandeira,
                    uc.numero_unidade,
                    uc.apelido,
                    uc.consumo_medio,
                    uc.ligacao,
                    p.numero_proposta,
                    p.nome_cliente,
                    p.desconto_tarifa as proposta_desconto_tarifa,
                    p.desconto_bandeira as proposta_desconto_bandeira,
                    consultor.nome as consultor_nome,
                    COALESCE(
                        NULLIF(p.documentacao->uc.numero_unidade::text->>'cpf', 'null'),
                        NULLIF(p.documentacao->uc.numero_unidade::text->>'cnpj', 'null'),
                        ''
                    ) as cpf_cnpj,
                    COALESCE(
                        NULLIF(p.documentacao->uc.numero_unidade::text->>'logradouroUC', 'null'),
                        NULLIF(p.documentacao->uc.numero_unidade::text->>'enderecoUC', 'null'),
                        ''
                    ) as endereco_uc,
                    COALESCE(
                        NULLIF(p.documentacao->uc.numero_unidade::text->>'CEP_UC', 'null'),
                        ''
                    ) as cep_uc,
                    COALESCE(
                        NULLIF(p.documentacao->uc.numero_unidade::text->>'Bairro_UC', 'null'),
                        ''
                    ) as bairro_uc,
                    COALESCE(
                        NULLIF(p.documentacao->uc.numero_unidade::text->>'Cidade_UC', 'null'),
                        ''
                    ) as cidade_uc,
                    COALESCE(
                        NULLIF(p.documentacao->uc.numero_unidade::text->>'Estado_UC', 'null'),
                        ''
                    ) as estado_uc,
                    ug.nome_usina as ug_nome
                FROM controle_clube cc
                JOIN unidades_consumidoras uc ON cc.uc_id = uc.id
                JOIN propostas p ON cc.proposta_id = p.id
                LEFT JOIN usuarios consultor ON p.consultor_id = consultor.id
                LEFT JOIN unidades_consumidoras ug ON cc.ug_id = ug.id AND ug.gerador = true
                WHERE cc.id IN ($placeholders) AND cc.deleted_at IS NULL
            ", $controleIds);

            $resultado = [];
            foreach ($controles as $controle) {
                $resultado[$controle->controle_id] = [
                    'numero_unidade' => $controle->numero_unidade,
                    'nome_cliente' => $controle->nome_cliente,
                    'apelido' => $controle->apelido,
                    'consumo_medio' => floatval($controle->consumo_medio),
                    'desconto_tarifa' => $controle->desconto_tarifa ?? $controle->proposta_desconto_tarifa ?? '20%',
                    'desconto_bandeira' => $controle->desconto_bandeira ?? $controle->proposta_desconto_bandeira ?? '20%',
                    'cpf_cnpj' => $this->formatarCpfCnpj($controle->cpf_cnpj ?? ''),
                    'consultor_nome' => $controle->consultor_nome ?? '',
                    'ug_nome' => $controle->ug_nome ?? '',
                    'ligacao' => $controle->ligacao ?? '',
                    'endereco_completo' => $controle->endereco_uc ?? '',
                    'cep_uc' => $controle->cep_uc ?? '',
                    'bairro_uc' => $controle->bairro_uc ?? '',
                    'cidade_uc' => $controle->cidade_uc ?? '',
                    'estado_uc' => $controle->estado_uc ?? ''
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $resultado
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar detalhes em lote', [
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    public function getUCDetalhes(string $controleId): JsonResponse
    {
        try {
            $currentUser = auth()->user();

            $controle = DB::selectOne("
                SELECT
                    cc.*,
                    uc.numero_unidade,
                    uc.apelido,
                    uc.consumo_medio,
                    uc.ligacao,
                    uc.distribuidora,
                    p.numero_proposta,
                    p.nome_cliente,
                    p.desconto_tarifa as proposta_desconto_tarifa,
                    p.desconto_bandeira as proposta_desconto_bandeira,
                    consultor.nome as consultor_nome,
                    COALESCE(
                        NULLIF(p.documentacao->uc.numero_unidade::text->>'cpf', 'null'),
                        NULLIF(p.documentacao->uc.numero_unidade::text->>'cnpj', 'null'),
                        ''
                    ) as cpf_cnpj,
                    COALESCE(
                        NULLIF(p.documentacao->uc.numero_unidade::text->>'logradouroUC', 'null'),
                        NULLIF(p.documentacao->uc.numero_unidade::text->>'enderecoUC', 'null'),
                        ''
                    ) as endereco_uc,
                    ug.nome_usina as ug_nome
                FROM controle_clube cc
                JOIN unidades_consumidoras uc ON cc.uc_id = uc.id
                JOIN propostas p ON cc.proposta_id = p.id
                LEFT JOIN usuarios consultor ON p.consultor_id = consultor.id
                LEFT JOIN usuarios cliente ON p.usuario_id = cliente.id
                LEFT JOIN unidades_consumidoras ug ON cc.ug_id = ug.id AND ug.gerador = true
                WHERE cc.id = ? AND cc.deleted_at IS NULL
            ", [$controleId]);

            if (!$controle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle não encontrado'
                ], 404);
            }

            Log::info('Buscando detalhes da UC no controle', [
                'controle_id' => $controleId,
                'uc_numero' => $controle->numero_unidade,
                'controle_desconto_tarifa' => $controle->desconto_tarifa,
                'controle_desconto_bandeira' => $controle->desconto_bandeira,
                'proposta_desconto_tarifa' => $controle->proposta_desconto_tarifa,
                'proposta_desconto_bandeira' => $controle->proposta_desconto_bandeira
            ]);

            $dados = [
                'controle_id' => $controle->id,
                'proposta_id' => $controle->proposta_id,
                'numero_proposta' => $controle->numero_proposta,
                'nome_cliente' => $controle->nome_cliente,
                'consultor' => $controle->consultor_nome ?? 'Sem consultor',
                'consultor_nome' => $controle->consultor_nome ?? 'Sem consultor',

                // Dados da UC
                'uc_id' => $controle->uc_id,
                'numero_uc' => $controle->numero_unidade,
                'numero_unidade' => $controle->numero_unidade,
                'apelido' => $controle->apelido,
                'consumo_medio' => floatval($controle->consumo_medio),
                'ligacao' => $controle->ligacao,
                'distribuidora' => $controle->distribuidora,
                'cpf_cnpj' => $this->formatarCpfCnpj($controle->cpf_cnpj ?? ''),
                'endereco_completo' => $controle->endereco_uc ?? '',

                // Dados do controle
                'ug_id' => $controle->ug_id,
                'ug_nome' => $controle->ug_nome ?? '',
                'calibragem' => floatval($controle->calibragem ?? 0),
                'calibragem_individual' => $controle->calibragem_individual ? floatval($controle->calibragem_individual) : null,
                'calibragem_global' => \App\Models\Configuracao::getCalibragemGlobal(),
                'valor_calibrado' => floatval($controle->valor_calibrado ?? 0),
                'status_troca' => $controle->status_troca,
                'data_titularidade' => $controle->data_titularidade,
                'observacoes' => $controle->observacoes,
                'whatsapp' => $controle->whatsapp,
                'email' => $controle->email,
                'documentacao_troca_titularidade' => $controle->documentacao_troca_titularidade,

                // ✅ DESCONTOS CORRETOS
                // Valores ORIGINAIS da proposta (imutáveis)
                'proposta_desconto_tarifa' => $controle->proposta_desconto_tarifa ?? '20%',
                'proposta_desconto_bandeira' => $controle->proposta_desconto_bandeira ?? '20%',

                // Valores ATUAIS da controle_clube (podem ser null = usa proposta)
                'desconto_tarifa' => $controle->desconto_tarifa, // null ou "25%" por exemplo
                'desconto_bandeira' => $controle->desconto_bandeira, // null ou "15%" por exemplo
            ];

            return response()->json([
                'success' => true,
                'data' => $dados
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar detalhes da UC', [
                'controle_id' => $controleId,
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
     * ✅ Extrair valor numérico do desconto
     */
    private function extrairValorDesconto(?string $desconto): float
    {
        if (empty($desconto)) {
            return 0.0;
        }
        
        $numeroStr = str_replace(['%', ' '], '', $desconto);
        
        return floatval($numeroStr) ?: 0.0;
    }

    public function buscarPorUC(string $numeroUC): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            Log::info('Buscando controle por número da UC', [
                'numero_uc' => $numeroUC,
                'user_id' => $currentUser->id
            ]);

            // Buscar controle pela UC com data_entrada_controle
            $controle = DB::selectOne("
                SELECT 
                    cc.id,
                    cc.data_entrada_controle,
                    cc.proposta_id,
                    cc.uc_id,
                    uc.numero_unidade,
                    uc.apelido,
                    p.numero_proposta,
                    p.nome_cliente
                FROM controle_clube cc
                JOIN unidades_consumidoras uc ON cc.uc_id = uc.id
                LEFT JOIN propostas p ON cc.proposta_id = p.id
                WHERE uc.numero_unidade = ? 
                AND cc.deleted_at IS NULL
                ORDER BY cc.data_entrada_controle DESC
                LIMIT 1
            ", [$numeroUC]);

            if (!$controle) {
                Log::info('Nenhum controle encontrado para a UC', [
                    'numero_uc' => $numeroUC
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum controle encontrado para esta UC',
                    'data' => null
                ], 404);
            }

            // Verificar permissão do usuário
            if ($currentUser->role === 'vendedor') {
                // Para vendedores, verificar se podem ver esta proposta
                $proposta = DB::selectOne("SELECT usuario_id FROM propostas WHERE id = ?", [$controle->proposta_id]);
                
                if ($proposta && $proposta->usuario_id !== $currentUser->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Sem permissão para ver este controle'
                    ], 403);
                }
            }

            Log::info('Controle encontrado para UC', [
                'numero_uc' => $numeroUC,
                'controle_id' => $controle->id,
                'data_entrada_controle' => $controle->data_entrada_controle
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $controle->id,
                    'data_entrada_controle' => $controle->data_entrada_controle,
                    'proposta_id' => $controle->proposta_id,
                    'uc_id' => $controle->uc_id,
                    'numero_unidade' => $controle->numero_unidade,
                    'apelido' => $controle->apelido,
                    'numero_proposta' => $controle->numero_proposta,
                    'nome_cliente' => $controle->nome_cliente
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar controle por UC', [
                'numero_uc' => $numeroUC,
                'user_id' => $currentUser->id ?? 'N/A',
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * ✅ ATUALIZAR DADOS DA UC (CONSUMO MÉDIO E CALIBRAGEM)
     */
    public function updateUCDetalhes(Request $request, string $controleId): JsonResponse
    {
        try {
            DB::beginTransaction();

            $currentUser = auth()->user();
            
            $controle = DB::selectOne("
                SELECT cc.*, uc.numero_unidade
                FROM controle_clube cc
                JOIN unidades_consumidoras uc ON cc.uc_id = uc.id
                WHERE cc.id = ? AND cc.deleted_at IS NULL
            ", [$controleId]);

            if (!$controle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controle não encontrado'
                ], 404);
            }

            Log::info('Atualizando UC no controle', [
                'controle_id' => $controleId,
                'uc_numero' => $controle->numero_unidade,
                'request_data' => $request->all()
            ]);

            $updateFields = [];
            $updateParams = [];

            // ✅ 1. Consumo médio na UC
            if ($request->has('consumo_medio')) {
                DB::update("
                    UPDATE unidades_consumidoras 
                    SET consumo_medio = ?, updated_at = NOW() 
                    WHERE id = ?
                ", [$request->consumo_medio, $controle->uc_id]);
            }

            // ✅ 2. Calibragem
            if ($request->has('usa_calibragem_global')) {
                if ($request->usa_calibragem_global) {
                    $updateFields[] = 'calibragem_individual = NULL';
                    Log::info('Usando calibragem global - limpando individual');
                } else {
                    if ($request->has('calibragem_individual') && $request->calibragem_individual !== null) {
                        $updateFields[] = 'calibragem_individual = ?';
                        $updateParams[] = $request->calibragem_individual;
                        Log::info('Usando calibragem individual', ['valor' => $request->calibragem_individual]);
                    }
                }
            }

            // ✅ 3. DESCONTOS CORRETOS
            if ($request->has('usa_desconto_proposta')) {
                if ($request->usa_desconto_proposta) {
                    // ✅ Usar desconto da proposta = NULL na controle_clube
                    $updateFields[] = 'desconto_tarifa = NULL';
                    $updateFields[] = 'desconto_bandeira = NULL';
                    
                    Log::info('Configurado para usar desconto da proposta (NULL na controle_clube)', [
                        'controle_id' => $controleId
                    ]);
                } else {
                    // ✅ Usar desconto individual = salvar valores na controle_clube
                    if ($request->has('desconto_tarifa') && $request->desconto_tarifa !== null) {
                        $descontoTarifaFormatado = $this->formatarDesconto($request->desconto_tarifa);
                        $updateFields[] = 'desconto_tarifa = ?';
                        $updateParams[] = $descontoTarifaFormatado;
                    }
                    
                    if ($request->has('desconto_bandeira') && $request->desconto_bandeira !== null) {
                        $descontoBandeiraFormatado = $this->formatarDesconto($request->desconto_bandeira);
                        $updateFields[] = 'desconto_bandeira = ?';
                        $updateParams[] = $descontoBandeiraFormatado;
                    }
                    
                    Log::info('Configurado para usar desconto individual', [
                        'controle_id' => $controleId,
                        'desconto_tarifa' => $request->desconto_tarifa . '%',
                        'desconto_bandeira' => $request->desconto_bandeira . '%'
                    ]);
                }
            }

            // ✅ 4. Observações
            if ($request->has('observacoes')) {
                $updateFields[] = 'observacoes = ?';
                $updateParams[] = $request->observacoes;
            }

            // ✅ 5. WhatsApp
            if ($request->has('whatsapp')) {
                $updateFields[] = 'whatsapp = ?';
                $updateParams[] = $request->whatsapp;
            }

            // ✅ 6. Email
            if ($request->has('email')) {
                // Validar formato de email se não estiver vazio
                if (!empty($request->email) && !filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Formato de email inválido'
                    ], 400);
                }
                $updateFields[] = 'email = ?';
                $updateParams[] = $request->email;
            }

            // ✅ 5. Documentação de Troca de Titularidade
            if ($request->has('documentacao_troca_titularidade')) {
                $updateFields[] = 'documentacao_troca_titularidade = ?';
                $updateParams[] = $request->documentacao_troca_titularidade;

                Log::info('Atualizando documentação', [
                    'controle_id' => $controleId,
                    'arquivo' => $request->documentacao_troca_titularidade
                ]);
            }

            // ✅ EXECUTAR atualizações
            if (!empty($updateFields)) {
                $sql = "UPDATE controle_clube SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE id = ?";
                $updateParams[] = $controleId;

                Log::info('Executando SQL de atualização', [
                    'sql' => $sql,
                    'params' => $updateParams
                ]);

                $affected = DB::update($sql, $updateParams);

                Log::info('Atualização concluída', [
                    'affected_rows' => $affected,
                    'controle_id' => $controleId
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'UC atualizada com sucesso!',
                'data' => [
                    'controle_id' => $controleId,
                    'fields_updated' => count($updateFields)
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            
            Log::error('Erro ao atualizar UC no controle', [
                'controle_id' => $controleId,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ Helper para formatar desconto
     */
    private function formatarDesconto($valor): string
    {
        if (str_contains($valor, '%')) {
            return $valor;
        }

        if (is_numeric($valor)) {
            return $valor . '%';
        }

        return $valor;
    }

    /**
     * ✅ Helper para formatar CPF/CNPJ
     */
    private function formatarCpfCnpj(?string $documento): string
    {
        if (empty($documento)) {
            return '';
        }

        // Remove tudo que não for número
        $documento = preg_replace('/[^0-9]/', '', $documento);

        if (empty($documento)) {
            return '';
        }

        // CPF: 11 dígitos - formato: xxx.xxx.xxx-xx
        if (strlen($documento) === 11) {
            return substr($documento, 0, 3) . '.' .
                   substr($documento, 3, 3) . '.' .
                   substr($documento, 6, 3) . '-' .
                   substr($documento, 9, 2);
        }

        // CNPJ: 14 dígitos - formato: xx.xxx.xxx/xxxx-xx
        if (strlen($documento) === 14) {
            return substr($documento, 0, 2) . '.' .
                   substr($documento, 2, 3) . '.' .
                   substr($documento, 5, 3) . '/' .
                   substr($documento, 8, 4) . '-' .
                   substr($documento, 12, 2);
        }

        // Se não tiver 11 nem 14 dígitos, retorna vazio
        return '';
    }

    /**
     * ✅ UPLOAD DE DOCUMENTAÇÃO PARA UC
     */
    public function uploadDocumento(Request $request): JsonResponse
    {
        try {
            Log::info('Upload de documento iniciado', [
                'request_data' => $request->all(),
                'files' => $request->files->all()
            ]);

            $validator = Validator::make($request->all(), [
                'documento' => 'required|file|mimes:pdf,jpeg,jpg,png|max:10240', // 10MB
                'controle_id' => 'required|exists:controle_clube,id',
                'tipo' => 'required|string'
            ]);

            if ($validator->fails()) {
                Log::error('Validação falhou no upload', [
                    'errors' => $validator->errors()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $file = $request->file('documento');
            $controleId = $request->controle_id;
            $tipo = $request->tipo;

            // Garantir que o diretório existe usando Storage facade
            if (!Storage::disk('public')->exists('controle')) {
                Storage::disk('public')->makeDirectory('controle');
            }

            // Gerar nome único para o arquivo
            $extension = $file->getClientOriginalExtension();
            $nomeArquivo = $controleId . '_' . $tipo . '_' . time() . '.' . $extension;

            // Salvar arquivo usando Storage facade
            $path = $file->storeAs('controle', $nomeArquivo, 'public');

            if (!$path) {
                Log::error('Falha ao salvar arquivo', [
                    'controle_id' => $controleId,
                    'nome_arquivo' => $nomeArquivo,
                    'directory_exists' => Storage::disk('public')->exists('controle'),
                    'directory_writable' => is_writable(storage_path('app/public/controle'))
                ]);
                throw new \Exception('Erro ao salvar arquivo');
            }

            // Atualizar banco de dados
            $controle = ControleClube::findOrFail($controleId);

            // Remover arquivo anterior se existir
            if ($controle->documentacao_troca_titularidade) {
                Storage::disk('public')->delete('controle/' . $controle->documentacao_troca_titularidade);
            }

            $controle->documentacao_troca_titularidade = $nomeArquivo;
            $controle->save();

            Log::info('Documento de controle salvo', [
                'controle_id' => $controleId,
                'arquivo' => $nomeArquivo,
                'tipo' => $tipo
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Documento enviado com sucesso',
                'nome_arquivo' => $nomeArquivo
            ]);

        } catch (\Exception $e) {
            Log::error('Erro no upload de documento do controle', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ VISUALIZAR DOCUMENTO DE CONTROLE
     */
    public function visualizarDocumento(string $nomeArquivo)
    {
        try {
            Log::info('Tentativa de visualizar documento', [
                'arquivo' => $nomeArquivo,
                'path' => storage_path('app/public/controle/' . $nomeArquivo)
            ]);

            $caminhoArquivo = storage_path('app/public/controle/' . $nomeArquivo);

            if (!file_exists($caminhoArquivo)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Documento não encontrado'
                ], 404);
            }

            // Determinar tipo de conteúdo
            $extension = pathinfo($caminhoArquivo, PATHINFO_EXTENSION);
            $contentType = match(strtolower($extension)) {
                'pdf' => 'application/pdf',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                default => 'application/octet-stream'
            };

            return response()->file($caminhoArquivo, [
                'Content-Type' => $contentType,
                'Content-Disposition' => 'inline; filename="' . $nomeArquivo . '"'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao visualizar documento do controle', [
                'arquivo' => $nomeArquivo,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno: ' . $e->getMessage()
            ], 500);
        }
    }

    public function removerDocumento(string $id): JsonResponse
    {
        try {
            Log::info('Iniciando remoção de documento', [
                'controle_id' => $id
            ]);

            $controle = ControleClube::findOrFail($id);

            if (!$controle->documentacao_troca_titularidade) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum documento encontrado para remover'
                ], 404);
            }

            $nomeArquivo = $controle->documentacao_troca_titularidade;

            // Remover arquivo físico do storage
            $arquivoRemovido = Storage::disk('public')->delete('controle/' . $nomeArquivo);

            // Atualizar banco de dados
            $controle->documentacao_troca_titularidade = null;
            $controle->save();

            Log::info('Documento removido com sucesso', [
                'controle_id' => $id,
                'arquivo_removido' => $nomeArquivo,
                'arquivo_deletado_fisicamente' => $arquivoRemovido
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Documento removido com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao remover documento do controle', [
                'controle_id' => $id,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 📍 BUSCAR DOCUMENTAÇÃO DA PROPOSTA POR NÚMERO DA UC
     * Retorna o JSON de documentação da proposta associada à UC
     */
    public function buscarDocumentacaoPorUC(string $numeroUC): JsonResponse
    {
        try {
            $currentUser = auth()->user();

            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            Log::info('Buscando documentação por UC', [
                'numero_uc' => $numeroUC,
                'user_id' => $currentUser->id
            ]);

            // Buscar a proposta através do controle_clube e UC
            $resultado = DB::selectOne("
                SELECT
                    p.id as proposta_id,
                    p.documentacao,
                    uc.numero_unidade
                FROM controle_clube cc
                JOIN unidades_consumidoras uc ON cc.uc_id = uc.id
                JOIN propostas p ON cc.proposta_id = p.id
                WHERE uc.numero_unidade = ?
                    AND cc.deleted_at IS NULL
                LIMIT 1
            ", [$numeroUC]);

            if (!$resultado) {
                return response()->json([
                    'success' => false,
                    'message' => 'UC não encontrada no controle'
                ], 404);
            }

            // Decodificar o JSON de documentação
            $documentacao = $resultado->documentacao ? json_decode($resultado->documentacao, true) : [];

            return response()->json([
                'success' => true,
                'data' => [
                    'proposta_id' => $resultado->proposta_id,
                    'numero_unidade' => $resultado->numero_unidade,
                    'documentacao' => $documentacao
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar documentação por UC', [
                'numero_uc' => $numeroUC,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar documentação: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ CALCULAR DIAS NO STATUS ATUAL
     * Baseado no status_troca, calcula quantos dias a UC está no status atual
     */
    private function calcularDiasNoStatus($controle): ?int
    {
        $now = new \DateTime();
        $statusTroca = $controle->status_troca ?? 'Aguardando';

        try {
            switch ($statusTroca) {
                case 'Esteira':
                    // Dias em Esteira = NOW - data_assinatura (ou data_entrada_controle se não houver assinatura)
                    if ($controle->data_assinatura) {
                        $dataInicio = new \DateTime($controle->data_assinatura);
                        return $now->diff($dataInicio)->days;
                    } elseif ($controle->data_entrada_controle) {
                        $dataInicio = new \DateTime($controle->data_entrada_controle);
                        return $now->diff($dataInicio)->days;
                    }
                    break;

                case 'Em andamento':
                    // Dias em "Em andamento" = NOW - data_em_andamento
                    if ($controle->data_em_andamento) {
                        $dataInicio = new \DateTime($controle->data_em_andamento);
                        return $now->diff($dataInicio)->days;
                    }
                    break;

                case 'Associado':
                    // Dias em "Associado" SEM UG = NOW - data_titularidade (ou desde desalocação)
                    // Se tem UG, não calcular; se não tem, calcular desde quando ficou sem
                    if (!$controle->ug_id) {
                        // Sem UG: calcular desde quando foi desalocado (data_alocacao_ug NULL) ou associado
                        if ($controle->data_titularidade) {
                            $dataInicio = new \DateTime($controle->data_titularidade);
                            return $now->diff($dataInicio)->days;
                        }
                    }
                    // Com UG: retornar null (não mostrar contador)
                    return null;

                default:
                    // Para outros status, usar data_entrada_controle
                    if ($controle->data_entrada_controle) {
                        $dataInicio = new \DateTime($controle->data_entrada_controle);
                        return $now->diff($dataInicio)->days;
                    }
                    break;
            }
        } catch (\Exception $e) {
            Log::warning('Erro ao calcular dias no status', [
                'controle_id' => $controle->id ?? 'desconhecido',
                'status_troca' => $statusTroca,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * ✅ VERIFICAR SE O STATUS ESTÁ COM ALERTA (>7 DIAS)
     * Retorna um objeto com informações sobre o alerta
     */
    private function verificarAlertaStatus($controle): array
    {
        $diasNoStatus = $this->calcularDiasNoStatus($controle);
        $statusTroca = $controle->status_troca ?? 'Aguardando';

        // Status que devem gerar alertas quando passam de 7 dias
        $statusComAlerta = ['Esteira', 'Em andamento'];

        // Associados sem UG também devem ter alerta
        $isAssociadoSemUG = ($statusTroca === 'Associado' && !$controle->ug_id);

        if ($diasNoStatus !== null && (in_array($statusTroca, $statusComAlerta) || $isAssociadoSemUG)) {
            $mensagemContexto = $isAssociadoSemUG
                ? "UC associada há {$diasNoStatus} dias sem UG atribuída"
                : "UC em '{$statusTroca}' há {$diasNoStatus} dias";

            return [
                'tem_alerta' => $diasNoStatus > 7,
                'dias' => $diasNoStatus,
                'status' => $statusTroca,
                'nivel' => $diasNoStatus > 14 ? 'critico' : ($diasNoStatus > 7 ? 'atencao' : 'normal'),
                'mensagem' => $diasNoStatus > 7 ? $mensagemContexto : null
            ];
        }

        return [
            'tem_alerta' => false,
            'dias' => $diasNoStatus,
            'status' => $statusTroca,
            'nivel' => 'normal',
            'mensagem' => null
        ];
    }

}