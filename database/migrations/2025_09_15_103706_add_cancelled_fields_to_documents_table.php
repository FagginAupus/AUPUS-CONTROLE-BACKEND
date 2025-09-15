<?php
// database/migrations/2025_XX_XX_XXXXXX_add_cancelled_fields_to_documents_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('last_checked_at');
            $table->string('cancelled_by', 36)->nullable()->after('cancelled_at');
            
            // Adicionar índice para consultas rápidas
            $table->index('cancelled_at');
            
            // FK para o usuário que cancelou (opcional)
            $table->foreign('cancelled_by')->references('id')->on('usuarios')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropIndex(['cancelled_at']);
            $table->dropColumn(['cancelled_at', 'cancelled_by']);
        });
    }
};