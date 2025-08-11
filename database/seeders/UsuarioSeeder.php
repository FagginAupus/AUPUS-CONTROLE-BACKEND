<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UsuarioSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Limpar tabela antes de popular (apenas em desenvolvimento)
        if (app()->environment('local')) {
            DB::table('usuarios')->truncate();
        }

        // Função para gerar ULID (26 caracteres)
        $generateUlid = function() {
            return strtoupper(Str::ulid()->toBase32());
        };

        // Corrigir campo telefone se necessário
        try {
            DB::statement('ALTER TABLE usuarios ALTER COLUMN telefone TYPE VARCHAR(20)');
            $this->command->info('✅ Campo telefone ajustado para VARCHAR(20)');
        } catch (\Exception $e) {
            // Ignorar erro se já estiver correto
        }

        // Criar usuário admin
        $adminId = $generateUlid();
        DB::table('usuarios')->insert([
            'id' => $adminId,
            'nome' => 'Administrador',
            'email' => 'admin@aupus.com',
            'senha' => Hash::make('123456'), // Hash explícito no seeder
            'telefone' => '62999999999',
            'status' => 'ativo',
            'role' => 'admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Criar usuário consultor
        $consultorId = $generateUlid();
        DB::table('usuarios')->insert([
            'id' => $consultorId,
            'nome' => 'João Consultor',
            'email' => 'consultor@aupus.com',
            'senha' => Hash::make('123456'),
            'telefone' => '62988888888',
            'status' => 'ativo',
            'role' => 'consultor',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Criar usuário gerente
        $gerenteId = $generateUlid();
        DB::table('usuarios')->insert([
            'id' => $gerenteId,
            'nome' => 'Maria Gerente',
            'email' => 'gerente@aupus.com',
            'senha' => Hash::make('123456'),
            'telefone' => '62977777777',
            'status' => 'ativo',
            'role' => 'gerente',
            'manager_id' => $consultorId, // Referência ao consultor
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Criar usuário vendedor
        $vendedorId = $generateUlid();
        DB::table('usuarios')->insert([
            'id' => $vendedorId,
            'nome' => 'Carlos Vendedor',
            'email' => 'vendedor@aupus.com',
            'senha' => Hash::make('123456'),
            'telefone' => '62966666666',
            'status' => 'ativo',
            'role' => 'vendedor',
            'manager_id' => $gerenteId, // Referência ao gerente
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Criar usuário extra para testes
        $testId = $generateUlid();
        DB::table('usuarios')->insert([
            'id' => $testId,
            'nome' => 'Teste Sistema',
            'email' => 'teste@aupus.com',
            'senha' => Hash::make('123456'),
            'telefone' => '62955555555',
            'status' => 'ativo',
            'role' => 'vendedor',
            'manager_id' => $gerenteId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command->info('Usuários de teste criados com sucesso usando ULID!');
        $this->command->info("Admin ID: {$adminId}");
        $this->command->info("Consultor ID: {$consultorId}");
        $this->command->info("Gerente ID: {$gerenteId}");
        $this->command->info("Vendedor ID: {$vendedorId}");
        $this->command->info("Teste ID: {$testId}");
        $this->command->info('=== CREDENCIAIS DE TESTE ===');
        $this->command->info('Admin: admin@aupus.com / 123456');
        $this->command->info('Consultor: consultor@aupus.com / 123456');
        $this->command->info('Gerente: gerente@aupus.com / 123456');
        $this->command->info('Vendedor: vendedor@aupus.com / 123456');
        $this->command->info('Teste: teste@aupus.com / 123456');
        $this->command->info('=============================');
    }
}