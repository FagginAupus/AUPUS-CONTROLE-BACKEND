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
    /**
     * Listar todas as UGs
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
            // Buscar apenas UGs (is_ug = true)
            $query = UnidadeConsumidora::where('is_ug', true)
                ->whereNull('deleted_at');

            // Filtros opcionais
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where('nome_usina', 'ILIKE', '%' . $search . '%');
            }

            $ugs = $query->orderBy('nome_usina', 'asc')->get();

            // Transformar dados para frontend
            $ugsTransformadas = $ugs->map(function ($ug) {
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

            return response()->json([
                'success' => true,
                'data' => $ugsTransformadas,
                'total' => $ugsTransformadas->count()
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao listar UGs', [
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
                'nomeUsina' => 'required|string|max:255',
                'potenciaCC' => 'required|numeric|min:0',
                'fatorCapacidade' => 'required|numeric|min:0|max:100',
                'localizacao' => 'nullable|string|max:500',
                'observacoes' => 'nullable|string|max:1000',
            ], [
                'nomeUsina.required' => 'Nome da usina é obrigatório',
                'potenciaCC.required' => 'Potência CC é obrigatória',
                'potenciaCC.numeric' => 'Potência CC deve ser um número',
                'fatorCapacidade.required' => 'Fator de capacidade é obrigatório',
                'fatorCapacidade.numeric' => 'Fator de capacidade deve ser um número',
                'fatorCapacidade.max' => 'Fator de capacidade não pode ser maior que 100%',
            ]);

            // Calcular capacidade (720 horas * potência * fator / 100)
            $capacidade = 720 * $request->potenciaCC * ($request->fatorCapacidade / 100);

            $ug = UnidadeConsumidora::create([
                'usuario_id' => $currentUser->id,
                'nome_usina' => $request->nomeUsina,
                'potencia_cc' => $request->potenciaCC,
                'fator_capacidade' => $request->fatorCapacidade,
                'capacidade_calculada' => $capacidade,
                'localizacao' => $request->localizacao,
                'observacoes_ug' => $request->observacoes,
                'is_ug' => true,
                'ucs_atribuidas' => 0,
                'media_consumo_atribuido' => 0,
                // Campos obrigatórios da tabela
                'mesmo_titular' => false,
                'numero_cliente' => 0,
                'numero_unidade' => 0,
                'tipo' => 'UG',
                'gerador' => true,
                'service' => false,
                'project' => false,
                'nexus_clube' => false,
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
                ->where('is_ug', true)
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
                ->where('is_ug', true)
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
                ->where('is_ug', true)
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
            $query = UnidadeConsumidora::where('is_ug', true)
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