<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Calendrier fiscal officiel DGI (« Échéances fiscales » du Calendrier 2026, impots.mg).
 *
 * Une période n'est jugée qu'à partir de DATE LIMITE DE PAIEMENT + DÉLAI (48 h par défaut) :
 * les déclarations peuvent encore être déposées ou corrigées après paiement, les juger avant
 * l'échéance fausserait l'analyse. Dates limites et délai : config/anomalies.php (section « echeances »).
 */
class CalendrierFiscal
{
    /**
     * Date limite de paiement d'une période (mois 0 = déclaration annuelle de l'exercice).
     * Null si l'impôt n'a pas de règle dans le calendrier.
     */
    public static function dateLimite(string $impot, int $annee, int $mois): ?DateTimeImmutable
    {
        $regle = config('anomalies.echeances.impots')[$impot] ?? null;

        if (! is_array($regle)) {
            return null;
        }

        if (($regle['periodicite'] ?? null) === 'mensuelle') {
            // le 15 du mois suivant la période (TVA, IRSA) ; décembre → janvier de l'année suivante
            $mois = max(1, min(12, $mois));

            return new DateTimeImmutable(sprintf(
                '%04d-%02d-%02d',
                $annee + intdiv($mois, 12),
                $mois % 12 + 1,
                (int) ($regle['jour'] ?? 15)
            ));
        }

        // déclaration annuelle (mois 0) : date fixe de l'année suivant l'exercice (IS : 31 mars, IR : 15 mai)
        return new DateTimeImmutable(sprintf('%04d-%s', $annee + 1, (string) ($regle['date'] ?? '01-01')));
    }

    /**
     * Instant à partir duquel la période peut être jugée : date limite + délai d'analyse (48 h).
     */
    public static function analysableDepuis(string $impot, int $annee, int $mois): ?DateTimeImmutable
    {
        $limite = self::dateLimite($impot, $annee, $mois);

        if ($limite === null) {
            return null;
        }

        return $limite->modify(sprintf('+%d hours', (int) config('anomalies.echeances.delai_analyse_heures', 48)));
    }

    /**
     * La période peut-elle être jugée à la date donnée ?
     * Impôt sans règle dans le calendrier : on garde l'ancien comportement (jugée immédiatement).
     */
    public static function analysable(string $impot, int $annee, int $mois, DateTimeInterface $date): bool
    {
        $depuis = self::analysableDepuis($impot, $annee, $mois);

        return $depuis === null || $date->getTimestamp() >= $depuis->getTimestamp();
    }

    /**
     * Dernière fenêtre d'échéance (date limite + délai) déjà ouverte à la date donnée :
     * sert de marqueur à l'analyse automatique, qui ne rejuge rien entre deux échéances.
     */
    public static function derniereEcheancePassee(DateTimeInterface $date): DateTimeImmutable
    {
        $seuil = null;
        $annee = (int) $date->format('Y');
        $maintenant = (new DateTimeImmutable('@' . $date->getTimestamp()));

        // Fenêtre assez large : périodes des deux derniers exercices couvrent toutes les échéances récentes
        foreach (config('anomalies.echeances.impots', []) as $impot => $regle) {
            $periodes = ($regle['periodicite'] ?? null) === 'mensuelle'
                ? array_map(fn (int $m) => [$annee, $m], range(1, 12))
                : [[$annee, 0], [$annee - 1, 0]];

            foreach ([...$periodes, ...array_map(fn (int $m) => [$annee - 1, $m], range(1, 12))] as [$a, $m]) {
                $depuis = self::analysableDepuis((string) $impot, $a, $m);

                if ($depuis !== null && $depuis <= $maintenant && ($seuil === null || $depuis > $seuil)) {
                    $seuil = $depuis;
                }
            }
        }

        return $seuil ?? $maintenant;
    }
}
