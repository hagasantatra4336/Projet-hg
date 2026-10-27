<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\DemandeInscription;
use App\Models\Fonction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Compteurs de notification de la navigation : dossiers à traiter, dossiers en cours, demandes à approuver. */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Centre $centreA;

    private Centre $centreB;

    private Fonction $fonctionAgent;

    private Fonction $fonctionAdmin;

    private Fonction $fonctionCentral;

    private Fonction $fonctionSuperadmin;

    private static int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centreA = Centre::create(['nom' => 'Centre notification A']);
        $this->centreB = Centre::create(['nom' => 'Centre notification B']);

        $this->fonctionAgent = Fonction::create(['nom' => 'Agent notification', 'role' => Role::Agent]);
        $this->fonctionAdmin = Fonction::create(['nom' => 'Chef notification', 'role' => Role::Admin]);
        $this->fonctionCentral = Fonction::create(['nom' => 'Central notification', 'role' => Role::Central]);
        $this->fonctionSuperadmin = Fonction::create(['nom' => 'Super notification', 'role' => Role::Superadmin]);
    }

    private function utilisateur(Fonction $fonction, ?Centre $centre): User
    {
        self::$compteur++;

        return User::create([
            'nom' => 'Utilisateur ' . self::$compteur,
            'email' => 'notif' . self::$compteur . '@test.local',
            'password' => 'motdepasse123',
            'centre_id' => $centre?->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);
    }

    private function contribuable(Centre $centre, string $nif): Contribuable
    {
        return Contribuable::create([
            'nif' => $nif,
            'nom' => 'Société ' . $nif,
            'centre_id' => $centre->id,
            'etat' => 1,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function alerte(Contribuable $c, array $attrs = []): Alerte
    {
        return Alerte::create($attrs + [
            'contribuable_id' => $c->id,
            'regle' => 'r1',
            'type_impot' => 'TVA',
            'annee_fiscale' => 2026,
            'mois' => 0,
            'periodes_manquees' => 0,
            'periodes_declarees' => 0,
            'niveau' => 'eleve',
            'motif' => 'Anomalie de test.',
            'statut' => Alerte::A_TRAITER,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function demande(Centre $centre, Fonction $fonction, array $attrs = []): DemandeInscription
    {
        self::$compteur++;

        return DemandeInscription::create($attrs + [
            'request_id' => str_pad((string) self::$compteur, 48, 'x', STR_PAD_LEFT),
            'nom' => 'Demandeur ' . self::$compteur,
            'email' => 'demandeur' . self::$compteur . '@test.local',
            'centre_id' => $centre->id,
            'fonction_id' => $fonction->id,
            'statut' => DemandeInscription::EN_ATTENTE,
            'niveau_approbation' => DemandeInscription::NIVEAU_CHEF,
            'expires_at' => now()->addDays(3),
        ]);
    }

    public function test_les_dossiers_suivent_le_centre_et_le_verrou(): void
    {
        $agentA = $this->utilisateur($this->fonctionAgent, $this->centreA);
        $agentB = $this->utilisateur($this->fonctionAgent, $this->centreB);

        $c1 = $this->contribuable($this->centreA, '2000000001');
        $c2 = $this->contribuable($this->centreA, '2000000002');
        $c3 = $this->contribuable($this->centreB, '2000000003');

        $this->alerte($c1);
        $this->alerte($c1, ['annee_fiscale' => 2025]); // deuxième alerte du même dossier : un seul dossier à ouvrir
        $this->alerte($c2, ['pris_par' => $agentA->id, 'pris_le' => now()]); // dossier pris par l'agent A
        $this->alerte($c3);
        $this->alerte($c3, ['annee_fiscale' => 2025, 'statut' => Alerte::TRAITEE, 'traite_le' => now()]);

        Sanctum::actingAs($agentA);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('defaillances', 1)
            ->assertJsonPath('traitements', 1)
            ->assertJsonPath('demandes', 0)
            ->assertJsonPath('total', 2);

        Sanctum::actingAs($agentB);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('defaillances', 1)
            ->assertJsonPath('traitements', 0);

        // Un dossier pris par un agent n'est plus « à traiter » pour les autres, mais reste en cours pour lui.
        $central = $this->utilisateur($this->fonctionCentral, null);
        Sanctum::actingAs($central);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('defaillances', 2)
            ->assertJsonPath('traitements', 0);
    }

    public function test_les_demandes_ne_comptent_que_celles_que_le_role_peut_approuver(): void
    {
        $chefA = $this->utilisateur($this->fonctionAdmin, $this->centreA);
        $chefB = $this->utilisateur($this->fonctionAdmin, $this->centreB);
        $central = $this->utilisateur($this->fonctionCentral, null);
        $superadmin = $this->utilisateur($this->fonctionSuperadmin, null);

        $this->demande($this->centreA, $this->fonctionAgent);
        $this->demande($this->centreA, $this->fonctionAdmin);
        $this->demande($this->centreB, $this->fonctionAgent);
        $this->demande($this->centreA, $this->fonctionCentral);
        // Ni les demandes expirées ni celles déjà traitées ne comptent
        $this->demande($this->centreA, $this->fonctionAgent, ['expires_at' => now()->subHour()]);
        $this->demande($this->centreA, $this->fonctionAgent, ['statut' => DemandeInscription::APPROUVEE]);

        Sanctum::actingAs($chefA);
        $this->getJson('/api/notifications')->assertJsonPath('demandes', 1);

        Sanctum::actingAs($chefB);
        $this->getJson('/api/notifications')->assertJsonPath('demandes', 1);

        // Le central approuve les agents et les chefs de centre, pas les fonctions central.
        Sanctum::actingAs($central);
        $this->getJson('/api/notifications')->assertJsonPath('demandes', 3);

        // Le superadmin voit aussi la demande de fonction central.
        Sanctum::actingAs($superadmin);
        $this->getJson('/api/notifications')->assertJsonPath('demandes', 4);
    }

    public function test_les_notifications_exigent_une_session(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }
}
