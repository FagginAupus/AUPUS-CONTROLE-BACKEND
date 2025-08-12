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
            // ✅ REMOVER campos individuais desnecessários
            $table->dropColumn([
                'numero_uc',
                'apelido', 
                'media_consumo',
                'ligacao',
                'distribuidora'
            ]);
        });
        
        Schema::table('propostas', function (Blueprint $table) {
            // ✅ CORRIGIR tipo da coluna unidades_consumidoras
            $table->dropColumn('unidades_consumidoras');
        });
        
        Schema::table('propostas', function (Blueprint $table) {
            // ✅ ADICIONAR como JSON
            $table->json('unidades_consumidoras')->nullable()->after('beneficios');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('propostas', function (Blueprint $table) {
            $table->dropColumn('unidades_consumidoras');
            
            $table->bigInteger('numero_uc')->nullable();
            $table->string('apelido', 100)->nullable();
            $table->decimal('media_consumo', 10, 2)->nullable();
            $table->string('ligacao', 20)->nullable();
            $table->string('distribuidora', 50)->nullable();
            $table->float('unidades_consumidoras')->nullable();
        });
    }
};