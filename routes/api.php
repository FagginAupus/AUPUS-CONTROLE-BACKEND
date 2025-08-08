
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\PropostaController;
use App\Http\Controllers\UnidadeConsumidoraController;
use App\Http\Controllers\ControleController;
use App\Http\Controllers\ConfiguracaoController;
use App\Http\Controllers\NotificacaoController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// ==========================================
// ROTAS PÚBLICAS (SEM AUTENTICAÇÃO)
// ==========================================

// Health check simples
Route::get('/health-check', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now(),
        'version' => '1.0.0',
        'message' => 'Aupus Controle API está funcionando!',
        'database' => 'PostgreSQL conectado'
    ]);
});

// Teste de conexão com banco
Route::get('/test-db', function () {
    try {
        \DB::connection()->getPdo();
        return response()->json([
            'status' => 'success',
            'database' => 'PostgreSQL conectado com sucesso!',
            'timestamp' => now()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Erro na conexão com o banco: ' . $e->getMessage()
        ], 500);
    }
});

// Rotas públicas (sem autenticação)
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
});

// Rotas protegidas (requerem autenticação JWT)
Route::middleware('auth:api')->group(function () {
    
    // ==========================================
    // AUTH - Autenticação
    // ==========================================
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });

    // ==========================================
    // USUÁRIOS - Gestão de usuários
    // ==========================================
    Route::prefix('usuarios')->group(function () {
        Route::get('/', [UsuarioController::class, 'index']);
        Route::post('/', [UsuarioController::class, 'store']);
        Route::get('team', [UsuarioController::class, 'getTeam']);
        Route::get('{id}', [UsuarioController::class, 'show']);
        Route::put('{id}', [UsuarioController::class, 'update']);
        Route::patch('{id}/toggle-active', [UsuarioController::class, 'toggleActive']);
    });

    // ==========================================
    // PROPOSTAS - Gestão de propostas
    // ==========================================
    Route::prefix('propostas')->group(function () {
        Route::get('/', [PropostaController::class, 'index']);
        Route::post('/', [PropostaController::class, 'store']);
        Route::get('statistics', [PropostaController::class, 'statistics']);
        Route::get('export', [PropostaController::class, 'export']);
        Route::get('{id}', [PropostaController::class, 'show']);
        Route::put('{id}', [PropostaController::class, 'update']);
        Route::patch('{id}/change-status', [PropostaController::class, 'changeStatus']);
        Route::delete('{id}', [PropostaController::class, 'destroy']);
    });

    // ==========================================
    // UNIDADES CONSUMIDORAS - UCs e UGs
    // ==========================================
    Route::prefix('unidades-consumidoras')->group(function () {
        Route::get('/', [UnidadeConsumidoraController::class, 'index']);
        Route::post('/', [UnidadeConsumidoraController::class, 'store']);
        Route::get('{id}', [UnidadeConsumidoraController::class, 'show']);
        Route::put('{id}', [UnidadeConsumidoraController::class, 'update']);
        Route::delete('{id}', [UnidadeConsumidoraController::class, 'destroy']);
        
        // Operações específicas para UGs
        Route::post('{id}/convert-to-ug', [UnidadeConsumidoraController::class, 'convertToUG']);
        Route::post('{id}/revert-to-uc', [UnidadeConsumidoraController::class, 'revertToUC']);
        Route::post('{id}/apply-calibragem', [UnidadeConsumidoraController::class, 'applyCalibragem']);
    });

    // ==========================================
    // CONTROLE CLUBE - Gestão do clube
    // ==========================================
    Route::prefix('controle')->group(function () {
        Route::get('/', [ControleController::class, 'index']);
        Route::post('/', [ControleController::class, 'store']);
        Route::get('statistics', [ControleController::class, 'statistics']);
        Route::get('{id}', [ControleController::class, 'show']);
        
        // Operações de calibragem
        Route::post('{id}/apply-calibragem', [ControleController::class, 'applyCalibragem']);
        Route::post('global-calibragem', [ControleController::class, 'applyGlobalCalibragem']);
        
        // Operações de UG
        Route::post('{id}/link-ug', [ControleController::class, 'linkUG']);
        Route::post('{id}/unlink-ug', [ControleController::class, 'unlinkUG']);
        
        // Operações de ativação
        Route::post('{id}/deactivate', [ControleController::class, 'deactivate']);
        Route::post('{id}/reactivate', [ControleController::class, 'reactivate']);
    });

    // ==========================================
    // CONFIGURAÇÕES - Settings do sistema
    // ==========================================
    Route::prefix('configuracoes')->group(function () {
        Route::get('/', [ConfiguracaoController::class, 'index']);
        Route::post('/', [ConfiguracaoController::class, 'store']);
        Route::get('grupo/{grupo}', [ConfiguracaoController::class, 'getByGroup']);
        Route::get('export', [ConfiguracaoController::class, 'export']);
        Route::get('{chave}', [ConfiguracaoController::class, 'show']);
        Route::put('{chave}', [ConfiguracaoController::class, 'update']);
        
        // Operações em lote
        Route::post('bulk-update', [ConfiguracaoController::class, 'updateBulk']);
        Route::post('reset-to-default', [ConfiguracaoController::class, 'resetToDefault']);
        Route::post('clear-cache', [ConfiguracaoController::class, 'clearCache']);
    });

    // ==========================================
    // NOTIFICAÇÕES - Sistema de notificações
    // ==========================================
    Route::prefix('notificacoes')->group(function () {
        Route::get('/', [NotificacaoController::class, 'index']);
        Route::get('statistics', [NotificacaoController::class, 'statistics']);
        Route::patch('{id}/mark-read', [NotificacaoController::class, 'markAsRead']);
        Route::patch('mark-all-read', [NotificacaoController::class, 'markAllAsRead']);
        Route::delete('{id}', [NotificacaoController::class, 'destroy']);
        Route::delete('cleanup-old', [NotificacaoController::class, 'cleanupOld']);
    });

    // ==========================================
    // DASHBOARD - Dados gerais do dashboard
    // ==========================================
    Route::prefix('dashboard')->group(function () {
        Route::get('/', function (Request $request) {
            $user = $request->user();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'nome' => $user->nome,
                        'email' => $user->email,
                        'role' => $user->role
                    ],
                    'statistics' => [
                        'propostas' => \App\Models\Proposta::getEstatisticas(['usuario' => $user]),
                        'controle' => \App\Models\ControleClube::getEstatisticas(['usuario' => $user]),
                        'unidades' => \App\Models\UnidadeConsumidora::getEstatisticas(['usuario' => $user]),
                        'notifications' => \App\Models\Notificacao::getEstatisticasPorUsuario($user->id)
                    ]
                ]
            ]);
        });
    });

    // ==========================================
    // RELATÓRIOS - Exportação de dados
    // ==========================================
    Route::prefix('relatorios')->group(function () {
        // Relatório geral
        Route::get('geral', function (Request $request) {
            $user = $request->user();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'propostas' => \App\Models\Proposta::comFiltroHierarquico($user)->with(['usuario', 'unidadesConsumidoras'])->get(),
                    'controle' => \App\Models\ControleClube::comFiltroHierarquico($user)->with(['usuario', 'proposta'])->get(),
                    'unidades' => \App\Models\UnidadeConsumidora::comFiltroHierarquico($user)->with(['usuario', 'proposta'])->get(),
                    'gerado_em' => now()->format('d/m/Y H:i:s'),
                    'gerado_por' => $user->nome
                ]
            ]);
        });
        
        // Relatório de performance por consultor
        Route::get('performance', function (Request $request) {
            $user = $request->user();
            
            if (!$user->isAdmin() && !$user->isConsultor()) {
                return response()->json(['success' => false, 'message' => 'Acesso negado'], 403);
            }
            
            $query = \App\Models\Proposta::query()->comFiltroHierarquico($user);
            
            if ($request->filled('periodo_inicio') && $request->filled('periodo_fim')) {
                $query->whereBetween('created_at', [
                    \Carbon\Carbon::parse($request->periodo_inicio)->startOfDay(),
                    \Carbon\Carbon::parse($request->periodo_fim)->endOfDay()
                ]);
            }
            
            $performance = $query->selectRaw("
                    consultor,
                    COUNT(*) as total_propostas,
                    COUNT(CASE WHEN status = 'Fechado' THEN 1 END) as fechadas,
                    COUNT(CASE WHEN status = 'Aguardando' THEN 1 END) as aguardando,
                    COUNT(CASE WHEN status = 'Perdido' THEN 1 END) as perdidas,
                    ROUND(
                        (COUNT(CASE WHEN status = 'Fechado' THEN 1 END)::float / COUNT(*)) * 100, 
                        2
                    ) as taxa_fechamento
                ")
                ->groupBy('consultor')
                ->orderByDesc('fechadas')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $performance,
                'meta' => [
                    'periodo' => [
                        'inicio' => $request->periodo_inicio ?? 'Início',
                        'fim' => $request->periodo_fim ?? 'Agora'
                    ],
                    'total_consultores' => $performance->count(),
                    'gerado_em' => now()->format('d/m/Y H:i:s')
                ]
            ]);
        });
        
        // Relatório de UGs e capacidade
        Route::get('ugs', function (Request $request) {
            $user = $request->user();
            
            if (!$user->isAdmin() && !$user->isConsultor()) {
                return response()->json(['success' => false, 'message' => 'Acesso negado'], 403);
            }
            
            $ugs = \App\Models\UnidadeConsumidora::query()
                ->comFiltroHierarquico($user)
                ->UGs()
                ->with(['usuario', 'proposta', 'controleClube'])
                ->get();
            
            $estatisticas = [
                'total_ugs' => $ugs->count(),
                'potencia_total' => $ugs->sum('potencia_cc'),
                'capacidade_total' => $ugs->sum('capacidade_calculada'),
                'ugs_vinculadas' => $ugs->filter(function($ug) {
                    return $ug->controleClube->count() > 0;
                })->count(),
                'fator_capacidade_medio' => $ugs->avg('fator_capacidade')
            ];
            
            return response()->json([
                'success' => true,
                'data' => $ugs,
                'statistics' => $estatisticas,
                'meta' => [
                    'gerado_em' => now()->format('d/m/Y H:i:s'),
                    'gerado_por' => $user->nome
                ]
            ]);
        });
    });

    // ==========================================
    // SISTEMA - Informações do sistema
    // ==========================================
    Route::prefix('sistema')->group(function () {
        Route::get('info', function () {
            return response()->json([
                'success' => true,
                'data' => [
                    'nome' => \App\Models\Configuracao::getEmpresaNome(),
                    'versao' => \App\Models\Configuracao::getSistemaVersao(),
                    'ambiente' => config('app.env'),
                    'timezone' => config('app.timezone'),
                    'database' => config('database.default'),
                    'cache_driver' => config('cache.default'),
                    'timestamp' => now()->toISOString()
                ]
            ]);
        });
        
        Route::get('health', function () {
            try {
                // Testar conexão com banco
                \DB::connection()->getPdo();
                $dbStatus = 'ok';
            } catch (\Exception $e) {
                $dbStatus = 'error';
            }
            
            // Testar cache
            try {
                \Cache::put('health_check', 'ok', 10);
                $cacheTest = \Cache::get('health_check');
                $cacheStatus = $cacheTest === 'ok' ? 'ok' : 'error';
            } catch (\Exception $e) {
                $cacheStatus = 'error';
            }
            
            $overallStatus = ($dbStatus === 'ok' && $cacheStatus === 'ok') ? 'healthy' : 'unhealthy';
            
            return response()->json([
                'success' => true,
                'status' => $overallStatus,
                'checks' => [
                    'database' => $dbStatus,
                    'cache' => $cacheStatus,
                    'timestamp' => now()->toISOString()
                ]
            ]);
        });
    });
});

// ==========================================
// FALLBACK - Rota para URLs não encontradas
// ==========================================
Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'Endpoint não encontrado',
        'available_endpoints' => [
            'auth' => [
                'POST /api/auth/login',
                'POST /api/auth/register',
                'POST /api/auth/logout',
                'GET /api/auth/me'
            ],
            'usuarios' => [
                'GET /api/usuarios',
                'POST /api/usuarios',
                'GET /api/usuarios/{id}'
            ],
            'propostas' => [
                'GET /api/propostas',
                'POST /api/propostas',
                'GET /api/propostas/{id}'
            ],
            'unidades-consumidoras' => [
                'GET /api/unidades-consumidoras',
                'POST /api/unidades-consumidoras'
            ],
            'controle' => [
                'GET /api/controle',
                'POST /api/controle'
            ],
            'configuracoes' => [
                'GET /api/configuracoes',
                'GET /api/configuracoes/grupo/{grupo}'
            ],
            'dashboard' => [
                'GET /api/dashboard'
            ],
            'sistema' => [
                'GET /api/sistema/info',
                'GET /api/sistema/health'
            ]
        ]
    ], 404);
});