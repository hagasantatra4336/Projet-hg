<?php

namespace Database\Seeders;

use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\Declaration;
use Illuminate\Database\Seeder;

/**
 * Jeu de données de test SIMULÉ ET ANONYMISÉ (aucune donnée réelle) pour démontrer la détection :
 *   php artisan db:seed --class=DeclarationDemoSeeder
 * Il faut au moins un centre. Relançable sans doublons. Ensuite, lancez l'analyse depuis la page
 * « Défaillances » (ou : php artisan dgi:detecter-defaillances).
 *
 * 30 contribuables répartis sur les centres, 6 scénarios de TVA (mois manqués, mois déclarés avant) :
 *   régulier | 1 mois / 8 | 2 mois / 7 | 4 mois / 10 | 3 mois / 3 (pas "habituel") | 1 mois / 12 (très régulier)
 * Un contribuable sur trois dépose aussi l'IRSA chaque mois, sans interruption.
 *
 * Chiffre d'affaires (règle « baisse du CA ») : stable à ± 8 % d'un mois à l'autre, sauf pour 5 contribuables
 * réguliers dont la dernière déclaration baisse de 35 %, 55 %, 75 % (alertes faible / moyen / élevé),
 * puis 20 % et 0 % (sous le seuil de 30 % : aucune alerte).
 */
class DeclarationDemoSeeder extends Seeder
{
    public function run(): void
    {
        $centres = Centre::orderBy('id')->pluck('id')->all();

        if ($centres === []) {
            $this->command?->error("Créez d'abord au moins un centre (Administration > Centres).");

            return;
        }

        mt_srand(42); // résultats reproductibles

        $ref = now()->startOfMonth()->subMonthNoOverflow(); // dernier mois attendu
        $scenarios = [[0, 12], [1, 8], [2, 7], [4, 10], [3, 3], [1, 12]];
        // Baisse du chiffre d'affaires sur la dernière déclaration (en %), par numéro de contribuable
        $baissesCa = [1 => 35, 7 => 55, 13 => 75, 19 => 20, 25 => 0]; // contribuables « réguliers » (scénario 0 mois manqué)
        $maintenant = now()->toDateTimeString();
        $lignes = [];

        for ($i = 1; $i <= 30; $i++) {
            $contribuable = Contribuable::firstOrCreate(
                ['nif' => sprintf('NIF-DEMO-%03d', $i)],
                ['nom' => sprintf('Contribuable anonyme %03d', $i), 'centre_id' => $centres[($i - 1) % count($centres)]]
            );

            [$manques, $serie] = $scenarios[($i - 1) % 6];
            $base = mt_rand(2_000_000, 40_000_000);

            // Chiffre d'affaires mensuel (stable à ± 8 %), du plus récent ($manques) au plus ancien
            $cas = [];
            for ($k = $manques; $k < $manques + $serie; $k++) {
                $cas[$k] = round($base * 5 * mt_rand(92, 108) / 100, 2);
            }
            if (($baissesCa[$i] ?? 0) > 0) {
                // la dernière déclaration baisse de X % par rapport à la précédente
                $cas[$manques] = round($cas[$manques + 1] * (1 - $baissesCa[$i] / 100), 2);
            }

            // TVA : $serie mois déclarés, précédés de $manques mois sans déclaration jusqu'à la période de référence
            for ($k = $manques; $k < $manques + $serie; $k++) {
                $lignes[] = $this->ligne($contribuable->id, 'TVA', $ref->copy()->subMonthsNoOverflow($k), $cas[$k], $maintenant);
            }

            // IRSA régulier pour un contribuable sur trois
            if ($i % 3 === 0) {
                for ($k = 0; $k < 12; $k++) {
                    $lignes[] = $this->ligne($contribuable->id, 'IRSA', $ref->copy()->subMonthsNoOverflow($k), $base * 5 / 4, $maintenant, false);
                }
            }
        }

        foreach (array_chunk($lignes, 500) as $lot) {
            Declaration::upsert($lot, ['contribuable_id', 'type_impot', 'periode'], ['montant', 'chiffre_affaires', 'date_depot', 'updated_at']);
        }

        $this->command?->info(count($lignes) . ' déclarations de test créées. Lancez maintenant l\'analyse (période de référence : ' . $ref->format('Y-m') . ').');
    }

    /**
     * @param float $ca  chiffre d'affaires du mois ; le montant déclaré (TVA, IRSA...) en est déduit (environ 20 %)
     * @param bool $avecCa  false pour l'IRSA (impôt sur salaires : pas de chiffre d'affaires)
     * @return array<string, mixed>
     */
    private function ligne(int $contribuableId, string $impot, \Carbon\Carbon $mois, float $ca, string $now, bool $avecCa = true): array
    {
        return [
            'contribuable_id' => $contribuableId,
            'type_impot' => $impot,
            'periode' => $mois->format('Y-m'),
            'montant' => round($ca * 0.2, 2),
            'chiffre_affaires' => $avecCa ? $ca : null,
            'date_depot' => $mois->copy()->addMonthNoOverflow()->day(mt_rand(5, 15))->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
