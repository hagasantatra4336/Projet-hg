<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Centre;
use App\Models\Fonction;
use App\Models\Indicateur;
use App\Models\Regle;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Catalogue des indicateurs (central / superadmin) : liste, ajout, modification,
 * et protections autour des indicateurs fournis ou déjà utilisés par une règle.
 */
class IndicateursTest extends TestCase
{
    use RefreshDatabase;

    private Centre $centre;

    private Fonction $fonctionCentral;

    private Fonction $fonctionAgent;

    private static int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Centre::create(['nom' => 'Centre indicateurs']);
        $this->fonctionCentral = Fonction::create(['nom' => 'Central indicateurs', 'role' => Role::Central]);
        $this->fonctionAgent = Fonction::create(['nom' => 'Agent indicateurs', 'role' => Role::Agent]);
    }

    private function utilisateur(Fonction $fonction): User
    {
        self::$compteur++;

        return User::create([
            'nom' => 'Utilisateur indicateur ' . self::$compteur,
            'email' => 'indicateur' . self::$compteur . '@test.local',
            'password' => 'motdepasse123',
            'centre_id' => $this->centre->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function indicateur(array $attrs = []): Indicateur
    {
        self::$compteur++;

        return Indicateur::create($attrs + [
            'code' => 'indicateur_' . self::$compteur,
            'libelle' => 'Indicateur ' . self::$compteur,
            'colonne' => 'chiffre_affaires',
            'unite' => 'Ar',
            'impots' => null,
            'actif' => true,
            'systeme' => false,
        ]);
    }

    /** Règle personnalisée qui utilise l'indicateur (le code est référencé dans les conditions). */
    private function regleUtilisant(string $code): Regle
    {
        self::$compteur++;

        return Regle::create([
            'code' => 'perso_' . self::$compteur,
            'nom' => 'Règle personnalisée ' . self::$compteur,
            'type' => Regle::PERSONNALISEE,
            'statut' => Regle::BROUILLON,
            'impots' => ['TVA'],
            'niveau' => 'moyen',
            'conditions' => [
                'operateur' => 'et',
                'conditions' => [
                    ['indicateur' => $code, 'mesure' => 'valeur', 'comparateur' => '>=', 'valeur' => 0],
                ],
            ],
        ]);
    }

    public function test_la_liste_expose_les_colonnes_et_les_impots_disponibles(): void
    {
        Sanctum::actingAs($this->utilisateur($this->fonctionCentral));

        $this->getJson('/api/indicateurs')
            ->assertOk()
            ->assertJsonStructure([
                'indicateurs' => [['id', 'code', 'libelle', 'colonne', 'unite', 'impots', 'actif', 'systeme', 'utilise']],
                'colonnes_disponibles' => [['colonne', 'type']],
                'impots_disponibles',
            ])
            ->assertJsonFragment(['colonne' => 'chiffre_affaires'])
            ->assertJsonFragment(['impots_disponibles' => array_values((array) config('anomalies.defaillance.impots'))]);
    }

    public function test_un_central_peut_ajouter_un_indicateur(): void
    {
        Sanctum::actingAs($this->utilisateur($this->fonctionCentral));

        $this->postJson('/api/indicateurs', [
            'code' => 'ca_declare',
            'libelle' => "Chiffre d'affaires déclaré",
            'description' => 'Ligne 15 de la déclaration.',
            'colonne' => 'chiffre_affaires',
            'unite' => 'Ar',
            'impots' => ['TVA', 'IR'],
        ])->assertCreated()->assertJsonPath('code', 'ca_declare');

        $this->assertDatabaseHas('indicateurs', [
            'code' => 'ca_declare',
            'libelle' => "Chiffre d'affaires déclaré",
            'colonne' => 'chiffre_affaires',
            'actif' => true,
            'systeme' => false,
        ]);
    }

    public function test_une_colonne_inconnue_est_refusee(): void
    {
        Sanctum::actingAs($this->utilisateur($this->fonctionCentral));

        $this->postJson('/api/indicateurs', [
            'code' => 'colonne_inconnue',
            'libelle' => 'Colonne inconnue',
            'colonne' => 'colonne_qui_nexiste_pas',
        ])->assertStatus(422)->assertJsonValidationErrors('colonne');
    }

    public function test_la_modification_change_le_libelle_l_unite_et_les_impots(): void
    {
        $indicateur = $this->indicateur();
        Sanctum::actingAs($this->utilisateur($this->fonctionCentral));

        $this->putJson("/api/indicateurs/{$indicateur->id}", [
            'libelle' => 'Libellé corrigé',
            'unite' => '%',
            'impots' => ['IS'],
            'description' => 'Description mise à jour.',
        ])->assertOk()->assertJsonPath('libelle', 'Libellé corrigé');

        $indicateur->refresh();
        $this->assertSame('Libellé corrigé', $indicateur->libelle);
        $this->assertSame('%', $indicateur->unite);
        $this->assertSame(['IS'], $indicateur->impots);
    }

    public function test_un_indicateur_fourni_garde_son_code(): void
    {
        $fourni = Indicateur::where('systeme', true)->firstOrFail();
        Sanctum::actingAs($this->utilisateur($this->fonctionCentral));

        $this->putJson("/api/indicateurs/{$fourni->id}", [
            'code' => 'code_modifie',
            'libelle' => 'Nouveau libellé',
        ])->assertOk();

        $fourni->refresh();
        $this->assertNotSame('code_modifie', $fourni->code);
        $this->assertSame('Nouveau libellé', $fourni->libelle);
    }

    public function test_un_indicateur_fourni_ne_peut_pas_etre_supprime(): void
    {
        $fourni = Indicateur::where('systeme', true)->firstOrFail();
        Sanctum::actingAs($this->utilisateur($this->fonctionCentral));

        $this->deleteJson("/api/indicateurs/{$fourni->id}")->assertStatus(422);
        $this->assertDatabaseHas('indicateurs', ['id' => $fourni->id]);
    }

    public function test_un_indicateur_utilise_ne_peut_pas_etre_supprime_desactive_ni_renomme(): void
    {
        // Une seconde colonne numérique : sans elle, la validation refuserait la colonne
        // avant même d'atteindre la protection « indicateur utilisé ».
        Schema::table('declarations', function (Blueprint $table): void {
            $table->decimal('montant_test', 16, 2)->nullable();
        });

        $indicateur = $this->indicateur(['code' => 'ca_utilise']);
        $this->regleUtilisant('ca_utilise');
        Sanctum::actingAs($this->utilisateur($this->fonctionCentral));

        $this->deleteJson("/api/indicateurs/{$indicateur->id}")->assertStatus(422);
        $this->assertDatabaseHas('indicateurs', ['id' => $indicateur->id]);

        $this->putJson("/api/indicateurs/{$indicateur->id}", ['actif' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('actif');

        $this->putJson("/api/indicateurs/{$indicateur->id}", ['code' => 'nouveau_code'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->putJson("/api/indicateurs/{$indicateur->id}", ['colonne' => 'montant_test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('colonne');

        // Le libellé et l'unité restent modifiables : la règle référence le code, pas le libellé.
        $this->putJson("/api/indicateurs/{$indicateur->id}", ['libelle' => 'Libellé ajusté'])
            ->assertOk();
        $this->assertSame('Libellé ajusté', $indicateur->refresh()->libelle);
    }

    public function test_un_indicateur_non_utilise_peut_etre_supprime(): void
    {
        $indicateur = $this->indicateur(['code' => 'ca_libre']);
        Sanctum::actingAs($this->utilisateur($this->fonctionCentral));

        $this->deleteJson("/api/indicateurs/{$indicateur->id}")->assertOk();
        $this->assertDatabaseMissing('indicateurs', ['id' => $indicateur->id]);
    }

    public function test_un_agent_ne_peut_pas_gerer_les_indicateurs(): void
    {
        Sanctum::actingAs($this->utilisateur($this->fonctionAgent));

        $this->getJson('/api/indicateurs')->assertForbidden();
        $this->postJson('/api/indicateurs', [
            'code' => 'interdit',
            'libelle' => 'Interdit',
            'colonne' => 'chiffre_affaires',
        ])->assertForbidden();
    }
}
