<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
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
        ];
    }
}
