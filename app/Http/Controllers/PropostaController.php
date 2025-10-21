<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use App\Services\AuditoriaService;
use Illuminate\Support\Facades\Storage;

class PropostaController extends Controller
{
    /**
     * ✅ LISTAR PROPOSTAS - COM MAPEAMENTO CORRETO PARA FRONTEND
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

            Log::info('Carregando propostas para usuário', [
                'user_id' => $currentUser->id,
                'user_role' => $currentUser->role
            ]);

            $query = "SELECT p.*, u.nome as consultor_nome FROM propostas p LEFT JOIN usuarios u ON p.consultor_id = u.id WHERE p.deleted_at IS NULL";
            $params = [];

            if (!in_array($currentUser->role, ['admin', 'analista'])) {
                if ($currentUser->role === 'consultor') {
                    // Consultor vê:
                    // 1. Propostas que ele criou (usuario_id)
                    // 2. Propostas atribuídas a ele (consultor_id)
                    // 3. Propostas dos subordinados (se houver)
                    
                    $subordinados = $currentUser->getAllSubordinates(); // Método já existe no model
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
                    
                } elseif ($currentUser->role === 'gerente') {
                    // Gerente vê suas propostas + propostas dos vendedores subordinados
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
                    
                } else {
                    // Vendedor vê apenas suas propostas
                    $query .= " AND p.usuario_id = ?";
                    $params[] = $currentUser->id;
                }
            }
            $query .= " ORDER BY p.created_at DESC";
            
            $propostas = DB::select($query, $params);

            // ✅ MAPEAR DADOS DO BACKEND PARA O FORMATO DO FRONTEND
            $propostasMapeadas = [];
            
            foreach ($propostas as $proposta) {
                $unidadesConsumidoras = json_decode($proposta->unidades_consumidoras ?? '[]', true);
                $beneficios = json_decode($proposta->beneficios ?? '[]', true);
                
                // 🚨 CORREÇÃO DEFINITIVA: Remover contaminação de $primeiraUC
                // Não usar dados de UCs em campos de compatibilidade da proposta

                $propostaMapeada = [
                    // Campos principais
                    'id' => $proposta->id,
                    'numeroProposta' => $proposta->numero_proposta,
                    'nomeCliente' => $proposta->nome_cliente,
                    'consultor' => $proposta->consultor_nome ?? 'Sem consultor',
                    'consultor_id' => $proposta->consultor_id,
                    'data' => $proposta->data_proposta,
                    'status' => $this->obterStatusProposta($unidadesConsumidoras),
                    'descontoTarifa' => $this->extrairValorDesconto($proposta->desconto_tarifa),
                    'descontoBandeira' => $this->extrairValorDesconto($proposta->desconto_bandeira),
                    'inflacao' => floatval($proposta->inflacao ?? 2.00),
                    'tarifaTributos' => floatval($proposta->tarifa_tributos ?? 0.98),
                    'recorrencia' => $proposta->recorrencia,
                    'observacoes' => $proposta->observacoes,
                    'documentacao' => json_decode($proposta->documentacao ?? '{}', true),

                    // 🔒 CAMPOS LIMPOS - sem contaminação de UCs de outras propostas
                    'apelido' => '', // Será preenchido na expansão com dados corretos
                    'numeroUC' => '', // Será preenchido na expansão com dados corretos
                    'numeroCliente' => '', // Será preenchido na expansão com dados corretos
                    'ligacao' => '', // Será preenchido na expansão com dados corretos
                    'media' => 0, // Será preenchido na expansão com dados corretos
                    'distribuidora' => '', // Será preenchido na expansão com dados corretos

                    // Arrays completos
                    'beneficios' => $beneficios,
                    'unidades_consumidoras' => $unidadesConsumidoras,
                    // Timestamps
                    'created_at' => $proposta->created_at,
                    'updated_at' => $proposta->updated_at
                ];
                
                $propostasMapeadas[] = $propostaMapeada;
            }

            Log::info('Propostas carregadas com sucesso', [
                'total' => count($propostasMapeadas),
                'user_id' => $currentUser->id
            ]);

            // ✅ EXPANDIR PROPOSTAS PARA UCs (uma linha por UC) - 🔒 VERSÃO CORRIGIDA COM SEGURANÇA
            $linhasExpandidas = [];

            foreach ($propostasMapeadas as $proposta) {
                $unidadesConsumidoras = $proposta['unidades_consumidoras'];

                if (empty($unidadesConsumidoras)) {
                    // 🚨 CORREÇÃO CRÍTICA: Criar linha com dados LIMPOS ao invés de usar $primeiraUC de outra proposta
                    $linhasExpandidas[] = [
                        'id' => $proposta['id'] . '-EMPTY',
                        'propostaId' => $proposta['id'],
                        'numeroProposta' => $proposta['numeroProposta'],
                        'nomeCliente' => $proposta['nomeCliente'],
                        'consultor' => $proposta['consultor'],
                        'consultor_id' => $proposta['consultor_id'],
                        'data' => $proposta['data'],
                        'status' => $proposta['status'],
                        'observacoes' => $proposta['observacoes'],
                        'recorrencia' => $proposta['recorrencia'],
                        'descontoTarifa' => $proposta['descontoTarifa'],
                        'descontoBandeira' => $proposta['descontoBandeira'],
                        'beneficios' => $proposta['beneficios'],
                        'documentacao' => $proposta['documentacao'],

                        // 🔒 DADOS LIMPOS - sem UC (ao invés de dados de outra proposta)
                        'apelido' => '-',
                        'numeroUC' => '-',
                        'numeroCliente' => '-',
                        'ligacao' => '-',
                        'media' => 0,
                        'distribuidora' => '-',

                        'created_at' => $proposta['created_at'],
                        'updated_at' => $proposta['updated_at']
                    ];
                } else {
                    // Para cada UC, criar uma linha separada (esta parte está correta)
                    foreach ($unidadesConsumidoras as $index => $uc) {
                        $linhasExpandidas[] = [
                            'id' => $proposta['id'] . '-UC-' . $index,
                            'propostaId' => $proposta['id'],
                            'numeroProposta' => $proposta['numeroProposta'],
                            'nomeCliente' => $proposta['nomeCliente'],
                            'consultor' => $proposta['consultor'],
                            'consultor_id' => $proposta['consultor_id'],
                            'data' => $proposta['data'],
                            'status' => $uc['status'] ?? $proposta['status'],
                            'observacoes' => $proposta['observacoes'],
                            'recorrencia' => $proposta['recorrencia'],
                            'descontoTarifa' => $proposta['descontoTarifa'],
                            'descontoBandeira' => $proposta['descontoBandeira'],
                            'beneficios' => $proposta['beneficios'],
                            'documentacao' => $proposta['documentacao'],

                            // Dados específicos desta UC (correto)
                            'apelido' => $uc['apelido'] ?? "UC " . ($uc['numero_unidade'] ?? ($index + 1)),
                            'numeroUC' => $uc['numero_unidade'] ?? $uc['numeroUC'] ?? '',
                            'numeroCliente' => $uc['numero_cliente'] ?? $uc['numeroCliente'] ?? '',
                            'ligacao' => $uc['ligacao'] ?? $uc['tipo_ligacao'] ?? '',
                            'media' => $uc['consumo_medio'] ?? $uc['media'] ?? 0,
                            'distribuidora' => $uc['distribuidora'] ?? '',

                            'created_at' => $proposta['created_at'],
                            'updated_at' => $proposta['updated_at']
                        ];
                    }
                }
            }

            Log::info('🔒 Propostas expandidas - CORREÇÃO DE SEGURANÇA APLICADA', [
                'propostas_originais' => count($propostasMapeadas),
                'linhas_expandidas' => count($linhasExpandidas),
                'user_id' => $currentUser->id,
                'user_role' => $currentUser->role,
                'security_fix_applied' => '2024-09-26 - Data leakage prevention'
            ]);

            return response()->json([
                'success' => true,
                'data' => $linhasExpandidas, // ✅ RETORNAR LINHAS EXPANDIDAS
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => count($linhasExpandidas),
                    'total' => count($linhasExpandidas),
                    'last_page' => 1
                ],
                'filters' => []
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao carregar propostas', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'user_id' => $currentUser->id ?? 'desconhecido'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    private function validarUcsDisponiveis(array $ucsArray): array
    {
        $ucsComPropostaAtiva = [];

        foreach ($ucsArray as $uc) {
            $numeroUC = $uc['numero_unidade'] ?? $uc['numeroUC'] ?? null;

            if (empty($numeroUC)) {
                continue; // Pular UCs sem número
            }

            // ✅ VERIFICAR 1: Campo JSON unidades_consumidoras na tabela propostas
            $propostasAtivasJSON = DB::select("
                SELECT DISTINCT
                    p.id,
                    p.numero_proposta,
                    p.nome_cliente,
                    uc_data->>'status' as status_uc
                FROM propostas p,
                jsonb_array_elements(p.unidades_consumidoras::jsonb) as uc_data
                WHERE p.deleted_at IS NULL
                AND (
                    uc_data->>'numero_unidade' = ? OR
                    uc_data->>'numeroUC' = ?
                )
                AND uc_data->>'status' NOT IN ('Cancelada', 'Perdida', 'Recusada')
            ", [$numeroUC, $numeroUC]);

            if (!empty($propostasAtivasJSON)) {
                foreach ($propostasAtivasJSON as $proposta) {
                    $ucsComPropostaAtiva[] = [
                        'numero_uc' => $numeroUC,
                        'apelido' => $uc['apelido'] ?? "UC {$numeroUC}",
                        'proposta_numero' => $proposta->numero_proposta,
                        'proposta_cliente' => $proposta->nome_cliente,
                        'status_atual' => $proposta->status_uc ?? 'Aguardando',
                        'origem' => 'propostas.unidades_consumidoras'
                    ];
                }
            }

            // ✅ VERIFICAR 2: Tabela controle_clube (UCs no Nexus Clube)
            $propostasAtivasControleClube = DB::select("
                SELECT DISTINCT
                    p.id,
                    p.numero_proposta,
                    p.nome_cliente,
                    cc.status_troca
                FROM controle_clube cc
                INNER JOIN unidades_consumidoras uc ON cc.uc_id = uc.id
                INNER JOIN propostas p ON cc.proposta_id = p.id
                WHERE uc.numero_unidade = ?
                AND cc.deleted_at IS NULL
                AND p.deleted_at IS NULL
                AND cc.status_troca IN ('Esteira', 'Em andamento', 'Concluído')
            ", [$numeroUC]);

            if (!empty($propostasAtivasControleClube)) {
                foreach ($propostasAtivasControleClube as $proposta) {
                    // Evitar duplicatas
                    $jaCadastrado = false;
                    foreach ($ucsComPropostaAtiva as $ucJaCadastrada) {
                        if ($ucJaCadastrada['numero_uc'] == $numeroUC &&
                            $ucJaCadastrada['proposta_numero'] == $proposta->numero_proposta) {
                            $jaCadastrado = true;
                            break;
                        }
                    }

                    if (!$jaCadastrado) {
                        $ucsComPropostaAtiva[] = [
                            'numero_uc' => $numeroUC,
                            'apelido' => $uc['apelido'] ?? "UC {$numeroUC}",
                            'proposta_numero' => $proposta->numero_proposta,
                            'proposta_cliente' => $proposta->nome_cliente,
                            'status_atual' => $proposta->status_troca ?? 'Em processo',
                            'origem' => 'controle_clube'
                        ];
                    }
                }
            }
        }

        return $ucsComPropostaAtiva;
    }

    private function validarUcsDisponiveisParaEdicao(array $ucsArray, string $propostaId): array
    {
        $ucsComPropostaAtiva = [];

        foreach ($ucsArray as $uc) {
            $numeroUC = $uc['numero_unidade'] ?? $uc['numeroUC'] ?? null;

            if (empty($numeroUC)) {
                continue;
            }

            // ✅ VERIFICAR 1: Campo JSON unidades_consumidoras na tabela propostas (EXCLUINDO PROPOSTA ATUAL)
            $propostasAtivasJSON = DB::select("
                SELECT DISTINCT
                    p.id,
                    p.numero_proposta,
                    p.nome_cliente,
                    uc_data->>'status' as status_uc
                FROM propostas p,
                jsonb_array_elements(p.unidades_consumidoras::jsonb) as uc_data
                WHERE p.deleted_at IS NULL
                AND p.id != ?
                AND (
                    uc_data->>'numero_unidade' = ? OR
                    uc_data->>'numeroUC' = ?
                )
                AND uc_data->>'status' NOT IN ('Cancelada', 'Perdida', 'Recusada')
            ", [$propostaId, $numeroUC, $numeroUC]);

            if (!empty($propostasAtivasJSON)) {
                foreach ($propostasAtivasJSON as $proposta) {
                    $ucsComPropostaAtiva[] = [
                        'numero_uc' => $numeroUC,
                        'apelido' => $uc['apelido'] ?? "UC {$numeroUC}",
                        'proposta_numero' => $proposta->numero_proposta,
                        'proposta_cliente' => $proposta->nome_cliente,
                        'status_atual' => $proposta->status_uc ?? 'Aguardando',
                        'origem' => 'propostas.unidades_consumidoras'
                    ];
                }
            }

            // ✅ VERIFICAR 2: Tabela controle_clube (EXCLUINDO PROPOSTA ATUAL)
            $propostasAtivasControleClube = DB::select("
                SELECT DISTINCT
                    p.id,
                    p.numero_proposta,
                    p.nome_cliente,
                    cc.status_troca
                FROM controle_clube cc
                INNER JOIN unidades_consumidoras uc ON cc.uc_id = uc.id
                INNER JOIN propostas p ON cc.proposta_id = p.id
                WHERE uc.numero_unidade = ?
                AND cc.deleted_at IS NULL
                AND p.deleted_at IS NULL
                AND p.id != ?
                AND cc.status_troca IN ('Esteira', 'Em andamento', 'Concluído')
            ", [$numeroUC, $propostaId]);

            if (!empty($propostasAtivasControleClube)) {
                foreach ($propostasAtivasControleClube as $proposta) {
                    // Evitar duplicatas
                    $jaCadastrado = false;
                    foreach ($ucsComPropostaAtiva as $ucJaCadastrada) {
                        if ($ucJaCadastrada['numero_uc'] == $numeroUC &&
                            $ucJaCadastrada['proposta_numero'] == $proposta->numero_proposta) {
                            $jaCadastrado = true;
                            break;
                        }
                    }

                    if (!$jaCadastrado) {
                        $ucsComPropostaAtiva[] = [
                            'numero_uc' => $numeroUC,
                            'apelido' => $uc['apelido'] ?? "UC {$numeroUC}",
                            'proposta_numero' => $proposta->numero_proposta,
                            'proposta_cliente' => $proposta->nome_cliente,
                            'status_atual' => $proposta->status_troca ?? 'Em processo',
                            'origem' => 'controle_clube'
                        ];
                    }
                }
            }
        }

        return $ucsComPropostaAtiva;
    }

    /**
     * ✅ ENDPOINT DE VERIFICAÇÃO INDIVIDUAL (PostgreSQL)
     */
    public function verificarDisponibilidadeUC(string $numero): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();

            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $todasPropostasAtivas = [];

