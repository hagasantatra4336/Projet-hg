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
export type StatutAlerte = "a_traiter" | "en_traitement" | "traitee" | "regularisee";

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

/** Libellés courts, pour afficher plusieurs anomalies dans une même ligne. */
export const REGLE_COURTS: Record<Regle, string> = {
  defaillance: "Défaut de dépôt",
  baisse_ca: "Baisse du CA",
  r1: "R1 · CA IR / TVA",
  r2: "R2 · Déficit",
  r3: "R3 · Crédit TVA",
  r5: "R5 · Recoupement",
  r6: "R6 · Importations",
  r7: "R7 · Exportations",
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
  en_traitement: "En traitement",
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
  // verrou de traitement : dossier pris par un agent (statut « en_traitement »)
  pris_par?: string | null;
  pris_le?: string | null;
  pris_par_moi?: boolean;
}

export interface DefaillanceSummary {
  a_traiter: number;
  en_traitement: number;
  eleve: number;
  moyen: number;
  faible: number;
  traitees: number;
  regularisees: number;
  /** Nombre de contribuables (dossiers) ayant au moins une anomalie libre à traiter. */
  dossiers_a_traiter: number;
  /** Nombre de contribuables (dossiers) dont au moins une anomalie est prise. */
  dossiers_en_traitement: number;
  /** Nombre de contribuables (dossiers) ayant au moins une anomalie traitée. */
  dossiers_traites: number;
  derniere_analyse: { at: string; date: string } | null;
}

/** Toutes les anomalies d'un contribuable, présentées comme un seul dossier. */
export interface GroupeAlerte {
  contribuable: Defaillance["contribuable"];
  alertes: Defaillance[];
  representant_id: number;
  niveau: NiveauRisque;
  statut: StatutAlerte;
  pris_par: string | null;
  pris_le: string | null;
  pris_par_moi: boolean;
  traite_par: string | null;
  traite_le: string | null;
  commentaire: string | null;
  nb_alertes: number;
  regles: string[];
  types_impot: string[];
  annees: number[];
}

export type AlerteContribuable = Omit<Defaillance, "contribuable">;

/** Une ligne de la base d'analyse risque (une année, ou un mois pour la TVA). */
export interface LigneBaseRisque {
  annee: number;
  mois: number;
  valeurs: Record<string, number | null>;
}

/** Dossier de traitement d'une alerte : l'alerte, les valeurs qui l'expliquent, la formule de sa règle. */
export interface DossierAlerte {
  alerte: Defaillance;
  indicateurs: Record<"IR" | "TVA" | "RECOUPEMENT" | "DAU", LigneBaseRisque[]>;
  formule: FormuleRegle | null;
  /** Formule de chaque règle présente dans le dossier contribuable. */
  formules?: Partial<Record<Regle, FormuleRegle | null>>;
  /** Dossier contribuable : toutes ses anomalies. */
  groupe: GroupeAlerte;
  /** Journal des actions : prise, reprise, libération, clôture, réouverture. */
  historique: ActionDossier[];
  verrou_minutes: number;
}

export type ActionDossierType = "prise" | "reprise" | "liberation" | "cloture" | "reouverture";

export interface ActionDossier {
  id: number;
  action: ActionDossierType;
  auteur: string;
  statut_avant: StatutAlerte;
  statut_apres: StatutAlerte;
  le: string | null;
}

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

/** Synthèse de l'analyse d'un contribuable, affichée sur sa fiche et reprise dans le PDF. */
export interface SyntheseFiche {
  alertes_total: number;
  par_statut: Record<StatutAlerte, number>;
  par_regle: Record<Regle, number>;
  par_niveau: Record<NiveauRisque, number>;
  niveau_global: NiveauRisque | null;
  derniere_analyse: { at: string; date: string } | null;
  annees: { premiere: number | null; derniere: number | null };
}

/** Chiffres clés repris de la base d'analyse risque (dernier exercice disponible par source). */
export interface ChiffresFiche {
  ir: { annee: number; ca: number; resultat: number; ir_a_payer: number } | null;
  tva: { annee: number; nb_mois: number; dernier_mois: number; ca: number; export: number; credit_tva: number } | null;
  recoupement: { annee: number; montant_total: number; prestations: number } | null;
  dau: { annee: number; tva_im4: number; caf_ex1: number } | null;
  ecart_ca_pct: number | null;
}

/** Une note du journal d'observations d'un contribuable. */
export interface ObservationFiche {
  id: number;
  texte: string;
  auteur: string | null;
  le: string | null;
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
    telephone: string | null;
    email: string | null;
    adresse: string | null;
    activite: string | null;
  };
  stats: { nb_declarations: number; premiere_annee: number | null; derniere_annee: number | null };
  resultat: SyntheseFiche;
  chiffres: ChiffresFiche;
  observations: ObservationFiche[];
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

/** Formules et interprétation d'une règle (texte fourni par l'API, seuils en vigueur). */
export interface FormuleRegle {
  formules: { label: string; expr: string }[];
  sources: string[];
  niveaux: { couleur: "vert" | "faible" | "orange" | "rouge"; texte: string }[];
}

/** Une modification de règle dans la page « Historique des règles ». */
export interface EntreeHistoriqueRegle extends HistoriqueRegle {
  regle: { code: string; nom: string; schema: ParametreSchema[] };
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
  formule?: FormuleRegle;
  modifie_par: string | null;
  modifie_le: string | null;
  historique: HistoriqueRegle[];
}

// ---- Demandes d'inscription (approbation selon la fonction demandée) ----

export type StatutDemande =
  | "en_attente"
  | "approuvee"
  | "refusee"
  | "annulee"
  | "expiree"
  | "compte_cree"
  | "introuvable";

export type NiveauApprobation = "chef" | "central" | "superadmin";

// Le superadmin n'est jamais nommé côté inscription : il est présenté comme l'administration centrale.
export const NIVEAU_APPROBATION_LABELS: Record<NiveauApprobation, string> = {
  chef: "Chef de centre",
  central: "Administration centrale",
  superadmin: "Administration centrale",
};

export interface DemandeInscription {
  id: number;
  nom: string;
  email: string;
  im: string | null;
  telephone: string | null;
  adresse: string | null;
  centre: { id: number; nom: string } | null;
  fonction: { id: number; nom: string } | null;
  statut: StatutDemande;
  niveau_approbation: NiveauApprobation | null;
  motif_refus: string | null;
  traite_par: string | null;
  traite_le: string | null;
  otp_envoye_le: string | null;
  created_at: string | null;
  expires_at: string | null;
}
