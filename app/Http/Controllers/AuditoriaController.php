<?php

namespace App\Http\Controllers;

use App\Services\AuditoriaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\DB;

class AuditoriaController extends Controller
{
    /**
     * Listar eventos de auditoria
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

            // Verificar se o usuário tem permissão para ver auditoria (admin/analista)
            if (!in_array($currentUser->role, ['admin', 'analista'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            $page = (int) $request->get('page', 1);
            $perPage = min((int) $request->get('per_page', 50), 100); // Máximo 100
            $offset = ($page - 1) * $perPage;

            // Filtros
            $modulo = $request->get('modulo');
            $eventoTipo = $request->get('evento_tipo');
            $usuarioId = $request->get('usuario_id');
            $dataInicio = $request->get('data_inicio');
            $dataFim = $request->get('data_fim');
            $eventoCritico = $request->get('evento_critico');

            // Query base
            $query = DB::table('auditoria as a')
                ->leftJoin('usuarios as u', 'a.usuario_id', '=', 'u.id')
                ->select([
                    'a.id',
                    'a.entidade',
                    'a.entidade_id',
                    'a.acao',
                    'a.sub_acao',
                    'a.evento_tipo',
                    'a.descricao_evento',
                    'a.modulo',
                    'a.dados_contexto',
                    'a.evento_critico',
                    'a.usuario_id',
                    'u.nome as usuario_nome',
                    'a.ip_address',
                    'a.data_acao',
                    'a.observacoes'
                ]);

            // Aplicar filtros
            if ($modulo) {
                $query->where('a.modulo', $modulo);
            }

            if ($eventoTipo) {
                $query->where('a.evento_tipo', $eventoTipo);
            }

            if ($usuarioId) {
                $query->where('a.usuario_id', $usuarioId);
            }

            if ($dataInicio) {
                $query->where('a.data_acao', '>=', $dataInicio);
            }

            if ($dataFim) {
                $query->where('a.data_acao', '<=', $dataFim);
            }

            if ($eventoCritico !== null) {
                $query->where('a.evento_critico', (bool) $eventoCritico);
            }

            // Contar total
            $total = $query->count();

            // Buscar registros paginados
            $eventos = $query
                ->orderBy('a.data_acao', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get()
                ->map(function ($evento) {
                    // Decodificar JSON
                    $evento->dados_contexto = json_decode($evento->dados_contexto, true);
                    return $evento;
                });

            return response()->json([
                'success' => true,
                'data' => $eventos,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => ceil($total / $perPage)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar eventos de auditoria: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obter eventos críticos recentes
     */
    public function eventosCriticos(): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();

            if (!$currentUser || !in_array($currentUser->role, ['admin', 'analista'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            $eventos = AuditoriaService::obterEventosCriticos(20);

            return response()->json([
                'success' => true,
                'data' => $eventos
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar eventos críticos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obter eventos por módulo
     */
    public function eventosPorModulo(string $modulo): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();

            if (!$currentUser || !in_array($currentUser->role, ['admin', 'analista'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            $eventos = AuditoriaService::obterEventosPorModulo($modulo, null, null, 50);

            return response()->json([
                'success' => true,
                'data' => $eventos,
                'modulo' => $modulo
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar eventos do módulo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obter histórico de uma entidade específica
     */
    public function historicoEntidade(string $entidade, string $entidadeId): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();

            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Admin/Analista podem ver todos os históricos
            // Outros usuários só podem ver histórico das próprias entidades
            if (!in_array($currentUser->role, ['admin', 'analista'])) {
                // Verificar se a entidade pertence ao usuário (simplificado)
                if ($entidade === 'propostas') {
                    $proposta = DB::selectOne("SELECT consultor_id FROM propostas WHERE id = ?", [$entidadeId]);
                    if (!$proposta || $proposta->consultor_id !== $currentUser->id) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Acesso negado'
                        ], 403);
                    }
                }
            }

            $historico = AuditoriaService::obterHistorico($entidade, $entidadeId, 30);

            return response()->json([
                'success' => true,
                'data' => $historico,
                'entidade' => $entidade,
                'entidade_id' => $entidadeId
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar histórico: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obter estatísticas de auditoria
     */
    public function estatisticas(): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();

            if (!$currentUser || !in_array($currentUser->role, ['admin', 'analista'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            $stats = DB::select("
                SELECT
                    modulo,
                    evento_tipo,
                    COUNT(*) as total,
                    COUNT(CASE WHEN evento_critico = true THEN 1 END) as criticos,
                    DATE(data_acao) as data
                FROM auditoria
                WHERE data_acao >= NOW() - INTERVAL '7 days'
                GROUP BY modulo, evento_tipo, DATE(data_acao)
                ORDER BY data DESC, total DESC
                LIMIT 50
            ");

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar estatísticas: ' . $e->getMessage()
            ], 500);
        }
    }
}