            // ✅ VERIFICAR 1: Campo JSON unidades_consumidoras
            $propostasAtivasJSON = DB::select("
                SELECT DISTINCT
                    p.id,
                    p.numero_proposta,
                    p.nome_cliente,
                    uc_data->>'status' as status_uc
                FROM propostas p,
                jsonb_array_elements(p.unidades_consumidoras::jsonb) as uc_data
                WHERE p.deleted_at IS NULL
                AND (
                    uc_data->>'numero_unidade' = ? OR
                    uc_data->>'numeroUC' = ?
                )
                AND uc_data->>'status' NOT IN ('Cancelada', 'Perdida', 'Recusada')
            ", [$numero, $numero]);

            foreach ($propostasAtivasJSON as $proposta) {
                $todasPropostasAtivas[] = [
                    'numero_proposta' => $proposta->numero_proposta,
                    'nome_cliente' => $proposta->nome_cliente,
                    'status' => $proposta->status_uc ?? 'Aguardando',
                    'origem' => 'propostas.unidades_consumidoras'
                ];
            }

            // ✅ VERIFICAR 2: Tabela controle_clube
            $propostasAtivasControleClube = DB::select("
                SELECT DISTINCT
                    p.id,
                    p.numero_proposta,
                    p.nome_cliente,
                    cc.status_troca
                FROM controle_clube cc
                INNER JOIN unidades_consumidoras uc ON cc.uc_id = uc.id
                INNER JOIN propostas p ON cc.proposta_id = p.id
                WHERE uc.numero_unidade = ?
                AND cc.deleted_at IS NULL
                AND p.deleted_at IS NULL
                AND cc.status_troca IN ('Esteira', 'Em andamento', 'Concluído')
            ", [$numero]);

            foreach ($propostasAtivasControleClube as $proposta) {
                // Evitar duplicatas
                $jaCadastrado = false;
                foreach ($todasPropostasAtivas as $propostaExistente) {
                    if ($propostaExistente['numero_proposta'] == $proposta->numero_proposta) {
                        $jaCadastrado = true;
                        break;
                    }
                }

                if (!$jaCadastrado) {
                    $todasPropostasAtivas[] = [
                        'numero_proposta' => $proposta->numero_proposta,
                        'nome_cliente' => $proposta->nome_cliente,
                        'status' => $proposta->status_troca ?? 'Em processo',
                        'origem' => 'controle_clube'
                    ];
                }
            }

            $disponivel = empty($todasPropostasAtivas);

            return response()->json([
                'success' => true,
                'disponivel' => $disponivel,
                'numero_uc' => $numero,
                'propostas_ativas' => $todasPropostasAtivas
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao verificar disponibilidade da UC', [
                'numero_uc' => $numero,
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
     * ✅ VERSÃO ALTERNATIVA MAIS SIMPLES (CASO A ANTERIOR DÊ ERRO)
     * Use esta se a consulta jsonb_array_elements ainda der problemas
     */
    private function validarUcsDisponiveisSimples(array $ucsArray): array
    {
        $ucsComPropostaAtiva = [];
        
        foreach ($ucsArray as $uc) {
            $numeroUC = $uc['numero_unidade'] ?? $uc['numeroUC'] ?? null;
            
            if (empty($numeroUC)) {
                continue;
            }
            
            // ✅ BUSCAR TODAS AS PROPOSTAS E FILTRAR NO PHP
            $propostas = DB::select("
                SELECT id, numero_proposta, nome_cliente, unidades_consumidoras
                FROM propostas 
                WHERE deleted_at IS NULL
            ");
            
            foreach ($propostas as $proposta) {
                $unidadesConsumidoras = json_decode($proposta->unidades_consumidoras, true) ?? [];
                
                foreach ($unidadesConsumidoras as $ucProposta) {
                    $numeroUCProposta = $ucProposta['numero_unidade'] ?? $ucProposta['numeroUC'] ?? null;
                    $statusUC = $ucProposta['status'] ?? 'Aguardando';
                    
                    // Verificar se é a UC procurada e se não está cancelada
                    if ($numeroUCProposta == $numeroUC && 
                        !in_array($statusUC, ['Cancelada', 'Perdida', 'Recusada'])) {
                        
                        $ucsComPropostaAtiva[] = [
                            'numero_uc' => $numeroUC,
                            'apelido' => $uc['apelido'] ?? "UC {$numeroUC}",
                            'proposta_numero' => $proposta->numero_proposta,
                            'proposta_cliente' => $proposta->nome_cliente,
                            'status_atual' => $statusUC
                        ];
                        
                        break 2; // Sair dos dois loops - UC já encontrada
                    }
                }
            }
        }
        
        return $ucsComPropostaAtiva;
    }

    /**
     * ✅ VERSÃO COM VERIFICAÇÃO DE COMPATIBILIDADE DO BANCO
     * Use esta no método store() para detectar automaticamente o banco
     */
    private function validarUcsDisponiveisCompativel(array $ucsArray): array
    {
        try {
            // Tentar verificar se é PostgreSQL
            $isPostgreSQL = DB::connection()->getDriverName() === 'pgsql';
            
            if ($isPostgreSQL) {
                // Tentar a versão PostgreSQL otimizada
                return $this->validarUcsDisponiveis($ucsArray);
            } else {
                // Usar versão simples para outros bancos
                return $this->validarUcsDisponiveisSimples($ucsArray);
            }
            
        } catch (\Exception $e) {
            Log::warning('Erro na validação otimizada, usando versão simples', [
                'error' => $e->getMessage()
            ]);
            
            // Fallback para versão simples
            return $this->validarUcsDisponiveisSimples($ucsArray);
        }
    }


    /**
     * ✅ MODIFICAR O MÉTODO store() EXISTENTE
     * Adicionar esta validação ANTES de inserir no banco
     */
    public function store(Request $request): JsonResponse
    {
        Log::info('=== DEBUG REQUEST CONSULTOR ===', [
            'consultor_id_request' => $request->consultor_id ?? 'não encontrado',
            'consultor_request' => $request->consultor ?? 'não encontrado',
            'all_request' => $request->all()
        ]);
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            Log::info('=== INICIANDO CRIAÇÃO DE PROPOSTA ===', [
                'user_id' => $currentUser->id,
                'request_data' => $request->all()
            ]);


            DB::beginTransaction();

            // Processar consultor_id
            $consultorId = null;
            if ($request->has('consultor_id') && $request->consultor_id) {
                $consultorId = $request->consultor_id;
                // Limpar valores inválidos
                if ($consultorId === 'null' || $consultorId === '') {
                    $consultorId = null;
                }
            } elseif ($request->has('consultor') && $request->consultor) {
                // Buscar por nome se não vier ID
                $consultorEncontrado = DB::selectOne("
                    SELECT id FROM usuarios
                    WHERE nome = ? AND role IN ('admin', 'analista', 'consultor', 'gerente', 'vendedor')
                    AND deleted_at IS NULL
                ", [$request->consultor]);

                if ($consultorEncontrado) {
                    $consultorId = $consultorEncontrado->id;
                }
            }

            // Processar recorrência
            $recorrencia = $request->recorrencia ?? '3%';
            // Se não tem consultor, recorrência deve ser 0%
            if ($consultorId === null) {
                $recorrencia = '0%';
            }
            // ✅ GERAR ID E NÚMERO DA PROPOSTA
            $id = Str::uuid()->toString();
            $numeroProposta = $this->gerarNumeroProposta();
                
            // ✅ PROCESSAR BENEFÍCIOS
            $beneficiosJson = '[]';
            if ($request->has('beneficios') && is_array($request->beneficios)) {
                $beneficiosJson = json_encode($request->beneficios, JSON_UNESCAPED_UNICODE);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Erro ao converter benefícios para JSON: ' . json_last_error_msg());
                }
            }

            // ✅ PROCESSAR UNIDADES CONSUMIDORAS
            $ucArray = [];
            $ucJson = '[]';

            // Verificar ambos os formatos de nome do campo
            if ($request->has('unidades_consumidoras') && is_array($request->unidades_consumidoras)) {
                $ucArray = $request->unidades_consumidoras;
            } elseif ($request->has('unidadesConsumidoras') && is_array($request->unidadesConsumidoras)) {
                $ucArray = $request->unidadesConsumidoras;
            }
            
            if (!empty($ucArray)) {
                // ✅ USAR VERSÃO COMPATÍVEL
                $ucsComPropostaAtiva = $this->validarUcsDisponiveisCompativel($ucArray);
                
                if (!empty($ucsComPropostaAtiva)) {
                    // Montar mensagem de erro detalhada
                    $mensagemErro = "As seguintes unidades já possuem propostas ativas e não podem ser incluídas:\n\n";
                    
                    foreach ($ucsComPropostaAtiva as $uc) {
                        $mensagemErro .= "• UC {$uc['numero_uc']} ({$uc['apelido']}) - ";
                        $mensagemErro .= "Proposta {$uc['proposta_numero']} para {$uc['proposta_cliente']} ";
                        $mensagemErro .= "com status '{$uc['status_atual']}'\n";
                    }
                    
                    $mensagemErro .= "\nSomente unidades sem propostas ativas ou com propostas canceladas podem ser incluídas.";
                    
                    return response()->json([
                        'success' => false,
                        'message' => $mensagemErro,
                        'error_type' => 'ucs_com_proposta_ativa',
                        'ucs_bloqueadas' => $ucsComPropostaAtiva
                    ], 422);
                }
                
                // ✅ Se chegou aqui, todas as UCs estão disponíveis
                $ucArray = array_map(function($uc) {
                    $uc['status'] = $uc['status'] ?? 'Aguardando';
                    return $uc;
                }, $ucArray);
                
                $ucJson = json_encode($ucArray, JSON_UNESCAPED_UNICODE);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Erro ao converter UCs para JSON: ' . json_last_error_msg());
                }
            }
            
            if (!empty($ucArray)) {
                $ucArray = array_map(function($uc) {
                    $uc['status'] = $uc['status'] ?? 'Aguardando';
                    return $uc;
                }, $ucArray);
                
                $ucJson = json_encode($ucArray, JSON_UNESCAPED_UNICODE);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Erro ao converter UCs para JSON: ' . json_last_error_msg());
                }
            }

            Log::info('=== DEBUG UNIDADES CONSUMIDORAS ===', [
                'request_unidades_consumidoras' => $request->unidades_consumidoras ?? 'não encontrado',
                'request_unidadesConsumidoras' => $request->unidadesConsumidoras ?? 'não encontrado',
                'ucArray_count' => count($ucArray),
                'ucJson' => $ucJson
            ]);

            // ✅ INSERIR PROPOSTA NO BANCO
            $sql = "INSERT INTO propostas (
                id, numero_proposta, data_proposta, nome_cliente, consultor_id, 
                usuario_id, recorrencia, desconto_tarifa, desconto_bandeira,
                observacoes, beneficios, unidades_consumidoras,
                inflacao, tarifa_tributos, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

            $params = [
                $id,
                $numeroProposta,
                $request->data_proposta ?? date('Y-m-d'),
                $request->nome_cliente,
                $consultorId,  
                $currentUser->id,
                $recorrencia, 
                $this->formatarDesconto($request->economia ?? 20),   
                $this->formatarDesconto($request->bandeira ?? 20),  
                $request->observacoes ?? '',
                $beneficiosJson,
                $ucJson,
                $request->inflacao ?? 2.00,              
                $request->tarifa_tributos ?? 0.98
            ];

            $result = DB::insert($sql, $params);

            if (!$result) {
                throw new \Exception('Falha ao inserir proposta no banco de dados');
            }

            // ✅ BUSCAR PROPOSTA INSERIDA
            $propostaInserida = DB::selectOne("SELECT p.*, u.nome as consultor_nome FROM propostas p LEFT JOIN usuarios u ON p.consultor_id = u.id WHERE p.id = ?", [$id]);

            if (!$propostaInserida) {
                throw new \Exception('Proposta não encontrada após inserção');
            }
            
            if (isset($updateParams['status']) && $updateParams['status'] === 'Fechada' && 
                isset($propostaOriginal) && $propostaOriginal->status !== 'Fechada') {
                $this->popularControleAutomatico($id);
            }

            DB::commit();

            Log::info('Proposta criada com sucesso', [
                'proposta_id' => $propostaInserida->id,
                'numero_proposta' => $propostaInserida->numero_proposta,
                'user_id' => $currentUser->id
            ]);

            // ✅ MAPEAR RESPOSTA PARA O FRONTEND
            $unidadesConsumidoras = json_decode($propostaInserida->unidades_consumidoras ?? '[]', true);
            $beneficios = json_decode($propostaInserida->beneficios ?? '[]', true);

            // Registrar evento de auditoria para criação de proposta
            AuditoriaService::registrarCriacaoProposta($propostaInserida->id, [
                'numero' => $propostaInserida->numero_proposta,
                'nome_cliente' => $propostaInserida->nome_cliente,
                'consultor' => $propostaInserida->consultor_id,
                'unidades_consumidoras' => $unidadesConsumidoras,
                'beneficios' => $beneficios
            ]);

            // 🔒 $primeiraUC removida - evita contaminação de dados
            $respostaMapeada = [
                'id' => $propostaInserida->id,
                'numeroProposta' => $propostaInserida->numero_proposta,
                'nomeCliente' => $propostaInserida->nome_cliente,
                'consultor' => $propostaInserida->consultor_nome ?? 'Sem consultor',
                'consultor_id' => $propostaInserida->consultor_id,
                'data' => $propostaInserida->data_proposta,
                'status' => $this->obterStatusProposta($unidadesConsumidoras),
                'descontoTarifa' => $propostaInserida->desconto_tarifa,
                'descontoBandeira' => $propostaInserida->desconto_bandeira,
                'recorrencia' => $propostaInserida->recorrencia,
                'observacoes' => $propostaInserida->observacoes,
                // 🔒 CAMPOS LIMPOS - não contaminar resposta de criação
                'apelido' => '',
                'numeroUC' => '',
                'numeroCliente' => '',
                'ligacao' => '',
                'media' => 0,
                'distribuidora' => '',
                'beneficios' => $beneficios,
                'unidadesConsumidoras' => $unidadesConsumidoras
            ];

            return response()->json([
                'success' => true,
                'message' => 'Proposta criada com sucesso',
                'data' => $respostaMapeada
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao criar proposta', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'request_data' => $request->all(),
                'user_id' => $currentUser->id ?? 'desconhecido'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ VISUALIZAR PROPOSTA ESPECÍFICA
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


            $query = "SELECT p.*, u.nome as consultor_nome FROM propostas p LEFT JOIN usuarios u ON p.consultor_id = u.id WHERE p.deleted_at IS NULL AND p.id = ?";
            $params = [$id];

            // Se não for admin ou analista, verificar se é proposta do usuário
            if (!in_array($currentUser->role, ['admin', 'analista'])) {
                if ($currentUser->role === 'consultor') {
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
                    
                } else {
                    $query .= " AND p.usuario_id = ?";
                    $params[] = $currentUser->id;
                }
            }

            $proposta = DB::selectOne($query, $params);

            if (!$proposta) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proposta não encontrada'
                ], 404);
            }

            // ✅ MAPEAR DADOS PARA O FRONTEND
            $unidadesConsumidoras = json_decode($proposta->unidades_consumidoras ?? '[]', true);
            $beneficios = json_decode($proposta->beneficios ?? '[]', true);
            $documentacao = json_decode($proposta->documentacao ?? '{}', true);

            // 🔒 $primeiraUC removida - evita contaminação de dados
            $propostaMapeada = [
                'id' => $proposta->id,
                'numero_proposta' => $proposta->numero_proposta,
                'numeroProposta' => $proposta->numero_proposta, // Compatibilidade frontend
                'nome_cliente' => $proposta->nome_cliente,
                'nomeCliente' => $proposta->nome_cliente, // Compatibilidade frontend
                'consultor' => $proposta->consultor_nome ?? 'Sem consultor',
                'consultor_id' => $proposta->consultor_id,
                'data_proposta' => $proposta->data_proposta,
                'data' => $proposta->data_proposta, // Compatibilidade frontend
                'status' => $this->obterStatusProposta($unidadesConsumidoras),
                'observacoes' => $proposta->observacoes,
                'recorrencia' => $proposta->recorrencia,
                'descontoTarifa' => $this->extrairValorDesconto($proposta->desconto_tarifa),
                'descontoBandeira' => $this->extrairValorDesconto($proposta->desconto_bandeira),
                'documentacao' => json_decode($proposta->documentacao ?? '{}', true),
                'economia' => $this->extrairValorDesconto($proposta->desconto_tarifa),
                'bandeira' => $this->extrairValorDesconto($proposta->desconto_bandeira),
                'inflacao' => floatval($proposta->inflacao ?? 2.00),
                'tarifaTributos' => floatval($proposta->tarifa_tributos ?? 0.98),
                // 🔒 CAMPOS LIMPOS - não contaminar resposta individual
                'apelido' => '',
                'numeroUC' => '',
                'numeroCliente' => '',
                'ligacao' => '',
                'media' => 0,
                'distribuidora' => '',
                
                // ✅ ARRAYS COMPLETOS - NOMES CORRETOS
                'beneficios' => $beneficios,
                'unidades_consumidoras' => $unidadesConsumidoras,
                'unidadesConsumidoras' => $unidadesConsumidoras, // ← ADICIONEI ESTA LINHA (compatibilidade)
                
                // Timestamps
                'created_at' => $proposta->created_at,
                'updated_at' => $proposta->updated_at
            ];


            return response()->json([
                'success' => true,
                'data' => $propostaMapeada
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao carregar proposta', [
                'proposta_id' => $id, 
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
     * ✅ ATUALIZAR PROPOSTA
     */
    public function update(Request $request, string $id): JsonResponse
    {   
        Log::info('=== DEBUG REQUEST CONSULTOR ===', [
            'consultor_id_request' => $request->consultor_id ?? 'não encontrado',
            'consultor_request' => $request->consultor ?? 'não encontrado',
            'all_request' => $request->all()
        ]);
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            Log::info('Atualizando proposta', [
                'proposta_id' => $id,
                'user_id' => $currentUser->id,
                'request_data' => $request->all()
            ]);

            DB::beginTransaction();

            // ✅ VERIFICAR SE PROPOSTA EXISTE E USUÁRIO TEM PERMISSÃO
            $query = "SELECT p.*, u.nome as consultor_nome FROM propostas p LEFT JOIN usuarios u ON p.consultor_id = u.id WHERE p.id = ? AND p.deleted_at IS NULL";
            $params = [$id];

            if (!in_array($currentUser->role, ['admin', 'analista'])) {
                $query .= " AND p.usuario_id = ?";
                $params[] = $currentUser->id;
            }
            $proposta = DB::selectOne($query, $params);

            if (!$proposta) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proposta não encontrada ou sem permissão'
                ], 404);
            }
            
            if ($request->has('inflacao')) {
                $valor = floatval($request->inflacao);
                if ($valor < 0 || $valor > 100) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Inflação deve estar entre 0 e 100%'
                    ], 422);
                }
            }

