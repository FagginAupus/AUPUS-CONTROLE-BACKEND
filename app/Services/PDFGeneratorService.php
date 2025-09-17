<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PDFGeneratorService
{
    private $templatePath;

    public function __construct()
    {
        $this->templatePath = storage_path('app/templates/PROCURACAO_E_TERMO_DE_ADESAO.pdf');
    }

    /**
     * ✅ MÉTODO PRINCIPAL - RETORNA DADOS PARA PREENCHIMENTO NO FRONTEND
     */
    public function gerarTermoAdesao(array $dados): string
    {
        Log::info('📄 PREPARANDO DADOS PARA PREENCHIMENTO DE FORM FIELDS', [
            'template_path' => $this->templatePath,
            'dados_fornecidos' => array_keys($dados)
        ]);

        // Verificar se template existe
        if (!file_exists($this->templatePath)) {
            throw new \Exception("Template PDF não encontrado: {$this->templatePath}");
        }

        try {
            // 1. Preparar dados formatados
            $dadosFormatados = $this->prepararDadosParaPDF($dados);
            
            // 2. Criar mapeamento de campos
            $mapeamento = [
                "text_1semi" => $dadosFormatados['nomeAssociado'] ?? '',
                "text_2jyxc" => $dadosFormatados['endereco'] ?? '',
                "text_3qmpl" => $dadosFormatados['formaPagamento'] ?? 'Boleto',
                "text_4nirf" => $dadosFormatados['cpf'] ?? '',
                "text_5igbr" => $dadosFormatados['representanteLegal'] ?? '',
                "textarea_6pyef" => $dadosFormatados['numeroUnidade'] ?? '',
                "textarea_7wrsb" => $dadosFormatados['logradouro'] ?? '',
                "text_15goku" => $dadosFormatados['dia'] ?? '',
                "text_16bzyc" => $dadosFormatados['mes'] ?? '',
                "text_13gmsz" => $dadosFormatados['economia'] ?? ''
            ];

            Log::info('📋 Mapeamento de campos preparado', [
                'campos_total' => count($mapeamento),
                'campos_preenchidos' => count(array_filter($mapeamento)),
                'mapeamento' => $mapeamento
            ]);

            // 3. ✅ NOVA ESTRATÉGIA: RETORNAR DADOS EM JSON PARA PROCESSAMENTO NO FRONTEND
            return json_encode([
                'tipo' => 'form_fields',
                'sucesso' => true,
                'template_path' => $this->templatePath,
                'mapeamento_campos' => $mapeamento,
                'dados_originais' => $dadosFormatados,
                'instrucoes' => 'Usar pdf-lib.js no frontend para preencher form fields'
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao preparar dados para PDF', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }

    /**
     * ✅ MÉTODO ALTERNATIVO: Usar PDFtk (se disponível no servidor)
     * PDFtk é a ferramenta mais eficaz para preencher form fields
     */
    public function preencherComPDFtk(array $dados): string
    {
        Log::info('🔧 Tentando usar PDFtk para preenchimento');

        try {
            // Preparar dados
            $dadosFormatados = $this->prepararDadosParaPDF($dados);
            $mapeamento = [
                "text_1semi" => $dadosFormatados['nomeAssociado'] ?? '',
                "text_2jyxc" => $dadosFormatados['endereco'] ?? '',
                "text_3qmpl" => $dadosFormatados['formaPagamento'] ?? 'Boleto',
                "text_4nirf" => $dadosFormatados['cpf'] ?? '',
                "text_5igbr" => $dadosFormatados['representanteLegal'] ?? '',
                "textarea_6pyef" => $dadosFormatados['numeroUnidade'] ?? '',
                "textarea_7wrsb" => $dadosFormatados['logradouro'] ?? '',
                "text_15goku" => $dadosFormatados['dia'] ?? '',
                "text_16bzyc" => $dadosFormatados['mes'] ?? '',
                "text_13gmsz" => $dadosFormatados['economia'] ?? ''
            ];

            // Criar arquivo FDF (Form Data Format)
            $fdfPath = $this->criarArquivoFDF($mapeamento);
            $outputPath = storage_path('app/temp/output_' . time() . '.pdf');

            // Comando PDFtk
            $comando = "pdftk \"{$this->templatePath}\" fill_form \"{$fdfPath}\" output \"{$outputPath}\" flatten";
            
            Log::info('🛠️ Executando PDFtk', ['comando' => $comando]);
            
            exec($comando, $output, $returnCode);

            if ($returnCode === 0 && file_exists($outputPath)) {
                $pdfPreenchido = file_get_contents($outputPath);
                
                // Limpar arquivos temporários
                unlink($fdfPath);
                unlink($outputPath);
                
                Log::info('✅ PDF preenchido com PDFtk');
                return $pdfPreenchido;
            } else {
                throw new \Exception("PDFtk falhou: código $returnCode");
            }

        } catch (\Exception $e) {
            Log::error('❌ PDFtk não disponível ou falhou', [
                'error' => $e->getMessage()
            ]);
            
            // Fallback: retornar dados para frontend
            return $this->gerarTermoAdesao($dados);
        }
    }

    /**
     * Criar arquivo FDF para PDFtk
     */
    private function criarArquivoFDF(array $mapeamento): string
    {
        $fdfPath = storage_path('app/temp/form_data_' . time() . '.fdf');
        
        $fdfContent = "%FDF-1.2\n1 0 obj\n<<\n/FDF << /Fields [";
        
        foreach ($mapeamento as $campo => $valor) {
            if (!empty($valor)) {
                $valorLimpo = $this->limparTextoParaPDF($valor);
                $valorEscapado = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $valorLimpo);
                $fdfContent .= "\n<< /T ({$campo}) /V ({$valorEscapado}) >>";
            }
        }
        
        $fdfContent .= "\n] >>\n>>\nendobj\ntrailer\n<<\n/Root 1 0 R\n>>\n%%EOF";
        
        file_put_contents($fdfPath, $fdfContent);
        
        return $fdfPath;
    }

    /**
     * ✅ MÉTODO PARA USO COM FRONTEND - Retornar template + dados
     */
    public function prepararParaFrontend(array $dados): array
    {
        Log::info('🌐 Preparando dados para processamento no frontend');

        try {
            // Ler template em base64
            $templateBase64 = base64_encode(file_get_contents($this->templatePath));
            
            // Preparar dados
            $dadosFormatados = $this->prepararDadosParaPDF($dados);
            
            // Mapeamento de campos
            $mapeamento = [
                "text_1semi" => $dadosFormatados['nomeAssociado'] ?? '',
                "text_2jyxc" => $dadosFormatados['endereco'] ?? '',
                "text_3qmpl" => $dadosFormatados['formaPagamento'] ?? 'Boleto',
                "text_4nirf" => $dadosFormatados['cpf'] ?? '',
                "text_5igbr" => $dadosFormatados['representanteLegal'] ?? '',
                "textarea_6pyef" => $dadosFormatados['numeroUnidade'] ?? '',
                "textarea_7wrsb" => $dadosFormatados['logradouro'] ?? '',
                "text_15goku" => $dadosFormatados['dia'] ?? '',
                "text_16bzyc" => $dadosFormatados['mes'] ?? '',
                "text_13gmsz" => $dadosFormatados['economia'] ?? ''
            ];

            return [
                'sucesso' => true,
                'template_base64' => $templateBase64,
                'mapeamento_campos' => $mapeamento,
                'dados_formatados' => $dadosFormatados,
                'instrucao' => 'Usar pdf-lib.js para preencher form fields no template'
            ];

        } catch (\Exception $e) {
            return [
                'sucesso' => false,
                'erro' => $e->getMessage()
            ];
        }
    }

    /**
     * ✅ FALLBACK: PDF simples quando form fields não funcionam
     */
    private function criarPDFSimplesComDados(array $dados): string
    {
        Log::info('📄 Criando PDF simples com dados (último recurso)');

        // Template PDF básico mas válido
        $dadosTexto = '';
        foreach ($dados as $campo => $valor) {
            if (!empty($valor)) {
                $label = $this->obterLabelCampo($campo);
                $dadosTexto .= "({$label}: {$valor}) Tj 0 -20 Td ";
            }
        }

        $pdf = "%PDF-1.4
1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj
3 0 obj<</Type/Page/Parent 2 0 R/Resources<</Font<</F1 4 0 R>>>>/MediaBox[0 0 612 792]/Contents 5 0 R>>endobj
4 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj
5 0 obj<</Length 200>>stream
BT
/F1 16 Tf
50 720 Td
(TERMO DE ADESAO - AUPUS ENERGIA) Tj
0 -30 Td
/F1 12 Tf
{$dadosTexto}
ET
endstream
endobj
xref
0 6
0000000000 65535 f 
0000000010 00000 n 
0000000053 00000 n 
0000000100 00000 n 
0000000200 00000 n 
0000000270 00000 n 
trailer<</Size 6/Root 1 0 R>>
startxref
320
%%EOF";

        return $pdf;
    }

    /**
     * Preparar dados para PDF
     */
    private function prepararDadosParaPDF(array $dados): array
    {
        // ✅ CORREÇÃO: Usar fuso horário do Brasil
        $agora = \Carbon\Carbon::now('America/Sao_Paulo');
        
        Log::info('📅 Data/hora atual', [
            'utc' => \Carbon\Carbon::now()->format('d/m/Y H:i:s'),
            'brasil' => $agora->format('d/m/Y H:i:s'),
            'timezone' => $agora->timezoneName
        ]);
        
        $cpfCnpj = '';
        if (($dados['tipoDocumento'] ?? '') === 'CPF') {
            $cpfCnpj = $dados['cpf'] ?? '';
        } else {
            $cpfCnpj = $dados['cnpj'] ?? '';
        }

        $dadosBrutos = [
            'nomeAssociado' => $dados['nomeCliente'] ?? '',
            'endereco' => $dados['enderecoUC'] ?? '',
            'formaPagamento' => 'Boleto',
            'cpf' => $cpfCnpj,
            'representanteLegal' => $dados['nomeRepresentante'] ?? '',
            'numeroUnidade' => (string)($dados['numeroUC'] ?? ''),
            'logradouro' => $dados['logradouroUC'] ?? '',
            'dia' => $agora->format('d'),   
            'mes' => $agora->format('m'),  
            'economia' => '       ' . ($dados['descontoTarifa'] ?? '0') . '%'
        ];

        // ✅ LIMPAR TODOS OS DADOS PARA UTF-8
        $dadosLimpos = [];
        foreach ($dadosBrutos as $campo => $valor) {
            $dadosLimpos[$campo] = $this->limparTextoParaPDF($valor);
        }


        return $dadosLimpos;
    }

    /**
     * Obter label do campo
     */
    private function obterLabelCampo(string $campo): string
    {
        $labels = [
            'nomeAssociado' => 'Nome do Associado',
            'endereco' => 'Endereco da UC',
            'formaPagamento' => 'Forma de Pagamento',
            'cpf' => 'CPF/CNPJ',
            'representanteLegal' => 'Representante Legal',
            'numeroUnidade' => 'Numero da UC',
            'logradouro' => 'Logradouro',
            'dia' => 'Dia',
            'mes' => 'Mes',
            'economia' => 'Desconto Tarifa'
        ];
        
        return $labels[$campo] ?? ucfirst($campo);
    }

    private function limparTextoParaPDF(string $texto): string
    {
        // 1. Garantir UTF-8
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'auto');
        }
        
        // 2. ✅ SUBSTITUIR APENAS CARACTERES REALMENTE PROBLEMÁTICOS
        $substituicoes = [
            // Manter nº como n° (não como "no")
            'nº' => 'numero',    // Número - usa grau simples
            'Nº' => 'numero',    // Número maiúsculo
            'ç' => 'c',    // Número maiúsculo
            // Caracteres especiais problemáticos
            '€' => 'EUR',    // Euro
            '£' => 'GBP',    // Libra
            '¢' => 'cent',   // Centavo
            '§' => 'par',    // Parágrafo
            '©' => '(c)',    // Copyright
            '®' => '(R)',    // Registrado
            '™' => '(TM)',   // Trademark
            '…' => '...',    // Reticências
            '"' => '"',      // Aspas esquerdas
            '"' => '"',      // Aspas direitas
            '–' => '-',      // En dash
            '—' => '-',      // Em dash
        ];
        
        $textoLimpo = str_replace(array_keys($substituicoes), array_values($substituicoes), $texto);
        
        // 3. ✅ MANTER ACENTOS BÁSICOS E Ç - eles geralmente funcionam
        // Não alterar: ã, á, é, í, ó, ú, â, ê, ô, à, ç, Ç
        
        // 4. Remover apenas caracteres de controle
        $textoLimpo = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $textoLimpo);
        
        return $textoLimpo;
    }

    // Compatibilidade
    public function gerarTermoPreenchido(array $dados): string
    {
        return $this->gerarTermoAdesao($dados);
    }
}