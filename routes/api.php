<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\PropostaController;
use App\Http\Controllers\UnidadeConsumidoraController;
use App\Http\Controllers\ControleController; // ADICIONADO
use App\Http\Controllers\ConfiguracaoController;
use App\Http\Controllers\NotificacaoController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ==========================================
// HEALTH CHECK - Verificar se API está funcionando
// ==========================================
Route::get('/health-check', function () {
    try {
        // Teste básico de conexão com o banco
        $dbConnection = \DB::connection()->getPdo();
        
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toISOString(),
            'version' => '1.0.0',
            'message' => 'Aupus Controle API está funcionando!',
            'database' => 'PostgreSQL conectado'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Erro na conexão com o banco: ' . $e->getMessage()
        ], 500);
    }
});

// Teste adicional de banco de dados
Route::get('test-db', function () {
    try {
        $result = \DB::select('SELECT version() as version');
        return response()->json([
            'status' => 'ok',
            'database_version' => $result[0]->version ?? 'Desconhecida',
            'timestamp' => now()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Erro na conexão com o banco: ' . $e->getMessage()
        ], 500);
    }
});

// ==========================================
// ROTAS PÚBLICAS (SEM AUTENTICAÇÃO)
// ==========================================
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
});

// ==========================================
// ROTAS PROTEGIDAS (REQUEREM AUTENTICAÇÃO JWT)
// ==========================================
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
        Route::get('equipe', [UsuarioController::class, 'getTeam']); // ← MANTER
        Route::get('team', [UsuarioController::class, 'getTeam']); // ← MANTER
        Route::get('statistics', [UsuarioController::class, 'statistics']);
        Route::get('{id}', [UsuarioController::class, 'show']);
        Route::put('{id}', [UsuarioController::class, 'update']);
        Route::patch('{id}/toggle-active', [UsuarioController::class, 'toggleActive']);
        Route::delete('{id}', [UsuarioController::class, 'destroy']);
        
        // MANTER ESTAS ROTAS (corrigi apenas o caminho):
        Route::post('invalidate-cache', [UsuarioController::class, 'invalidateTeamCache']); // ← SEM /usuarios/
        
        // Operações em lote
        Route::post('bulk-activate', [UsuarioController::class, 'bulkActivate']);
        Route::post('bulk-deactivate', [UsuarioController::class, 'bulkDeactivate']);
    });

    // ==========================================
    // PROPOSTAS - Gestão de propostas
    // ==========================================
    Route::prefix('propostas')->group(function () {
        Route::get('/', [PropostaController::class, 'index']);
        Route::post('/', [PropostaController::class, 'store']);
        Route::get('verificar-numero/{numero}', [PropostaController::class, 'verificarNumero']); // ✅ DEVE VIR ANTES DO {id}
        Route::get('statistics', [PropostaController::class, 'statistics']);
        Route::get('export', [PropostaController::class, 'export']);
        
        // ✅ ROTAS ESPECÍFICAS PRIMEIRO (antes das genéricas com {id})
        Route::post('bulk-update-status', [PropostaController::class, 'bulkUpdateStatus']);
        Route::post('{id}/upload-documento', [PropostaController::class, 'uploadDocumento']);
        Route::delete('{id}/documento/{tipo}', [PropostaController::class, 'removeDocumento']);
        Route::post('{id}/duplicate', [PropostaController::class, 'duplicate']);
        Route::post('{id}/convert-to-controle', [PropostaController::class, 'convertToControle']);
        
        // ✅ ROTAS GENÉRICAS POR ÚLTIMO
        Route::get('{id}', [PropostaController::class, 'show']);
        Route::put('{id}', [PropostaController::class, 'update']);
        Route::patch('{id}/status', [PropostaController::class, 'updateStatus']);
        Route::delete('{id}', [PropostaController::class, 'destroy']);
    });

    // ==========================================
    // ✅ CONTROLE CLUBE - ATUALIZADO COM NOVAS FUNCIONALIDADES
    // ==========================================
    Route::prefix('controle')->group(function () {
        Route::get('/', [ControleController::class, 'index']);
        Route::post('/', [ControleController::class, 'store']);
        Route::get('statistics', [ControleController::class, 'statistics']);
        
        // ✅ NOVAS ROTAS ESPECÍFICAS (ANTES das genéricas {id})
        Route::get('ugs-disponiveis', [ControleController::class, 'getUgsDisponiveis']);
        Route::patch('{id}/status-troca', [ControleController::class, 'updateStatusTroca']);
        Route::post('{id}/atribuir-ug', [ControleController::class, 'atribuirUg']);
        Route::patch('{id}/remover-ug', [ControleController::class, 'removerUg']);  // ⬅️ ADICIONAR ESTA LINHA
        
        // Rotas genéricas por último
        Route::get('{id}', [ControleController::class, 'show']);
        Route::put('{id}', [ControleController::class, 'update']);
        Route::delete('{id}', [ControleController::class, 'destroy']);
        
        // Operações especiais
        Route::post('bulk-calibragem', [ControleController::class, 'bulkCalibragem']);
        Route::post('bulk-toggle-status', [ControleController::class, 'bulkToggleStatus']);
    });

    // ==========================================
    // UNIDADES CONSUMIDORAS
    // ==========================================
    Route::prefix('unidades-consumidoras')->group(function () {
        Route::get('/', [UnidadeConsumidoraController::class, 'index']);
        Route::post('/', [UnidadeConsumidoraController::class, 'store']);
        Route::get('statistics', [UnidadeConsumidoraController::class, 'statistics']);
        Route::get('export', [UnidadeConsumidoraController::class, 'export']);
        Route::get('{id}', [UnidadeConsumidoraController::class, 'show']);
        Route::put('{id}', [UnidadeConsumidoraController::class, 'update']);
        Route::delete('{id}', [UnidadeConsumidoraController::class, 'destroy']);
        
        // Operações especiais
        Route::post('import', [UnidadeConsumidoraController::class, 'import']);
        Route::post('bulk-update', [UnidadeConsumidoraController::class, 'bulkUpdate']);
        Route::post('{id}/calculate-economy', [UnidadeConsumidoraController::class, 'calculateEconomy']);
    });

    // ==========================================
    // UGS (USINAS GERADORAS)
    // ==========================================

    Route::prefix('ugs')->group(function () {
        Route::get('/', [\App\Http\Controllers\UGController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\UGController::class, 'store']);
        Route::get('{id}', [\App\Http\Controllers\UGController::class, 'show']);
        Route::put('{id}', [\App\Http\Controllers\UGController::class, 'update']);
        Route::delete('{id}', [\App\Http\Controllers\UGController::class, 'destroy']); // ✅ VERIFICAR SE EXISTE
        Route::get('statistics', [\App\Http\Controllers\UGController::class, 'statistics']);
    });

    // CONFIGURAÇÕES - Configurações do sistema
    // ==========================================
    Route::prefix('configuracoes')->group(function () {
        Route::get('/', [ConfiguracaoController::class, 'index']);
        Route::post('/', [ConfiguracaoController::class, 'store']);
        Route::get('grupo/{grupo}', [ConfiguracaoController::class, 'getByGroup']);
        
        // ✅ ROTAS ESPECÍFICAS PRIMEIRO (antes das genéricas)
        Route::get('calibragem-global/value', [ConfiguracaoController::class, 'getCalibragemGlobal']);
        Route::post('bulk-update', [ConfiguracaoController::class, 'bulkUpdate']);
        Route::post('reset-group/{grupo}', [ConfiguracaoController::class, 'resetGroup']);
        
        // ✅ ROTAS POR CHAVE (corretas)
        Route::get('{chave}', [ConfiguracaoController::class, 'show']);
        Route::put('{chave}', [ConfiguracaoController::class, 'update']);
        
        // ✅ ROTAS POR ID (se você tiver um método destroy que usa ID)
        Route::delete('{id}', [ConfiguracaoController::class, 'destroy']);
    });

    // ==========================================
    // NOTIFICAÇÕES - Sistema de notificações
    // ==========================================
    Route::prefix('notificacoes')->group(function () {
        Route::get('/', [NotificacaoController::class, 'index']);
        Route::post('/', [NotificacaoController::class, 'store']);
        Route::patch('{id}/read', [NotificacaoController::class, 'markAsRead']);
        Route::post('mark-all-read', [NotificacaoController::class, 'markAllAsRead']);
        Route::delete('{id}', [NotificacaoController::class, 'destroy']);
        
        // Operações especiais
        Route::get('unread-count', [NotificacaoController::class, 'getUnreadCount']);
        Route::post('broadcast', [NotificacaoController::class, 'broadcast']);
    });

    // ==========================================
    // DASHBOARD - Dados gerais do dashboard
    // ==========================================
    Route::prefix('dashboard')->group(function () {
        Route::get('/', function (Request $request) {
            $user = $request->user();
            
            try {
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
                            'propostas' => [
                                'total' => \App\Models\Proposta::count(),
                                'fechadas' => \App\Models\Proposta::where('status', 'Fechado')->count(),
                                'aguardando' => \App\Models\Proposta::where('status', 'Aguardando')->count(),
                            ],
                            'controle' => [
                                'total' => \App\Models\ControleClube::count(),
                                'ativos' => \App\Models\ControleClube::where('ativo', true)->count(),
                                'inativos' => \App\Models\ControleClube::where('ativo', false)->count(),
                            ],
                            'unidades' => [
                                'total' => \App\Models\UnidadeConsumidora::count(),
                                'ugs' => \App\Models\UnidadeConsumidora::where('is_ug', true)->count(),
                                'ucs' => \App\Models\UnidadeConsumidora::where('is_ug', false)->count(),
                            ],
                            'usuarios' => [
                                'total' => \App\Models\Usuario::count(),
                                'ativos' => \App\Models\Usuario::where('is_active', true)->count(),
                            ]
                        ]
                    ]
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erro ao carregar dados do dashboard',
                    'error' => $e->getMessage()
                ], 500);
            }
        });
        
        Route::get('resumo', function (Request $request) {
            $user = $request->user();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'resumo_vendas' => [
                        'mes_atual' => \App\Models\Proposta::whereMonth('created_at', now()->month)->count(),
                        'mes_anterior' => \App\Models\Proposta::whereMonth('created_at', now()->subMonth()->month)->count(),
                    ],
                    'metas' => [
                        'propostas_meta' => 100,
                        'propostas_atual' => \App\Models\Proposta::whereMonth('created_at', now()->month)->count(),
                    ],
                    'top_consultores' => \App\Models\Usuario::withCount('propostas')
                        ->orderBy('propostas_count', 'desc')
                        ->take(5)
                        ->get()
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
                    'propostas' => \App\Models\Proposta::with(['usuario'])->get(),
                    'controle' => \App\Models\ControleClube::with(['usuario', 'proposta'])->get(),
                    'unidades' => \App\Models\UnidadeConsumidora::all(),
                    'generated_at' => now()->toISOString(),
                    'generated_by' => $user->nome
                ]
            ]);
        });
        
        // Relatório de performance
        Route::get('performance', function (Request $request) {
            return response()->json([
                'success' => true,
                'data' => [
                    'consultores_ranking' => \App\Models\Usuario::withCount(['propostas' => function ($query) {
                        $query->where('status', 'Fechado');
                    }])
                    ->orderBy('propostas_count', 'desc')
                    ->get(),
                    
                    'conversao_mensal' => \App\Models\Proposta::selectRaw('
                        EXTRACT(YEAR FROM created_at) as ano,
                        EXTRACT(MONTH FROM created_at) as mes,
                        COUNT(*) as total_propostas,
                        SUM(CASE WHEN status = "Fechado" THEN 1 ELSE 0 END) as fechadas
                    ')
                    ->groupByRaw('EXTRACT(YEAR FROM created_at), EXTRACT(MONTH FROM created_at)')
                    ->orderBy('ano', 'desc')
                    ->orderBy('mes', 'desc')
                    ->take(12)
                    ->get()
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
                    'app_name' => config('app.name'),
                    'app_version' => '1.0.0',
                    'laravel_version' => app()->version(),
                    'php_version' => PHP_VERSION,
                    'database' => [
                        'driver' => config('database.default'),
                        'version' => \DB::select('SELECT version() as version')[0]->version ?? 'Unknown'
                    ],
                    'environment' => config('app.env'),
                    'debug_mode' => config('app.debug'),
                    'timezone' => config('app.timezone'),
                    'locale' => config('app.locale'),
                    'url' => config('app.url')
                ]
            ]);
        });
        
        Route::get('health', function () {
            $checks = [];
            
            // Check database
            try {
                \DB::connection()->getPdo();
                $checks['database'] = ['status' => 'ok', 'message' => 'Connected'];
            } catch (\Exception $e) {
                $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
            }
            
            // Check storage
            try {
                \Storage::disk('local')->put('health-check.txt', 'test');
                \Storage::disk('local')->delete('health-check.txt');
                $checks['storage'] = ['status' => 'ok', 'message' => 'Writable'];
            } catch (\Exception $e) {
                $checks['storage'] = ['status' => 'error', 'message' => $e->getMessage()];
            }
            
            $overallStatus = collect($checks)->every(fn($check) => $check['status'] === 'ok') ? 'healthy' : 'unhealthy';
            
            return response()->json([
                'status' => $overallStatus,
                'timestamp' => now()->toISOString(),
                'checks' => $checks
            ], $overallStatus === 'healthy' ? 200 : 503);
        });
    });
});

// ==========================================
// FALLBACK - Rotas não encontradas
// ==========================================
Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'Rota não encontrada',
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
            'controle' => [
                'GET /api/controle',
                'POST /api/controle',
                'GET /api/controle/{id}',
                'PATCH /api/controle/{id}/status-troca',
                'POST /api/controle/{id}/atribuir-ug',
                'GET /api/controle/ugs-disponiveis'
            ],
            'unidades-consumidoras' => [
                'GET /api/unidades-consumidoras',
                'POST /api/unidades-consumidoras'
            ],
            'ugs' => [
                'GET /api/ugs',
                'POST /api/ugs',
                'GET /api/ugs/{id}'
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