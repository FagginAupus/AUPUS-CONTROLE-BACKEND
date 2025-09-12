<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Rotas existentes
Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return view('welcome');
})->name('login');

Route::get('/test', function () {
    return 'Backend funcionando! ' . now();
});

// ✅ ROTA PRINCIPAL PARA SERVIR ARQUIVOS DE PROPOSTAS
Route::get('/storage/propostas/{tipo}/{filename}', function ($tipo, $filename) {
    $path = "propostas/{$tipo}/{$filename}";
    
    // Verificar se arquivo existe no disco público
    if (!Storage::disk('public')->exists($path)) {
        Log::warning('Arquivo não encontrado', [
            'path' => $path,
            'tipo' => $tipo,
            'filename' => $filename,
            'full_path' => Storage::disk('public')->path($path)
        ]);
        abort(404, 'Arquivo não encontrado');
    }
    
    // Retornar o arquivo com headers apropriados
    $response = Storage::disk('public')->response($path);
    
    // Adicionar headers de CORS
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
    $response->headers->set('Access-Control-Allow-Headers', 'Origin, Content-Type, Accept, Authorization');
    
    return $response;
})->where(['tipo' => '(documentos|faturas)', 'filename' => '.*']);

// ✅ ROTA DE FALLBACK PARA COMPATIBILIDADE
Route::get('/storage/{path}', function ($path) {
    // Verificar se é arquivo de propostas
    if (str_starts_with($path, 'propostas/')) {
        if (!Storage::disk('public')->exists($path)) {
            abort(404, 'Arquivo não encontrado');
        }
        
        $response = Storage::disk('public')->response($path);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        return $response;
    }
    
    // Para outros arquivos, usar comportamento padrão
    abort(404, 'Arquivo não encontrado');
})->where('path', '.*');

// ✅ ROTA DE DEBUG PARA TESTAR
Route::get('/debug-storage', function () {
    $propostas_path = 'propostas/documentos';
    $files = [];
    
    try {
        $files = Storage::disk('public')->files($propostas_path);
    } catch (\Exception $e) {
        // Diretório não existe
    }
    
    return response()->json([
        'status' => 'ok',
        'storage_config' => [
            'public_root' => config('filesystems.disks.public.root'),
            'public_url' => config('filesystems.disks.public.url'),
            'default_disk' => config('filesystems.default')
        ],
        'directories' => [
            'public_exists' => Storage::disk('public')->exists(''),
            'propostas_exists' => Storage::disk('public')->exists('propostas'),
            'documentos_exists' => Storage::disk('public')->exists('propostas/documentos'),
            'faturas_exists' => Storage::disk('public')->exists('propostas/faturas')
        ],
        'files_in_documentos' => array_slice($files, 0, 10),
        'symlink' => [
            'exists' => file_exists(public_path('storage')),
            'is_link' => is_link(public_path('storage')),
            'target' => is_link(public_path('storage')) ? readlink(public_path('storage')) : null
        ],
        'test_urls' => [
            'base_url' => config('app.url'),
            'example_doc' => config('app.url') . '/storage/propostas/documentos/exemplo.pdf'
        ]
    ]);
});

// Rota de informações do sistema
Route::get('/info', function () {
    return response()->json([
        'app_name' => config('app.name'),
        'app_url' => config('app.url'),
        'environment' => config('app.env'),
        'database' => [
            'driver' => config('database.default'),
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

Route::get('/download/propostas/{tipo}/{filename}', function ($tipo, $filename) {
    $path = "propostas/{$tipo}/{$filename}";
    
    // Verificar se arquivo existe no disco público
    if (!Storage::disk('public')->exists($path)) {
        Log::warning('Arquivo não encontrado para download', [
            'path' => $path,
            'tipo' => $tipo,
            'filename' => $filename
        ]);
        abort(404, 'Arquivo não encontrado');
    }
    
    // Obter caminho completo do arquivo
    $caminhoCompleto = Storage::disk('public')->path($path);
    
    // ✅ FORÇAR DOWNLOAD com headers apropriados
    return response()->download($caminhoCompleto, $filename, [
        'Content-Type' => Storage::disk('public')->mimeType($path),
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, OPTIONS',
        'Access-Control-Allow-Headers' => 'Origin, Content-Type, Accept, Authorization'
    ]);
    
})->where(['tipo' => '(documentos|faturas)', 'filename' => '.*']);

Route::get('/storage/templates/{filename}', function ($filename) {
    $path = storage_path('app/templates/' . $filename);
    
    if (!file_exists($path)) {
        abort(404, 'Template PDF não encontrado: ' . $filename);
    }
    
    return response()->file($path, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'inline; filename="' . $filename . '"',
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, OPTIONS',
        'Access-Control-Allow-Headers' => 'Origin, Content-Type, Accept, Authorization'
    ]);
})->where('filename', '.*\.pdf$');