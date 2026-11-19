import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import {
  NIVEAU_LABELS,
  REGLE_LABELS,
  STATUT_LABELS,
  TYPES_IMPOT,
  type Centre,
  type DefaillanceSummary,
  type DossierAlerte,
  type GroupeAlerte,
  type NiveauRisque,
  type Paginated,
  type Regle,
  type StatutAlerte,
} from "../types";
import {
  ConfirmDialog,
  ErrorBox,
  FormActions,
  IconButton,
  Modal,
  inputClass,
  primaryButtonClass,
  secondaryButtonClass,
} from "../components/ui";
import {
  IconChevron,
  IconClock,
  IconEdit,
  IconEye,
  IconFile,
  IconRefresh,
  IconSearch,
  IconSliders,
  IconUpload,
  IconUserPlus,
} from "../components/icons";
import SearchSelect from "../components/SearchSelect";
import {
  AnomaliesDossierModal,
  ContribuableModal,
  NiveauBadge,
  RegleBadge,
  StatutBadge,
  formatDate,
  formatDateHeure,
} from "../components/defaillance";

// ---------------------------------------------------------------------
//  Petits utilitaires
// ---------------------------------------------------------------------

/** Rafraîchissement périodique de la liste (les dossiers peuvent être pris/traités par un autre agent). */
const REFRESH_MS = 60_000;

function pageWindow(current: number, last: number): number[] {
  const size = Math.min(5, last);
  const start = Math.max(1, Math.min(current - 2, last - size + 1));
  return Array.from({ length: size }, (_, i) => start + i);
}

interface Filters {
  regle: "" | Regle;
  niveau: "" | NiveauRisque;
  type_impot: string;
  statut: "" | StatutAlerte;
  annee_fiscale: string;
  /** Intervalle d'exercices (résultats d'une analyse manuelle sur plusieurs années). */
  annee_min: string;
  annee_max: string;
  centre_id: string;
  /** Dossiers créés depuis moins de N jours ("" = sans limite). */
  age_min: string;
}

/** Automatique : date du jour et fenêtre d'exercices enregistrée pour chaque règle. Manuelle : année ou intervalle ciblé. */
type ModeAnalyse = "automatique" | "manuelle";

/** Contribuable proposé dans la recherche de l'analyse ciblée. */
interface ContribuableCible {
  id: number;
  nif: string;
  nom: string;
  centre: string | null;
}

/** Années proposées au lancement manuel : l'année en cours et les cinq précédentes (maximum de la fenêtre). */
const ANNEES_ANALYSE: number[] = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);

/** Cas concrets affichés dans la fenêtre d'analyse pour chaque critère de risque. */
const EXEMPLES_CRITERES: { titre: string; exemple: string }[] = [
  { titre: "R4 · Défaut de dépôt", exemple: "TVA non déposée de mars à décembre 2025 (10 mois) → score 4/5, risque moyen." },
  { titre: "Baisse du CA", exemple: "CA TVA de 80 M en mars 2025 contre 120 M en mars 2024 → baisse de 33 %." },
  { titre: "R1 · CA IR / CA TVA", exemple: "CA IR 500 M contre CA TVA 300 M → écart de 40 % → score 4/10, risque moyen." },
  { titre: "R2 · Déficit chronique", exemple: "Résultat IR négatif en 2023, 2024 et 2025 → score 5/5, risque élevé." },
  { titre: "R3 · Crédit de TVA", exemple: "Crédit de TVA non nul de janvier à juin 2025 → 6 mois → score 5/5." },
  { titre: "R5 · Recoupement", exemple: "CA recoupé 400 M contre CA TVA 250 M → 60 % d'écart → score 5/5." },
  { titre: "R6 · Importations", exemple: "TVA des DAU IM4 50 M contre TVA déductible importée 20 M → score 6/10." },
  { titre: "R7 · Exportations", exemple: "Valeur CAF des DAU EX1 200 M contre CA export déclaré 120 M → score 4/10." },
];

/** Agent du centre susceptible de recevoir un dossier. */
interface AgentOption {
  id: number;
  nom: string;
  fonction: string | null;
}

/** Initiales affichées dans la pastille ronde d'un agent. */
function initialesAgent(nom: string): string {
  return nom
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((mot) => mot[0]?.toUpperCase() ?? "")
    .join("");
}

type SortField = "niveau" | "type_impot" | "annee_fiscale" | "cree_le";

/** Nombre de jours entiers depuis une date ISO (0 = aujourd'hui). */
function joursDepuis(iso: string): number {
  const debut = new Date(iso).getTime();
  if (Number.isNaN(debut)) return 0;
  return Math.max(0, Math.floor((Date.now() - debut) / 86_400_000));
}

/** Ancienneté lisible : « Aujourd'hui », « 1 j », « 45 j ». */
function ageAffiche(iso: string | null): string {
  if (!iso) return "—";
  const jours = joursDepuis(iso);
  return jours === 0 ? "Aujourd'hui" : `${jours.toLocaleString("fr-FR")} j`;
}


// Contrôles de filtre compacts (même hauteur, largeur selon le contenu)
const filterInput =
  "rounded-lg border border-line bg-white px-2 py-1.5 text-xs text-slate-900 placeholder-slate-400 shadow-sm " +
  "focus:outline-none focus:border-brand-azure focus:ring-4 focus:ring-brand-azure/10 transition " +
  "disabled:bg-slate-50 disabled:text-slate-500";

// Libellé au-dessus de chaque contrôle de filtre
const filterLabel = "mb-1 block text-[11px] font-semibold uppercase tracking-wider text-slate-500";

// ---------------------------------------------------------------------
//  Page
// ---------------------------------------------------------------------

