<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

class HistoricoRateioController extends Controller
{
    /**
     * Listar histórico de rateios
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 50);
            $ugId = $request->get('ug_id');

            $query = DB::table('historico_rateios as hr')
                ->leftJoin('unidades_consumidoras as ug', 'hr.ug_id', '=', 'ug.id')
                ->leftJoin('usuarios as u', 'hr.usuario_id', '=', 'u.id')
                ->whereNull('hr.deleted_at')
                ->select([
                    'hr.id',
                    'hr.ug_id',
                    'ug.nome_usina',
                    'ug.numero_unidade',
                    'hr.data_envio',
                    'hr.data_efetivacao',
                    'hr.arquivo_nome',
                    'hr.arquivo_path',
                    'hr.observacoes',
                    'hr.usuario_id',
                    'u.nome as usuario_nome',
                    'hr.created_at',
                    'hr.updated_at'
                ])
                ->orderBy('hr.created_at', 'desc');

            if ($ugId) {
                $query->where('hr.ug_id', $ugId);
            }

            $rateios = $query->paginate($perPage);

            // Adicionar contagem de itens para cada rateio
            $rateiosComItens = collect($rateios->items())->map(function ($rateio) {
                $rateio->total_itens = DB::table('historico_rateio_itens')
                    ->where('historico_rateio_id', $rateio->id)
                    ->count();
                return $rateio;
            });

            return response()->json([
                'success' => true,
                'data' => $rateiosComItens,
                'pagination' => [
                    'current_page' => $rateios->currentPage(),
                    'last_page' => $rateios->lastPage(),
                    'per_page' => $rateios->perPage(),
                    'total' => $rateios->total()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar histórico de rateios', [
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar histórico de rateios'
            ], 500);
        }
    }

    /**
     * Criar novo registro de histórico de rateio
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();

            if (!$currentUser) {
                return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
            }

            Log::info('Dados recebidos para criar rateio', [
                'all' => $request->all(),
                'ug_id' => $request->ug_id,
                'has_file' => $request->hasFile('arquivo')
            ]);

            $validator = Validator::make($request->all(), [
                'ug_id' => 'required|string',
                'data_envio' => 'nullable|date',
                'data_efetivacao' => 'nullable|date',
                'observacoes' => 'nullable|string|max:500',
                'arquivo' => 'nullable|file|max:10240' // 10MB max
            ]);

            // Validação manual da extensão do arquivo (mais confiável que mimes)
            if ($request->hasFile('arquivo')) {
                $ext = strtolower($request->file('arquivo')->getClientOriginalExtension());
                if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Formato de arquivo inválido',
                        'errors' => ['arquivo' => ['O arquivo deve ser do tipo: xlsx, xls ou csv']]
                    ], 422);
                }
            }

            if ($validator->fails()) {
                Log::error('Validação falhou', ['errors' => $validator->errors()->toArray()]);
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar se a UG existe
            $ugExists = DB::table('unidades_consumidoras')->where('id', $request->ug_id)->exists();
            if (!$ugExists) {
                Log::error('UG não encontrada', ['ug_id' => $request->ug_id]);
                return response()->json([
                    'success' => false,
                    'message' => 'UG não encontrada',
                    'errors' => ['ug_id' => ['A UG selecionada não existe']]
                ], 422);
            }

            DB::beginTransaction();

            $id = (string) Str::ulid();
            $arquivoNome = null;
            $arquivoPath = null;
            $itensProcessados = [];

            // Upload do arquivo se enviado
            if ($request->hasFile('arquivo')) {
                $arquivo = $request->file('arquivo');
                $arquivoNome = $arquivo->getClientOriginalName();
                $arquivoPath = $arquivo->storeAs(
                    'rateios/' . date('Y/m'),
                    $id . '_' . $arquivoNome,
                    'public'
                );

                // Processar o arquivo e extrair itens de rateio
                $itensProcessados = $this->processarArquivoRateio($arquivo);

                Log::info('Arquivo de rateio processado', [
                    'arquivo' => $arquivoNome,
                    'itens_encontrados' => count($itensProcessados)
                ]);
            }

            DB::table('historico_rateios')->insert([
                'id' => $id,
                'ug_id' => $request->ug_id,
                'data_envio' => $request->data_envio,
                'data_efetivacao' => $request->data_efetivacao,
                'arquivo_nome' => $arquivoNome,
                'arquivo_path' => $arquivoPath,
                'observacoes' => $request->observacoes,
                'usuario_id' => $currentUser->id,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Inserir itens do rateio
            if (!empty($itensProcessados)) {
                foreach ($itensProcessados as $item) {
                    DB::table('historico_rateio_itens')->insert([
                        'id' => (string) Str::ulid(),
                        'historico_rateio_id' => $id,
                        'numero_uc' => $item['numero_uc'],
                        'porcentagem' => $item['porcentagem'],
                        'consumo_kwh' => $item['consumo_kwh'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            DB::commit();

            Log::info('Histórico de rateio criado', [
                'id' => $id,
                'ug_id' => $request->ug_id,
                'usuario' => $currentUser->nome,
                'total_itens' => count($itensProcessados)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Histórico de rateio criado com sucesso',
                'data' => [
                    'id' => $id,
                    'itens_processados' => count($itensProcessados)
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao criar histórico de rateio', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar histórico de rateio: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Processar arquivo Excel/CSV e extrair dados de rateio
     */
    private function processarArquivoRateio($arquivo): array
    {
        $itens = [];
        $ext = strtolower($arquivo->getClientOriginalExtension());
        $tempPath = $arquivo->getRealPath();

        try {
            if ($ext === 'csv') {
                $reader = new Csv();
                $reader->setDelimiter(';'); // Padrão brasileiro
                $reader->setEnclosure('"');
                $spreadsheet = $reader->load($tempPath);
            } else {
                $spreadsheet = IOFactory::load($tempPath);
            }

            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            Log::info('Arquivo carregado para processamento', [
                'total_linhas' => count($rows),
                'primeiras_linhas' => array_slice($rows, 0, 10)
            ]);

            // Formato esperado (baseado no export da página de UGs):
            // Linha 1: Código da UC Geradora: | (vazio) | [número da UG]
            // Linha 2: Titular da UC: | (vazio) | [titular]
            // Linha 3: CNPJ/CPF: | (vazio) | [documento]
            // Linha 4: Lista de unidades consumidoras...
            // Linha 5: UC Beneficiaria | (vazio) | Porcentagem de rateio
            // Linha 6+: [número UC] | (vazio) | [porcentagem]%

            $inicioItens = false;
            foreach ($rows as $index => $row) {
                // Verificar se chegou na linha de cabeçalho "UC Beneficiaria"
                $primeiraColuna = trim($row[0] ?? '');

                if (stripos($primeiraColuna, 'UC Beneficiaria') !== false ||
                    stripos($primeiraColuna, 'UC Beneficiária') !== false) {
                    $inicioItens = true;
                    continue;
                }

                // Processar linhas de dados após o cabeçalho
                if ($inicioItens && !empty($primeiraColuna)) {
                    // Pular linhas de cabeçalho ou totais
                    if (stripos($primeiraColuna, 'Total') !== false ||
                        stripos($primeiraColuna, 'Código') !== false ||
                        stripos($primeiraColuna, 'Lista') !== false) {
                        continue;
                    }

                    // Extrair número da UC (primeira coluna)
                    $numeroUC = preg_replace('/[^0-9]/', '', $primeiraColuna);

                    if (empty($numeroUC)) {
                        continue;
                    }

                    // Extrair porcentagem (terceira coluna ou última coluna não vazia)
                    $porcentagemStr = '';
                    for ($i = count($row) - 1; $i >= 0; $i--) {
                        $valor = trim($row[$i] ?? '');
                        if (!empty($valor) && $valor !== $primeiraColuna) {
                            $porcentagemStr = $valor;
                            break;
                        }
                    }

                    // Limpar e converter porcentagem
                    $porcentagemStr = str_replace(['%', ' '], '', $porcentagemStr);
                    $porcentagemStr = str_replace(',', '.', $porcentagemStr);
                    $porcentagem = floatval($porcentagemStr);

                    if ($porcentagem > 0 && $porcentagem <= 100) {
                        $itens[] = [
                            'numero_uc' => $numeroUC,
                            'porcentagem' => $porcentagem,
                            'consumo_kwh' => null
                        ];

                        Log::info('Item de rateio extraído', [
                            'linha' => $index + 1,
                            'numero_uc' => $numeroUC,
                            'porcentagem' => $porcentagem
                        ]);
                    }
                }
            }

            // Se não encontrou itens no formato padrão, tentar formato alternativo
            if (empty($itens)) {
                $itens = $this->processarFormatoAlternativo($rows);
            }

        } catch (\Exception $e) {
            Log::error('Erro ao processar arquivo de rateio', [
                'error' => $e->getMessage(),
                'linha' => $e->getLine()
            ]);
        }

        return $itens;
    }

