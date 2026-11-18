<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\Api\AuthController;
use App\Models\Activite;
use App\Models\Centre;
use App\Models\Fonction;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Journal d'activités du système (central et superadmin) et statut en ligne des utilisateurs.
 */
class ActivitesTest extends TestCase
{
    use RefreshDatabase;

    private Centre $centre;

    private Fonction $fonctionCentral;

    private Fonction $fonctionSuperadmin;

    private Fonction $fonctionAdmin;

    private Fonction $fonctionAgent;

    private static int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush(); // les compteurs de limitation (connexion, OTP) ne doivent pas fuir entre les tests

        $this->centre = Centre::create(['nom' => 'Centre activités']);
        $this->fonctionCentral = Fonction::create(['nom' => 'Central activités', 'role' => Role::Central]);
        $this->fonctionSuperadmin = Fonction::create(['nom' => 'Superadmin activités', 'role' => Role::Superadmin]);
        $this->fonctionAdmin = Fonction::create(['nom' => 'Admin activités', 'role' => Role::Admin]);
        $this->fonctionAgent = Fonction::create(['nom' => 'Agent activités', 'role' => Role::Agent]);
    }

    private function utilisateur(Fonction $fonction): User
    {
        self::$compteur++;

        return User::create([
            'nom' => 'Utilisateur activité ' . self::$compteur,
            'email' => 'activite' . self::$compteur . '@test.local',
            'im' => str_pad((string) self::$compteur, 6, '0', STR_PAD_LEFT),
            'telephone' => '03400000' . str_pad((string) self::$compteur, 2, '0', STR_PAD_LEFT),
            'password' => 'motdepasse123',
            'centre_id' => $this->centre->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function activite(array $attrs = []): Activite
    {
        self::$compteur++;

        return Activite::create($attrs + [
            'user_nom' => 'Utilisateur journal ' . self::$compteur,
            'role' => Role::Central->value,
            'categorie' => Activite::CATEGORIE_ADMINISTRATION,
            'action' => 'modification_centre',
            'description' => 'Modification d’un centre',
            'cible' => 'Centre ' . self::$compteur,
            'methode' => 'PUT',
            'chemin' => '/api/centres/' . self::$compteur,
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);
    }

    public function test_la_liste_est_reservee_au_central_et_au_superadmin(): void
    {
        foreach ([$this->fonctionCentral, $this->fonctionSuperadmin] as $fonction) {
            Sanctum::actingAs($this->utilisateur($fonction));
            $this->getJson('/api/activites')->assertOk();
        }

        foreach ([$this->fonctionAdmin, $this->fonctionAgent] as $fonction) {
            Sanctum::actingAs($this->utilisateur($fonction));
            $this->getJson('/api/activites')->assertForbidden();
        }
    }

    public function test_la_liste_expose_les_entrees_les_categories_et_les_compteurs(): void
    {
        $this->activite(['categorie' => Activite::CATEGORIE_CONNEXION, 'action' => 'connexion', 'description' => 'Connexion au système']);
        Sanctum::actingAs($this->utilisateur($this->fonctionCentral));

        $this->getJson('/api/activites')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'user_id', 'user_nom', 'role', 'categorie', 'categorie_libelle',
                    'action', 'description', 'cible', 'ip', 'user_agent', 'cree_le',
                ]],
                'current_page',
                'last_page',
                'total',
                'categories' => [['value', 'label']],
                'compteurs' => ['total', 'aujourdhui', 'connexions', 'utilisateurs'],
            ])
            ->assertJsonPath('compteurs.total', 1)
            ->assertJsonPath('compteurs.connexions', 1);
    }

    public function test_les_filtres_recherche_categorie_et_periode_s_appliquent(): void
    {
        $ancienne = $this->activite(['description' => 'Ancienne action sur un centre']);
        $ancienne->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->activite([
            'categorie' => Activite::CATEGORIE_CONNEXION,
            'action' => 'connexion',
            'description' => 'Connexion au système',
            'user_nom' => 'Rakoto Jean',
        ]);

        Sanctum::actingAs($this->utilisateur($this->fonctionCentral));

        $this->getJson('/api/activites?search=Rakoto')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'connexion');

        $this->getJson('/api/activites?categorie=connexion')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/activites?periode=jour')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/activites?periode=tout')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_une_action_reussie_est_journalisee_par_le_middleware(): void
    {
        $superadmin = $this->utilisateur($this->fonctionSuperadmin);
        Sanctum::actingAs($superadmin);

        $this->postJson('/api/users', [
            'nom' => 'Agent créé',
            'email' => 'agent.cree@test.local',
            'im' => '654321',
            'telephone' => '0341234567',
            'centre_id' => $this->centre->id,
            'fonction_id' => $this->fonctionAgent->id,
            'password' => 'motdepasse123',
        ])->assertCreated();

        $this->assertDatabaseHas('activites', [
            'user_id' => $superadmin->id,
            'user_nom' => $superadmin->nom,
            'categorie' => Activite::CATEGORIE_ADMINISTRATION,
            'action' => 'creation_utilisateur',
            'methode' => 'POST',
        ]);
    }

    public function test_une_action_refusee_n_est_pas_journalisee(): void
    {
        Sanctum::actingAs($this->utilisateur($this->fonctionCentral));

        // Le central ne peut pas attribuer la fonction superadmin : validation en erreur, rien n'est modifié.
        $this->postJson('/api/users', [
            'nom' => 'Compte refusé',
            'email' => 'refuse@test.local',
            'im' => '111222',
            'telephone' => '0341112233',
            'centre_id' => $this->centre->id,
            'fonction_id' => $this->fonctionSuperadmin->id,
            'password' => 'motdepasse123',
        ])->assertStatus(422);

        $this->assertDatabaseCount('activites', 0);
    }

    public function test_la_connexion_reussie_est_journalisee(): void
    {
        $user = $this->utilisateur($this->fonctionCentral);
        $code = '123456';

        Cache::put(AuthController::cleConnexion($user->email), [
            'user_id' => $user->id,
            'otp' => app(OtpService::class)->hacher($user->email, $code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ], now()->addMinutes(10));

        $this->postJson('/api/login/verify-otp', ['email' => $user->email, 'otp_code' => $code])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);

        $this->assertDatabaseHas('activites', [
            'user_id' => $user->id,
            'categorie' => Activite::CATEGORIE_CONNEXION,
            'action' => 'connexion',
        ]);
    }

    public function test_la_deconnexion_est_journalisee(): void
    {
        $user = $this->utilisateur($this->fonctionCentral);
        $token = $user->createToken('PHPUnit')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertDatabaseHas('activites', [
            'user_id' => $user->id,
            'categorie' => Activite::CATEGORIE_CONNEXION,
            'action' => 'deconnexion',
        ]);
    }

    public function test_le_statut_en_ligne_repose_sur_la_derniere_utilisation_du_jeton(): void
    {
        $central = $this->utilisateur($this->fonctionCentral);
        $actif = $this->utilisateur($this->fonctionAgent);
        $inactif = $this->utilisateur($this->fonctionAgent);

        $actif->createToken('Navigateur')->accessToken
            ->forceFill(['last_used_at' => now()->subMinutes(2)])->save();

        $inactif->createToken('Navigateur')->accessToken
            ->forceFill(['last_used_at' => now()->subHours(2)])->save();

        Sanctum::actingAs($central);
        $reponse = $this->getJson('/api/users')->assertOk();

        $lignes = collect($reponse->json('data'))->keyBy('id');

        $this->assertTrue($lignes[$actif->id]['en_ligne']);
        $this->assertNotNull($lignes[$actif->id]['derniere_activite_le']);
        $this->assertFalse($lignes[$inactif->id]['en_ligne']);
        $this->assertNotNull($lignes[$inactif->id]['derniere_activite_le']);
    }
}
