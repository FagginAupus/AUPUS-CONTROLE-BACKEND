<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class PropostaController extends Controller
{
    /**
     * ✅ LISTAR PROPOSTAS
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

            $query = "SELECT * FROM propostas WHERE deleted_at IS NULL";
            $params = [];

            if ($currentUser->role !== 'admin') {
                $query .= " AND usuario_id = ?";
                $params[] = $currentUser->id;
            }

            $query .= " ORDER BY created_at DESC";
            
            $propostas = DB::select($query, $params);

            return response()->json([
                'success' => true,
                'data' => $propostas,
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => count($propostas),
                    'total' => count($propostas),
                    'last_page' => 1
                ],
                'filters' => []
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao carregar propostas', [
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
     * ✅ CRIAR PROPOSTA - COM DEBUG JSON ULTRA DETALHADO
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            Log::info('=== STEP 1: DADOS RECEBIDOS ===', [
                'user_id' => $currentUser->id,
                'all_data' => $request->all(),
                'content_type' => $request->header('Content-Type'),
                'method' => $request->method()
            ]);

            // ✅ VALIDAÇÃO MÍNIMA
            if (!$request->nome_cliente || !$request->consultor || !$request->data_proposta) {
                Log::warning('Campos obrigatórios faltando', [
                    'nome_cliente' => $request->nome_cliente,
                    'consultor' => $request->consultor,
                    'data_proposta' => $request->data_proposta,
                    'received_fields' => array_keys($request->all())
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Campos obrigatórios: nome_cliente, consultor, data_proposta',
                    'received' => [
                        'nome_cliente' => $request->nome_cliente,
                        'consultor' => $request->consultor, 
                        'data_proposta' => $request->data_proposta
                    ]
                ], 422);
            }

            Log::info('=== STEP 2: VALIDAÇÃO BÁSICA OK ===');

            DB::beginTransaction();

            try {
                // ✅ GERAR DADOS BÁSICOS
                $id = (string) Str::uuid();
                $numeroProposta = $request->numero_proposta ?: $this->gerarNumeroProposta();
                
                Log::info('=== STEP 3: PROCESSANDO BENEFÍCIOS ===', [
                    'beneficios_exists' => $request->has('beneficios'),
                    'beneficios_type' => gettype($request->beneficios),
                    'beneficios_value' => $request->beneficios,
                    'beneficios_is_array' => is_array($request->beneficios)
                ]);

                // ✅ PROCESSAR BENEFÍCIOS COM MÁXIMA CAUTELA
                $beneficiosArray = [];
                $beneficiosJson = '[]';

                if ($request->has('beneficios')) {
                    if (is_array($request->beneficios)) {
                        $beneficiosArray = $request->beneficios;
                        $jsonTest = json_encode($beneficiosArray);
                        
                        Log::info('Benefícios JSON encode test', [
                            'original_array' => $beneficiosArray,
                            'json_result' => $jsonTest,
                            'json_error' => json_last_error(),
                            'json_error_msg' => json_last_error_msg()
                        ]);

                        if (json_last_error() === JSON_ERROR_NONE) {
                            $beneficiosJson = $jsonTest;
                        } else {
                            throw new \Exception('Erro ao converter benefícios para JSON: ' . json_last_error_msg());
                        }
                    } else {
                        Log::warning('Benefícios não é array', [
                            'type' => gettype($request->beneficios),
                            'value' => $request->beneficios
                        ]);
                        $beneficiosJson = '[]';
                    }
                }

                Log::info('=== STEP 4: PROCESSANDO UNIDADES CONSUMIDORAS ===', [
                    'uc_exists' => $request->has('unidades_consumidoras'),
                    'uc_type' => gettype($request->unidades_consumidoras),
                    'uc_is_array' => is_array($request->unidades_consumidoras),
                    'uc_count' => is_array($request->unidades_consumidoras) ? count($request->unidades_consumidoras) : 0
                ]);

                // ✅ PROCESSAR UCs COM MÁXIMA CAUTELA
                $ucArray = [];
                $ucJson = '[]';

                if ($request->has('unidades_consumidoras')) {
                    if (is_array($request->unidades_consumidoras)) {
                        $ucArray = $request->unidades_consumidoras;
                        
                        Log::info('UC Array detalhado', [
                            'uc_array' => $ucArray,
                            'primeiro_item' => isset($ucArray[0]) ? $ucArray[0] : 'não existe'
                        ]);

                        $jsonTest = json_encode($ucArray);
                        
                        Log::info('UC JSON encode test', [
                            'json_result' => $jsonTest,
                            'json_error' => json_last_error(),
                            'json_error_msg' => json_last_error_msg()
                        ]);

                        if (json_last_error() === JSON_ERROR_NONE) {
                            $ucJson = $jsonTest;
                        } else {
                            throw new \Exception('Erro ao converter UCs para JSON: ' . json_last_error_msg());
                        }
                    } else {
                        Log::warning('UCs não é array', [
                            'type' => gettype($request->unidades_consumidoras),
                            'value' => $request->unidades_consumidoras
                        ]);
                        $ucJson = '[]';
                    }
                }

                Log::info('=== STEP 5: DADOS PROCESSADOS PARA SQL ===', [
                    'id' => $id,
                    'numero_proposta' => $numeroProposta,
                    'nome_cliente' => $request->nome_cliente,
                    'consultor' => $request->consultor,
                    'data_proposta' => $request->data_proposta,
                    'beneficios_json' => $beneficiosJson,
                    'beneficios_length' => strlen($beneficiosJson),
                    'uc_json' => $ucJson,
                    'uc_length' => strlen($ucJson)
                ]);

                // ✅ TESTAR JSON ANTES DE INSERIR
                Log::info('=== STEP 6: TESTANDO JSON NO POSTGRESQL ===');

                // Teste 1: Validar JSON benefícios
                try {
                    $testBeneficios = DB::select("SELECT ?::json as test", [$beneficiosJson]);
                    Log::info('✅ Benefícios JSON válido no PostgreSQL', [
                        'result' => $testBeneficios[0]->test ?? 'sem resultado'
                    ]);
                } catch (\Exception $e) {
                    Log::error('❌ Benefícios JSON inválido no PostgreSQL', [
                        'error' => $e->getMessage(),
                        'json_string' => $beneficiosJson
                    ]);
                    throw new \Exception('JSON benefícios inválido para PostgreSQL: ' . $e->getMessage());
                }

                // Teste 2: Validar JSON UCs
                try {
                    $testUc = DB::select("SELECT ?::json as test", [$ucJson]);
                    Log::info('✅ UCs JSON válido no PostgreSQL', [
                        'result' => $testUc[0]->test ?? 'sem resultado'
                    ]);
                } catch (\Exception $e) {
                    Log::error('❌ UCs JSON inválido no PostgreSQL', [
                        'error' => $e->getMessage(),
                        'json_string' => $ucJson
                    ]);
                    throw new \Exception('JSON UCs inválido para PostgreSQL: ' . $e->getMessage());
                }

                Log::info('=== STEP 7: EXECUTANDO INSERÇÃO ===');

                // ✅ INSERÇÃO COM PARÂMETROS EXPLÍCITOS
                $sql = "INSERT INTO propostas (
                    id, numero_proposta, data_proposta, nome_cliente, consultor, usuario_id,
                    recorrencia, economia, bandeira, status, observacoes, beneficios, 
                    unidades_consumidoras, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::json, ?::json, NOW(), NOW())";

                $params = [
                    $id,                                                // 1
                    $numeroProposta,                                   // 2
                    $request->data_proposta,                          // 3
                    $request->nome_cliente,                           // 4
                    $request->consultor,                              // 5
                    $currentUser->id,                                 // 6
                    $request->recorrencia ?? '3%',                    // 7
                    floatval($request->economia ?? 20),               // 8
                    floatval($request->bandeira ?? 20),               // 9
                    $request->status ?? 'Em Análise',                 // 10
                    $request->observacoes ?? '',                      // 11
                    $beneficiosJson,                                  // 12
                    $ucJson                                           // 13
                ];

                Log::info('SQL e parâmetros finais', [
                    'sql' => $sql,
                    'params' => $params,
                    'param_count' => count($params)
                ]);

                $result = DB::insert($sql, $params);

                Log::info('=== STEP 8: RESULTADO DA INSERÇÃO ===', [
                    'insert_result' => $result,
                    'result_type' => gettype($result)
                ]);

                if (!$result) {
                    throw new \Exception('INSERT retornou false - falha na inserção');
                }

                // ✅ VERIFICAR SE INSERIU
                $propostaInserida = DB::selectOne("SELECT * FROM propostas WHERE id = ?", [$id]);

                if (!$propostaInserida) {
                    throw new \Exception('Proposta não encontrada após inserção');
                }

                Log::info('=== STEP 9: PROPOSTA INSERIDA COM SUCESSO ===', [
                    'proposta_id' => $propostaInserida->id,
                    'numero_proposta' => $propostaInserida->numero_proposta,
                    'beneficios_db' => $propostaInserida->beneficios,
                    'ucs_db' => $propostaInserida->unidades_consumidoras
                ]);

                DB::commit();

                // ✅ RESPOSTA DE SUCESSO
                return response()->json([
                    'success' => true,
                    'message' => 'Proposta criada com sucesso',
                    'data' => [
                        'id' => $propostaInserida->id,
                        'numeroProposta' => $propostaInserida->numero_proposta,
                        'nomeCliente' => $propostaInserida->nome_cliente,
                        'consultor' => $propostaInserida->consultor,
                        'data' => $propostaInserida->data_proposta,
                        'status' => $propostaInserida->status,
                        'economia' => $propostaInserida->economia,
                        'bandeira' => $propostaInserida->bandeira,
                        'recorrencia' => $propostaInserida->recorrencia,
                        'observacoes' => $propostaInserida->observacoes,
                        'beneficios' => json_decode($propostaInserida->beneficios ?? '[]', true),
                        'unidades_consumidoras' => json_decode($propostaInserida->unidades_consumidoras ?? '[]', true),
                        'created_at' => $propostaInserida->created_at,
                        'updated_at' => $propostaInserida->updated_at
                    ]
                ], 201);

            } catch (\Exception $dbException) {
                DB::rollBack();
                
                Log::error('=== ERRO NA TRANSAÇÃO ===', [
                    'error_message' => $dbException->getMessage(),
                    'error_line' => $dbException->getLine(),
                    'error_file' => $dbException->getFile(),
                    'error_trace' => $dbException->getTraceAsString()
                ]);

                // Verificar tipos específicos de erro
                $errorMsg = $dbException->getMessage();

                if (str_contains($errorMsg, 'duplicate key') || str_contains($errorMsg, 'unique')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Número da proposta já existe'
                    ], 409);
                }

                if (str_contains($errorMsg, 'json') || str_contains($errorMsg, 'JSON')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Erro no formato dos dados JSON',
                        'debug' => [
                            'error_detail' => $errorMsg,
                            'beneficios_json' => $beneficiosJson ?? 'não definido',
                            'uc_json' => $ucJson ?? 'não definido'
                        ]
                    ], 422);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Erro no banco de dados: ' . $errorMsg,
                    'debug' => [
                        'error' => $errorMsg,
                        'line' => $dbException->getLine()
                    ]
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('=== ERRO GERAL ===', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor: ' . $e->getMessage(),
                'debug' => [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => basename($e->getFile())
                ]
            ], 500);
        }
    }

    /**
     * ✅ GERAR NÚMERO DA PROPOSTA
     */
    private function gerarNumeroProposta(): string
    {
        try {
            $ano = date('Y');
            $count = DB::scalar("SELECT COUNT(*) FROM propostas WHERE EXTRACT(YEAR FROM created_at) = ?", [$ano]);
            return sprintf('%s/%03d', $ano, $count + 1);
        } catch (\Exception $e) {
            Log::error('Erro ao gerar número da proposta', ['error' => $e->getMessage()]);
            return date('Y') . '/' . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
        }
    }

    /**
     * ✅ OUTROS MÉTODOS (sem alteração)
     */
    public function show(string $id): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json(['success' => false, 'message' => 'Usuário não autenticado'], 401);
            }

            $proposta = DB::selectOne("SELECT * FROM propostas WHERE id = ? AND deleted_at IS NULL", [$id]);

            if (!$proposta) {
                return response()->json(['success' => false, 'message' => 'Proposta não encontrada'], 404);
            }

            if ($currentUser->role === 'vendedor' && $proposta->usuario_id !== $currentUser->id) {
                return response()->json(['success' => false, 'message' => 'Sem permissão'], 403);
            }

            return response()->json([
                'success' => true,
                'data' => [
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
                    'beneficios' => json_decode($proposta->beneficios ?? '[]', true),
                    'unidades_consumidoras' => json_decode($proposta->unidades_consumidoras ?? '[]', true),
                    'created_at' => $proposta->created_at,
                    'updated_at' => $proposta->updated_at
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao carregar proposta', ['proposta_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno'], 500);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Método não implementado ainda'], 501);
    }

    public function destroy(string $id): JsonResponse  
    {
        return response()->json(['success' => false, 'message' => 'Método não implementado ainda'], 501);
    }
}