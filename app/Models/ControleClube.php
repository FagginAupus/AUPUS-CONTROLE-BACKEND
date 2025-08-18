<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ControleClube extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Indicar que utilizamos UUID ao invés de incremento automático
     */
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * Nome da tabela
     */
    protected $table = 'controle_clube';

    /**
     * Campos que podem ser preenchidos via mass assignment
     */
    protected $fillable = [
        'id',
        'proposta_id',
        'uc_id',
        'ug_id',
        'observacoes',
        'data_entrada_controle',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * Campos que devem ser convertidos para outros tipos
     */
    protected $casts = [
        'id' => 'string',
        'proposta_id' => 'string',
        'uc_id' => 'string',
        'ug_id' => 'string',
        'data_entrada_controle' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * Campos protegidos (não serão mostrados em JSON)
     */
    protected $hidden = [
        'deleted_at'
    ];


    /**
     * ===================================
     * RELACIONAMENTOS
     * ===================================
     */

    /**
     * Relacionamento com Proposta
     */
    public function proposta()
    {
        return $this->belongsTo(Proposta::class, 'proposta_id', 'id');
    }

    /**
     * Relacionamento com Unidade Consumidora
     */
    public function unidadeConsumidora()
    {
        return $this->belongsTo(UnidadeConsumidora::class, 'uc_id', 'id');
    }

    /**
     * Relacionamento com Unidade Geradora (UC que é UG)
     */
    public function unidadeGeradora()
    {
        return $this->belongsTo(UnidadeConsumidora::class, 'ug_id', 'id');
    }

    /**
     * ===================================
     * SCOPES
     * ===================================
     */

    /**
     * Scope para filtrar por proposta
     */
    public function scopeDaProposta($query, $propostaId)
    {
        return $query->where('proposta_id', $propostaId);
    }

    /**
     * Scope para filtrar por UC
     */
    public function scopeDaUc($query, $ucId)
    {
        return $query->where('uc_id', $ucId);
    }

    /**
     * Scope para filtrar por UG
     */
    public function scopeDaUg($query, $ugId)
    {
        return $query->where('ug_id', $ugId);
    }

    /**
     * Scope para controles com calibragem
     */
    public function scopeComCalibragem($query)
    {
        return $query->where('calibragem', '!=', 0);
    }

    /**
     * Scope para controles sem UG
     */
    public function scopeSemUg($query)
    {
        return $query->whereNull('ug_id');
    }

    /**
     * Scope para controles com UG
     */
    public function scopeComUg($query)
    {
        return $query->whereNotNull('ug_id');
    }

    /**
     * Verificar se tem UG vinculada
     */
    public function temUg(): bool
    {
        return !is_null($this->ug_id);
    }

    /**
     * Obter controles ativos (não excluídos)
     */
    public static function ativos()
    {
        return static::whereNull('deleted_at');
    }

    /**
     * Obter controles do mês atual
     */
    public static function doMesAtual()
    {
        return static::whereMonth('data_entrada_controle', now()->month)
                    ->whereYear('data_entrada_controle', now()->year);
    }

    /**
     * Obter controles do ano atual
     */
    public static function doAnoAtual()
    {
        return static::whereYear('data_entrada_controle', now()->year);
    }

    /**
     * ===================================
     * EVENTOS DO MODEL
     * ===================================
     */

    /**
     * Boot do modelo
     */
    protected static function boot()
    {
        parent::boot();

        // Antes de criar
        static::creating(function ($controle) {
            if (empty($controle->id)) {
                $controle->id = (string) \Illuminate\Support\Str::uuid();
            }

            // Se não tem data de entrada, usar a atual
            if (empty($controle->data_entrada_controle)) {
                $controle->data_entrada_controle = now();
            }
        });

        // Depois de criar
        static::created(function ($controle) {
            \Log::info('Controle clube criado', [
                'controle_id' => $controle->id,
                'proposta_id' => $controle->proposta_id,
                'uc_id' => $controle->uc_id,
                'ug_id' => $controle->ug_id,
                'calibragem' => $controle->calibragem,
                'usuario_id' => auth()->user()->id ?? 'sistema'
            ]);
        });

        // Antes de atualizar
        static::updating(function ($controle) {
            // Log das mudanças importantes
            if ($controle->isDirty('calibragem')) {
                \Log::info('Calibragem do controle alterada', [
                    'controle_id' => $controle->id,
                    'calibragem_anterior' => $controle->getOriginal('calibragem'),
                    'calibragem_nova' => $controle->calibragem,
                    'usuario_id' => auth()->user()->id ?? 'sistema'
                ]);
            }

            if ($controle->isDirty('ug_id')) {
                \Log::info('UG do controle alterada', [
                    'controle_id' => $controle->id,
                    'ug_anterior' => $controle->getOriginal('ug_id'),
                    'ug_nova' => $controle->ug_id,
                    'usuario_id' => auth()->user()->id ?? 'sistema'
                ]);
            }
        });
    }

    /**
     * ===================================
     * VALIDAÇÕES PERSONALIZADAS
     * ===================================
     */

    /**
     * Validar se a combinação proposta+UC é única
     */
    public function validarUnicidadePropostaUc(): bool
    {
        $existe = static::where('proposta_id', $this->proposta_id)
                        ->where('uc_id', $this->uc_id)
                        ->where('id', '!=', $this->id)
                        ->exists();

        return !$existe;
    }

    /**
     * ===================================
     * SERIALIZAÇÃO
     * ===================================
     */

    /**
     * Definir como o modelo deve ser serializado para JSON
     */
    public function toArray()
    {
        $array = parent::toArray();
        
        // Adicionar campos calculados
        $array['calibragem_formatada'] = $this->calibragem_formatada;
        $array['valor_calibrado_formatado'] = $this->valor_calibrado_formatado;
        $array['tipo_calibragem'] = $this->tipo_calibragem;
        $array['cor_calibragem'] = $this->cor_calibragem;
        $array['tem_calibragem'] = $this->temCalibragem();
        $array['tem_ug'] = $this->temUg();
        $array['tem_valor_calibrado'] = $this->temValorCalibrado();
        
        return $array;
    }
}