export default function DefaillancesPage() {
  const { user } = useAuth();
  const role = user?.role ?? null;
  const estAgent = role === "agent";
  const canManage = role === "superadmin" || role === "central" || role === "admin";
  const canPickCentre = role === "superadmin" || role === "central";
  const [searchParams] = useSearchParams();

  const [data, setData] = useState<Paginated<GroupeAlerte> | null>(null);
  const [summary, setSummary] = useState<DefaillanceSummary | null>(null);
  const [centres, setCentres] = useState<Centre[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string>("");
  const [reloadKey, setReloadKey] = useState<number>(0);
  const silentRef = useRef<boolean>(false); // true = rechargement automatique (sans voile de chargement)
  const [ficheId, setFicheId] = useState<number | null>(null);

  // Détail des anomalies d'un dossier (consultation avant affectation notamment)
  const [voirGroupe, setVoirGroupe] = useState<GroupeAlerte | null>(null);
  const [voirId, setVoirId] = useState<number | null>(null);
  const [voirErreur, setVoirErreur] = useState<string>("");

  const [filters, setFilters] = useState<Filters>(() => {
    // Lien direct depuis le comparatif des centres : ?centre_id=12
    const centre = searchParams.get("centre_id") ?? "";

    return {
      regle: "",
      niveau: "",
      type_impot: "",
      statut: "a_traiter",
      annee_fiscale: "",
      annee_min: "",
      annee_max: "",
      centre_id: canPickCentre && /^\d+$/.test(centre) ? centre : "",
      age_min: "",
    };
  });
  const [searchInput, setSearchInput] = useState<string>("");
  const [filtresOuverts, setFiltresOuverts] = useState<boolean>(false);
  const [search, setSearch] = useState<string>("");
  const [sort, setSort] = useState<SortField>("niveau");
  const [dir, setDir] = useState<"asc" | "desc">("desc");
  const [page, setPage] = useState<number>(1);

  // Traitement d'une alerte (EXF-008) : « Traiter » prend le dossier puis ouvre la page de traitement
  const navigate = useNavigate();
  const [priseId, setPriseId] = useState<number | null>(null);
  const [priseErreur, setPriseErreur] = useState<string>("");
  const [aReprendre, setAReprendre] = useState<GroupeAlerte | null>(null);

  // Affectation nominative d'un dossier libre (chefs de centre, central, superadmin)
  const [aAssigner, setAAssigner] = useState<GroupeAlerte | null>(null);
  const [agents, setAgents] = useState<AgentOption[] | null>(null); // null = chargement
  const [agentId, setAgentId] = useState<string>("");
  const [agentQuery, setAgentQuery] = useState<string>("");
  const [assignErreur, setAssignErreur] = useState<string>("");
  const [assigning, setAssigning] = useState<boolean>(false);

  // Lancer l'analyse (manuelle par défaut : l'utilisateur choisit l'année ou l'intervalle)
  const [analyseOpen, setAnalyseOpen] = useState<boolean>(false);
  const [modeAnalyse, setModeAnalyse] = useState<ModeAnalyse>("manuelle");
  const [cible, setCible] = useState<"annee" | "intervalle">("annee");
  const [anneeDebut, setAnneeDebut] = useState<string>(String(ANNEES_ANALYSE[1]));
  const [anneeFin, setAnneeFin] = useState<string>(String(ANNEES_ANALYSE[0]));
  const [analyseRunning, setAnalyseRunning] = useState<boolean>(false);
  const [analyseError, setAnalyseError] = useState<string>("");

  // Analyse ciblée facultative : contribuable choisi par nom ou par NIF
  const [contribuableId, setContribuableId] = useState<string>("");
  const [contribuableCible, setContribuableCible] = useState<ContribuableCible | null>(null);
  const [contribuablesTrouves, setContribuablesTrouves] = useState<ContribuableCible[]>([]);
  const [contribuablesLoading, setContribuablesLoading] = useState<boolean>(false);
  const [contribuablesError, setContribuablesError] = useState<string>("");

  // Export CSV
  const [exporting, setExporting] = useState<boolean>(false);
  const [exportError, setExportError] = useState<string>("");

  // Recherche avec un petit délai
  useEffect(() => {
    const t = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(t);
  }, [searchInput]);

  // Liste des centres (filtre réservé à central / superadmin)
  useEffect(() => {
    if (!canPickCentre) return;
    api
      .get<Centre[]>("/centres")
      .then((res) => setCentres(res.data))
      .catch(() => undefined);
  }, [canPickCentre]);

  // Liste des alertes
  useEffect(() => {
    let cancelled = false;
    if (!silentRef.current) setLoading(true);
    silentRef.current = false;

    api
      .get<Paginated<GroupeAlerte>>("/defaillances", {
        params: {
          page,
          sort,
          dir,
          grouper: 1,
          search: search || undefined,
          regle: filters.regle || undefined,
          niveau: filters.niveau || undefined,
          type_impot: filters.type_impot || undefined,
          // Un agent ne voit que ses dossiers assignés : le filtre de statut de l'écran ne s'applique pas à lui.
          statut: estAgent ? undefined : filters.statut || undefined,
          annee_fiscale: filters.annee_fiscale || undefined,
          annee_min: filters.annee_min || undefined,
          annee_max: filters.annee_max || undefined,
          centre_id: filters.centre_id || undefined,
          age_min: filters.age_min || undefined,
        },
      })
      .then((res) => {
        if (cancelled) return;
        setData(res.data);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(getErrorMessage(err, "Impossible de charger les alertes."));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [page, sort, dir, search, filters, reloadKey]);

  // Compteurs du haut de page
  useEffect(() => {
    api
      .get<DefaillanceSummary>("/defaillances/summary", {
        params: { centre_id: filters.centre_id || undefined },
      })
      .then((res) => setSummary(res.data))
      .catch(() => undefined);
  }, [filters.centre_id, reloadKey]);

  const reload = useCallback(() => setReloadKey((k) => k + 1), []);

  // Rafraîchissement périodique de la liste (dossiers pris ou traités par un autre agent)
  useEffect(() => {
    const t = setInterval(() => {
      silentRef.current = true;
      setReloadKey((k) => k + 1);
    }, REFRESH_MS);
    return () => clearInterval(t);
  }, []);

  function changeFilter<K extends keyof Filters>(key: K, value: Filters[K]) {
    setFilters((f) => ({ ...f, [key]: value }));
    setPage(1);
  }

  const nbFiltresActifs =
    (searchInput.trim() !== "" ? 1 : 0) +
    (filters.regle !== "" ? 1 : 0) +
    (filters.niveau !== "" ? 1 : 0) +
    (filters.type_impot !== "" ? 1 : 0) +
    (filters.statut !== "a_traiter" ? 1 : 0) +
    (filters.annee_fiscale !== "" ? 1 : 0) +
    (filters.annee_min !== "" || filters.annee_max !== "" ? 1 : 0) +
    (filters.centre_id !== "" ? 1 : 0) +
    (filters.age_min !== "" ? 1 : 0);

  const filtresActifs = nbFiltresActifs > 0;

  // Le panneau se déplie dès qu'un filtre devient actif et se replie quand tout est réinitialisé.
  useEffect(() => {
    setFiltresOuverts(filtresActifs);
  }, [filtresActifs]);

  function resetFilters() {
    setSearchInput("");
    setFilters({ regle: "", niveau: "", type_impot: "", statut: "a_traiter", annee_fiscale: "", annee_min: "", annee_max: "", centre_id: "", age_min: "" });
    setPage(1);
  }

  function toggleSort(field: SortField) {
    if (sort === field) {
      setDir((d) => (d === "asc" ? "desc" : "asc"));
    } else {
      setSort(field);
      setDir(field === "niveau" ? "desc" : "asc");
    }
    setPage(1);
  }

  function sortMark(field: SortField): string {
    if (sort !== field) return "";
    return dir === "asc" ? " ▲" : " ▼";
  }

  function toggleNiveau(niveau: NiveauRisque) {
    setFilters((f) => ({
      ...f,
      niveau: f.niveau === niveau ? "" : niveau,
      statut: "a_traiter",
    }));
    setPage(1);
  }

  // ---- Traitement d'une alerte ----

  /**
   * « Traiter » : prend le dossier (il passe « En traitement » pour les autres utilisateurs) puis ouvre la page de traitement.
   * Dossier déjà traité ou régularisé : simple consultation. Dossier pris par un autre : refusé (sauf reprise par un responsable).
   */
  async function traiter(g: GroupeAlerte, forcer = false) {
    setPriseErreur("");
    const id = g.representant_id;

    if (g.statut === "traitee" || g.statut === "regularisee") {
      navigate(`/defaillances/${id}/traitement`);
      return;
    }

    setPriseId(id);
    try {
      // Le dossier est pris en entier : toutes les anomalies du contribuable passent « En traitement ».
      await api.post(`/defaillances/${id}/prendre`, { groupe: true, ...(forcer ? { forcer: true } : {}) });
      navigate(`/defaillances/${id}/traitement`);
    } catch (err) {
      setPriseErreur(getErrorMessage(err, "Impossible de prendre ce dossier."));
      reload(); // l'état du dossier a changé : la liste est mise à jour
    } finally {
      setPriseId(null);
    }
  }

  // ---- Détail des anomalies ----

  /** Ouvre la fenêtre de détail avec le dossier complet, quels que soient les filtres de la liste. */
  async function voirDetails(g: GroupeAlerte) {
    setVoirErreur("");
    setVoirId(g.representant_id);
    try {
      const res = await api.get<DossierAlerte>(`/defaillances/${g.representant_id}/dossier`);
      setVoirGroupe(res.data.groupe);
    } catch (err) {
      setVoirErreur(getErrorMessage(err, "Impossible de charger le détail du dossier."));
    } finally {
      setVoirId(null);
    }
  }

  // ---- Affectation d'un dossier à un agent ----

  /** Ouvre la fenêtre d'assignation et charge les agents du centre du dossier. */
  function openAssigner(g: GroupeAlerte) {
    setAAssigner(g);
    setAgents(null);
    setAgentId("");
    setAgentQuery("");
    setAssignErreur("");

    api
      .get<AgentOption[]>(`/defaillances/${g.representant_id}/agents`)
      .then((res) => {
        setAgents(res.data);
        if (res.data.length === 1) setAgentId(String(res.data[0].id));
      })
      .catch((err) => {
        setAgents([]);
        setAssignErreur(getErrorMessage(err, "Impossible de charger les agents du centre."));
      });
  }

  async function handleAssigner(e: FormEvent) {
    e.preventDefault();
    if (!aAssigner || !agentId) return;

    setAssigning(true);
    setAssignErreur("");
    try {
      await api.post(`/defaillances/${aAssigner.representant_id}/assigner`, { user_id: Number(agentId) });
      setAAssigner(null);
      reload();
    } catch (err) {
      // Dossier pris entre-temps : la liste est rechargée pour refléter son état.
      setAssignErreur(getErrorMessage(err, "Assignation impossible."));
      reload();
    } finally {
      setAssigning(false);
    }
  }

  // ---- Analyse ----

  /** Recherche distante des contribuables : nom ou NIF, périmètre de l'utilisateur imposé par l'API. */
  const chercherContribuables = useCallback(async (q: string) => {
    setContribuablesLoading(true);
    setContribuablesError("");

    try {
      const res = await api.get<ContribuableCible[]>("/contribuables/recherche", {
        params: { search: q || undefined },
      });
      setContribuablesTrouves(res.data);
    } catch (err) {
      setContribuablesError(getErrorMessage(err, "Recherche des contribuables impossible."));
    } finally {
      setContribuablesLoading(false);
    }
  }, []);

  // Le contribuable déjà choisi reste proposé même si la nouvelle recherche ne le renvoie pas.
  const optionsContribuable = useMemo(() => {
    const base = contribuablesTrouves.map((c) => ({
      value: String(c.id),
      label: c.nom,
      description: `NIF ${c.nif}${c.centre ? ` · ${c.centre}` : ""}`,
    }));

    if (contribuableCible && !base.some((o) => o.value === String(contribuableCible.id))) {
      base.unshift({
        value: String(contribuableCible.id),
        label: contribuableCible.nom,
        description: `NIF ${contribuableCible.nif}${contribuableCible.centre ? ` · ${contribuableCible.centre}` : ""}`,
      });
    }

    return base;
  }, [contribuablesTrouves, contribuableCible]);

  function choisirContribuable(value: string) {
    setContribuableId(value);

    if (value === "") {
      setContribuableCible(null);
      return;
    }

    const choisi =
      contribuablesTrouves.find((c) => String(c.id) === value) ??
      (contribuableCible && String(contribuableCible.id) === value ? contribuableCible : null);

    setContribuableCible(choisi);
  }

  /** L'analyse automatique suit les réglages ; la manuelle exige une année (ou un intervalle) valide. */
  const analyseValide =
    modeAnalyse === "automatique" ||
    (cible === "annee"
      ? anneeDebut !== ""
      : anneeDebut !== "" && anneeFin !== "" && Number(anneeDebut) <= Number(anneeFin));

  function choisirDebut(v: string) {
    setAnneeDebut(v);
    if (cible === "intervalle" && v !== "" && anneeFin !== "" && Number(v) > Number(anneeFin)) setAnneeFin(v);
  }

  function choisirFin(v: string) {
    setAnneeFin(v);
    if (v !== "" && anneeDebut !== "" && Number(v) < Number(anneeDebut)) setAnneeDebut(v);
  }

  async function handleAnalyse(e: FormEvent) {
    e.preventDefault();
    if (!analyseValide) return;
    setAnalyseRunning(true);
    setAnalyseError("");

    try {
      if (modeAnalyse === "automatique") {
        await api.post("/defaillances/analyser", {
          mode: "automatique",
          contribuable_id: contribuableCible?.id,
        });
        // Date du jour et fenêtre de chaque règle : aucun filtre d'exercice.
        setFilters((f) => ({
          ...f,
          statut: "a_traiter",
          regle: "",
          niveau: "",
          type_impot: "",
          annee_fiscale: "",
          annee_min: "",
          annee_max: "",
          centre_id: "",
          age_min: "",
        }));
      } else {
        const debut = Number(anneeDebut);
        const fin = cible === "annee" ? debut : Number(anneeFin);

        await api.post("/defaillances/analyser", {
          mode: "manuelle",
          annee_debut: debut,
          annee_fin: fin,
          contribuable_id: contribuableCible?.id,
        });
        // Le tableau ne montre que les exercices analysés : une année précise, ou l'intervalle choisi.
        setFilters((f) => ({
          ...f,
          statut: "a_traiter",
          regle: "",
          niveau: "",
          type_impot: "",
          annee_fiscale: cible === "annee" ? String(debut) : "",
          annee_min: cible === "annee" ? "" : String(debut),
          annee_max: cible === "annee" ? "" : String(fin),
          centre_id: "",
          age_min: "",
        }));
      }
      setPage(1);
      // Analyse ciblée : le tableau ne montre que le dossier du contribuable choisi.
      if (contribuableCible) {
        setSearchInput(contribuableCible.nif);
        setSearch(contribuableCible.nif);
      }
      setAnalyseOpen(false); // les résultats s'affichent directement dans le tableau des anomalies
      reload();
      window.dispatchEvent(new Event("notifications:maj"));
    } catch (err) {
      setAnalyseError(getErrorMessage(err, "L'analyse a échoué."));
    } finally {
      setAnalyseRunning(false);
    }
  }

  // ---- Export CSV de la liste filtrée ----

  async function exporter() {
    setExporting(true);
    setExportError("");

    try {
      // Mêmes filtres que la liste : l'export contient tous les dossiers correspondants, pas seulement la page affichée.
      const res = await api.get("/defaillances/export", {
        params: {
          search: search || undefined,
          regle: filters.regle || undefined,
          niveau: filters.niveau || undefined,
          type_impot: filters.type_impot || undefined,
          // Un agent ne voit que ses dossiers assignés : le filtre de statut de l'écran ne s'applique pas à lui.
          statut: estAgent ? undefined : filters.statut || undefined,
          annee_fiscale: filters.annee_fiscale || undefined,
          annee_min: filters.annee_min || undefined,
          annee_max: filters.annee_max || undefined,
          centre_id: filters.centre_id || undefined,
          age_min: filters.age_min || undefined,
        },
        responseType: "blob",
      });

      const url = URL.createObjectURL(res.data as Blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `dossiers-defaillances-${new Date().toISOString().slice(0, 10)}.csv`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      setExportError("Export impossible. Réessayez dans un instant.");
    } finally {
      setExporting(false);
    }
  }

  const from = data && data.total > 0 ? (data.current_page - 1) * 10 + 1 : 0;
  const to = data ? Math.min(data.current_page * 10, data.total) : 0;

  const cards: { niveau: NiveauRisque; value: number | undefined }[] = [
    { niveau: "eleve", value: summary?.eleve },
    { niveau: "moyen", value: summary?.moyen },
    { niveau: "faible", value: summary?.faible },
  ];

  // Fenêtre d'assignation : recherche interne et agent sélectionné
  const agentQueryNorm = agentQuery.trim().toLowerCase();
  const agentsFiltres = (agents ?? []).filter(
    (a) =>
      agentQueryNorm === "" ||
      a.nom.toLowerCase().includes(agentQueryNorm) ||
      (a.fonction ?? "").toLowerCase().includes(agentQueryNorm),
  );
  const agentChoisi = (agents ?? []).find((a) => String(a.id) === agentId) ?? null;

  return (
    <div>
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-xl font-semibold tracking-tight text-brand-blue">Anomalies de déclaration</h1>
        </div>

        <div className="flex flex-wrap gap-2">
          {canManage && (
            <Link to="/admin/activites-anomalies" className={`${secondaryButtonClass} inline-flex items-center gap-2`}>
              <IconClock className="h-4 w-4" /> Suivi des activités
            </Link>
          )}
          <button
            type="button"
            className={`${secondaryButtonClass} inline-flex items-center gap-2`}
            onClick={() => void exporter()}
            disabled={exporting}
            title="Exporter en CSV les dossiers correspondant aux filtres actuels"
          >
            <IconUpload className="h-4 w-4" /> {exporting ? "Export..." : "Exporter CSV"}
          </button>

          {canManage && (
            <button
              type="button"
              className={`${primaryButtonClass} inline-flex items-center gap-2`}
              onClick={() => {
                setAnalyseError("");
                setAnalyseOpen(true);
              }}
            >
              <IconRefresh className="h-4 w-4" /> Lancer l'analyse
            </button>
          )}
        </div>
      </div>

      {/* Cartes : dossiers à traiter et alertes par niveau de risque (vues de pilotage) */}
      {!estAgent && (
        <div className="grid gap-2 sm:grid-cols-4">
          <div className="rounded-xl border border-line border-l-4 border-l-brand-blue bg-white p-3.5 shadow-sm">
            <p className="text-xs text-slate-500">Dossiers à traiter</p>
            <p className="text-2xl font-semibold tabular-nums text-brand-blue">{summary ? summary.dossiers_a_traiter : "—"}</p>
            {summary && (
              <p className="text-xs text-slate-500">
                {summary.a_traiter} alerte{summary.a_traiter > 1 ? "s" : ""}
                {summary.dossiers_en_traitement > 0 ? ` · ${summary.dossiers_en_traitement} en traitement` : ""}
              </p>
            )}
          </div>
          {cards.map((c) => {
            const bord =
              c.niveau === "eleve"
                ? "border-l-red-500"
                : c.niveau === "moyen"
                  ? "border-l-amber-500"
                  : "border-l-emerald-500";

            return (
              <button
                key={c.niveau}
                type="button"
                onClick={() => toggleNiveau(c.niveau)}
                aria-pressed={filters.niveau === c.niveau}
                className={`rounded-xl border border-line border-l-4 bg-white p-3.5 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md ${bord} ${
                  filters.niveau === c.niveau ? "ring-2 ring-brand-azure/50" : ""
                }`}
              >
                <p className="text-xs text-slate-500">Alertes · risque {NIVEAU_LABELS[c.niveau].toLowerCase()}</p>
                <p className="flex items-center gap-2 text-2xl font-semibold tabular-nums text-brand-blue">
                  {c.value ?? "—"}
                  <NiveauBadge niveau={c.niveau} />
                </p>
              </button>
            );
          })}
        </div>
      )}

      <section className="mt-4 rounded-xl bg-white p-4 border border-line sm:p-5">
        {/* Filtres : panneau repliable, déplié automatiquement quand un filtre est actif */}
        <div className="mb-2 flex items-center justify-between gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={() => setFiltresOuverts((o) => !o)}
              aria-expanded={filtresOuverts}
              className="inline-flex items-center gap-1.5 rounded-lg border border-line bg-white px-2.5 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-600 shadow-sm transition hover:border-brand-azure/40 hover:text-brand-azure"
            >
              <IconSliders className="h-3.5 w-3.5" />
              Filtres
              {nbFiltresActifs > 0 && (
                <span className="rounded-full bg-brand-blue px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">
                  {nbFiltresActifs}
                </span>
              )}
              <IconChevron
                className={`h-3.5 w-3.5 transition-transform duration-200 ${filtresOuverts ? "rotate-90" : ""}`}
              />
            </button>
            {(filters.annee_min !== "" || filters.annee_max !== "") && (
              <span className="inline-flex items-center gap-1.5 rounded-lg border border-brand-azure/30 bg-brand-azure-soft px-2 py-1 text-[11px] font-medium text-brand-blue">
                Exercices {filters.annee_min || "…"} à {filters.annee_max || "…"}
                <button
                  type="button"
                  aria-label="Retirer le filtre d'exercices"
                  title="Retirer le filtre d'exercices"
                  onClick={() => setFilters((f) => ({ ...f, annee_min: "", annee_max: "" }))}
                  className="rounded px-0.5 leading-none hover:bg-white/70"
                >
                  ×
                </button>
              </span>
            )}
          </div>
          {filtresActifs && (
            <button
              type="button"
              onClick={resetFilters}
              title="Réinitialiser les filtres"
              className="inline-flex items-center gap-1.5 rounded-lg border border-line bg-white px-2 py-1 text-xs text-slate-600 shadow-sm transition hover:border-brand-azure/40 hover:text-brand-azure"
            >
              <IconRefresh className="h-3.5 w-3.5" /> Réinitialiser
            </button>
          )}
        </div>

        {filtresOuverts && (
        <div role="group" aria-label="Filtres des alertes" className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
          <label className="block sm:col-span-2 xl:col-span-1">
            <span className={filterLabel}>Recherche</span>
            <div className="relative">
              <IconSearch className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                placeholder="Nom ou NIF..."
                aria-label="Rechercher un contribuable"
                className={`${filterInput} w-full pl-8`}
              />
            </div>
          </label>

          <label className="block">
            <span className={filterLabel}>Anomalie</span>
            <select
              aria-label="Type d'anomalie"
              className={`${filterInput} w-full`}
              value={filters.regle}
              onChange={(e) => changeFilter("regle", e.target.value as Filters["regle"])}
            >
              <option value="">Toutes les anomalies</option>
              {(Object.keys(REGLE_LABELS) as Regle[]).map((r) => (
                <option key={r} value={r}>
                  {REGLE_LABELS[r]}
                </option>
              ))}
            </select>
          </label>

          <label className="block">
            <span className={filterLabel}>Niveau</span>
            <select
              aria-label="Niveau de risque"
              className={`${filterInput} w-full`}
              value={filters.niveau}
              onChange={(e) => changeFilter("niveau", e.target.value as Filters["niveau"])}
            >
              <option value="">Tous les niveaux</option>
              {(Object.keys(NIVEAU_LABELS) as NiveauRisque[]).map((n) => (
                <option key={n} value={n}>
                  {NIVEAU_LABELS[n]}
                </option>
              ))}
            </select>
          </label>

          <label className="block">
            <span className={filterLabel}>Impôt</span>
            <select
              aria-label="Type d'impôt"
              className={`${filterInput} w-full`}
              value={filters.type_impot}
              onChange={(e) => changeFilter("type_impot", e.target.value)}
            >
              <option value="">Tous les impôts</option>
              {TYPES_IMPOT.map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </select>
          </label>

          {!estAgent && (
            <label className="block">
              <span className={filterLabel}>Statut</span>
              <select
                aria-label="Statut"
                className={`${filterInput} w-full`}
                value={filters.statut}
                onChange={(e) => changeFilter("statut", e.target.value as Filters["statut"])}
              >
                <option value="">Tous les statuts</option>
                {/* Les dossiers clôturés (toutes anomalies traitées/régularisées) sont dans Archives */}
                {(Object.keys(STATUT_LABELS) as StatutAlerte[])
                  .filter((s) => s === "a_traiter" || s === "en_traitement")
                  .map((s) => (
                    <option key={s} value={s}>
                      {STATUT_LABELS[s]}
                    </option>
                  ))}
              </select>
            </label>
          )}

          {/* Exercices réellement présents, le plus récent en tête */}
          <label className="block">
            <span className={filterLabel}>Exercice</span>
            <select
              aria-label="Exercice"
              title="Exercice (année fiscale) de l'anomalie"
              className={`${filterInput} w-full`}
              value={filters.annee_fiscale}
              onChange={(e) => changeFilter("annee_fiscale", e.target.value)}
            >
              <option value="">Tous les exercices</option>
              {(summary?.exercices ?? []).map((annee) => (
                <option key={annee} value={String(annee)}>
                  {annee}
                </option>
              ))}
              {/* Année analysée sans aucune anomalie : le filtre reste lisible dans la liste. */}
              {filters.annee_fiscale !== "" &&
                !(summary?.exercices ?? []).some((annee) => String(annee) === filters.annee_fiscale) && (
                  <option value={filters.annee_fiscale}>{filters.annee_fiscale}</option>
                )}
            </select>
          </label>

          <label className="block">
            <span className={filterLabel}>Ancienneté</span>
            <select
              aria-label="Ancienneté du dossier"
              title="Dossiers dont la plus ancienne anomalie date de moins de N jours"
              className={`${filterInput} w-full`}
              value={filters.age_min}
              onChange={(e) => changeFilter("age_min", e.target.value)}
            >
              <option value="">Toutes les anciennetés</option>
              <option value="7">Créés depuis moins de 7 j</option>
              <option value="30">Créés depuis moins de 30 j</option>
              <option value="90">Créés depuis moins de 90 j</option>
            </select>
          </label>

          {canPickCentre && (
            <label className="block">
              <span className={filterLabel}>Centre</span>
              <SearchSelect
                options={centres.map((c) => ({
                  value: String(c.id),
                  label: c.nom,
                  description: c.adresse,
                }))}
                value={filters.centre_id}
                onChange={(v) => changeFilter("centre_id", v)}
                className={`${filterInput} w-full`}
                label="Centre"
                clearLabel="Tous les centres"
                emptyLabel="Aucun centre trouvé"
              />
            </label>
          )}
        </div>
        )}

        <ErrorBox message={error} />
        <ErrorBox message={priseErreur} />
        <ErrorBox message={exportError} />
        <ErrorBox message={voirErreur} />

        <div className="overflow-x-auto">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead>
              <tr className="bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                <th className="px-2 py-2">Contribuable</th>
                <th className="px-2 py-2">Anomalie</th>
                <th className="px-2 py-2">
                  <button type="button" onClick={() => toggleSort("type_impot")} className="hover:text-brand-azure">
                    Impôt{sortMark("type_impot")}
                  </button>
                </th>
                <th className="px-2 py-2">
                  <button type="button" onClick={() => toggleSort("annee_fiscale")} className="hover:text-brand-azure">
                    Exercice / période{sortMark("annee_fiscale")}
                  </button>
                </th>
                <th className="px-2 py-2">
                  <button type="button" onClick={() => toggleSort("niveau")} className="hover:text-brand-azure">
                    Risque{sortMark("niveau")}
                  </button>
                </th>
                <th className="px-2 py-2">Motif</th>
                <th className="px-2 py-2">
                  <button
                    type="button"
                    onClick={() => toggleSort("cree_le")}
                    title="Date de la plus ancienne anomalie du dossier"
                    className="hover:text-brand-azure"
                  >
                    Âge{sortMark("cree_le")}
                  </button>
                </th>
                <th className="px-2 py-2">Statut</th>
                <th className="sticky right-0 z-10 border-l border-line bg-slate-50 px-2 py-2 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className={loading ? "opacity-50 transition" : "transition"}>
              {data?.data.map((g) => {
                const principal = g.alertes[0];
                const motifs = g.alertes.map((a) => `${REGLE_LABELS[a.regle] ?? a.regle} — ${a.motif}`).join(" ");

                return (
                  <tr key={g.contribuable.id} className="group border-t border-line align-top hover:bg-slate-50">
                    <td className="px-2 py-2.5">
                      <button
                        type="button"
                        onClick={() => setFicheId(g.contribuable.id)}
                        className="text-left font-medium text-brand-blue underline-offset-2 hover:underline"
                        title="Voir la fiche du contribuable"
                      >
                        {g.contribuable.nom}
                      </button>
                      <p className="text-xs text-slate-500">
                        {g.contribuable.nif}
                        {canPickCentre && g.contribuable.centre ? ` · ${g.contribuable.centre}` : ""}
                      </p>
                    </td>
                    <td className="px-2 py-2.5">
                      <div className="flex max-w-[230px] flex-wrap gap-1">
                        {g.alertes.map((a) => (
                          <RegleBadge key={a.id} regle={a.regle} court />
                        ))}
                      </div>
                      <p className="mt-0.5 text-xs text-slate-500">
                        {g.nb_alertes} anomalie{g.nb_alertes > 1 ? "s" : ""}
                      </p>
                    </td>
                    <td className="px-2 py-2.5 font-medium text-slate-700">{g.types_impot.join(" / ")}</td>
                    <td className="px-2 py-2.5 text-slate-700">{g.annees.join(", ")}</td>
                    <td className="px-2 py-2.5">
                      <NiveauBadge niveau={g.niveau} />
                    </td>
                    <td className="px-2 py-2.5">
                      <div className="line-clamp-3 max-w-[260px] text-xs leading-snug text-slate-700" title={motifs}>
                        <span className="font-medium">{REGLE_LABELS[principal.regle] ?? principal.regle}</span> — {principal.motif}
                      </div>
                      {g.nb_alertes > 1 && (
                        <p className="mt-0.5 text-[12px] text-slate-500">
                          + {g.nb_alertes - 1} autre{g.nb_alertes > 2 ? "s" : ""} anomalie{g.nb_alertes > 2 ? "s" : ""}
                        </p>
                      )}
                    </td>
                    <td className="px-2 py-2.5 whitespace-nowrap">
                      {g.cree_le ? (
                        <span
                          className={
                            joursDepuis(g.cree_le) >= 30 && (g.statut === "a_traiter" || g.statut === "en_traitement")
                              ? "font-medium text-amber-700"
                              : "text-slate-700"
                          }
                          title={`Plus ancienne anomalie du dossier : ${formatDate(g.cree_le)}`}
                        >
                          {ageAffiche(g.cree_le)}
                        </span>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                    <td className="px-2 py-2.5">
                      <div className="flex items-center gap-1.5 whitespace-nowrap">
                        <StatutBadge statut={g.statut} />
                        {g.statut === "traitee" && g.traite_par && (
                          <span
                            className="min-w-0 max-w-[150px] truncate text-[10px] leading-4 text-slate-500"
                            title={`Traité par ${g.traite_par}${g.traite_le ? ` le ${formatDate(g.traite_le)}` : ""}`}
                          >
                            par {g.traite_par}
                            {g.traite_le ? ` · ${formatDate(g.traite_le)}` : ""}
                          </span>
                        )}
                        {g.statut === "en_traitement" && g.pris_par && (
                          <span
                            className="min-w-0 max-w-[150px] truncate text-[10px] leading-4 text-slate-500"
                            title={`Pris par ${g.pris_par_moi ? "vous" : g.pris_par}${g.pris_le ? ` depuis ${formatDateHeure(g.pris_le)}` : ""}`}
                          >
                            par {g.pris_par_moi ? "vous" : g.pris_par}
                            {g.pris_le ? ` · ${formatDateHeure(g.pris_le)}` : ""}
                          </span>
                        )}
                      </div>
                      {g.commentaire && (
                        <p className="mt-0.5 line-clamp-1 max-w-[180px] break-words text-[10px] italic leading-4 text-slate-500" title={g.commentaire}>
                          {g.commentaire}
                        </p>
                      )}
                    </td>
                    <td className="sticky right-0 z-[1] border-l border-line bg-white px-2 py-2 text-right group-hover:bg-slate-50">
                      {g.statut === "en_traitement" && !g.pris_par_moi ? (
                        <div className="flex flex-col items-end gap-1.5">
                          <span className="rounded border border-line bg-slate-50 px-2 py-1 text-[12px] font-medium text-slate-500" title={`Dossier pris par ${g.pris_par ?? "un autre utilisateur"}`}>
                            En traitement
                          </span>
                          <div className="flex items-center gap-1">
                            <IconButton
                              label="Voir le détail des anomalies"
                              disabled={voirId === g.representant_id}
                              onClick={() => void voirDetails(g)}
                            >
                              <IconEye className="h-4 w-4" />
                            </IconButton>
                            {canManage && (
                              <IconButton
                                label="Reprendre le dossier"
                                disabled={priseId === g.representant_id}
                                onClick={() => setAReprendre(g)}
                              >
                                <IconRefresh className="h-4 w-4" />
                              </IconButton>
                            )}
                          </div>
                        </div>
                      ) : (
                        <div className="flex items-center justify-end gap-1">
                          <IconButton
                            tone="primary"
                            label={
                              g.statut === "en_traitement"
                                ? "Continuer le traitement"
                                : g.statut === "a_traiter"
                                  ? "Traiter le dossier"
                                  : "Ouvrir le dossier traité"
                            }
                            disabled={priseId === g.representant_id}
                            onClick={() => void traiter(g)}
                          >
                            {priseId === g.representant_id ? (
                              <IconRefresh className="h-4 w-4 animate-spin" />
                            ) : g.statut === "a_traiter" || g.statut === "en_traitement" ? (
                              <IconEdit className="h-4 w-4" />
                            ) : (
                              <IconFile className="h-4 w-4" />
                            )}
                          </IconButton>
                          <IconButton
                            label="Voir le détail des anomalies"
                            disabled={voirId === g.representant_id}
                            onClick={() => void voirDetails(g)}
                          >
                            <IconEye className="h-4 w-4" />
                          </IconButton>
                          {g.statut === "a_traiter" && canManage && (
                            <IconButton
                              label="Assigner le dossier à un agent"
                              disabled={priseId === g.representant_id}
                              onClick={() => openAssigner(g)}
                            >
                              <IconUserPlus className="h-4 w-4" />
                            </IconButton>
                          )}
                        </div>
                      )}
                    </td>
                  </tr>
                );
              })}
              {data && data.data.length === 0 && (
                <tr>
                  <td colSpan={9} className="px-2 py-8 text-center text-slate-500">
                    {estAgent
                      ? "Aucun dossier ne vous est assigné. Votre chef de centre peut vous en confier un."
                      : canManage
                        ? "Aucune alerte. Lancez l'analyse pour détecter les défaillances."
                        : "Aucune anomalie pour votre centre."}
                  </td>
                </tr>
              )}
              {!data && loading && (
                <tr>
                  <td colSpan={9} className="px-2 py-8 text-center text-slate-500">
                    Chargement...
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        {data && data.total > 0 && (
          <div className="mt-3 flex flex-col gap-2 border-t border-line pt-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-xs text-slate-500">
              Affichage de {from} à {to} sur {data.total.toLocaleString("fr-FR")} contribuables
            </p>
            <div className="flex items-center gap-1">
              <button
                type="button"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={data.current_page <= 1}
                aria-label="Page précédente"
                className="rounded-md px-2 py-1 text-sm text-slate-500 hover:bg-slate-100 disabled:opacity-40"
              >
                ‹
              </button>
              {pageWindow(data.current_page, data.last_page).map((n) => (
                <button
                  key={n}
                  type="button"
                  onClick={() => setPage(n)}
                  aria-current={n === data.current_page ? "page" : undefined}
                  className={`h-7 min-w-7 rounded-md px-2 text-sm transition ${
                    n === data.current_page ? "bg-brand-blue font-semibold text-white" : "text-slate-600 hover:bg-slate-100"
                  }`}
                >
                  {n}
                </button>
              ))}
              <button
                type="button"
                onClick={() => setPage((p) => Math.min(data.last_page, p + 1))}
                disabled={data.current_page >= data.last_page}
                aria-label="Page suivante"
                className="rounded-md px-2 py-1 text-sm text-slate-500 hover:bg-slate-100 disabled:opacity-40"
              >
                ›
              </button>
            </div>
          </div>
        )}
      </section>

      {/* Lancer l'analyse */}
      {analyseOpen && (
        <Modal title="Lancer l'analyse" onClose={() => setAnalyseOpen(false)}>
          <form onSubmit={handleAnalyse} className="space-y-4">
            <p className="text-sm text-slate-600">
              Choisissez une analyse <strong>manuelle</strong> pour cibler une année ou un intervalle d'années, ou une
              analyse <strong>automatique</strong> (date du jour et fenêtre d'exercices enregistrée pour chaque règle).
              Chaque contribuable concerné forme un seul dossier, quel que soit le nombre d'anomalies ; en manuel, le
              tableau n'affiche que les exercices analysés. Vous pouvez aussi limiter l'analyse à un seul contribuable,
              retrouvé par son nom ou son NIF.
              {role ==="admin"? " L'analyse porte sur votre centre." : " L'analyse porte sur tous les centres."}
            </p>

            <fieldset>
              <legend className="mb-2 text-sm font-medium text-slate-800">Mode d'analyse</legend>
              <div role="radiogroup" aria-label="Mode d'analyse" className="grid gap-2 sm:grid-cols-2">

              <label
                  className={`flex cursor-pointer gap-2.5 rounded-xl border px-3 py-2.5 transition ${
                    modeAnalyse === "manuelle"
                      ? "border-brand-azure bg-brand-azure-soft ring-1 ring-brand-azure/40"
                      : "border-line bg-white hover:border-brand-azure/40 hover:bg-slate-50"
                  }`}
                >
                  <input
                    type="radio"
                    name="mode-analyse"
                    checked={modeAnalyse === "manuelle"}
                    onChange={() => setModeAnalyse("manuelle")}
                    className="mt-0.5 h-4 w-4 accent-brand-blue"
                  />
                  <span className="min-w-0">
                    <span className="block text-sm font-semibold text-slate-900">Manuelle</span>
                    <span className="block text-xs leading-snug text-slate-600">
                      Cible une année précise ou un intervalle (six années au plus).
                    </span>
                  </span>
                </label>

                <label
                  className={`flex cursor-pointer gap-2.5 rounded-xl border px-3 py-2.5 transition ${
                    modeAnalyse === "automatique"
                      ? "border-brand-azure bg-brand-azure-soft ring-1 ring-brand-azure/40"
                      : "border-line bg-white hover:border-brand-azure/40 hover:bg-slate-50"
                  }`}
                >
                  <input
                    type="radio"
                    name="mode-analyse"
                    checked={modeAnalyse === "automatique"}
                    onChange={() => setModeAnalyse("automatique")}
                    className="mt-0.5 h-4 w-4 accent-brand-blue"
                  />
                  <span className="min-w-0">
                    <span className="block text-sm font-semibold text-slate-900">Automatique</span>
                    <span className="block text-xs leading-snug text-slate-600">
                      Date du jour et fenêtre d'exercices enregistrée pour chaque règle.
                    </span>
                  </span>
                </label>
                
              </div>
            </fieldset>

            <div className="rounded-xl border border-line bg-white p-3.5">
              <label className="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                Contribuable ciblé (facultatif)
              </label>
              <SearchSelect
                options={optionsContribuable}
                value={contribuableId}
                onChange={choisirContribuable}
                onSearch={chercherContribuables}
                loading={contribuablesLoading}
                className={inputClass}
                placeholder="Nom ou NIF..."
                clearLabel="Tous les contribuables (aucune cible)"
                emptyLabel="Aucun contribuable trouvé"
                label="Rechercher un contribuable par nom ou par NIF"
              />
              <p className="mt-1.5 text-xs leading-snug text-slate-500">
                {contribuableCible
                  ? `Seul le dossier de ${contribuableCible.nom} (NIF ${contribuableCible.nif}) sera analysé.`
                  : "Laissez vide pour analyser tous les contribuables du périmètre."}
              </p>
              <ErrorBox message={contribuablesError} />
            </div>

            {modeAnalyse === "manuelle" && (
            <div className="rounded-xl border border-line bg-slate-50/70 p-3.5">
                <div role="radiogroup" aria-label="Période ciblée" className="flex flex-wrap gap-x-5 gap-y-2">
                  <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                      type="radio"
                      name="cible-analyse"
                      className="h-4 w-4 accent-brand-blue"
                      checked={cible === "annee"}
                      onChange={() => {
                        setCible("annee");
                        setAnneeFin(anneeDebut);
                      }}
                    />
                    Une année
                  </label>
                  <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                      type="radio"
                      name="cible-analyse"
                      className="h-4 w-4 accent-brand-blue"
                      checked={cible === "intervalle"}
                      onChange={() => {
                        setCible("intervalle");
                        if (Number(anneeFin) < Number(anneeDebut)) setAnneeFin(anneeDebut);
                      }}
                    />
                    Un intervalle d'années
                  </label>
                </div>

                <div className="mt-3 flex flex-wrap items-end gap-3">
                  <label className="block">
                    <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                      {cible === "annee" ? "Année" : "De"}
                    </span>
                    <select
                      aria-label={cible === "annee" ? "Année à analyser" : "Première année de l'intervalle"}
                      className={inputClass}
                      value={anneeDebut}
                      onChange={(e) => choisirDebut(e.target.value)}
                    >
                      {ANNEES_ANALYSE.map((annee) => (
                        <option key={annee} value={annee}>
                          {annee}
                        </option>
                      ))}
                    </select>
                  </label>
                  {cible === "intervalle" && (
                    <label className="block">
                      <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-slate-500">À</span>
                      <select
                        aria-label="Dernière année de l'intervalle"
                        className={inputClass}
                        value={anneeFin}
                        onChange={(e) => choisirFin(e.target.value)}
                      >
                        {ANNEES_ANALYSE.map((annee) => (
                          <option key={annee} value={annee}>
                            {annee}
                          </option>
                        ))}
                      </select>
                    </label>
                  )}
                </div>

                <p className="mt-2 text-xs leading-snug text-slate-500">
                  {cible === "annee"
                    ? `Analyse de l'année ${anneeDebut}.`
                    : `Analyse des années ${anneeDebut} à ${anneeFin} (${Number(anneeFin) - Number(anneeDebut) + 1} exercices).`}{" "}
                  La dernière année est l'année de référence. Un exercice révolu est jugé après sa clôture
                  (dernière échéance de dépôt + 48 h) ; un exercice en cours n'est jugé que sur les échéances
                  déjà dépassées.
                </p>
                {!analyseValide && (
                  <p className="mt-1 text-xs font-medium text-red-600">L'année de début doit précéder l'année de fin.</p>
                )}
              </div>
            )}

            {/*<div className="rounded-xl border border-line bg-slate-50/70 p-3.5">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Exemples par critère de risque</p>
              <dl className="mt-2 grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
                {EXEMPLES_CRITERES.map((x) => (
                  <div key={x.titre} className="text-xs leading-snug text-slate-600">
                    <dt className="font-semibold text-slate-800">{x.titre}</dt>
                    <dd>{x.exemple}</dd>
                  </div>
                ))}
              </dl>
            </div>*/}

            <ErrorBox message={analyseError} />

            <FormActions>
              <button type="button" className={secondaryButtonClass} onClick={() => setAnalyseOpen(false)}>
                Fermer
              </button>
              <button type="submit" className={primaryButtonClass} disabled={analyseRunning || !analyseValide}>
                {analyseRunning ? "Analyse en cours..." : "Lancer l'analyse"}
              </button>
            </FormActions>
          </form>
        </Modal>
      )}

      {/* Affectation d'un dossier à un agent */}
      {aAssigner && (
        <Modal title="Assigner le dossier" onClose={() => setAAssigner(null)}>
          <form onSubmit={handleAssigner} className="space-y-4">
            {/* Rappel du dossier concerné */}
            <div className="rounded-xl border border-line border-l-4 border-l-brand-blue bg-slate-50 p-3.5">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="truncate text-sm font-semibold text-slate-900">{aAssigner.contribuable.nom}</p>
                  <p className="text-xs text-slate-500">
                    NIF {aAssigner.contribuable.nif}
                    {aAssigner.contribuable.centre ? ` · ${aAssigner.contribuable.centre}` : ""}
                  </p>
                </div>
                <NiveauBadge niveau={aAssigner.niveau} />
              </div>
              <div className="mt-2 flex flex-wrap gap-1">
                {aAssigner.alertes.map((a) => (
                  <RegleBadge key={a.id} regle={a.regle} court />
                ))}
              </div>
              <p className="mt-1.5 text-xs text-slate-600">
                {aAssigner.nb_alertes} anomalie{aAssigner.nb_alertes > 1 ? "s" : ""} · {aAssigner.types_impot.join(" / ")} ·
                exercice{aAssigner.annees.length > 1 ? "s" : ""} {aAssigner.annees.join(", ")}
              </p>
            </div>

            <p className="text-sm text-slate-600">
              Le dossier passera « En traitement » au nom de l'agent choisi. Il apparaîtra alors dans sa liste de travail.
            </p>

            <ErrorBox message={assignErreur} />

            {agents === null ? (
              <p className="text-sm text-slate-500">Chargement des agents...</p>
            ) : agents.length === 0 ? (
              <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">
                Aucun agent dans le centre de ce dossier.
              </p>
            ) : (
              <div>
                <div className="mb-1 flex items-center justify-between gap-2">
                  <span className="text-sm font-medium text-slate-700">Agent</span>
                  <span className="text-xs text-slate-500">Agents du même centre que le dossier</span>                </div>

                {agents.length > 1 && (
                  <div className="relative mb-2">
                    <IconSearch className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                      value={agentQuery}
                      onChange={(e) => setAgentQuery(e.target.value)}
                      placeholder="Rechercher un agent..."
                      aria-label="Rechercher un agent"
                      className={`${inputClass} pl-8`}
                    />
                  </div>
                )}

                <div
                  role="radiogroup"
                  aria-label="Agent à qui assigner le dossier"
                  className="max-h-64 space-y-1.5 overflow-y-auto pr-1"
                >
                  {agentsFiltres.map((a) => {
                    const selectionne = String(a.id) === agentId;

                    return (
                      <label
                        key={a.id}
                        className={`flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-2.5 transition ${
                          selectionne
                            ? "border-brand-azure bg-brand-azure-soft ring-1 ring-brand-azure/40"
                            : "border-line bg-white hover:border-brand-azure/40 hover:bg-slate-50"
                        }`}
                      >
                        <input
                          type="radio"
                          name="agent"
                          value={a.id}
                          checked={selectionne}
                          onChange={() => setAgentId(String(a.id))}
                          className="sr-only"
                        />
                        <span
                          className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
                            selectionne ? "bg-gradient-to-br from-brand-blue to-brand-blue-light text-white shadow-sm" : "bg-slate-100 text-slate-600"
                          }`}
                          aria-hidden="true"
                        >
                          {initialesAgent(a.nom)}
                        </span>
                        <span className="min-w-0 flex-1">
                          <span className="block truncate text-sm font-medium text-slate-900">{a.nom}</span>
                          <span className="block truncate text-xs text-slate-500">{a.fonction ?? "Agent"}</span>
                        </span>
                        <span
                          className={`h-4 w-4 shrink-0 rounded-full border ${
                            selectionne ? "border-brand-azure bg-brand-azure" : "border-slate-300"
                          }`}
                          aria-hidden="true"
                        />
                      </label>
                    );
                  })}

                  {agentsFiltres.length === 0 && (
                    <p className="px-1 py-2 text-sm text-slate-500">Aucun agent ne correspond à la recherche.</p>
                  )}
                </div>
              </div>
            )}

            <FormActions>
              <button type="button" className={secondaryButtonClass} onClick={() => setAAssigner(null)}>
                Annuler
              </button>
              <button
                type="submit"
                className={primaryButtonClass}
                disabled={assigning || !agentId || agents === null || agents.length === 0}
              >
                {assigning ? "Assignation..." : agentChoisi ? `Assigner à ${agentChoisi.nom}` : "Assigner"}
              </button>
            </FormActions>
          </form>
        </Modal>
      )}

      {/* Détail des anomalies d'un dossier (consultation, avant affectation) */}
      {voirGroupe && (
        <AnomaliesDossierModal
          groupe={voirGroupe}
          canAssign={canManage}
          onClose={() => setVoirGroupe(null)}
          onAssign={() => {
            const groupe = voirGroupe;
            if (!groupe) return;
            setVoirGroupe(null);
            openAssigner(groupe);
          }}
        />
      )}

      {/* Fiche contribuable (en dernier : s'affiche au-dessus de la fenêtre « Traiter ») */}
      {ficheId !== null && <ContribuableModal id={ficheId} onClose={() => setFicheId(null)} />}

      {aReprendre && (
        <ConfirmDialog
          title="Reprendre le dossier"
          message={`Ce dossier est déjà pris par ${aReprendre.pris_par ?? "un autre utilisateur"}. Le reprendre ?`}
          detail="Il passera « En traitement » à votre nom et l'utilisateur précédent ne pourra plus y saisir de commentaire."
          confirmLabel="Reprendre le dossier"
          loadingLabel="Reprise..."
          tone="primary"
          loading={priseId === aReprendre.representant_id}
          onConfirm={() => {
            const dossier = aReprendre;
            setAReprendre(null);
            void traiter(dossier, true);
          }}
          onCancel={() => setAReprendre(null)}
        />
      )}
    </div>
  );
}
