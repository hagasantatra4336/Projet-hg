<?php

namespace Tests\Unit;

use App\Services\CalendrierFiscal;
use DateTimeImmutable;
use Tests\TestCase;

/**
 * Calendrier fiscal officiel DGI (« Échéances fiscales » du Calendrier 2026) : dates limites de
 * paiement par impôt et fenêtre d'analyse (échéance + 48 h, config/anomalies.php « echeances »).
 */
class CalendrierFiscalTest extends TestCase
{
    public function test_dates_limites_officielles_par_impot(): void
    {
        // TVA / IRSA mensuelles : le 15 du mois suivant ; décembre → janvier de l'année suivante
        $this->assertSame('2026-10-15', CalendrierFiscal::dateLimite('TVA', 2026, 9)?->format('Y-m-d'));
        $this->assertSame('2027-01-15', CalendrierFiscal::dateLimite('IRSA', 2026, 12)?->format('Y-m-d'));

        // IS / IR annuels : date fixe de l'année suivant l'exercice (IS : 31 mars, IR : 15 mai)
        $this->assertSame('2026-03-31', CalendrierFiscal::dateLimite('IS', 2025, 0)?->format('Y-m-d'));
        $this->assertSame('2026-05-15', CalendrierFiscal::dateLimite('IR', 2025, 0)?->format('Y-m-d'));

        // Impôt absent du calendrier : pas de date limite connue
        $this->assertNull(CalendrierFiscal::dateLimite('XYZ', 2026, 1));
    }

    public function test_la_fenetre_d_analyse_ouvre_48h_apres_l_echeance(): void
    {
        // Échéance TVA septembre 2026 : le 15/10 → analysable à partir du 17/10
        $this->assertSame('2026-10-17', CalendrierFiscal::analysableDepuis('TVA', 2026, 9)?->format('Y-m-d'));

        $avant = new DateTimeImmutable('2026-10-16');
        $apres = new DateTimeImmutable('2026-10-17');

        $this->assertFalse(CalendrierFiscal::analysable('TVA', 2026, 9, $avant));
        $this->assertTrue(CalendrierFiscal::analysable('TVA', 2026, 9, $apres));

        // Impôt sans règle dans le calendrier : jugé immédiatement (comportement historique)
        $this->assertTrue(CalendrierFiscal::analysable('XYZ', 2026, 9, $avant));
    }

    public function test_derniere_echeance_passee(): void
    {
        // Au 20/11 : dernière fenêtre ouverte = TVA d'octobre (échéance 15/11 + 48 h = 17/11)
        $this->assertSame(
            '2026-11-17',
            CalendrierFiscal::derniereEcheancePassee(new DateTimeImmutable('2026-11-20'))->format('Y-m-d')
        );

        // Au 16/11 : la fenêtre d'octobre n'est pas encore ouverte → reste celle de septembre (17/10)
        $this->assertSame(
            '2026-10-17',
            CalendrierFiscal::derniereEcheancePassee(new DateTimeImmutable('2026-11-16'))->format('Y-m-d')
        );
    }

    public function test_un_exercice_est_clos_apres_sa_derniere_echeance_plus_48h(): void
    {
        // Exercice 2025 : dernière échéance = IR 2025 au 15/05/2026 (+ 48 h → 17/05/2026)
        $this->assertSame('2026-05-17', CalendrierFiscal::exerciceClosDepuis(2025)?->format('Y-m-d'));

        $this->assertFalse(CalendrierFiscal::exerciceClose(2025, new DateTimeImmutable('2026-05-16')));
        $this->assertTrue(CalendrierFiscal::exerciceClose(2025, new DateTimeImmutable('2026-05-17')));

        // L'exercice en cours n'est pas clos tant que ses déclarations annuelles sont ouvertes
        $this->assertFalse(CalendrierFiscal::exerciceClose(2026, new DateTimeImmutable('2026-12-31')));
    }

    public function test_dernier_exercice_clos(): void
    {
        // Au 29/10/2026 : 2025 est clos depuis le 17/05/2026, 2026 ne le sera que le 17/05/2027
        $this->assertSame(2025, CalendrierFiscal::dernierExerciceClos(new DateTimeImmutable('2026-10-29')));
        $this->assertSame(2024, CalendrierFiscal::dernierExerciceClos(new DateTimeImmutable('2026-05-16')));
        $this->assertSame(2025, CalendrierFiscal::dernierExerciceClos(new DateTimeImmutable('2026-05-17')));
    }
}
