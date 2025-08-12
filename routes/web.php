<?php

use Illuminate\Support\Facades\Route;

// Rota principal - retorna uma resposta simples para aplicações SPA
Route::get('/', function () {
    return response()->json([
        'message' => 'Aupus Controle API',
        'version' => '2.0',
        'frontend' => 'React SPA',
        'api_base_url' => config('app.url') . '/api',
        'status' => 'active'
    ]);
});

// ✅ ADICIONANDO A ROTA LOGIN QUE ESTAVA FALTANDO
// Esta rota é necessária para evitar o erro "Route [login] not defined"
Route::name('login')->get('/login', function () {
    return response()->json([
        'message' => 'Esta é uma aplicação SPA. Use a API para autenticação.',
        'api_login_url' => config('app.url') . '/api/auth/login',
        'method' => 'POST',
        'required_fields' => ['email', 'password']
    ], 401);
});

// Rota de fallback para SPA (Single Page Application)
// Redireciona todas as rotas não encontradas para o index do React
Route::fallback(function () {
    // Em produção, você pode servir seu index.html aqui
    return response()->json([
        'message' => 'Rota não encontrada - Esta é uma API REST',
        'documentation' => config('app.url') . '/api/documentation',
        'available_endpoints' => [
            'auth' => config('app.url') . '/api/auth',
            'propostas' => config('app.url') . '/api/propostas', 
            'controle' => config('app.url') . '/api/controle',
            'ugs' => config('app.url') . '/api/ugs',
            'usuarios' => config('app.url') . '/api/usuarios'
        ]
    ], 404);
});

// Rotas para desenvolvimento - apenas se não estiver em produção
if (!app()->environment('production')) {
    
    // Rota de teste para verificar se a aplicação está funcionando
    Route::get('/test', function () {
        return response()->json([
            'message' => 'Aplicação funcionando corretamente',
            'environment' => app()->environment(),
            'timestamp' => now()->toISOString(),
            'database' => [
                'connection' => config('database.default'),
                'status' => 'connected'
            ]
        ]);
    });
    
    // Rota de informações do sistema
    Route::get('/info', function () {
        return response()->json([
            'app' => [
                'name' => config('app.name'),
                'version' => '2.0',
                'environment' => app()->environment(),
                'debug' => config('app.debug'),
                'timezone' => config('app.timezone'),
                'locale' => config('app.locale'),
            ],
            'database' => [
                'default' => config('database.default'),
                'host' => config('database.connections.' . config('database.default') . '.host'),
                'database' => config('database.connections.' . config('database.default') . '.database'),
            ],
            'cache' => [
                'driver' => config('cache.default'),
            ],
            'session' => [
                'driver' => config('session.driver'),
                'lifetime' => config('session.lifetime'),
            ]
        ]);
    });
}