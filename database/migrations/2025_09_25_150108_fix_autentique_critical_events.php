<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Corrigir registros de auditoria onde envio de termo para Autentique foi marcado incorretamente como crítico
        DB::table('auditoria')
            ->where('evento_tipo', 'TERMO_ENVIADO_AUTENTIQUE')
            ->where('evento_critico', true)
            ->update([
                'evento_critico' => false
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverter os registros de volta ao estado crítico (caso necessário)
        DB::table('auditoria')
            ->where('evento_tipo', 'TERMO_ENVIADO_AUTENTIQUE')
            ->where('evento_critico', false)
            ->update([
                'evento_critico' => true
            ]);
    }
};
