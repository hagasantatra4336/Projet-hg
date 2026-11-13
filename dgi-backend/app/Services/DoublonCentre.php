<?php

namespace App\Services;

use App\Models\Centre;
use App\Support\Noms;

/**
 * Empêche d'enregistrer deux centres qui désignent la même entité, même écrits différemment.
 *
 * Exemples reconnus comme un seul et même centre :
 *   « DGE » ≡ « Direction des Grandes Entreprises (DGE) »
 *   « DGE » ≡ « DIRECTION DES GRANDES ENTREPRISES » (sigle = initiales du nom)
 *   « Direction Générale des Impôts » ≡ « DIRECTION GENERALE DES IMPOTS (DGI) »
 *   « CF TANA I » ≡ « CENTRE FISCAL ANTANANARIVO I » (via la colonne « abreviation »)
 *
 * La comparaison ignore les accents, la casse, les espaces, la ponctuation et les mots
 * vides (de, des, la...). Un sigle est rapproché du nom complet qui le contient ou dont
 * il constitue les initiales. Deux noms qui ne diffèrent que par un suffixe (TANA I /
 * TANA II, MAHAJANGA A / MAHAJANGA B) restent distincts.
 */
class DoublonCentre
{
    /**
     * Retourne le centre existant qui correspond déjà à ce nom, ou null.
     *
     * @param string      $nom         nom saisi (peut contenir le sigle entre parenthèses)
     * @param string|null $abreviation abréviation connue, si l'appelant en fournit une
     * @param int|null    $ignorerId   centre à exclure de la comparaison (modification)
     */
    public static function trouver(string $nom, ?string $abreviation = null, ?int $ignorerId = null): ?Centre
    {
        $candidat = self::signature($nom, $abreviation);
        if ($candidat === null) {
            return null;
        }

        foreach (Centre::query()->get(['id', 'nom', 'abreviation']) as $centre) {
            if ($ignorerId !== null && (int) $centre->id === $ignorerId) {
                continue;
            }

            $existant = self::signature($centre->nom, $centre->abreviation);
            if ($existant !== null && self::correspond($candidat, $existant)) {
                return $centre;
            }
        }

        return null;
    }

    /**
     * Signature comparable d'un nom de centre.
     *
     * @return array{complet: string, base: string, mots: string[], sigles: string[], initiales: string|null}|null
     */
    private static function signature(string $nom, ?string $abreviation): ?array
    {
        $complet = Noms::normaliser($nom);
        if ($complet === '') {
            return null;
        }

        $sigles = [];
        foreach ([
            Noms::sigleParentheses($nom),
            $abreviation !== null ? Noms::normaliser($abreviation) : null,
        ] as $sigle) {
            if ($sigle !== null && $sigle !== '') {
                $sigles[$sigle] = true;
            }
        }

        return [
            'complet' => $complet,
            'base' => Noms::normaliser(Noms::sansParentheses($nom)),
            'mots' => Noms::mots($nom),
            'sigles' => array_keys($sigles),
            'initiales' => Noms::initiales($nom),
        ];
    }

    /**
     * @param array{complet: string, base: string, mots: string[], sigles: string[], initiales: string|null} $a
     * @param array{complet: string, base: string, mots: string[], sigles: string[], initiales: string|null} $b
     */
    private static function correspond(array $a, array $b): bool
    {
        // Écriture identique (accents/casse/ponctuation mis à part).
        if ($a['complet'] === $b['complet']) {
            return true;
        }

        // Même nom de base, même si l'un précise un sigle entre parenthèses.
        if ($a['base'] !== '' && $a['base'] === $b['base']) {
            return true;
        }

        // Mêmes mots significatifs.
        if ($a['mots'] !== [] && $a['mots'] === $b['mots']) {
            return true;
        }

        // Sigle explicite (parenthèses ou abréviation) d'un côté = sigle, nom, base ou mot de l'autre.
        foreach ($a['sigles'] as $sigle) {
            if (self::sigleCorrespond($sigle, $b)) {
                return true;
            }
        }
        foreach ($b['sigles'] as $sigle) {
            if (self::sigleCorrespond($sigle, $a)) {
                return true;
            }
        }

        // Sigle seul (« DGE ») face au nom complet dont il est le sigle ou les initiales.
        foreach ([[$a, $b], [$b, $a]] as [$sigle, $nomComplet]) {
            if (count($sigle['mots']) === 1 && Noms::estSigle($sigle['mots'][0])) {
                $mot = $sigle['mots'][0];

                if (
                    in_array($mot, $nomComplet['sigles'], true)
                    || $mot === $nomComplet['initiales']
                    || $mot === $nomComplet['base']
                    || $mot === $nomComplet['complet']
                ) {
                    return true;
                }
            }
        }

        // Tous les mots de l'un figurent dans l'autre (au moins deux mots côté le plus court,
        // pour ne pas confondre un mot générique avec un centre précis).
        foreach ([[$a, $b], [$b, $a]] as [$petit, $grand]) {
            if (
                count($petit['mots']) >= 2
                && count($petit['mots']) < count($grand['mots'])
                && array_diff($petit['mots'], $grand['mots']) === []
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{complet: string, base: string, mots: string[], sigles: string[], initiales: string|null} $signature
     */
    private static function sigleCorrespond(string $sigle, array $signature): bool
    {
        if (in_array($sigle, $signature['sigles'], true)) {
            return true;
        }

        if ($sigle === $signature['base'] || $sigle === $signature['complet']) {
            return true;
        }

        return false;
    }
}
