<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alerte extends Model
{
    public const REGLE_DEFAILLANCE = 'defaillance';
    public const REGLE_BAISSE_CA = 'baisse_ca';
    public const REGLES = ['defaillance', 'baisse_ca'];

    /** Du moins grave au plus grave. */
    public const NIVEAUX = ['faible', 'moyen', 'eleve'];

    public const A_TRAITER = 'a_traiter';
    public const TRAITEE = 'traitee';
    public const REGULARISEE = 'regularisee'; // l'anomalie n'est plus constatée (statut automatique)

    protected $fillable = [
        'contribuable_id',
        'regle',
        'type_impot',
        'annee_fiscale',
        'mois',
        'date_limite',
        'periodes_manquees',
        'periodes_declarees',
        'niveau',
        'motif',
        'details',
        'statut',
        'commentaire',
        'traite_par',
        'traite_le',
    ];

    protected function casts(): array
    {
        return [
            'annee_fiscale' => 'integer',
            'mois' => 'integer',
            'periodes_manquees' => 'integer',
            'periodes_declarees' => 'integer',
            'date_limite' => 'date',
            'details' => 'array',
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
