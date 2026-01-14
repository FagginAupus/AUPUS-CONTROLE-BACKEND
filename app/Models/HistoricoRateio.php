<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class HistoricoRateio extends Model
{
    use SoftDeletes, HasUlids;

    protected $table = 'historico_rateios';

    protected $fillable = [
        'ug_id',
        'data_envio',
        'data_efetivacao',
        'arquivo_nome',
        'arquivo_path',
        'observacoes',
        'usuario_id'
    ];

    protected $casts = [
        'data_envio' => 'date',
        'data_efetivacao' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * Relacionamento com a UG (Unidade Geradora)
     */
    public function ug()
    {
        return $this->belongsTo(UnidadeConsumidora::class, 'ug_id');
    }

    /**
     * Relacionamento com o usuário que cadastrou
     */
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
