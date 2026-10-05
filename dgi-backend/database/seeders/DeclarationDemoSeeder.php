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
        $maintenant = now()->toDateTimeString();
        $lignes = [];

        for ($i = 1; $i <= 30; $i++) {
            $contribuable = Contribuable::firstOrCreate(
                ['nif' => sprintf('NIF-DEMO-%03d', $i)],
                ['nom' => sprintf('Contribuable anonyme %03d', $i), 'centre_id' => $centres[($i - 1) % count($centres)]]
            );

            [$manques, $serie] = $scenarios[($i - 1) % 6];
            $base = mt_rand(2_000_000, 40_000_000);

            // TVA : $serie mois déclarés, précédés de $manques mois sans déclaration jusqu'à la période de référence
            for ($k = $manques; $k < $manques + $serie; $k++) {
                $lignes[] = $this->ligne($contribuable->id, 'TVA', $ref->copy()->subMonthsNoOverflow($k), $base, $maintenant);
            }

            // IRSA régulier pour un contribuable sur trois
            if ($i % 3 === 0) {
                for ($k = 0; $k < 12; $k++) {
                    $lignes[] = $this->ligne($contribuable->id, 'IRSA', $ref->copy()->subMonthsNoOverflow($k), (int) ($base / 4), $maintenant);
                }
            }
        }

        foreach (array_chunk($lignes, 500) as $lot) {
            Declaration::upsert($lot, ['contribuable_id', 'type_impot', 'periode'], ['montant', 'date_depot', 'updated_at']);
        }

        $this->command?->info(count($lignes) . ' déclarations de test créées. Lancez maintenant l\'analyse (période de référence : ' . $ref->format('Y-m') . ').');
    }

    /** @return array<string, mixed> */
    private function ligne(int $contribuableId, string $impot, \Carbon\Carbon $mois, int $base, string $now): array
    {
        return [
            'contribuable_id' => $contribuableId,
            'type_impot' => $impot,
            'periode' => $mois->format('Y-m'),
            'montant' => round($base * mt_rand(85, 115) / 100, 2),
            'date_depot' => $mois->copy()->addMonthNoOverflow()->day(mt_rand(5, 15))->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
