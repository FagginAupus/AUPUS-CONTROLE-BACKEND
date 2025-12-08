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
        Schema::create('associados', function (Blueprint $table) {
            // ID como UUID/ULID (padrão do sistema)
            $table->string('id', 36)->primary();

            // Dados do Associado
            $table->string('nome', 200);
            $table->string('cpf_cnpj', 18)->unique();
            $table->string('whatsapp', 20)->nullable();
            $table->string('email', 255)->nullable();

            // Dados de Endereço
            $table->string('endereco', 255)->nullable();
            $table->string('bairro', 100)->nullable();
            $table->string('cidade', 100)->nullable();
            $table->string('estado', 2)->nullable();
            $table->string('cep', 10)->nullable();

            // Consultor responsável
            $table->string('consultor_id', 36)->nullable();
            $table->foreign('consultor_id')
                  ->references('id')
                  ->on('usuarios')
                  ->onDelete('set null');

            // Auditoria
            $table->timestamps();
            $table->softDeletes();

            // Índices
            $table->index('cpf_cnpj');
            $table->index('nome');
        });

        // Adicionar coluna associado_id nas tabelas relacionadas
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            $table->string('associado_id', 36)->nullable()->after('usuario_id');
            $table->foreign('associado_id')
                  ->references('id')
                  ->on('associados')
                  ->onDelete('set null');
            $table->index('associado_id');
        });

        Schema::table('controle_clube', function (Blueprint $table) {
            $table->string('associado_id', 36)->nullable()->after('uc_id');
            $table->foreign('associado_id')
                  ->references('id')
                  ->on('associados')
                  ->onDelete('set null');
            $table->index('associado_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remover foreign keys primeiro
        Schema::table('controle_clube', function (Blueprint $table) {
            $table->dropForeign(['associado_id']);
            $table->dropColumn('associado_id');
        });

        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            $table->dropForeign(['associado_id']);
            $table->dropColumn('associado_id');
        });

        Schema::dropIfExists('associados');
    }
};
