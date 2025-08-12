<?php

namespace App\Http\Controllers;

use App\Models\Notificacao;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class NotificacaoController extends Controller implements HasMiddleware
{
    /**
     * Get the middleware that should be assigned to the controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('auth:api'),
        ];
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
     * Criar nova notificação
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

        // Apenas admins podem criar notificações manuais
        if (!$currentUser->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas administradores podem criar notificações'
            ], 403);
        }

        $validator = \Validator::make($request->all(), [
            'usuario_id' => 'required|exists:usuarios,id',
            'titulo' => 'required|string|max:200',
            'descricao' => 'required|string|max:1000',
            'tipo' => 'required|in:info,success,warning,error,sistema',
            'link' => 'nullable|string|max:500'
        ], [
            'usuario_id.required' => 'Usuário é obrigatório',
            'usuario_id.exists' => 'Usuário não encontrado',
            'titulo.required' => 'Título é obrigatório',
            'descricao.required' => 'Descrição é obrigatória',
            'tipo.required' => 'Tipo é obrigatório',
            'tipo.in' => 'Tipo deve ser: info, success, warning, error ou sistema'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $notificacao = Notificacao::create([
                'usuario_id' => $request->usuario_id,
                'titulo' => $request->titulo,
                'descricao' => $request->descricao,
                'tipo' => $request->tipo,
                'link' => $request->link,
                'lida' => false
            ]);

            \Log::info('Notificação criada manualmente', [
                'notificacao_id' => $notificacao->id,
                'usuario_destino' => $request->usuario_id,
                'tipo' => $request->tipo,
                'created_by' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Notificação criada com sucesso!',
                'data' => [
                    'id' => $notificacao->id,
                    'titulo' => $notificacao->titulo,
                    'descricao' => $notificacao->descricao,
                    'tipo' => $notificacao->tipo,
                    'lida' => $notificacao->lida,
                    'created_at' => $notificacao->created_at->format('Y-m-d H:i:s')
                ]
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Erro ao criar notificação', [
                'user_id' => $currentUser->id,
                'request_data' => $request->except(['password', 'token']),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor ao criar notificação'
            ], 500);
        }
    }

    /**
     * Exibir notificação específica
     */
    public function show(string $id): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        try {
            $notificacao = Notificacao::findOrFail($id);

            // Verificar se a notificação pertence ao usuário ou se é admin
            if ($notificacao->usuario_id !== $currentUser->id && !$currentUser->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => [
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
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Notificação não encontrada'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erro ao buscar notificação', [
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
            $notificacao = Notificacao::findOrFail($id);

            // Verificar se a notificação pertence ao usuário
            if ($notificacao->usuario_id !== $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            if ($notificacao->lida) {
                return response()->json([
                    'success' => true,
                    'message' => 'Notificação já estava marcada como lida'
                ]);
            }

            $notificacao->marcarComoLida();

            return response()->json([
                'success' => true,
                'message' => 'Notificação marcada como lida!'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Notificação não encontrada'
            ], 404);
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
            $totalMarcadas = Notificacao::porUsuario($currentUser->id)
                                       ->naoLidas()
                                       ->update(['lida' => true]);

            \Log::info('Todas as notificações marcadas como lidas', [
                'user_id' => $currentUser->id,
                'total_marcadas' => $totalMarcadas
            ]);

            return response()->json([
                'success' => true,
                'message' => "Todas as {$totalMarcadas} notificações foram marcadas como lidas!"
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
            $notificacao = Notificacao::findOrFail($id);

            // Verificar se a notificação pertence ao usuário ou se é admin
            if ($notificacao->usuario_id !== $currentUser->id && !$currentUser->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }

            $titulo = $notificacao->titulo;
            $notificacao->delete();

            \Log::info('Notificação excluída', [
                'notificacao_id' => $id,
                'titulo' => $titulo,
                'deleted_by' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Notificação excluída com sucesso!'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Notificação não encontrada'
            ], 404);
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
    public function cleanupOld(): JsonResponse
    {
        $currentUser = JWTAuth::user();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Apenas o próprio usuário pode limpar suas notificações antigas
        try {
            $diasLimpeza = 30; // Remover notificações lidas com mais de 30 dias
            $totalRemoções = Notificacao::porUsuario($currentUser->id)
                                      ->lidas()
                                      ->where('created_at', '<', now()->subDays($diasLimpeza))
                                      ->delete();

            \Log::info('Limpeza de notificações antigas', [
                'user_id' => $currentUser->id,
                'total_removidas' => $totalRemoções,
                'dias_limpeza' => $diasLimpeza
            ]);

            return response()->json([
                'success' => true,
                'message' => "Limpeza concluída! {$totalRemoções} notificações antigas foram removidas."
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro na limpeza de notificações antigas', [
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
     * Estatísticas de notificações
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
            $query = Notificacao::porUsuario($currentUser->id);

            $stats = [
                'total_notificacoes' => $query->count(),
                'nao_lidas' => $query->naoLidas()->count(),
                'lidas' => $query->lidas()->count(),
                'por_tipo' => $query->selectRaw('tipo, COUNT(*) as total')
                                  ->groupBy('tipo')
                                  ->pluck('total', 'tipo')
                                  ->toArray(),
                'recentes_7_dias' => $query->recentes(7)->count(),
                'recentes_30_dias' => $query->recentes(30)->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
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