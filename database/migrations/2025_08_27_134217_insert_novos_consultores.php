<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Inserir novos consultores
        $consultores = [
            [
                'nome' => 'Juruna Mocó Lima',
                'email' => 'jurunalima@gmail.com',
                'cpf_cnpj' => '820.407.96-34',
                'endereco' => 'Rua Sacramento Qd 126, Lt 9/5, setor dos Afonsos',
                'cidade' => 'Aparecida de Goiânia',
                'estado' => 'Goiás',
                'cep' => '74915-380',
                'pix' => 'jurunalima@gmail.com',
                'telefone' => null // será preenchido se necessário
            ],
            [
                'nome' => 'Paulo César Tavares',
                'email' => 'tavarespc01@gmail.com',
                'cpf_cnpj' => '28260082187',
                'endereco' => 'Rua 52 N° 92 apto 1002 Jardim Goiás',
                'cidade' => 'Goiânia',
                'estado' => 'Goiás',
                'cep' => '74810-200',
                'pix' => '282600821-87',
                'telefone' => null
            ],
            [
                'nome' => 'Thales Porfirio Norinho',
                'email' => 'thalesnorinho@gmail.com',
                'cpf_cnpj' => '36736847877',
                'endereco' => 'rua C179 Qd 447 Lt 8 Ap 202 Jd América',
                'cidade' => 'Goiânia',
                'estado' => 'Goiás',
                'cep' => '74275-180',
                'pix' => '60.886.144/0001-19',
                'telefone' => null
            ],
            [
                'nome' => 'Pablo Cardoso Ribeiro',
                'email' => 'cardosoribeirop@gmail.com',
                'cpf_cnpj' => '052.968.601-52',
                'endereco' => 'Avenida São João, nº 380, Apto. 1203A, Setor Alto da Glória',
                'cidade' => 'Goiânia',
                'estado' => 'Goiás',
                'cep' => '74.815-700',
                'pix' => '05296860152',
                'telefone' => null
            ],
            [
                'nome' => 'Rubens consorte filho',
                'email' => 'rconsorte60@gmail.com',
                'cpf_cnpj' => '260.595.251-72',
                'endereco' => 'Avenida 136, 515 apto 201 edifício DJ Oliveira, Setor marista',
                'cidade' => 'Goiânia',
                'estado' => 'Goiás',
                'cep' => '74180-040',
                'pix' => '62 98425-0027',
                'telefone' => null
            ],
            [
                'nome' => 'Ailton Garcia Barbosa Filho',
                'email' => 'Energygreengyn@gmail.com',
                'cpf_cnpj' => '72875089153',
                'endereco' => 'Rua t 27 n 606, Setor Bueno',
                'cidade' => 'Goiânia',
                'estado' => 'Goiás',
                'cep' => '74210030',
                'pix' => '2875089153',
                'telefone' => null
            ],
            [
                'nome' => 'Patrick Morais Rocha',
                'email' => 'patrick_pdr@icloud.com',
                'cpf_cnpj' => '04115423110',
                'endereco' => 'Rua Francisca Maia da Silveira q19 Lt 18, São Francisco 2',
                'cidade' => 'Senador Canedo',
                'estado' => 'GO',
                'cep' => '75261642',
                'pix' => 'patrick_pdr@icloud.com',
                'telefone' => null
            ]
        ];

        // Manager ID fixo conforme solicitado
        $managerId = '01K2CPBYE07B3HWW0CZHHB3ZCR';

        foreach ($consultores as $consultor) {
            DB::table('usuarios')->insert([
                'id' => (string) Str::ulid(),
                'nome' => $consultor['nome'],
                'email' => $consultor['email'],
                'senha' => Hash::make('00000000'),
                'telefone' => $consultor['telefone'],
                'cpf_cnpj' => $consultor['cpf_cnpj'],
                'endereco' => $consultor['endereco'],
                'cidade' => $consultor['cidade'],
                'estado' => $consultor['estado'],
                'cep' => $consultor['cep'],
                'pix' => $consultor['pix'],
                'role' => 'consultor',
                'status' => 'Ativo',
                'manager_id' => $managerId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Log da inserção
        DB::statement("SELECT 'Inseridos 7 novos consultores com sucesso!' as resultado");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remover consultores baseado nos emails
        $emails = [
            'jurunalima@gmail.com',
            'tavarespc01@gmail.com',
            'thalesnorinho@gmail.com',
            'cardosoribeirop@gmail.com',
            'rconsorte60@gmail.com',
            'Energygreengyn@gmail.com',
            'patrick_pdr@icloud.com'
        ];

        DB::table('usuarios')
            ->whereIn('email', $emails)
            ->delete();
    }
};