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

            $query = "SELECT * FROM propostas WHERE deleted_at IS NULL AND status != 'Cancelada'";
            $params = [];

            // Se não for admin, filtrar apenas as propostas do usuário
            if ($currentUser->role !== 'admin') {
                $query .= " AND usuario_id = ?";
                $params[] = $currentUser->id;
            }

            $query .= " ORDER BY created_at DESC";
            
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
                    'consultor' => $proposta->consultor,
                    'data' => $proposta->data_proposta,
                    'status' => $proposta->status,
                    'descontoTarifa' => $this->extrairValorDesconto($proposta->desconto_tarifa),
                    'descontoBandeira' => $this->extrairValorDesconto($proposta->desconto_bandeira),
                    'recorrencia' => $proposta->recorrencia,
                    'observacoes' => $proposta->observacoes,
                    
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

            return response()->json([
                'success' => true,
                'data' => $propostasMapeadas,
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => count($propostasMapeadas),
                    'total' => count($propostasMapeadas),
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

    /**
     * ✅ CRIAR NOVA PROPOSTA
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

            Log::info('=== INICIANDO CRIAÇÃO DE PROPOSTA ===', [
                'user_id' => $currentUser->id,
                'request_data' => $request->all()
            ]);

            DB::beginTransaction();

            // ✅ GERAR ID E NÚMERO DA PROPOSTA
            $id = Str::uuid()->toString();
            $numeroProposta = $request->numero_proposta ?? $this->gerarNumeroProposta();
                
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
                id, numero_proposta, data_proposta, nome_cliente, consultor, 
                usuario_id, recorrencia, desconto_tarifa, desconto_bandeira, status,
                observacoes, beneficios, unidades_consumidoras,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

            $params = [
                $id,
                $numeroProposta,
                $request->data_proposta ?? date('Y-m-d'),
                $request->nome_cliente,
                $request->consultor,
                $currentUser->id,
                $request->recorrencia ?? '3%',
                $this->formatarDesconto($request->economia ?? 20),   
                $this->formatarDesconto($request->bandeira ?? 20),  
                $request->status ?? 'Aguardando',
                $request->observacoes ?? '',
                $beneficiosJson,
                $ucJson
            ];

            $result = DB::insert($sql, $params);

            if (!$result) {
                throw new \Exception('Falha ao inserir proposta no banco de dados');
            }

            // ✅ BUSCAR PROPOSTA INSERIDA
            $propostaInserida = DB::selectOne("SELECT * FROM propostas WHERE id = ?", [$id]);

            if (!$propostaInserida) {
                throw new \Exception('Proposta não encontrada após inserção');
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
                'consultor' => $propostaInserida->consultor,
                'data' => $propostaInserida->data_proposta,
                'status' => $propostaInserida->status,
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

            $query = "SELECT * FROM propostas WHERE id = ? AND deleted_at IS NULL";
            $params = [$id];

            // Se não for admin, verificar se é proposta do usuário
            if ($currentUser->role !== 'admin') {
                $query .= " AND usuario_id = ?";
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
            $primeiraUC = !empty($unidadesConsumidoras) ? $unidadesConsumidoras[0] : null;

            $propostaMapeada = [
                // Campos principais - NOMES CORRETOS PARA STORAGESERVICE
                'id' => $proposta->id,
                'numero_proposta' => $proposta->numero_proposta,
                'numeroProposta' => $proposta->numero_proposta, // Compatibilidade frontend
                'nome_cliente' => $proposta->nome_cliente,
                'nomeCliente' => $proposta->nome_cliente, // Compatibilidade frontend
                'consultor' => $proposta->consultor,
                'data_proposta' => $proposta->data_proposta,
                'data' => $proposta->data_proposta, // Compatibilidade frontend
                'status' => $proposta->status,
                'observacoes' => $proposta->observacoes,
                'recorrencia' => $proposta->recorrencia,
                
                // ✅ DESCONTOS MAPEADOS CORRETAMENTE - PRIORIDADE PRINCIPAL
                'descontoTarifa' => $this->extrairValorDesconto($proposta->desconto_tarifa),
                'descontoBandeira' => $this->extrairValorDesconto($proposta->desconto_bandeira),
                
                // Compatibilidade com nomes antigos (para evitar quebra)
                'economia' => $this->extrairValorDesconto($proposta->desconto_tarifa),
                'bandeira' => $this->extrairValorDesconto($proposta->desconto_bandeira),
                
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
            $query = "SELECT * FROM propostas WHERE id = ? AND deleted_at IS NULL";
            $params = [$id];

            if ($currentUser->role !== 'admin') {
                $query .= " AND usuario_id = ?";
                $params[] = $currentUser->id;
            }

            $proposta = DB::selectOne($query, $params);

            if (!$proposta) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proposta não encontrada ou sem permissão'
                ], 404);
            }

            // ✅ PROCESSAR DADOS DE ATUALIZAÇÃO
            $updateFields = [];
            $updateParams = [];

            if ($request->has('nome_cliente')) {
                $updateFields[] = 'nome_cliente = ?';
                $updateParams[] = $request->nome_cliente;
            }

            if ($request->has('consultor')) {
                $updateFields[] = 'consultor = ?';
                $updateParams[] = $request->consultor;
            }

            if ($request->has('data_proposta')) {
                $updateFields[] = 'data_proposta = ?';
                $updateParams[] = $request->data_proposta;
            }

            if ($request->has('status')) {
                $updateFields[] = 'status = ?';
                $updateParams[] = $request->status;
            }

            if ($request->has('descontoTarifa')) {
                $updateFields[] = 'desconto_tarifa = ?';
                $updateParams[] = $this->formatarDesconto($request->descontoTarifa);
            }

            if ($request->has('descontoBandeira')) {
                $updateFields[] = 'desconto_bandeira = ?';
                $updateParams[] = $this->formatarDesconto($request->descontoBandeira);
            }

            if ($request->has('recorrencia')) {
                $updateFields[] = 'recorrencia = ?';
                $updateParams[] = $request->recorrencia;
            }

            if ($request->has('observacoes')) {
                $updateFields[] = 'observacoes = ?';
                $updateParams[] = $request->observacoes;
            }

            if ($request->has('beneficios') && is_array($request->beneficios)) {
                $beneficiosJson = json_encode($request->beneficios, JSON_UNESCAPED_UNICODE);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $updateFields[] = 'beneficios = ?';
                    $updateParams[] = $beneficiosJson;
                }
            }

            if ($request->has('unidadesConsumidoras') && is_array($request->unidadesConsumidoras)) {
                $ucJson = json_encode($request->unidadesConsumidoras, JSON_UNESCAPED_UNICODE);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $updateFields[] = 'unidades_consumidoras = ?';
                    $updateParams[] = $ucJson;
                }
            }

            if (empty($updateFields)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum campo válido fornecido para atualização'
                ], 400);
            }

            // ✅ ATUALIZAR NO BANCO
            $updateFields[] = 'updated_at = NOW()';
            $updateParams[] = $id;

            $updateSql = "UPDATE propostas SET " . implode(', ', $updateFields) . " WHERE id = ?";
            
            $result = DB::update($updateSql, $updateParams);

            if (!$result) {
                throw new \Exception('Nenhuma linha foi atualizada');
            }

            // ✅ BUSCAR PROPOSTA ATUALIZADA
            $propostaAtualizada = DB::selectOne("SELECT * FROM propostas WHERE id = ?", [$id]);

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
                'consultor' => $propostaAtualizada->consultor,
                'data' => $propostaAtualizada->data_proposta,
                'status' => $propostaAtualizada->status,
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
                'unidadesConsumidoras' => $unidadesConsumidoras
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

            $query = "UPDATE propostas SET deleted_at = NOW(), updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
            $params = [$id];

            // Se não for admin, verificar se é proposta do usuário
            if ($currentUser->role !== 'admin') {
                $query .= " AND usuario_id = ?";
                $params[] = $currentUser->id;
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
        
        // ✅ SOLUÇÃO ROBUSTA: Loop até encontrar número disponível
        $tentativa = 1;
        $maxTentativas = 1000; // Evitar loop infinito
        
        while ($tentativa <= $maxTentativas) {
            $numeroTentativa = $ano . '/' . str_pad($tentativa, 3, '0', STR_PAD_LEFT);
            
            // Verificar se já existe
            $existe = DB::table('propostas')
                ->where('numero_proposta', $numeroTentativa)
                ->count();

            // Se não existe, usar este número
            if ($existe == 0) {
                return $numeroTentativa;
            }
            
            $tentativa++;
        }
        
        // Se chegou até aqui, algo está muito errado
        throw new \Exception("Não foi possível gerar número de proposta único após {$maxTentativas} tentativas");
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

    /**
     * ✅ Extrair valor numérico do desconto para cálculos
     */
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
}