<?php

namespace App\Http\Controllers;

use App\Models\HistoricoMensalResumo;
use App\Models\HistoricoMensalAssociado;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use Carbon\Carbon;

class HistoricoMensalController extends Controller
{
    /**
     * Listar todos os meses disponíveis com resumo
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            if (!$currentUser || !in_array($currentUser->role, ['admin', 'analista'])) {
                return response()->json(['success' => false, 'message' => 'Sem permissão'], 403);
            }

            $resumos = HistoricoMensalResumo::orderBy('ano_mes', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $resumos
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao listar histórico mensal', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Detalhes de um mês específico (lista de associados)
     */
    public function show(Request $request, string $anoMes): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            if (!$currentUser || !in_array($currentUser->role, ['admin', 'analista'])) {
                return response()->json(['success' => false, 'message' => 'Sem permissão'], 403);
            }

            if (!preg_match('/^\d{4}-\d{2}$/', $anoMes)) {
                return response()->json(['success' => false, 'message' => 'Formato inválido. Use YYYY-MM'], 400);
            }

            $resumo = HistoricoMensalResumo::where('ano_mes', $anoMes)->first();
            if (!$resumo) {
                return response()->json(['success' => false, 'message' => 'Mês não encontrado'], 404);
            }

            $itens = HistoricoMensalAssociado::where('resumo_id', $resumo->id)
                ->orderBy('nome_cliente')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'resumo' => $resumo,
                    'itens' => $itens
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar detalhes do mês', ['ano_mes' => $anoMes, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Gerar snapshot do mês anterior (chamado manualmente ou por cron)
     */
    public function gerarMesAnterior(Request $request): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            if (!$currentUser || !in_array($currentUser->role, ['admin', 'analista'])) {
                return response()->json(['success' => false, 'message' => 'Sem permissão'], 403);
            }

            $mesAnterior = Carbon::now()->subMonth();
            $anoMes = $mesAnterior->format('Y-m');

            $resultado = $this->gerarSnapshotMes($anoMes);

