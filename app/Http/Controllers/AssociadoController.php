<?php

namespace App\Http\Controllers;

use App\Models\Associado;
use App\Models\UnidadeConsumidora;
use App\Models\ControleClube;
use App\Models\Proposta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class AssociadoController extends Controller
{
    /**
     * Listar todos os associados
     */
    public function index(Request $request)
    {
        try {
            $query = Associado::with(['unidadesConsumidoras'])
                ->whereNull('deleted_at');

            // Filtro por nome
            if ($request->has('nome') && !empty($request->nome)) {
                $query->where('nome', 'ILIKE', '%' . $request->nome . '%');
            }

            // Filtro por CPF/CNPJ
            if ($request->has('cpf_cnpj') && !empty($request->cpf_cnpj)) {
                $query->porCpfCnpj($request->cpf_cnpj);
            }

            // Ordenação
            $orderBy = $request->get('order_by', 'nome');
            $orderDir = $request->get('order_dir', 'asc');
            $query->orderBy($orderBy, $orderDir);

            // Paginação
            $perPage = $request->get('per_page', 50);
            $associados = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $associados->items(),
                'meta' => [
                    'current_page' => $associados->currentPage(),
                    'last_page' => $associados->lastPage(),
                    'per_page' => $associados->perPage(),
                    'total' => $associados->total()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar associados', [
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar associados',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Buscar associado por CPF/CNPJ, nome, email ou número UC
     * Usado no modal de validação para vincular a associado existente
     */
    public function buscarPorCpfCnpj(Request $request)
    {
        try {
            // Aceita tanto cpf_cnpj quanto busca genérica
            $busca = $request->get('busca') ?? $request->get('cpf_cnpj');

            if (empty($busca) || strlen($busca) < 3) {
                return response()->json([
                    'success' => false,
                    'message' => 'Digite pelo menos 3 caracteres para buscar'
                ], 400);
            }

            // Limpar formatação para busca numérica
            $buscaLimpa = preg_replace('/[^0-9]/', '', $busca);

            // Buscar associados por nome, CPF/CNPJ ou email
            // Só conta os controles (não carrega a relação completa para evitar erro no toArray)
            $query = Associado::withCount('controles')
                ->where(function($q) use ($busca, $buscaLimpa) {
                    // Busca por nome (parcial)
                    $q->where('nome', 'ILIKE', "%{$busca}%")
                      // Busca por email (parcial)
                      ->orWhere('email', 'ILIKE', "%{$busca}%")
                      // Busca por CPF/CNPJ (exato ou parcial)
                      ->orWhere('cpf_cnpj', 'ILIKE', "%{$busca}%");

                    // Se tem números, buscar também sem formatação
                    if (!empty($buscaLimpa) && strlen($buscaLimpa) >= 3) {
                        $q->orWhereRaw("REPLACE(REPLACE(REPLACE(cpf_cnpj, '.', ''), '-', ''), '/', '') ILIKE ?", ["%{$buscaLimpa}%"]);
                    }
                });

            // Buscar também por número de UC vinculada
            if (!empty($buscaLimpa) && strlen($buscaLimpa) >= 3) {
                $query->orWhereHas('unidadesConsumidoras', function($ucQuery) use ($busca, $buscaLimpa) {
                    $ucQuery->where('numero_unidade', 'ILIKE', "%{$busca}%")
                            ->orWhere('numero_unidade', 'ILIKE', "%{$buscaLimpa}%");
                });
            }

            $associados = $query->limit(10)->get();

            if ($associados->count() > 0) {
                // Se encontrou apenas um, retorna no formato antigo para compatibilidade
                if ($associados->count() === 1) {
                    return response()->json([
                        'success' => true,
                        'encontrado' => true,
                        'data' => $associados->first(),
                        'total' => 1
                    ]);
                }

                // Se encontrou múltiplos, retorna lista
                return response()->json([
                    'success' => true,
                    'encontrado' => true,
                    'data' => $associados,
                    'total' => $associados->count(),
                    'multiplos' => true
                ]);
            }

            return response()->json([
                'success' => true,
                'encontrado' => false,
                'message' => 'Nenhum associado encontrado'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar associado', [
                'busca' => $request->get('busca') ?? $request->get('cpf_cnpj'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar associado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Exibir um associado específico
     */
    public function show($id)
    {
        try {
            $associado = Associado::with([
                'unidadesConsumidoras',
                'controles.unidadeConsumidora'
            ])->find($id);

            if (!$associado) {
                return response()->json([
                    'success' => false,
                    'message' => 'Associado não encontrado'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $associado
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar associado', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar associado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Criar novo associado
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'nome' => 'required|string|max:200',
                'cpf_cnpj' => 'required|string|max:18',
                'whatsapp' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar se CPF/CNPJ já existe
            if (Associado::cpfCnpjExiste($request->cpf_cnpj)) {
                return response()->json([
                    'success' => false,
                    'message' => 'CPF/CNPJ já cadastrado'
                ], 409);
            }

            $associado = Associado::create([
                'nome' => $request->nome,
                'cpf_cnpj' => $request->cpf_cnpj,
                'whatsapp' => $request->whatsapp,
                'email' => $request->email
            ]);

            Log::info('Associado criado', [
                'associado_id' => $associado->id,
                'nome' => $associado->nome,
                'cpf_cnpj' => $associado->cpf_cnpj
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Associado criado com sucesso',
                'data' => $associado
            ], 201);

        } catch (\Exception $e) {
            Log::error('Erro ao criar associado', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar associado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Atualizar associado
     */
    public function update(Request $request, $id)
    {
        try {
            $associado = Associado::find($id);

            if (!$associado) {
                return response()->json([
                    'success' => false,
                    'message' => 'Associado não encontrado'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'nome' => 'sometimes|string|max:200',
                'cpf_cnpj' => 'sometimes|string|max:18',
                'whatsapp' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Se alterou CPF/CNPJ, verificar se já existe
            if ($request->has('cpf_cnpj') && $request->cpf_cnpj !== $associado->cpf_cnpj) {
                $existente = Associado::porCpfCnpj($request->cpf_cnpj)
                    ->where('id', '!=', $id)
                    ->first();

                if ($existente) {
                    return response()->json([
                        'success' => false,
                        'message' => 'CPF/CNPJ já cadastrado para outro associado'
                    ], 409);
                }
            }

            $associado->update($request->all());

            Log::info('Associado atualizado', [
                'associado_id' => $associado->id,
                'campos_atualizados' => array_keys($request->all())
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Associado atualizado com sucesso',
                'data' => $associado->fresh()
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao atualizar associado', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar associado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Excluir associado (soft delete)
     */
    public function destroy($id)
    {
        try {
            $associado = Associado::find($id);

            if (!$associado) {
                return response()->json([
                    'success' => false,
                    'message' => 'Associado não encontrado'
                ], 404);
            }

            // Verificar se tem UCs vinculadas
            if ($associado->unidadesConsumidoras()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Associado possui unidades consumidoras vinculadas'
                ], 409);
            }

            $associado->delete();

            Log::info('Associado excluído', [
                'associado_id' => $id,
                'nome' => $associado->nome
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Associado excluído com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao excluir associado', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao excluir associado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Listar UCs pendentes de validação
     * Retorna todas as propostas com UCs no status "Pendente Validação"
     */
    public function listarPendentesValidacao(Request $request)
    {
        try {
            // Buscar propostas que tenham UCs com status "Pendente Validação"
            $propostas = DB::select("
                SELECT
                    p.id,
                    p.numero_proposta,
                    p.nome_cliente,
                    p.desconto_tarifa,
                    p.desconto_bandeira,
                    p.consultor_id,
                    p.documentacao,
                    p.unidades_consumidoras,
                    p.created_at,
                    u.nome as consultor_nome
                FROM propostas p
                LEFT JOIN usuarios u ON u.id = p.consultor_id
                WHERE p.deleted_at IS NULL
                  AND p.unidades_consumidoras::text LIKE '%Pendente Valida%'
                ORDER BY p.created_at DESC
            ");

            $resultado = [];

            foreach ($propostas as $proposta) {
                $ucs = json_decode($proposta->unidades_consumidoras ?? '[]', true);
                $documentacao = json_decode($proposta->documentacao ?? '{}', true);

                foreach ($ucs as $uc) {
                    $status = $uc['status'] ?? 'Aguardando';

                    if ($status === 'Pendente Validação') {
                        $numeroUC = $uc['numero_unidade'] ?? $uc['numeroUC'] ?? null;
                        $docUC = $documentacao[$numeroUC] ?? [];

                        // Buscar dados em vários locais possíveis
                        $cpfCnpj = $docUC['cpfCnpj'] ?? $docUC['CPF_CNPJ'] ?? $docUC['cpf_cnpj'] ?? $docUC['cpf'] ?? $docUC['cnpj'] ?? null;
                        $whatsapp = $docUC['whatsapp'] ?? $docUC['Whatsapp'] ?? $docUC['whatsappRepresentante'] ?? $docUC['telefone'] ?? null;
                        $email = $docUC['email'] ?? $docUC['Email'] ?? $docUC['emailRepresentante'] ?? null;

                        $resultado[] = [
                            'proposta_id' => $proposta->id,
                            'numero_proposta' => $proposta->numero_proposta,
                            'nome_cliente' => $proposta->nome_cliente,
                            'consultor_id' => $proposta->consultor_id,
                            'consultor_nome' => $proposta->consultor_nome,
                            'desconto_tarifa' => $proposta->desconto_tarifa,
                            'desconto_bandeira' => $proposta->desconto_bandeira,
                            'created_at' => $proposta->created_at,
                            'uc' => [
                                'numero_unidade' => $numeroUC,
                                'apelido' => $uc['apelido'] ?? null,
                                'ligacao' => $uc['ligacao'] ?? null,
                                'consumo_medio' => $uc['consumo_medio'] ?? $uc['media'] ?? null,
                                'distribuidora' => $uc['distribuidora'] ?? null,
                                'cpf_cnpj' => $cpfCnpj,
                                'endereco' => $docUC['logradouroUC'] ?? $docUC['enderecoUC'] ?? $docUC['endereco'] ?? null,
                                'bairro' => $docUC['Bairro_UC'] ?? $docUC['bairro'] ?? null,
                                'cidade' => $docUC['Cidade_UC'] ?? $docUC['cidade'] ?? null,
                                'estado' => $docUC['Estado_UC'] ?? $docUC['estado'] ?? $docUC['uf'] ?? null,
                                'cep' => $docUC['CEP_UC'] ?? $docUC['cep'] ?? null,
                                'whatsapp' => $whatsapp,
                                'email' => $email
                            ]
                        ];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => $resultado,
                'total' => count($resultado)
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar pendentes de validação', [
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar pendentes de validação',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obter dados para validação de uma UC específica
     */
    public function obterDadosValidacao($proposta_id, $numero_uc)
    {
        try {
            $proposta = Proposta::find($proposta_id);

            if (!$proposta) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proposta não encontrada'
                ], 404);
            }

            // Verificar se já é array ou precisa decodificar
            $ucs = $proposta->unidades_consumidoras;
            if (is_string($ucs)) {
                $ucs = json_decode($ucs, true) ?? [];
            } elseif (!is_array($ucs)) {
                $ucs = [];
            }

            $documentacao = $proposta->documentacao;
            if (is_string($documentacao)) {
                $documentacao = json_decode($documentacao, true) ?? [];
            } elseif (!is_array($documentacao)) {
                $documentacao = [];
            }

            // Encontrar a UC específica
            $ucEncontrada = null;
            foreach ($ucs as $uc) {
                $numeroUCAtual = $uc['numero_unidade'] ?? $uc['numeroUC'] ?? null;
                if ($numeroUCAtual == $numero_uc) {
                    $ucEncontrada = $uc;
                    break;
                }
            }

            if (!$ucEncontrada) {
                return response()->json([
                    'success' => false,
                    'message' => 'UC não encontrada na proposta'
                ], 404);
            }

            $docUC = $documentacao[$numero_uc] ?? [];

            // Buscar CPF/CNPJ em vários locais possíveis
            $cpfCnpj = $docUC['cpfCnpj'] ?? $docUC['CPF_CNPJ'] ?? $docUC['cpf_cnpj'] ?? $docUC['cpf'] ?? $docUC['cnpj'] ?? null;

            // Buscar whatsapp em vários locais possíveis
            $whatsapp = $docUC['whatsapp'] ?? $docUC['Whatsapp'] ?? $docUC['whatsappRepresentante'] ?? $docUC['telefone'] ?? null;

            // Buscar email em vários locais possíveis
            $email = $docUC['email'] ?? $docUC['Email'] ?? $docUC['emailRepresentante'] ?? null;

            // Buscar se já existe associado com esse CPF/CNPJ
            $associadoExistente = null;
            if ($cpfCnpj) {
                $associadoExistente = Associado::with('unidadesConsumidoras')
                    ->porCpfCnpj($cpfCnpj)
                    ->first();
            }

            // Buscar consultor (usa consultor_id ou usuario_id como fallback)
            $consultor = null;
            $consultorId = $proposta->consultor_id ?? $proposta->usuario_id;
            if ($consultorId) {
                $consultor = DB::selectOne("SELECT id, nome FROM usuarios WHERE id = ?", [$consultorId]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'proposta' => [
                        'id' => $proposta->id,
                        'numero_proposta' => $proposta->numero_proposta,
                        'nome_cliente' => $proposta->nome_cliente,
                        'desconto_tarifa' => $proposta->desconto_tarifa,
                        'desconto_bandeira' => $proposta->desconto_bandeira,
                        'consultor_id' => $consultorId,
                        'consultor_nome' => $consultor->nome ?? null
                    ],
                    'uc' => [
                        'numero_unidade' => $numero_uc,
                        'apelido' => $ucEncontrada['apelido'] ?? 'UC ' . $numero_uc,
                        'ligacao' => $ucEncontrada['ligacao'] ?? 'Monofásica',
                        'consumo_medio' => $ucEncontrada['consumo_medio'] ?? $ucEncontrada['media'] ?? 0,
                        'distribuidora' => $ucEncontrada['distribuidora'] ?? 'EQUATORIAL GO'
                    ],
                    'cliente' => [
                        'nome' => $proposta->nome_cliente,
                        'cpf_cnpj' => $cpfCnpj,
                        'endereco' => $docUC['logradouroUC'] ?? $docUC['enderecoUC'] ?? $docUC['endereco'] ?? null,
                        'bairro' => $docUC['Bairro_UC'] ?? $docUC['bairro'] ?? null,
                        'cidade' => $docUC['Cidade_UC'] ?? $docUC['cidade'] ?? null,
                        'estado' => $docUC['Estado_UC'] ?? $docUC['estado'] ?? $docUC['uf'] ?? null,
                        'cep' => $docUC['CEP_UC'] ?? $docUC['cep'] ?? null,
                        'whatsapp' => $whatsapp,
                        'email' => $email
                    ],
                    'associado_existente' => $associadoExistente
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao obter dados para validação', [
                'proposta_id' => $proposta_id,
                'numero_uc' => $numero_uc,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao obter dados para validação',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Confirmar validação e criar Associado + UC + Controle
     */
    public function confirmarValidacao(Request $request, $proposta_id, $numero_uc)
    {
        try {
            $currentUser = JWTAuth::user();

            // Verificar se é admin ou analista
            if (!in_array($currentUser->role, ['admin', 'analista'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Apenas administradores e analistas podem validar associados'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'nome' => 'required|string|max:200',
                'cpf_cnpj' => 'required|string|max:18',
                'whatsapp' => 'nullable|string|max:20',
                'email' => 'required|email|max:255', // Email é obrigatório
                // Campos de endereço ficam no controle_clube
                'endereco' => 'nullable|string|max:255',
                'bairro' => 'nullable|string|max:100',
                'cidade' => 'nullable|string|max:100',
                'estado' => 'nullable|string|max:2',
                'cep' => 'nullable|string|max:10',
                // Campos da UC
                'apelido_uc' => 'nullable|string|max:100',
                'ligacao' => 'nullable|string|max:50',
                'consumo_medio' => 'nullable|numeric',
                'associado_id' => 'nullable|string|max:36', // Se vincular a existente
                // Consultor (se alterado, atualiza na proposta)
                'consultor_id' => 'nullable|string|max:36',
                // Campos de faturamento (salvos na UC)
                'nome_faturamento' => 'nullable|string|max:200',
                'cpf_cnpj_faturamento' => 'nullable|string|max:20',
                'whatsapp_faturamento' => 'nullable|string|max:20',
                'email_faturamento_1' => 'nullable|email|max:255',
                'email_faturamento_2' => 'nullable|email|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // 1. Buscar proposta
            $proposta = Proposta::find($proposta_id);
            if (!$proposta) {
                throw new \Exception('Proposta não encontrada');
            }

            // 1.1 Se consultor foi alterado, atualizar na proposta
            if ($request->has('consultor_id') && $request->consultor_id !== $proposta->consultor_id) {
                DB::update("UPDATE propostas SET consultor_id = ?, updated_at = NOW() WHERE id = ?", [
                    $request->consultor_id,
                    $proposta_id
                ]);

                Log::info('Consultor da proposta atualizado', [
                    'proposta_id' => $proposta_id,
                    'consultor_anterior' => $proposta->consultor_id,
                    'consultor_novo' => $request->consultor_id
                ]);
            }

            // 2. Criar ou buscar Associado
            $associadoId = $request->associado_id;

            if ($associadoId) {
                // Vincular a associado existente - NÃO atualiza dados do associado (ficam inalteráveis)
                $associado = Associado::find($associadoId);
                if (!$associado) {
                    throw new \Exception('Associado não encontrado');
                }

                Log::info('Vinculando UC a associado existente', [
                    'associado_id' => $associadoId,
                    'nome' => $associado->nome
                ]);

            } else {
                // SEM VINCULAÇÃO MANUAL - Verificar se já existe associado com CPF/CNPJ ou email
                $associadoPorCpf = Associado::buscarPorCpfCnpj($request->cpf_cnpj);
                $associadoPorEmail = Associado::where('email', 'ILIKE', $request->email)->first();

                // Coletar associados conflitantes
                $conflitos = [];
                if ($associadoPorCpf) {
                    $conflitos[] = [
                        'id' => $associadoPorCpf->id,
                        'nome' => $associadoPorCpf->nome,
                        'cpf_cnpj' => $associadoPorCpf->cpf_cnpj,
                        'email' => $associadoPorCpf->email,
                        'motivo' => 'CPF/CNPJ já cadastrado'
                    ];
                }
                if ($associadoPorEmail && (!$associadoPorCpf || $associadoPorEmail->id !== $associadoPorCpf->id)) {
                    $conflitos[] = [
                        'id' => $associadoPorEmail->id,
                        'nome' => $associadoPorEmail->nome,
                        'cpf_cnpj' => $associadoPorEmail->cpf_cnpj,
                        'email' => $associadoPorEmail->email,
                        'motivo' => 'Email já cadastrado'
                    ];
                }

                // Se houver conflitos, bloquear e pedir vinculação
                if (!empty($conflitos)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Já existe(m) associado(s) com este CPF/CNPJ ou email. Vincule a um dos associados existentes.',
                        'conflitos' => $conflitos,
                        'requer_vinculacao' => true
                    ], 409);
                }

                // Criar novo associado (sem campos de endereço - estes ficam no controle)
                $associado = Associado::create([
                    'nome' => $request->nome,
                    'cpf_cnpj' => $request->cpf_cnpj,
                    'whatsapp' => $request->whatsapp,
                    'email' => $request->email
                ]);

                $associadoId = $associado->id;

                Log::info('Novo associado criado', [
                    'associado_id' => $associadoId,
                    'nome' => $associado->nome,
                    'cpf_cnpj' => $associado->cpf_cnpj
                ]);
            }

            // 3. Criar ou buscar UC
            $ucExistente = DB::selectOne(
                "SELECT id FROM unidades_consumidoras WHERE numero_unidade = ? AND deleted_at IS NULL",
                [$numero_uc]
            );

            if (!$ucExistente) {
                $ucId = \Illuminate\Support\Str::ulid()->toString();

                DB::insert("
                    INSERT INTO unidades_consumidoras (
                        id, usuario_id, associado_id, concessionaria_id, numero_unidade,
                        apelido, consumo_medio, ligacao, distribuidora, proposta_id,
                        bairro, cidade, estado, cep, endereco_completo,
                        grupo, desconto_fatura, desconto_bandeira,
                        nome_faturamento, cpf_cnpj_faturamento, whatsapp_faturamento,
                        email_faturamento_1, email_faturamento_2,
                        created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ", [
                    $ucId,
                    $currentUser->id,
                    $associadoId,
                    '01JB849ZDG0RPC5EB8ZFTB4GJN', // EQUATORIAL
                    $numero_uc,
                    $request->apelido_uc ?? 'UC ' . $numero_uc,
                    $request->consumo_medio ?? 0,
                    $request->ligacao ?? 'Monofásica',
                    'EQUATORIAL GO',
                    $proposta_id,
                    $request->bairro,
                    $request->cidade,
                    $request->estado,
                    $request->cep,
                    $request->endereco,
                    'B',
                    $this->extrairValorDesconto($proposta->desconto_tarifa),
                    $this->extrairValorDesconto($proposta->desconto_bandeira),
                    $request->nome_faturamento,
                    $request->cpf_cnpj_faturamento,
                    $request->whatsapp_faturamento,
                    $request->email_faturamento_1,
                    $request->email_faturamento_2
                ]);

                Log::info('UC criada', ['uc_id' => $ucId, 'numero_uc' => $numero_uc]);

            } else {
                $ucId = $ucExistente->id;

                // Atualizar UC existente com associado_id e dados de faturamento
                DB::update("
                    UPDATE unidades_consumidoras
                    SET associado_id = ?,
                        bairro = COALESCE(?, bairro),
                        cidade = COALESCE(?, cidade),
                        estado = COALESCE(?, estado),
                        cep = COALESCE(?, cep),
                        endereco_completo = COALESCE(?, endereco_completo),
                        nome_faturamento = COALESCE(?, nome_faturamento),
                        cpf_cnpj_faturamento = COALESCE(?, cpf_cnpj_faturamento),
                        whatsapp_faturamento = COALESCE(?, whatsapp_faturamento),
                        email_faturamento_1 = COALESCE(?, email_faturamento_1),
                        email_faturamento_2 = COALESCE(?, email_faturamento_2),
                        updated_at = NOW()
                    WHERE id = ?
                ", [
                    $associadoId,
                    $request->bairro,
                    $request->cidade,
                    $request->estado,
                    $request->cep,
                    $request->endereco,
                    $request->nome_faturamento,
                    $request->cpf_cnpj_faturamento,
                    $request->whatsapp_faturamento,
                    $request->email_faturamento_1,
                    $request->email_faturamento_2,
                    $ucId
                ]);

                Log::info('UC existente atualizada', ['uc_id' => $ucId, 'numero_uc' => $numero_uc]);
            }

            // 4. Criar ou restaurar Controle
            // Buscar incluindo soft-deletados para evitar violação de constraint unique
            $controleExistente = DB::selectOne(
                "SELECT id, deleted_at FROM controle_clube WHERE proposta_id = ? AND uc_id = ?",
                [$proposta_id, $ucId]
            );

            if (!$controleExistente) {
                // Não existe, criar novo
                $controleId = \Illuminate\Support\Str::ulid()->toString();

                DB::insert("
                    INSERT INTO controle_clube (
                        id, proposta_id, uc_id, associado_id, calibragem,
                        desconto_tarifa, desconto_bandeira,
                        whatsapp, email, nome_cliente, apelido_uc, cpf_cnpj,
                        data_entrada_controle, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                ", [
                    $controleId,
                    $proposta_id,
                    $ucId,
                    $associadoId,
                    0.00,
                    $proposta->desconto_tarifa,
                    $proposta->desconto_bandeira,
                    $request->whatsapp,
                    $request->email,
                    $request->nome,
                    $request->apelido_uc ?? 'UC ' . $numero_uc,
                    $request->cpf_cnpj
                ]);

                Log::info('Controle criado', [
                    'controle_id' => $controleId,
                    'proposta_id' => $proposta_id,
                    'uc_id' => $ucId,
                    'associado_id' => $associadoId
                ]);

            } else if ($controleExistente->deleted_at !== null) {
                // Existe mas está soft-deletado - restaurar e atualizar
                DB::update("
                    UPDATE controle_clube
                    SET associado_id = ?,
                        whatsapp = ?,
                        email = ?,
                        nome_cliente = ?,
                        apelido_uc = ?,
                        cpf_cnpj = ?,
                        deleted_at = NULL,
                        updated_at = NOW()
                    WHERE id = ?
                ", [
                    $associadoId,
                    $request->whatsapp,
                    $request->email,
                    $request->nome,
                    $request->apelido_uc ?? 'UC ' . $numero_uc,
                    $request->cpf_cnpj,
                    $controleExistente->id
                ]);

                Log::info('Controle restaurado de soft-delete', ['controle_id' => $controleExistente->id]);

            } else {
                // Existe e está ativo - apenas atualizar
                DB::update("
                    UPDATE controle_clube
                    SET associado_id = ?,
                        whatsapp = ?,
                        email = ?,
                        nome_cliente = ?,
                        apelido_uc = ?,
                        cpf_cnpj = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ", [
                    $associadoId,
                    $request->whatsapp,
                    $request->email,
                    $request->nome,
                    $request->apelido_uc,
                    $request->cpf_cnpj,
                    $controleExistente->id
                ]);

                Log::info('Controle atualizado', ['controle_id' => $controleExistente->id]);
            }

            // 5. Atualizar status da UC na proposta para "Associado"
            $ucs = json_decode($proposta->unidades_consumidoras ?? '[]', true);
            foreach ($ucs as &$uc) {
                $ucNumero = $uc['numero_unidade'] ?? $uc['numeroUC'] ?? null;
                if ($ucNumero == $numero_uc) {
                    $uc['status'] = 'Associado';
                    break;
                }
            }

            DB::update("
                UPDATE propostas SET unidades_consumidoras = ?, updated_at = NOW() WHERE id = ?
            ", [json_encode($ucs), $proposta_id]);

            DB::commit();

            Log::info('Validação concluída com sucesso', [
                'proposta_id' => $proposta_id,
                'numero_uc' => $numero_uc,
                'associado_id' => $associadoId,
                'usuario_id' => $currentUser->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Validação concluída com sucesso',
                'data' => [
                    'associado_id' => $associadoId,
                    'uc_id' => $ucId,
                    'controle_id' => $controleExistente->id ?? $controleId ?? null
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Erro ao confirmar validação', [
                'proposta_id' => $proposta_id,
                'numero_uc' => $numero_uc,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao confirmar validação',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extrair valor numérico do desconto
     */
    private function extrairValorDesconto($desconto)
    {
        if (empty($desconto)) {
            return null;
        }

        $numero = preg_replace('/[^0-9.,]/', '', $desconto);
        $numero = str_replace(',', '.', $numero);

        return is_numeric($numero) ? floatval($numero) : null;
    }
}
