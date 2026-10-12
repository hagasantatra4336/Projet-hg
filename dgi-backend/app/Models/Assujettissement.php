<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Obligation déclarative d'un contribuable pour un impôt et une année (périodicité : 1 mensuel … 5 annuel). */
class Assujettissement extends Model
{
    public const PERIODICITES = [1 => 'mensuelle', 2 => 'bimestrielle', 3 => 'trimestrielle', 4 => 'semestrielle', 5 => 'annuelle'];

    protected $fillable = ['contribuable_id', 'type_impot', 'annee_fiscale', 'periodicite', 'date_debut', 'date_fin', 'actif', 'exonere'];

    protected function casts(): array
    {
        return [
            'annee_fiscale' => 'integer',
            'periodicite' => 'integer',
            'date_debut' => 'date',
            'date_fin' => 'date',
            'actif' => 'boolean',
            'exonere' => 'boolean',
        ];
    }

    public function contribuable(): BelongsTo
    {
        return $this->belongsTo(Contribuable::class);
    }
}
