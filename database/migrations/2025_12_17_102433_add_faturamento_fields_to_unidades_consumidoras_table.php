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
            // Campos de faturamento
            $table->string('nome_faturamento', 200)->nullable()->after('apelido');
            $table->string('cpf_cnpj_faturamento', 20)->nullable()->after('nome_faturamento');
            $table->string('whatsapp_faturamento', 20)->nullable()->after('cpf_cnpj_faturamento');
            $table->string('email_faturamento_1', 255)->nullable()->after('whatsapp_faturamento');
            $table->string('email_faturamento_2', 255)->nullable()->after('email_faturamento_1');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            $table->dropColumn([
                'nome_faturamento',
                'cpf_cnpj_faturamento',
                'whatsapp_faturamento',
                'email_faturamento_1',
                'email_faturamento_2'
            ]);
        });
    }
};
