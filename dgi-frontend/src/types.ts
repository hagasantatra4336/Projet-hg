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

export type Regle = "defaillance" | "baisse_ca" | "r1" | "r2" | "r3" | "r5" | "r6" | "r7";
export type NiveauRisque = "faible" | "moyen" | "eleve";
export type StatutAlerte = "a_traiter" | "traitee" | "regularisee";

export const TYPES_IMPOT = ["TVA", "IR", "IS", "IRSA"] as const;

export const REGLE_LABELS: Record<Regle, string> = {
  defaillance: "Défaillance de déclaration",
  baisse_ca: "Baisse du chiffre d'affaires",
  r1: "Écart CA IR / CA TVA (R1)",
  r2: "Déficit chronique (R2)",
  r3: "Crédit de TVA permanent (R3)",
  r5: "Discordance recoupement (R5)",
  r6: "Importations (R6)",
  r7: "Exportations (R7)",
};

/** Score maximal de chaque critère de risque R. */
export const SCORE_MAX_R: Partial<Record<Regle, number>> = { r1: 10, r2: 5, r3: 5, r5: 5, r6: 10, r7: 10 };

/** Détails d'une alerte : défaillance (score R4...) ou baisse du chiffre d'affaires. */
export interface DetailsAlerte {
  // défaillance de dépôt
  score_r4?: number;
  periodicite?: "mensuelle" | "annuelle";
  mois_manquants?: number[];
  jours_retard?: number;
  origine_obligation?: "assujettissement" | "historique";
  // baisse du chiffre d'affaires
  ca_precedent?: number;
  ca_actuel?: number;
  baisse_pct?: number;
  reference?: "meme_mois_annee_precedente" | "annee_precedente";
  annee_precedente?: number;
  // critères de risque R1, R2, R3, R5, R6, R7
  score_risque?: number;
  score_max?: number;
  indicateurs?: Record<string, number | null>;
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
  annee_fiscale: number; // exercice concerné
  mois: number; // 0 = exercice entier (défaillance, déclaration annuelle), 1 à 12 = mois (baisse du CA de la TVA)
  date_limite: string | null; // « AAAA-MM-JJ »
  periodes_manquees: number; // périodes (mois ou années) non déposées
  periodes_declarees: number;
  niveau: NiveauRisque;
  motif: string;
  details: DetailsAlerte | null;
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
  derniere_analyse: { at: string; date: string } | null;
}

export type AlerteContribuable = Omit<Defaillance, "contribuable">;

export const ETAT_LABELS: Record<number, string> = { 1: "Actif", 2: "Bloqué", 3: "Cessation", 4: "Radié" };

export interface CelluleDeclaration {
  annee: number;
  mois: number; // 0 = déclaration annuelle
  declare: boolean;
  chiffre_affaires: number | null;
  date_limite: string | null;
  date_depot: string | null;
  echue: boolean; // date limite dépassée
  obligee: boolean; // le contribuable devait déclarer cette période
}

export interface LigneHistorique {
  type_impot: string;
  cellules: CelluleDeclaration[];
}

/** Fiche d'un contribuable : identité, état, obligations, alertes et historique des déclarations. */
export interface ContribuableFiche {
  contribuable: {
    id: number;
    nif: string;
    nom: string;
    centre: string | null;
    etat: number | null;
    etat_libelle: string | null;
    date_cessation: string | null;
    date_radiation: string | null;
    date_blocage: string | null;
  };
  stats: { nb_declarations: number; premiere_annee: number | null; derniere_annee: number | null };
  obligations: { type_impot: string; annee_fiscale: number; periodicite: string; actif: boolean; exonere: boolean }[];
  alertes: AlerteContribuable[];
  historique: {
    mensuel: { periodes: { annee: number; mois: number }[]; impots: LigneHistorique[] };
    annuel: { annees: number[]; impots: LigneHistorique[] };
  };
}

// ---- Règles de détection réglables ----

export interface ParametreSchema {
  cle: string;
  label: string;
  aide: string;
  type: "entier" | "decimal" | "liste_impots" | "bareme";
  min?: number;
  max?: number;
  options?: string[];
}

export type ValeursRegle = Record<string, unknown>;

export interface HistoriqueRegle {
  id: number;
  date: string | null;
  par: string | null;
  action: "modification" | "reinitialisation";
  avant: { actif: boolean; parametres: ValeursRegle };
  apres: { actif: boolean; parametres: ValeursRegle };
}

export interface RegleConfig {
  code: string;
  nom: string;
  description: string | null;
  actif: boolean;
  parametres: ValeursRegle;
  defauts: ValeursRegle;
  personnalisee: boolean;
  schema: ParametreSchema[];
  modifie_par: string | null;
  modifie_le: string | null;
  historique: HistoriqueRegle[];
}
