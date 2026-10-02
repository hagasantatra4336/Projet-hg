<?php

namespace App\Enums;

/**
 * Rôles de l'application. Un utilisateur n'a pas de rôle "propre" :
 * son rôle est celui de sa FONCTION (table fonctions, colonne "role").
 */
enum Role: string
{
    case Agent = 'agent';
    case Admin = 'admin';          // souvent le chef d'un centre
    case Central = 'central';
    case Superadmin = 'superadmin';

    /** Niveau hiérarchique (plus grand = plus de pouvoir). */
    public function rank(): int
    {
        return match ($this) {
            self::Agent => 1,
            self::Admin => 2,
            self::Central => 3,
            self::Superadmin => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Agent => 'Agent',
            self::Admin => 'Admin',
            self::Central => 'Central',
            self::Superadmin => 'Super admin',
        };
    }

    /**
     * Peut-on attribuer le rôle $target ?
     * - superadmin : tous les rôles
     * - les autres : uniquement les rôles de rang STRICTEMENT inférieur au leur
     *   (un admin crée des agents, un central crée des admins/agents...).
     */
    public function canAssign(self $target): bool
    {
        return $this === self::Superadmin || $target->rank() < $this->rank();
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
