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
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            // Campos para integração com propostas
            $table->string('proposta_id', 36)->nullable()
                  ->comment('ID da proposta vinculada');
            $table->string('distribuidora', 100)->nullable()
                  ->comment('Nome da distribuidora de energia');
            $table->string('tipo_ligacao', 50)->nullable()
                  ->comment('Tipo de ligação elétrica');
            $table->decimal('valor_fatura', 10, 2)->nullable()
                  ->comment('Valor médio da fatura mensal');
            
            // CAMPOS PARA UGs (Usinas Geradoras)
            $table->boolean('is_ug')->default(false)
                  ->comment('Se esta UC é uma Usina Geradora');
            $table->string('nome_usina', 200)->nullable()
                  ->comment('Nome da usina geradora (quando is_ug=true)');
            $table->decimal('potencia_cc', 10, 2)->nullable()
                  ->comment('Potência CC da usina em kWp');
            $table->decimal('fator_capacidade', 5, 2)->nullable()
                  ->comment('Fator de capacidade da usina em %');
            $table->decimal('capacidade_calculada', 10, 2)->nullable()
                  ->comment('Capacidade calculada em kWh/mês (720h * potencia_cc * fator_capacidade/100)');
            $table->string('localizacao', 200)->nullable()
                  ->comment('Localização da usina geradora');
            $table->text('observacoes_ug')->nullable()
                  ->comment('Observações específicas da UG');
            
            // Controle de UCs atribuídas (para UGs)
            $table->integer('ucs_atribuidas')->default(0)
                  ->comment('Quantidade de UCs atribuídas a esta UG');
            $table->decimal('media_consumo_atribuido', 10, 2)->default(0)
                  ->comment('Média de consumo das UCs atribuídas');
            
            // Índices para performance
            $table->index('proposta_id', 'idx_uc_proposta');
            $table->index('is_ug', 'idx_uc_is_ug');
            $table->index('distribuidora', 'idx_uc_distribuidora');
            $table->index(['is_ug', 'nome_usina'], 'idx_ug_nome');
        });
        
        // Comentários
        DB::statement("COMMENT ON COLUMN unidades_consumidoras.is_ug IS 'Flag que indica se esta UC é uma Usina Geradora'");
        DB::statement("COMMENT ON COLUMN unidades_consumidoras.capacidade_calculada IS 'Capacidade mensal: 720h * potencia_cc * (fator_capacidade/100)'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            // Remover índices
            $table->dropIndex('idx_uc_proposta');
            $table->dropIndex('idx_uc_is_ug');
            $table->dropIndex('idx_uc_distribuidora');
            $table->dropIndex('idx_ug_nome');
            
            // Remover colunas
            $table->dropColumn([
                'proposta_id', 'distribuidora', 'tipo_ligacao', 'valor_fatura',
                'is_ug', 'nome_usina', 'potencia_cc', 'fator_capacidade', 
                'capacidade_calculada', 'localizacao', 'observacoes_ug',
                'ucs_atribuidas', 'media_consumo_atribuido'
            ]);
        });
    }
};