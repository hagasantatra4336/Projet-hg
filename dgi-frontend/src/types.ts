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

// ---- Défaillances de déclaration ----

export type Regle = "defaillance" | "baisse_ca";
export type NiveauRisque = "faible" | "moyen" | "eleve";
export type StatutAlerte = "a_traiter" | "traitee" | "regularisee";

export const TYPES_IMPOT = ["TVA", "IR", "IS", "IRSA"] as const;

export const REGLE_LABELS: Record<Regle, string> = {
  defaillance: "Défaillance de déclaration",
  baisse_ca: "Baisse du chiffre d'affaires",
};

/** Détails d'une alerte « baisse du chiffre d'affaires ». */
export interface DetailsBaisseCa {
  ca_precedent: number;
  ca_actuel: number;
  baisse_pct: number;
  periode_precedente: string;
}

export const NIVEAU_LABELS: Record<NiveauRisque, string> = {
  faible: "Faible",
  moyen: "Moyen",
  eleve: "Élevé",
};

export const STATUT_LABELS: Record<StatutAlerte, string> = {
  a_traiter: "À traiter",
  traitee: "Traitée",
  regularisee: "Régularisée",
};

export interface Defaillance {
  id: number;
  contribuable: { id: number; nif: string; nom: string; centre: string | null };
  regle: Regle;
  type_impot: string;
  periode: string; // première période manquante, "AAAA-MM"
  mois_manques: number;
  serie_precedente: number;
  niveau: NiveauRisque;
  motif: string;
  details: DetailsBaisseCa | null;
  statut: StatutAlerte;
  commentaire: string | null;
  traite_par: string | null;
  traite_le: string | null;
}

export interface DefaillanceSummary {
  a_traiter: number;
  eleve: number;
  moyen: number;
  faible: number;
  traitees: number;
  regularisees: number;
  derniere_analyse: { at: string; periode: string } | null;
}

export type AlerteContribuable = Omit<Defaillance, "contribuable">;

/** Fiche d'un contribuable : identité, alertes et historique des 12 derniers mois. */
export interface ContribuableFiche {
  contribuable: { id: number; nif: string; nom: string; centre: string | null };
  stats: { nb_declarations: number; premiere_periode: string | null; derniere_periode: string | null };
  alertes: AlerteContribuable[];
  historique: {
    periodes: string[];
    impots: {
      type_impot: string;
      premiere_periode: string | null;
      cellules: {
        periode: string;
        declare: boolean;
        montant: number | null;
        chiffre_affaires: number | null;
        date_depot: string | null;
      }[];
    }[];
  };
}
