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

            if ($currentUser->role !== 'admin') {
                $query .= " AND p.usuario_id = ?";
                $params[] = $currentUser->id;
            }
            $query .= " ORDER BY p.created_at DESC";
            
            $propostas = DB::select($query, $params);

            // ✅ MAPEAR DADOS DO BACKEND PARA O FORMATO DO FRONTEND
            $propostasMapeadas = [];
            
            foreach ($propostas as $proposta) {
                $unidadesConsumidoras = json_decode($proposta->unidades_consumidoras ?? '[]', true);
                $beneficios = json_decode($proposta->beneficios ?? '[]', true);
                
                // Pegar dados da primeira UC para compatibilidade
                $primeiraUC = !empty($unidadesConsumidoras) ? $unidadesConsumidoras[0] : null;
                
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
                    // Dados da UC (para compatibilidade com frontend)
                    'apelido' => $primeiraUC['apelido'] ?? '',
                    'numeroUC' => $primeiraUC['numero_unidade'] ?? $primeiraUC['numeroUC'] ?? '',
                    'numeroCliente' => $primeiraUC['numero_cliente'] ?? $primeiraUC['numeroCliente'] ?? '',
                    'ligacao' => $primeiraUC['ligacao'] ?? $primeiraUC['tipo_ligacao'] ?? '',
                    'media' => $primeiraUC['consumo_medio'] ?? $primeiraUC['media'] ?? 0,
                    'distribuidora' => $primeiraUC['distribuidora'] ?? '',
                    
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

            // ✅ EXPANDIR PROPOSTAS PARA UCs (uma linha por UC)
            $linhasExpandidas = [];

            foreach ($propostasMapeadas as $proposta) {
                $unidadesConsumidoras = $proposta['unidades_consumidoras'];
                
                if (empty($unidadesConsumidoras)) {
                    // Se não tem UCs, criar uma linha padrão
                    $linhasExpandidas[] = $proposta;
                } else {
                    // Para cada UC, criar uma linha separada
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
                            
                            // Dados específicos desta UC
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

            Log::info('Propostas expandidas', [
                'propostas_originais' => count($propostasMapeadas),
                'linhas_expandidas' => count($linhasExpandidas),
                'user_id' => $currentUser->id
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
            
            // ✅ VERSÃO POSTGRESQL - Usar jsonb_array_elements()
            $propostasAtivas = DB::select("
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
            
            if (!empty($propostasAtivas)) {
                foreach ($propostasAtivas as $proposta) {
                    $ucsComPropostaAtiva[] = [
                        'numero_uc' => $numeroUC,
                        'apelido' => $uc['apelido'] ?? "UC {$numeroUC}",
                        'proposta_numero' => $proposta->numero_proposta,
                        'proposta_cliente' => $proposta->nome_cliente,
                        'status_atual' => $proposta->status_uc ?? 'Aguardando'
                    ];
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
            
            // ✅ VERSÃO POSTGRESQL - EXCLUIR PROPOSTA ATUAL
            $propostasAtivas = DB::select("
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
            
            if (!empty($propostasAtivas)) {
                foreach ($propostasAtivas as $proposta) {
                    $ucsComPropostaAtiva[] = [
                        'numero_uc' => $numeroUC,
                        'apelido' => $uc['apelido'] ?? "UC {$numeroUC}",
                        'proposta_numero' => $proposta->numero_proposta,
                        'proposta_cliente' => $proposta->nome_cliente,
                        'status_atual' => $proposta->status_uc ?? 'Aguardando'
                    ];
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

            // ✅ VERIFICAR SE A UC TEM PROPOSTAS ATIVAS (PostgreSQL)
            $propostasAtivas = DB::select("
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

            $disponivel = empty($propostasAtivas);

            return response()->json([
                'success' => true,
                'disponivel' => $disponivel,
                'numero_uc' => $numero,
                'propostas_ativas' => array_map(function($proposta) {
                    return [
                        'numero_proposta' => $proposta->numero_proposta,
                        'nome_cliente' => $proposta->nome_cliente,
                        'status' => $proposta->status_uc ?? 'Aguardando'
                    ];
                }, $propostasAtivas)
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


            $consultorId = null;
            if ($request->has('consultor_id') && $request->consultor_id) {
                $consultorId = $request->consultor_id;
            } elseif ($request->has('consultor') && $request->consultor) {
                // Buscar por nome se não vier ID
                $consultorEncontrado = DB::selectOne("
                    SELECT id FROM usuarios 
                    WHERE nome = ? AND role IN ('admin', 'consultor', 'gerente', 'vendedor')
                    AND deleted_at IS NULL
                ", [$request->consultor]);
                
                if ($consultorEncontrado) {
                    $consultorId = $consultorEncontrado->id;
                }
            }
            DB::beginTransaction();

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
                $request->recorrencia ?? '3%',
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
            $primeiraUC = !empty($unidadesConsumidoras) ? $unidadesConsumidoras[0] : null;

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
                'apelido' => $primeiraUC['apelido'] ?? '',
                'numeroUC' => $primeiraUC['numero_unidade'] ?? $primeiraUC['numeroUC'] ?? '',
                'numeroCliente' => $primeiraUC['numero_cliente'] ?? $primeiraUC['numeroCliente'] ?? '',
                'ligacao' => $primeiraUC['ligacao'] ?? $primeiraUC['tipo_ligacao'] ?? '',
                'media' => $primeiraUC['consumo_medio'] ?? $primeiraUC['media'] ?? 0,
                'distribuidora' => $primeiraUC['distribuidora'] ?? '',
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

            $query = "SELECT p.*, u.nome as consultor_nome FROM propostas p LEFT JOIN usuarios u ON p.consultor_id = u.id WHERE p.id = ? AND p.deleted_at IS NULL";
            $params = [$id];

            // Se não for admin, verificar se é proposta do usuário
            if ($currentUser->role !== 'admin') {
                $query .= " AND p.usuario_id = ?";
                $params[] = $currentUser->id;
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
            $primeiraUC = !empty($unidadesConsumidoras) ? $unidadesConsumidoras[0] : null;

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
                // Dados da UC (para compatibilidade com frontend)
                'apelido' => $primeiraUC['apelido'] ?? '',
                'numeroUC' => $primeiraUC['numero_unidade'] ?? $primeiraUC['numeroUC'] ?? '',
                'numeroCliente' => $primeiraUC['numero_cliente'] ?? $primeiraUC['numeroCliente'] ?? '', // ← ADICIONEI ESTA LINHA
                'ligacao' => $primeiraUC['ligacao'] ?? $primeiraUC['tipo_ligacao'] ?? '',
                'media' => $primeiraUC['consumo_medio'] ?? $primeiraUC['media'] ?? 0,
                'distribuidora' => $primeiraUC['distribuidora'] ?? '',
                
                // ✅ ARRAYS COMPLETOS - NOMES CORRETOS
                'beneficios' => $beneficios,
                'unidades_consumidoras' => $unidadesConsumidoras,
                'unidadesConsumidoras' => $unidadesConsumidoras, // ← ADICIONEI ESTA LINHA (compatibilidade)
                
                // Timestamps
                'created_at' => $proposta->created_at,
                'updated_at' => $proposta->updated_at
            ];

            // ✅ DEBUG: Log da proposta mapeada
            Log::info('Proposta mapeada para frontend', [
                'id' => $proposta->id,
                'numero_proposta' => $proposta->numero_proposta,
                'ucs_count' => count($unidadesConsumidoras),
                'ucs_sample' => array_slice($unidadesConsumidoras, 0, 1) // Primeira UC para debug
            ]);

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

            if ($currentUser->role !== 'admin') {
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
            $camposGerais = ['nome_cliente', 'data_proposta', 'observacoes', 'recorrencia', 'inflacao', 'tarifa_tributos'];
            foreach ($camposGerais as $campo) {
                if ($request->has($campo)) {
                    $updateFields[] = "{$campo} = ?";
                    $updateParams[] = $request->get($campo);
                }
            }

            // ✅ ADICIONAR ESTA SEÇÃO - Tratar campo 'consultor' (nome) enviado pelo frontend
            if ($request->has('consultor') && $request->consultor) {
                // Buscar ID do consultor pelo nome
                $consultorEncontrado = DB::selectOne("
                    SELECT id FROM usuarios 
                    WHERE nome = ? AND role IN ('admin', 'consultor', 'gerente', 'vendedor')
                    AND deleted_at IS NULL
                ", [$request->consultor]);
                
                if ($consultorEncontrado) {
                    $updateFields[] = 'consultor_id = ?';
                    $updateParams[] = $consultorEncontrado->id;
                    
                    Log::info('✅ Consultor encontrado e será atualizado', [
                        'nome_consultor' => $request->consultor,
                        'consultor_id' => $consultorEncontrado->id
                    ]);
                } else {
                    Log::warning('❌ Consultor não encontrado pelo nome', [
                        'nome_pesquisado' => $request->consultor
                    ]);
                }
            }

            // Tratar consultor_id direto (se vier)
            if ($request->has('consultor_id') && $request->consultor_id) {
                $consultorExiste = DB::selectOne("
                    SELECT id FROM usuarios 
                    WHERE id = ? AND role IN ('admin', 'consultor', 'gerente', 'vendedor')
                    AND deleted_at IS NULL
                ", [$request->consultor_id]);
                
                if ($consultorExiste) {
                    $updateFields[] = 'consultor_id = ?';
                    $updateParams[] = $request->consultor_id;
                    
                    Log::info('✅ Consultor_id direto será atualizado', [
                        'consultor_id' => $request->consultor_id
                    ]);
                }
            }

            // Tratar consultor_id direto (se vier)
            if ($request->has('consultor_id') && $request->consultor_id) {
                $consultorExiste = DB::selectOne("
                    SELECT id FROM usuarios 
                    WHERE id = ? AND role IN ('admin', 'consultor', 'gerente', 'vendedor')
                    AND deleted_at IS NULL
                ", [$request->consultor_id]);
                
                if ($consultorExiste) {
                    $updateFields[] = 'consultor_id = ?';
                    $updateParams[] = $request->consultor_id;
                    
                    Log::info('✅ Consultor_id direto será atualizado', [
                        'consultor_id' => $request->consultor_id
                    ]);
                }
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

            // 3️⃣ DOCUMENTAÇÃO DA UC (específica para a UC sendo editada)
            if ($numeroUC && $request->has('documentacao')) {
                $documentacaoAtual = json_decode($proposta->documentacao ?? '{}', true);
                $novaDocumentacao = $request->get('documentacao');
                
                // Se houver campos de arquivo, eles já devem ter os nomes dos arquivos salvos
                $documentacaoAtual[$numeroUC] = $novaDocumentacao;
                
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

            if ($request->has('status') && $request->status === 'Fechada') {
                Log::info('Status alterado para Fechada - populando controle', [
                    'proposta_id' => $id,
                    'status_anterior' => $proposta->status ?? 'desconhecido',
                    'status_novo' => $request->status,
                    'uc_especifica' => $numeroUC ?? null
                ]);
                
                // Se há numeroUC, significa que é uma atualização de UC específica
                if ($numeroUC) {
                    $this->popularControleAutomaticoParaUC($id, $numeroUC);
                } else {
                    // Se não há numeroUC, é uma atualização geral da proposta
                    $this->popularControleAutomatico($id);
                }
            }

            DB::commit();

            Log::info('Proposta atualizada com sucesso', [
                'proposta_id' => $id,
                'user_id' => $currentUser->id
            ]);

            // ✅ MAPEAR RESPOSTA PARA O FRONTEND
            $unidadesConsumidoras = json_decode($propostaAtualizada->unidades_consumidoras ?? '[]', true);
            $beneficios = json_decode($propostaAtualizada->beneficios ?? '[]', true);
            $primeiraUC = !empty($unidadesConsumidoras) ? $unidadesConsumidoras[0] : null;

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
                'apelido' => $primeiraUC['apelido'] ?? '',
                'numeroUC' => $primeiraUC['numero_unidade'] ?? $primeiraUC['numeroUC'] ?? '',
                'numeroCliente' => $primeiraUC['numero_cliente'] ?? $primeiraUC['numeroCliente'] ?? '',
                'ligacao' => $primeiraUC['ligacao'] ?? $primeiraUC['tipo_ligacao'] ?? '',
                'media' => $primeiraUC['consumo_medio'] ?? $primeiraUC['media'] ?? 0,
                'distribuidora' => $primeiraUC['distribuidora'] ?? '',
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

                // ✅ VERIFICAR SE UC JÁ EXISTE NA TABELA unidades_consumidoras
                $ucExistente = DB::selectOne(
                    "SELECT id FROM unidades_consumidoras WHERE numero_unidade = ?", 
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

                // ✅ VERIFICAR SE JÁ EXISTE CONTROLE
                $controleExistente = DB::selectOne(
                    "SELECT id FROM controle_clube WHERE proposta_id = ? AND uc_id = ?", 
                    [$proposta_id, $ucIdFinal]
                );

                if (!$controleExistente) {
                    // ✅ GERAR ULID PARA O CONTROLE
                    $controleId = \Illuminate\Support\Str::ulid()->toString();
                    
                    // ✅ CRIAR CONTROLE
                    DB::insert("
                        INSERT INTO controle_clube (
                            id, proposta_id, uc_id, calibragem, 
                            data_entrada_controle, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())
                    ", [
                        $controleId,
                        $proposta_id,
                        $ucIdFinal,  // ✅ ID da UC (ULID)
                        0.00
                    ]);

                    Log::info('Controle criado com sucesso', [
                        'controle_id' => $controleId,
                        'proposta_id' => $proposta_id,
                        'uc_id' => $ucIdFinal,
                        'numero_uc' => $numeroUC
                    ]);
                } else {
                    Log::info('Controle já existia', [
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
            $currentUser = JWTAuth::user();
            
            Log::info('Iniciando population do controle para UC específica', [
                'proposta_id' => $proposta_id,
                'numero_uc' => $numero_uc,
                'user_id' => $currentUser->id
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
                Log::warning('UC específica não encontrada', [
                    'proposta_id' => $proposta_id,
                    'numero_uc' => $numero_uc
                ]);
                return false;
            }

            Log::info('UC específica encontrada para processar', [
                'proposta_id' => $proposta_id,
                'numero_uc' => $numero_uc,
                'uc_data' => $ucEspecifica
            ]);

            // ✅ PROCESSAR APENAS ESTA UC
            $numeroUC = $ucEspecifica['numero_unidade'] ?? $ucEspecifica['numeroUC'] ?? $numero_uc;

            if (empty($numeroUC)) {
                Log::warning('Número da UC não encontrado', ['uc_data' => $ucEspecifica]);
                return false;
            }

            // ✅ BUSCAR OU CRIAR UC NO BANCO
            $ucBanco = DB::selectOne("
                SELECT id FROM unidades_consumidoras 
                WHERE numero_unidade = ? AND usuario_id = ? AND deleted_at IS NULL
            ", [$numeroUC, $currentUser->id]);

            $ucIdFinal = null;

            if ($ucBanco) {
                $ucIdFinal = $ucBanco->id;
                Log::info('UC já existe no banco', ['uc_id' => $ucIdFinal, 'numero_uc' => $numeroUC]);
            } else {
                // ✅ CRIAR UC NO BANCO
                $ucIdFinal = \Illuminate\Support\Str::ulid()->toString();
                
                DB::insert("
                    INSERT INTO unidades_consumidoras (
                        id, usuario_id, concessionaria_id, endereco_id, numero_unidade, 
                        apelido, consumo_medio, ligacao, distribuidora, proposta_id,
                        localizacao, gerador, grupo, desconto_fatura, desconto_bandeira,
                        created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ", [
                    $ucIdFinal,                                          // id (ULID)
                    $currentUser->id,                                    // usuario_id (usuário logado)
                    '01JB849ZDG0RPC5EB8ZFTB4GJN',                       // concessionaria_id (EQUATORIAL)
                    null,                                                // endereco_id (deixar em branco por enquanto)
                    $numeroUC,                                           // numero_unidade
                    $ucEspecifica['apelido'] ?? 'UC ' . $numeroUC,      // apelido
                    $ucEspecifica['consumo_medio'] ?? $ucEspecifica['media'] ?? 0, // consumo_medio
                    $ucEspecifica['ligacao'] ?? 'Monofásica',           // ligacao
                    $ucEspecifica['distribuidora'] ?? 'EQUATORIAL GO',   // distribuidora
                    $proposta_id,                                        // proposta_id
                    $ucEspecifica['endereco_uc'] ?? $ucEspecifica['localizacao'] ?? null, // localizacao
                    false,                                               // gerador (sempre false para UCs normais)
                    'B',                                                 // grupo
                    $this->extrairValorDesconto($proposta->desconto_tarifa), // desconto_fatura
                    $this->extrairValorDesconto($proposta->desconto_bandeira), // desconto_bandeira
                ]);

                Log::info('UC criada no banco para UC específica', ['uc_id' => $ucIdFinal, 'numero_uc' => $numeroUC]);
            }

            // ✅ VERIFICAR SE JÁ EXISTE CONTROLE PARA ESTA UC
            $controleExistente = DB::selectOne("
                SELECT id FROM controle_clube 
                WHERE proposta_id = ? AND uc_id = ?", 
                [$proposta_id, $ucIdFinal]
            );

            if (!$controleExistente) {
                // ✅ GERAR ULID PARA O CONTROLE
                $controleId = \Illuminate\Support\Str::ulid()->toString();
                
                // ✅ CRIAR CONTROLE
                DB::insert("
                    INSERT INTO controle_clube (
                        id, proposta_id, uc_id, calibragem, 
                        data_entrada_controle, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())
                ", [
                    $controleId,
                    $proposta_id,
                    $ucIdFinal,
                    0.00
                ]);

                Log::info('Controle criado com sucesso para UC específica', [
                    'controle_id' => $controleId,
                    'proposta_id' => $proposta_id,
                    'uc_id' => $ucIdFinal,
                    'numero_uc' => $numeroUC
                ]);
            } else {
                Log::info('Controle já existia para esta UC', [
                    'controle_existente_id' => $controleExistente->id,
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
    public function uploadDocumento(Request $request, string $id): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
            }

            $validator = Validator::make($request->all(), [
                'arquivo' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx',
                'numeroUC' => 'required|string',
                'tipoDocumento' => 'required|string'
            ]);

            if ($request->has('inflacao')) {
                $validatorInflacao = Validator::make($request->all(), [
                    'inflacao' => 'numeric|min:0|max:100'
                ]);
                if ($validatorInflacao->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Inflação deve ser um número entre 0 e 100',
                        'errors' => $validatorInflacao->errors()
                    ], 422);
                }
            }

            if ($request->has('tarifa_tributos')) {
                $validatorTarifa = Validator::make($request->all(), [
                    'tarifa_tributos' => 'numeric|min:0'
                ]);
                if ($validatorTarifa->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tarifa com tributos deve ser um número positivo',
                        'errors' => $validatorTarifa->errors()
                    ], 422);
                }
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Arquivo inválido',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Buscar proposta
            $proposta = DB::selectOne("SELECT * FROM propostas WHERE id = ? AND deleted_at IS NULL", [$id]);
            if (!$proposta) {
                return response()->json(['success' => false, 'message' => 'Proposta não encontrada'], 404);
            }

            $arquivo = $request->file('arquivo');
            $numeroUC = $request->numeroUC;
            $tipoDocumento = $request->tipoDocumento;
            
            // Gerar nome único
            $ano = date('Y');
            $mes = date('m');
            $timestamp = time();
            $extensao = $arquivo->getClientOriginalExtension();
            $numeroProposta = str_replace('/', '', $proposta->numero_proposta);
            
            $nomeArquivo = "{$ano}_{$mes}_{$numeroProposta}_{$numeroUC}_{$tipoDocumento}_{$timestamp}.{$extensao}";
            
            // Salvar arquivo
            $caminhoArquivo = $arquivo->storeAs('public/propostas/documentos', $nomeArquivo);

            if (!$caminhoArquivo) {
                throw new \Exception('Erro ao salvar arquivo');
            }

            return response()->json([
                'success' => true,
                'nomeArquivo' => $nomeArquivo,
                'message' => 'Documento enviado com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro no upload de documento', [
                'error' => $e->getMessage(),
                'proposta_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao fazer upload: ' . $e->getMessage()
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

            Log::info('Excluindo proposta', [
                'proposta_id' => $id,
                'user_id' => $currentUser->id
            ]);

            $query = "UPDATE propostas p SET deleted_at = NOW(), updated_at = NOW() WHERE p.id = ? AND p.deleted_at IS NULL";
            $params = [$id];

            // Se não for admin, verificar se é proposta do usuário
            if (!$currentUser->isAdmin()) {
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

    private function extrairValorDesconto($desconto): float
    {
        if (is_string($desconto) && str_ends_with($desconto, '%')) {
            $valor = floatval(str_replace('%', '', $desconto));
            \Log::info('🔍 Extraindo desconto:', [
                'original' => $desconto,
                'extraido' => $valor
            ]);
            return $valor;
        }
        
        $valor = floatval($desconto);
        \Log::info('🔍 Desconto numérico:', [
            'original' => $desconto,
            'convertido' => $valor
        ]);
        return $valor;
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
} 