    /**
     * Processar formato alternativo (UC na coluna A, Porcentagem na coluna B ou C)
     */
    private function processarFormatoAlternativo(array $rows): array
    {
        $itens = [];

        foreach ($rows as $index => $row) {
            // Pular linhas vazias ou de cabeçalho
            if ($index < 1) continue;

            $col0 = trim($row[0] ?? '');
            $col1 = trim($row[1] ?? '');
            $col2 = trim($row[2] ?? '');

            // Tentar extrair número da UC da primeira coluna
            $numeroUC = preg_replace('/[^0-9]/', '', $col0);

            if (strlen($numeroUC) >= 5) { // UC válida tem pelo menos 5 dígitos
                // Procurar porcentagem nas outras colunas
                $porcentagem = 0;

                foreach ([$col2, $col1] as $valor) {
                    if (!empty($valor)) {
                        $valorLimpo = str_replace(['%', ' '], '', $valor);
                        $valorLimpo = str_replace(',', '.', $valorLimpo);
                        $numerico = floatval($valorLimpo);

                        if ($numerico > 0 && $numerico <= 100) {
                            $porcentagem = $numerico;
                            break;
                        }
                    }
                }

                if ($porcentagem > 0) {
                    $itens[] = [
                        'numero_uc' => $numeroUC,
                        'porcentagem' => $porcentagem,
                        'consumo_kwh' => null
                    ];
                }
            }
        }

        return $itens;
    }

