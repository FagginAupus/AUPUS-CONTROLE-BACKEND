<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class ControleClube extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'controle_clube';

    protected $fillable = [
        // Relacionamentos
        'proposta_id',
        'uc_id',
        'ug_id',
        'usuario_id',
        
        // Dados básicos (denormalizados para performance)
        'numero_proposta',
        'numero_uc',
        'nome_cliente',
        'consultor',
        
        // Dados operacionais
        'consumo_medio',
        'geracao_prevista',
        'economia_percentual',
        'desconto_bandeira',
        'recorrencia',
        'calibragem_aplicada',
        
        // Controle de datas
        'data_inicio_clube',
        'data_fim_clube',
        'data_ultima_calibragem',
        
        // Status e observações
        'ativo',
        'motivo_inativacao',
        'observacoes'
    ];

    protected $casts = [
        // Decimals
        'consumo_medio' => 'decimal:2',
        'geracao_prevista' => 'decimal:2',
        'economia_percentual' => 'decimal:2',
        'desconto_bandeira' => 'decimal:2',
        'calibragem_aplicada' => 'decimal:2',
        
        // Booleans
        'ativo' => 'boolean',
        
        // Dates
        'data_inicio_clube' => 'date',
        'data_fim_clube' => 'date',
        'data_ultima_calibragem' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'ativo' => true,
        'economia_percentual' => 20.00,
        'desconto_bandeira' => 20.00,
        'recorrencia' => '3%'
    ];

    // Relacionamentos
    public function proposta(): BelongsTo
    {
        return $this->belongsTo(Proposta::class, 'proposta_id');
    }

    public function unidadeConsumidora(): BelongsTo
    {
        return $this->belongsTo(UnidadeConsumidora::class, 'uc_id');
    }

    public function usinaGeradora(): BelongsTo
    {
        return $this->belongsTo(UnidadeConsumidora::class, 'ug_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    // Scopes
    public function scopeAtivos($query)
    {
        return $query->where('ativo', true);
    }

    public function scopeInativos($query)
    {
        return $query->where('ativo', false);
    }

    public function scopePorConsultor($query, $consultor)
    {
        return $query->where('consultor', $consultor);
    }

    public function scopePorUsuario($query, $usuarioId)
    {
        return $query->where('usuario_id', $usuarioId);
    }

    public function scopePorProposta($query, $propostaId)
    {
        return $query->where('proposta_id', $propostaId);
    }

    public function scopeComUG($query)
    {
        return $query->whereNotNull('ug_id');
    }

    public function scopeSemUG($query)
    {
        return $query->whereNull('ug_id');
    }

    public function scopePorPeriodoInicio($query, $dataInicio, $dataFim)
    {
        return $query->whereBetween('data_inicio_clube', [$dataInicio, $dataFim]);
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
        
        // Gerente e Vendedor veem apenas seu próprio controle
        return $query->where('consultor', $usuario->nome);
    }

    // Accessors
    public function getStatusDisplayAttribute(): string
    {
        if (!$this->ativo) {
            return '❌ Inativo';
        }
        
        if ($this->data_fim_clube && $this->data_fim_clube < Carbon::now()) {
            return '⏰ Expirado';
        }
        
        if ($this->ug_id) {
            return '🏭 Ativo (com UG)';
        }
        
        return '✅ Ativo';
    }

    public function getStatusColorAttribute(): string
    {
        if (!$this->ativo) return 'danger';
        if ($this->data_fim_clube && $this->data_fim_clube < Carbon::now()) return 'warning';
        return 'success';
    }

    public function getTempoAtividadeAttribute(): int
    {
        if (!$this->data_inicio_clube) return 0;
        
        $dataFim = $this->data_fim_clube ?? Carbon::now();
        
        return Carbon::parse($this->data_inicio_clube)->diffInDays($dataFim);
    }

    public function getTempoAtividadeTextoAttribute(): string
    {
        $dias = $this->tempo_atividade;
        
        if ($dias < 30) {
            return "{$dias} dias";
        }
        
        if ($dias < 365) {
            $meses = round($dias / 30);
            return "{$meses} meses";
        }
        
        $anos = round($dias / 365, 1);
        return "{$anos} anos";
    }

    public function getEconomiaCalculadaAttribute(): float
    {
        if (!$this->consumo_medio || !$this->economia_percentual) {
            return 0;
        }
        
        return ($this->consumo_medio * $this->economia_percentual) / 100;
    }

    public function getDescontoBandeiraCalculadoAttribute(): float
    {
        if (!$this->consumo_medio || !$this->desconto_bandeira) {
            return 0;
        }
        
        return ($this->consumo_medio * $this->desconto_bandeira) / 100;
    }

    public function getConsumoFormatadoAttribute(): string
    {
        return number_format($this->consumo_medio, 2, ',', '.') . ' kWh';
    }

    public function getGeracaoFormatadaAttribute(): string
    {
        return number_format($this->geracao_prevista ?? 0, 2, ',', '.') . ' kWh';
    }

    public function getEconomiaFormatadaAttribute(): string
    {
        return number_format($this->economia_percentual, 2, ',', '.') . '%';
    }

    public function getBandeiraFormatadaAttribute(): string
    {
        return number_format($this->desconto_bandeira, 2, ',', '.') . '%';
    }

    public function getCalibragemFormatadaAttribute(): string
    {
        return number_format($this->calibragem_aplicada ?? 0, 2, ',', '.') . '%';
    }

    // Métodos de Negócio
    public function aplicarCalibragem(float $percentualCalibragem, Usuario $usuario): bool
    {
        $fatorCalibragem = 1 + ($percentualCalibragem / 100);
        
        $this->consumo_medio = $this->consumo_medio * $fatorCalibragem;
        $this->calibragem_aplicada = $percentualCalibragem;
        $this->data_ultima_calibragem = Carbon::now();
        
        // Log da calibragem
        $observacaoCalibrada = "\n[" . Carbon::now()->format('d/m/Y H:i') . "] ";
        $observacaoCalibrada .= "Calibragem de {$percentualCalibragem}% aplicada por {$usuario->nome}";
        
        $this->observacoes = ($this->observacoes ?? '') . $observacaoCalibrada;
        
        return $this->save();
    }

    public function vincularUG(UnidadeConsumidora $ug, Usuario $usuario): bool
    {
        if (!$ug->is_ug) {
            return false;
        }
        
        $this->ug_id = $ug->id;
        $this->geracao_prevista = $ug->capacidade_calculada;
        
        // Log da vinculação
        $observacaoUG = "\n[" . Carbon::now()->format('d/m/Y H:i') . "] ";
        $observacaoUG .= "UG {$ug->nome_usina} vinculada por {$usuario->nome}";
        
        $this->observacoes = ($this->observacoes ?? '') . $observacaoUG;
        
        return $this->save();
    }

    public function desvincularUG(Usuario $usuario, string $motivo = ''): bool
    {
        if (!$this->ug_id) {
            return false;
        }
        
        $ugNome = $this->usinaGeradora->nome_usina ?? 'UG Desconhecida';
        
        $this->ug_id = null;
        $this->geracao_prevista = null;
        
        // Log da desvinculação
        $observacaoDesvinc = "\n[" . Carbon::now()->format('d/m/Y H:i') . "] ";
        $observacaoDesvinc .= "UG {$ugNome} desvinculada por {$usuario->nome}";
        if ($motivo) {
            $observacaoDesvinc .= " - Motivo: {$motivo}";
        }
        
        $this->observacoes = ($this->observacoes ?? '') . $observacaoDesvinc;
        
        return $this->save();
    }

    public function inativar(string $motivo, Usuario $usuario): bool
    {
        $this->ativo = false;
        $this->motivo_inativacao = $motivo;
        $this->data_fim_clube = Carbon::now();
        
        // Log da inativação
        $observacaoInativ = "\n[" . Carbon::now()->format('d/m/Y H:i') . "] ";
        $observacaoInativ .= "Inativado por {$usuario->nome} - Motivo: {$motivo}";
        
        $this->observacoes = ($this->observacoes ?? '') . $observacaoInativ;
        
        return $this->save();
    }

    public function reativar(Usuario $usuario): bool
    {
        if ($this->ativo) {
            return false; // Já está ativo
        }
        
        $this->ativo = true;
        $this->motivo_inativacao = null;
        $this->data_fim_clube = null;
        
        // Log da reativação
        $observacaoReativ = "\n[" . Carbon::now()->format('d/m/Y H:i') . "] ";
        $observacaoReativ .= "Reativado por {$usuario->nome}";
        
        $this->observacoes = ($this->observacoes ?? '') . $observacaoReativ;
        
        return $this->save();
    }

    // Validações
    public function isValidForCalibragem(): array
    {
        $errors = [];
        
        if (!$this->ativo) {
            $errors[] = 'Registro deve estar ativo para aplicar calibragem';
        }
        
        if (!$this->consumo_medio || $this->consumo_medio <= 0) {
            $errors[] = 'Consumo médio deve ser maior que zero';
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
            $query->whereBetween('data_inicio_clube', $filtros['periodo']);
        }
        
        if (isset($filtros['usuario']) && $filtros['usuario'] instanceof Usuario) {
            $query->comFiltroHierarquico($filtros['usuario']);
        }
        
        return [
            'total' => $query->count(),
            'ativos' => $query->clone()->ativos()->count(),
            'inativos' => $query->clone()->inativos()->count(),
            'com_ug' => $query->clone()->comUG()->count(),
            'sem_ug' => $query->clone()->semUG()->count(),
            'consumo_total' => $query->clone()->ativos()->sum('consumo_medio'),
            'geracao_total' => $query->clone()->ativos()->sum('geracao_prevista'),
            'economia_total' => $query->clone()->ativos()->get()->sum('economia_calculada'),
            'tempo_medio_atividade' => self::getTempoMedioAtividade($filtros)
        ];
    }

    private static function getTempoMedioAtividade($filtros = []): float
    {
        $query = self::query()->ativos();
        
        if (isset($filtros['periodo'])) {
            $query->whereBetween('data_inicio_clube', $filtros['periodo']);
        }
        
        $registros = $query->get();
        
        if ($registros->isEmpty()) return 0;
        
        $somaDias = $registros->sum('tempo_atividade');
        
        return round($somaDias / $registros->count(), 1);
    }

    // Boot method para eventos
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($controle) {
            if (empty($controle->data_inicio_clube)) {
                $controle->data_inicio_clube = Carbon::now()->toDateString();
            }
        });

        static::updating(function ($controle) {
            // Log de mudanças importantes
            if ($controle->isDirty('ativo')) {
                $statusOriginal = $controle->getOriginal('ativo') ? 'Ativo' : 'Inativo';
                $statusNovo = $controle->ativo ? 'Ativo' : 'Inativo';
                
                \Log::info("Controle Clube {$controle->numero_proposta}/{$controle->numero_uc}: Status alterado de '{$statusOriginal}' para '{$statusNovo}'");
            }
        });
    }
}