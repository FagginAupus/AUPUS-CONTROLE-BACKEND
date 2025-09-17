<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids; 
use Illuminate\Support\Str; 

class Document extends Model
{   

    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * Generate a new ULID for the model.
     */
    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    const STATUS_PENDING = 'pending';
    const STATUS_SIGNED = 'signed';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';
    
    protected $fillable = [
        'autentique_id',
        'name',
        'status',
        'is_sandbox',
        'proposta_id',
        'document_data',
        'signers',
        'autentique_response',
        'total_signers',
        'signed_count',
        'rejected_count',
        'autentique_created_at',
        'last_checked_at',
        'created_by',
        'cancelled_at',     
        'cancelled_by',
        // ← ADICIONAR ESTES CAMPOS:
        'uploaded_manually',
        'uploaded_at',
        'uploaded_by', 
        'manual_upload_filename',
        'signer_email',
        'signer_name',
        'signing_url',
        'envio_whatsapp',  // ← CRÍTICO
        'envio_email'      // ← CRÍTICO
    ];

    protected $casts = [
        'document_data' => 'array',
        'signers' => 'array', 
        'autentique_response' => 'array',
        'is_sandbox' => 'boolean',
        'autentique_created_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'cancelled_at' => 'datetime',
        // ← ADICIONAR ESTES:
        'uploaded_manually' => 'boolean',
        'uploaded_at' => 'datetime',
        'envio_whatsapp' => 'boolean',  // ← CRÍTICO
        'envio_email' => 'boolean'      // ← CRÍTICO
    ];

    public function proposta(): BelongsTo
    {
        return $this->belongsTo(Proposta::class, 'proposta_id', 'id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by', 'id');
    }

    public function getSigningProgressAttribute(): int
    {
        if ($this->total_signers == 0) return 0;
        return round(($this->signed_count / $this->total_signers) * 100);
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'Aguardando Assinatura',
            self::STATUS_SIGNED => 'Assinado',
            self::STATUS_REJECTED => 'Rejeitado',
            self::STATUS_CANCELLED => 'Cancelado',
            default => 'Status Desconhecido'
        };
    }

    public function isCancelled(): bool  // ← ADICIONAR MÉTODO
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_SIGNED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }
}