    /**
     * Atualizar registro de histórico de rateio
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();

            if (!$currentUser) {
                return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
            }

            $rateio = DB::table('historico_rateios')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (!$rateio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Registro não encontrado'
                ], 404);
            }

            // Validação manual da extensão do arquivo
            if ($request->hasFile('arquivo')) {
                $ext = strtolower($request->file('arquivo')->getClientOriginalExtension());
                if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Formato de arquivo inválido',
                        'errors' => ['arquivo' => ['O arquivo deve ser do tipo: xlsx, xls ou csv']]
                    ], 422);
                }
            }

            $validator = Validator::make($request->all(), [
                'data_envio' => 'nullable|date',
                'data_efetivacao' => 'nullable|date',
                'observacoes' => 'nullable|string|max:500',
                'arquivo' => 'nullable|file|max:10240'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $dadosAtualizacao = [
                'updated_at' => now()
            ];

            if ($request->has('data_envio')) {
                $dadosAtualizacao['data_envio'] = $request->data_envio;
            }

            if ($request->has('data_efetivacao')) {
                $dadosAtualizacao['data_efetivacao'] = $request->data_efetivacao;
            }

            if ($request->has('observacoes')) {
                $dadosAtualizacao['observacoes'] = $request->observacoes;
            }

            // Upload de novo arquivo
            if ($request->hasFile('arquivo')) {
                // Remover arquivo antigo se existir
                if ($rateio->arquivo_path) {
                    Storage::disk('public')->delete($rateio->arquivo_path);
                }

                $arquivo = $request->file('arquivo');
                $arquivoNome = $arquivo->getClientOriginalName();
                $arquivoPath = $arquivo->storeAs(
                    'rateios/' . date('Y/m'),
                    $id . '_' . $arquivoNome,
                    'public'
                );

                $dadosAtualizacao['arquivo_nome'] = $arquivoNome;
                $dadosAtualizacao['arquivo_path'] = $arquivoPath;

                // Reprocessar arquivo e atualizar itens
                $itensProcessados = $this->processarArquivoRateio($arquivo);

                // Remover itens antigos
                DB::table('historico_rateio_itens')
                    ->where('historico_rateio_id', $id)
                    ->delete();

                // Inserir novos itens
                foreach ($itensProcessados as $item) {
                    DB::table('historico_rateio_itens')->insert([
                        'id' => (string) Str::ulid(),
                        'historico_rateio_id' => $id,
                        'numero_uc' => $item['numero_uc'],
                        'porcentagem' => $item['porcentagem'],
                        'consumo_kwh' => $item['consumo_kwh'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }

                Log::info('Itens de rateio atualizados', [
                    'historico_rateio_id' => $id,
                    'total_itens' => count($itensProcessados)
                ]);
            }

            DB::table('historico_rateios')
                ->where('id', $id)
                ->update($dadosAtualizacao);

            DB::commit();

            Log::info('Histórico de rateio atualizado', [
                'id' => $id,
                'usuario' => $currentUser->nome
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Histórico de rateio atualizado com sucesso'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao atualizar histórico de rateio', [
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar histórico de rateio'
            ], 500);
        }
    }

    /**
     * Excluir registro de histórico de rateio (soft delete)
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();

            if (!$currentUser) {
                return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
            }

            $rateio = DB::table('historico_rateios')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (!$rateio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Registro não encontrado'
                ], 404);
            }

            // Soft delete (os itens ficam para histórico, ou podem ser deletados em cascade)
            DB::table('historico_rateios')
                ->where('id', $id)
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now()
                ]);

            Log::info('Histórico de rateio excluído', [
                'id' => $id,
                'usuario' => $currentUser->nome
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Histórico de rateio excluído com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao excluir histórico de rateio', [
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao excluir histórico de rateio'
            ], 500);
        }
    }

    /**
     * Download do arquivo de rateio
     */
    public function downloadArquivo(string $id): JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            $rateio = DB::table('historico_rateios')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (!$rateio || !$rateio->arquivo_path) {
                return response()->json([
                    'success' => false,
                    'message' => 'Arquivo não encontrado'
                ], 404);
            }

