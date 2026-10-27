<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\Fonction;
use App\Models\User;
use App\Services\AnomalieAnalyseur;
use App\Services\CalculRisque;
use App\Support\Nif;
use Database\Seeders\DeclarationDemoSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regroupement des anomalies par contribuable (dossier unique) et exemples R1, R2, R3, R5, R6, R7
 * fournis par AnomaliesRisqueDemoSeeder.
 */
class RegroupementAnomaliesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Centre::create(['nom' => 'Centre de test']);
        $this->seed(DeclarationDemoSeeder::class);
        app(AnomalieAnalyseur::class)->analyser(new DateTimeImmutable(now()->toDateString()));
    }

    private function contribuable(int $numero): Contribuable
    {
        $nif = Nif::normaliser('90000' . sprintf('%05d', $numero));

        return Contribuable::where('nif', $nif)->firstOrFail();
    }

    private function connecter(): User
    {
        $fonction = Fonction::create(['nom' => 'Super administrateur test', 'role' => Role::Superadmin]);
        $user = User::create([
            'nom' => 'Super Test', 'email' => 'super@test.local', 'password' => 'motdepasse123',
            'fonction_id' => $fonction->id, 'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_les_exemples_declenchent_les_six_criteres_de_risque(): void
    {
        $attendus = ['r1' => 1, 'r2' => 6, 'r3' => 7, 'r5' => 13, 'r6' => 19, 'r7' => 25];

        foreach ($attendus as $regle => $numero) {
            $alertes = Alerte::where('contribuable_id', $this->contribuable($numero)->id)
                ->where('regle', $regle)->get();

            $this->assertTrue($alertes->isNotEmpty(), "Aucune alerte {$regle} pour le contribuable {$numero}.");
            $this->assertTrue($alertes->contains(fn (Alerte $a) => $a->niveau === 'eleve'), "Aucune alerte {$regle} rouge.");
        }

        // Chaque exemple ne déclenche que le critère attendu (pas de faux positif sur les autres règles).
        foreach ($attendus as $regle => $numero) {
            $regles = Alerte::where('contribuable_id', $this->contribuable($numero)->id)
                ->whereIn('regle', CalculRisque::CODES)->distinct()->pluck('regle')->sort()->values()->all();
            $this->assertSame([$regle], $regles, "Le contribuable {$numero} déclenche aussi : " . implode(', ', $regles));
        }

        // R2 : le dossier du contribuable 6 contient aussi l'alerte orange de l'exercice précédent.
        $r2 = Alerte::where('contribuable_id', $this->contribuable(6)->id)->where('regle', 'r2')->get();
        $this->assertGreaterThanOrEqual(2, $r2->count());
    }

    public function test_le_summary_distingue_alertes_et_dossiers_a_traiter(): void
    {
        $this->connecter();

        $libres = Alerte::where('statut', Alerte::A_TRAITER)->whereNull('pris_par');
        $alertes = (clone $libres)->count();
        $dossiers = (clone $libres)->distinct()->count('contribuable_id');

        $res = $this->getJson('/api/defaillances/summary');
        $res->assertOk();
        $this->assertSame($alertes, $res->json('a_traiter'));
        $this->assertSame($dossiers, $res->json('dossiers_a_traiter'));

        // La liste étant groupée par contribuable, elle compte bien des dossiers.
        $this->assertSame($dossiers, $this->getJson('/api/defaillances?grouper=1')->json('total'));

        // Un dossier pris sort des « à traiter » et entre dans « en traitement ».
        $alerte = (clone $libres)->firstOrFail();
        $this->postJson("/api/defaillances/{$alerte->id}/prendre", ['groupe' => true])->assertOk();

        $res = $this->getJson('/api/defaillances/summary');
        $this->assertSame($dossiers - 1, $res->json('dossiers_a_traiter'));
        $this->assertSame(1, $res->json('dossiers_en_traitement'));
    }

    public function test_la_liste_regroupe_les_anomalies_dun_contribuable(): void
    {
        $this->connecter();
        $c = $this->contribuable(1);

        $res = $this->getJson('/api/defaillances?grouper=1&search=' . $c->nif);
        $res->assertOk();

        $groupes = collect($res->json('data'));
        $this->assertCount(1, $groupes);

        $groupe = $groupes->first();
        $this->assertSame($c->id, $groupe['contribuable']['id']);
        $this->assertSame(Alerte::where('contribuable_id', $c->id)->count(), $groupe['nb_alertes']);
        $this->assertContains('r1', $groupe['regles']);
        $this->assertNotEmpty($groupe['alertes']);
        $this->assertNotNull($groupe['representant_id']);
    }

    public function test_le_dossier_contribuable_et_les_actions_portent_sur_toutes_les_anomalies(): void
    {
        $user = $this->connecter();
        $c = $this->contribuable(1);
        $alerte = Alerte::where('contribuable_id', $c->id)->where('regle', 'r1')->firstOrFail();
        $nb = Alerte::where('contribuable_id', $c->id)->count();

        // Le dossier expose toutes les anomalies et la formule de chaque règle présente.
        $res = $this->getJson("/api/defaillances/{$alerte->id}/dossier");
        $res->assertOk();
        $this->assertSame($nb, $res->json('groupe.nb_alertes'));
        $this->assertNotNull($res->json('formules.r1'));

        // Prendre le dossier : toutes les anomalies passent « en traitement ».
        $this->postJson("/api/defaillances/{$alerte->id}/prendre", ['groupe' => true])->assertOk();
        $this->assertSame(0, Alerte::where('contribuable_id', $c->id)->whereNull('pris_par')->count());
        $this->assertSame($nb, Alerte::where('contribuable_id', $c->id)->where('pris_par', $user->id)->count());

        // Traiter le dossier : toutes les anomalies sont traitées et le verrou est levé.
        $this->patchJson("/api/defaillances/{$alerte->id}", [
            'groupe' => true, 'statut' => 'traitee', 'commentaire' => 'Dossier traité en test',
        ])->assertOk();

        $this->assertSame($nb, Alerte::where('contribuable_id', $c->id)->where('statut', 'traitee')->count());
        $this->assertSame(0, Alerte::where('contribuable_id', $c->id)->whereNotNull('pris_par')->count());
        $this->assertSame($nb, Alerte::where('contribuable_id', $c->id)->where('commentaire', 'Dossier traité en test')->count());
    }
}
