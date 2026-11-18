<?php

namespace App\Console\Commands;

use App\Models\DemandeInscription;
use Illuminate\Console\Command;

/**
 * Clôture les demandes approuvées dont le code OTP a expiré sans être saisi : l'inscription a
 * échoué et l'agent doit déposer une nouvelle demande. Sans ce passage, la demande resterait
 * « approuvée » et son matricule bloquerait toute nouvelle inscription.
 *
 * Planifié toutes les cinq minutes (routes/console.php).
 */
class ExpirerOtpApprobation extends Command
{
    protected $signature = 'dgi:expirer-otp-approbation';

    protected $description = "Clôture les demandes approuvées dont le code OTP a expiré sans être saisi";

    public function handle(): int
    {
        $cloturees = DemandeInscription::query()
            ->where('statut', DemandeInscription::APPROUVEE)
            ->whereNotNull('otp_hash')
            ->whereNotNull('otp_expires_at')
            ->where('otp_expires_at', '<=', now())
            ->update([
                'statut' => DemandeInscription::EXPIREE,
                'otp_hash' => null,
                'rappel_otp_envoye_le' => null,
            ]);

        $this->info(sprintf('%d demande(s) clôturée(s) pour code expiré.', $cloturees));

        return self::SUCCESS;
    }
}
