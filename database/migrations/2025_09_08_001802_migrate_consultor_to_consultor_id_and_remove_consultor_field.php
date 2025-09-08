<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1️⃣ MIGRAR DADOS: consultor (nome) → consultor_id (UUID)
        DB::statement("
            UPDATE propostas 
            SET consultor_id = usuarios.id
            FROM usuarios
            WHERE TRIM(LOWER(propostas.consultor)) = TRIM(LOWER(usuarios.nome))
              AND usuarios.role IN ('admin', 'consultor', 'gerente', 'vendedor')
              AND usuarios.is_active = true
              AND propostas.consultor_id IS NULL
              AND propostas.deleted_at IS NULL
              AND propostas.consultor IS NOT NULL
              AND TRIM(propostas.consultor) != ''
        ");

        // 2️⃣ LOG/VERIFICAÇÃO: Mostrar quantas foram migradas
        $migradas = DB::selectOne("
            SELECT COUNT(*) as total 
            FROM propostas 
            WHERE consultor_id IS NOT NULL 
              AND deleted_at IS NULL
        ")->total;

        $semMigracao = DB::selectOne("
            SELECT COUNT(*) as total 
            FROM propostas 
            WHERE consultor_id IS NULL 
              AND deleted_at IS NULL
              AND (consultor IS NOT NULL AND TRIM(consultor) != '')
        ")->total;

        // Log da migração
        \Log::info('Migration consultor → consultor_id executada', [
            'propostas_migradas' => $migradas,
            'propostas_sem_migracao' => $semMigracao
        ]);

        // 3️⃣ REMOVER CAMPO consultor (antigo campo texto)
        Schema::table('propostas', function (Blueprint $table) {
            $table->dropIndex('idx_propostas_consultor'); // Remover índice do campo consultor
            $table->dropColumn('consultor'); // Remover campo consultor
        });

        // 4️⃣ TORNAR consultor_id NOT NULL (opcional - descomente se quiser)
        // Schema::table('propostas', function (Blueprint $table) {
        //     $table->string('consultor_id', 36)->nullable(false)->change();
        // });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ⚠️ ATENÇÃO: Esta reversão pode causar perda de dados!
        // Só execute se tiver certeza e backup dos dados
        
        // 1️⃣ RECRIAR CAMPO consultor
        Schema::table('propostas', function (Blueprint $table) {
            $table->string('consultor', 100)->nullable()->after('nome_cliente');
            $table->index('consultor', 'idx_propostas_consultor');
        });

        // 2️⃣ TENTAR RECUPERAR DADOS: consultor_id → consultor (nome)
        DB::statement("
            UPDATE propostas 
            SET consultor = usuarios.nome
            FROM usuarios
            WHERE propostas.consultor_id = usuarios.id
              AND propostas.deleted_at IS NULL
        ");

        // 3️⃣ TORNAR consultor_id NULLABLE NOVAMENTE (se foi alterado)
        // Schema::table('propostas', function (Blueprint $table) {
        //     $table->string('consultor_id', 36)->nullable()->change();
        // });
    }
};