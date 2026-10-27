import { useCallback, useEffect, useRef, useState, type ChangeEvent, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import {
  NIVEAU_LABELS,
  REGLE_LABELS,
  STATUT_LABELS,
  TYPES_IMPOT,
  type Centre,
  type DefaillanceSummary,
  type GroupeAlerte,
  type NiveauRisque,
  type Paginated,
  type Regle,
  type StatutAlerte,
} from "../types";
import {
  ConfirmDialog,
  ErrorBox,
  Field,
  FormActions,
  Modal,
  inputClass,
  primaryButtonClass,
  secondaryButtonClass,
} from "../components/ui";
import { IconRefresh, IconSearch, IconUpload } from "../components/icons";
import SearchSelect from "../components/SearchSelect";
import {
  ContribuableModal,
  NiveauBadge,
  RegleBadge,
  StatutBadge,
  aujourdhui,
  formatDate,
  formatDateHeure,
} from "../components/defaillance";

// ---------------------------------------------------------------------
//  Petits utilitaires
// ---------------------------------------------------------------------

/** Rafraîchissement automatique de la liste (l'analyse tourne toutes les 5 min côté serveur). */
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
  centre_id: string;
}

interface ImportResult {
  message: string;
  erreurs: string[];
}

type SortField = "niveau" | "type_impot" | "annee_fiscale";

// Contrôles de filtre compacts (même hauteur, largeur selon le contenu)
const filterInput =
  "rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs text-slate-900 placeholder-slate-400 " +
  "focus:outline-none focus:border-brand-blue focus:ring-1 focus:ring-brand-blue transition " +
  "disabled:bg-slate-100 disabled:text-slate-500";

// ---------------------------------------------------------------------
//  Page
// ---------------------------------------------------------------------

