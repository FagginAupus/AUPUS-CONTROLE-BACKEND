<?php
// app/Http/Controllers/UGController.php

namespace App\Http\Controllers;

use App\Models\UnidadeConsumidora;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UGController extends Controller
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

        try {
            \Log::info('=== UGController::index() INICIADO ===', [
                'user_id' => $currentUser->id,
                'user_role' => $currentUser->role,
                'timestamp' => now()->toISOString(),
                'request_search' => $request->get('search', 'sem busca')
            ]);

            $search = $request->get('search');

            // ✅ CORRIGIDO: Usar 'gerador' ao invés de 'is_ug'
            $query = UnidadeConsumidora::query()
                ->where('gerador', true)
                ->where('nexus_clube', true)
                ->whereNull('deleted_at');

            \Log::info('UG Query construída', [
                'sql_without_bindings' => $query->toSql(),
                'search_term' => $search ?? 'nenhum'
            ]);

            if ($search) {
                \Log::info('Aplicando filtro de busca', [
                    'search' => $search,
                    'pattern' => '%' . $search . '%'
                ]);
                $query->where('nome_usina', 'ILIKE', '%' . $search . '%');
            }

            // Log antes de executar a query
            \Log::info('Executando query para buscar UGs...');
            $ugs = $query->orderBy('nome_usina', 'asc')->get();

            \Log::info('UGs encontradas', [
                'total' => $ugs->count(),
                'primeira_ug' => $ugs->first() ? [
                    'id' => $ugs->first()->id,
                    'nome' => $ugs->first()->nome_usina,
                    'gerador' => $ugs->first()->gerador,
                    'nexus_clube' => $ugs->first()->nexus_clube,
                    'potencia_cc' => $ugs->first()->potencia_cc,
                    'fator_capacidade' => $ugs->first()->fator_capacidade
                ] : 'nenhuma'
            ]);

            // Transformar dados para frontend
            $ugsTransformadas = $ugs->map(function ($ug) {
                return [
                    'id' => $ug->id,
                    'nomeUsina' => $ug->nome_usina,
                    'potenciaCC' => (float) $ug->potencia_cc,
                    'fatorCapacidade' => (float) ($ug->fator_capacidade * 100), // ✅ MULTIPLICAR POR 100
                    'capacidade' => (float) $ug->capacidade_calculada,
                    'localizacao' => $ug->localizacao,
                    'observacoes' => $ug->observacoes_ug,
                    'ucsAtribuidas' => (int) $ug->ucs_atribuidas,
                    'mediaConsumoAtribuido' => (float) $ug->media_consumo_atribuido, // ✅ CORRIGIDO
                    'dataCadastro' => $ug->created_at?->toISOString(),
                    'dataAtualizacao' => $ug->updated_at?->toISOString(),
                ];
            });

            \Log::info('UGs transformadas para frontend', [
                'total_transformadas' => $ugsTransformadas->count(),
                'primeira_transformada' => $ugsTransformadas->first() ?: 'nenhuma'
            ]);

            $response = [
                'success' => true,
                'data' => $ugsTransformadas,
                'total' => $ugsTransformadas->count()
            ];

            \Log::info('=== UGController::index() CONCLUÍDO COM SUCESSO ===', [
                'total_retornado' => $ugsTransformadas->count()
            ]);

            return response()->json($response);

        } catch (\Exception $e) {
            \Log::error('=== ERRO em UGController::index() ===', [
                'user_id' => $currentUser->id,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Criar nova UG
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

        // Verificar se é admin
        if ($currentUser->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem criar UGs'
            ], 403);
        }

        try {
            \Log::info('=== UGController::store() DADOS RECEBIDOS ===', [
                'all_request_data' => $request->all(),
                'content_type' => $request->header('Content-Type')
            ]);

            // ✅ VALIDAÇÃO
            $dadosValidados = $request->validate([
                'nome_usina' => 'required|string|max:255',
                'potencia_cc' => 'required|numeric|min:0.1|max:10000',
                'fator_capacidade' => 'required|numeric|min:1|max:100', // ✅ CORRIGIDO: 1-100 ao invés de 0.01-1
                'numero_unidade' => 'required|string|max:50',
                'apelido' => 'required|string|max:255',
                'localizacao' => 'nullable|string|max:255',
                'observacoes_ug' => 'nullable|string|max:1000',
                'gerador' => 'required|boolean',
                'nexus_clube' => 'required|boolean',
            ]);

            \Log::info('✅ Dados validados com sucesso:', $dadosValidados);

            // ✅ CRIAR UG usando ULID
            $ug = UnidadeConsumidora::create([
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'usuario_id' => (string) $currentUser->id,
                'concessionaria_id' => '01JB849ZDG0RPC5EB8ZFTB4GJN', // ✅ ULID padrão fixo
                'nome_usina' => $dadosValidados['nome_usina'],
                'potencia_cc' => (float) $dadosValidados['potencia_cc'],
                'fator_capacidade' => (float) ($dadosValidados['fator_capacidade'] / 100),
                'numero_unidade' => $dadosValidados['numero_unidade'],
                'apelido' => $dadosValidados['apelido'],
                'localizacao' => $dadosValidados['localizacao'] ?? '',
                'observacoes_ug' => $dadosValidados['observacoes_ug'] ?? '',
                'gerador' => $dadosValidados['gerador'],
                'nexus_clube' => $dadosValidados['nexus_clube'],
                
                // ✅ CALCULAR CAPACIDADE
                'capacidade_calculada' => 720 * $dadosValidados['potencia_cc'] * ($dadosValidados['fator_capacidade'] / 100),
                
                
                // Campos extras com valores padrão
                'nexus_cativo' => false,
                'service' => false,
                'project' => false,
                'distribuidora' => $request->input('distribuidora', 'EQUATORIAL'),
                'consumo_medio' => 0,
                'tipo' => 'UG',
                'classe' => 'Comercial',
                'subclasse' => 'Comercial',
                'grupo' => 'A',
                'ligacao' => 'Trifásico',
                'mesmo_titular' => false,
                'numero_cliente' => '0',
                'proprietario' => false,
                'tensao_nominal' => 0,
                'irrigante' => false,
                'calibragem_percentual' => 0,
                'relacao_te' => 1,
                'tipo_conexao' => 'Rede',
                'estrutura_tarifaria' => 'Convencional',
                'desconto_fatura' => 0,
                'desconto_bandeira' => 0,
                'ucs_atribuidas' => 0,
                'media_consumo_atribuido' => 0,
            ]);

            \Log::info('✅ UG criada com sucesso', [
                'ug_id' => $ug->id,
                'nome_usina' => $ug->nome_usina
            ]);

            // Transformar para frontend
            $ugTransformada = [
                'id' => $ug->id,
                'nomeUsina' => $ug->nome_usina,
                'potenciaCC' => (float) $ug->potencia_cc,
                'fatorCapacidade' => (float) ($ug->fator_capacidade * 100), // ✅ MULTIPLICAR POR 100
                'capacidade' => (float) $ug->capacidade_calculada,
                'localizacao' => $ug->localizacao,
                'observacoes' => $ug->observacoes_ug,
                'ucsAtribuidas' => (int) $ug->ucs_atribuidas,
                'mediaConsumoAtribuido' => (float) $ug->media_consumo_atribuido,
                'dataCadastro' => $ug->created_at?->toISOString(),
                'dataAtualizacao' => $ug->updated_at?->toISOString(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'UG criada com sucesso',
                'data' => $ugTransformada
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            \Log::error('Erro ao criar UG', [
                'user_id' => $currentUser->id,
                'data' => $request->all(),
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
     * Mostrar UG específica
     */
    public function show($id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        try {
            $ug = UnidadeConsumidora::where('id', $id)
                ->where('gerador', true) // CORRIGIDO: usar 'gerador' ao invés de 'is_ug'
                ->where('nexus_clube', true) // ADICIONADO: verificar nexus_clube
                ->whereNull('deleted_at')
                ->firstOrFail();

            $ugTransformada = [
                'id' => $ug->id,
                'nomeUsina' => $ug->nome_usina,
                'potenciaCC' => (float) $ug->potencia_cc,
                'fatorCapacidade' => (float) ($ug->fator_capacidade * 100), // ✅ MULTIPLICAR POR 100
                'capacidade' => (float) $ug->capacidade_calculada,
                'localizacao' => $ug->localizacao,
                'observacoes' => $ug->observacoes_ug,
                'ucsAtribuidas' => (int) $ug->ucs_atribuidas,
                'mediaConsumoAtribuido' => (float) $ug->media_consumo_atribuido,
                'dataCadastro' => $ug->created_at?->toISOString(),
                'dataAtualizacao' => $ug->updated_at?->toISOString(),
            ];

            return response()->json([
                'success' => true,
                'data' => $ugTransformada
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'UG não encontrada'
            ], 404);

        } catch (\Exception $e) {
            \Log::error('Erro ao buscar UG', [
                'ug_id' => $id,
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
     * Atualizar UG
     */
    public function update(Request $request, $id): JsonResponse
    {
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
                'message' => 'Apenas administradores podem atualizar UGs'
            ], 403);
        }

        try {
            $ug = UnidadeConsumidora::where('id', $id)
                ->where('gerador', true) // CORRIGIDO: usar 'gerador' ao invés de 'is_ug'
                ->where('nexus_clube', true) // ADICIONADO: verificar nexus_clube
                ->whereNull('deleted_at')
                ->firstOrFail();

            $request->validate([
                'nomeUsina' => 'sometimes|required|string|max:255',
                'potenciaCC' => 'sometimes|required|numeric|min:0',
                'fatorCapacidade' => 'sometimes|required|numeric|min:1|max:100', // ✅ CORRIGIDO
                'localizacao' => 'sometimes|nullable|string|max:500',
                'observacoes' => 'sometimes|nullable|string|max:1000',
            ]);

            $dadosAtualizacao = [];

            if ($request->has('nomeUsina')) {
                $dadosAtualizacao['nome_usina'] = $request->nomeUsina;
            }

            if ($request->has('potenciaCC')) {
                $dadosAtualizacao['potencia_cc'] = $request->potenciaCC;
            }

            if ($request->has('fatorCapacidade')) {
                $dadosAtualizacao['fator_capacidade'] = $request->fatorCapacidade;
            }

            if ($request->has('localizacao')) {
                $dadosAtualizacao['localizacao'] = $request->localizacao;
            }

            if ($request->has('observacoes')) {
                $dadosAtualizacao['observacoes_ug'] = $request->observacoes;
            }

            // Recalcular capacidade se potência ou fator mudaram
            if ($request->has('potenciaCC') || $request->has('fatorCapacidade')) {
                $potencia = $request->potenciaCC ?? $ug->potencia_cc;
                $fator = $request->fatorCapacidade ?? ($ug->fator_capacidade * 100); // ✅ MULTIPLICAR POR 100 para comparar
                $dadosAtualizacao['capacidade_calculada'] = 720 * $potencia * ($fator / 100);
                $dadosAtualizacao['fator_capacidade'] = $fator / 100; // ✅ ARMAZENAR COMO DECIMAL
            }

            $ug->update($dadosAtualizacao);

            $ugTransformada = [
                'id' => $ug->id,
                'nomeUsina' => $ug->nome_usina,
                'potenciaCC' => (float) $ug->potencia_cc,
                'fatorCapacidade' => (float) ($ug->fator_capacidade * 100), // ✅ MULTIPLICAR POR 100 para frontend
                'capacidade' => (float) $ug->capacidade_calculada,
                'localizacao' => $ug->localizacao,
                'observacoes' => $ug->observacoes_ug,
                'ucsAtribuidas' => (int) $ug->ucs_atribuidas,
                'mediaConsumoAtribuido' => (float) $ug->media_consumo_atribuido,
                'dataCadastro' => $ug->created_at?->toISOString(),
                'dataAtualizacao' => $ug->updated_at?->toISOString(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'UG atualizada com sucesso',
                'data' => $ugTransformada
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'UG não encontrada'
            ], 404);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            \Log::error('Erro ao atualizar UG', [
                'ug_id' => $id,
                'user_id' => $currentUser->id,
                'data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Deletar UG
     */
    public function destroy($id): JsonResponse
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
                'message' => 'Apenas administradores podem excluir UGs'
            ], 403);
        }

        try {
            \Log::info('Iniciando exclusão de UG', ['ug_id' => $id]);

            // ✅ 1. Verificar se UG existe e pode ser excluída
            $ug = DB::selectOne("
                SELECT id, nome_usina, ucs_atribuidas, deleted_at
                FROM unidades_consumidoras 
                WHERE id = ? AND gerador = true AND nexus_clube = true AND deleted_at IS NULL
            ", [$id]);

            if (!$ug) {
                \Log::warning('UG não encontrada', ['ug_id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'UG não encontrada'
                ], 404);
            }

            \Log::info('UG encontrada', [
                'ug_id' => $ug->id,
                'nome_usina' => $ug->nome_usina,
                'ucs_atribuidas' => $ug->ucs_atribuidas
            ]);

            // ✅ 2. Verificar se há UCs atribuídas
            if ($ug->ucs_atribuidas > 0) {
                \Log::warning('UG possui UCs atribuídas', [
                    'ug_id' => $id,
                    'ucs_atribuidas' => $ug->ucs_atribuidas
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível excluir UG com UCs atribuídas. Remova as UCs primeiro.'
                ], 400);
            }

            // ✅ 3. Executar soft delete com DB::update DIRETAMENTE
            $agora = now()->format('Y-m-d H:i:s');
            
            \Log::info('Executando soft delete', [
                'ug_id' => $id,
                'deleted_at' => $agora,
                'deleted_by' => $currentUser->id
            ]);

            $rowsAffected = DB::update("
                UPDATE unidades_consumidoras 
                SET deleted_at = ?, deleted_by = ?, updated_at = ?
                WHERE id = ? AND deleted_at IS NULL
            ", [$agora, $currentUser->id, $agora, $id]);

            \Log::info('Resultado do UPDATE', [
                'ug_id' => $id,
                'rows_affected' => $rowsAffected,
                'deleted_at' => $agora
            ]);

            // ✅ 4. Verificar se a atualização funcionou
            if ($rowsAffected === 0) {
                \Log::error('Nenhuma linha foi atualizada', ['ug_id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Falha ao excluir UG - nenhuma linha afetada'
                ], 500);
            }

            // ✅ 5. Confirmar que foi excluída
            $ugExcluida = DB::selectOne("
                SELECT deleted_at, deleted_by 
                FROM unidades_consumidoras 
                WHERE id = ?
            ", [$id]);

            \Log::info('✅ UG EXCLUÍDA COM SUCESSO!', [
                'ug_id' => $id,
                'nome_usina' => $ug->nome_usina,
                'rows_affected' => $rowsAffected,
                'deleted_at_confirmacao' => $ugExcluida->deleted_at,
                'deleted_by_confirmacao' => $ugExcluida->deleted_by,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => "UG '{$ug->nome_usina}' excluída com sucesso"
            ]);

        } catch (\Exception $e) {
            \Log::error('❌ ERRO ao excluir UG', [
                'ug_id' => $id,
                'user_id' => $currentUser->id,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Obter estatísticas das UGs
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
            $query = UnidadeConsumidora::where('gerador', true) // CORRIGIDO: usar 'gerador' ao invés de 'is_ug'
                ->where('nexus_clube', true) // ADICIONADO: verificar nexus_clube
                ->whereNull('deleted_at');

            $stats = [
                'total' => $query->count(),
                'capacidadeTotal' => $query->sum('capacidade_calculada'),
                'ucsAtribuidas' => $query->sum('ucs_atribuidas'),
                'mediaConsumo' => $query->avg('media_consumo_atribuido'),
                'potenciaTotal' => $query->sum('potencia_cc'),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao obter estatísticas UGs', [
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