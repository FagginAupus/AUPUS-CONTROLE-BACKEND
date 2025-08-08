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
        Schema::create('propostas', function (Blueprint $table) {
            // Chave primária
            $table->string('id', 36)->primary()->comment('UUID da proposta');
            
            // Dados básicos da proposta
            $table->string('numero_proposta', 50)->unique()
                  ->comment('Número único da proposta');
            $table->date('data_proposta')
                  ->comment('Data de criação da proposta');
            $table->string('nome_cliente', 200)
                  ->comment('Nome do cliente/empresa');
            $table->string('consultor', 100)
                  ->comment('Nome do consultor responsável');
            $table->string('usuario_id', 36)
                  ->comment('ID do usuário que criou a proposta');
            
            // Configurações financeiras
            $table->string('recorrencia', 10)->default('3%')
                  ->comment('Percentual de recorrência');
            $table->decimal('economia', 5, 2)->default(20.00)
                  ->comment('Percentual de economia prometida');
            $table->decimal('bandeira', 5, 2)->default(20.00)
                  ->comment('Percentual de economia na bandeira');
            
            // Status e controle
            $table->enum('status', ['Aguardando', 'Fechado', 'Perdido'])
                  ->default('Aguardando')
                  ->comment('Status atual da proposta');
            $table->text('observacoes')->nullable()
                  ->comment('Observações gerais da proposta');
            
            // Benefícios selecionados (JSON flexível)
            $table->json('beneficios')->nullable()
                  ->comment('Lista de benefícios selecionados para esta proposta');
            
            // Timestamps e soft deletes
            $table->timestamps();
            $table->softDeletes();
            
            // Índices para performance
            $table->index('numero_proposta', 'idx_propostas_numero');
            $table->index('status', 'idx_propostas_status');
            $table->index('consultor', 'idx_propostas_consultor');
            $table->index('data_proposta', 'idx_propostas_data');
            $table->index('usuario_id', 'idx_propostas_usuario');
            $table->index(['status', 'data_proposta'], 'idx_propostas_status_data');
            
            // Foreign keys
            $table->foreign('usuario_id', 'fk_propostas_usuario')
                  ->references('id')
                  ->on('usuarios')
                  ->onDelete('restrict');
        });
        
        // Comentário na tabela
        DB::statement("COMMENT ON TABLE propostas IS 'Tabela principal de propostas de energia solar'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('propostas');
    }
};