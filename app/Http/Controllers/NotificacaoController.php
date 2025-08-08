<?php

namespace App\Http\Controllers;

use App\Models\Notificacao;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Facades\JWTAuth;

class NotificacaoController extends Controller
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Listar notificações do usuário
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
            $query = Notificacao::query()
                               ->porUsuario($currentUser->id)
                               ->ordenadaPorData();

            // Filtros
            if ($request->filled('lida')) {
                if ($request->boolean('lida')) {
                    $query->lidas();
                } else {
                    $query->naoLidas();
                }
            }

            if ($request->filled('tipo')) {
                $query->porTipo($request->tipo);
            }

            if ($request->filled('recentes')) {
                $dias = $request->get('dias', 7);
                $query->recentes($dias);
            }

            // Paginação
            $perPage = min($request->get('per_page', 20), 100);
            $notificacoes = $query->paginate($perPage);

            // Transformar dados
            $notificacoes->getCollection()->transform(function ($notificacao) {
                return [
                    'id' => $notificacao->id,
                    'titulo' => $notificacao->titulo,
                    'descricao' => $notificacao->descricao,
                    'lida' => $notificacao->lida,
                    'tipo' => $notificacao->tipo,
                    'tipo_icon' => $notificacao->tipo_icon,
                    'tipo_color' => $notificacao->tipo_color,
                    'link' => $notificacao->link,
                    'tempo_decorrido' => $notificacao->tempo_decorrido,
                    'data_formatada' => $notificacao->data_formatada,
                    'created_at' => $notificacao->created_at->toISOString()
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $notificacoes
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao listar notificações', [
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
     * Marcar notificação como lida
     */
    public function markAsRead(string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        try {
            $notificacao = Notificacao::where('id', $id)
                                     ->where('usuario_id', $currentUser->id)
                                     ->first();

            if (!$notificacao) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notificação não encontrada'
                ], 404);
            }

            $foiMarcada = $notificacao->marcarComoLida();

            return response()->json([
                'success' => true,
                'message' => $foiMarcada ? 'Notificação marcada como lida' : 'Notificação já estava lida',
                'data' => [
                    'id' => $notificacao->id,
                    'lida' => $notificacao->lida
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao marcar notificação como lida', [
                'notificacao_id' => $id,
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
     * Marcar todas as notificações como lidas
     */
    public function markAllAsRead(): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        try {
            $totalMarcadas = Notificacao::marcarTodasLidasPorUsuario($currentUser->id);

            return response()->json([
                'success' => true,
                'message' => "Todas as notificações foram marcadas como lidas ({$totalMarcadas})",
                'data' => [
                    'total_marcadas' => $totalMarcadas
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao marcar todas as notificações como lidas', [
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
     * Excluir notificação
     */
    public function destroy(string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        try {
            $notificacao = Notificacao::where('id', $id)
                                     ->where('usuario_id', $currentUser->id)
                                     ->first();

            if (!$notificacao) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notificação não encontrada'
                ], 404);
            }

            $titulo = $notificacao->titulo;
            $notificacao->delete();

            return response()->json([
                'success' => true,
                'message' => "Notificação '{$titulo}' excluída com sucesso"
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao excluir notificação', [
                'notificacao_id' => $id,
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
     * Limpar notificações antigas
     */
    public function cleanupOld(Request $request): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Apenas admins podem fazer limpeza
        if (!$currentUser->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem limpar notificações antigas'
            ], 403);
        }

        try {
            $diasParaManter = $request->get('dias', 30);
            $totalRemovidas = Notificacao::limparAntigas($diasParaManter);

            \Log::info('Limpeza de notificações antigas', [
                'dias_mantidos' => $diasParaManter,
                'total_removidas' => $totalRemovidas,
                'user_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => "Limpeza concluída: {$totalRemovidas} notificação(ões) removida(s)",
                'data' => [
                    'total_removidas' => $totalRemovidas,
                    'dias_mantidos' => $diasParaManter
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao limpar notificações antigas', [
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
     * Estatísticas das notificações
     */
    public function statistics(): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        try {
            $estatisticas = Notificacao::getEstatisticasPorUsuario($currentUser->id);

            return response()->json([
                'success' => true,
                'data' => $estatisticas
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao buscar estatísticas de notificações', [
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