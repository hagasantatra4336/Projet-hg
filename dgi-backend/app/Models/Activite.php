<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une ligne du journal d'activités du système (qui a fait quoi, quand, depuis quel appareil). */
class Activite extends Model
{
    public const CATEGORIE_CONNEXION = 'connexion';

    public const CATEGORIE_ADMINISTRATION = 'administration';

    public const CATEGORIE_METIER = 'metier';

    public const CATEGORIE_REGLES = 'regles';

    public const CATEGORIE_PROFIL = 'profil';

    protected $fillable = [
        'user_id',
        'user_nom',
        'role',
        'categorie',
        'action',
        'description',
        'cible',
        'methode',
        'chemin',
        'ip',
        'user_agent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Libellés des catégories, partagés avec le filtre du front. */
    public static function categories(): array
    {
        return [
            self::CATEGORIE_CONNEXION => 'Connexion',
            self::CATEGORIE_ADMINISTRATION => 'Administration',
            self::CATEGORIE_METIER => 'Traitement métier',
            self::CATEGORIE_REGLES => 'Règles & indicateurs',
            self::CATEGORIE_PROFIL => 'Profil',
        ];
    }
}
