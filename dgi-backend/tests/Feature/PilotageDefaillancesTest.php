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
 * Indicateurs de pilotage du résumé : délai moyen de traitement, attente avant prise, dossiers en retard.
 */
class PilotageDefaillancesTest extends TestCase
{
    use RefreshDatabase;

    private Centre $centre;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Centre::create(['nom' => 'Centre pilotage']);
        $this->agent = $this->utilisateur(Role::Agent, $this->centre, 'agent@pilotage.test');
    }

    private function utilisateur(Role $role, Centre $centre, string $email): User
    {
        $fonction = Fonction::create(['nom' => 'Fonction '.$role->value.' '.$email, 'role' => $role]);

        return User::create([
            'nom' => 'Utilisateur '.$email,
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
            'regle' => 'r1',
            'type_impot' => 'TVA',
            'annee_fiscale' => 2026,
            'mois' => 0,
            'periodes_manquees' => 0,
            'periodes_declarees' => 0,
            'niveau' => 'eleve',
            'motif' => 'Écart entre le CA IR et le CA TVA.',
            'statut' => Alerte::A_TRAITER,
        ]);
    }

    public function test_le_resume_chiffre_les_delais_et_les_retards(): void
    {
        // Dossier clos : ouvert il y a 10 j, pris 8 j plus tard (2 j d'attente), clos au bout de 5 j.
        $clos = $this->alerte('1111111111', $this->centre->id, [
            'annee_fiscale' => 2024,
            'pris_par' => $this->agent->id,
            'statut' => Alerte::TRAITEE,
            'traite_par' => $this->agent->id,
        ]);

        // created_at / pris_le / traite_le ne sont pas modifiables en masse : on les fixe directement.
        DB::table('alertes')->where('id', $clos->id)->update([
            'created_at' => now()->subDays(10),
            'pris_le' => now()->subDays(8),
            'traite_le' => now()->subDays(5),
        ]);

        // Dossier ouvert depuis 45 j : en retard.
        $retard = $this->alerte('2222222222', $this->centre->id);
        DB::table('alertes')->where('id', $retard->id)->update(['created_at' => now()->subDays(45)]);

        // Dossier récent : ni en retard, ni pris.
        $recent = $this->alerte('3333333333', $this->centre->id);
        DB::table('alertes')->where('id', $recent->id)->update(['created_at' => now()->subDays(3)]);

        Sanctum::actingAs($this->agent);

        $res = $this->getJson('/api/defaillances/summary');
        $res->assertOk();

        $this->assertEqualsWithDelta(5.0, $res->json('delai_moyen_jours'), 0.01);
        $this->assertEqualsWithDelta(2.0, $res->json('attente_moyenne_jours'), 0.01);
        $this->assertSame(1, $res->json('dossiers_en_retard'));

        // Exercices proposés : du plus récent au plus ancien, sans doublon.
        $this->assertSame([2026, 2024], $res->json('exercices'));
    }

    public function test_le_pilotage_reste_limite_au_centre_de_l_agent(): void
    {
        $autre = Centre::create(['nom' => 'Centre lointain']);
        $agent = $this->utilisateur(Role::Agent, $autre, 'agent@lointain.test');

        $retard = $this->alerte('2222222222', $this->centre->id);
        DB::table('alertes')->where('id', $retard->id)->update(['created_at' => now()->subDays(45)]);
        $this->alerte('4444444444', $autre->id, ['annee_fiscale' => 2023]);

        Sanctum::actingAs($agent);

        $res = $this->getJson('/api/defaillances/summary');
        $res->assertOk();

        $this->assertSame(0, $res->json('dossiers_en_retard'));
        $this->assertSame([2023], $res->json('exercices'));
    }
}
