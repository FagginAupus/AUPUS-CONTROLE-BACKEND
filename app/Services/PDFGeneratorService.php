<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PDFGeneratorService
{
    private $templatePath;

    public function __construct()
    {
        // Template PDF em storage/app/templates/
        $this->templatePath = storage_path('app/templates/PROCURACAO_E_TERMO_DE_ADESAO.pdf');
    }

    /**
     * Gera PDF preenchido com os dados fornecidos
     */
    public function gerarTermoPreenchido(array $dados): string
    {
        Log::info('📄 Iniciando geração de PDF', [
            'template_path' => $this->templatePath,
            'dados_fornecidos' => array_keys($dados)
        ]);

        // Verificar se template existe
        if (!file_exists($this->templatePath)) {
            throw new \Exception("Template PDF não encontrado: {$this->templatePath}");
        }

        // Criar PDF temporário usando a lógica do projeto api_authentic
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempOutputPath = $tempDir . '/termo_preenchido_' . time() . '.pdf';

        try {
            // Ler template original
            $templateContent = file_get_contents($this->templatePath);
            
            // Usar a mesma lógica do document-form.blade.php para preenchimento
            $pdfPreenchido = $this->preencherCamposPDF($templateContent, $dados);
            
            // Salvar PDF preenchido temporariamente
            file_put_contents($tempOutputPath, $pdfPreenchido);
            
            Log::info('✅ PDF gerado com sucesso', [
                'output_path' => $tempOutputPath,
                'size' => filesize($tempOutputPath) . ' bytes'
            ]);

            return $pdfPreenchido;
            
        } catch (\Exception $e) {
            Log::error('❌ Erro ao gerar PDF', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            // Limpar arquivo temporário se existir
            if (file_exists($tempOutputPath)) {
                unlink($tempOutputPath);
            }
            
            throw $e;
        }
    }

    /**
     * Retorna template PDF para preenchimento via JavaScript no frontend
     */
    private function preencherCamposPDF(string $templateContent, array $dados): string
    {
        // Mapeamento baseado no fornecido pelo usuário
        $mapeamento = [
            "text_1semi" => $dados['nomeAssociado'] ?? '',
            "text_2jyxc" => $dados['endereco'] ?? '',
            "text_3qmpl" => $dados['formaPagamento'] ?? '',
            "text_4nirf" => $dados['cpf'] ?? '',
            "text_5igbr" => $dados['representanteLegal'] ?? '',
            "textarea_6pyef" => $dados['numeroUnidade'] ?? '',
            "textarea_7wrsb" => $dados['logradouro'] ?? '',
            "text_15goku" => $dados['dia'] ?? '',
            "text_16bzyc" => $dados['mes'] ?? '',
            "text_13gmsz" => $dados['economia'] ?? ''
        ];

        Log::info('📝 Preparando dados para preenchimento JavaScript', [
            'mapeamento' => $mapeamento,
            'template_size' => strlen($templateContent) . ' bytes'
        ]);

        // Para implementação JavaScript, retornamos o template original
        // O preenchimento será feito no frontend usando pdf-lib
        return $templateContent;
    }

    /**
     * Método para preencher PDF usando biblioteca PHP (setasign/fpdi + tcpdf)
     */
    private function preencherComBibliotecaPDF(string $templateContent, array $mapeamento): string
    {
        try {
            // Salvar template temporariamente
            $tempTemplatePath = storage_path('app/temp/template_temp_' . time() . '.pdf');
            file_put_contents($tempTemplatePath, $templateContent);

            // Aqui você usaria uma biblioteca como TCPDF + FPDI para:
            // 1. Carregar o PDF template
            // 2. Preencher os campos do formulário
            // 3. Gerar o PDF final
            
            // Por enquanto, vamos retornar o template original
            // TODO: Implementar preenchimento real com biblioteca PDF
            
            Log::warning('⚠️ Preenchimento PDF simulado - implementar biblioteca PDF real');
            
            // Limpar arquivo temporário
            if (file_exists($tempTemplatePath)) {
                unlink($tempTemplatePath);
            }
            
            return $templateContent; // Retorna template original por enquanto
            
        } catch (\Exception $e) {
            Log::error('❌ Erro ao preencher PDF com biblioteca', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Alternativa: preparar dados para preenchimento via JavaScript no frontend
     */
    private function prepararParaPreenchimentoJavaScript(string $templateContent, array $mapeamento): string
    {
        // Esta seria uma alternativa onde o preenchimento aconteceria no frontend
        // usando pdf-lib.js como no projeto api_authentic
        
        Log::info('📝 Preparando dados para preenchimento JavaScript', [
            'campos' => count($mapeamento)
        ]);
        
        return $templateContent;
    }

    /**
     * Criar PDF simples com dados (fallback se template não disponível)
     */
    public function criarPDFSimples(array $dados): string
    {
        Log::info('📄 Criando PDF simples como fallback');
        
        // Criar PDF simples usando TCPDF ou similar
        $conteudo = $this->gerarConteudoHTMLParaPDF($dados);
        
        // Converter HTML para PDF usando biblioteca como DomPDF
        // TODO: Implementar conversão HTML -> PDF
        
        return $conteudo; // Por enquanto retorna HTML
    }

    /**
     * Gerar conteúdo HTML para conversão em PDF
     */
    private function gerarConteudoHTMLParaPDF(array $dados): string
    {
        return '
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Termo de Adesão - AUPUS Energia</title>
            <style>
                body { font-family: Arial, sans-serif; font-size: 12px; }
                .header { text-align: center; margin-bottom: 30px; }
                .campo { margin-bottom: 10px; }
                .label { font-weight: bold; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>TERMO DE ADESÃO</h1>
                <h2>AUPUS ENERGIA</h2>
            </div>
            
            <div class="campo">
                <span class="label">Nome do Associado:</span> ' . ($dados['nomeAssociado'] ?? '') . '
            </div>
            
            <div class="campo">
                <span class="label">Endereço:</span> ' . ($dados['endereco'] ?? '') . '
            </div>
            
            <div class="campo">
                <span class="label">CPF/CNPJ:</span> ' . ($dados['cpf'] ?? '') . '
            </div>
            
            <div class="campo">
                <span class="label">Representante Legal:</span> ' . ($dados['representanteLegal'] ?? '') . '
            </div>
            
            <div class="campo">
                <span class="label">Número da UC:</span> ' . ($dados['numeroUnidade'] ?? '') . '
            </div>
            
            <div class="campo">
                <span class="label">Logradouro:</span> ' . ($dados['logradouro'] ?? '') . '
            </div>
            
            <div class="campo">
                <span class="label">Forma de Pagamento:</span> ' . ($dados['formaPagamento'] ?? '') . '
            </div>
            
            <div class="campo">
                <span class="label">Desconto Tarifa:</span> ' . ($dados['economia'] ?? '') . '%
            </div>
            
            <div class="campo">
                <span class="label">Data:</span> ' . ($dados['dia'] ?? '') . '/' . ($dados['mes'] ?? '') . '/' . date('Y') . '
            </div>
        </body>
        </html>';
    }


    public function gerarTermoAdesao(array $dados): string
    {
        Log::info('📄 Iniciando geração de PDF - Termo de Adesão');
        
        try {
            // Preparar dados formatados
            $dadosFormatados = $this->prepararDadosParaPDF($dados);
            
            // Usar método existente
            return $this->gerarTermoPreenchido($dadosFormatados);
            
        } catch (\Exception $e) {
            Log::error('❌ Erro ao gerar PDF', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function prepararDadosParaPDF(array $dados): array
    {
        $agora = \Carbon\Carbon::now();
        
        // Determinar CPF/CNPJ baseado no tipo
        $cpfCnpj = '';
        if (($dados['tipoDocumento'] ?? '') === 'CPF') {
            $cpfCnpj = $dados['cpf'] ?? '';
        } else {
            $cpfCnpj = $dados['cnpj'] ?? '';
        }

        return [
            'nomeAssociado' => $dados['nomeCliente'] ?? '',
            'endereco' => $dados['enderecoUC'] ?? '',
            'formaPagamento' => $dados['formaPagamento'] ?? 'Boleto',
            'cpf' => $cpfCnpj,
            'representanteLegal' => $dados['nomeRepresentante'] ?? '',
            'numeroUnidade' => $dados['numeroUC'] ?? '',
            'logradouro' => $dados['logradouro'] ?? '',
            'dia' => $agora->format('d'),
            'mes' => $agora->format('m'),
            'economia' => $dados['economia'] ?? '0'
        ];
    }
}