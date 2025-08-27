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
        Schema::table('usuarios', function (Blueprint $table) {
            // Adicionar campo PIX depois da coluna 'cep'
            $table->string('pix', 255)->nullable()->after('cep')
                  ->comment('Chave PIX para consultores (obrigatória) e opcional para gerentes/vendedores');
            
            // Índice para consultas por PIX
            $table->index('pix', 'idx_usuarios_pix');
        });
        
        // Comentário na tabela
        DB::statement("COMMENT ON COLUMN usuarios.pix IS 'Chave PIX: CPF, CNPJ, email, telefone ou chave aleatória'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            // Remover índice
            $table->dropIndex('idx_usuarios_pix');
            
            // Remover coluna
            $table->dropColumn('pix');
        });
    }
};