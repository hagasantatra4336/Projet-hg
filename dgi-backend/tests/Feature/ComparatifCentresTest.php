<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\Fonction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Comparatif des centres : agrégats par centre, filtres et contrôle d'accès.
 */
class ComparatifCentresTest extends TestCase
{
    use RefreshDatabase;

    private Centre $premier;

    private Centre $second;

    private User $central;

    protected function setUp(): void
    {
        parent::setUp();

        $this->premier = Centre::create(['nom' => 'Centre Alpha', 'abreviation' => 'CA']);
        $this->second = Centre::create(['nom' => 'Centre Beta', 'abreviation' => 'CB']);
        $this->central = $this->utilisateur(Role::Central, null, 'central@comparatif.test', 'Agent Central');
    }

    private function utilisateur(Role $role, ?Centre $centre, string $email, string $nom): User
    {
        $fonction = Fonction::create(['nom' => 'Fonction '.$role->value.' '.$email, 'role' => $role]);

        return User::create([
            'nom' => $nom,
            'email' => $email,
            'password' => 'motdepasse123',
            'centre_id' => $centre?->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);
    }

    private function alerte(string $nif, ?int $centreId, array $attributs = []): Alerte
    {
        $contribuable = Contribuable::firstOrCreate(
            ['nif' => $nif],
            ['nom' => 'Société '.$nif, 'centre_id' => $centreId, 'etat' => 1]
        );

        return Alerte::create($attributs + [
            'contribuable_id' => $contribuable->id,
            'regle' => 'defaillance',
            'type_impot' => 'TVA',
            'annee_fiscale' => 2026,
            'mois' => 0,
            'date_limite' => '2026-03-15',
            'periodes_manquees' => 1,
            'periodes_declarees' => 0,
            'niveau' => 'eleve',
            'motif' => 'Défaut de dépôt.',
            'statut' => Alerte::A_TRAITER,
        ]);
    }

    /** Fixe la date de création d'une alerte (non fillable). */
    private function creerLe(Alerte $alerte, int $jours): Alerte
    {
        $alerte->forceFill(['created_at' => now()->subDays($jours), 'updated_at' => now()->subDays($jours)])->save();

        return $alerte->refresh();
    }

    private function ligne(array $centres, ?int $id): ?array
    {
        foreach ($centres as $centre) {
            if ($centre['id'] === $id) {
                return $centre;
            }
        }

        return null;
    }

    public function test_le_central_compare_les_centres_avec_taux_et_delais(): void
    {
        // Centre Alpha : un dossier clôturé en 8 jours, un dossier ouvert depuis 40 jours (en retard).
        $clos = $this->creerLe($this->alerte('1111111111', $this->premier->id, [
            'statut' => Alerte::TRAITEE,
            'pris_le' => now()->subDays(8),
            'traite_le' => now()->subDays(2),
        ]), 10);

        $this->creerLe($this->alerte('2222222222', $this->premier->id), 40);

        // Centre Beta : un dossier récent, encore ouvert.
        $this->creerLe($this->alerte('3333333333', $this->second->id), 5);

        Sanctum::actingAs($this->central);

        $res = $this->getJson('/api/statistiques/centres');
        $res->assertOk();

        $this->assertSame(2, $res->json('totaux.centres'));
        $this->assertSame(2, $res->json('totaux.centres_actifs'));
        $this->assertSame(2, $res->json('totaux.dossiers_ouverts'));
        $this->assertSame(1, $res->json('totaux.dossiers_clotures'));
        $this->assertSame(1, $res->json('totaux.dossiers_en_retard'));

        $alpha = $this->ligne($res->json('centres'), $this->premier->id);
        $this->assertNotNull($alpha);
        $this->assertSame('Centre Alpha', $alpha['nom']);
        $this->assertSame(2, $alpha['dossiers_total']);
        $this->assertSame(1, $alpha['dossiers_ouverts']);
        $this->assertSame(1, $alpha['dossiers_clotures']);
        $this->assertSame(1, $alpha['dossiers_en_retard']);
        $this->assertEquals(50.0, $alpha['taux_cloture']);
        $this->assertEqualsWithDelta(8.0, $alpha['delai_moyen_jours'], 0.1);
        $this->assertEqualsWithDelta(2.0, $alpha['attente_moyenne_jours'], 0.1);

        $beta = $this->ligne($res->json('centres'), $this->second->id);
        $this->assertNotNull($beta);
        $this->assertSame(0, $beta['dossiers_en_retard']);
        $this->assertEquals(0.0, $beta['taux_cloture']);

        // La médiane porte sur les centres actifs.
        $this->assertEquals(25.0, $res->json('medianes.taux_cloture'));

        // Le dossier clôturé est bien sorti de « à traiter » : deux dossiers restent libres.
        $this->assertSame(2, $res->json('totaux.anomalies_a_traiter'));
        $this->assertSame(1, $res->json('totaux.anomalies_traitees'));

        // Le filtre par exercice ne retient que les anomalies de l'année demandée.
        $this->assertEquals($clos->annee_fiscale, 2026);
    }

    public function test_le_filtre_par_exercice_et_les_services_centraux(): void
    {
        $this->creerLe($this->alerte('4444444444', $this->premier->id, ['annee_fiscale' => 2025]), 4);
        $this->creerLe($this->alerte('5555555555', $this->premier->id, ['annee_fiscale' => 2026]), 4);

        $service = Centre::create(['nom' => 'Service central', 'type_centre' => Centre::TYPE_CENTRAL]);
        $this->creerLe($this->alerte('6666666666', $service->id), 4);

        Sanctum::actingAs($this->central);

        // Par défaut, le service central n'apparaît pas dans le classement.
        $res = $this->getJson('/api/statistiques/centres');
        $res->assertOk();
        $this->assertSame([2026, 2025], $res->json('annees'));
        $this->assertSame(2, $res->json('totaux.centres'));

        $alpha = $this->ligne($res->json('centres'), $this->premier->id);
        $this->assertSame(2, $alpha['dossiers_total']);

        // Filtre 2025 : un seul dossier.
        $res = $this->getJson('/api/statistiques/centres?annee_fiscale=2025');
        $res->assertOk();
        $alpha = $this->ligne($res->json('centres'), $this->premier->id);
        $this->assertSame(1, $alpha['dossiers_total']);
        $this->assertSame(1, $res->json('totaux.dossiers_ouverts'));

        // Le service central est inclus sur demande explicite (SSIF est créé par migration).
        $res = $this->getJson('/api/statistiques/centres?inclure_centraux=1');
        $res->assertOk();
        $this->assertSame(4, $res->json('totaux.centres'));
        $this->assertNotNull($this->ligne($res->json('centres'), $service->id));
    }

    public function test_les_contribuables_sans_centre_sont_regroupes(): void
    {
        $this->creerLe($this->alerte('7777777777', $this->premier->id), 3);
        $this->creerLe($this->alerte('8888888888', null), 3);

        Sanctum::actingAs($this->central);

        $res = $this->getJson('/api/statistiques/centres');
        $res->assertOk();

        $sansCentre = collect($res->json('centres'))->firstWhere('nom', 'Sans centre');

        $this->assertNotNull($sansCentre);
        $this->assertNull($sansCentre['id']);
        $this->assertSame(1, $sansCentre['dossiers_ouverts']);
        $this->assertSame(3, $res->json('totaux.centres'));
    }

    public function test_un_chef_de_centre_et_un_agent_nont_pas_acces_au_comparatif(): void
    {
        $chef = $this->utilisateur(Role::Admin, $this->premier, 'chef@comparatif.test', 'Chef Alpha');
        $agent = $this->utilisateur(Role::Agent, $this->premier, 'agent@comparatif.test', 'Agent Alpha');

        Sanctum::actingAs($chef);
        $this->getJson('/api/statistiques/centres')->assertForbidden();

        Sanctum::actingAs($agent);
        $this->getJson('/api/statistiques/centres')->assertForbidden();
    }
}
