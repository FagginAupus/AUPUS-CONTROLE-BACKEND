<?php
// database/migrations/2025_09_16_154100_add_manual_upload_fields_to_documents.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Campos para documentos com upload manual
            $table->boolean('uploaded_manually')->default(false)->after('last_checked_at');
            $table->timestamp('uploaded_at')->nullable()->after('uploaded_manually');
            $table->string('uploaded_by', 36)->nullable()->after('uploaded_at');
            $table->string('manual_upload_filename')->nullable()->after('uploaded_by');
            
            // Campos extras para compatibilidade
            $table->string('signer_email')->nullable()->after('manual_upload_filename');
            $table->string('signer_name')->nullable()->after('signer_email');
            $table->text('signing_url')->nullable()->after('signer_name');
            $table->boolean('envio_whatsapp')->default(false)->after('signing_url');
            $table->boolean('envio_email')->default(true)->after('envio_whatsapp');
            
            // Índices para otimização
            $table->index('uploaded_manually');
            $table->index('signer_email');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['uploaded_manually']);
            $table->dropIndex(['signer_email']);
            $table->dropColumn([
                'uploaded_manually',
                'uploaded_at', 
                'uploaded_by',
                'manual_upload_filename',
                'signer_email',
                'signer_name',
                'signing_url',
                'envio_whatsapp',
                'envio_email'
            ]);
        });
    }
};