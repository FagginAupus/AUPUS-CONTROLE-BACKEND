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
        Schema::create('historico_rateios', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('ug_id', 36); // ID da unidade geradora
            $table->date('data_envio')->nullable(); // Data de envio do rateio
            $table->date('data_efetivacao')->nullable(); // Data de efetivação do rateio
            $table->string('arquivo_nome')->nullable(); // Nome do arquivo Excel/CSV
            $table->string('arquivo_path')->nullable(); // Caminho do arquivo armazenado
            $table->text('observacoes')->nullable(); // Observações
            $table->char('usuario_id', 36)->nullable(); // Usuário que cadastrou
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('ug_id')
                  ->references('id')
                  ->on('unidades_consumidoras')
                  ->onDelete('cascade');

            $table->foreign('usuario_id')
                  ->references('id')
                  ->on('usuarios')
                  ->onDelete('set null');

            // Indexes
            $table->index('ug_id');
            $table->index('data_envio');
            $table->index('data_efetivacao');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('historico_rateios');
    }
};