            if ($request->has('tarifa_tributos')) {
                $valor = floatval($request->tarifa_tributos);
                if ($valor < 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tarifa com tributos deve ser um valor positivo'
                    ], 422);
                }
            }
            
            $updateFields = [];
            $updateParams = [];

            // Identificar qual UC está sendo editada
            $numeroUC = $request->get('numeroUC') ?? $request->get('numero_uc');

            // 1️⃣ CAMPOS GERAIS (aplicam para toda a proposta)
            $camposGerais = ['nome_cliente', 'data_proposta', 'observacoes', 'inflacao', 'tarifa_tributos'];
            foreach ($camposGerais as $campo) {
                if ($request->has($campo)) {
                    $updateFields[] = "{$campo} = ?";
                    $updateParams[] = $request->get($campo);
                }
            }

            $definindoSemConsultor = false;
            $recorrenciaDefinida = false;

            // Tratar recorrência primeiro (se vier no request)
            if ($request->has('recorrencia')) {
                $updateFields[] = 'recorrencia = ?';
                $updateParams[] = $request->get('recorrencia');
                $recorrenciaDefinida = true;
            }

            // ✅ LÓGICA CONSOLIDADA PARA CONSULTOR - APENAS UMA VEZ
            $consultorIdFinal = null;
            $processarConsultor = false;

            // Priorizar consultor_id direto se vier
            if ($request->has('consultor_id')) {
                $processarConsultor = true;
                $consultorId = $request->consultor_id;
                
                if (empty($consultorId) || $consultorId === 'null' || $consultorId === null || $consultorId === '') {
                    $consultorIdFinal = null;
                    $definindoSemConsultor = true;
                    
                    Log::info('✅ Consultor_id removido - definindo como NULL', [
                        'proposta_id' => $id,
                        'consultor_id_recebido' => $consultorId
                    ]);
                } else {
                    $consultorExiste = DB::selectOne("
                        SELECT id FROM usuarios
                        WHERE id = ? AND role IN ('admin', 'analista', 'consultor', 'gerente', 'vendedor')
                        AND deleted_at IS NULL
                    ", [$consultorId]);
                    
                    if ($consultorExiste) {
                        $consultorIdFinal = $consultorId;
                        
                        Log::info('✅ Consultor_id direto será atualizado', [
                            'consultor_id' => $consultorId
                        ]);
                    } else {
                        Log::warning('❌ Consultor_id não encontrado', [
                            'consultor_id_pesquisado' => $consultorId
                        ]);
                    }
                }
            } 
            // Se não tem consultor_id, buscar pelo nome
            elseif ($request->has('consultor')) {
                $processarConsultor = true;
                $consultorNome = trim($request->consultor);
                
                if (empty($consultorNome) || $consultorNome === 'Sem consultor') {
                    $consultorIdFinal = null;
                    $definindoSemConsultor = true;
                    
                    Log::info('✅ Consultor removido - definindo como NULL', [
                        'proposta_id' => $id,
                        'consultor_nome' => $consultorNome
                    ]);
                } else {
                    $consultorEncontrado = DB::selectOne("
                        SELECT id FROM usuarios
                        WHERE nome = ? AND role IN ('admin', 'analista', 'consultor', 'gerente', 'vendedor')
                        AND deleted_at IS NULL
                    ", [$consultorNome]);
                    
                    if ($consultorEncontrado) {
                        $consultorIdFinal = $consultorEncontrado->id;
                        
                        Log::info('✅ Consultor encontrado e será atualizado', [
                            'nome_consultor' => $consultorNome,
                            'consultor_id' => $consultorEncontrado->id
                        ]);
                    } else {
                        Log::warning('❌ Consultor não encontrado pelo nome', [
                            'nome_pesquisado' => $consultorNome
                        ]);
                    }
                }
            }

            // Adicionar ao UPDATE apenas uma vez
            if ($processarConsultor) {
                if ($consultorIdFinal === null) {
                    $updateFields[] = 'consultor_id = NULL';
                } else {
                    $updateFields[] = 'consultor_id = ?';
                    $updateParams[] = $consultorIdFinal;
                }
            }

            // REGRA 1: Se definiu como "sem consultor" E não definiu recorrência ainda, ajustar recorrência para 0%
            if ($definindoSemConsultor && !$recorrenciaDefinida) {
                $updateFields[] = 'recorrencia = ?';
                $updateParams[] = '0%';

                Log::info('✅ Recorrência ajustada para 0% por estar sem consultor', [
                    'proposta_id' => $id
                ]);
            }

            // REGRA 2: Se definiu consultor E não definiu recorrência ainda, ajustar recorrência para 3%
            if ($processarConsultor && $consultorIdFinal !== null && !$recorrenciaDefinida) {
                $updateFields[] = 'recorrencia = ?';
                $updateParams[] = '3%';

                Log::info('✅ Recorrência ajustada para 3% por ter consultor', [
                    'proposta_id' => $id,
                    'consultor_id' => $consultorIdFinal
                ]);
            }

            // Descontos especiais
            if ($request->has('descontoTarifa') || $request->has('economia')) {
                $valor = $request->has('descontoTarifa') ? $request->descontoTarifa : $request->economia;
                $updateFields[] = 'desconto_tarifa = ?';
                $updateParams[] = $this->formatarDesconto($valor);
            }

            if ($request->has('descontoBandeira') || $request->has('bandeira')) {
                $valor = $request->has('descontoBandeira') ? $request->descontoBandeira : $request->bandeira;
                $updateFields[] = 'desconto_bandeira = ?';
                $updateParams[] = $this->formatarDesconto($valor);
            }

            // Benefícios (geral)
            if ($request->has('beneficios') && is_array($request->beneficios)) {
                $updateFields[] = 'beneficios = ?';
                $updateParams[] = json_encode($request->beneficios, JSON_UNESCAPED_UNICODE);
            }
            
            // ✅ VALIDAR CAMPOS OBRIGATÓRIOS APENAS SE STATUS ESTÁ MUDANDO PARA FECHADO
            if ($request->has('status') && ($request->status === 'Fechado' || $request->status === 'Fechada')) {
                // Verificar se o status está realmente mudando
                $unidadesConsumidoras = json_decode($proposta->unidades_consumidoras ?? '[]', true);
                $ucAtual = null;

                // Encontrar a UC atual e seu status
                foreach ($unidadesConsumidoras as $uc) {
                    if (($uc['numero_unidade'] ?? $uc['numeroUC'] ?? '') === $numeroUC) {
                        $ucAtual = $uc;
                        break;
                    }
                }

                $statusAtual = $ucAtual['status'] ?? 'Aguardando';

                // ✅ SÓ VALIDAR SE STATUS ESTÁ MUDANDO PARA FECHADO
                if ($statusAtual !== 'Fechado' && $statusAtual !== 'Fechada') {
                    $documentacao = $request->documentacao ?? [];
                    $erros = [];

                    // Campos básicos obrigatórios - usar nome_cliente do request ou da proposta
                    $nomeCliente = $request->nomeCliente ?? $request->nome_cliente ?? $proposta->nome_cliente;
                    if (empty($nomeCliente)) $erros[] = 'Nome do Cliente';
                    if (empty($request->apelido)) $erros[] = 'Apelido UC';
                    if (empty($numeroUC)) $erros[] = 'Número UC';

                    // Campos de documentação obrigatórios
                    if (empty($documentacao['enderecoUC'])) {
                        $erros[] = 'Endereço da UC';
                    }

                    // ✅ ENDEREÇO DO REPRESENTANTE NÃO É MAIS OBRIGATÓRIO
                    // if (empty($documentacao['enderecoRepresentante'])) {
                    //     $erros[] = 'Endereço do Representante';
                    // }

                    if (empty($documentacao['nomeRepresentante'])) {
                        $erros[] = 'Nome do Representante';
                    }

                    // Validação específica por tipo de documento
                    if (($documentacao['tipoDocumento'] ?? '') === 'CPF') {
                        if (empty($documentacao['cpf'])) {
                            $erros[] = 'CPF é obrigatório para pessoa física';
                        }
                    } else if (($documentacao['tipoDocumento'] ?? '') === 'CNPJ') {
                        if (empty($documentacao['razaoSocial'])) {
                            $erros[] = 'Razão Social é obrigatória para pessoa jurídica';
                        }
                        if (empty($documentacao['cnpj'])) {
                            $erros[] = 'CNPJ é obrigatório para pessoa jurídica';
                        }
                    }

                    // Se há erros, retornar antes de processar
                    if (!empty($erros)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Para fechar a proposta, corrija os seguintes campos:',
                            'errors' => $erros
                        ], 422);
                    }
                }
            }

            // 3️⃣ DOCUMENTAÇÃO DA UC (específica para a UC sendo editada)
            if ($numeroUC && $request->has('documentacao')) {
                $documentacaoAtual = json_decode($proposta->documentacao ?? '{}', true);
                $novaDocumentacao = $request->get('documentacao');
                
                // Mesclar com documentação existente da UC
                $documentacaoAtual[$numeroUC] = array_merge(
                    $documentacaoAtual[$numeroUC] ?? [],
                    $novaDocumentacao
                );
                
                $updateFields[] = 'documentacao = ?';
                $updateParams[] = json_encode($documentacaoAtual, JSON_UNESCAPED_UNICODE);
                
                Log::info('Documentação da UC atualizada', [
                    'proposta_id' => $id,
                    'numero_uc' => $numeroUC,
                    'campos_atualizados' => array_keys($novaDocumentacao)
                ]);
            }

            // 4️⃣ CAMPOS ADICIONAIS DE WHATSAPP E EMAIL DO REPRESENTANTE
            if ($numeroUC && ($request->has('whatsappRepresentante') || $request->has('emailRepresentante'))) {
                $documentacaoAtual = json_decode($proposta->documentacao ?? '{}', true);
                
                // Garantir que existe a estrutura da UC
                if (!isset($documentacaoAtual[$numeroUC])) {
                    $documentacaoAtual[$numeroUC] = [];
                }
                
                // Adicionar WhatsApp se fornecido
                if ($request->has('whatsappRepresentante')) {
                    $documentacaoAtual[$numeroUC]['whatsappRepresentante'] = $request->input('whatsappRepresentante');
                }
                
                // Adicionar Email se fornecido
                if ($request->has('emailRepresentante')) {
                    $documentacaoAtual[$numeroUC]['emailRepresentante'] = $request->input('emailRepresentante');
                }

                if ($numeroUC && $request->has('logradouroUC')) {
                    $documentacaoAtual = json_decode($proposta->documentacao ?? '{}', true);
                    
                    // Garantir estrutura da UC
                    if (!isset($documentacaoAtual[$numeroUC])) {
                        $documentacaoAtual[$numeroUC] = [];
                    }
                    
                    // Salvar logradouroUC no JSON da documentação
                    $documentacaoAtual[$numeroUC]['logradouroUC'] = $request->input('logradouroUC');
                    
                    $updateFields[] = 'documentacao = ?';
                    $updateParams[] = json_encode($documentacaoAtual, JSON_UNESCAPED_UNICODE);
                    
                    Log::info('LogradouroUC salvo na documentação', [
                        'proposta_id' => $id,
                        'numero_uc' => $numeroUC,
                        'logradouro' => $request->input('logradouroUC')
                    ]);
                }


                if ($request->has('documentacao') && is_array($request->documentacao)) {
                    // Processar documentação específica da UC
                    $numeroUC = $request->get('numeroUC') ?? $request->get('numero_unidade');
                    
                    if ($numeroUC) {
                        // Buscar documentação existente
                        $documentacaoAtual = json_decode($proposta->documentacao ?? '{}', true);
                        
                        // Atualizar documentação específica da UC
                        $documentacaoAtual[$numeroUC] = array_merge(
                            $documentacaoAtual[$numeroUC] ?? [],
                            $request->documentacao
                        );
                        
                        $updateFields[] = 'documentacao = ?';
                        $updateParams[] = json_encode($documentacaoAtual, JSON_UNESCAPED_UNICODE);
                        
                        Log::info('Documentação da UC atualizada', [
                            'proposta_id' => $id,
                            'numero_uc' => $numeroUC,
                            'campos_atualizados' => array_keys($request->documentacao)
                        ]);
                    }
                }
                
                $updateFields[] = 'documentacao = ?';
                $updateParams[] = json_encode($documentacaoAtual, JSON_UNESCAPED_UNICODE);
            }

            if ($numeroUC) {
                $unidadesAtuais = json_decode($proposta->unidades_consumidoras ?? '[]', true);
                $ucAtualizada = false;
                
                $camposUC = ['apelido', 'status', 'consumo_medio', 'ligacao', 'distribuidora', 'numero_cliente'];
                
                foreach ($unidadesAtuais as &$uc) {
                    if (($uc['numero_unidade'] ?? $uc['numeroUC']) == $numeroUC) {
                        // Campos da requisição com nomes corretos
                        if ($request->has('apelido')) { $uc['apelido'] = $request->apelido; $ucAtualizada = true; }
                        if ($request->has('ligacao')) { $uc['ligacao'] = $request->ligacao; $ucAtualizada = true; }
                        if ($request->has('media')) { $uc['consumo_medio'] = $request->media; $ucAtualizada = true; }
                        if ($request->has('distribuidora')) { $uc['distribuidora'] = $request->distribuidora; $ucAtualizada = true; }
                        if ($request->has('status')) { $uc['status'] = $request->status; $ucAtualizada = true; }
                        
                        // Campos da requisição com nomes do array original
                        foreach ($camposUC as $campo) {
                            if ($request->has($campo)) {
                                $uc[$campo] = $request->get($campo);
                                $ucAtualizada = true;
                            }
                        }
                        break;
                    }
                }
                
                if ($ucAtualizada) {
                    $updateFields[] = 'unidades_consumidoras = ?';  // ✅ SÓ ESTA VEZ
                    $updateParams[] = json_encode($unidadesAtuais, JSON_UNESCAPED_UNICODE);
                }
            }
        
            if (empty($updateFields)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum campo válido fornecido para atualização'
                ], 400);
            }

            $updateFields[] = 'updated_at = NOW()';
            $updateParams[] = $id;
            
            Log::info('🔍 FINAL - Campos e parâmetros do UPDATE:', [
                'updateFields' => $updateFields,
                'updateParams' => $updateParams,
                'proposta_id' => $id
            ]);
            $updateSql = "UPDATE propostas SET " . implode(', ', $updateFields) . " WHERE id = ?";
            
            $result = DB::update($updateSql, $updateParams);

            if (!$result) {
                throw new \Exception('Nenhuma linha foi atualizada');
            }

            $propostaAtualizada = DB::selectOne("SELECT * FROM propostas WHERE id = ?", [$id]);

            $numeroUC = $request->get('numeroUC') ?? $request->get('numero_unidade');

            // Verificação para status geral da proposta (manter código existente)
            if ($request->has('status') && !$numeroUC && $request->status === 'Fechada') {
                $this->popularControleAutomatico($id);
            }

            DB::commit();

            Log::info('Proposta atualizada com sucesso', [
                'proposta_id' => $id,
                'user_id' => $currentUser->id,
                'logradouro_alterado' => $request->has('logradouroUC'),
                'documentacao_alterada' => $request->has('documentacao'),
                'campos_alterados' => array_keys($request->all())
            ]);

            // ✅ VERIFICAR SE DEVE PULAR LOG GENÉRICO (para evitar conflito com logs específicos)
            $skipLog = session()->get('skip_proposta_log', false);
            $alteracaoDocumentacaoApenas = session()->get('alteracao_documentacao_apenas', false);

            // Limpar flags de sessão
            session()->forget(['skip_proposta_log', 'alteracao_documentacao_apenas']);

            // Só registrar log genérico se não for alteração específica de documentação
            if (!$skipLog && !$alteracaoDocumentacaoApenas) {
                // Registrar evento de auditoria para edição de proposta
                AuditoriaService::registrar('propostas', $id, 'ALTERADO', [
                    'evento_tipo' => 'PROPOSTA_EDITADA',
                    'descricao_evento' => 'Proposta editada pelo usuário',
                    'modulo' => 'propostas',
                    'dados_novos' => [
                        'campos_alterados' => array_keys($request->all()),
                        'nome_cliente' => $propostaAtualizada->nome_cliente
                    ],
                    'dados_contexto' => [
                        'logradouro_alterado' => $request->has('logradouroUC'),
                        'documentacao_alterada' => $request->has('documentacao'),
                        'timestamp' => now()->toISOString()
                    ]
                ]);
            } else {
                Log::info('Log genérico de proposta pulado - alteração específica de documentação detectada', [
                    'proposta_id' => $id,
                    'skip_log' => $skipLog,
                    'doc_apenas' => $alteracaoDocumentacaoApenas
                ]);
            }

            // ✅ MAPEAR RESPOSTA PARA O FRONTEND
            $unidadesConsumidoras = json_decode($propostaAtualizada->unidades_consumidoras ?? '[]', true);
            $beneficios = json_decode($propostaAtualizada->beneficios ?? '[]', true);

            // 🔒 $primeiraUC removida - evita contaminação de dados
            $respostaMapeada = [
                'id' => $propostaAtualizada->id,
                'numeroProposta' => $propostaAtualizada->numero_proposta,
                'nomeCliente' => $propostaAtualizada->nome_cliente,
                'consultor' => $proposta->consultor_nome ?? 'Sem consultor',
                'consultor_id' => $propostaAtualizada->consultor_id,
                'data' => $propostaAtualizada->data_proposta,
                'status' => $this->obterStatusProposta($unidadesConsumidoras),
                'economia' => $this->extrairValorDesconto($propostaAtualizada->desconto_tarifa),
                'bandeira' => $this->extrairValorDesconto($propostaAtualizada->desconto_bandeira),
                'recorrencia' => $propostaAtualizada->recorrencia,
                'observacoes' => $propostaAtualizada->observacoes,
                // 🔒 CAMPOS LIMPOS - não contaminar resposta de atualização
                'apelido' => '',
                'numeroUC' => '',
                'numeroCliente' => '',
                'ligacao' => '',
                'media' => 0,
                'distribuidora' => '',
                'beneficios' => $beneficios,
                'unidadesConsumidoras' => $unidadesConsumidoras,
                'inflacao' => $propostaAtualizada->inflacao,              
                'tarifaTributos' => $propostaAtualizada->tarifa_tributos,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Proposta atualizada com sucesso',
                'data' => $respostaMapeada
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao atualizar proposta', [
                'proposta_id' => $id,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'user_id' => $currentUser->id ?? 'desconhecido'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ POPULAR CONTROLE AUTOMATICAMENTE QUANDO STATUS = FECHADA
     */
    public function popularControleAutomatico($proposta_id)
    {
        try {
            // ✅ PEGAR USUÁRIO ATUAL LOGADO
            $currentUser = JWTAuth::user();
            
            Log::info('Iniciando population do controle', [
                'proposta_id' => $proposta_id,
                'user_id' => $currentUser->id
            ]);
            
            // ✅ BUSCAR PROPOSTA
            $proposta = DB::selectOne("SELECT * FROM propostas WHERE id = ?", [$proposta_id]);
            
            if (!$proposta) {
                Log::warning('Proposta não encontrada', ['proposta_id' => $proposta_id]);
                return false;
            }

            // ✅ BUSCAR UCs DA PROPOSTA
            $unidadesConsumidoras = json_decode($proposta->unidades_consumidoras ?? '[]', true);
            
            if (empty($unidadesConsumidoras)) {
                Log::warning('Nenhuma UC encontrada na proposta', ['proposta_id' => $proposta_id]);
                return false;
            }

            Log::info('UCs encontradas para processar', [
                'proposta_id' => $proposta_id,
                'total_ucs' => count($unidadesConsumidoras),
                'ucs_data' => $unidadesConsumidoras
            ]);

            // ✅ PROCESSAR CADA UC
            foreach ($unidadesConsumidoras as $uc) {
                $numeroUC = $uc['numero_unidade'] ?? $uc['numeroUC'] ?? null;
                
                if (!$numeroUC) {
                    Log::warning('UC sem número válido', ['uc' => $uc]);
                    continue;
                }

                Log::info('Processando UC', [
                    'numero_uc' => $numeroUC,
                    'uc_data' => $uc
                ]);

                // ✅ VERIFICAR SE UC JÁ EXISTE NA TABELA unidades_consumidoras (apenas não deletadas)
                $ucExistente = DB::selectOne(
                    "SELECT id FROM unidades_consumidoras WHERE numero_unidade = ? AND deleted_at IS NULL",
                    [$numeroUC]
                );

                if (!$ucExistente) {
                    // ✅ GERAR ULID PARA A UC
                    $ucId = \Illuminate\Support\Str::ulid()->toString();
                    
                    // ✅ INSERIR UC NA TABELA unidades_consumidoras
                    DB::insert("
                        INSERT INTO unidades_consumidoras (
                            id, usuario_id, concessionaria_id, endereco_id, numero_unidade, 
                            apelido, consumo_medio, ligacao, distribuidora, proposta_id,
                            localizacao, is_ug, grupo, desconto_fatura, desconto_bandeira,
                            created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ", [
                        $ucId,                                          // id (ULID)
                        $currentUser->id,                               // usuario_id (usuário logado)
                        '01JB849ZDG0RPC5EB8ZFTB4GJN',                  // concessionaria_id (EQUATORIAL)
                        null,                                           // endereco_id (deixar em branco por enquanto)
                        $numeroUC,                                      // numero_unidade
                        $uc['apelido'] ?? 'UC ' . $numeroUC,          // apelido
                        $uc['consumo_medio'] ?? $uc['media'] ?? 0,     // consumo_medio
                        $uc['ligacao'] ?? 'Monofásica',               // ligacao (CORRIGIDO)
                        $uc['distribuidora'] ?? 'EQUATORIAL GO',       // distribuidora
                        $proposta_id,                                   // proposta_id
                        $uc['endereco_uc'] ?? $uc['localizacao'] ?? null, // localizacao
                        false,                                          // is_ug (sempre false para UCs normais)
                        'B',                                           // grupo (ADICIONADO)
                        $this->extrairValorDesconto($proposta->desconto_tarifa), // desconto_fatura (ADICIONADO)
                        $this->extrairValorDesconto($proposta->desconto_bandeira), // desconto_bandeira (ADICIONADO)
                    ]);

                    Log::info('UC criada na tabela unidades_consumidoras', [
                        'uc_id' => $ucId,
                        'numero_unidade' => $numeroUC,
                        'proposta_id' => $proposta_id,
                        'usuario_id' => $currentUser->id
                    ]);

                    $ucIdFinal = $ucId;
                } else {
                    $ucIdFinal = $ucExistente->id;
                    Log::info('UC já existia na tabela', [
                        'uc_id' => $ucIdFinal,
                        'numero_unidade' => $numeroUC
                    ]);
                }

                $controleExistente = DB::selectOne(
                    "SELECT id, deleted_at FROM controle_clube WHERE proposta_id = ? AND uc_id = ?", 
                    [$proposta_id, $ucIdFinal]
                );

                if (!$controleExistente) {

                    $controleId = \Illuminate\Support\Str::ulid()->toString();
                    
                    // ✅ CRIAR CONTROLE COM OS DESCONTOS DA PROPOSTA
                    DB::insert("
                        INSERT INTO controle_clube (
                            id, proposta_id, uc_id, calibragem, 
                            desconto_tarifa, desconto_bandeira,
                            data_entrada_controle, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                    ", [
                        $controleId,
                        $proposta_id,
                        $ucIdFinal, 
                        0.00,   
                        $proposta->desconto_tarifa,  
                        $proposta->desconto_bandeira 
                    ]);

                    Log::info('Controle criado com descontos da proposta', [
                        'controle_id' => $controleId,
                        'proposta_id' => $proposta_id,
                        'uc_id' => $ucIdFinal,
                        'numero_uc' => $numeroUC,
                        'desconto_tarifa' => $proposta->desconto_tarifa,
                        'desconto_bandeira' => $proposta->desconto_bandeira
                    ]);
                    
                } elseif ($controleExistente->deleted_at !== null) {
                    // ✅ REATIVAR CONTROLE SOFT DELETED E ATUALIZAR DESCONTOS
                    DB::update("
                        UPDATE controle_clube 
                        SET deleted_at = NULL, 
                            desconto_tarifa = ?,
                            desconto_bandeira = ?,
                            updated_at = NOW() 
                        WHERE id = ?
                    ", [
                        $proposta->desconto_tarifa,
                        $proposta->desconto_bandeira,
                        $controleExistente->id
                    ]);

                    Log::info('Controle reativado com descontos atualizados', [
                        'controle_id' => $controleExistente->id,
                        'proposta_id' => $proposta_id,
                        'desconto_tarifa' => $proposta->desconto_tarifa,
                        'desconto_bandeira' => $proposta->desconto_bandeira
                    ]);
                } else {
                    Log::info('Controle já existia ativo', [
                        'controle_existente_id' => $controleExistente->id,
                        'proposta_id' => $proposta_id,
                        'uc_id' => $ucIdFinal
                    ]);
                }
            }

            Log::info('Population do controle concluída com sucesso', [
                'proposta_id' => $proposta_id,
                'total_ucs_processadas' => count($unidadesConsumidoras)
            ]);
            
            return true;

        } catch (\Exception $e) {
            Log::error('Erro ao popular controle automático', [
                'proposta_id' => $proposta_id,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            ]);
            return false;
        }
    }

    /**
     * ✅ POPULAR CONTROLE AUTOMATICAMENTE PARA UMA UC ESPECÍFICA
     */
    public function popularControleAutomaticoParaUC($proposta_id, $numero_uc)
    {
        try {
            // ✅ TENTAR PEGAR USUÁRIO LOGADO, SE FALHAR BUSCAR DA PROPOSTA
            try {
                $currentUser = JWTAuth::user();
                $usuarioId = $currentUser ? $currentUser->id : null;
            } catch (\Exception $e) {
                $currentUser = null;
                $usuarioId = null;
            }
            
            // Se não há usuário logado (webhook), buscar usuário da proposta
            if (!$usuarioId) {
                $usuarioProposta = DB::selectOne(
                    "SELECT usuario_id FROM propostas WHERE id = ?", 
                    [$proposta_id]
                );
                $usuarioId = $usuarioProposta ? $usuarioProposta->usuario_id : '01K2CPBYE07B3HWW0CZHHB3ZCR';
                
                Log::info('Usando usuário da proposta (sem autenticação JWT)', [
                    'proposta_id' => $proposta_id,
                    'usuario_id' => $usuarioId,
                    'contexto' => 'webhook'
                ]);
            }
            
            Log::info('Iniciando population do controle para UC específica', [
                'proposta_id' => $proposta_id,
                'numero_uc' => $numero_uc,
                'user_id' => $usuarioId,
                'tem_jwt' => !is_null($currentUser)
            ]);
            
            // ✅ BUSCAR PROPOSTA
            $proposta = DB::selectOne("SELECT * FROM propostas WHERE id = ?", [$proposta_id]);
            
            if (!$proposta) {
                Log::warning('Proposta não encontrada', ['proposta_id' => $proposta_id]);
                return false;
            }

            // ✅ BUSCAR A UC ESPECÍFICA NA PROPOSTA
            $unidadesConsumidoras = json_decode($proposta->unidades_consumidoras ?? '[]', true);
            
            if (empty($unidadesConsumidoras)) {
                Log::warning('Nenhuma UC encontrada na proposta', ['proposta_id' => $proposta_id]);
                return false;
            }

            // Encontrar apenas a UC específica
            $ucEspecifica = null;
            foreach ($unidadesConsumidoras as $uc) {
                if (($uc['numero_unidade'] ?? $uc['numeroUC'] ?? '') == $numero_uc) {
                    $ucEspecifica = $uc;
                    break;
                }
            }
            
            if (!$ucEspecifica) {
                Log::warning('UC específica não encontrada na proposta', [
                    'proposta_id' => $proposta_id,
                    'numero_uc' => $numero_uc
                ]);
                return false;
            }

            $numeroUC = $ucEspecifica['numero_unidade'] ?? $ucEspecifica['numeroUC'];

            Log::info('UC encontrada para processar', [
                'numero_uc' => $numeroUC,
                'uc_data' => $ucEspecifica
            ]);

            // ✅ VERIFICAR SE UC JÁ EXISTE NA TABELA unidades_consumidoras (apenas não deletadas)
            $ucExistente = DB::selectOne(
                "SELECT id FROM unidades_consumidoras WHERE numero_unidade = ? AND deleted_at IS NULL",
                [$numeroUC]
            );

            if (!$ucExistente) {
                // ✅ GERAR ULID PARA A UC
                $ucId = \Illuminate\Support\Str::ulid()->toString();
                
                // ✅ INSERIR UC NA TABELA com os campos corretos
                DB::insert("
                    INSERT INTO unidades_consumidoras (
                        id, usuario_id, concessionaria_id, endereco_id, numero_unidade, 
                        apelido, consumo_medio, ligacao, distribuidora, proposta_id,
                        localizacao, gerador, grupo, desconto_fatura, desconto_bandeira,
                        created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ", [
                    $ucId,                                          // id (ULID)
                    $usuarioId,                                     // usuario_id
                    '01JB849ZDG0RPC5EB8ZFTB4GJN',                  // concessionaria_id (EQUATORIAL)
                    null,                                           // endereco_id
                    $numeroUC,                                      // numero_unidade
                    $ucEspecifica['apelido'] ?? 'UC ' . $numeroUC, // apelido
                    $ucEspecifica['consumo_medio'] ?? $ucEspecifica['media'] ?? 0, // consumo_medio
                    $ucEspecifica['ligacao'] ?? 'Monofásica',       // ligacao
                    $ucEspecifica['distribuidora'] ?? 'EQUATORIAL GO', // distribuidora
                    $proposta_id,                                   // proposta_id
                    $ucEspecifica['endereco_uc'] ?? $ucEspecifica['localizacao'] ?? null, // localizacao
                    false,                                          // gerador (sempre false para UCs normais)
                    'B',                                           // grupo
                    $this->extrairValorDesconto($proposta->desconto_tarifa), // desconto_fatura
                    $this->extrairValorDesconto($proposta->desconto_bandeira), // desconto_bandeira
                ]);

                Log::info('UC criada na tabela unidades_consumidoras', [
                    'uc_id' => $ucId,
                    'numero_unidade' => $numeroUC,
                    'proposta_id' => $proposta_id,
                    'usuario_id' => $usuarioId
                ]);

                $ucIdFinal = $ucId;
            } else {
                $ucIdFinal = $ucExistente->id;
                Log::info('UC já existia na tabela', [
                    'uc_id' => $ucIdFinal,
                    'numero_unidade' => $numeroUC
                ]);
            }

            // ✅ VERIFICAR SE JÁ EXISTE CONTROLE PARA ESTA UC
            $controleExistente = DB::selectOne(
                "SELECT id, deleted_at FROM controle_clube WHERE proposta_id = ? AND uc_id = ?",
                [$proposta_id, $ucIdFinal]
            );

            if ($controleExistente) {
                if ($controleExistente->deleted_at) {
                    // ✅ REATIVAR CONTROLE (REMOVER SOFT DELETE)
                    DB::update("UPDATE controle_clube SET deleted_at = NULL WHERE id = ?", [$controleExistente->id]);
                    
                    Log::info('Controle reativado (removido soft delete)', [
                        'controle_id' => $controleExistente->id,
                        'proposta_id' => $proposta_id,
                        'uc_id' => $ucIdFinal,
                        'numero_uc' => $numeroUC
                    ]);
                } else {
                    Log::info('Controle já existia ativo para esta UC', [
                        'controle_existente_id' => $controleExistente->id,
                        'proposta_id' => $proposta_id,
                        'uc_id' => $ucIdFinal,
                        'numero_uc' => $numeroUC
                    ]);
                }
            } else {
                // ✅ CRIAR NOVO CONTROLE
                $controleId = \Illuminate\Support\Str::ulid()->toString();
                
                DB::insert("
                    INSERT INTO controle_clube (
                        id, proposta_id, uc_id, calibragem, valor_calibrado,
                        data_entrada_controle, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                ", [
                    $controleId,
                    $proposta_id,
                    $ucIdFinal,
                    0, // calibragem padrão
                    floatval($ucEspecifica['consumo_medio'] ?? $ucEspecifica['media'] ?? 0)
                ]);

                Log::info('Novo controle criado para UC', [
                    'controle_id' => $controleId,
                    'proposta_id' => $proposta_id,
                    'uc_id' => $ucIdFinal,
                    'numero_uc' => $numeroUC
                ]);
            }

            Log::info('Population do controle para UC específica concluída', [
                'proposta_id' => $proposta_id,
                'numero_uc' => $numeroUC
            ]);
            
            return true;

        } catch (\Exception $e) {
            Log::error('Erro ao popular controle automático para UC específica', [
                'proposta_id' => $proposta_id,
                'numero_uc' => $numero_uc,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            ]);
            return false;
        }
    }

    /**
     * ✅ UPLOAD DE DOCUMENTO ESPECÍFICO
     */
    
    public function uploadDocumento(Request $request, $id)
    {
        // ✅ LOG INICIAL DETALHADO
        Log::info('=== INÍCIO UPLOAD DOCUMENTO ===', [
            'proposta_id' => $id,
            'timestamp' => now(),
            'user_id' => auth()->id(),
            'memory_usage' => memory_get_usage(true) . ' bytes',
            'request_data' => [
                'numeroUC' => $request->input('numeroUC'),
                'tipoDocumento' => $request->input('tipoDocumento'),
            ],
            'arquivo_info' => $request->file('arquivo') ? [
                'nome' => $request->file('arquivo')->getClientOriginalName(),
                'tamanho' => $request->file('arquivo')->getSize(),
                'mime_type' => $request->file('arquivo')->getMimeType(),
                'is_valid' => $request->file('arquivo')->isValid(),
                'error_code' => $request->file('arquivo')->getError()
            ] : 'Nenhum arquivo'
        ]);

        try {
            // ✅ VERIFICAR SE A PROPOSTA EXISTE
            $proposta = DB::selectOne(
                "SELECT id, numero_proposta, documentacao FROM propostas WHERE id = ? AND deleted_at IS NULL", 
                [$id]
            );
            
            if (!$proposta) {
                Log::error('Proposta não encontrada', ['proposta_id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Proposta não encontrada',
                    'debug_info' => [
                        'proposta_id' => $id,
                        'timestamp' => now()
                    ]
                ], 404);
            }

            Log::info('Proposta encontrada', [
                'proposta_id' => $id,
                'numero_proposta' => $proposta->numero_proposta
            ]);

            // ✅ VALIDAR ARQUIVO
            $arquivo = $request->file('arquivo');
            if (!$arquivo || !$arquivo->isValid()) {
                Log::error('Arquivo inválido', [
                    'proposta_id' => $id,
                    'arquivo_presente' => !is_null($arquivo),
                    'arquivo_valido' => $arquivo?->isValid(),
                    'erro_upload' => $arquivo?->getError()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum arquivo válido foi enviado',
                    'debug_info' => [
                        'arquivo_presente' => !is_null($arquivo),
                        'arquivo_valido' => $arquivo?->isValid(),
                        'erro_upload' => $arquivo?->getError(),
                        'timestamp' => now()
                    ]
                ], 400);
            }

            // ✅ VALIDAR PARÂMETROS
            $numeroUC = $request->input('numeroUC');
            $tipoDocumento = $request->input('tipoDocumento');
            
            if (!$tipoDocumento) {
                Log::error('Tipo de documento não informado', ['proposta_id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Tipo de documento é obrigatório',
                    'debug_info' => [
                        'proposta_id' => $id,
                        'timestamp' => now()
                    ]
                ], 400);
            }

            if ($tipoDocumento === 'faturaUC' && !$numeroUC) {
                Log::error('Número UC não informado para fatura', ['proposta_id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Número da UC é obrigatório para faturas',
                    'debug_info' => [
                        'proposta_id' => $id,
                        'tipo_documento' => $tipoDocumento,
                        'timestamp' => now()
                    ]
                ], 400);
            }

            // ✅ VALIDAÇÕES DE ARQUIVO (como nas correções anteriores)
            $extensao = strtolower($arquivo->getClientOriginalExtension());
            $mimeType = $arquivo->getMimeType();
            $tamanhoMaximo = 10 * 1024 * 1024; // 10MB
            
            $extensoesPermitidas = ['pdf', 'jpg', 'jpeg', 'png'];
            $mimeTypesPermitidos = [
                'application/pdf',
                'image/jpeg',
                'image/jpg',
                'image/pjpeg',
                'image/png',
                'image/x-png'
            ];
            
            if (!in_array($extensao, $extensoesPermitidas)) {
                Log::error('Extensão não permitida', [
                    'extensao' => $extensao,
                    'permitidas' => $extensoesPermitidas
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => "Extensão não permitida: .{$extensao}",
                    'debug_info' => [
                        'extensao' => $extensao,
                        'permitidas' => $extensoesPermitidas,
                        'timestamp' => now()
                    ]
                ], 400);
            }

            if ($arquivo->getSize() > $tamanhoMaximo) {
                Log::error('Arquivo muito grande', [
                    'tamanho_bytes' => $arquivo->getSize(),
                    'limite_bytes' => $tamanhoMaximo
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Arquivo muito grande. Tamanho máximo: 10MB',
                    'debug_info' => [
                        'tamanho_mb' => round($arquivo->getSize() / 1024 / 1024, 2),
                        'limite_mb' => 10,
                        'timestamp' => now()
                    ]
                ], 400);
            }

            Log::info('Validações passaram', [
                'extensao' => $extensao,
                'mime_type' => $mimeType,
                'tamanho_mb' => round($arquivo->getSize() / 1024 / 1024, 2)
            ]);

            // ✅ GERAR NOME E SALVAR ARQUIVO
            $timestamp = time();
            $ano = date('Y');
            $mes = date('m');
            $numeroProposta = $proposta->numero_proposta;
            
            if ($tipoDocumento === 'faturaUC') {
                $nomeArquivo = "{$ano}_{$mes}_{$numeroProposta}_{$numeroUC}_fatura_{$timestamp}.{$extensao}";
                $diretorio = 'propostas/faturas';
            } else {
                $nomeArquivo = "{$ano}_{$mes}_{$numeroProposta}_{$numeroUC}_{$tipoDocumento}_{$timestamp}.{$extensao}";
                $diretorio = 'propostas/documentos';
            }

            Log::info('Tentando salvar arquivo', [
                'nome_arquivo' => $nomeArquivo,
                'diretorio' => $diretorio,
                'tamanho_original' => $arquivo->getSize()
            ]);

            // ✅ CRIAR DIRETÓRIO SE NÃO EXISTIR
            $caminhoCompleto = storage_path("app/public/{$diretorio}");
            if (!file_exists($caminhoCompleto)) {
                mkdir($caminhoCompleto, 0755, true);
                Log::info('Diretório criado', ['path' => $caminhoCompleto]);
            }

            // ✅ SALVAR ARQUIVO COM VERIFICAÇÃO DETALHADA
            try {
                $caminhoArquivo = $arquivo->storeAs($diretorio, $nomeArquivo, 'public');
                
                if (!$caminhoArquivo) {
                    throw new \Exception('storeAs retornou false');
                }

                // Verificar se arquivo foi realmente salvo
                $caminhoFisicoCompleto = Storage::disk('public')->path($caminhoArquivo);
                if (!file_exists($caminhoFisicoCompleto)) {
                    throw new \Exception('Arquivo não encontrado após salvamento');
                }

                $tamanhoSalvo = filesize($caminhoFisicoCompleto);
                
                Log::info('Arquivo salvo com sucesso', [
                    'caminho_salvo' => $caminhoArquivo,
                    'caminho_fisico' => $caminhoFisicoCompleto,
                    'tamanho_original' => $arquivo->getSize(),
                    'tamanho_salvo' => $tamanhoSalvo,
                    'url_publica' => Storage::disk('public')->url($caminhoArquivo)
                ]);

            } catch (\Exception $saveError) {
                Log::error('Erro crítico ao salvar arquivo', [
                    'error' => $saveError->getMessage(),
                    'diretorio' => $diretorio,
                    'nome_arquivo' => $nomeArquivo,
                    'espaco_livre' => disk_free_space($caminhoCompleto)
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Erro ao salvar arquivo: ' . $saveError->getMessage(),
                    'debug_info' => [
                        'erro_salvamento' => $saveError->getMessage(),
                        'timestamp' => now()
                    ]
                ], 500);
            }

            // ✅ ATUALIZAR DOCUMENTAÇÃO
            $atualizouDocumentacao = $this->atualizarDocumentacaoProposta($id, $numeroUC, $tipoDocumento, $nomeArquivo);
            
            if (!$atualizouDocumentacao) {
                Log::warning('Falha ao atualizar documentação, mas arquivo foi salvo');
            }

            // ✅ RESPOSTA DE SUCESSO COM LOGS DETALHADOS
            $responseData = [
                'success' => true,
                'nomeArquivo' => $nomeArquivo,
                'caminhoCompleto' => $caminhoArquivo,
                'tipoDocumento' => $tipoDocumento,
                'numeroUC' => $numeroUC,
                'url' => Storage::disk('public')->url($caminhoArquivo),
                'tamanho' => filesize($caminhoFisicoCompleto),
                'message' => $tipoDocumento === 'faturaUC' 
                    ? 'Fatura da UC enviada com sucesso' 
                    : 'Documento enviado com sucesso',
                'debug_info' => [
                    'proposta_id' => $id,
                    'numero_proposta' => $proposta->numero_proposta,
                    'documentacao_atualizada' => $atualizouDocumentacao,
                    'timestamp' => now()
                ]
            ];

            Log::info('=== SUCESSO UPLOAD DOCUMENTO ===', [
                'proposta_id' => $id,
                'nome_arquivo' => $nomeArquivo,
                'tamanho_final' => filesize($caminhoFisicoCompleto),
                'response_keys' => array_keys($responseData),
                'memory_final' => memory_get_usage(true) . ' bytes'
            ]);

            // ✅ GARANTIR QUE A RESPOSTA SEJA JSON VÁLIDA
            $response = response()->json($responseData, 200);
            
            // Log da resposta antes de enviá-la
            Log::info('Resposta JSON gerada', [
                'response_size' => strlen(json_encode($responseData)),
                'json_valid' => json_last_error() === JSON_ERROR_NONE,
                'json_error' => json_last_error_msg()
            ]);

            return $response;

        } catch (\Exception $e) {
            Log::error('=== ERRO CRÍTICO UPLOAD DOCUMENTO ===', [
                'error' => $e->getMessage(),
                'proposta_id' => $id,
                'tipo_documento' => $request->input('tipoDocumento') ?? 'N/A',
                'numero_uc' => $request->input('numeroUC') ?? 'N/A',
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
                'memory_usage' => memory_get_usage(true) . ' bytes',
                'timestamp' => now()
            ]);

            // ✅ RESPOSTA DE ERRO ESTRUTURADA
            $errorResponse = [
                'success' => false,
                'message' => 'Erro interno no upload: ' . $e->getMessage(),
                'error_type' => 'upload_error',
                'debug_info' => [
                    'proposta_id' => $id,
                    'error_line' => $e->getLine(),
                    'error_file' => basename($e->getFile()),
                    'timestamp' => now()
                ]
            ];

            return response()->json($errorResponse, 500);
        }
    }

    /**
     * ✅ NOVO MÉTODO AUXILIAR: Converter código de erro de upload para mensagem
     */
    private function getUploadErrorMessage($errorCode)
    {
        $errors = [
            UPLOAD_ERR_OK => 'Sem erro',
            UPLOAD_ERR_INI_SIZE => 'Arquivo maior que upload_max_filesize no php.ini',
            UPLOAD_ERR_FORM_SIZE => 'Arquivo maior que MAX_FILE_SIZE no formulário',
            UPLOAD_ERR_PARTIAL => 'Upload foi interrompido',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado',
            UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária não encontrada',
            UPLOAD_ERR_CANT_WRITE => 'Falha ao escrever arquivo no disco',
            UPLOAD_ERR_EXTENSION => 'Extensão do PHP bloqueou o upload'
        ];

        return $errors[$errorCode] ?? "Erro desconhecido: {$errorCode}";
    }

    /**
     * ✅ MÉTODO AUXILIAR CORRIGIDO: Atualizar documentação JSON da proposta
     */
    private function atualizarDocumentacaoProposta($propostaId, $numeroUC, $tipoDocumento, $nomeArquivo)
    {
        try {
            Log::info('Iniciando atualização da documentação', [
                'proposta_id' => $propostaId,
                'numero_uc' => $numeroUC,
                'tipo_documento' => $tipoDocumento,
                'nome_arquivo' => $nomeArquivo
            ]);

            // Buscar documentação atual
            $proposta = DB::selectOne("SELECT documentacao FROM propostas WHERE id = ?", [$propostaId]);
            
            $documentacaoAtual = [];
            if ($proposta && $proposta->documentacao) {
                $decodificada = json_decode($proposta->documentacao, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $documentacaoAtual = $decodificada;
                } else {
                    Log::warning('JSON inválido na documentação atual', [
                        'proposta_id' => $propostaId,
                        'json_error' => json_last_error_msg()
                    ]);
                }
            }

            Log::info('Documentação atual carregada', [
                'proposta_id' => $propostaId,
                'documentacao_keys' => array_keys($documentacaoAtual),
                'tem_faturas_ucs' => isset($documentacaoAtual['faturas_ucs'])
            ]);

            // ✅ ESTRUTURA ESPECÍFICA PARA FATURAS DAS UCs
            if ($tipoDocumento === 'faturaUC') {
                // Inicializar estrutura de faturas se não existir
                if (!isset($documentacaoAtual['faturas_ucs'])) {
                    $documentacaoAtual['faturas_ucs'] = [];
                }
                
                // Adicionar/atualizar fatura da UC específica
                $documentacaoAtual['faturas_ucs'][$numeroUC] = $nomeArquivo;
                $documentacaoAtual['data_upload_faturas'] = date('Y-m-d H:i:s');
                
                Log::info('Fatura de UC adicionada à documentação', [
                    'proposta_id' => $propostaId,
                    'numero_uc' => $numeroUC,
                    'arquivo' => $nomeArquivo,
                    'total_faturas' => count($documentacaoAtual['faturas_ucs'])
                ]);
            } 
            // ✅ DOCUMENTOS GERAIS DA PROPOSTA
            else {
                // ✅ Verificar se é um documento extra
                if (strpos($tipoDocumento, 'documentoExtra_') === 0) {
                    // Inicializar array de documentos extras se não existir
                    if (!isset($documentacaoAtual['documentosExtras'])) {
                        $documentacaoAtual['documentosExtras'] = [];
                    }

                    // Extrair índice do documento extra (ex: documentoExtra_0, documentoExtra_1)
                    $indexExtra = str_replace('documentoExtra_', '', $tipoDocumento);

                    // Adicionar documento extra ao array
                    $documentacaoAtual['documentosExtras'][$indexExtra] = $nomeArquivo;

                    Log::info('Documento extra adicionado à documentação', [
                        'proposta_id' => $propostaId,
                        'index' => $indexExtra,
                        'arquivo' => $nomeArquivo
                    ]);
                } else {
                    // Para documentos gerais, salvar no nível raiz
                    $documentacaoAtual[$tipoDocumento] = $nomeArquivo;

                    // Também manter estrutura por UC se numeroUC foi fornecido
                    if ($numeroUC) {
                        if (!isset($documentacaoAtual[$numeroUC])) {
                            $documentacaoAtual[$numeroUC] = [];
                        }
                        $documentacaoAtual[$numeroUC][$tipoDocumento] = $nomeArquivo;
                    }

                    Log::info('Documento geral adicionado à documentação', [
                        'proposta_id' => $propostaId,
                        'numero_uc' => $numeroUC,
                        'tipo' => $tipoDocumento,
                        'arquivo' => $nomeArquivo
                    ]);
                }
            }

            // ✅ CONVERTER PARA JSON E ATUALIZAR
            $documentacaoJson = json_encode($documentacaoAtual, JSON_UNESCAPED_UNICODE);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Erro ao codificar documentação para JSON: ' . json_last_error_msg());
            }

            $result = DB::update(
                "UPDATE propostas SET documentacao = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                [$documentacaoJson, $propostaId]
            );

            if ($result !== 1) {
                throw new \Exception('Falha ao atualizar registro no banco de dados');
            }

            Log::info('Documentação atualizada com sucesso no banco', [
                'proposta_id' => $propostaId,
                'total_documentos' => count($documentacaoAtual),
                'faturas_ucs' => count($documentacaoAtual['faturas_ucs'] ?? []),
                'documentacao_tamanho' => strlen($documentacaoJson)
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Erro ao atualizar documentação da proposta', [
                'proposta_id' => $propostaId,
                'numero_uc' => $numeroUC,
                'tipo_documento' => $tipoDocumento,
                'nome_arquivo' => $nomeArquivo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return false;
        }
    }

    /**
     * ✅ MÉTODO DE DEBUG: Verificar documentação da proposta
     */
    public function debugDocumentacao($id)
    {
        try {
            $proposta = DB::selectOne(
                "SELECT id, numero_proposta, documentacao, created_at, updated_at FROM propostas WHERE id = ? AND deleted_at IS NULL", 
                [$id]
            );
            
            if (!$proposta) {
                return response()->json(['error' => 'Proposta não encontrada'], 404);
            }

            $documentacao = json_decode($proposta->documentacao ?? '{}', true);
            
            return response()->json([
                'proposta' => [
                    'id' => $proposta->id,
                    'numero_proposta' => $proposta->numero_proposta,
                    'created_at' => $proposta->created_at,
                    'updated_at' => $proposta->updated_at
                ],
                'documentacao' => $documentacao,
                'documentacao_raw' => $proposta->documentacao,
                'json_valido' => json_last_error() === JSON_ERROR_NONE,
                'json_error' => json_last_error_msg(),
                'estrutura' => [
                    'tem_faturas_ucs' => isset($documentacao['faturas_ucs']),
                    'total_faturas' => count($documentacao['faturas_ucs'] ?? []),
                    'chaves_principais' => array_keys($documentacao)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * ✅ NOVO MÉTODO: Atualizar documentação completa da proposta (usado pelo frontend)
     */
    public function atualizarDocumentacao(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'documentacao' => 'required|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados de documentação inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar se a proposta existe
            $proposta = DB::selectOne("SELECT * FROM propostas WHERE id = ? AND deleted_at IS NULL", [$id]);
            if (!$proposta) {
                return response()->json(['success' => false, 'message' => 'Proposta não encontrada'], 404);
            }

            // ✅ MESCLAR documentação existente com nova
            $documentacaoAtual = json_decode($proposta->documentacao ?? '{}', true);
            $documentacaoNova = $request->documentacao;
            
            // Mesclar preservando dados existentes
            $documentacaoFinal = array_merge($documentacaoAtual, $documentacaoNova);
            
            // Atualizar documentação
            DB::update(
                "UPDATE propostas SET documentacao = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                [json_encode($documentacaoFinal), $id]
            );

            Log::info('Documentação completa atualizada', [
                'proposta_id' => $id,
                'numero_proposta' => $proposta->numero_proposta,
                'documentacao_anterior' => array_keys($documentacaoAtual),
                'documentacao_nova' => array_keys($documentacaoNova),
                'documentacao_final' => array_keys($documentacaoFinal),
                'usuario' => auth()->user()->nome ?? 'Sistema'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Documentação atualizada com sucesso',
                'documentacao' => $documentacaoFinal
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao atualizar documentação completa', [
                'proposta_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar documentação: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ NOVO MÉTODO: Remover arquivo específico da proposta
     */
    public function removerArquivo(Request $request, $id, $tipo, $numeroUC = null)
    {
        try {
            // Verificar se a proposta existe
            $proposta = DB::selectOne("SELECT numero_proposta, documentacao FROM propostas WHERE id = ? AND deleted_at IS NULL", [$id]);
            
            if (!$proposta) {
                return response()->json(['success' => false, 'message' => 'Proposta não encontrada'], 404);
            }

            // Decodificar documentação atual
            $documentacao = json_decode($proposta->documentacao ?? '{}', true);
            
            $arquivoRemovido = null;
            $caminhoArquivo = null;

            // ✅ REMOVER FATURA DE UC ESPECÍFICA
            if ($tipo === 'faturaUC') {
                if (!$numeroUC) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Número da UC é obrigatório para remover fatura'
                    ], 400);
                }

                if (isset($documentacao['faturas_ucs'][$numeroUC])) {
                    $arquivoRemovido = $documentacao['faturas_ucs'][$numeroUC];
                    $caminhoArquivo = "public/propostas/faturas/{$arquivoRemovido}";
                    
                    // Remover da documentação
                    unset($documentacao['faturas_ucs'][$numeroUC]);
                    
                    // Se não há mais faturas, remover a chave completamente
                    if (empty($documentacao['faturas_ucs'])) {
                        unset($documentacao['faturas_ucs']);
                        unset($documentacao['data_upload_faturas']);
                    } else {
                        // Atualizar timestamp
                        $documentacao['data_upload_faturas'] = date('Y-m-d H:i:s');
                    }
                    
                    Log::info('Fatura de UC removida da documentação', [
                        'proposta_id' => $id,
                        'numero_uc' => $numeroUC,
                        'arquivo_removido' => $arquivoRemovido
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => "Fatura da UC {$numeroUC} não encontrada"
                    ], 404);
                }
            } 
            // ✅ REMOVER DOCUMENTO GERAL
            else {
                $tiposPermitidos = [
                    'documentoPessoal',
                    'contratoSocial', 
                    'documentoPessoalRepresentante',
                    'contratoLocacao',
                    'termoAdesao'
                ];

                if (!in_array($tipo, $tiposPermitidos)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tipo de documento inválido'
                    ], 400);
                }

                if (isset($documentacao[$tipo])) {
                    $arquivoRemovido = $documentacao[$tipo];
                    $caminhoArquivo = "public/propostas/documentos/{$arquivoRemovido}";
                    
                    // Remover da documentação
                    unset($documentacao[$tipo]);
                    
                    Log::info('Documento geral removido da documentação', [
                        'proposta_id' => $id,
                        'tipo_documento' => $tipo,
                        'arquivo_removido' => $arquivoRemovido
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => "Documento do tipo {$tipo} não encontrado"
                    ], 404);
                }
            }

            // ✅ ATUALIZAR DOCUMENTAÇÃO NO BANCO
            DB::update(
                "UPDATE propostas SET documentacao = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                [json_encode($documentacao), $id]
            );

            // ✅ REMOVER ARQUIVO FÍSICO DO Storage

            if ($caminhoArquivo && Storage::disk('public')->exists($caminhoArquivo)) {
                $arquivoDeletado = Storage::disk('public')->delete($caminhoArquivo);
                
                Log::info('Arquivo físico removido do Storage', [
                        'caminho' => $caminhoArquivo,
                        'disco' => 'public',
                        'sucesso' => $arquivoDeletado
                    ]);
                } else {
                    Log::warning('Arquivo físico não encontrado no Storage público', [
                        'caminho' => $caminhoArquivo ?? 'N/A',
                        'disco' => 'public'
                    ]);
                }

            return response()->json([
                'success' => true,
                'message' => $tipo === 'faturaUC' 
                    ? "Fatura da UC {$numeroUC} removida com sucesso"
                    : "Documento {$tipo} removido com sucesso",
                'arquivo_removido' => $arquivoRemovido,
                'documentacao_atualizada' => $documentacao
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao remover arquivo da proposta', [
                'proposta_id' => $id,
                'tipo' => $tipo,
                'numero_uc' => $numeroUC,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao remover arquivo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ MÉTODO AUXILIAR: Listar diretórios de arquivos da proposta
     */
    public function listarDiretoriosArquivos($id)
    {
        try {
            $proposta = DB::selectOne("SELECT numero_proposta FROM propostas WHERE id = ? AND deleted_at IS NULL", [$id]);
            
            if (!$proposta) {
                return response()->json(['success' => false, 'message' => 'Proposta não encontrada'], 404);
            }

            $diretorios = [
                'documentos' => Storage_path('app/public/propostas/documentos'),
                'faturas' => Storage_path('app/public/propostas/faturas')
            ];

            $info = [];
            foreach ($diretorios as $tipo => $caminho) {
                $arquivos = [];
                
                if (is_dir($caminho)) {
                    $pattern = "*{$proposta->numero_proposta}*";
                    $arquivosEncontrados = glob($caminho . '/' . str_replace('/', '', $pattern));
                    
                    foreach ($arquivosEncontrados as $arquivo) {
                        $arquivos[] = [
                            'nome' => basename($arquivo),
                            'tamanho' => filesize($arquivo),
                            'data_modificacao' => date('Y-m-d H:i:s', filemtime($arquivo))
                        ];
                    }
                }

                $info[$tipo] = [
                    'diretorio' => $caminho,
                    'existe' => is_dir($caminho),
                    'arquivos' => $arquivos,
                    'total' => count($arquivos)
                ];
            }

            return response()->json([
                'success' => true,
                'proposta' => $proposta->numero_proposta,
                'diretorios' => $info
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar diretórios de arquivos', [
                'proposta_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar diretórios: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ NOVO MÉTODO: Listar arquivos de uma proposta
     */
    public function listarArquivos($id)
    {
        try {
            $proposta = DB::selectOne("SELECT numero_proposta, documentacao FROM propostas WHERE id = ? AND deleted_at IS NULL", [$id]);
            
            if (!$proposta) {
                return response()->json(['success' => false, 'message' => 'Proposta não encontrada'], 404);
            }

            $documentacao = json_decode($proposta->documentacao ?? '{}', true);
            
            $arquivos = [];
            
            // Faturas das UCs
            if (isset($documentacao['faturas_ucs']) && is_array($documentacao['faturas_ucs'])) {
                foreach ($documentacao['faturas_ucs'] as $numeroUC => $nomeArquivo) {
                    $arquivos[] = [
                        'tipo' => 'faturaUC',
                        'numero_uc' => $numeroUC,
                        'nome_arquivo' => $nomeArquivo,
                        'url' => asset("storage/propostas/faturas/{$nomeArquivo}"),
                        'descricao' => "Fatura da UC {$numeroUC}"
                    ];
                }
            }
            
            // Documentos gerais
            $tiposDocumentos = [
                'documentoPessoal' => 'Documento Pessoal',
                'contratoSocial' => 'Contrato Social',
                'documentoPessoalRepresentante' => 'Documento do Representante',
                'contratoLocacao' => 'Contrato de Locação',
                'termoAdesao' => 'Termo de Adesão'
            ];

            foreach ($tiposDocumentos as $tipo => $descricao) {
                if (isset($documentacao[$tipo]) && !empty($documentacao[$tipo])) {
                    $arquivos[] = [
                        'tipo' => $tipo,
                        'numero_uc' => null,
                        'nome_arquivo' => $documentacao[$tipo],
                        'url' => asset("storage/propostas/documentos/{$documentacao[$tipo]}"),
                        'descricao' => $descricao
                    ];
                }
            }

            // ✅ DOCUMENTOS EXTRAS (dinâmicos)
            if (isset($documentacao['documentosExtras']) && is_array($documentacao['documentosExtras'])) {
                foreach ($documentacao['documentosExtras'] as $index => $docExtra) {
                    if (!empty($docExtra)) {
                        $arquivos[] = [
                            'tipo' => 'documentoExtra',
                            'numero_uc' => null,
                            'nome_arquivo' => $docExtra,
                            'url' => asset("storage/propostas/documentos/{$docExtra}"),
                            'descricao' => "Documento Extra " . ($index + 1)
                        ];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'proposta' => $proposta->numero_proposta,
                'arquivos' => $arquivos,
                'total' => count($arquivos)
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar arquivos da proposta', [
                'proposta_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar arquivos: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * ✅ EXCLUIR PROPOSTA (SOFT DELETE)
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

            // Obter dados da proposta antes de excluí-la para auditoria
            $propostaParaAuditoria = DB::selectOne("SELECT numero_proposta, nome_cliente, consultor_id FROM propostas WHERE id = ? AND deleted_at IS NULL", [$id]);

            Log::info('Excluindo proposta', [
                'proposta_id' => $id,
                'user_id' => $currentUser->id,
                'numero_proposta' => $propostaParaAuditoria->numero_proposta ?? null
            ]);

            $query = "UPDATE propostas p SET deleted_at = NOW(), updated_at = NOW() WHERE p.id = ? AND p.deleted_at IS NULL";
            $params = [$id];

            // Se não for admin, verificar se é proposta do usuário
            if (!$currentUser->isAdminOrAnalista()) {
                if ($currentUser->isConsultor()) {
                    // Consultor vê suas propostas + propostas dos subordinados + propostas com seu nome
                    $subordinados = $currentUser->getAllSubordinates();
                    $subordinadosIds = array_column($subordinados, 'id');
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinadosIds);
                    
                    $placeholders = str_repeat('?,', count($usuariosPermitidos) - 1) . '?';
                    $query .= " AND (p.usuario_id IN ({$placeholders}) OR p.consultor_id = ?)";
                    $params = array_merge($params, $usuariosPermitidos);
                    $params[] = $currentUser->id;
                                        
                } elseif ($currentUser->isGerente()) {
                    // Gerente vê apenas suas propostas + propostas dos vendedores subordinados
                    $subordinados = $currentUser->getAllSubordinates();
                    $subordinadosIds = array_column($subordinados, 'id');
                    $usuariosPermitidos = array_merge([$currentUser->id], $subordinadosIds);
                    
                    if (!empty($usuariosPermitidos)) {
                        $placeholders = str_repeat('?,', count($usuariosPermitidos) - 1) . '?';
                        $query .= " AND usuario_id IN ({$placeholders})";
                        $params = array_merge($params, $usuariosPermitidos);
                    } else {
                        $query .= " AND p.usuario_id = ?";
                        $params[] = $currentUser->id;
                    }
                    
                } else {
                    // Vendedor vê apenas suas propostas
                    $query .= " AND p.usuario_id = ?";
                    $params[] = $currentUser->id;
                }
            }

            $result = DB::update($query, $params);

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proposta não encontrada ou sem permissão'
                ], 404);
            }

            Log::info('Proposta excluída com sucesso', [
                'proposta_id' => $id,
                'user_id' => $currentUser->id
            ]);

            // Registrar evento de auditoria para exclusão de proposta
            if ($propostaParaAuditoria) {
                AuditoriaService::registrarExclusaoProposta($id, [
                    'numero' => $propostaParaAuditoria->numero_proposta,
                    'nome_cliente' => $propostaParaAuditoria->nome_cliente,
                    'consultor_id' => $propostaParaAuditoria->consultor_id
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Proposta excluída com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao excluir proposta', [
                'proposta_id' => $id,
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
     * ✅ VERIFICAR SE NÚMERO DA PROPOSTA ESTÁ DISPONÍVEL
     */

    public function verificarNumero(string $numero): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Verificar se número já existe
            $existe = DB::selectOne(
                "SELECT COUNT(*) as total FROM propostas WHERE numero_proposta = ? AND deleted_at IS NULL",
                [$numero]
            );

            $disponivel = ($existe->total ?? 0) === 0;

            Log::info('Verificação de número de proposta', [
                'numero' => $numero,
                'existe' => !$disponivel,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'numero_proposta' => $numero,
                'disponivel' => $disponivel,
                'message' => $disponivel 
                    ? 'Número disponível' 
                    : 'Número já existe'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao verificar número da proposta', [
                'numero' => $numero,
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
     * ✅ GERAR NÚMERO DA PROPOSTA
     */
    private function gerarNumeroProposta(): string
    {
        $ano = date('Y');
        
        // Buscar o próximo número disponível em uma única query
        $resultado = DB::selectOne("
            SELECT COALESCE(MAX(
                CAST(SUBSTRING(numero_proposta FROM POSITION('/' IN numero_proposta) + 1) AS INTEGER)
            ), 0) + 1 as proximo_numero
            FROM propostas 
            WHERE EXTRACT(YEAR FROM data_proposta) = ? 
            AND deleted_at IS NULL
        ", [$ano]);
        
        $proximoNumero = $resultado->proximo_numero ?? 1;
        
        return $ano . '/' . str_pad($proximoNumero, 3, '0', STR_PAD_LEFT);
    }

    /**
     * ✅ Formatar desconto para salvar no banco com %
     */
    private function formatarDesconto($valor): string
    {
        if (is_string($valor) && str_ends_with($valor, '%')) {
            // Já vem com %, só validar
            return $valor;
        }
        
        // Converter número para porcentagem
        $numero = floatval($valor);
        return $numero . '%';
    }

    private function extrairValorDesconto($desconto)
    {
        if (empty($desconto)) return 0;
        
        // Se já for número, retorna
        if (is_numeric($desconto)) {
            return floatval($desconto);
        }
        
        // Se for string, extrai o número
        $numeroStr = preg_replace('/[^0-9.,]/', '', $desconto);
        $numeroStr = str_replace(',', '.', $numeroStr);
        
        return floatval($numeroStr) ?: 0;
    }

    /**
     * ✅ Extrair valor numérico do desconto para cálculos
     */
    private function obterStatusProposta(array $unidadesConsumidoras): string
    {
        if (empty($unidadesConsumidoras)) {
            return 'Aguardando';
        }
        
        $statusUCs = array_column($unidadesConsumidoras, 'status');
        $statusUCs = array_map(fn($s) => $s ?? 'Aguardando', $statusUCs);
        
        // Se todas são iguais, retorna o status comum
        if (count(array_unique($statusUCs)) === 1) {
            return $statusUCs[0];
        }
        
        // Se tem status mistos, priorizar:
        if (in_array('Fechada', $statusUCs)) {
            return count(array_filter($statusUCs, fn($s) => $s === 'Fechada')) === count($statusUCs) ? 'Fechada' : 'Em Andamento';
        }
        
        if (in_array('Recusada', $statusUCs)) {
            return 'Recusada';
        }
        
        if (in_array('Cancelada', $statusUCs)) {
            return 'Cancelada';
        }
        
        return 'Aguardando';
    }

    /**
     * ✅ REMOVER CONTROLE QUANDO STATUS SAI DE "FECHADA"
     */
    private function removerDoControle($proposta_id, $numero_uc, $status_anterior, $status_novo)
    {
        try {
            Log::info('Iniciando remoção do controle', [
                'proposta_id' => $proposta_id,
                'numero_uc' => $numero_uc,
                'status_anterior' => $status_anterior,
                'status_novo' => $status_novo
            ]);

            // ✅ BUSCAR A UC DIRETAMENTE NA TABELA unidades_consumidoras
            $ucEncontrada = DB::selectOne("
                SELECT id FROM unidades_consumidoras 
                WHERE numero_unidade = ? AND proposta_id = ? AND deleted_at IS NULL
            ", [$numero_uc, $proposta_id]);

            if (!$ucEncontrada) {
                Log::warning('UC não encontrada na tabela unidades_consumidoras', [
                    'proposta_id' => $proposta_id,
                    'numero_uc' => $numero_uc
                ]);
                return false;
            }

            $ucIdParaRemover = $ucEncontrada->id;

            Log::info('UC encontrada na tabela', [
                'uc_id' => $ucIdParaRemover,
                'numero_uc' => $numero_uc,
                'proposta_id' => $proposta_id
            ]);

            // ✅ VERIFICAR SE EXISTE CONTROLE PARA ESTA PROPOSTA E UC
            $controleExistente = DB::selectOne("
                SELECT id FROM controle_clube 
                WHERE proposta_id = ? AND uc_id = ? AND deleted_at IS NULL
            ", [$proposta_id, $ucIdParaRemover]);

            if ($controleExistente) {
                // ✅ FAZER SOFT DELETE DO CONTROLE
                $resultado = DB::update("
                    UPDATE controle_clube 
                    SET deleted_at = NOW(), updated_at = NOW() 
                    WHERE id = ?
                ", [$controleExistente->id]);

                if ($resultado > 0) {
                    AuditoriaService::registrarRemocaoControle(
                        $proposta_id,
                        $ucIdParaRemover, 
                        $controleExistente->id,
                        $status_anterior,
                        $status_novo
                    );

                    Log::info('✅ Controle removido com sucesso (soft delete)', [
                        'controle_id' => $controleExistente->id,
                        'proposta_id' => $proposta_id,
                        'uc_id' => $ucIdParaRemover,
                        'numero_uc' => $numero_uc,
                        'status_anterior' => $status_anterior,
                        'status_novo' => $status_novo
                    ]);
                    return true;
                } else {
                    Log::warning('Falha ao executar soft delete', [
                        'controle_id' => $controleExistente->id
                    ]);
                    return false;
                }
            } else {
                Log::info('Nenhum controle encontrado para remover', [
                    'proposta_id' => $proposta_id,
                    'uc_id' => $ucIdParaRemover,
                    'numero_uc' => $numero_uc
                ]);
                return true; // Não é erro, apenas não havia controle para remover
            }

        } catch (\Exception $e) {
            Log::error('Erro ao remover controle', [
                'proposta_id' => $proposta_id,
                'numero_uc' => $numero_uc,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            ]);
            return false;
        }
    }

    private function extrairDocumentacaoUC($documentacao, $numeroUC)
    {
        if (empty($documentacao) || empty($numeroUC)) {
            return [];
        }
        
        $docs = is_string($documentacao) ? json_decode($documentacao, true) : $documentacao;
        
        return $docs[$numeroUC] ?? [];
    }

    /**
     * Validar campos obrigatórios para fechamento
     */
    private function validarCamposObrigatoriosParaFechamento($dadosProposta, $documentacao)
    {
        $erros = [];

        // Campos básicos
        if (empty($dadosProposta['nomeCliente'])) $erros[] = 'Nome do Cliente';
        if (empty($dadosProposta['apelido'])) $erros[] = 'Apelido UC';
        if (empty($dadosProposta['numeroUC'])) $erros[] = 'Número UC';

        // Documentação
        if (empty($documentacao['enderecoUC'])) $erros[] = 'Endereço da UC';
        // ✅ ENDEREÇO DO REPRESENTANTE NÃO É MAIS OBRIGATÓRIO
        // if (empty($documentacao['enderecoRepresentante'])) $erros[] = 'Endereço do Representante';
        if (empty($documentacao['nomeRepresentante'])) $erros[] = 'Nome do Representante';
        
        // Tipo de documento específico
        if (($documentacao['tipoDocumento'] ?? '') === 'CPF') {
            if (empty($documentacao['cpf'])) $erros[] = 'CPF';
        } else {
            if (empty($documentacao['cnpj'])) $erros[] = 'CNPJ';
            if (empty($documentacao['razaoSocial'])) $erros[] = 'Razão Social';
        }
        
        return $erros;
    }
    public function atualizarArquivoDocumentacao($propostaId, $numeroUC, $tipoArquivo, $nomeArquivo, $acao = 'salvar')
    {
        try {
            // Buscar documentação atual
            $proposta = DB::selectOne("SELECT documentacao FROM propostas WHERE id = ?", [$propostaId]);
            
            $documentacao = [];
            if ($proposta && $proposta->documentacao) {
                $documentacao = json_decode($proposta->documentacao, true) ?? [];
            }
            
            // Inicializar estrutura da UC se não existir
            if (!isset($documentacao[$numeroUC])) {
                $documentacao[$numeroUC] = [];
            }
            
            if (!isset($documentacao[$numeroUC]['arquivos'])) {
                $documentacao[$numeroUC]['arquivos'] = [];
            }
            
            if ($acao === 'salvar') {
                // Salvar arquivo
                $documentacao[$numeroUC]['arquivos'][$tipoArquivo] = $nomeArquivo;
                $documentacao[$numeroUC]['arquivos']['data_' . $tipoArquivo] = now()->format('Y-m-d H:i:s');
                
            } elseif ($acao === 'remover') {
                // Remover arquivo
                unset($documentacao[$numeroUC]['arquivos'][$tipoArquivo]);
                unset($documentacao[$numeroUC]['arquivos']['data_' . $tipoArquivo]);
                
                // Se não tem mais arquivos, limpar estrutura
                if (empty($documentacao[$numeroUC]['arquivos'])) {
                    unset($documentacao[$numeroUC]['arquivos']);
                }
            }
            
            // ✅ MARCAR COMO ALTERAÇÃO DE DOCUMENTAÇÃO ESPECÍFICA
            // Definir flag temporária para evitar log genérico
            if (!session()->has('skip_proposta_log')) {
                session()->put('skip_proposta_log', true);
                session()->put('alteracao_documentacao_apenas', true);
            }

            // Atualizar no banco
            DB::update(
                "UPDATE propostas SET documentacao = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                [json_encode($documentacao, JSON_UNESCAPED_UNICODE), $propostaId]
            );
            
            Log::info('Arquivo atualizado na documentação JSON', [
                'proposta_id' => $propostaId,
                'numero_uc' => $numeroUC,
                'tipo_arquivo' => $tipoArquivo,
                'nome_arquivo' => $nomeArquivo,
                'acao' => $acao
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar arquivo na documentação', [
                'proposta_id' => $propostaId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function buscarArquivoDocumentacao($propostaId, $numeroUC, $tipoArquivo)
    {
        try {
            $proposta = DB::selectOne("SELECT documentacao FROM propostas WHERE id = ?", [$propostaId]);
            
            if (!$proposta || !$proposta->documentacao) {
                return null;
            }
            
            $documentacao = json_decode($proposta->documentacao, true);
            
            return $documentacao[$numeroUC]['arquivos'][$tipoArquivo] ?? null;
            
        } catch (\Exception $e) {
            Log::error('Erro ao buscar arquivo na documentação', [
                'proposta_id' => $propostaId,
                'error' => $e->getMessage()
            ]);
            return null;
        }

        
    }  
}