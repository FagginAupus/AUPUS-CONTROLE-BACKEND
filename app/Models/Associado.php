<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Associado extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * Indicar que utilizamos UUID ao invés de incremento automático
     */
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * Nome da tabela
     */
    protected $table = 'associados';

    /**
     * Campos que podem ser preenchidos via mass assignment
     */
    protected $fillable = [
        'id',
        'nome',
        'cpf_cnpj',
        'whatsapp',
        'email',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * Campos que devem ser convertidos para outros tipos
     */
    protected $casts = [
        'id' => 'string',
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

    // ========================================
    // RELACIONAMENTOS
    // ========================================

    /**
     * Relacionamento com Unidades Consumidoras (1:N)
     * Um associado pode ter múltiplas UCs
     */
    public function unidadesConsumidoras(): HasMany
    {
        return $this->hasMany(UnidadeConsumidora::class, 'associado_id', 'id');
    }

    /**
     * Relacionamento com Controle Clube (1:N)
     * Um associado pode ter múltiplos controles
     */
    public function controles(): HasMany
    {
        return $this->hasMany(ControleClube::class, 'associado_id', 'id');
    }

    // ========================================
    // SCOPES
    // ========================================

    /**
     * Scope para buscar por CPF/CNPJ
     */
    public function scopePorCpfCnpj($query, $cpfCnpj)
    {
        // Limpar formatação para busca
        $cpfCnpjLimpo = preg_replace('/[^0-9]/', '', $cpfCnpj);

        return $query->where(function($q) use ($cpfCnpj, $cpfCnpjLimpo) {
            $q->where('cpf_cnpj', $cpfCnpj)
              ->orWhere('cpf_cnpj', $cpfCnpjLimpo)
              ->orWhereRaw("REPLACE(REPLACE(REPLACE(cpf_cnpj, '.', ''), '-', ''), '/', '') = ?", [$cpfCnpjLimpo]);
        });
    }

    /**
     * Scope para buscar por nome
     */
    public function scopePorNome($query, $nome)
    {
        return $query->where('nome', 'ILIKE', '%' . $nome . '%');
    }

    // ========================================
    // ACCESSORS
    // ========================================

    /**
     * Accessor: CPF/CNPJ formatado
     */
    public function getCpfCnpjFormatadoAttribute(): string
    {
        $cpfCnpj = preg_replace('/[^0-9]/', '', $this->cpf_cnpj);

        if (strlen($cpfCnpj) === 11) {
            // CPF: 000.000.000-00
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpfCnpj);
        } elseif (strlen($cpfCnpj) === 14) {
            // CNPJ: 00.000.000/0000-00
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cpfCnpj);
        }

        return $this->cpf_cnpj;
    }

    /**
     * Accessor: Quantidade de UCs
     */
    public function getQuantidadeUcsAttribute(): int
    {
        return $this->unidadesConsumidoras()->count();
    }

    /**
     * Accessor: Tipo de pessoa (PF ou PJ)
     */
    public function getTipoPessoaAttribute(): string
    {
        $cpfCnpj = preg_replace('/[^0-9]/', '', $this->cpf_cnpj);
        return strlen($cpfCnpj) === 11 ? 'PF' : 'PJ';
    }

    // ========================================
    // MÉTODOS DE NEGÓCIO
    // ========================================

    /**
     * Buscar associado existente por CPF/CNPJ
     */
    public static function buscarPorCpfCnpj(string $cpfCnpj): ?self
    {
        return self::porCpfCnpj($cpfCnpj)->first();
    }

    /**
     * Verificar se CPF/CNPJ já existe
     */
    public static function cpfCnpjExiste(string $cpfCnpj): bool
    {
        return self::porCpfCnpj($cpfCnpj)->exists();
    }

    /**
     * Vincular uma UC ao associado
     */
    public function vincularUc(string $ucId): bool
    {
        return UnidadeConsumidora::where('id', $ucId)
            ->update(['associado_id' => $this->id]);
    }

    /**
     * Vincular um controle ao associado
     */
    public function vincularControle(string $controleId): bool
    {
        return ControleClube::where('id', $controleId)
            ->update(['associado_id' => $this->id]);
    }

    // ========================================
    // SERIALIZAÇÃO
    // ========================================

    /**
     * Definir como o modelo deve ser serializado para JSON
     */
    public function toArray()
    {
        $array = parent::toArray();

        // Adicionar campos calculados
        $array['cpf_cnpj_formatado'] = $this->cpf_cnpj_formatado;
        $array['quantidade_ucs'] = $this->quantidade_ucs;
        $array['tipo_pessoa'] = $this->tipo_pessoa;

        return $array;
    }
}
