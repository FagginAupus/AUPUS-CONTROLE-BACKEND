<?php

namespace App\Http\Controllers;

use App\Models\UnidadeConsumidora;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class UGController extends Controller
{
    /**
     * ✅ OBTER QUANTIDADE DE UCs ATRIBUÍDAS A UMA UG
     */
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
        // Buscar calibragem global
        $calibragemGlobal = DB::selectOne(
            "SELECT valor FROM configuracoes WHERE chave = 'calibragem_global'"
        );
        
        $calibragemGlobalValor = floatval($calibragemGlobal->valor ?? 0);
        
        // Buscar todas as UCs atribuídas a esta UG com seus consumos
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

            // Query simplificada sem as colunas redundantes
            $query = "
                SELECT id, nome_usina, numero_unidade, potencia_cc, fator_capacidade, 
                    capacidade_calculada, localizacao, observacoes_ug,
                    created_at, updated_at
                FROM unidades_consumidoras 
                WHERE gerador = true 
                AND nexus_clube = true 
                AND deleted_at IS NULL
            ";
                        
            $params = [];

            if ($search) {
                $query .= " AND nome_usina ILIKE ?";
                $params[] = '%' . $search . '%';
            }

            $query .= " ORDER BY nome_usina ASC";

            $ugs = DB::select($query, $params);

            \Log::info('UGs encontradas', [
                'total' => count($ugs),
                'primeira_ug' => !empty($ugs) ? $ugs[0]->nome_usina : 'nenhuma'
            ]);

            $ugsTransformadas = [];

            foreach ($ugs as $ug) {
                // Calcular dinamicamente
                $ucsAtribuidas = $this->obterUcsAtribuidas($ug->id);
                $mediaConsumoAtribuido = $this->obterMediaConsumoAtribuido($ug->id);

                $ugsTransformadas[] = [
                    'id' => $ug->id,
                    'nomeUsina' => $ug->nome_usina, 
                    'numeroUnidade' => $ug->numero_unidade,
                    'potenciaCC' => (float) $ug->potencia_cc,
                    'fatorCapacidade' => (float) ($ug->fator_capacidade * 100),
                    'capacidade' => (float) $ug->capacidade_calculada,
                    'localizacao' => $ug->localizacao,
                    'observacoes' => $ug->observacoes_ug,
                    'ucsAtribuidas' => $ucsAtribuidas, // Calculado dinamicamente
                    'mediaConsumoAtribuido' => $mediaConsumoAtribuido, // Calculado dinamicamente
                    'dataCadastro' => $ug->created_at ? Carbon::parse($ug->created_at)->toISOString() : null,
                    'dataAtualizacao' => $ug->updated_at ? Carbon::parse($ug->updated_at)->toISOString() : null,
                ];
            }

            $response = [
                'success' => true,
                'data' => $ugsTransformadas,
                'total' => count($ugsTransformadas)
            ];

            \Log::info('=== UGController::index() CONCLUÍDO COM SUCESSO ===', [
                'total_retornado' => count($ugsTransformadas)
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
                'fator_capacidade' => 'required|numeric|min:1|max:100',
                'numero_unidade' => 'required|string|max:50',
                'apelido' => 'required|string|max:255',
                'localizacao' => 'nullable|string|max:255',
                'observacoes_ug' => 'nullable|string|max:1000',
                'gerador' => 'required|boolean',
                'nexus_clube' => 'required|boolean',
            ]);

            \Log::info('✅ Dados validados com sucesso:', $dadosValidados);

            // ✅ CRIAR UG usando ULID - SEM as colunas redundantes
            $ug = UnidadeConsumidora::create([
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'usuario_id' => (string) $currentUser->id,
                'concessionaria_id' => '01JB849ZDG0RPC5EB8ZFTB4GJN',
                'nome_usina' => $dadosValidados['nome_usina'],
                'potencia_cc' => (float) $dadosValidados['potencia_cc'],
                'fator_capacidade' => (float) ($dadosValidados['fator_capacidade'] / 100),
                'numero_unidade' => $dadosValidados['numero_unidade'],
                'apelido' => $dadosValidados['apelido'],
                'localizacao' => $dadosValidados['localizacao'] ?? '',
                'observacoes_ug' => $dadosValidados['observacoes_ug'] ?? '',
                'gerador' => $dadosValidados['gerador'],
                'nexus_clube' => $dadosValidados['nexus_clube'],
            
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
            ]);

            \Log::info('✅ UG criada com sucesso', [
                'ug_id' => $ug->id,
                'nome_usina' => $ug->nome_usina
            ]);

            // Transformar para frontend - CALCULADO DINAMICAMENTE
            $ugTransformada = [
                'id' => $ug->id,
                'nomeUsina' => $ug->nome_usina,
                'numeroUnidade' => $ug->numero_unidade,
                'potenciaCC' => (float) $ug->potencia_cc,
                'fatorCapacidade' => (float) $ug->fator_capacidade,
                'capacidade' => (float) $ug->capacidade_calculada,
                'localizacao' => $ug->localizacao,
                'observacoes' => $ug->observacoes_ug,
                'ucsAtribuidas' => 0, // Nova UG sempre começa com 0
                'mediaConsumoAtribuido' => 0.0, // Nova UG sempre começa com 0
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
                ->where('gerador', true)
                ->where('nexus_clube', true)
                ->whereNull('deleted_at')
                ->firstOrFail();

            $ugTransformada = [
                'id' => $ug->id,
                'nomeUsina' => $ug->nome_usina,
                'numeroUnidade' => $ug->numero_unidade,
                'potenciaCC' => (float) $ug->potencia_cc,
                'fatorCapacidade' => (float) ($ug->fator_capacidade * 100),
                'capacidade' => (float) $ug->capacidade_calculada,
                'localizacao' => $ug->localizacao,
                'observacoes' => $ug->observacoes_ug,
                'ucsAtribuidas' => $this->obterUcsAtribuidas($ug->id), // Calculado dinamicamente
                'mediaConsumoAtribuido' => $this->obterMediaConsumoAtribuido($ug->id), // Calculado dinamicamente
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
                ->where('gerador', true)
                ->where('nexus_clube', true)
                ->whereNull('deleted_at')
                ->firstOrFail();

            $request->validate([
                'nomeUsina' => 'sometimes|required|string|max:255',
                'potenciaCC' => 'sometimes|required|numeric|min:0',
                'fatorCapacidade' => 'sometimes|required|numeric|min:1|max:100',
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
                $dadosAtualizacao['fator_capacidade'] = $request->fatorCapacidade / 100;
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
                $fator = ($request->fatorCapacidade ?? ($ug->fator_capacidade * 100)) / 100;
                $dadosAtualizacao['capacidade_calculada'] = 720 * $potencia * $fator;
            }

            $ug->update($dadosAtualizacao);

            $ugTransformada = [
                'id' => $ug->id,
                'nomeUsina' => $ug->nome_usina,
                'potenciaCC' => (float) $ug->potencia_cc,
                'fatorCapacidade' => (float) ($ug->fator_capacidade * 100),
                'capacidade' => (float) $ug->capacidade_calculada,
                'localizacao' => $ug->localizacao,
                'observacoes' => $ug->observacoes_ug,
                'ucsAtribuidas' => $this->obterUcsAtribuidas($ug->id), // Calculado dinamicamente
                'mediaConsumoAtribuido' => $this->obterMediaConsumoAtribuido($ug->id), // Calculado dinamicamente
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

            // Verificar se há UCs atribuídas - CALCULADO DINAMICAMENTE
            $ucsAtribuidas = $this->obterUcsAtribuidas($id);
            if ($ucsAtribuidas > 0) {
                \Log::warning('UG possui UCs atribuídas', [
                    'ug_id' => $id,
                    'ucs_atribuidas' => $ucsAtribuidas
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível excluir UG com UCs atribuídas. Remova as UCs primeiro.'
                ], 400);
            }

            // Executar soft delete
            $agora = now()->format('Y-m-d H:i:s');
            
            $rowsAffected = DB::update("
                UPDATE unidades_consumidoras 
                SET deleted_at = ?, updated_at = ?
                WHERE id = ? AND deleted_at IS NULL
            ", [$agora, $agora, $id]);

            if ($rowsAffected === 0) {
                \Log::error('Nenhuma linha foi atualizada', ['ug_id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'UG não encontrada ou já excluída'
                ], 404);
            }

            \Log::info('UG excluída com sucesso', [
                'ug_id' => $id,
                'user_id' => $currentUser->id,
                'deleted_at' => $agora
            ]);

            return response()->json([
                'success' => true,
                'message' => 'UG excluída com sucesso'
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao excluir UG', [
                'ug_id' => $id,
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
     * Estatísticas das UGs
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            
            if (!$currentUser) {
                return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
            }

            $query = DB::table('unidades_consumidoras')
                       ->where('gerador', true)
                       ->where('nexus_clube', true) 
                       ->whereNull('deleted_at');

            // Aplicar filtros baseados na role do usuário
            if ($currentUser->role === 'admin') {
                // Admin vê tudo
            } else {
                if ($currentUser->role === 'gerente') {
                    $query->where('concessionaria_id', $currentUser->concessionaria_atual_id);
                } else {
                    $query->where('usuario_id', $currentUser->id);
                }
            }

            $ugs = $query->get();
            
            // Calcular estatísticas dinamicamente
            $totalUcsAtribuidas = 0;
            $totalMediaConsumo = 0;
            
            foreach ($ugs as $ug) {
                $totalUcsAtribuidas += $this->obterUcsAtribuidas($ug->id);
                $totalMediaConsumo += $this->obterMediaConsumoAtribuido($ug->id);
            }

            $stats = [
                'total' => $ugs->count(),
                'capacidadeTotal' => $ugs->sum('capacidade_calculada'),
                'ucsAtribuidas' => $totalUcsAtribuidas,
                'mediaConsumo' => $ugs->count() > 0 ? $totalMediaConsumo / $ugs->count() : 0,
                'potenciaTotal' => $ugs->sum('potencia_cc'),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao obter estatísticas UGs', [
                'user_id' => $currentUser->id ?? 'desconhecido',
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }
}