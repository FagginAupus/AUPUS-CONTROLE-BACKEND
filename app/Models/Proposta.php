<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Proposta extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'propostas';

    protected $fillable = [
        'numero_proposta',
        'data_proposta',
        'nome_cliente',
        'consultor',
        'usuario_id',
        'recorrencia',
        'economia',
        'bandeira',
        'status',
        'observacoes',
        'beneficios'
    ];

    protected $casts = [
        'data_proposta' => 'date',
        'economia' => 'decimal:2',
        'bandeira' => 'decimal:2',
        'beneficios' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'recorrencia' => '3%',
        'economia' => 20.00,
        'bandeira' => 20.00,
        'status' => 'Aguardando'
    ];

    // Relacionamentos
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function controleClube(): HasMany
    {
        return $this->hasMany(ControleClube::class, 'proposta_id');
    }

    public function unidadesConsumidoras(): HasMany
    {
        return $this->hasMany(UnidadeConsumidora::class, 'proposta_id');
    }

    // Scopes
    public function scopeAguardando($query)
    {
        return $query->where('status', 'Aguardando');
    }

    public function scopeFechado($query)
    {
        return $query->where('status', 'Fechado');
    }

    public function scopePerdido($query)
    {
        return $query->where('status', 'Perdido');
    }

    public function scopeNaoFechado($query)
    {
        return $query->where('status', 'Não Fechado');
    }

    public function scopeCancelado($query)
    {
        return $query->where('status', 'Cancelado');
    }

    public function scopePorConsultor($query, $consultor)
    {
        return $query->where('consultor', $consultor);
    }

    public function scopePorPeriodo($query, $dataInicio, $dataFim)
    {
        return $query->whereBetween('data_proposta', [$dataInicio, $dataFim]);
    }

    public function scopePorUsuario($query, $usuarioId)
    {
        return $query->where('usuario_id', $usuarioId);
    }

    public function scopeComFiltroHierarquico($query, Usuario $usuario)
    {
        if ($usuario->isAdmin()) {
            return $query; // Admin vê tudo
        }
        
        if ($usuario->isConsultor()) {
            $subordinados = $usuario->getAllSubordinates();
            $consultoresPermitidos = array_merge([$usuario->nome], array_column($subordinados, 'nome'));
            return $query->whereIn('consultor', $consultoresPermitidos);
        }
        
        // Gerente e Vendedor veem apenas suas próprias propostas
        return $query->where('consultor', $usuario->nome);
    }

    // Accessors
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'Aguardando' => 'warning',
            'Fechado' => 'success',
            'Perdido' => 'danger',
            'Não Fechado' => 'secondary',
            'Cancelado' => 'dark',
            default => 'secondary'
        };
    }

    public function getStatusIconAttribute(): string
    {
        return match($this->status) {
            'Aguardando' => '⏳',
            'Fechado' => '✅',
            'Perdido' => '❌',
            'Não Fechado' => '⏸️',
            'Cancelado' => '🚫',
            default => '❓'
        };
    }

    public function getDataPropostaFormatadaAttribute(): string
    {
        return $this->data_proposta ? $this->data_proposta->format('d/m/Y') : '';
    }

    public function getEconomiaFormatadaAttribute(): string
    {
        return number_format($this->economia, 2, ',', '.') . '%';
    }

    public function getBandeiraFormatadaAttribute(): string
    {
        return number_format($this->bandeira, 2, ',', '.') . '%';
    }

    public function getTempoAguardandoAttribute(): int
    {
        if ($this->status !== 'Aguardando') return 0;
        
        return Carbon::parse($this->data_proposta)->diffInDays(Carbon::now());
    }

    public function getTempoAguardandoTextoAttribute(): string
    {
        $dias = $this->tempo_aguardando;
        
        if ($dias == 0) return 'Hoje';
        if ($dias == 1) return '1 dia';
        if ($dias <= 7) return "{$dias} dias";
        if ($dias <= 30) return ceil($dias / 7) . ' semanas';
        
        return ceil($dias / 30) . ' meses';
    }

    // Métodos de Negócio
    public function fecharProposta(array $dadosControle = []): bool
    {
        $this->status = 'Fechado';
        $saved = $this->save();

        if ($saved && !empty($dadosControle)) {
            // Criar registro no controle clube
            $this->criarControleClube($dadosControle);
        }

        return $saved;
    }

    public function perderProposta(string $motivo = ''): bool
    {
        $this->status = 'Perdido';
        
        if ($motivo) {
            $observacoesAtuais = $this->observacoes ?? '';
            $this->observacoes = $observacoesAtuais . "\n\nMotivo da perda: " . $motivo;
        }

        return $this->save();
    }

    public function cancelarProposta(string $motivo = ''): bool
    {
        $this->status = 'Cancelado';
        
        if ($motivo) {
            $observacoesAtuais = $this->observacoes ?? '';
            $this->observacoes = $observacoesAtuais . "\n\nMotivo do cancelamento: " . $motivo;
        }

        return $this->save();
    }

    public function reativarProposta(): bool
    {
        if (in_array($this->status, ['Perdido', 'Cancelado', 'Não Fechado'])) {
            $this->status = 'Aguardando';
            return $this->save();
        }

        return false;
    }

    private function criarControleClube(array $dados): ControleClube
    {
        return ControleClube::create([
            'proposta_id' => $this->id,
            'numero_proposta' => $this->numero_proposta,
            'numero_uc' => $dados['numero_uc'] ?? null,
            'nome_cliente' => $this->nome_cliente,
            'consultor' => $this->consultor,
            'usuario_id' => $this->usuario_id,
            'consumo_medio' => $dados['consumo_medio'] ?? null,
            'geracao_prevista' => $dados['geracao_prevista'] ?? null,
            'economia_percentual' => $this->economia,
            'desconto_bandeira' => $this->bandeira,
            'recorrencia' => $this->recorrencia,
            'data_inicio_clube' => $dados['data_inicio_clube'] ?? Carbon::now()->toDateString(),
            'ativo' => true
        ]);
    }

    // Validações
    public function isValidForFechamento(): array
    {
        $errors = [];

        if ($this->status !== 'Aguardando') {
            $errors[] = 'Apenas propostas aguardando podem ser fechadas';
        }

        if (empty($this->nome_cliente)) {
            $errors[] = 'Nome do cliente é obrigatório';
        }

        if (empty($this->consultor)) {
            $errors[] = 'Consultor é obrigatório';
        }

        // Verificar se tem UCs vinculadas
        if ($this->unidadesConsumidoras()->count() === 0) {
            $errors[] = 'Pelo menos uma unidade consumidora deve estar vinculada';
        }

        return $errors;
    }

    // Estatísticas
    public static function getEstatisticas($filtros = [])
    {
        $query = self::query();

        if (isset($filtros['usuario_id'])) {
            $query->where('usuario_id', $filtros['usuario_id']);
        }

        if (isset($filtros['consultor'])) {
            $query->where('consultor', $filtros['consultor']);
        }

        if (isset($filtros['periodo'])) {
            $query->whereBetween('data_proposta', $filtros['periodo']);
        }

        if (isset($filtros['usuario']) && $filtros['usuario'] instanceof Usuario) {
            $query->comFiltroHierarquico($filtros['usuario']);
        }

        $total = $query->count();
        
        return [
            'total' => $total,
            'aguardando' => $query->clone()->where('status', 'Aguardando')->count(),
            'fechado' => $query->clone()->where('status', 'Fechado')->count(),
            'perdido' => $query->clone()->where('status', 'Perdido')->count(),
            'nao_fechado' => $query->clone()->where('status', 'Não Fechado')->count(),
            'cancelado' => $query->clone()->where('status', 'Cancelado')->count(),
            'taxa_fechamento' => $total > 0 ? 
                ($query->clone()->where('status', 'Fechado')->count() / $total) * 100 : 0,
            'tempo_medio_fechamento' => self::getTempoMedioFechamento($filtros)
        ];
    }

    private static function getTempoMedioFechamento($filtros = []): float
    {
        $query = self::query()->where('status', 'Fechado');
        
        if (isset($filtros['periodo'])) {
            $query->whereBetween('data_proposta', $filtros['periodo']);
        }
        
        $propostas = $query->get();
        
        if ($propostas->isEmpty()) return 0;
        
        $somaDias = $propostas->sum(function($proposta) {
            return Carbon::parse($proposta->data_proposta)->diffInDays($proposta->updated_at);
        });
        
        return round($somaDias / $propostas->count(), 1);
    }

    // Boot method para eventos
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($proposta) {
            if (empty($proposta->numero_proposta)) {
                $proposta->numero_proposta = self::gerarNumeroProposta();
            }
            
            if (empty($proposta->data_proposta)) {
                $proposta->data_proposta = Carbon::now()->toDateString();
            }
        });

        static::updating(function ($proposta) {
            // Log de mudanças de status
            if ($proposta->isDirty('status')) {
                $statusOriginal = $proposta->getOriginal('status');
                $statusNovo = $proposta->status;
                
                \Log::info("Proposta {$proposta->numero_proposta}: Status alterado de '{$statusOriginal}' para '{$statusNovo}'");
            }
        });
    }

    private static function gerarNumeroProposta(): string
    {
        $ano = Carbon::now()->format('Y');
        $ultimoNumero = self::whereYear('created_at', Carbon::now()->year)
                           ->whereRaw("numero_proposta LIKE '{$ano}-%'")
                           ->count();
        
        $proximoNumero = str_pad($ultimoNumero + 1, 4, '0', STR_PAD_LEFT);
        
        return "{$ano}-{$proximoNumero}";
    }
}