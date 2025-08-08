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
        Schema::create('configuracoes', function (Blueprint $table) {
            // Chave primária
            $table->string('id', 36)->primary()->comment('UUID da configuração');
            
            // Dados da configuração
            $table->string('chave', 50)->unique()
                  ->comment('Chave única da configuração');
            $table->text('valor')
                  ->comment('Valor da configuração (pode ser JSON)');
            $table->enum('tipo', ['string', 'number', 'boolean', 'json'])
                  ->default('string')
                  ->comment('Tipo do valor para validação');
            $table->text('descricao')->nullable()
                  ->comment('Descrição da configuração');
            $table->string('grupo', 50)->default('geral')
                  ->comment('Grupo da configuração (geral, calibragem, sistema, propostas)');
            
            // Controle de alterações
            $table->string('updated_by', 36)->nullable()
                  ->comment('ID do usuário que fez a última alteração');
            
            // Timestamps
            $table->timestamps();
            
            // Índices para performance
            $table->index('chave', 'idx_config_chave');
            $table->index('grupo', 'idx_config_grupo');
            $table->index('updated_by', 'idx_config_updated_by');
            
            // Foreign key
            $table->foreign('updated_by', 'fk_config_updated_by')
                  ->references('id')
                  ->on('usuarios')
                  ->onDelete('set null');
        });
        
        // Comentário na tabela
        DB::statement("COMMENT ON TABLE configuracoes IS 'Configurações globais do sistema'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configuracoes');
    }
};