            $path = Storage::disk('public')->path($rateio->arquivo_path);

            if (!file_exists($path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Arquivo não encontrado no servidor'
                ], 404);
            }

            return response()->download($path, $rateio->arquivo_nome);

        } catch (\Exception $e) {
            Log::error('Erro ao baixar arquivo de rateio', [
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao baixar arquivo'
            ], 500);
        }
    }

    /**
     * Listar UGs para seleção no formulário
     */
    public function listarUGs(): JsonResponse
    {
        try {
            $ugs = DB::table('unidades_consumidoras')
                ->where('gerador', true)
                ->whereNull('deleted_at')
                ->select(['id', 'nome_usina', 'numero_unidade'])
                ->orderBy('nome_usina')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $ugs
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar UGs', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar UGs'
            ], 500);
        }
    }

    /**
     * Listar itens de um rateio específico
     */
    public function listarItens(string $id): JsonResponse
    {
        try {
            $rateio = DB::table('historico_rateios')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (!$rateio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rateio não encontrado'
                ], 404);
            }

            $itens = DB::table('historico_rateio_itens')
                ->where('historico_rateio_id', $id)
                ->select(['id', 'numero_uc', 'porcentagem', 'consumo_kwh', 'created_at'])
                ->orderBy('porcentagem', 'desc')
                ->get();

            // Buscar informações adicionais das UCs (se existirem no controle)
            $itensComInfo = $itens->map(function ($item) {
                $ucInfo = DB::table('controle_clube as c')
                    ->join('unidades_consumidoras as uc', 'c.uc_id', '=', 'uc.id')
                    ->where('uc.numero_unidade', $item->numero_uc)
                    ->select(['c.nome_cliente', 'uc.id as uc_id'])
                    ->first();

                $item->nome_cliente = $ucInfo->nome_cliente ?? null;
                $item->uc_id = $ucInfo->uc_id ?? null;

                return $item;
            });

            return response()->json([
                'success' => true,
                'data' => $itensComInfo,
                'total' => $itens->count(),
                'soma_porcentagens' => $itens->sum('porcentagem')
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar itens do rateio', [
                'error' => $e->getMessage(),
                'rateio_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar itens do rateio'
            ], 500);
        }
    }
}
