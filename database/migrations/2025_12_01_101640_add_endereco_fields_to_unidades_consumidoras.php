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
            // Campos de endereço (independentes da tabela enderecos)
            $table->string('endereco_completo', 300)->nullable()->after('localizacao');
            $table->string('bairro', 100)->nullable()->after('endereco_completo');
            $table->string('cidade', 100)->nullable()->after('bairro');
            $table->string('estado', 2)->nullable()->after('cidade');
            $table->string('cep', 10)->nullable()->after('estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            $table->dropColumn(['endereco_completo', 'bairro', 'cidade', 'estado', 'cep']);
        });
    }
};
