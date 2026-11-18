<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\JournalActivite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'adresse' => $this->adresse,
            'im' => $this->im,
            'centre_id' => $this->centre_id,
            'fonction_id' => $this->fonction_id,
            'centre' => $this->centre ? [
                'id' => $this->centre->id,
                'nom' => $this->centre->nom,
            ] : null,
            'fonction' => $this->fonction ? [
                'id' => $this->fonction->id,
                'nom' => $this->fonction->nom,
                'role' => $this->fonction->role->value,
            ] : null,
            'role' => $this->role?->value,
            'desactive' => $this->estDesactive(),
            'motif_desactivation' => $this->estDesactive() ? $this->motif_desactivation : null,
            'desactive_le' => $this->estDesactive() ? $this->desactive_le?->toIso8601String() : null,
            'desactive_par_nom' => ! $this->estDesactive() || ! $this->relationLoaded('desactivePar')
                ? null
                : $this->desactivePar?->nom,
            // Statut calculé à partir du jeton Sanctum : aucune colonne en base n'est nécessaire.
            'en_ligne' => JournalActivite::estEnLigne($this->activiteRecente()),
            'derniere_activite_le' => $this->activiteRecente() !== null
                ? Carbon::parse($this->activiteRecente())->toIso8601String()
                : null,
        ];
    }

    /** Dernière trace du jeton : « last_used_at », à défaut la date de connexion (jeton jamais réutilisé). */
    private function activiteRecente(): ?string
    {
        return $this->derniere_activite ?? $this->derniere_connexion ?? null;
    }
}
