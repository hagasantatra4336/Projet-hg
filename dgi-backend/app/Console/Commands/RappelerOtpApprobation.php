<?php

namespace App\Console\Commands;

use App\Models\DemandeInscription;
use App\Notifications\RappelOtpApprobationNotification;
use App\Services\OtpService;
use App\Support\FrontendUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Un code d'approbation est valable 24 heures. Une heure avant l'expiration, l'agent reçoit un
 * rappel : passé le délai, la demande est clôturée et il devra en déposer une nouvelle.
 *
 * Planifié toutes les cinq minutes (routes/console.php). Le rappel n'est envoyé qu'une fois par
 * code : la colonne rappel_otp_envoye_le est remise à null à chaque nouvel envoi de code.
 */
class RappelerOtpApprobation extends Command
{
    protected $signature = 'dgi:rappeler-otp-approbation
        {--simulation : Affiche les rappels à envoyer sans expédier d\'e-mail}';

    protected $description = "Envoie un rappel une heure avant l'expiration d'un code OTP d'approbation";

    public function handle(): int
    {
        $simulation = (bool) $this->option('simulation');
        $limite = now()->addMinutes(OtpService::RAPPEL_AVANT_EXPIRATION_MINUTES);
        $url = rtrim(FrontendUrl::base(), '/') . '/';

        $envoyes = 0;
        $echecs = 0;

        DemandeInscription::query()
            ->where('statut', DemandeInscription::APPROUVEE)
            ->whereNotNull('otp_hash')
            ->whereNotNull('otp_expires_at')
            ->whereNull('rappel_otp_envoye_le')
            ->where('otp_expires_at', '>', now())
            ->where('otp_expires_at', '<=', $limite)
            ->select(['id', 'nom', 'email', 'otp_expires_at'])
            ->orderBy('id')
            ->chunkById(100, function ($demandes) use ($simulation, $url, &$envoyes, &$echecs): void {
                foreach ($demandes as $demande) {
                    $minutes = max(1, (int) ceil(($demande->otp_expires_at->timestamp - now()->timestamp) / 60));

                    if ($simulation) {
                        $this->line(sprintf(
                            '#%d %s <%s> — rappel dans %s',
                            $demande->id,
                            $demande->nom,
                            $demande->email,
                            OtpService::dureeLisible($minutes)
                        ));
                        $envoyes++;

                        continue;
                    }

                    try {
                        Notification::route('mail', $demande->email)
                            ->notify(new RappelOtpApprobationNotification($demande->nom, $minutes, $url));

                        $demande->forceFill(['rappel_otp_envoye_le' => now()])->save();
                        $envoyes++;
                    } catch (Throwable $e) {
                        // Un échec SMTP ne doit pas empêcher les autres rappels de partir ;
                        // le drapeau reste à null, le prochain passage réessaiera.
                        $echecs++;
                        Log::error(sprintf(
                            'Rappel OTP de la demande #%d (%s) impossible : %s',
                            $demande->id,
                            $demande->email,
                            $e->getMessage()
                        ));
                    }
                }
            });

        $this->info(sprintf(
            '%d rappel(s) %s, %d échec(s).',
            $envoyes,
            $simulation ? 'à envoyer' : 'envoyé(s)',
            $echecs
        ));

        return self::SUCCESS;
    }
}
