<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Declaration extends Model
{
    protected $fillable = ['contribuable_id', 'type_impot', 'periode', 'montant', 'chiffre_affaires', 'date_depot'];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'chiffre_affaires' => 'decimal:2',
            'date_depot' => 'date',
        ];
    }

    public function contribuable(): BelongsTo
    {
        return $this->belongsTo(Contribuable::class);
    }
}
