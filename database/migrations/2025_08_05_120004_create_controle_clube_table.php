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
        Schema::create('controle_clube', function (Blueprint $table) {
            // Chave primária
            $table->string('id', 36)->primary()->comment('UUID do controle');
            
            // Relacionamentos principais
            $table->string('proposta_id', 36)
                  ->comment('ID da proposta fechada');
            $table->string('uc_id', 36)
                  ->comment('ID da unidade consumidora');
            $table->string('ug_id', 36)->nullable()
                  ->comment('ID da usina geradora atribuída (UC que é UG)');
            
            // Calibragem e valores
            $table->decimal('calibragem', 5, 2)->default(0.00)
                  ->comment('Percentual de calibragem aplicado');
            $table->decimal('valor_calibrado', 10, 2)->nullable()
                  ->comment('Valor após aplicação da calibragem');
            
            // Informações adicionais
            $table->text('observacoes')->nullable()
                  ->comment('Observações do controle');
            $table->timestamp('data_entrada_controle')->useCurrent()
                  ->comment('Data de entrada no controle');
            
            // Timestamps e soft deletes
            $table->timestamps();
            $table->softDeletes();
            
            // Índices para performance
            $table->index('proposta_id', 'idx_controle_proposta');
            $table->index('uc_id', 'idx_controle_uc');
            $table->index('ug_id', 'idx_controle_ug');
            $table->index('data_entrada_controle', 'idx_controle_data_entrada');
            
            // Constraint única (uma UC por proposta no controle)
            $table->unique(['proposta_id', 'uc_id'], 'unique_proposta_uc');
            
            // Foreign keys
            $table->foreign('proposta_id', 'fk_controle_proposta')
                  ->references('id')
                  ->on('propostas')
                  ->onDelete('cascade');
            $table->foreign('uc_id', 'fk_controle_uc')
                  ->references('id')
                  ->on('unidades_consumidoras')
                  ->onDelete('cascade');
            $table->foreign('ug_id', 'fk_controle_ug')
                  ->references('id')
                  ->on('unidades_consumidoras')
                  ->onDelete('set null');
        });
        
        // Comentário na tabela
        DB::statement("COMMENT ON TABLE controle_clube IS 'Controle de propostas fechadas com UCs e UGs atribuídas'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('controle_clube');
    }
};