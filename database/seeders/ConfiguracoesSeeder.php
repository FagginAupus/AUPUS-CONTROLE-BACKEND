<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Configuracao;
use Illuminate\Support\Str;

class ConfiguracoesSeeder extends Seeder
{
    public function run(): void
    {
        // Limpar tabela antes de popular (apenas em desenvolvimento)
        if (app()->environment('local')) {
            Configuracao::truncate();
        }

        $configs = [
            [
                'id' => (string) Str::uuid(),
                'chave' => 'calibragem_global',
                'valor' => '0.00',
                'tipo' => 'number',
                'descricao' => 'Percentual de calibragem global aplicado no sistema',
                'grupo' => 'calibragem'
            ],
            [
                'id' => (string) Str::uuid(),
                'chave' => 'calibragem_bandeira',
                'valor' => '0.00',
                'tipo' => 'number',
                'descricao' => 'Calibragem específica para bandeira tarifária',
                'grupo' => 'calibragem'
            ],
            [
                'id' => (string) Str::uuid(),
                'chave' => 'empresa_nome',
                'valor' => 'Aupus Energia',
                'tipo' => 'string',
                'descricao' => 'Nome da empresa',
                'grupo' => 'geral'
            ],
            [
                'id' => (string) Str::uuid(),
                'chave' => 'empresa_slogan',
                'valor' => 'Entre nessa onda!',
                'tipo' => 'string',
                'descricao' => 'Slogan da empresa',
                'grupo' => 'geral'
            ],
            [
                'id' => (string) Str::uuid(),
                'chave' => 'sistema_versao',
                'valor' => '2.0',
                'tipo' => 'string',
                'descricao' => 'Versão atual do sistema',
                'grupo' => 'sistema'
            ],
            [
                'id' => (string) Str::uuid(),
                'chave' => 'beneficios_padrao',
                'valor' => json_encode([
                    'beneficio1' => 'Economia na conta de energia',
                    'beneficio2' => 'Sustentabilidade ambiental',
                    'beneficio3' => 'Energia limpa e renovável',
                    'beneficio4' => 'Redução de impostos',
                    'beneficio5' => 'Geração distribuída',
                    'beneficio6' => 'Compensação elétrica',
                    'beneficio7' => 'Créditos de energia',
                    'beneficio8' => 'Monitoramento online',
                    'beneficio9' => 'Valorização do imóvel',
                    'beneficio10' => 'Baixa manutenção',
                    'beneficio11' => 'Tecnologia alemã',
                    'beneficio12' => 'Garantia de 25 anos'
                ]),
                'tipo' => 'json',
                'descricao' => 'Lista de benefícios padrão para propostas',
                'grupo' => 'propostas'
            ],
            [
                'id' => (string) Str::uuid(),
                'chave' => 'economia_padrao',
                'valor' => '20.00',
                'tipo' => 'number',
                'descricao' => 'Percentual de economia padrão para novas propostas',
                'grupo' => 'propostas'
            ],
            [
                'id' => (string) Str::uuid(),
                'chave' => 'bandeira_padrao',
                'valor' => '20.00',
                'tipo' => 'number',
                'descricao' => 'Percentual de economia na bandeira padrão',
                'grupo' => 'propostas'
            ],
            [
                'id' => (string) Str::uuid(),
                'chave' => 'recorrencia_padrao',
                'valor' => '3%',
                'tipo' => 'string',
                'descricao' => 'Percentual de recorrência padrão',
                'grupo' => 'propostas'
            ]
        ];

        foreach ($configs as $config) {
            // Usar updateOrCreate para evitar duplicatas
            Configuracao::updateOrCreate(
                ['chave' => $config['chave']], // Buscar por chave
                $config // Dados para criar/atualizar
            );
        }

        $this->command->info('Configurações padrão criadas/atualizadas com sucesso!');
        $this->command->info('Total de configurações: ' . count($configs));
    }
}