            return response()->json([
                'success' => true,
                'message' => "Snapshot do mês {$anoMes} gerado com sucesso",
                'data' => $resultado
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao gerar snapshot do mês anterior', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Gerar snapshots retroativos para todos os meses com dados
     */
    public function gerarRetroativo(Request $request): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            if (!$currentUser || !in_array($currentUser->role, ['admin', 'analista'])) {
                return response()->json(['success' => false, 'message' => 'Sem permissão'], 403);
            }

            // Encontrar o mês mais antigo com dados
            $primeiraEntrada = DB::selectOne("
                SELECT MIN(COALESCE(data_assinatura, data_entrada_controle)) as primeira_data
                FROM controle_clube
            ");

            if (!$primeiraEntrada || !$primeiraEntrada->primeira_data) {
                return response()->json(['success' => false, 'message' => 'Nenhum dado encontrado no controle'], 404);
            }

            $mesInicio = Carbon::parse($primeiraEntrada->primeira_data)->startOfMonth();
            $mesFim = Carbon::now()->startOfMonth(); // mês atual (gera até o anterior)
            $resultados = [];

            $mesAtual = $mesInicio->copy();
            while ($mesAtual->lt($mesFim)) {
                $anoMes = $mesAtual->format('Y-m');
                $resultado = $this->gerarSnapshotMes($anoMes);
                $resultados[] = $resultado;
                $mesAtual->addMonth();
            }

            // Gerar também o mês atual (parcial)
            $anoMesAtual = Carbon::now()->format('Y-m');
            $resultado = $this->gerarSnapshotMes($anoMesAtual);
            $resultados[] = $resultado;

            return response()->json([
                'success' => true,
                'message' => count($resultados) . ' meses gerados com sucesso',
                'data' => $resultados
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao gerar histórico retroativo', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Lógica central: gerar snapshot de um mês específico
     */
    private function gerarSnapshotMes(string $anoMes): array
    {
        $primeiroDia = Carbon::parse($anoMes . '-01')->startOfDay();
        $ultimoDia = $primeiroDia->copy()->endOfMonth()->endOfDay();

        Log::info("Gerando snapshot para {$anoMes}", [
            'primeiro_dia' => $primeiroDia,
            'ultimo_dia' => $ultimoDia
        ]);

        // Buscar todos os registros que estiveram ativos em algum momento do mês
        // Critério: entrou antes do fim do mês E (não foi deletado OU foi deletado depois do início do mês)
        $registros = DB::select("
            SELECT
                cc.id as controle_id,
                cc.proposta_id,
                cc.uc_id,
                cc.ug_id,
                cc.status_troca,
                cc.nome_cliente,
                cc.apelido_uc,
                uc.consumo_medio,
                cc.valor_calibrado as consumo_calibrado,
                cc.calibragem,
                cc.desconto_tarifa,
                cc.desconto_bandeira,
                cc.data_entrada_controle,
                cc.data_assinatura,
                cc.data_em_andamento,
                cc.data_titularidade,
                cc.data_alocacao_ug,
                cc.created_at,
                cc.deleted_at,
                p.numero_proposta,
                u_consultor.nome as consultor_nome,
                uc.numero_unidade as numero_uc,
                ug.apelido as ug_nome
            FROM controle_clube cc
            LEFT JOIN propostas p ON cc.proposta_id = p.id
            LEFT JOIN usuarios u_consultor ON p.consultor_id = u_consultor.id
            LEFT JOIN unidades_consumidoras uc ON cc.uc_id = uc.id
            LEFT JOIN unidades_consumidoras ug ON cc.ug_id = ug.id
            WHERE COALESCE(cc.data_assinatura, cc.data_entrada_controle) <= ?
              AND (cc.deleted_at IS NULL OR cc.deleted_at >= ?)
            ORDER BY cc.nome_cliente ASC, uc.numero_unidade ASC
        ", [$ultimoDia, $primeiroDia]);

        // Calcular novos e saídas do mês
        $novosNoMes = 0;
        $saidasNoMes = 0;
        $totalEsteira = 0;
        $totalEmAndamento = 0;
        $totalAssociado = 0;
        $totalSaindo = 0;
        $totalComUg = 0;
        $totalSemUg = 0;

        foreach ($registros as $r) {
            $dataEntrada = Carbon::parse($r->data_assinatura ?? $r->data_entrada_controle);
            if ($dataEntrada->gte($primeiroDia) && $dataEntrada->lte($ultimoDia)) {
                $novosNoMes++;
            }

            if ($r->deleted_at) {
                $deletadoEm = Carbon::parse($r->deleted_at);
                if ($deletadoEm->gte($primeiroDia) && $deletadoEm->lte($ultimoDia)) {
                    $saidasNoMes++;
                }
            }

            switch ($r->status_troca) {
                case 'Esteira': $totalEsteira++; break;
                case 'Em andamento': $totalEmAndamento++; break;
                case 'Associado': $totalAssociado++; break;
                case 'Saindo': $totalSaindo++; break;
            }

            if ($r->ug_id) {
                $totalComUg++;
            } else {
                $totalSemUg++;
            }
        }

        // Criar ou atualizar resumo
        DB::beginTransaction();
        try {
            // Deletar dados existentes do mês (permite re-gerar)
            $resumoExistente = DB::selectOne("SELECT id FROM historico_mensal_resumo WHERE ano_mes = ?", [$anoMes]);
            if ($resumoExistente) {
                DB::delete("DELETE FROM historico_mensal_associados WHERE resumo_id = ?", [$resumoExistente->id]);
                DB::delete("DELETE FROM historico_mensal_resumo WHERE id = ?", [$resumoExistente->id]);
            }

            $resumoId = (string) Str::ulid();
            DB::insert("
                INSERT INTO historico_mensal_resumo
                (id, ano_mes, total_associados, novos_no_mes, saidas_no_mes, total_esteira, total_em_andamento, total_associado, total_saindo, total_com_ug, total_sem_ug, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                $resumoId, $anoMes, count($registros), $novosNoMes, $saidasNoMes,
                $totalEsteira, $totalEmAndamento, $totalAssociado, $totalSaindo,
                $totalComUg, $totalSemUg
            ]);

            // Inserir snapshots individuais
            foreach ($registros as $r) {
                DB::insert("
                    INSERT INTO historico_mensal_associados
                    (id, ano_mes, resumo_id, controle_id, proposta_id, uc_id, ug_id, nome_cliente, numero_uc, numero_proposta, apelido_uc, status_troca, ug_nome, consumo_medio, consumo_calibrado, calibragem, desconto_tarifa, desconto_bandeira, consultor, data_entrada_controle, data_assinatura, data_em_andamento, data_titularidade, data_alocacao_ug, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ", [
                    (string) Str::ulid(),
                    $anoMes,
                    $resumoId,
                    $r->controle_id,
                    $r->proposta_id,
                    $r->uc_id,
                    $r->ug_id,
                    $r->nome_cliente,
                    $r->numero_uc,
                    $r->numero_proposta,
                    $r->apelido_uc,
                    $r->status_troca,
                    $r->ug_nome,
                    $r->consumo_medio,
                    $r->consumo_calibrado,
                    $r->calibragem,
                    $r->desconto_tarifa,
                    $r->desconto_bandeira,
                    $r->consultor_nome,
                    $r->data_entrada_controle,
                    $r->data_assinatura,
                    $r->data_em_andamento,
                    $r->data_titularidade,
                    $r->data_alocacao_ug,
                ]);
            }

            DB::commit();

            Log::info("Snapshot {$anoMes} gerado", ['total' => count($registros), 'novos' => $novosNoMes, 'saidas' => $saidasNoMes]);

            return [
                'ano_mes' => $anoMes,
                'total' => count($registros),
                'novos' => $novosNoMes,
                'saidas' => $saidasNoMes,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Exportar mês para Excel (CSV)
     */
    public function exportar(Request $request, string $anoMes): JsonResponse
    {
        try {
            $currentUser = JWTAuth::user();
            if (!$currentUser || !in_array($currentUser->role, ['admin', 'analista'])) {
                return response()->json(['success' => false, 'message' => 'Sem permissão'], 403);
            }

            $resumo = HistoricoMensalResumo::where('ano_mes', $anoMes)->first();
            if (!$resumo) {
                return response()->json(['success' => false, 'message' => 'Mês não encontrado'], 404);
            }

            $itens = HistoricoMensalAssociado::where('resumo_id', $resumo->id)
                ->orderBy('nome_cliente')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'resumo' => $resumo,
                    'itens' => $itens
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
