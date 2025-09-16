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
use App\Http\Controllers\DocumentController;

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

// ✅ ADICIONE ESTA ROTA DE DEBUG AQUI:
Route::get('debug-session', function (Request $request) {
    \Log::info('=== DEBUG SESSION INICIADO ===');
    
    try {
        // 1. Verificar cabeçalho Authorization
        $authHeader = $request->header('Authorization');
        \Log::info('Authorization header:', ['header' => $authHeader]);
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'success' => false,
                'message' => 'Cabeçalho Authorization não encontrado ou inválido',
                'debug' => [
                    'auth_header' => $authHeader,
                    'all_headers' => $request->headers->all()
                ]
            ], 401);
        }
        
        $token = substr($authHeader, 7);
        \Log::info('Token extraído:', [
            'token_length' => strlen($token),
            'token_start' => substr($token, 0, 20),
            'token_end' => substr($token, -20)
        ]);
        
        // 2. Verificar configurações JWT
        \Log::info('Configurações JWT:', [
            'JWT_TTL' => config('jwt.ttl'),
            'JWT_REFRESH_TTL' => config('jwt.refresh_ttl'),
            'JWT_ALGO' => config('jwt.algo'),
            'JWT_BLACKLIST_ENABLED' => config('jwt.blacklist_enabled'),
            'JWT_BLACKLIST_GRACE_PERIOD' => config('jwt.blacklist_grace_period'),
            'JWT_SECRET' => substr(config('jwt.secret'), 0, 10) . '...'
        ]);
        
        // 3. Tentar parsear token manualmente
        try {
            $payload = JWTAuth::getJWTProvider()->decode($token);
            \Log::info('Token decodificado com sucesso:', [
                'payload' => $payload->toArray()
            ]);
            
            $exp = $payload->get('exp');
            $iat = $payload->get('iat');
            $now = time();
            
            \Log::info('Análise de tempo do token:', [
                'issued_at' => $iat,
                'expires_at' => $exp,
                'current_time' => $now,
                'token_age_seconds' => $now - $iat,
                'time_until_expiry' => $exp - $now,
                'is_expired' => $now >= $exp,
                'issued_at_formatted' => date('Y-m-d H:i:s', $iat),
                'expires_at_formatted' => date('Y-m-d H:i:s', $exp),
                'current_time_formatted' => date('Y-m-d H:i:s', $now)
            ]);
            
        } catch (\Exception $decodeError) {
            \Log::error('Erro ao decodificar token:', [
                'error' => $decodeError->getMessage(),
                'token_valid' => false
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Token mal formado',
                'debug' => [
                    'decode_error' => $decodeError->getMessage(),
                    'token_length' => strlen($token)
                ]
            ], 401);
        }
        
        // 4. Tentar usar JWTAuth::getPayload()
        try {
            $jwtPayload = JWTAuth::getPayload();
            \Log::info('JWTAuth::getPayload() funcionou:', [
                'payload_data' => $jwtPayload->toArray()
            ]);
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            \Log::warning('Token expirado via JWTAuth:', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Token expirado detectado pelo JWTAuth',
                'error_type' => 'token_expired',
                'requires_login' => true,
                'debug' => [
                    'exception' => $e->getMessage(),
                    'jwt_method' => 'JWTAuth::getPayload()'
                ]
            ], 401);
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            \Log::warning('Token inválido via JWTAuth:', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Token inválido detectado pelo JWTAuth',
                'error_type' => 'token_invalid',
                'requires_login' => true,
                'debug' => [
                    'exception' => $e->getMessage(),
                    'jwt_method' => 'JWTAuth::getPayload()'
                ]
            ], 401);
        } catch (\Exception $jwtError) {
            \Log::error('Erro genérico no JWTAuth:', [
                'error' => $jwtError->getMessage(),
                'class' => get_class($jwtError)
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro de JWT genérico',
                'error_type' => 'jwt_error',
                'requires_login' => true,
                'debug' => [
                    'exception' => $jwtError->getMessage(),
                    'exception_class' => get_class($jwtError)
                ]
            ], 401);
        }
        
        // 5. Se chegou até aqui, token está válido
        return response()->json([
            'success' => true,
            'message' => 'Token está válido!',
            'debug' => [
                'token_age_seconds' => time() - $iat,
                'time_until_expiry_seconds' => $exp - time(),
                'time_until_expiry_minutes' => round(($exp - time()) / 60, 2),
                'jwt_method_works' => true
            ]
        ]);
        
    } catch (\Exception $e) {
        \Log::error('Erro geral no debug:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Erro interno no debug',
            'debug' => [
                'error' => $e->getMessage()
            ]
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
        
        // ✅ ADICIONAR ESTAS DUAS LINHAS:
        Route::post('extend-session', [AuthController::class, 'extendSession']);
        Route::get('session-status', [AuthController::class, 'sessionStatus']);
        Route::get('check-default-password', [AuthController::class, 'checkDefaultPassword']);
        Route::post('change-default-password', [AuthController::class, 'changeDefaultPassword']);
    });

    // ==========================================
    // USUÁRIOS - Gestão de usuários
    // ==========================================
    Route::prefix('usuarios')->group(function () {
        Route::get('/', [UsuarioController::class, 'index']);
        Route::post('/', [UsuarioController::class, 'store']);
        Route::get('equipe', [UsuarioController::class, 'getTeam']); 
        Route::get('team', [UsuarioController::class, 'getTeam']); 
        Route::get('{id}/familia', [UsuarioController::class, 'getFamiliaConsultor']);
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
        Route::get('verificar-numero/{numero}', [PropostaController::class, 'verificarNumero']); 
        Route::get('statistics', [PropostaController::class, 'statistics']);
        Route::get('export', [PropostaController::class, 'export']);
        
        // ✅ ROTAS ESPECÍFICAS PRIMEIRO (antes das genéricas com {id})
        Route::post('bulk-update-status', [PropostaController::class, 'bulkUpdateStatus']);
        Route::post('{id}/upload-documento', [PropostaController::class, 'uploadDocumento']);
        Route::put('{id}/documentacao', [PropostaController::class, 'atualizarDocumentacao']);
        Route::get('{id}/arquivos', [PropostaController::class, 'listarArquivos']);   
        Route::delete('{id}/arquivo/{tipo}/{numeroUC?}', [PropostaController::class, 'removerArquivo']);
        Route::delete('{id}/documento/{tipo}', [PropostaController::class, 'removeDocumento']);
        Route::post('{id}/duplicate', [PropostaController::class, 'duplicate']);
        Route::post('{id}/convert-to-controle', [PropostaController::class, 'convertToControle']);
        
        // ✅ ROTAS GENÉRICAS POR ÚLTIMO
        Route::get('{id}', [PropostaController::class, 'show']);
        Route::put('{id}', [PropostaController::class, 'update']);
        Route::patch('{id}/status', [PropostaController::class, 'updateStatus']);
        Route::delete('{id}', [PropostaController::class, 'destroy']);

        Route::post('draft', [PropostaController::class, 'saveDraft']);
        Route::put('{id}/draft', [PropostaController::class, 'updateDraft']);
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
        Route::patch('{id}/remover-ug', [ControleController::class, 'removerUg']); 
        Route::get('{id}/uc-detalhes', [ControleController::class, 'getUCDetalhes']);
        Route::put('{id}/uc-detalhes', [ControleController::class, 'updateUCDetalhes']);;
        
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

    // ==========================================
    // DOCUMENTOS - Sistema de Assinatura Digital
    // ==========================================
    Route::prefix('documentos')->group(function () {
        
        // ✅ NOVAS ROTAS - ADICIONAR AQUI NO INÍCIO
        Route::post('propostas/{proposta}/gerar-pdf-apenas', [DocumentController::class, 'gerarPdfApenas']);
            //->middleware('permission:prospec.edit');
        
        Route::post('propostas/{proposta}/enviar-para-autentique', [DocumentController::class, 'enviarParaAutentique']);
           // ->middleware('permission:prospec.edit');

        Route::get('propostas/{proposta}/pdf-temporario', [DocumentController::class, 'verificarPdfTemporario']);

        Route::get('propostas/{proposta}/pdf-original', [DocumentController::class, 'buscarPDFOriginal'])
            ->middleware(['auth:api']);
            //->middleware('permission:prospec.view');  // Comentado temporariamente

        Route::post('propostas/{proposta}/gerar-termo', [DocumentController::class, 'gerarTermoAdesao'])
            ->middleware('permission:prospec.edit');

        Route::post('propostas/{proposta}/gerar-termo-completo', [DocumentController::class, 'gerarTermoCompleto']);
            
        // Finalizar documento após preenchimento no frontend
        Route::post('finalizar', [DocumentController::class, 'finalizarDocumento'])
            ->middleware('permission:prospec.edit');
            
        // Buscar status do documento de uma proposta
        Route::get('propostas/{proposta}/status', [DocumentController::class, 'buscarStatusDocumento']);
            //->middleware('permission:prospec.view');
        
        Route::get('propostas/{proposta}/pdf-assinado', [DocumentController::class, 'baixarPDFAssinado'])
            ->name('documentos.pdf-assinado');
            //->middleware('permission:prospec.view');

        Route::post('propostas/{proposta}/upload-termo-assinado', [DocumentController::class, 'uploadTermoAssinadoManual']);
            
        // Listar documentos de uma proposta
        Route::get('propostas/{proposta}', [DocumentController::class, 'listarDocumentosProposta'])
            ->middleware('permission:prospec.view');
            
        // Buscar documento específico
        Route::get('{documento}', [DocumentController::class, 'show'])
            ->middleware('permission:prospec.view');
            
        // Reenviar convite de assinatura
        Route::post('{documento}/reenviar', [DocumentController::class, 'reenviarConvite'])
            ->middleware('permission:prospec.edit');
            
        // Cancelar documento - ROTA JÁ EXISTE COM NOME DIFERENTE
        Route::delete('{documento}', [DocumentController::class, 'cancelarDocumento'])
            ->middleware('permission:prospec.delete');

    });
    
    Route::delete('/documentos/propostas/{proposta}/cancelar-pendente', [DocumentController::class, 'cancelarDocumentoPendente']);
        //->middleware('permission:prospec.edit');
    
});

// Webhook da Autentique (público - sem autenticação)
Route::post('/documentos/webhook/autentique', [DocumentController::class, 'webhook']);
Route::post('/webhook/autentique', [DocumentController::class, 'webhook']); // Rota alternativa



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