<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HistoricoMensalAssociado extends Model
{
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $table = 'historico_mensal_associados';
    public $timestamps = false;

    protected $fillable = [
        'ano_mes',
        'resumo_id',
        'controle_id',
        'proposta_id',
        'uc_id',
        'ug_id',
        'nome_cliente',
        'numero_uc',
        'numero_proposta',
        'apelido_uc',
        'status_troca',
        'ug_nome',
        'consumo_medio',
        'consumo_calibrado',
        'calibragem',
        'desconto_tarifa',
        'desconto_bandeira',
        'consultor',
        'data_entrada_controle',
        'data_assinatura',
        'data_em_andamento',
        'data_titularidade',
        'data_alocacao_ug',
        'created_at',
    ];

    protected $casts = [
        'consumo_medio' => 'float',
        'consumo_calibrado' => 'float',
        'calibragem' => 'float',
        'data_entrada_controle' => 'datetime',
        'data_assinatura' => 'datetime',
        'data_em_andamento' => 'datetime',
        'data_titularidade' => 'date',
        'data_alocacao_ug' => 'datetime',
    ];

    public function resumo()
    {
        return $this->belongsTo(HistoricoMensalResumo::class, 'resumo_id');
    }
}
