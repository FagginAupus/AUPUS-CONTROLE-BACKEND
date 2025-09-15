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
        
        if ($localDocument->proposta_id && $localDocument->document_data) {
            $dadosDocumento = $localDocument->document_data;
            $numeroUC = $dadosDocumento['numeroUC'] ?? null;
            
            if ($numeroUC) {
                // Buscar proposta e alterar status da UC
                $proposta = \App\Models\Proposta::find($localDocument->proposta_id);
                if ($proposta) {
                    $unidadesConsumidoras = json_decode($proposta->unidades_consumidoras ?? '[]', true);
                    
                    foreach ($unidadesConsumidoras as &$uc) {
                        if (($uc['numero_unidade'] ?? $uc['numeroUC']) == $numeroUC) {
                            $statusAnterior = $uc['status'] ?? null;
                            $uc['status'] = 'Fechada';
                            
                            Log::info('Status UC alterado automaticamente após assinatura', [
                                'proposta_id' => $localDocument->proposta_id,
                                'numero_uc' => $numeroUC,
                                'status_anterior' => $statusAnterior,
                                'status_novo' => 'Fechada'
                            ]);
                            break;
                        }
                    }
                    
                    $proposta->update([
                        'unidades_consumidoras' => json_encode($unidadesConsumidoras)
                    ]);
                    
                    // Adicionar ao controle automaticamente
                    if ($numeroUC) {
                        // Usar método existente para popular controle
                        app(PropostaController::class)->popularControleAutomaticoParaUC($localDocument->proposta_id, $numeroUC);
                    }
                }
            }
        }
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

    /**
     * ✅ GERAÇÃO TERMO - USANDO PREENCHIMENTO REAL DE FORM FIELDS
     */
    public function gerarTermoCompleto(Request $request, $propostaId): JsonResponse
    {
        Log::info('=== INÍCIO GERAÇÃO TERMO COM FORM FIELDS ===', [
            'proposta_id' => $propostaId,
            'user_id' => auth()->id()
        ]);

        try {
            // Validação
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

            // Verificar documento existente
            $documentoExistente = Document::where('proposta_id', $propostaId)
                ->where('status', Document::STATUS_PENDING)
                ->first();

            if ($documentoExistente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Já existe um termo pendente de assinatura para esta proposta. Para atualizar o termo, cancele o atual primeiro.',
                    'documento' => [
                        'id' => $documentoExistente->id,
                        'autentique_id' => $documentoExistente->autentique_id,
                        'nome' => $documentoExistente->name,
                        'status' => $documentoExistente->status_label,
                        'progresso' => $documentoExistente->signing_progress . '%',
                        'link_assinatura' => null, // Não fornecer link para forçar cancelamento
                        'criado_em' => $documentoExistente->created_at->format('d/m/Y H:i'),
                        'opcoes' => [
                            'pode_cancelar' => true,
                            'pode_atualizar' => false
                        ]
                    ]
                ], 409);
            }

            // ✅ NOVA ESTRATÉGIA: Tentar diferentes métodos de preenchimento
            $dadosCompletos = array_merge($request->all(), [
                'numeroProposta' => $proposta->numero_proposta,
                'nomeCliente' => $proposta->nome_cliente,
                'consultor' => $proposta->consultor
            ]);

            Log::info('🎯 Tentando preenchimento de PDF com form fields');

            // 1️⃣ PRIMEIRA OPÇÃO: PDFtk (melhor para form fields)
            $pdfContent = $this->tentarPreenchimentoPDFtk($dadosCompletos);

            // 2️⃣ SEGUNDA OPÇÃO: Frontend com pdf-lib.js  
            if (!$pdfContent) {
                Log::info('📤 PDFtk não disponível, preparando para frontend');
                return $this->prepararParaPreenchimentoFrontend($dadosCompletos, $proposta, $request);
            }

            // ✅ 3️⃣ ENVIAR PARA AUTENTIQUE
            $resultado = $this->autentiqueService->criarDocumento([
                'nome' => "Termo de Adesão - {$proposta->numero_proposta}",
                'conteudo_pdf' => $pdfContent,
                'signatarios' => [[
                    'email' => $request->emailRepresentante,
                    'nome' => $request->nomeRepresentante
                ]]
            ]);

            $documento = $resultado['createDocument'] ?? $resultado;

            // Salvar no banco
            $documentoSalvo = Document::create([
                'proposta_id' => $proposta->id,
                'autentique_id' => $documento['id'],
                'name' => "Termo de Adesão - {$proposta->numero_proposta}",
                'status' => Document::STATUS_PENDING,
                'signer_email' => $request->emailRepresentante,
                'signer_name' => $request->nomeRepresentante,
                'signing_url' => $documento['signatures'][0]['link']['short_link'] ?? null,
                'created_by' => auth()->id(),
                'document_data' => $request->all(),
                'signers' => [[
                    'email' => $request->emailRepresentante,
                    'name' => $request->nomeRepresentante,
                    'action' => 'SIGN'
                ]],
                'autentique_response' => $documento,
                'is_sandbox' => env('AUTENTIQUE_SANDBOX', true),
                'total_signers' => 1,
                'signed_count' => 0,
                'rejected_count' => 0,
                'autentique_created_at' => $documento['created_at'] ?? now()
            ]);

            Log::info('✅ Termo gerado e enviado com sucesso');

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
            Log::error('❌ Erro ao gerar termo', [
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

    /**
     * ✅ MÉTODO 1: Tentar preenchimento com PDFtk
     */
    private function tentarPreenchimentoPDFtk(array $dados): ?string
    {
        try {
            // Verificar se PDFtk está instalado
            exec('which pdftk', $output, $returnCode);
            
            if ($returnCode !== 0) {
                Log::info('⚠️ PDFtk não encontrado no sistema');
                return null;
            }

            Log::info('🔧 PDFtk encontrado, tentando preenchimento');
            
            return $this->pdfGeneratorService->preencherComPDFtk($dados);

        } catch (\Exception $e) {
            Log::warning('⚠️ Erro com PDFtk', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * ✅ MÉTODO 2: Preparar para preenchimento no frontend
     */
    private function prepararParaPreenchimentoFrontend(array $dados, $proposta, $request): JsonResponse
    {
        try {
            $dadosParaFrontend = $this->pdfGeneratorService->prepararParaFrontend($dados);

            if (!$dadosParaFrontend['sucesso']) {
                throw new \Exception($dadosParaFrontend['erro']);
            }

            Log::info('📋 Dados preparados para frontend', [
                'campos_preenchidos' => count(array_filter($dadosParaFrontend['mapeamento_campos']))
            ]);

            return response()->json([
                'success' => true,
                'tipo' => 'preenchimento_frontend',
                'message' => 'Template e dados preparados para preenchimento no frontend',
                'dados' => [
                    'template_base64' => $dadosParaFrontend['template_base64'],
                    'mapeamento_campos' => $dadosParaFrontend['mapeamento_campos'],
                    'proposta' => [
                        'id' => $proposta->id,
                        'numero' => $proposta->numero_proposta,
                        'nome_cliente' => $proposta->nome_cliente
                    ],
                    'signatario' => [
                        'nome' => $request->nomeRepresentante,
                        'email' => $request->emailRepresentante
                    ]
                ],
                'instrucoes' => [
                    'usar_pdf_lib' => true,
                    'preencher_form_fields' => true,
                    'enviar_preenchido_para' => '/api/documentos/finalizar-preenchido'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao preparar para frontend', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao preparar dados para preenchimento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ MÉTODO 3: Receber PDF preenchido do frontend
     */
    public function finalizarPreenchido(Request $request): JsonResponse
    {
        Log::info('📥 Recebendo PDF preenchido do frontend');

        try {
            $validator = Validator::make($request->all(), [
                'proposta_id' => 'required|string',
                'pdf_base64' => 'required|string',
                'dados' => 'required|array',
                'signatario' => 'required|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Decodificar PDF preenchido
            $pdfContent = base64_decode($request->pdf_base64);
            if (!$pdfContent) {
                throw new \Exception('Erro ao decodificar PDF');
            }

            $proposta = Proposta::findOrFail($request->proposta_id);

            // Enviar para Autentique
            $resultado = $this->autentiqueService->criarDocumento([
                'nome' => "Termo de Adesão - {$proposta->numero_proposta}",
                'conteudo_pdf' => $pdfContent,
                'signatarios' => [[
                    'email' => $request->signatario['email'],
                    'nome' => $request->signatario['nome']
                ]]
            ]);

            $documento = $resultado['createDocument'] ?? $resultado;

            // Salvar no banco
            $documentoSalvo = Document::create([
                'proposta_id' => $request->proposta_id,
                'autentique_id' => $documento['id'],
                'name' => "Termo de Adesão - {$proposta->numero_proposta}",
                'status' => Document::STATUS_PENDING,
                'signer_email' => $request->signatario['email'],
                'signer_name' => $request->signatario['nome'],
                'signing_url' => $documento['signatures'][0]['link']['short_link'] ?? null,
                'created_by' => auth()->id(),
                'document_data' => $request->dados,
                'signers' => [$request->signatario],
                'autentique_response' => $documento,
                'is_sandbox' => env('AUTENTIQUE_SANDBOX', true),
                'total_signers' => 1,
                'signed_count' => 0,
                'rejected_count' => 0,
                'autentique_created_at' => $documento['created_at'] ?? now()
            ]);

            Log::info('✅ PDF preenchido processado com sucesso');

            return response()->json([
                'success' => true,
                'message' => 'Termo preenchido e enviado para assinatura!',
                'documento' => [
                    'id' => $documentoSalvo->id,
                    'status' => $documentoSalvo->status,
                    'nome' => $documentoSalvo->name,
                    'link_assinatura' => $documentoSalvo->signing_url
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao finalizar preenchimento', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar PDF preenchido: ' . $e->getMessage()
            ], 500);
        }
    }

    public function cancelarDocumentoPendente($propostaId): JsonResponse
    {
        try {
            $documento = Document::where('proposta_id', $propostaId)
                ->where('status', Document::STATUS_PENDING)
                ->first();

            if (!$documento) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum documento pendente encontrado'
                ], 404);
            }

            // Cancelar na Autentique se necessário
            try {
                $this->autentiqueService->cancelDocument($documento->autentique_id);
            } catch (\Exception $e) {
                Log::warning('Erro ao cancelar documento na Autentique', ['error' => $e->getMessage()]);
            }

            // Marcar como cancelado localmente
            $documento->update([
                'status' => Document::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Documento cancelado com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao cancelar documento', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erro ao cancelar documento'
            ], 500);
        }
    }

    public function baixarPDFAssinado($propostaId): JsonResponse
    {
        try {
            $documento = Document::where('proposta_id', $propostaId)
                ->where('status', Document::STATUS_SIGNED)
                ->first();

            if (!$documento) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum documento assinado encontrado'
                ], 404);
            }

            // Buscar PDF assinado na Autentique
            $pdfAssinado = $this->autentiqueService->downloadSignedDocument($documento->autentique_id);
            
            if (!$pdfAssinado) {
                return response()->json([
                    'success' => false,
                    'message' => 'PDF assinado não encontrado na Autentique'
                ], 404);
            }

            // Salvar PDF localmente para cache
            $nomeArquivo = "termo_assinado_{$documento->id}.pdf";
            $caminhoLocal = storage_path("app/public/termos_assinados/{$nomeArquivo}");
            
            if (!is_dir(dirname($caminhoLocal))) {
                mkdir(dirname($caminhoLocal), 0755, true);
            }
            
            file_put_contents($caminhoLocal, $pdfAssinado);

            return response()->json([
                'success' => true,
                'documento' => [
                    'nome' => $nomeArquivo,
                    'url' => asset("storage/termos_assinados/{$nomeArquivo}"),
                    'tamanho' => strlen($pdfAssinado),
                    'data_assinatura' => $documento->updated_at->format('d/m/Y H:i')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao baixar PDF assinado', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erro ao baixar PDF assinado'
            ], 500);
        }
    }
}