export default function DefaillancesPage() {
  const { user } = useAuth();
  const role = user?.role ?? null;
  const canManage = role === "superadmin" || role === "central" || role === "admin";
  const canPickCentre = role === "superadmin" || role === "central";

  const [data, setData] = useState<Paginated<GroupeAlerte> | null>(null);
  const [summary, setSummary] = useState<DefaillanceSummary | null>(null);
  const [centres, setCentres] = useState<Centre[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string>("");
  const [reloadKey, setReloadKey] = useState<number>(0);
  const silentRef = useRef<boolean>(false); // true = rechargement automatique (sans voile de chargement)
  const [ficheId, setFicheId] = useState<number | null>(null);

  const [filters, setFilters] = useState<Filters>({
    regle: "",
    niveau: "",
    type_impot: "",
    statut: "a_traiter",
    annee_fiscale: "",
    centre_id: "",
  });
  const [searchInput, setSearchInput] = useState<string>("");
  const [search, setSearch] = useState<string>("");
  const [sort, setSort] = useState<SortField>("niveau");
  const [dir, setDir] = useState<"asc" | "desc">("desc");
  const [page, setPage] = useState<number>(1);

  // Traitement d'une alerte (EXF-008) : « Traiter » prend le dossier puis ouvre la page de traitement
  const navigate = useNavigate();
  const [priseId, setPriseId] = useState<number | null>(null);
  const [priseErreur, setPriseErreur] = useState<string>("");
  const [aReprendre, setAReprendre] = useState<GroupeAlerte | null>(null);

  // Lancer l'analyse
  const [analyseOpen, setAnalyseOpen] = useState<boolean>(false);
  const [analysePeriode, setAnalysePeriode] = useState<string>(aujourdhui());
  const [analyseRunning, setAnalyseRunning] = useState<boolean>(false);
  const [analyseError, setAnalyseError] = useState<string>("");
  const [analyseMessage, setAnalyseMessage] = useState<string>("");

  // Import CSV
  const [importOpen, setImportOpen] = useState<boolean>(false);
  const [file, setFile] = useState<File | null>(null);
  const [importing, setImporting] = useState<boolean>(false);
  const [importError, setImportError] = useState<string>("");
  const [importResult, setImportResult] = useState<ImportResult | null>(null);

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
          statut: filters.statut || undefined,
          annee_fiscale: filters.annee_fiscale || undefined,
          centre_id: filters.centre_id || undefined,
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

  // Rafraîchissement automatique toutes les minutes (nouvelles alertes de l'analyse planifiée)
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

  const filtresActifs =
    searchInput.trim() !== "" ||
    filters.regle !== "" ||
    filters.niveau !== "" ||
    filters.type_impot !== "" ||
    filters.statut !== "a_traiter" ||
    filters.annee_fiscale !== "" ||
    filters.centre_id !== "";

  function resetFilters() {
    setSearchInput("");
    setFilters({ regle: "", niveau: "", type_impot: "", statut: "a_traiter", annee_fiscale: "", centre_id: "" });
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

  // ---- Analyse ----

  async function handleAnalyse(e: FormEvent) {
    e.preventDefault();
    setAnalyseRunning(true);
    setAnalyseError("");
    setAnalyseMessage("");

    try {
      const res = await api.post<{ message: string }>("/defaillances/analyser", { date: analysePeriode });
      setAnalyseMessage(res.data.message);
      setFilters((f) => ({ ...f, statut: "a_traiter", regle: "", niveau: "", type_impot: "", annee_fiscale: "" }));
      setPage(1);
      reload();
      window.dispatchEvent(new Event("notifications:maj"));
    } catch (err) {
      setAnalyseError(getErrorMessage(err, "L'analyse a échoué."));
    } finally {
      setAnalyseRunning(false);
    }
  }

  // ---- Import CSV ----

  function openImport() {
    setFile(null);
    setImportError("");
    setImportResult(null);
    setImportOpen(true);
  }

  async function handleImport(e: FormEvent) {
    e.preventDefault();
    if (!file) {
      setImportError("Choisissez un fichier CSV.");
      return;
    }
    setImporting(true);
    setImportError("");
    setImportResult(null);

    try {
      const body = new FormData();
      body.append("fichier", file);
      const res = await api.post<ImportResult>("/declarations/import", body, {
        headers: { "Content-Type": "multipart/form-data" },
      });
      setImportResult(res.data);
      reload();
      window.dispatchEvent(new Event("notifications:maj"));
    } catch (err) {
      setImportError(getErrorMessage(err, "Import impossible."));
    } finally {
      setImporting(false);
    }
  }

  function downloadTemplate() {
    const centre = user?.centre?.nom ?? "Nom du centre";
    const lignes = [
      "nif;nom;centre;type_impot;annee_fiscale;mois;chiffre_affaires;date_limite;date_depot",
      `0000000001;Contribuable exemple;${centre};TVA;2025;7;85000000;2025-08-15;2025-08-12`,
      `0000000001;Contribuable exemple;${centre};TVA;2025;8;88000000;2025-09-15;2025-09-10`,
      `0000000001;Contribuable exemple;${centre};IR;2024;;1020000000;2025-05-31;2025-05-20`,
    ];
    const blob = new Blob(["\uFEFF" + lignes.join("\n")], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = "modele-declarations.csv";
    a.click();
    URL.revokeObjectURL(url);
  }

  const from = data && data.total > 0 ? (data.current_page - 1) * 10 + 1 : 0;
  const to = data ? Math.min(data.current_page * 10, data.total) : 0;

  const cards: { niveau: NiveauRisque; value: number | undefined }[] = [
    { niveau: "eleve", value: summary?.eleve },
    { niveau: "moyen", value: summary?.moyen },
    { niveau: "faible", value: summary?.faible },
  ];

  return (
    <div>
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-xl font-semibold tracking-tight text-brand-blue">Anomalies de déclaration</h1>
        </div>

        {canManage && (
          <div className="flex flex-wrap gap-2">
            <button type="button" className={`${secondaryButtonClass} inline-flex items-center gap-2`} onClick={openImport}>
              <IconUpload className="h-4 w-4" /> Importer un CSV
            </button>
            <button
              type="button"
              className={`${primaryButtonClass} inline-flex items-center gap-2`}
              onClick={() => {
                setAnalyseMessage("");
                setAnalyseError("");
                setAnalyseOpen(true);
              }}
            >
              <IconRefresh className="h-4 w-4" /> Lancer l'analyse
            </button>
          </div>
        )}
      </div>

      {/* Cartes : dossiers à traiter et alertes par niveau de risque */}
      <div className="grid gap-2 sm:grid-cols-4">
        <div className="rounded-xl bg-white p-3 border border-line">
          <p className="text-xs text-slate-500">Dossiers à traiter</p>
          <p className="text-2xl font-semibold text-brand-blue">{summary ? summary.dossiers_a_traiter : "—"}</p>
          {summary && (
            <p className="text-xs text-slate-500">
              {summary.a_traiter} alerte{summary.a_traiter > 1 ? "s" : ""}
              {summary.dossiers_en_traitement > 0 ? ` · ${summary.dossiers_en_traitement} en traitement` : ""}
            </p>
          )}
        </div>
        {cards.map((c) => (
          <button
            key={c.niveau}
            type="button"
            onClick={() => toggleNiveau(c.niveau)}
            aria-pressed={filters.niveau === c.niveau}
            className={`rounded-xl bg-white p-3 text-left border border-line transition hover:border-brand-blue/40 ${
              filters.niveau === c.niveau ? "ring-2 ring-brand-blue" : ""
            }`}
          >
            <p className="text-xs text-slate-500">Alertes · risque {NIVEAU_LABELS[c.niveau].toLowerCase()}</p>
            <p className="flex items-center gap-2 text-2xl font-semibold text-brand-blue">
              {c.value ?? "—"}
              <NiveauBadge niveau={c.niveau} />
            </p>
          </button>
        ))}
      </div>

      <section className="mt-4 rounded-xl bg-white p-4 border border-line sm:p-5">
        {/* Filtres : une seule ligne sur grand écran, retour à la ligne sinon */}
        <div role="group" aria-label="Filtres des alertes" className="mb-4 flex flex-wrap items-center gap-1.5">
          <div className="relative w-full sm:w-36 sm:shrink-0">
            <IconSearch className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Nom ou NIF..."
              aria-label="Rechercher un contribuable"
              className={`${filterInput} w-full pl-8`}
            />
          </div>

          <select
            aria-label="Type d'anomalie"
            title="Type d'anomalie"
            className={`${filterInput} sm:shrink-0`}
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

          <select
            aria-label="Niveau de risque"
            title="Niveau de risque"
            className={`${filterInput} sm:shrink-0`}
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

          <select
            aria-label="Type d'impôt"
            title="Type d'impôt"
            className={`${filterInput} sm:shrink-0`}
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

          <select
            aria-label="Statut"
            title="Statut"
            className={`${filterInput} sm:shrink-0`}
            value={filters.statut}
            onChange={(e) => changeFilter("statut", e.target.value as Filters["statut"])}
          >
            <option value="">Tous les statuts</option>
            {(Object.keys(STATUT_LABELS) as StatutAlerte[]).map((s) => (
              <option key={s} value={s}>
                {STATUT_LABELS[s]}
              </option>
            ))}
          </select>

          <input
            type="number"
            min={1990}
            max={2100}
            aria-label="Exercice"
            title="Exercice (année fiscale) de l'anomalie"
            placeholder="Exercice"
            className={`${filterInput} w-20 sm:shrink-0`}
            value={filters.annee_fiscale}
            onChange={(e) => changeFilter("annee_fiscale", e.target.value)}
          />

          {canPickCentre && (
            <div className="w-full sm:w-40 sm:shrink-0">
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
            </div>
          )}

          {filtresActifs && (
            <button
              type="button"
              onClick={resetFilters}
              title="Réinitialiser les filtres"
              aria-label="Réinitialiser les filtres"
              className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-500 transition hover:border-brand-blue/40 hover:text-brand-blue"
            >
              <IconRefresh className="h-4 w-4" />
            </button>
          )}
        </div>

        <ErrorBox message={error} />
        <ErrorBox message={priseErreur} />

        <div className="overflow-x-auto">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead>
              <tr className="bg-slate-50 text-xs font-semibold text-slate-600">
                <th className="px-2 py-2">Contribuable</th>
                <th className="px-2 py-2">Anomalie</th>
                <th className="px-2 py-2">
                  <button type="button" onClick={() => toggleSort("type_impot")} className="hover:text-brand-blue">
                    Impôt{sortMark("type_impot")}
                  </button>
                </th>
                <th className="px-2 py-2">
                  <button type="button" onClick={() => toggleSort("annee_fiscale")} className="hover:text-brand-blue">
                    Exercice / période{sortMark("annee_fiscale")}
                  </button>
                </th>
                <th className="px-2 py-2">
                  <button type="button" onClick={() => toggleSort("niveau")} className="hover:text-brand-blue">
                    Risque{sortMark("niveau")}
                  </button>
                </th>
                <th className="px-2 py-2">Motif</th>
                <th className="px-2 py-2">Statut</th>
                <th className="sticky right-0 z-10 border-l border-line bg-slate-50 px-2 py-2 text-right">Traiter</th>
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
                    <td className="px-2 py-2.5">
                      <StatutBadge statut={g.statut} />
                      {g.statut === "traitee" && g.traite_par && (
                        <p className="mt-0.5 text-xs text-slate-500">
                          par {g.traite_par}
                          {g.traite_le ? `, le ${formatDate(g.traite_le)}` : ""}
                        </p>
                      )}
                      {g.statut === "en_traitement" && g.pris_par && (
                        <p className="mt-0.5 text-xs text-slate-500">
                          par {g.pris_par_moi ? "vous" : g.pris_par}
                          {g.pris_le ? `, depuis ${formatDateHeure(g.pris_le)}` : ""}
                        </p>
                      )}
                      {g.commentaire && (
                        <p className="mt-0.5 line-clamp-2 max-w-[160px] break-words text-xs italic text-slate-500" title={g.commentaire}>
                          {g.commentaire}
                        </p>
                      )}
                    </td>
                    <td className="sticky right-0 z-[1] border-l border-line bg-white px-2 py-2 text-right group-hover:bg-slate-50">
                      {g.statut === "en_traitement" && !g.pris_par_moi ? (
                        <div className="flex flex-col items-end gap-0.5">
                          <span className="rounded border border-line bg-slate-50 px-2 py-1 text-[12px] font-medium text-slate-500" title={`Dossier pris par ${g.pris_par ?? "un autre utilisateur"}`}>
                            En traitement
                          </span>
                          {canManage && (
                            <button
                              type="button"
                              className="text-xs font-medium text-brand-blue underline disabled:opacity-50"
                              disabled={priseId === g.representant_id}
                              onClick={() => setAReprendre(g)}
                            >
                              Reprendre
                            </button>
                          )}
                        </div>
                      ) : (
                        <button
                          type="button"
                          className={primaryButtonClass + " px-2.5 py-1 text-xs"}
                          disabled={priseId === g.representant_id}
                          onClick={() => void traiter(g)}
                        >
                          {priseId === g.representant_id
                            ? "..."
                            : g.statut === "en_traitement"
                              ? "Continuer"
                              : g.statut === "a_traiter"
                                ? "Traiter"
                                : "Voir le dossier"}
                        </button>
                      )}
                    </td>
                  </tr>
                );
              })}
              {data && data.data.length === 0 && (
                <tr>
                  <td colSpan={8} className="px-2 py-8 text-center text-slate-500">
                    {canManage
                      ? "Aucune alerte. Importez des déclarations puis lancez l'analyse."
                      : "Aucune anomalie pour votre centre."}
                  </td>
                </tr>
              )}
              {!data && loading && (
                <tr>
                  <td colSpan={8} className="px-2 py-8 text-center text-slate-500">
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
              Toutes les règles actives sont appliquées. <strong>Défaut de dépôt</strong> (R4) et <strong>baisse du chiffre
              d'affaires</strong> partent des déclarations (date limite dépassée ; CA en recul de 30 % ou plus). Les{" "}
              <strong>critères de risque R1, R2, R3, R5, R6 et R7</strong> recoupent l'IR, la TVA, le recoupement et la douane
              (écart de CA, série de déficits, crédits de TVA, marchés publics, importations, exportations). Chaque contribuable
              concerné forme un seul dossier, quel que soit le nombre d'anomalies. L'analyse tourne aussi automatiquement toutes
              les 5 minutes.
              {role === "admin" ? " L'analyse porte sur votre centre." : " L'analyse porte sur tous les centres."}
            </p>

            <ErrorBox message={analyseError} />
            {analyseMessage && (
              <p className="rounded-lg border border-brand-green/60 bg-leaf-50 px-3 py-2 text-sm text-brand-blue">
                {analyseMessage}
              </p>
            )}

            <Field
              label="Date d'analyse"
              required
              hint="Les dates limites dépassées à cette date sont contrôlées. Par défaut, aujourd'hui."
            >
              <input
                type="date"
                required
                className={inputClass}
                value={analysePeriode}
                onChange={(e) => setAnalysePeriode(e.target.value)}
              />
            </Field>

            <FormActions>
              <button type="button" className={secondaryButtonClass} onClick={() => setAnalyseOpen(false)}>
                Fermer
              </button>
              <button type="submit" className={primaryButtonClass} disabled={analyseRunning || !analysePeriode}>
                {analyseRunning ? "Analyse en cours..." : "Lancer l'analyse"}
              </button>
            </FormActions>
          </form>
        </Modal>
      )}

      {/* Import CSV */}
      {importOpen && (
        <Modal title="Importer des déclarations (CSV)" onClose={() => setImportOpen(false)}>
          <form onSubmit={handleImport} className="space-y-4">
            <p className="text-sm text-slate-600">
              Colonnes :{" "}
              <code className="text-xs">
                nif ; nom ; centre ; type_impot ; annee_fiscale ; mois ; chiffre_affaires ; date_limite ; date_depot
              </code>
              . Le <strong>mois</strong> (1 à 12) est vide pour une déclaration annuelle (IR, IS). Le chiffre d'affaires et la date
              limite servent à détecter les anomalies. Impôts : TVA, IR, IS, IRSA. Une déclaration déjà présente est mise à jour.
              Pour charger aussi les assujettissements et l'état des contribuables, utilisez l'import depuis la base source.
            </p>
            <button type="button" onClick={downloadTemplate} className="text-sm font-medium text-brand-blue underline">
              Télécharger un modèle CSV
            </button>

            <ErrorBox message={importError} />

            {importResult && (
              <div className="rounded-lg border border-brand-green/60 bg-leaf-50 px-3 py-2 text-sm text-brand-blue">
                <p className="font-medium">{importResult.message}</p>
                {importResult.erreurs.length > 0 && (
                  <ul className="mt-2 list-disc space-y-0.5 pl-5 text-xs text-slate-700">
                    {importResult.erreurs.map((m) => (
                      <li key={m}>{m}</li>
                    ))}
                  </ul>
                )}
                <p className="mt-2 text-xs">Lancez ensuite l'analyse pour détecter les défaillances.</p>
              </div>
            )}

            <Field label="Fichier CSV" required hint="Format CSV séparé par des points-virgules, 5 Mo maximum.">
              <input
                type="file"
                accept=".csv,.txt,text/csv"
                className={inputClass}
                onChange={(e: ChangeEvent<HTMLInputElement>) => setFile(e.target.files?.[0] ?? null)}
              />
            </Field>

            <FormActions>
              <button type="button" className={secondaryButtonClass} onClick={() => setImportOpen(false)}>
                Fermer
              </button>
              <button type="submit" className={primaryButtonClass} disabled={importing || !file}>
                {importing ? "Import en cours..." : "Importer"}
              </button>
            </FormActions>
          </form>
        </Modal>
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
