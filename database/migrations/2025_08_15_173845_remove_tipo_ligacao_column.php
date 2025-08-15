<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ✅ Primeiro, migrar dados da coluna tipo_ligacao para ligacao (se houver)
        DB::statement("
            UPDATE unidades_consumidoras 
            SET ligacao = tipo_ligacao 
            WHERE ligacao IS NULL AND tipo_ligacao IS NOT NULL
        ");
        
        // ✅ Remover a coluna duplicada tipo_ligacao
        if (Schema::hasColumn('unidades_consumidoras', 'tipo_ligacao')) {
            Schema::table('unidades_consumidoras', function (Blueprint $table) {
                $table->dropColumn('tipo_ligacao');
            });
        }
        
        echo "✅ Coluna 'tipo_ligacao' removida com sucesso\n";
        echo "✅ Dados migrados para coluna 'ligacao'\n";
    }

    public function down(): void
    {
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            $table->string('tipo_ligacao', 50)->nullable()
                  ->comment('Tipo de ligação elétrica (COLUNA DUPLICADA)');
        });
    }
};