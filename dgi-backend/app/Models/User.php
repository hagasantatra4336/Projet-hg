<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'nom',
        'email',
        'telephone',
        'adresse',
        'im',
        'centre_id',
        'fonction_id',
        'password',
        'email_verified_at',
        'desactive_le',
        'motif_desactivation',
        'desactive_par_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'desactive_le' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function estDesactive(): bool
    {
        return $this->desactive_le !== null;
    }

    /** Message renvoyé à la connexion (et à toute requête encore authentifiée). */
    public function messageDesactivation(): string
    {
        $motif = trim((string) $this->motif_desactivation);

        return $motif !== ''
            ? "Ce compte est désactivé. Motif : {$motif}"
            : 'Ce compte est désactivé.';
    }

    /** @return array{message: string, compte_desactive: true, motif: string|null} */
    public function payloadDesactivation(): array
    {
        return [
            'message' => $this->messageDesactivation(),
            'compte_desactive' => true,
            'motif' => $this->motif_desactivation,
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

    public function desactivePar(): BelongsTo
    {
        return $this->belongsTo(self::class, 'desactive_par_id');
    }

    /** Rôle effectif = rôle de la fonction (null si aucune fonction). */
    protected function role(): Attribute
    {
        return Attribute::get(fn (): ?Role => $this->fonction?->role);
    }
}
