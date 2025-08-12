<?php
// database/migrations/XXXX_XX_XX_XXXXXX_add_missing_fields_to_propostas_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Verificar se a tabela existe
        if (!Schema::hasTable('propostas')) {
            throw new Exception('Tabela propostas não existe!');
        }

        Schema::table('propostas', function (Blueprint $table) {
            // Verificar e adicionar campos que podem estar faltando
            
            if (!Schema::hasColumn('propostas', 'numero_proposta')) {
                $table->string('numero_proposta', 50)->nullable()->unique();
            }
            
            if (!Schema::hasColumn('propostas', 'data_proposta')) {
                $table->date('data_proposta')->nullable();
            }
            
            if (!Schema::hasColumn('propostas', 'nome_cliente')) {
                $table->string('nome_cliente', 200)->nullable();
            }
            
            if (!Schema::hasColumn('propostas', 'consultor')) {
                $table->string('consultor', 100)->nullable();
            }
            
            if (!Schema::hasColumn('propostas', 'usuario_id')) {
                $table->uuid('usuario_id')->nullable();
            }
            
            if (!Schema::hasColumn('propostas', 'recorrencia')) {
                $table->string('recorrencia', 50)->default('3%');
            }
            
            if (!Schema::hasColumn('propostas', 'economia')) {
                $table->decimal('economia', 5, 2)->default(20.00);
            }
            
            if (!Schema::hasColumn('propostas', 'bandeira')) {
                $table->decimal('bandeira', 5, 2)->default(20.00);
            }
            
            if (!Schema::hasColumn('propostas', 'status')) {
                $table->string('status', 50)->default('Aguardando');
            }
            
            if (!Schema::hasColumn('propostas', 'observacoes')) {
                $table->text('observacoes')->nullable();
            }
            
            if (!Schema::hasColumn('propostas', 'beneficios')) {
                $table->json('beneficios')->nullable();
            }
            
            if (!Schema::hasColumn('propostas', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Adicionar índices se não existirem
        try {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_propostas_usuario_id ON propostas(usuario_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_propostas_status ON propostas(status)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_propostas_consultor ON propostas(consultor)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_propostas_data_proposta ON propostas(data_proposta)');
        } catch (Exception $e) {
            // Índices já existem
        }

        // Adicionar foreign key se não existir
        try {
            if (Schema::hasTable('usuarios')) {
                DB::statement('
                    ALTER TABLE propostas 
                    ADD CONSTRAINT fk_propostas_usuario_id 
                    FOREIGN KEY (usuario_id) 
                    REFERENCES usuarios(id) 
                    ON DELETE CASCADE
                ');
            }
        } catch (Exception $e) {
            // Foreign key já existe
        }
    }

    public function down(): void
    {
        Schema::table('propostas', function (Blueprint $table) {
            // Remover campos se necessário (cuidado!)
            // $table->dropColumn(['campo1', 'campo2']);
        });
    }
};