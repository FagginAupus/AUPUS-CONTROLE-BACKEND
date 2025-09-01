<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ===============================================
        // TABELA: propostas - ADICIONAR CAMPO CONSULTOR_ID (MANTER CONSULTOR)
        // ===============================================
        
        Schema::table('propostas', function (Blueprint $table) {
            // Adicionar campo consultor_id (foreign key) MANTENDO o campo consultor existente
            $table->string('consultor_id', 36)->nullable()->after('consultor')
                  ->comment('ID do consultor responsável (FK para usuarios)');
            
            // Criar índice para o novo campo
            $table->index('consultor_id', 'idx_propostas_consultor_id');
            
            // Foreign key para usuarios
            $table->foreign('consultor_id', 'fk_propostas_consultor')
                  ->references('id')
                  ->on('usuarios')
                  ->onDelete('set null');
        });

        // ===============================================
        // TABELA: unidades_consumidoras - REMOVER E ADICIONAR CAMPOS
        // ===============================================
        
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            // Remover campos desnecessários
            $table->dropColumn([
                'ucs_atribuidas',
                'media_consumo_atribuido'
            ]);
            
            // Adicionar campo potencia_ca
            $table->decimal('potencia_ca', 10, 2)->nullable()->after('potencia_cc')
                  ->comment('Potência CA em kWp (para UGs)');
        });

        // ===============================================
        // CONSTRAINT PARA UGs (OPCIONAL - GARANTIR INTEGRIDADE)
        // ===============================================
        
        DB::statement('
            ALTER TABLE unidades_consumidoras 
            ADD CONSTRAINT chk_ug_fields_updated
            CHECK (
                (is_ug = false) OR 
                (is_ug = true AND 
                 nome_usina IS NOT NULL AND 
                 potencia_cc IS NOT NULL AND 
                 fator_capacidade IS NOT NULL)
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ===============================================
        // REVERTER TABELA: propostas
        // ===============================================
        
        Schema::table('propostas', function (Blueprint $table) {
            // Remover foreign key e índice
            $table->dropForeign('fk_propostas_consultor');
            $table->dropIndex('idx_propostas_consultor_id');
            
            // Remover apenas o campo consultor_id (mantém consultor original)
            $table->dropColumn('consultor_id');
        });

        // ===============================================
        // REVERTER TABELA: unidades_consumidoras
        // ===============================================
        
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            // Remover potencia_ca
            $table->dropColumn('potencia_ca');
            
            // Restaurar campos removidos
            $table->integer('ucs_atribuidas')->default(0)->after('observacoes_ug')
                  ->comment('Quantidade UCs atribuídas');
            $table->decimal('media_consumo_atribuido', 10, 2)->default(0)->after('ucs_atribuidas')
                  ->comment('Média consumo UCs');
        });

        // ===============================================
        // REVERTER CONSTRAINT
        // ===============================================
        
        DB::statement('
            ALTER TABLE unidades_consumidoras 
            DROP CONSTRAINT IF EXISTS chk_ug_fields_updated
        ');
    }
};