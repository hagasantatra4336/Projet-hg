<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\Fonction;
use App\Models\User;
use App\Services\SessionsAppareils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Politique d'appareils : deux sessions pour agent et admin, une seule pour central et superadmin.
 * La fermeture de la session précédente d'un rôle à session unique libère aussi ses dossiers.
 */
class SessionsAppareilsTest extends TestCase
{
    use RefreshDatabase;

    private SessionsAppareils $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessions = app(SessionsAppareils::class);
    }

    private function utilisateur(Role $role, ?Centre $centre = null): User
    {
        $centre ??= Centre::create(['nom' => 'Centre ' . $role->value]);
        $fonction = Fonction::create(['nom' => 'Fonction ' . $role->value . ' ' . uniqid(), 'role' => $role]);

        return User::create([
            'nom' => 'Utilisateur ' . $role->value,
            'email' => $role->value . '.' . uniqid() . '@sessions.test',
            'password' => 'motdepasse123',
            'centre_id' => $centre->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);
    }

    private function requete(string $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36'): Request
    {
        return Request::create('/api/login', 'POST', [], [], [], ['HTTP_USER_AGENT' => $ua]);
    }

    public function test_un_agent_garde_deux_appareils_et_le_plus_ancien_est_ferme(): void
    {
        $agent = $this->utilisateur(Role::Agent);

        $premier = $this->sessions->ouvrir($agent, $this->requete());
        $deuxieme = $this->sessions->ouvrir($agent, $this->requete());
        $troisieme = $this->sessions->ouvrir($agent, $this->requete());

        $this->assertSame(2, $agent->tokens()->count());
        $this->assertNull(PersonalAccessToken::findToken($premier), 'Le premier jeton doit être fermé.');
        $this->assertNotNull(PersonalAccessToken::findToken($deuxieme));
        $this->assertNotNull(PersonalAccessToken::findToken($troisieme));
    }

    public function test_un_admin_est_traite_comme_un_agent(): void
    {
        $admin = $this->utilisateur(Role::Admin);

        $this->sessions->ouvrir($admin, $this->requete());
        $this->sessions->ouvrir($admin, $this->requete());
        $this->sessions->ouvrir($admin, $this->requete());

        $this->assertSame(2, $admin->tokens()->count());
    }

    public function test_un_central_n_a_qu_une_session_et_ses_dossiers_sont_liberes(): void
    {
        $centre = Centre::create(['nom' => 'Centre central']);
        $central = $this->utilisateur(Role::Central, $centre);

        $contribuable = Contribuable::create([
            'nif' => '3333333333', 'nom' => 'Société verrou', 'centre_id' => $centre->id, 'etat' => 1,
        ]);
        $alerte = Alerte::create([
            'contribuable_id' => $contribuable->id,
            'regle' => 'r1', 'type_impot' => 'TVA', 'annee_fiscale' => 2026, 'mois' => 0,
            'periodes_manquees' => 0, 'periodes_declarees' => 0, 'niveau' => 'eleve',
            'motif' => 'Écart.', 'statut' => Alerte::A_TRAITER,
            'pris_par' => $central->id, 'pris_le' => now(),
        ]);

        $premier = $this->sessions->ouvrir($central, $this->requete());
        $second = $this->sessions->ouvrir($central, $this->requete());

        $this->assertSame(1, $central->tokens()->count());
        $this->assertNull(PersonalAccessToken::findToken($premier));
        $this->assertNotNull(PersonalAccessToken::findToken($second));

        $alerte = $alerte->fresh();
        $this->assertNull($alerte->getAttribute('pris_par'), 'Le dossier tenu par la session fermée doit être libéré.');
        $this->assertNull($alerte->getAttribute('pris_le'));
    }

    public function test_un_superadmin_n_a_qu_une_session(): void
    {
        $superadmin = $this->utilisateur(Role::Superadmin);

        $this->sessions->ouvrir($superadmin, $this->requete());
        $this->sessions->ouvrir($superadmin, $this->requete());

        $this->assertSame(1, $superadmin->tokens()->count());
    }

    public function test_le_jeton_porte_le_nom_de_l_appareil(): void
    {
        $agent = $this->utilisateur(Role::Agent);

        $this->sessions->ouvrir($agent, $this->requete(
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36'
        ));

        $this->assertSame('Chrome · Android', $agent->tokens()->first()->name);
    }
}
