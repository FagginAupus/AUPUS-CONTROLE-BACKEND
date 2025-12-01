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
        Schema::table('controle_clube', function (Blueprint $table) {
            // Campos de identificação do cliente (independentes da proposta)
            $table->string('nome_cliente', 200)->nullable()->after('ug_id');
            $table->string('apelido_uc', 100)->nullable()->after('nome_cliente');
            $table->string('cpf_cnpj', 18)->nullable()->after('apelido_uc');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('controle_clube', function (Blueprint $table) {
            $table->dropColumn(['nome_cliente', 'apelido_uc', 'cpf_cnpj']);
        });
    }
};
