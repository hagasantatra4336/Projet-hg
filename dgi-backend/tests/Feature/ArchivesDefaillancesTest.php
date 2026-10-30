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
 * Archives : les dossiers clôturés (toutes anomalies traitées ou régularisées) quittent la liste
 * de travail et ne sont plus visibles que via le paramètre archive=1.
 */
class ArchivesDefaillancesTest extends TestCase
{
    use RefreshDatabase;

    private Centre $centre;

    private Centre $autreCentre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Centre::create(['nom' => 'Centre archives']);
        $this->autreCentre = Centre::create(['nom' => 'Centre voisin']);
    }

    private function alerte(string $nif, string $nom, int $centreId, array $attributs = []): Alerte
    {
        $contribuable = Contribuable::firstOrCreate(
            ['nif' => $nif],
            ['nom' => $nom, 'centre_id' => $centreId, 'etat' => 1]
        );

        return Alerte::create($attributs + [
            'contribuable_id' => $contribuable->id,
            'regle' => 'r1',
            'type_impot' => 'TVA',
            'annee_fiscale' => 2025,
            'mois' => 0,
            'periodes_manquees' => 0,
            'periodes_declarees' => 0,
            'niveau' => 'eleve',
            'motif' => 'Écart entre le CA IR et le CA TVA.',
            'statut' => Alerte::A_TRAITER,
        ]);
    }

    private function utilisateur(Role $role, Centre $centre, string $email): void
    {
        $fonction = Fonction::create(['nom' => 'Fonction '.$role->value.' '.$email, 'role' => $role]);

        Sanctum::actingAs(User::create([
            'nom' => 'Utilisateur '.$email,
            'email' => $email,
            'password' => 'motdepasse123',
            'centre_id' => $centre->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]));
    }

    /** Dossier clôturé traité, dossier clôturé régularisé, dossier mixte (une alerte encore ouverte). */
    private function chargerJeuxDeDonnees(): void
    {
        $this->alerte('1111111111', 'Dossier traité', $this->centre->id, ['statut' => Alerte::TRAITEE]);
        $this->alerte('2222222222', 'Dossier régularisé', $this->centre->id, ['statut' => Alerte::REGULARISEE]);
        $mixte = $this->alerte('3333333333', 'Dossier mixte', $this->centre->id);
        $this->alerte('3333333333', 'Dossier mixte', $this->centre->id, ['statut' => Alerte::TRAITEE, 'regle' => 'r3']);
        $this->alerte('4444444444', 'Dossier ouvert', $this->centre->id);

        $this->assertSame(Alerte::A_TRAITER, $mixte->refresh()->statut);
    }

    public function test_la_liste_de_travail_exclut_les_dossiers_clotures_meme_tous_statuts(): void
    {
        $this->utilisateur(Role::Superadmin, $this->centre, 'super@archives.test');
        $this->chargerJeuxDeDonnees();

        // Sans filtre statut (« Tous les statuts ») : les clôturés sont absents.
        $res = $this->getJson('/api/defaillances?grouper=1');
        $res->assertOk();
        $noms = collect($res->json('data'))->pluck('contribuable.nom')->all();
        sort($noms);
        $this->assertSame(['Dossier mixte', 'Dossier ouvert'], $noms);

        // Le filtre « À traiter » (défaut de l'écran) donne le même résultat.
        $nomsATraiter = collect($this->getJson('/api/defaillances?grouper=1&statut='.Alerte::A_TRAITER)->json('data'))->pluck('contribuable.nom')->all();
        $this->assertNotContains('Dossier traité', $nomsATraiter);
    }

    public function test_le_parametre_archive_ne_retourne_que_les_dossiers_clotures(): void
    {
        $this->utilisateur(Role::Superadmin, $this->centre, 'super2@archives.test');
        $this->chargerJeuxDeDonnees();

        $res = $this->getJson('/api/defaillances?grouper=1&archive=1');
        $res->assertOk();

        // Le dossier mixte (une anomalie encore ouverte) n'est PAS archivé.
        $noms = collect($res->json('data'))->pluck('contribuable.nom')->all();
        sort($noms);
        $this->assertSame(['Dossier régularisé', 'Dossier traité'], $noms);

        // Les filtres classiques s'appliquent aussi aux archives.
        $filtre = collect($this->getJson('/api/defaillances?grouper=1&archive=1&search=régularisé')->json('data'))->pluck('contribuable.nom')->all();
        $this->assertSame(['Dossier régularisé'], $filtre);
    }

    public function test_l_archive_est_limitee_au_centre_de_l_agent(): void
    {
        $this->utilisateur(Role::Agent, $this->centre, 'agent@archives.test');
        $this->alerte('1111111111', 'Dossier lointain', $this->autreCentre->id, ['statut' => Alerte::TRAITEE]);

        $res = $this->getJson('/api/defaillances?grouper=1&archive=1');
        $res->assertOk();
        $this->assertCount(0, $res->json('data'));
    }

    public function test_rouvrir_un_dossier_le_fait_sortir_des_archives(): void
    {
        $this->utilisateur(Role::Superadmin, $this->centre, 'super3@archives.test');
        $alerte = $this->alerte('1111111111', 'Dossier à rouvrir', $this->centre->id, ['statut' => Alerte::TRAITEE]);

        $archivee = collect($this->getJson('/api/defaillances?grouper=1&archive=1')->json('data'))->pluck('contribuable.nom')->all();
        $this->assertSame(['Dossier à rouvrir'], $archivee);

        // Réouverture d'une alerte : le dossier redevient ouvert.
        $this->patchJson('/api/defaillances/'.$alerte->id, ['statut' => Alerte::A_TRAITER])->assertOk();

        $this->assertCount(0, $this->getJson('/api/defaillances?grouper=1&archive=1')->json('data'));
        $noms = collect($this->getJson('/api/defaillances?grouper=1')->json('data'))->pluck('contribuable.nom')->all();
        $this->assertSame(['Dossier à rouvrir'], $noms);
    }
}
