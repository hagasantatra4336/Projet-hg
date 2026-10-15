// Types des indicateurs et des règles personnalisées (étape 2).

export interface Condition {
  indicateur: string;
  mesure: string;
  comparateur: string;
  valeur?: number | "";
  indicateur_b?: string | null;
}

export interface GroupeConditions {
  operateur: "et" | "ou";
  conditions: (Condition | GroupeConditions)[];
}

export type StatutRegle = "brouillon" | "active" | "desactivee";

export interface IndicateurOption {
  code: string;
  libelle: string;
  unite: string | null;
}

export interface ParametreModele {
  cle: string;
  type: "indicateur" | "decimal" | "choix";
  defaut?: number | string;
  min?: number;
  max?: number;
  options?: string[];
}

export interface ModeleRegle {
  code: string;
  nom: string;
  description: string;
  niveau_defaut: string;
  parametres: ParametreModele[];
}

export interface OptionsReglesPerso {
  modeles: ModeleRegle[];
  indicateurs: IndicateurOption[];
  impots: string[];
  niveaux: string[];
  mesures: string[];
  comparateurs: string[];
  max_conditions: number;
  max_profondeur: number;
}

export interface ResultatSimulation {
  date: string;
  analyses: number;
  alertes: number;
  valeurs_absentes: number;
  par_niveau: Record<string, number>;
  par_impot: Record<string, number>;
  par_centre: Record<string, number>;
  alertes_existantes: number;
  echantillon: { contribuable_id: number; type_impot: string; annee_fiscale: number; mois: number; motif: string }[];
}

export interface SimulationRegle {
  id: number;
  date: string | null;
  date_analyse: string | null;
  version: number;
  statut: "reussie" | "erreur";
  erreur: string | null;
  a_jour: boolean;
  alertes: number | null;
  analyses: number | null;
  resultats: ResultatSimulation | null;
}

export interface HistoriqueReglePerso {
  id: number;
  date: string | null;
  par: string | null;
  action: string;
  avant: Record<string, unknown>;
  apres: Record<string, unknown>;
}

export interface ReglePerso {
  id: number;
  code: string;
  nom: string;
  description: string | null;
  modele: string | null;
  statut: StatutRegle;
  actif: boolean;
  niveau: string;
  impots: string[];
  conditions: GroupeConditions;
  fenetre_annees: number;
  version: number;
  simulation_valide: boolean;
  derniere_simulation: SimulationRegle | null;
  nb_alertes: number;
  cree_par: string | null;
  modifie_par: string | null;
  modifie_le: string | null;
  // présents dans le détail (GET /regles-personnalisees/{id} et actions)
  simulation?: SimulationRegle | null;
  historique?: HistoriqueReglePerso[];
}

export interface IndicateurAdmin {
  id: number;
  code: string;
  libelle: string;
  description: string | null;
  colonne: string;
  unite: string | null;
  impots: string[] | null;
  actif: boolean;
  systeme: boolean;
  utilise: boolean;
}

export const STATUT_REGLE_LABELS: Record<StatutRegle, string> = {
  brouillon: "Brouillon",
  active: "Active",
  desactivee: "Désactivée",
};

export const MESURE_LABELS: Record<string, string> = {
  valeur: "valeur déclarée",
  baisse_pct_n1: "baisse en % (vs même période N-1)",
  hausse_pct_n1: "hausse en % (vs même période N-1)",
  ecart_n1: "écart (vs même période N-1)",
  ratio_pct: "ratio en % (÷ autre indicateur)",
};

export const COMPARATEUR_LABELS: Record<string, string> = {
  ">": "supérieur à",
  ">=": "supérieur ou égal à",
  "<": "inférieur à",
  "<=": "inférieur ou égal à",
  "=": "égal à",
  "!=": "différent de",
  vide: "non renseignée",
  non_vide: "renseignée",
};

export const NIVEAU_REGLE_LABELS: Record<string, string> = { faible: "Faible", moyen: "Moyen", eleve: "Élevé" };

export const ACTION_LABELS: Record<string, string> = {
  creation: "Création du brouillon",
  modification: "Modification (retour en brouillon)",
  simulation: "Simulation",
  activation: "Activation",
  desactivation: "Désactivation",
};

export function estGroupe(n: Condition | GroupeConditions): n is GroupeConditions {
  return "conditions" in n;
}
