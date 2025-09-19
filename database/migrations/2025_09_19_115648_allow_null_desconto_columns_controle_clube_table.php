<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ✅ PERMITIR NULL nas colunas de desconto da controle_clube
     */
    public function up(): void
    {
        echo "🔄 Alterando colunas de desconto para permitir NULL...\n";

        Schema::table('controle_clube', function (Blueprint $table) {
            $table->string('desconto_tarifa', 10)->nullable()->change();
            $table->string('desconto_bandeira', 10)->nullable()->change();
        });

        echo "✅ Colunas alteradas com sucesso!\n";
        echo "💡 Agora NULL = usa desconto da proposta, valor = usa desconto individual\n";
    }

    /**
     * ✅ REVERTER as alterações se necessário
     */
    public function down(): void
    {
        echo "🔄 Revertendo colunas para NOT NULL...\n";

        // Primeiro, preencher os NULLs com valores padrão
        DB::statement("
            UPDATE controle_clube 
            SET desconto_tarifa = '20%' 
            WHERE desconto_tarifa IS NULL
        ");

        DB::statement("
            UPDATE controle_clube 
            SET desconto_bandeira = '20%' 
            WHERE desconto_bandeira IS NULL
        ");

        Schema::table('controle_clube', function (Blueprint $table) {
            $table->string('desconto_tarifa', 10)->nullable(false)->change();
            $table->string('desconto_bandeira', 10)->nullable(false)->change();
        });

        echo "✅ Colunas revertidas para NOT NULL!\n";
    }
};