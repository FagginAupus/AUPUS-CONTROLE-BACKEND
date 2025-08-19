<?php
// app/Http/Controllers/UGController.php

namespace App\Http\Controllers;

use App\Models\UnidadeConsumidora;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

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
                \Log::debug('Transformando UG', [
                    'id' => $ug->id,
                    'nome_usina' => $ug->nome_usina,
                    'gerador' => $ug->gerador,
                    'nexus_clube' => $ug->nexus_clube
                ]);

                return [
                    'id' => $ug->id,
                    'nomeUsina' => $ug->nome_usina,
                    'potenciaCC' => (float) $ug->potencia_cc,
                    'fatorCapacidade' => (float) $ug->fator_capacidade,
                    'capacidade' => (float) $ug->capacidade_calculada,
                    'localizacao' => $ug->localizacao,
                    'observacoes' => $ug->observacoes_ug,
                    'ucsAtribuidas' => (int) $ug->ucs_atribuidas,
                    'mediaConsumoAtribuido' => (float) $ug->media_consumo_atribuido,
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
            $request->validate([
                'nomeUsina' => 'required|string|max:255',     // Frontend envia nomeUsina
                'potenciaCC' => 'required|numeric|min:0',     // Frontend envia potenciaCC  
                'fatorCapacidade' => 'required|numeric|min:0|max:100', // Frontend envia fatorCapacidade
                'localizacao' => 'nullable|string|max:500',
                'observacoes' => 'nullable|string|max:1000',
                'apelido' => 'required|string|max:100',       // ADICIONAR campo obrigatório
                'numero_unidade' => 'required|string|max:50', // ADICIONAR campo obrigatório
            ], [
                'nomeUsina.required' => 'Nome da usina é obrigatório',
                'potenciaCC.required' => 'Potência CC é obrigatória',
                'potenciaCC.numeric' => 'Potência CC deve ser um número',
                'fatorCapacidade.required' => 'Fator de capacidade é obrigatório',
                'fatorCapacidade.numeric' => 'Fator de capacidade deve ser um número',
                'fatorCapacidade.max' => 'Fator de capacidade não pode ser maior que 100%',
                'apelido.required' => 'Apelido é obrigatório',
                'numero_unidade.required' => 'Número da unidade é obrigatório',
            ]);

            // Calcular capacidade (720 horas * potência * fator / 100)
            $capacidade = 720 * $request->potenciaCC * ($request->fatorCapacidade / 100);

            $ug = UnidadeConsumidora::create([
                'usuario_id' => $currentUser->id,
                'nome_usina' => $request->nomeUsina,        // Mapear corretamente
                'potencia_cc' => $request->potenciaCC,      // Mapear corretamente  
                'fator_capacidade' => $request->fatorCapacidade, // Mapear corretamente
                'capacidade_calculada' => $capacidade,
                'localizacao' => $request->localizacao,
                'observacoes_ug' => $request->observacoes,
                'apelido' => $request->apelido,             // ADICIONAR
                'numero_unidade' => $request->numero_unidade, // ADICIONAR
                'consumo_medio' => 0,                       // ADICIONAR padrão
                'gerador' => true,                          // CORRIGIDO: usar 'gerador'
                'nexus_clube' => true,                      // ADICIONADO: sempre true para UGs
                'ucs_atribuidas' => 0,
                'media_consumo_atribuido' => 0,
                'mesmo_titular' => false,
                'numero_cliente' => 0,
                'tipo' => 'UG',
                'service' => false,
                'project' => false,
                'nexus_cativo' => false,
                'proprietario' => false,
                'tensao_nominal' => 0,
                'grupo' => 'A',
                'ligacao' => 'Trifásico',
                'irrigante' => false,
                'calibragem_percentual' => 0,
                'relacao_te' => 1,
                'classe' => 'Comercial',
                'subclasse' => 'Comercial',
                'tipo_conexao' => 'Rede',
                'estrutura_tarifaria' => 'Convencional',
                'desconto_fatura' => 0,
                'desconto_bandeira' => 0,
            ]);

            $ugTransformada = [
                'id' => $ug->id,
                'nomeUsina' => $ug->nome_usina,
                'potenciaCC' => (float) $ug->potencia_cc,
                'fatorCapacidade' => (float) $ug->fator_capacidade,
                'capacidade' => (float) $ug->capacidade_calculada,
                'localizacao' => $ug->localizacao,
                'observacoes' => $ug->observacoes_ug,
                'ucsAtribuidas' => 0,
                'mediaConsumoAtribuido' => 0,
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
                'fatorCapacidade' => (float) $ug->fator_capacidade,
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
                'fatorCapacidade' => 'sometimes|required|numeric|min:0|max:100',
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
                $fator = $request->fatorCapacidade ?? $ug->fator_capacidade;
                $dadosAtualizacao['capacidade_calculada'] = 720 * $potencia * ($fator / 100);
            }

            $ug->update($dadosAtualizacao);

            $ugTransformada = [
                'id' => $ug->id,
                'nomeUsina' => $ug->nome_usina,
                'potenciaCC' => (float) $ug->potencia_cc,
                'fatorCapacidade' => (float) $ug->fator_capacidade,
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

        // Verificar se é admin
        if ($currentUser->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem excluir UGs'
            ], 403);
        }

        try {
            $ug = UnidadeConsumidora::where('id', $id)
                ->where('gerador', true) // CORRIGIDO: usar 'gerador' ao invés de 'is_ug'
                ->where('nexus_clube', true) // ADICIONADO: verificar nexus_clube
                ->whereNull('deleted_at')
                ->firstOrFail();

            // Verificar se há UCs atribuídas a esta UG
            if ($ug->ucs_atribuidas > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível excluir UG com UCs atribuídas. Remova as UCs primeiro.'
                ], 422);
            }

            // Soft delete
            $ug->update([
                'deleted_at' => now(),
                'deleted_by' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'UG excluída com sucesso'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'UG não encontrada'
            ], 404);

        } catch (\Exception $e) {
            \Log::error('Erro ao excluir UG', [
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