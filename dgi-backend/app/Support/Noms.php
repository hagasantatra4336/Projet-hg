<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalisation et comparaison de libellés saisis par les utilisateurs (centres, fonctions).
 *
 * Deux écritures sont considérées comme équivalentes quand elles ne diffèrent que par
 * les accents, la casse, les espaces ou la ponctuation. Pour les centres, la comparaison
 * va plus loin : un sigle (« DGE ») est rapproché de son nom complet
 * (« Direction des Grandes Entreprises (DGE) »).
 */
final class Noms
{
    /** Mots sans valeur pour comparer deux libellés. */
    private const MOTS_VIDES = [
        'de', 'du', 'des', 'd', 'la', 'le', 'les', 'l', 'et', 'en', 'au', 'aux',
        'a', 'sur', 'pour', 'the', 'of', 'and',
    ];

    /** Majuscules, sans accents, ponctuation remplacée par des espaces simples. */
    public static function normaliser(string $valeur): string
    {
        $valeur = Str::ascii($valeur); // é → e, ô → o, ç → c, œ → oe...
        $valeur = (string) preg_replace('/[^A-Za-z0-9]+/', ' ', $valeur);
        $valeur = trim((string) preg_replace('/\s+/', ' ', $valeur));

        return mb_strtoupper($valeur);
    }

    /** Partie du libellé avant la parenthèse : « Direction ... (DGE) » → « Direction ... ». */
    public static function sansParentheses(string $valeur): string
    {
        $position = mb_strpos($valeur, '(');

        return $position === false ? $valeur : mb_substr($valeur, 0, $position);
    }

    /**
     * Mots significatifs (mots vides retirés), en majuscules sans accents.
     *
     * @return string[]
     */
    public static function mots(string $valeur): array
    {
        $mots = explode(' ', self::normaliser(self::sansParentheses($valeur)));

        return array_values(array_filter(
            $mots,
            fn (string $mot) => $mot !== '' && ! in_array(mb_strtolower($mot), self::MOTS_VIDES, true),
        ));
    }

    /** Sigle entre parenthèses : « Direction ... (DGE) » → « DGE ». */
    public static function sigleParentheses(string $valeur): ?string
    {
        if (! preg_match('/\(([^)]+)\)/u', $valeur, $correspondance)) {
            return null;
        }

        $sigle = self::normaliser($correspondance[1]);

        return $sigle !== '' ? $sigle : null;
    }

    /**
     * Initiales des mots significatifs : « Direction des Grandes Entreprises » → « DGE ».
     * Retourne null pour un libellé d'un seul mot (évite qu'un simple « D » serve de sigle).
     */
    public static function initiales(string $valeur): ?string
    {
        $mots = self::mots($valeur);
        if (count($mots) < 2) {
            return null;
        }

        $lettres = '';
        foreach ($mots as $mot) {
            $lettres .= mb_substr($mot, 0, 1);
        }

        return $lettres;
    }

    /** Sigle court : de 2 à 6 lettres (« DGE », « SSIF », « TANA »). */
    public static function estSigle(string $valeur): bool
    {
        return (bool) preg_match('/^[A-Z]{2,6}$/', $valeur);
    }
}
