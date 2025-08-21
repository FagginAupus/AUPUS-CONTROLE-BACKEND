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
            // Adicionar campo deleted_by para rastrear quem excluiu o registro
            $table->string('deleted_by', 36)->nullable()->after('deleted_at');
            
            // Adicionar foreign key para usuarios
            $table->foreign('deleted_by', 'fk_uc_deleted_by')
                  ->references('id')
                  ->on('usuarios')
                  ->onDelete('set null');
        });
        
        // Adicionar índice para melhor performance
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            $table->index(['deleted_at', 'deleted_by'], 'idx_uc_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unidades_consumidoras', function (Blueprint $table) {
            // Remover foreign key primeiro
            $table->dropForeign('fk_uc_deleted_by');
            
            // Remover índice
            $table->dropIndex('idx_uc_deleted');
            
            // Remover coluna
            $table->dropColumn('deleted_by');
        });
    }
};