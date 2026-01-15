<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabela para armazenar os itens de cada rateio (UCs beneficiárias e suas porcentagens)
     */
    public function up(): void
    {
        Schema::create('historico_rateio_itens', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID
            $table->char('historico_rateio_id', 26); // FK para historico_rateios
            $table->string('numero_uc', 50); // Número da UC beneficiária
            $table->decimal('porcentagem', 8, 4); // Porcentagem de rateio (ex: 12.5000)
            $table->decimal('consumo_kwh', 12, 2)->nullable(); // Consumo em kWh se disponível
            $table->timestamps();

            // Índices
            $table->index('historico_rateio_id');
            $table->index('numero_uc');

            // Foreign key
            $table->foreign('historico_rateio_id')
                  ->references('id')
                  ->on('historico_rateios')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('historico_rateio_itens');
    }
};
