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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('autentique_id')->unique(); // ID do documento na Autentique
            $table->string('name'); // Nome do documento
            $table->string('status')->default('pending'); // pending, signed, rejected
            $table->boolean('is_sandbox')->default(true);
            
            // Relacionamento com propostas
            $table->string('proposta_id', 36)->nullable(); // UUID da proposta
            $table->foreign('proposta_id')->references('id')->on('propostas')->onDelete('cascade');
            
            $table->text('document_data'); // Dados do associado (JSON como texto)
            $table->text('signers'); // Array de signatários (JSON como texto)
            $table->text('autentique_response')->nullable(); // Resposta completa da Autentique (JSON como texto)
            $table->integer('total_signers')->default(0);
            $table->integer('signed_count')->default(0);
            $table->integer('rejected_count')->default(0);
            $table->timestamp('autentique_created_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            
            // Campos específicos para AUPUS
            $table->string('created_by', 36)->nullable(); // ID do usuário que criou
            $table->foreign('created_by')->references('id')->on('usuarios')->onDelete('set null');
            
            $table->timestamps();
            
            $table->index(['status', 'created_at']);
            $table->index(['autentique_created_at']);
            $table->index(['proposta_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};