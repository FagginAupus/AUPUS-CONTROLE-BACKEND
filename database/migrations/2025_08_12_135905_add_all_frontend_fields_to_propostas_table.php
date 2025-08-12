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
            // ✅ CAMPOS BÁSICOS DO CLIENTE (que o frontend envia)
            $table->string('telefone', 20)->nullable()->after('consultor');
            $table->string('email', 200)->nullable()->after('telefone'); 
            $table->text('endereco')->nullable()->after('email');
            
            // ✅ DADOS DAS UCs (que vêm dentro de unidades_consumidoras)
            $table->json('unidades_consumidoras')->nullable()->after('beneficios');
            
            // ✅ CAMPOS CALCULADOS/DERIVADOS DAS UCs PARA FACILITAR CONSULTAS
            // (extraídos da primeira UC para campos de busca/listagem rápida)
            $table->bigInteger('numero_uc')->nullable()->after('unidades_consumidoras')->index();
            $table->string('apelido', 100)->nullable()->after('numero_uc');
            $table->decimal('media_consumo', 10, 2)->nullable()->after('apelido');
            $table->string('ligacao', 20)->nullable()->after('media_consumo');
            $table->string('distribuidora', 50)->nullable()->after('ligacao');
            
            // ✅ ÍNDICES PARA PERFORMANCE
            $table->index('telefone');
            $table->index('email');
            $table->index(['numero_uc', 'apelido']);
            $table->index('distribuidora');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('propostas', function (Blueprint $table) {
            // Remover índices primeiro
            $table->dropIndex(['propostas_telefone_index']);
            $table->dropIndex(['propostas_email_index']);
            $table->dropIndex(['propostas_numero_uc_apelido_index']);
            $table->dropIndex(['propostas_distribuidora_index']);
            $table->dropIndex(['propostas_numero_uc_index']);
            
            // Remover colunas
            $table->dropColumn([
                'telefone', 
                'email', 
                'endereco', 
                'unidades_consumidoras',
                'numero_uc',
                'apelido', 
                'media_consumo',
                'ligacao',
                'distribuidora'
            ]);
        });
    }
};