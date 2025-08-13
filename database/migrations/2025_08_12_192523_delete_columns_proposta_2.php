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
        Schema::table('propostas', function (Blueprint $table) {
            // Tentar remover índice 'telefone'
            try {
                $table->dropIndex(['telefone']);
            } catch (\Throwable $e) {
                // Índice pode não existir, então ignoramos o erro
            }

            // Tentar remover índice 'email'
            try {
                $table->dropIndex(['email']);
            } catch (\Throwable $e) {
                // Índice pode não existir, então ignoramos o erro
            }

            // Remover colunas
            $table->dropColumn(['telefone', 'email', 'endereco']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('propostas', function (Blueprint $table) {
            // Recriar as colunas
            $table->string('telefone', 20)->nullable()->after('consultor');
            $table->string('email', 200)->nullable()->after('telefone');
            $table->text('endereco')->nullable()->after('email');

            // Recriar índices
            $table->index('telefone');
            $table->index('email');
        });
    }
};
