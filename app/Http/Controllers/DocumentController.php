<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\AutentiqueService;
use App\Services\PDFGeneratorService;
use App\Models\Document;
use App\Models\Proposta;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class DocumentController extends Controller
{
    private $autentiqueService;
    private $pdfGeneratorService;

    public function __construct(
        AutentiqueService $autentiqueService,
        PDFGeneratorService $pdfGeneratorService
    ) {
        $this->autentiqueService = $autentiqueService;
        $this->pdfGeneratorService = $pdfGeneratorService;
    }

    /**
     * Gera e envia termo de adesão para assinatura
     */
    public function gerarTermoAdesao(Request $request, $propostaId): JsonResponse
    {
        Log::info('=== INÍCIO GERAÇÃO TERMO DE ADESÃO ===', [
            'proposta_id' => $propostaId,
            'user_id' => auth()->id()
        ]);

        try {
            // Validação dos dados recebidos
            $validator = Validator::make($request->all(), [
                'nomeCliente' => 'required|string|max:255',
                'numeroUC' => 'required',
                'enderecoUC' => 'required|string',
                'tipoDocumento' => 'required|in:CPF,CNPJ',
                'nomeRepresentante' => 'required|string|max:255',
                'enderecoRepresentante' => 'required|string',
                'emailRepresentante' => 'required|email',
                'whatsappRepresentante' => 'nullable|string',
                'descontoTarifa' => 'required|numeric|min:0|max:100',
                'logradouroUC' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos para gerar o termo',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Buscar proposta
            $proposta = Proposta::findOrFail($propostaId);
            
            // Verificar se já existe documento pendente para esta proposta
            $documentoExistente = Document::where('proposta_id', $propostaId)
                ->where('status', Document::STATUS_PENDING)
                ->first();

            if ($documentoExistente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Já existe um termo pendente de assinatura para esta proposta',
                    'documento' => [
                        'id' => $documentoExistente->id,
                        'status' => $documentoExistente->status,
                        'nome' => $documentoExistente->name,
                        'progresso' => $documentoExistente->signing_progress . '%'
                    ]
                ], 409);
            }

            // Preparar dados para o PDF baseado no mapeamento fornecido
            $dadosParaPDF = $this->prepararDadosParaPDF($request->all());

            // Gerar PDF preenchido
            $pdfContent = $this->pdfGeneratorService->gerarTermoPreenchido($dadosParaPDF);

            // Preparar signatário
            $signatarios = [
                [
                    'email' => $request->emailRepresentante,
                    'action' => 'SIGN',
                    'name' => $request->nomeRepresentante
                ]
            ];

            // Criar documento na Autentique
            $resultado = $this->autentiqueService->createDocumentFromProposta(
                $dadosParaPDF,
                $signatarios,
                $pdfContent,
                env('AUTENTIQUE_SANDBOX', true)
            );

            // Salvar documento no banco local
            $document = Document::create([
                'autentique_id' => $resultado['id'],
                'name' => $dadosParaPDF['nomeAssociado'] ? "Termo de Adesão - {$dadosParaPDF['nomeAssociado']}" : "Termo de Adesão",
                'status' => Document::STATUS_PENDING,
                'is_sandbox' => env('AUTENTIQUE_SANDBOX', true),
                'proposta_id' => $propostaId,
                'document_data' => $dadosParaPDF,
                'signers' => $signatarios,
                'autentique_response' => $resultado,
                'total_signers' => count($signatarios),
                'signed_count' => 0,
                'rejected_count' => 0,
                'autentique_created_at' => now(),
                'created_by' => auth()->id()
            ]);

            // Extrair links de assinatura
            $linkAssinatura = null;
            if (isset($resultado['signatures'][0]['link']['short_link'])) {
                $linkAssinatura = $resultado['signatures'][0]['link']['short_link'];
            }

            Log::info('✅ Termo de adesão gerado com sucesso', [
                'proposta_id' => $propostaId,
                'document_id' => $document->id,
                'autentique_id' => $resultado['id'],
                'signatario' => $request->emailRepresentante,
                'link_assinatura' => $linkAssinatura
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Termo de adesão gerado e enviado para assinatura com sucesso!',
                'documento' => [
                    'id' => $document->id,
                    'autentique_id' => $resultado['id'],
                    'nome' => $document->name,
                    'status' => $document->status_label,
                    'progresso' => $document->signing_progress . '%',
                    'link_assinatura' => $linkAssinatura,
                    'signatario' => $request->nomeRepresentante,
                    'email_signatario' => $request->emailRepresentante,
                    'criado_em' => $document->created_at->format('d/m/Y H:i')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao gerar termo de adesão', [
                'proposta_id' => $propostaId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao gerar o termo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Preparar dados para o PDF baseado no mapeamento fornecido
     */
    private function prepararDadosParaPDF($dados): array
    {
        $agora = Carbon::now();
        
        // Determinar CPF/CNPJ baseado no tipo
        $cpfCnpj = '';
        if ($dados['tipoDocumento'] === 'CPF') {
            $cpfCnpj = $dados['cpf'] ?? '';
        } else {
            $cpfCnpj = $dados['cnpj'] ?? '';
        }

        return [
            'nomeAssociado' => $dados['nomeCliente'] ?? '',
            'endereco' => $dados['enderecoUC'] ?? '',
            'formaPagamento' => 'Boleto', // ← Sempre fixo
            'cpf' => $cpfCnpj,
            'representanteLegal' => $dados['nomeRepresentante'] ?? '',
            'numeroUnidade' => (string)($dados['numeroUC'] ?? ''),
            'logradouro' => $dados['logradouroUC'] ?? '',
            'dia' => $agora->format('d'),
            'mes' => $agora->format('m'),
            'economia' => $dados['descontoTarifa'] ?? '0'
        ];
    }

    /**
     * Finalizar criação do documento após preenchimento no frontend
     */
    public function finalizarDocumento(Request $request): JsonResponse
    {
        Log::info('=== FINALIZANDO DOCUMENTO PREENCHIDO ===', [
            'proposta_id' => $request->proposta_id
        ]);

        try {
            $validator = Validator::make($request->all(), [
                'proposta_id' => 'required|string',
                'pdf_base64' => 'required|string',
                'dados' => 'required|array',
                'signatarios' => 'required|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Decodificar PDF
            $pdfContent = base64_decode($request->pdf_base64);
            if (!$pdfContent) {
                throw new \Exception('Erro ao decodificar PDF base64');
            }

            // Criar documento na Autentique
            $resultado = $this->autentiqueService->createDocumentFromProposta(
                $request->dados,
                $request->signatarios,
                $pdfContent,
                $request->sandbox ?? env('AUTENTIQUE_SANDBOX', true)
            );

            // Salvar documento no banco local
            $document = Document::create([
                'autentique_id' => $resultado['id'],
                'name' => $request->dados['nomeAssociado'] ? "Termo de Adesão - {$request->dados['nomeAssociado']}" : "Termo de Adesão",
                'status' => Document::STATUS_PENDING,
                'is_sandbox' => $request->sandbox ?? env('AUTENTIQUE_SANDBOX', true),
                'proposta_id' => $request->proposta_id,
                'document_data' => $request->dados,
                'signers' => $request->signatarios,
                'autentique_response' => $resultado,
                'total_signers' => count($request->signatarios),
                'signed_count' => 0,
                'rejected_count' => 0,
                'autentique_created_at' => now(),
                'created_by' => auth()->id()
            ]);

            // Extrair links de assinatura
            $linkAssinatura = null;
            if (isset($resultado['signatures'][0]['link']['short_link'])) {
                $linkAssinatura = $resultado['signatures'][0]['link']['short_link'];
            }

            Log::info('✅ Documento finalizado com sucesso', [
                'document_id' => $document->id,
                'autentique_id' => $resultado['id']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Termo de adesão gerado e enviado para assinatura com sucesso!',
                'documento' => [
                    'id' => $document->id,
                    'autentique_id' => $resultado['id'],
                    'nome' => $document->name,
                    'status' => $document->status_label,
                    'progresso' => $document->signing_progress . '%',
                    'link_assinatura' => $linkAssinatura,
                    'signatario' => $request->signatarios[0]['name'] ?? '',
                    'email_signatario' => $request->signatarios[0]['email'] ?? '',
                    'criado_em' => $document->created_at->format('d/m/Y H:i')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao finalizar documento', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao finalizar documento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Webhook da Autentique para receber notificações
     */
    public function webhook(Request $request): JsonResponse
    {
        Log::info('🎣 WEBHOOK RECEBIDO', [
            'headers' => $request->headers->all(),
            'body' => $request->all()
        ]);

        try {
            $eventType = $request->input('event') ?? $request->input('type');
            $eventData = $request->input('data') ?? $request->all();

            Log::info('📥 Processando evento webhook', [
                'event_type' => $eventType,
                'document_id' => $eventData['id'] ?? 'N/A'
            ]);

            switch ($eventType) {
                case 'document.finished':
                    $this->handleDocumentFinished($eventData, $request->all());
                    break;
                    
                case 'document.updated':
                    $this->handleDocumentUpdated($eventData, $request->all());
                    break;
                    
                case 'signature.accepted':
                    $this->handleSignatureAccepted($eventData, $request->all());
                    break;
                    
                case 'signature.rejected':
                    $this->handleSignatureRejected($eventData, $request->all());
                    break;
                    
                default:
                    Log::info('ℹ️ Evento webhook não mapeado', ['event_type' => $eventType]);
            }

            return response()->json(['success' => true, 'message' => 'Webhook processado']);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao processar webhook', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle document.finished - documento totalmente assinado
     */
    private function handleDocumentFinished(array $eventData, array $fullEvent)
    {
        $documentId = $eventData['id'] ?? null;
        if (!$documentId) return;

        $localDocument = Document::where('autentique_id', $documentId)->first();
        if (!$localDocument) {
            Log::warning('Documento não encontrado localmente', ['document_id' => $documentId]);
            return;
        }

        $oldStatus = $localDocument->status;
        $localDocument->update([
            'status' => Document::STATUS_SIGNED,
            'signed_count' => $localDocument->total_signers,
            'last_checked_at' => now(),
            'autentique_response' => $fullEvent
        ]);

        Log::info('🎉 DOCUMENTO TOTALMENTE ASSINADO!', [
            'document_id' => $localDocument->autentique_id,
            'document_name' => $localDocument->name,
            'proposta_id' => $localDocument->proposta_id
        ]);
    }

    /**
     * Handle document.updated - documento foi atualizado
     */
    private function handleDocumentUpdated(array $eventData, array $fullEvent)
    {
        $documentId = $eventData['id'] ?? null;
        if (!$documentId) return;

        $localDocument = Document::where('autentique_id', $documentId)->first();
        if (!$localDocument) return;

        // Atualizar dados do documento
        $localDocument->update([
            'last_checked_at' => now(),
            'autentique_response' => $fullEvent
        ]);

        Log::info('📝 Documento atualizado via webhook', [
            'document_id' => $documentId,
            'status' => $localDocument->status
        ]);
    }

    /**
     * Handle signature.accepted - signatário assinou
     */
    private function handleSignatureAccepted(array $eventData, array $fullEvent)
    {
        $documentId = $eventData['document'] ?? $eventData['id'];
        $signerName = $eventData['user']['name'] ?? 'Signatário';
        $signerEmail = $eventData['user']['email'] ?? null;

        if (!$documentId) return;

        $localDocument = Document::where('autentique_id', $documentId)->first();
        if (!$localDocument) return;

        // Incrementar contador de assinaturas
        $localDocument->increment('signed_count');
        $localDocument->update(['last_checked_at' => now()]);

        Log::info('✍️ ASSINATURA ACEITA!', [
            'document_id' => $documentId,
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'progress' => $localDocument->signing_progress . '%'
        ]);
    }

    /**
     * Handle signature.rejected - signatário recusou
     */
    private function handleSignatureRejected(array $eventData, array $fullEvent)
    {
        $documentId = $eventData['document'] ?? $eventData['id'];
        $signerName = $eventData['user']['name'] ?? 'Signatário';

        if (!$documentId) return;

        $localDocument = Document::where('autentique_id', $documentId)->first();
        if (!$localDocument) return;

        $localDocument->update([
            'status' => Document::STATUS_REJECTED,
            'rejected_count' => $localDocument->rejected_count + 1,
            'last_checked_at' => now(),
            'autentique_response' => $fullEvent
        ]);

        Log::info('❌ ASSINATURA REJEITADA!', [
            'document_id' => $documentId,
            'signer_name' => $signerName
        ]);
    }
    public function buscarStatusDocumento(Request $request, $propostaId): JsonResponse
    {
        try {
            $documento = Document::where('proposta_id', $propostaId)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$documento) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum documento encontrado para esta proposta'
                ], 404);
            }

            // Buscar link de assinatura se disponível
            $linkAssinatura = null;
            if ($documento->autentique_response && isset($documento->autentique_response['signatures'][0]['link']['short_link'])) {
                $linkAssinatura = $documento->autentique_response['signatures'][0]['link']['short_link'];
            }

            return response()->json([
                'success' => true,
                'documento' => [
                    'id' => $documento->id,
                    'nome' => $documento->name,
                    'status' => $documento->status_label,
                    'progresso' => $documento->signing_progress . '%',
                    'link_assinatura' => $linkAssinatura,
                    'criado_em' => $documento->created_at->format('d/m/Y H:i'),
                    'atualizado_em' => $documento->updated_at->format('d/m/Y H:i')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao buscar status do documento', [
                'proposta_id' => $propostaId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar status do documento'
            ], 500);
        }
    }

    public function gerarTermoCompleto(Request $request, $propostaId): JsonResponse
    {
        Log::info('=== INÍCIO GERAÇÃO TERMO COMPLETO ===', [
            'proposta_id' => $propostaId,
            'user_id' => auth()->id()
        ]);

        Log::info('=== DADOS RECEBIDOS PARA VALIDAÇÃO ===', [
            'request_all' => $request->all(),
            'validation_rules' => [
                'nomeCliente', 'numeroUC', 'enderecoUC', 'tipoDocumento',
                'nomeRepresentante', 'enderecoRepresentante', 'emailRepresentante',
                'economia', 'formaPagamento', 'logradouro'
            ]
        ]);

        try {
            // Reutilizar validação do método existente
            $validator = Validator::make($request->all(), [
                'nomeCliente' => 'required|string|max:255',
                'numeroUC' => 'required',
                'enderecoUC' => 'required|string',
                'tipoDocumento' => 'required|in:CPF,CNPJ',
                'nomeRepresentante' => 'required|string|max:255',
                'enderecoRepresentante' => 'required|string',
                'emailRepresentante' => 'required|email',
                'whatsappRepresentante' => 'nullable|string',
                'descontoTarifa' => 'required|numeric|min:0|max:100', 
                'logradouroUC' => 'required|string'  
            ]);

            if ($validator->fails()) {
                // Debug detalhado dos erros de validação
                Log::error('=== VALIDAÇÃO FALHOU - DEBUG DETALHADO ===', [
                    'errors' => $validator->errors()->all(),
                    'errors_by_field' => $validator->errors()->toArray(),
                    'campos_recebidos' => array_keys($request->all()),
                    'valores_problemáticos' => [
                        'numeroUC' => [
                            'valor' => $request->numeroUC,
                            'tipo' => gettype($request->numeroUC),
                            'é_string' => is_string($request->numeroUC)
                        ],
                        'descontoTarifa' => [
                            'valor' => $request->descontoTarifa,
                            'tipo' => gettype($request->descontoTarifa),
                            'é_numeric' => is_numeric($request->descontoTarifa)
                        ]
                    ]
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos para gerar o termo',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Buscar proposta
            $proposta = Proposta::findOrFail($propostaId);
            
            // Verificar documento existente
            $documentoExistente = Document::where('proposta_id', $propostaId)
                ->where('status', Document::STATUS_PENDING)
                ->first();

            if ($documentoExistente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Já existe um termo pendente de assinatura para esta proposta',
                    'documento' => [
                        'id' => $documentoExistente->id,
                        'status' => $documentoExistente->status,
                        'nome' => $documentoExistente->name,
                        'progresso' => $documentoExistente->signing_progress,
                        'link_assinatura' => $documentoExistente->signing_url,
                        'email_signatario' => $documentoExistente->signer_email
                    ]
                ], 409);
            }

            // 1. Gerar PDF usando o service
            Log::info('📄 Gerando PDF...');
            $dadosParaPDF = array_merge($request->all(), [
                'numeroProposta' => $proposta->numero_proposta,
                'nomeCliente' => $proposta->nome_cliente,
                'consultor' => $proposta->consultor
            ]);
            
            $pdfContent = $this->pdfGeneratorService->gerarTermoAdesao($dadosParaPDF);
            
            // 2. Enviar para Autentique
            Log::info('📤 Enviando para Autentique...');
            $resultado = $this->autentiqueService->criarDocumento([
                'nome' => "Termo de Adesão - {$proposta->numero_proposta}",
                'conteudo_pdf' => $pdfContent,
                'signatarios' => [[
                    'email' => $request->emailRepresentante,
                    'nome' => $request->nomeRepresentante
                ]]
            ]);

            // ✅ CORREÇÃO: Acessar a estrutura correta da resposta
            $documento = $resultado['createDocument'] ?? $resultado;

            Log::info('=== DEBUG DOCUMENTO RETORNADO ===', [
                'resultado_keys' => array_keys($resultado),
                'documento_keys' => array_keys($documento),
                'documento_id' => $documento['id'] ?? 'NAO_ENCONTRADO'
            ]);

            // 3. Salvar no banco
            $documentoSalvo = Document::create([
                'proposta_id' => $proposta->id,
                'autentique_id' => $documento['id'],
                'name' => "Termo de Adesão - {$proposta->numero_proposta}",
                'status' => Document::STATUS_PENDING,
                'signer_email' => $request->emailRepresentante,
                'signer_name' => $request->nomeRepresentante,
                'signing_url' => $documento['signatures'][0]['link']['short_link'] ?? null,
                'created_by' => auth()->id(),
                
                // ✅ ADICIONAR CAMPOS OBRIGATÓRIOS:
                'document_data' => $request->all(), // Dados da requisição
                'signers' => [[
                    'email' => $request->emailRepresentante,
                    'name' => $request->nomeRepresentante,
                    'action' => 'SIGN'
                ]], // Array de signatários
                'autentique_response' => $documento, // Resposta da Autentique
                'is_sandbox' => env('AUTENTIQUE_SANDBOX', true),
                'total_signers' => 1,
                'signed_count' => 0,
                'rejected_count' => 0,
                'autentique_created_at' => $documento['created_at'] ?? now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Termo gerado e enviado para assinatura com sucesso!',
                'documento' => [
                    'id' => $documentoSalvo->id,
                    'status' => $documentoSalvo->status,
                    'nome' => $documentoSalvo->name,
                    'link_assinatura' => $documentoSalvo->signing_url,
                    'email_signatario' => $documentoSalvo->signer_email
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao gerar termo completo', [
                'proposta_id' => $propostaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao gerar termo: ' . $e->getMessage()
            ], 500);
        }
    }
}