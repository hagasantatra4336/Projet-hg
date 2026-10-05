<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alerte extends Model
{
    public const REGLE_DEFAILLANCE = 'defaillance';

    /** Du moins grave au plus grave. */
    public const NIVEAUX = ['faible', 'moyen', 'eleve'];

    public const A_TRAITER = 'a_traiter';
    public const TRAITEE = 'traitee';
    public const REGULARISEE = 'regularisee'; // le contribuable a déposé depuis (statut automatique)

    protected $fillable = [
        'contribuable_id',
        'regle',
        'type_impot',
        'periode',
        'mois_manques',
        'serie_precedente',
        'niveau',
        'motif',
        'statut',
        'commentaire',
        'traite_par',
        'traite_le',
    ];

    protected function casts(): array
    {
        return [
            'mois_manques' => 'integer',
            'serie_precedente' => 'integer',
            'traite_le' => 'datetime',
        ];
    }

    public function contribuable(): BelongsTo
    {
        return $this->belongsTo(Contribuable::class);
    }

    public function traitePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traite_par');
    }
}
