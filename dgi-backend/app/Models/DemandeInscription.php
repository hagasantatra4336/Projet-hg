<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande d'inscription d'un agent, approuvée (ou refusée) par le chef de son centre.
 * Le code OTP n'est envoyé qu'après approbation ; il est conservé haché (HMAC + APP_KEY).
 */
class DemandeInscription extends Model
{
    public const EN_ATTENTE = 'en_attente';
    public const APPROUVEE = 'approuvee';
    public const REFUSEE = 'refusee';
    public const ANNULEE = 'annulee';
    public const EXPIREE = 'expiree';
    public const COMPTE_CREE = 'compte_cree';

    /** Niveaux d'approbation, du plus proche du demandeur au plus haut. */
    public const NIVEAU_CHEF = 'chef';
    public const NIVEAU_CENTRAL = 'central';
    public const NIVEAU_SUPERADMIN = 'superadmin';

    protected $table = 'demandes_inscription';

    protected $fillable = [
        'request_id',
        'nom',
        'email',
        'im',
        'telephone',
        'adresse',
        'centre_id',
        'fonction_id',
        'statut',
        'niveau_approbation',
        'traite_par',
        'traite_le',
        'motif_refus',
        'otp_hash',
        'otp_expires_at',
        'otp_attempts',
        'otp_renvois',
        'otp_envoye_le',
        'setup_hash',
        'setup_expires_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'otp_expires_at' => 'datetime',
            'otp_envoye_le' => 'datetime',
            'setup_expires_at' => 'datetime',
            'traite_le' => 'datetime',
            'expires_at' => 'datetime',
            'otp_attempts' => 'integer',
            'otp_renvois' => 'integer',
        ];
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function fonction(): BelongsTo
    {
        return $this->belongsTo(Fonction::class);
    }

    public function traitePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traite_par');
    }

    /** Statut réel : une demande encore « en_attente » au-delà de sa date limite est expirée. */
    public function statutEffectif(): string
    {
        if ($this->statut === self::EN_ATTENTE && $this->expires_at !== null && now()->greaterThan($this->expires_at)) {
            return self::EXPIREE;
        }

        return $this->statut;
    }

    /** Le code a été saisi et le jeton de définition du mot de passe est encore valable. */
    public function codeVerifie(): bool
    {
        return $this->setup_hash !== null
            && $this->setup_expires_at !== null
            && now()->lessThanOrEqualTo($this->setup_expires_at);
    }
}
