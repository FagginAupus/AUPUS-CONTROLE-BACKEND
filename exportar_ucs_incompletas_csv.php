<?php
/**
 * Script para exportar UCs com campos incompletos em CSV
 */

require __DIR__.'/vendor/autoload.php';

use Illuminate\Support\Facades\DB;

// Carrega o ambiente Laravel
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$propostas = DB::table('propostas')
    ->whereNotNull('documentacao')
    ->whereRaw("documentacao::text LIKE '%CEP_UC%'")
    ->get();

$ucsIncompletas = [];

foreach ($propostas as $proposta) {
    $documentacao = json_decode($proposta->documentacao, true);

    if (!$documentacao) {
        continue;
    }

    foreach ($documentacao as $numeroUC => $dados) {
        // Pula campos que não são UCs
        if (in_array($numeroUC, ['faturas_ucs', 'data_upload_faturas', 'documentoPessoal', 'termoAdesao'])) {
            continue;
        }

        // Verifica se tem os campos novos
        if (!isset($dados['CEP_UC'])) {
            continue;
        }

        // Verifica se algum campo está vazio ou null
        $camposVazios = [];

        if (empty($dados['CEP_UC'])) {
            $camposVazios[] = 'CEP_UC';
        }
        if (empty($dados['Bairro_UC']) || $dados['Bairro_UC'] === null) {
            $camposVazios[] = 'Bairro_UC';
        }
        if (empty($dados['Cidade_UC']) || $dados['Cidade_UC'] === null) {
            $camposVazios[] = 'Cidade_UC';
        }
        if (empty($dados['Estado_UC']) || $dados['Estado_UC'] === null) {
            $camposVazios[] = 'Estado_UC';
        }

        if (count($camposVazios) > 0) {
            $ucsIncompletas[] = [
                'proposta_id' => $proposta->id,
                'numero_proposta' => $proposta->numero_proposta,
                'numero_uc' => $numeroUC,
                'cep_uc' => $dados['CEP_UC'] ?? '',
                'bairro_uc' => $dados['Bairro_UC'] ?? '',
                'cidade_uc' => $dados['Cidade_UC'] ?? '',
                'estado_uc' => $dados['Estado_UC'] ?? '',
                'campos_vazios' => implode('; ', $camposVazios),
                'endereco_original' => $dados['enderecoUC'] ?? 'Sem endereço'
            ];
        }
    }
}

// Gera CSV
$csvFile = '/tmp/ucs_incompletas.csv';
$fp = fopen($csvFile, 'w');

// Cabeçalho
fputcsv($fp, [
    'Proposta ID',
    'Número Proposta',
    'Número UC',
    'CEP UC',
    'Bairro UC',
    'Cidade UC',
    'Estado UC',
    'Campos Vazios',
    'Endereço Original'
]);

// Dados
foreach ($ucsIncompletas as $uc) {
    fputcsv($fp, [
        $uc['proposta_id'],
        $uc['numero_proposta'],
        $uc['numero_uc'],
        $uc['cep_uc'],
        $uc['bairro_uc'],
        $uc['cidade_uc'],
        $uc['estado_uc'],
        $uc['campos_vazios'],
        $uc['endereco_original']
    ]);
}

fclose($fp);

echo "CSV gerado com sucesso em: $csvFile\n";
echo "Total de registros: " . count($ucsIncompletas) . "\n";
