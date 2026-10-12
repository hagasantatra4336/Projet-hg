<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Declaration extends Model
{
    protected $fillable = ['contribuable_id', 'type_impot', 'annee_fiscale', 'mois', 'chiffre_affaires', 'date_limite', 'date_depot'];

    protected function casts(): array
    {
        return [
            'annee_fiscale' => 'integer',
            'mois' => 'integer',
            'chiffre_affaires' => 'decimal:2',
            'date_limite' => 'date',
            'date_depot' => 'date',
        ];
    }

    public function contribuable(): BelongsTo
    {
        return $this->belongsTo(Contribuable::class);
    }
}
