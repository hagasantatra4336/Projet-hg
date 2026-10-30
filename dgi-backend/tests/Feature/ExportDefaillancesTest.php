<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\Fonction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Export CSV de la liste des anomalies et filtre d'ancienneté des dossiers.
 */
class ExportDefaillancesTest extends TestCase
{
    use RefreshDatabase;

    private Centre $centre;

    private Centre $autreCentre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Centre::create(['nom' => 'Centre export']);
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

    private function utilisateur(Role $role, Centre $centre, string $email): User
    {
        $fonction = Fonction::create(['nom' => 'Fonction '.$role->value.' '.$email, 'role' => $role]);

        $user = User::create([
            'nom' => 'Utilisateur '.$email,
            'email' => $email,
            'password' => 'motdepasse123',
            'centre_id' => $centre->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_l_export_contient_une_ligne_par_dossier_avec_les_libelles(): void
    {
        $this->utilisateur(Role::Superadmin, $this->centre, 'super@export.test');

        // Deux anomalies du même contribuable : un seul dossier dans l'export.
        $premiere = $this->alerte('1111111111', 'Société export', $this->centre->id);
        $this->alerte('1111111111', 'Société export', $this->centre->id, ['regle' => 'r3', 'niveau' => 'moyen']);
        $this->alerte('2222222222', 'Société voisine', $this->autreCentre->id);

        $res = $this->get('/api/defaillances/export?annee_fiscale=2025');
        $res->assertOk();

        $contenu = $res->streamedContent();
        $lignes = array_values(array_filter(explode("\r\n", $contenu)));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenu, 'Le fichier doit commencer par le BOM UTF-8.');
        $this->assertStringContainsString('NIF;Contribuable;Centre;Niveau', $lignes[0]);
        $this->assertCount(3, $lignes, 'En-tête + deux dossiers attendus.');
        $this->assertStringContainsString('1111111111;Société export;Centre export;Élevé', $lignes[1]);
        $this->assertStringContainsString('R1, R3', $lignes[1]);
        $this->assertStringContainsString('2', $lignes[1]);
        $this->assertStringContainsString('À traiter', $lignes[1]);
        $this->assertStringContainsString('2222222222;Société voisine;Centre voisin', $lignes[2]);
        $this->assertNotNull($premiere->created_at);
    }

    public function test_l_export_est_limite_au_centre_de_l_agent(): void
    {
        $this->utilisateur(Role::Agent, $this->centre, 'agent@export.test');
        $this->alerte('1111111111', 'Société export', $this->centre->id, ['annee_fiscale' => 2024]);
        $this->alerte('2222222222', 'Société voisine', $this->autreCentre->id, ['annee_fiscale' => 2024]);

        $contenu = $this->get('/api/defaillances/export')->streamedContent();

        $this->assertStringContainsString('Société export', $contenu);
        $this->assertStringNotContainsString('Société voisine', $contenu);
    }

    public function test_le_filtre_d_anciennete_ne_garde_que_les_dossiers_recents(): void
    {
        $this->utilisateur(Role::Superadmin, $this->centre, 'super2@export.test');

        $ancienne = $this->alerte('1111111111', 'Dossier ancien', $this->centre->id);
        $recente = $this->alerte('2222222222', 'Dossier récent', $this->centre->id);
        // Dossier mixte : une anomalie ancienne + une récente. Son âge affiché est celui de la plus ancienne,
        // il ne doit donc pas passer pour un dossier récent.
        $mixteAncienne = $this->alerte('3333333333', 'Dossier mixte', $this->centre->id);
        $this->alerte('3333333333', 'Dossier mixte', $this->centre->id, ['regle' => 'r3']);

        DB::table('alertes')->where('id', $ancienne->id)->update(['created_at' => now()->subDays(45)]);
        DB::table('alertes')->where('id', $recente->id)->update(['created_at' => now()->subDay()]);
        DB::table('alertes')->where('id', $mixteAncienne->id)->update(['created_at' => now()->subDays(45)]);

        $res = $this->getJson('/api/defaillances?grouper=1&age_min=30');
        $res->assertOk();

        $noms = collect($res->json('data'))->pluck('contribuable.nom')->all();
        $this->assertSame(['Dossier récent'], $noms);

        // Sans le filtre, les trois dossiers sont présents ; l'âge est exposé pour l'affichage.
        $tous = collect($this->getJson('/api/defaillances?grouper=1')->json('data'));
        $this->assertCount(3, $tous);
        $this->assertNotNull($tous->firstWhere('contribuable.nom', 'Dossier ancien')['cree_le']);

        // Même règle dans l'export CSV.
        $contenu = $this->get('/api/defaillances/export?age_min=30')->streamedContent();
        $this->assertStringContainsString('Dossier récent', $contenu);
        $this->assertStringNotContainsString('Dossier ancien', $contenu);
        $this->assertStringNotContainsString('Dossier mixte', $contenu);
    }
}
