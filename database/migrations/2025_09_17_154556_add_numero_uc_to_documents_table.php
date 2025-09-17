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
        Schema::table('documents', function (Blueprint $table) {
            $table->string('numero_uc')->nullable()->after('proposta_id')
                  ->comment('Número da UC específica do termo (para permitir múltiplos termos por proposta)');
            
            // Criar índice composto para proposta_id + numero_uc
            $table->index(['proposta_id', 'numero_uc'], 'idx_documents_proposta_uc');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('idx_documents_proposta_uc');
            $table->dropColumn('numero_uc');
        });
    }
};