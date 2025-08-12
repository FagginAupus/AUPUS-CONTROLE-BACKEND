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
            // ✅ CORRIGIR: Mudar de FLOAT para JSON
            $table->dropColumn('unidades_consumidoras');
        });
        
        Schema::table('propostas', function (Blueprint $table) {
            // ✅ ADICIONAR como JSON agora
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
            $table->float('unidades_consumidoras')->nullable()->after('beneficios');
        });
    }
};