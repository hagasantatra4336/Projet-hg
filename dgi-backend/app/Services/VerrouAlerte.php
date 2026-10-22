<?php

namespace App\Services;

use App\Models\Alerte;
use Illuminate\Support\Carbon;

/**
 * Verrou de traitement d'un dossier (alerte). Un dossier « pris » par un agent est « En traitement » pour les autres,
 * qui ne peuvent pas le prendre. Le verrou expire après DUREE_MINUTES sans activité (agent parti sans libérer le dossier),
 * et chaque enregistrement du propriétaire le prolonge. Un admin, central ou superadmin peut reprendre un dossier pris.
 */
class VerrouAlerte
{
    public const DUREE_MINUTES = 240;

    public static function limite(): Carbon
    {
        return now()->subMinutes(self::DUREE_MINUTES);
    }

    /** Le dossier est-il actuellement pris (verrou non expiré, alerte encore « à traiter ») ? */
    public static function verrouille(Alerte $a): bool
    {
        return $a->statut === Alerte::A_TRAITER
            && $a->getAttribute('pris_par') !== null
            && $a->getAttribute('pris_le') !== null
            && Carbon::parse($a->getAttribute('pris_le'))->gt(self::limite());
    }

    /** Alertes à traiter ET prises (« En traitement »). @param \Illuminate\Database\Eloquent\Builder $q */
    public static function verrouillees($q): void
    {
        $q->where('alertes.statut', Alerte::A_TRAITER)
            ->whereNotNull('alertes.pris_par')
            ->where('alertes.pris_le', '>', self::limite());
    }

    /** Alertes à traiter et LIBRES (personne ne les a prises, ou verrou expiré). @param \Illuminate\Database\Eloquent\Builder $q */
    public static function libres($q): void
    {
        $q->where('alertes.statut', Alerte::A_TRAITER)
            ->where(fn ($r) => $r->whereNull('alertes.pris_par')->orWhereNull('alertes.pris_le')->orWhere('alertes.pris_le', '<=', self::limite()));
    }
}
