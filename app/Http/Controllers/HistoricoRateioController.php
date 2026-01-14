<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use App\Models\HistoricoRateio;

class HistoricoRateioController extends Controller
{
    /**
     * Listar histórico de rateios
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 50);
            $ugId = $request->get('ug_id');

            $query = DB::table('historico_rateios as hr')
                ->leftJoin('unidades_consumidoras as ug', 'hr.ug_id', '=', 'ug.id')
                ->leftJoin('usuarios as u', 'hr.usuario_id', '=', 'u.id')
                ->whereNull('hr.deleted_at')
                ->select([
                    'hr.id',
                    'hr.ug_id',
                    'ug.nome_usina',
                    'ug.numero_unidade',
                    'hr.data_envio',
                    'hr.data_efetivacao',
                    'hr.arquivo_nome',
                    'hr.arquivo_path',
                    'hr.observacoes',
                    'hr.usuario_id',
                    'u.name as usuario_nome',
                    'hr.created_at',
                    'hr.updated_at'
                ])
                ->orderBy('hr.created_at', 'desc');

            if ($ugId) {
                $query->where('hr.ug_id', $ugId);
            }

            $rateios = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $rateios->items(),
                'pagination' => [
                    'current_page' => $rateios->currentPage(),
                    'last_page' => $rateios->lastPage(),
                    'per_page' => $rateios->perPage(),
                    'total' => $rateios->total()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar histórico de rateios', [
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar histórico de rateios'
            ], 500);
        }
    }

    /**
     * Criar novo registro de histórico de rateio
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();

            if (!$currentUser) {
                return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
            }

            $validator = Validator::make($request->all(), [
                'ug_id' => 'required|string|exists:unidades_consumidoras,id',
                'data_envio' => 'nullable|date',
                'data_efetivacao' => 'nullable|date',
                'observacoes' => 'nullable|string|max:500',
                'arquivo' => 'nullable|file|mimes:xlsx,xls,csv|max:10240' // 10MB max
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $id = (string) Str::ulid();
            $arquivoNome = null;
            $arquivoPath = null;

            // Upload do arquivo se enviado
            if ($request->hasFile('arquivo')) {
                $arquivo = $request->file('arquivo');
                $arquivoNome = $arquivo->getClientOriginalName();
                $arquivoPath = $arquivo->storeAs(
                    'rateios/' . date('Y/m'),
                    $id . '_' . $arquivoNome,
                    'public'
                );
            }

            DB::table('historico_rateios')->insert([
                'id' => $id,
                'ug_id' => $request->ug_id,
                'data_envio' => $request->data_envio,
                'data_efetivacao' => $request->data_efetivacao,
                'arquivo_nome' => $arquivoNome,
                'arquivo_path' => $arquivoPath,
                'observacoes' => $request->observacoes,
                'usuario_id' => $currentUser->id,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            Log::info('Histórico de rateio criado', [
                'id' => $id,
                'ug_id' => $request->ug_id,
                'usuario' => $currentUser->name
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Histórico de rateio criado com sucesso',
                'data' => ['id' => $id]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Erro ao criar histórico de rateio', [
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar histórico de rateio: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Atualizar registro de histórico de rateio
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();

            if (!$currentUser) {
                return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
            }

            $rateio = DB::table('historico_rateios')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (!$rateio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Registro não encontrado'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'data_envio' => 'nullable|date',
                'data_efetivacao' => 'nullable|date',
                'observacoes' => 'nullable|string|max:500',
                'arquivo' => 'nullable|file|mimes:xlsx,xls,csv|max:10240'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $dadosAtualizacao = [
                'updated_at' => now()
            ];

            if ($request->has('data_envio')) {
                $dadosAtualizacao['data_envio'] = $request->data_envio;
            }

            if ($request->has('data_efetivacao')) {
                $dadosAtualizacao['data_efetivacao'] = $request->data_efetivacao;
            }

            if ($request->has('observacoes')) {
                $dadosAtualizacao['observacoes'] = $request->observacoes;
            }

            // Upload de novo arquivo
            if ($request->hasFile('arquivo')) {
                // Remover arquivo antigo se existir
                if ($rateio->arquivo_path) {
                    Storage::disk('public')->delete($rateio->arquivo_path);
                }

                $arquivo = $request->file('arquivo');
                $arquivoNome = $arquivo->getClientOriginalName();
                $arquivoPath = $arquivo->storeAs(
                    'rateios/' . date('Y/m'),
                    $id . '_' . $arquivoNome,
                    'public'
                );

                $dadosAtualizacao['arquivo_nome'] = $arquivoNome;
                $dadosAtualizacao['arquivo_path'] = $arquivoPath;
            }

            DB::table('historico_rateios')
                ->where('id', $id)
                ->update($dadosAtualizacao);

            Log::info('Histórico de rateio atualizado', [
                'id' => $id,
                'usuario' => $currentUser->name
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Histórico de rateio atualizado com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao atualizar histórico de rateio', [
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar histórico de rateio'
            ], 500);
        }
    }

    /**
     * Excluir registro de histórico de rateio (soft delete)
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();

            if (!$currentUser) {
                return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
            }

            $rateio = DB::table('historico_rateios')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (!$rateio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Registro não encontrado'
                ], 404);
            }

            // Soft delete
            DB::table('historico_rateios')
                ->where('id', $id)
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now()
                ]);

            Log::info('Histórico de rateio excluído', [
                'id' => $id,
                'usuario' => $currentUser->name
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Histórico de rateio excluído com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao excluir histórico de rateio', [
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao excluir histórico de rateio'
            ], 500);
        }
    }

    /**
     * Download do arquivo de rateio
     */
    public function downloadArquivo(string $id): JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            $rateio = DB::table('historico_rateios')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (!$rateio || !$rateio->arquivo_path) {
                return response()->json([
                    'success' => false,
                    'message' => 'Arquivo não encontrado'
                ], 404);
            }

            $path = Storage::disk('public')->path($rateio->arquivo_path);

            if (!file_exists($path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Arquivo não encontrado no servidor'
                ], 404);
            }

            return response()->download($path, $rateio->arquivo_nome);

        } catch (\Exception $e) {
            Log::error('Erro ao baixar arquivo de rateio', [
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao baixar arquivo'
            ], 500);
        }
    }

    /**
     * Listar UGs para seleção no formulário
     */
    public function listarUGs(): JsonResponse
    {
        try {
            $ugs = DB::table('unidades_consumidoras')
                ->where('gerador', true)
                ->whereNull('deleted_at')
                ->select(['id', 'nome_usina', 'numero_unidade'])
                ->orderBy('nome_usina')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $ugs
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar UGs', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar UGs'
            ], 500);
        }
    }
}
