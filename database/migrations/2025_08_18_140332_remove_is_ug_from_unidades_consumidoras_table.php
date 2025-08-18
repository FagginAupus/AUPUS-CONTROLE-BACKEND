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
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            // Sincronizar os dados existentes usando valores boolean
            // Onde is_ug = true, definir gerador = true
            DB::statement("UPDATE unidades_consumidoras SET gerador = true WHERE is_ug = true");
            
            // Onde is_ug = false, definir gerador = false  
            DB::statement("UPDATE unidades_consumidoras SET gerador = false WHERE is_ug = false");
        });
        
        // Verificar se o índice existe antes de tentar removê-lo
        $indexes = DB::select("
            SELECT indexname 
            FROM pg_indexes 
            WHERE tablename = 'unidades_consumidoras' 
            AND indexname = 'unidades_consumidoras_is_ug_index'
        ");
        
        Schema::table('unidades_consumidoras', function (Blueprint $table) use ($indexes) {
            // Remover índice relacionado ao is_ug se existir
            if (!empty($indexes)) {
                $table->dropIndex(['is_ug']);
            }
            
            // Remover o campo duplicado
            $table->dropColumn('is_ug');
        });
        
        // Criar novo índice para gerador
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            $table->index('gerador', 'idx_uc_gerador');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            // Recriar o campo is_ug
            $table->boolean('is_ug')->default(false)->after('gerador');
        });
        
        // Restaurar os dados
        DB::statement("UPDATE unidades_consumidoras SET is_ug = gerador");
        
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            // Recriar índice para is_ug
            $table->index('is_ug', 'idx_uc_is_ug');
            
            // Remover o índice do gerador
            $table->dropIndex(['gerador']);
        });
    }
};