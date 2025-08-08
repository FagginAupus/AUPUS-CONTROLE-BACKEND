<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Configuracao extends Model
{
    protected $table = 'configuracoes';
    
    protected $keyType = 'string';
    public $incrementing = false;
    
    protected $fillable = [
        'id', 'chave', 'valor', 'tipo', 'descricao', 'grupo', 'updated_by'
    ];
    
    protected $casts = [
        'valor' => 'string',
    ];
    
    // Gerar UUID automaticamente
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }
    
    // Relacionamento com usuário que fez a alteração
    public function updatedBy()
    {
        return $this->belongsTo(Usuario::class, 'updated_by');
    }
    
    // Helper para obter valor tipado
    public function getTypedValueAttribute()
    {
        switch ($this->tipo) {
            case 'number':
                return (float) $this->valor;
            case 'boolean':
                return (bool) $this->valor;
            case 'json':
                return json_decode($this->valor, true);
            default:
                return $this->valor;
        }
    }
}