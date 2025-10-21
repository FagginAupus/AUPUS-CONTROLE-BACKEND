<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\AuditoriaService;
use Carbon\Carbon;

class RelatorioController extends Controller
{
    /**
     * Dashboard Executivo - Métricas gerais do sistema
     */
    public function dashboardExecutivo(Request $request)
    {
        try {
            $dataInicio = $request->get('data_inicio', Carbon::now()->subDays(30)->format('Y-m-d'));
            $dataFim = $request->get('data_fim', Carbon::now()->format('Y-m-d'));

            // Métricas gerais (filtrando por período)
            $totalPropostas = DB::table('propostas')
                ->whereBetween('data_proposta', [$dataInicio, $dataFim])
                ->count();

            $propostasFechadas = DB::table('propostas')
                ->where('status_proposta', 'fechada')
                ->whereBetween('data_proposta', [$dataInicio, $dataFim])
                ->count();

            $totalControle = DB::table('controle_clube')
                ->whereNull('deleted_at')
                ->count();

            $totalUGs = DB::table('unidades_geradoras')
                ->count();

            // Taxa de conversão
            $taxaConversao = $totalPropostas > 0 ? round(($propostasFechadas / $totalPropostas) * 100, 2) : 0;

            // Evolução mensal
            $evolucaoMensal = DB::table('propostas')
                ->selectRaw("
                    DATE_FORMAT(data_proposta, '%Y-%m') as periodo,
                    COUNT(*) as total,
                    SUM(CASE WHEN status_proposta = 'fechada' THEN 1 ELSE 0 END) as fechadas,
                    ROUND((SUM(CASE WHEN status_proposta = 'fechada' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as taxa_conversao
                ")
                ->whereBetween('data_proposta', [$dataInicio, $dataFim])
                ->groupBy('periodo')
                ->orderBy('periodo')
                ->get();

            // Top 5 consultores
            $topConsultores = DB::table('propostas')
                ->selectRaw("
                    consultor,
                    COUNT(*) as total_propostas,
                    SUM(CASE WHEN status_proposta = 'fechada' THEN 1 ELSE 0 END) as fechadas,
                    ROUND(AVG(CASE WHEN status_proposta = 'fechada' THEN valor_uc ELSE NULL END), 2) as ticket_medio
                ")
                ->whereBetween('data_proposta', [$dataInicio, $dataFim])
                ->groupBy('consultor')
                ->orderByDesc('fechadas')
                ->limit(5)
                ->get();

            // Distribuição por status
            $statusDistribuicao = DB::table('propostas')
                ->selectRaw("
                    status_proposta,
                    COUNT(*) as quantidade,
                    ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM propostas WHERE data_proposta BETWEEN ? AND ?)), 2) as percentual
                ", [$dataInicio, $dataFim])
                ->whereBetween('data_proposta', [$dataInicio, $dataFim])
                ->groupBy('status_proposta')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'metricas_gerais' => [
                        'total_propostas' => $totalPropostas,
                        'propostas_fechadas' => $propostasFechadas,
                        'total_controle' => $totalControle,
                        'total_ugs' => $totalUGs,
                        'taxa_conversao' => $taxaConversao
                    ],
                    'evolucao_mensal' => $evolucaoMensal,
                    'top_consultores' => $topConsultores,
                    'status_distribuicao' => $statusDistribuicao
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erro no dashboard executivo: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Ranking de Consultores
     */
    public function rankingConsultores(Request $request)
    {
        try {
            $dataInicio = $request->get('data_inicio', Carbon::now()->subDays(30)->format('Y-m-d'));
            $dataFim = $request->get('data_fim', Carbon::now()->format('Y-m-d'));
            $limit = $request->get('limit', 20);

            $ranking = DB::table('propostas')
                ->select(
                    'consultor',
                    DB::raw('COUNT(*) as total_propostas'),
                    DB::raw('SUM(CASE WHEN status_proposta = "fechada" THEN 1 ELSE 0 END) as fechadas'),
                    DB::raw('ROUND((SUM(CASE WHEN status_proposta = "fechada" THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as taxa_conversao'),
                    DB::raw('ROUND(AVG(CASE WHEN status_proposta = "fechada" THEN valor_uc ELSE NULL END), 2) as ticket_medio'),
                    DB::raw('SUM(CASE WHEN status_proposta = "fechada" THEN valor_uc ELSE 0 END) as valor_total'),
                    DB::raw('ROUND(AVG(consumo_medio), 0) as consumo_medio'),
                    DB::raw('COUNT(DISTINCT DATE(data_proposta)) as dias_ativos')
                )
                ->whereBetween('data_proposta', [$dataInicio, $dataFim])
                ->groupBy('consultor')
                ->orderByDesc('fechadas')
                ->orderByDesc('taxa_conversao')
                ->limit($limit)
                ->get()
                ->map(function ($item, $index) {
                    return [
                        'posicao' => $index + 1,
                        'consultor' => $item->consultor,
                        'total_propostas' => $item->total_propostas,
                        'fechadas' => $item->fechadas,
                        'taxa_conversao' => $item->taxa_conversao,
                        'ticket_medio' => $item->ticket_medio,
                        'valor_total' => $item->valor_total,
                        'consumo_medio' => $item->consumo_medio,
                        'dias_ativos' => $item->dias_ativos,
                        'propostas_por_dia' => $item->dias_ativos > 0 ? round($item->total_propostas / $item->dias_ativos, 2) : 0
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'ranking' => $ranking,
                    'periodo' => "$dataInicio a $dataFim",
                    'total_consultores' => $ranking->count()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro no ranking de consultores', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar ranking de consultores'
            ], 500);
        }
    }

    /**
     * Análise de Propostas
     */
    public function analisePropostas(Request $request)
    {
        try {
            $dataInicio = $request->get('data_inicio', Carbon::now()->subDays(30)->format('Y-m-d'));
            $dataFim = $request->get('data_fim', Carbon::now()->format('Y-m-d'));

            // Distribuição por status
            $statusDistribuicao = DB::table('propostas')
                ->select(
                    'status_proposta',
                    DB::raw('COUNT(*) as quantidade'),
                    DB::raw('ROUND(AVG(valor_uc), 2) as valor_medio'),
                    DB::raw('ROUND(AVG(consumo_medio), 2) as consumo_medio')
                )
                ->whereBetween('data_proposta', [$dataInicio, $dataFim])
                ->groupBy('status_proposta')
                ->get();

            // Funil de conversão
            $funil = [
                'total_prospects' => DB::table('propostas')->whereBetween('data_proposta', [$dataInicio, $dataFim])->count(),
                'em_negociacao' => DB::table('propostas')->where('status_proposta', 'em_negociacao')->whereBetween('data_proposta', [$dataInicio, $dataFim])->count(),
                'aprovacao_pendente' => DB::table('propostas')->where('status_proposta', 'aprovacao_pendente')->whereBetween('data_proposta', [$dataInicio, $dataFim])->count(),
                'fechadas' => DB::table('propostas')->where('status_proposta', 'fechada')->whereBetween('data_proposta', [$dataInicio, $dataFim])->count(),
                'perdidas' => DB::table('propostas')->where('status_proposta', 'perdida')->whereBetween('data_proposta', [$dataInicio, $dataFim])->count()
            ];

            // Tempo médio por status
            $tempoMedioStatus = DB::table('propostas')
                ->select(
                    'status_proposta',
                    DB::raw('AVG(DATEDIFF(NOW(), data_proposta)) as dias_medio')
                )
                ->whereBetween('data_proposta', [$dataInicio, $dataFim])
                ->groupBy('status_proposta')
                ->get();

            // Análise de valores
            $analiseValores = DB::table('propostas')
                ->selectRaw('
                    COUNT(*) as total,
                    ROUND(AVG(valor_uc), 2) as valor_medio,
                    ROUND(MIN(valor_uc), 2) as valor_minimo,
                    ROUND(MAX(valor_uc), 2) as valor_maximo,
                    ROUND(SUM(valor_uc), 2) as valor_total,
                    ROUND(AVG(consumo_medio), 2) as consumo_medio
                ')
                ->whereBetween('data_proposta', [$dataInicio, $dataFim])
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'status_distribuicao' => $statusDistribuicao,
                    'funil_conversao' => $funil,
                    'tempo_medio_status' => $tempoMedioStatus,
                    'analise_valores' => $analiseValores,
                    'periodo' => "$dataInicio a $dataFim"
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro na análise de propostas', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar análise de propostas'
            ], 500);
        }
    }

    /**
     * Controle Clube
     */
    public function controleClube(Request $request)
    {
        try {
            // Status de troca de titularidade
            $statusTroca = DB::table('controle_clube')
                ->select(
                    'status_troca',
                    DB::raw('COUNT(*) as quantidade')
                )
                ->groupBy('status_troca')
                ->get();

            // UCs vs UGs disponíveis
            $totalUCs = DB::table('controle_clube')->count();
            $totalUGs = DB::table('unidades_geradoras')->count();
            $ugsDisponiveis = DB::table('unidades_geradoras')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('controle_clube')
                        ->whereColumn('controle_clube.ug_id', 'unidades_geradoras.id');
                })
                ->count();

            // Performance de calibragem
            $performanceCalibragem = DB::table('controle_clube')
                ->select(
                    'consultor',
                    DB::raw('COUNT(*) as total_ucs'),
                    DB::raw('AVG(economia_percentual) as economia_media'),
                    DB::raw('SUM(CASE WHEN economia_percentual IS NOT NULL THEN 1 ELSE 0 END) as calibradas'),
                    DB::raw('ROUND((SUM(CASE WHEN economia_percentual IS NOT NULL THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as percentual_calibrado')
                )
                ->groupBy('consultor')
                ->orderByDesc('economia_media')
                ->get();

            // Alertas operacionais
            $alertas = [];

            // UCs sem UG definida
            $ucsSemUG = DB::table('controle_clube')
                ->whereNull('ug_id')
                ->count();

            if ($ucsSemUG > 0) {
                $alertas[] = [
                    'tipo' => 'warning',
                    'titulo' => 'UCs sem UG definida',
                    'descricao' => "$ucsSemUG UCs estão sem UG atribuída",
                    'quantidade' => $ucsSemUG
                ];
            }

            // UCs sem calibragem há mais de 30 dias
            $ucsSemCalibragem = DB::table('controle_clube')
                ->where('data_entrada_controle', '<', Carbon::now()->subDays(30))
                ->whereNull('economia_percentual')
                ->count();

            if ($ucsSemCalibragem > 0) {
                $alertas[] = [
                    'tipo' => 'error',
                    'titulo' => 'UCs sem calibragem',
                    'descricao' => "$ucsSemCalibragem UCs estão há mais de 30 dias sem calibragem",
                    'quantidade' => $ucsSemCalibragem
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'status_troca' => $statusTroca,
                    'capacidade' => [
                        'total_ucs' => $totalUCs,
                        'total_ugs' => $totalUGs,
                        'ugs_disponiveis' => $ugsDisponiveis,
                        'ocupacao_percentual' => $totalUGs > 0 ? round((($totalUGs - $ugsDisponiveis) / $totalUGs) * 100, 2) : 0
                    ],
                    'performance_calibragem' => $performanceCalibragem,
                    'alertas' => $alertas
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro no controle clube', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar relatório de controle clube'
            ], 500);
        }
    }

    /**
     * Análise Geográfica
     */
    public function geografico(Request $request)
    {
        try {
            $dataInicio = $request->get('data_inicio', Carbon::now()->subDays(90)->format('Y-m-d'));
            $dataFim = $request->get('data_fim', Carbon::now()->format('Y-m-d'));

            // Performance por distribuidora
            $porDistribuidora = DB::table('propostas')
                ->select(
                    'distribuidora',
                    DB::raw('COUNT(*) as total_propostas'),
                    DB::raw('SUM(CASE WHEN status_proposta = "fechada" THEN 1 ELSE 0 END) as fechadas'),
                    DB::raw('ROUND(AVG(valor_uc), 2) as ticket_medio'),
                    DB::raw('ROUND(AVG(consumo_medio), 2) as consumo_medio')
                )
                ->whereBetween('data_proposta', [$dataInicio, $dataFim])
                ->groupBy('distribuidora')
                ->orderByDesc('fechadas')
                ->get();

            // Top estados/regiões
            $porEstado = DB::table('propostas')
                ->select(
                    'estado',
                    DB::raw('COUNT(*) as total_propostas'),
                    DB::raw('SUM(CASE WHEN status_proposta = "fechada" THEN 1 ELSE 0 END) as fechadas'),
                    DB::raw('ROUND((SUM(CASE WHEN status_proposta = "fechada" THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as taxa_conversao')
                )
                ->whereBetween('data_proposta', [$dataInicio, $dataFim])
                ->groupBy('estado')
                ->orderByDesc('fechadas')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'por_distribuidora' => $porDistribuidora,
                    'por_estado' => $porEstado,
                    'periodo' => "$dataInicio a $dataFim"
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro na análise geográfica', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar análise geográfica'
            ], 500);
        }
    }

    /**
     * Relatórios Financeiros
     */
    public function financeiro(Request $request)
    {
        try {
            $dataInicio = $request->get('data_inicio', Carbon::now()->subMonths(3)->format('Y-m-d'));
            $dataFim = $request->get('data_fim', Carbon::now()->format('Y-m-d'));

            // Pipeline de receita
            $pipeline = DB::table('propostas')
                ->selectRaw('
                    status_proposta,
                    COUNT(*) as quantidade,
                    ROUND(SUM(valor_uc), 2) as valor_total,
                    ROUND(AVG(valor_uc), 2) as ticket_medio
                ')
                ->whereBetween('data_proposta', [$dataInicio, $dataFim])
                ->groupBy('status_proposta')
                ->get();

            // Simulação de comissões
            $comissoes = DB::table('controle_clube as cc')
                ->join('propostas as p', 'p.numero_uc', '=', 'cc.numero_unidade')
                ->select(
                    'cc.consultor',
                    DB::raw('COUNT(*) as total_ucs'),
                    DB::raw('ROUND(SUM(cc.economia_percentual * p.valor_uc * 0.05), 2) as comissao_estimada'),
                    DB::raw('ROUND(AVG(cc.economia_percentual), 2) as economia_media')
                )
                ->whereNotNull('cc.economia_percentual')
                ->groupBy('cc.consultor')
                ->orderByDesc('comissao_estimada')
                ->get();

            // ROI por canal/origem
            $roiCanal = DB::table('propostas')
                ->select(
                    'origem_lead',
                    DB::raw('COUNT(*) as total_propostas'),
                    DB::raw('SUM(CASE WHEN status_proposta = "fechada" THEN 1 ELSE 0 END) as fechadas'),
                    DB::raw('ROUND(SUM(CASE WHEN status_proposta = "fechada" THEN valor_uc ELSE 0 END), 2) as receita_total'),
                    DB::raw('ROUND((SUM(CASE WHEN status_proposta = "fechada" THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as taxa_conversao')
                )
                ->whereBetween('data_proposta', [$dataInicio, $dataFim])
                ->groupBy('origem_lead')
                ->orderByDesc('receita_total')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'pipeline' => $pipeline,
                    'comissoes' => $comissoes,
                    'roi_canal' => $roiCanal,
                    'periodo' => "$dataInicio a $dataFim"
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro no relatório financeiro', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar relatório financeiro'
            ], 500);
        }
    }

