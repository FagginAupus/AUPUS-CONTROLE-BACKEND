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
        // TABELA: propostas - ATUALIZAR CAMPO CONSULTOR
        // ===============================================
        
        Schema::table('propostas', function (Blueprint $table) {
            // Remover índice existente antes de dropar coluna
            $table->dropIndex('idx_propostas_consultor');
            
            // Remover campo consultor (nome)
            $table->dropColumn('consultor');
            
            // Adicionar campo consultor_id (foreign key)
            $table->string('consultor_id', 36)->nullable()->after('nome_cliente')
                  ->comment('ID do consultor responsável');
            
            // Criar novo índice
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
            
            // Remover consultor_id
            $table->dropColumn('consultor_id');
            
            // Restaurar campo consultor (nome)
            $table->string('consultor', 100)->after('nome_cliente')
                  ->comment('Nome do consultor responsável');
            
            // Restaurar índice
            $table->index('consultor', 'idx_propostas_consultor');
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
            DROP CONSTRAINT IF EXISTS chk_ug_fields
        ');
        
        DB::statement('
            ALTER TABLE unidades_consumidoras 
            ADD CONSTRAINT chk_ug_fields 
            CHECK (
                (gerador = false) OR 
                (gerador = true AND 
                 nome_usina IS NOT NULL AND 
                 potencia_cc IS NOT NULL AND 
                 fator_capacidade IS NOT NULL)
            )
        ');
    }
};