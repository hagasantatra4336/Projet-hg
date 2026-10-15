import { useCallback, useEffect, useRef, useState, type ChangeEvent, type FormEvent } from "react";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import {
  NIVEAU_LABELS,
  REGLE_LABELS,
  STATUT_LABELS,
  TYPES_IMPOT,
  type Centre,
  type Defaillance,
  type DefaillanceSummary,
  type NiveauRisque,
  type Paginated,
  type Regle,
  type StatutAlerte,
} from "../types";
import {
  EditButton,
  ErrorBox,
  Field,
  Modal,
  inputClass,
  primaryButtonClass,
  secondaryButtonClass,
} from "../components/ui";
import { IconRefresh, IconSearch, IconUpload } from "../components/icons";
import {
  ContribuableModal,
  NiveauBadge,
  PeriodeAnomalie,
  RegleBadge,
  StatutBadge,
  aujourdhui,
  formatDate,
  formatDateHeure,
  formatJour,
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

const selectClass = `${inputClass} py-2 text-sm`;

// ---------------------------------------------------------------------
//  Page
// ---------------------------------------------------------------------

export default function DefaillancesPage() {
  const { user } = useAuth();
  const role = user?.role ?? null;
  const canManage = role === "superadmin" || role === "central" || role === "admin";
  const canPickCentre = role === "superadmin" || role === "central";

  const [data, setData] = useState<Paginated<Defaillance> | null>(null);
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

  // Traitement d'une alerte (EXF-008)
  const [selected, setSelected] = useState<Defaillance | null>(null);
  const [formStatut, setFormStatut] = useState<"a_traiter" | "traitee">("a_traiter");
  const [formCommentaire, setFormCommentaire] = useState<string>("");
  const [formError, setFormError] = useState<string>("");
  const [saving, setSaving] = useState<boolean>(false);

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
      .get<Paginated<Defaillance>>("/defaillances", {
        params: {
          page,
          sort,
          dir,
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

  function openTraitement(d: Defaillance) {
    setSelected(d);
    setFormStatut(d.statut === "traitee" ? "traitee" : "a_traiter");
    setFormCommentaire(d.commentaire ?? "");
    setFormError("");
  }

  async function handleSave(e: FormEvent) {
    e.preventDefault();
    if (!selected) return;
    setSaving(true);
    setFormError("");

    try {
      const body: { commentaire: string; statut?: "a_traiter" | "traitee" } = { commentaire: formCommentaire };
      if (selected.statut !== "regularisee") body.statut = formStatut;

      await api.patch(`/defaillances/${selected.id}`, body);
      setSelected(null);
      reload();
    } catch (err) {
      setFormError(getErrorMessage(err, "Enregistrement impossible."));
    } finally {
      setSaving(false);
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
      <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-brand-blue">Anomalies de déclaration</h1>
          <p className="text-sm text-slate-500">
            Défaillances de déclaration et baisses du chiffre d'affaires
            {role === "agent" || role === "admin" ? ` · ${user?.centre?.nom ?? "votre centre"}` : ""}
          </p>
          <p className="mt-1 text-xs text-slate-400">
            Analyse automatique toutes les minutes
            {summary
              ? summary.derniere_analyse
                ? ` · dernière : ${formatDateHeure(summary.derniere_analyse.at)} (au ${formatJour(summary.derniere_analyse.date)})`
                : " · aucune analyse effectuée pour l'instant"
              : ""}
          </p>
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

      {/* Cartes : alertes à traiter par niveau de risque */}
      <div className="grid gap-3 sm:grid-cols-4">
        <div className="rounded-xl bg-white p-4 border border-line">
          <p className="text-xs text-slate-500">Alertes à traiter</p>
          <p className="text-3xl font-semibold text-brand-blue">{summary ? summary.a_traiter : "—"}</p>
        </div>
        {cards.map((c) => (
          <button
            key={c.niveau}
            type="button"
            onClick={() => toggleNiveau(c.niveau)}
            aria-pressed={filters.niveau === c.niveau}
            className={`rounded-xl bg-white p-4 text-left border border-line transition hover:border-brand-blue/40 ${
              filters.niveau === c.niveau ? "ring-2 ring-brand-blue" : ""
            }`}
          >
            <p className="text-xs text-slate-500">Risque {NIVEAU_LABELS[c.niveau].toLowerCase()}</p>
            <p className="flex items-center gap-2 text-3xl font-semibold text-brand-blue">
              {c.value ?? "—"}
              <NiveauBadge niveau={c.niveau} />
            </p>
          </button>
        ))}
      </div>

      <section className="mt-6 rounded-xl bg-white p-5 border border-line sm:p-6">
        {/* Filtres */}
        <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
          <div className="relative lg:col-span-2">
            <IconSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Nom ou NIF..."
              aria-label="Rechercher un contribuable"
              className={`${inputClass} py-2 pl-9 text-sm`}
            />
          </div>

          <select
            aria-label="Type d'anomalie"
            className={selectClass}
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
            className={selectClass}
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
            className={selectClass}
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
            className={selectClass}
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
            placeholder="Exercice (ex. 2025)"
            className={selectClass}
            value={filters.annee_fiscale}
            onChange={(e) => changeFilter("annee_fiscale", e.target.value)}
          />

          {canPickCentre && (
            <select
              aria-label="Centre"
              className={`${selectClass} lg:col-span-2`}
              value={filters.centre_id}
              onChange={(e) => changeFilter("centre_id", e.target.value)}
            >
              <option value="">Tous les centres</option>
              {centres.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.nom}
                </option>
              ))}
            </select>
          )}
        </div>

        <ErrorBox message={error} />

        <div className="overflow-x-auto">
          <table className="w-full min-w-[980px] text-left text-sm">
            <thead>
              <tr className="bg-slate-50 text-xs font-semibold text-slate-600">
                <th className="px-3 py-3">Contribuable</th>
                <th className="px-3 py-3">Centre</th>
                <th className="px-3 py-3">Anomalie</th>
                <th className="px-3 py-3">
                  <button type="button" onClick={() => toggleSort("type_impot")} className="hover:text-brand-blue">
                    Impôt{sortMark("type_impot")}
                  </button>
                </th>
                <th className="px-3 py-3">
                  <button type="button" onClick={() => toggleSort("annee_fiscale")} className="hover:text-brand-blue">
                    Exercice / période{sortMark("annee_fiscale")}
                  </button>
                </th>
                <th className="px-3 py-3">
                  <button type="button" onClick={() => toggleSort("niveau")} className="hover:text-brand-blue">
                    Risque{sortMark("niveau")}
                  </button>
                </th>
                <th className="px-3 py-3">Motif</th>
                <th className="px-3 py-3">Statut</th>
                <th className="px-3 py-3 text-right">Traiter</th>
              </tr>
            </thead>
            <tbody className={loading ? "opacity-50 transition" : "transition"}>
              {data?.data.map((d) => (
                <tr key={d.id} className="border-t border-line hover:bg-slate-50 align-top">
                  <td className="px-3 py-4">
                    <button
                      type="button"
                      onClick={() => setFicheId(d.contribuable.id)}
                      className="text-left font-medium text-brand-blue underline-offset-2 hover:underline"
                      title="Voir la fiche du contribuable"
                    >
                      {d.contribuable.nom}
                    </button>
                    <p className="text-xs text-slate-500">{d.contribuable.nif}</p>
                  </td>
                  <td className="px-3 py-4 text-slate-700">{d.contribuable.centre ?? "—"}</td>
                  <td className="px-3 py-4">
                    <RegleBadge regle={d.regle} />
                  </td>
                  <td className="px-3 py-4 font-medium text-slate-700">{d.type_impot}</td>
                  <td className="px-3 py-4 text-slate-700">
                    <PeriodeAnomalie a={d} />
                  </td>
                  <td className="px-3 py-4">
                    <NiveauBadge niveau={d.niveau} />
                  </td>
                  <td className="max-w-xs px-3 py-4 text-xs leading-relaxed text-slate-700">{d.motif}</td>
                  <td className="px-3 py-4">
                    <StatutBadge statut={d.statut} />
                    {d.statut === "traitee" && d.traite_par && (
                      <p className="mt-1 text-xs text-slate-500">
                        par {d.traite_par}
                        {d.traite_le ? `, le ${formatDate(d.traite_le)}` : ""}
                      </p>
                    )}
                    {d.commentaire && <p className="mt-1 max-w-[200px] break-words text-xs italic text-slate-500">{d.commentaire}</p>}
                  </td>
                  <td className="px-3 py-3 text-right">
                    <EditButton onClick={() => openTraitement(d)} label="Traiter l'alerte" />
                  </td>
                </tr>
              ))}
              {data && data.data.length === 0 && (
                <tr>
                  <td colSpan={9} className="px-3 py-10 text-center text-slate-500">
                    {canManage
                      ? "Aucune alerte. Importez des déclarations puis lancez l'analyse."
                      : "Aucune anomalie pour votre centre."}
                  </td>
                </tr>
              )}
              {!data && loading && (
                <tr>
                  <td colSpan={9} className="px-3 py-10 text-center text-slate-500">
                    Chargement...
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        {data && data.total > 0 && (
          <div className="mt-4 flex flex-col gap-3 border-t border-line pt-4 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-xs text-slate-500">
              Affichage de {from} à {to} sur {data.total.toLocaleString("fr-FR")} alertes
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
                  className={`h-8 min-w-8 rounded-md px-2 text-sm transition ${
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

      {/* Traiter une alerte */}
      {selected && (
        <Modal title="Traiter l'alerte" onClose={() => setSelected(null)}>
          <form onSubmit={handleSave} className="space-y-4">
            <div className="rounded-lg bg-slate-50 p-3 text-sm">
              <p className="font-semibold text-brand-blue">
                {selected.contribuable.nom} <span className="font-normal text-slate-500">({selected.contribuable.nif})</span>
              </p>
              <p className="mt-1 text-slate-700">{selected.motif}</p>
              <button
                type="button"
                onClick={() => setFicheId(selected.contribuable.id)}
                className="mt-2 text-xs font-medium text-brand-blue underline"
              >
                Voir la fiche du contribuable
              </button>
            </div>

            <ErrorBox message={formError} />

            {selected.statut === "regularisee" ? (
              <p className="text-sm text-slate-600">
                L'anomalie n'est plus constatée (déclaration déposée ou corrigée) : l'alerte est régularisée. Vous pouvez tout de même ajouter un commentaire.
              </p>
            ) : (
              <Field label="Statut">
                <select
                  className={inputClass}
                  value={formStatut}
                  onChange={(e) => setFormStatut(e.target.value as "a_traiter" | "traitee")}
                >
                  <option value="a_traiter">À traiter</option>
                  <option value="traitee">Traitée</option>
                </select>
              </Field>
            )}

            <Field label="Commentaire">
              <textarea
                className={inputClass}
                rows={4}
                maxLength={2000}
                value={formCommentaire}
                onChange={(e) => setFormCommentaire(e.target.value)}
                placeholder="Ex. : contribuable contacté, déclaration attendue avant le 15..."
              />
            </Field>

            <div className="flex justify-end gap-3">
              <button type="button" className={secondaryButtonClass} onClick={() => setSelected(null)} disabled={saving}>
                Annuler
              </button>
              <button type="submit" className={primaryButtonClass} disabled={saving}>
                {saving ? "Enregistrement..." : "Enregistrer"}
              </button>
            </div>
          </form>
        </Modal>
      )}

      {/* Lancer l'analyse */}
      {analyseOpen && (
        <Modal title="Lancer l'analyse" onClose={() => setAnalyseOpen(false)}>
          <form onSubmit={handleAnalyse} className="space-y-4">
            <p className="text-sm text-slate-600">
              Deux règles sont appliquées. <strong>Défaut de dépôt</strong> (critère R4 de la DGI) : un contribuable assujetti
              qui n'a pas déposé une déclaration dont la date limite est dépassée (score sur 5 : déclaration annuelle non déposée = 5,
              mois de TVA non déposés dans l'exercice : 1 à 4 = 2, 5 à 9 = 3, 10 à 12 = 4) ; les contribuables en cessation ou radiés
              sont exclus. <strong>Baisse du chiffre d'affaires</strong> de 30 % ou plus : TVA par rapport au même mois de l'année
              précédente, IR par rapport à l'exercice précédent. L'analyse tourne aussi automatiquement toutes les 5 minutes.
              {role === "admin" ? " L'analyse porte sur votre centre." : " L'analyse porte sur tous les centres."}
            </p>

            <ErrorBox message={analyseError} />
            {analyseMessage && (
              <p className="rounded-lg border border-brand-green/60 bg-leaf-50 px-3 py-2 text-sm text-brand-blue">
                {analyseMessage}
              </p>
            )}

            <Field label="Date d'analyse (les dates limites dépassées à cette date sont contrôlées)">
              <input
                type="date"
                required
                className={inputClass}
                value={analysePeriode}
                onChange={(e) => setAnalysePeriode(e.target.value)}
              />
            </Field>

            <div className="flex justify-end gap-3">
              <button type="button" className={secondaryButtonClass} onClick={() => setAnalyseOpen(false)}>
                Fermer
              </button>
              <button type="submit" className={primaryButtonClass} disabled={analyseRunning || !analysePeriode}>
                {analyseRunning ? "Analyse en cours..." : "Lancer l'analyse"}
              </button>
            </div>
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

            <Field label="Fichier CSV (5 Mo max)">
              <input
                type="file"
                accept=".csv,.txt,text/csv"
                className={inputClass}
                onChange={(e: ChangeEvent<HTMLInputElement>) => setFile(e.target.files?.[0] ?? null)}
              />
            </Field>

            <div className="flex justify-end gap-3">
              <button type="button" className={secondaryButtonClass} onClick={() => setImportOpen(false)}>
                Fermer
              </button>
              <button type="submit" className={primaryButtonClass} disabled={importing || !file}>
                {importing ? "Import en cours..." : "Importer"}
              </button>
            </div>
          </form>
        </Modal>
      )}

      {/* Fiche contribuable (en dernier : s'affiche au-dessus de la fenêtre « Traiter ») */}
      {ficheId !== null && <ContribuableModal id={ficheId} onClose={() => setFicheId(null)} />}
    </div>
  );
}
