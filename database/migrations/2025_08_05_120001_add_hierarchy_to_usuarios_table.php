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
        Schema::table('usuarios', function (Blueprint $table) {
            // Campos para hierarquia e roles (SEM username - usa email existente)
            $table->enum('role', ['admin', 'consultor', 'gerente', 'vendedor'])
                  ->default('vendedor')
                  ->comment('Role do usuário no sistema');
            $table->string('manager_id', 36)->nullable()
                  ->comment('ID do gerente/supervisor direto');
            $table->boolean('is_active')->default(true)
                  ->comment('Se o usuário está ativo no sistema');
            
            // Garantir que email seja unique e não nulo (caso não seja)
            // $table->unique('email'); // Descomente se email não for unique ainda
            
            // Índices para performance
            $table->index('role', 'idx_usuarios_role');
            $table->index('manager_id', 'idx_usuarios_manager');
            $table->index('email', 'idx_usuarios_email'); // Email para login
            
            // Foreign key para hierarquia
            $table->foreign('manager_id', 'fk_usuarios_manager')
                  ->references('id')
                  ->on('usuarios')
                  ->onDelete('set null');
        });
        
        // Comentários na tabela
        DB::statement("COMMENT ON COLUMN usuarios.email IS 'Email usado para login (deve ser único)'");
        DB::statement("COMMENT ON COLUMN usuarios.role IS 'Papel do usuário: admin, consultor, gerente, vendedor'");
        DB::statement("COMMENT ON COLUMN usuarios.manager_id IS 'ID do supervisor/gerente direto'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            // Remover foreign key
            $table->dropForeign('fk_usuarios_manager');
            
            // Remover índices
            $table->dropIndex('idx_usuarios_role');
            $table->dropIndex('idx_usuarios_manager');
            $table->dropIndex('idx_usuarios_email');
            
            // Remover colunas (não remove email que já existia)
            $table->dropColumn(['role', 'manager_id', 'is_active']);
        });
    }
};