    /**
     * Produtividade
     */
    public function produtividade(Request $request)
    {
        try {
            $dataInicio = $request->get('data_inicio', Carbon::now()->subDays(60)->format('Y-m-d'));
            $dataFim = $request->get('data_fim', Carbon::now()->format('Y-m-d'));

            // Ciclo de vendas médio
            $cicloVendas = DB::table('propostas')
                ->select(
                    'consultor',
                    DB::raw('AVG(DATEDIFF(updated_at, data_proposta)) as ciclo_medio_dias'),
                    DB::raw('COUNT(*) as total_propostas'),
                    DB::raw('SUM(CASE WHEN status_proposta = "fechada" THEN 1 ELSE 0 END) as fechadas')
                )
                ->where('status_proposta', 'fechada')
                ->whereBetween('data_proposta', [$dataInicio, $dataFim])
                ->groupBy('consultor')
                ->orderBy('ciclo_medio_dias')
                ->get();

            // Propostas por consultor/mês
            $produtividadeMensal = DB::table('propostas')
                ->select(
                    'consultor',
                    DB::raw('YEAR(data_proposta) as ano'),
                    DB::raw('MONTH(data_proposta) as mes'),
                    DB::raw('COUNT(*) as total_propostas'),
                    DB::raw('SUM(CASE WHEN status_proposta = "fechada" THEN 1 ELSE 0 END) as fechadas')
                )
                ->whereBetween('data_proposta', [$dataInicio, $dataFim])
                ->groupBy('consultor', 'ano', 'mes')
                ->orderBy('ano', 'desc')
                ->orderBy('mes', 'desc')
                ->get();

            // Identificação de gargalos
            $gargalos = DB::table('propostas')
                ->select(
                    'status_proposta',
                    DB::raw('COUNT(*) as quantidade'),
                    DB::raw('AVG(DATEDIFF(NOW(), data_proposta)) as dias_medio_parado'),
                    DB::raw('MAX(DATEDIFF(NOW(), data_proposta)) as dias_max_parado')
                )
                ->whereNotIn('status_proposta', ['fechada', 'perdida'])
                ->groupBy('status_proposta')
                ->orderByDesc('dias_medio_parado')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'ciclo_vendas' => $cicloVendas,
                    'produtividade_mensal' => $produtividadeMensal,
                    'gargalos' => $gargalos,
                    'periodo' => "$dataInicio a $dataFim"
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro no relatório de produtividade', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar relatório de produtividade'
            ], 500);
        }
    }

    /**
     * Exportar dados
     */
    public function exportar(Request $request)
    {
        try {
            $tipo = $request->input('tipo'); // excel, csv, pdf
            $relatorio = $request->input('relatorio'); // dashboard, ranking, etc
            $filtros = $request->input('filtros', []);

            // Registrar tentativa de exportação
            AuditoriaService::registrar('relatorios', null, 'EXPORTACAO_INICIADA', [
                'evento_tipo' => 'EXPORTACAO_RELATORIO',
                'descricao_evento' => "Exportação de relatório iniciada: $relatorio ($tipo)",
                'modulo' => 'relatorios',
                'dados_contexto' => [
                    'tipo' => $tipo,
                    'relatorio' => $relatorio,
                    'filtros' => $filtros,
                    'usuario_id' => auth()->id()
                ]
            ]);

            // Por enquanto retornamos os dados para o frontend processar
            // Implementação completa de exportação seria feita aqui

            return response()->json([
                'success' => true,
                'message' => 'Exportação iniciada com sucesso',
                'data' => [
                    'tipo' => $tipo,
                    'relatorio' => $relatorio,
                    'status' => 'preparando'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro na exportação', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao iniciar exportação'
            ], 500);
        }
    }
}
