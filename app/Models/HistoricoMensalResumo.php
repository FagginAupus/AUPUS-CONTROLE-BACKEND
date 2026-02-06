<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HistoricoMensalResumo extends Model
{
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $table = 'historico_mensal_resumo';

    protected $fillable = [
        'ano_mes',
        'total_associados',
        'novos_no_mes',
        'saidas_no_mes',
        'total_esteira',
        'total_em_andamento',
        'total_associado',
        'total_saindo',
        'total_com_ug',
        'total_sem_ug',
    ];

    protected $casts = [
        'total_associados' => 'integer',
        'novos_no_mes' => 'integer',
        'saidas_no_mes' => 'integer',
        'total_esteira' => 'integer',
        'total_em_andamento' => 'integer',
        'total_associado' => 'integer',
        'total_saindo' => 'integer',
        'total_com_ug' => 'integer',
        'total_sem_ug' => 'integer',
    ];

    public function itens()
    {
        return $this->hasMany(HistoricoMensalAssociado::class, 'resumo_id');
    }
}
