<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\DossierHistorique;
use App\Models\Fonction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tableau de bord : chiffres clés, répartition mensuelle, clôtures récentes et historique personnel.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Centre $centre;

    private User $agent;

    private User $chef;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Centre::create(['nom' => 'Centre dashboard']);
        $this->agent = $this->utilisateur(Role::Agent, $this->centre, 'agent@dashboard.test', 'Agent Test');
        $this->chef = $this->utilisateur(Role::Admin, $this->centre, 'chef@dashboard.test', 'Chef Test');
    }

    private function utilisateur(Role $role, Centre $centre, string $email, string $nom): User
    {
        $fonction = Fonction::create(['nom' => 'Fonction '.$role->value.' '.$email, 'role' => $role]);

        return User::create([
            'nom' => $nom,
            'email' => $email,
            'password' => 'motdepasse123',
            'centre_id' => $centre->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);
    }

    private function alerte(string $nif, int $centreId, array $attributs = []): Alerte
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

    private function journal(Alerte $alerte, User $auteur, string $action = DossierHistorique::CLOTURE): DossierHistorique
    {
        return DossierHistorique::create([
            'contribuable_id' => $alerte->contribuable_id,
            'alerte_id' => $alerte->id,
            'user_id' => $auteur->id,
            'auteur' => $auteur->nom,
            'action' => $action,
            'statut_avant' => 'a_traiter',
            'statut_apres' => 'traitee',
        ]);
    }

    public function test_un_agent_voit_ses_dossiers_ses_clotures_et_son_historique(): void
    {
        // Dossier clôturé par l'agent.
        $clos = $this->alerte('1111111111', $this->centre->id, [
            'statut' => Alerte::TRAITEE,
            'traite_par' => $this->agent->id,
            'traite_le' => now()->subDay(),
        ]);
        $this->journal($clos, $this->agent);

        // Dossier mensuel pris par l'agent (avril) : reste « en traitement ».
        $this->alerte('2222222222', $this->centre->id, [
            'mois' => 4,
            'date_limite' => null,
            'pris_par' => $this->agent->id,
            'pris_le' => now(),
        ]);

        // Dossier libre d'un autre agent : hors périmètre de l'agent.
        $this->alerte('3333333333', $this->centre->id, ['annee_fiscale' => 2025]);

        // Action d'un autre utilisateur : ne doit pas apparaître dans « Mon historique ».
        $this->journal($clos, $this->chef, DossierHistorique::PRISE);

        Sanctum::actingAs($this->agent);

        $res = $this->getJson('/api/dashboard');
        $res->assertOk();

        // Un agent n'a pas accès aux totaux globaux.
        $this->assertNull($res->json('totaux.centres'));
        $this->assertNull($res->json('totaux.utilisateurs'));

        // Une anomalie clôturée, une prise (donc « en traitement »), aucune libre.
        $this->assertSame(1, $res->json('totaux.anomalies_traitees'));
        $this->assertSame(1, $res->json('totaux.anomalies_en_traitement'));
        $this->assertSame(0, $res->json('totaux.anomalies_a_traiter'));
        $this->assertSame(2, $res->json('totaux.anomalies_total'));

        // Répartition mensuelle : l'annuelle est rattachée à son échéance (mars), la TVA à avril.
        $this->assertSame([2026], $res->json('anomalies_par_mois.annees'));
        $series = collect($res->json('anomalies_par_mois.series'))->keyBy('mois');
        $this->assertSame(1, $series[3]['total']);
        $this->assertSame(1, $series[4]['total']);

        // Clôtureurs : l'agent, avec son dossier.
        $this->assertSame('Agent Test', $res->json('clotureurs.0.nom'));
        $this->assertSame(1, $res->json('clotureurs.0.dossiers'));

        // Derniers dossiers clôturés et historique personnel.
        $this->assertSame('1111111111', $res->json('derniers_dossiers.0.contribuable.nif'));
        $this->assertSame('cloture', $res->json('historique.0.action'));
        $this->assertCount(1, $res->json('historique'));
    }

    public function test_un_chef_de_centre_est_limite_a_son_centre(): void
    {
        $autre = Centre::create(['nom' => 'Centre lointain']);

        $this->alerte('4444444444', $this->centre->id);
        $this->alerte('5555555555', $autre->id);
        $this->alerte('6666666666', $autre->id, ['annee_fiscale' => 2025]);

        Sanctum::actingAs($this->chef);

        $res = $this->getJson('/api/dashboard');
        $res->assertOk();

        $this->assertSame(1, $res->json('totaux.anomalies_total'));
        $this->assertSame(1, $res->json('totaux.anomalies_a_traiter'));
        $this->assertSame(1, $res->json('totaux.centres'));

        // Le chef ne compte que les agents de son centre (lui-même est admin, non compté).
        $this->assertSame(1, $res->json('totaux.utilisateurs'));

        // Aucune répartition hors de son centre.
        $series = collect($res->json('anomalies_par_mois.series'));
        $this->assertSame(1, $series->sum('total'));
    }

    public function test_un_compte_sans_fonction_est_refuse(): void
    {
        $sansFonction = User::create([
            'nom' => 'Sans fonction',
            'email' => 'sans@dashboard.test',
            'password' => 'motdepasse123',
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($sansFonction);

        $this->getJson('/api/dashboard')->assertForbidden();
    }
}
