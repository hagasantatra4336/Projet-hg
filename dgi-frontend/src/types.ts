export type Role = "agent" | "admin" | "central" | "superadmin";

export const ROLE_LABELS: Record<Role, string> = {
  agent: "Agent",
  admin: "Admin",
  central: "Central",
  superadmin: "Super admin",
};

export const ROLE_RANK: Record<Role, number> = {
  agent: 1,
  admin: 2,
  central: 3,
  superadmin: 4,
};

export const ROLES: Role[] = ["agent", "admin", "central", "superadmin"];

export interface Centre {
  id: number;
  nom: string;
  adresse: string | null;
  users_count?: number;
}

export interface Fonction {
  id: number;
  nom: string;
  role: Role;
  users_count?: number;
}

export interface AppUser {
  id: number;
  nom: string;
  email: string;
  telephone: string | null;
  adresse: string | null;
  im: string | null;
  centre_id: number | null;
  fonction_id: number | null;
  centre: { id: number; nom: string } | null;
  fonction: { id: number; nom: string; role: Role } | null;
  role: Role | null;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}
