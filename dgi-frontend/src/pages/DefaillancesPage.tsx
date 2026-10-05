import { useCallback, useEffect, useRef, useState, type ChangeEvent, type FormEvent } from "react";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import {
  NIVEAU_LABELS,
  STATUT_LABELS,
  TYPES_IMPOT,
  type Centre,
  type Defaillance,
  type DefaillanceSummary,
  type NiveauRisque,
  type Paginated,
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
  StatutBadge,
  formatDate,
  formatDateHeure,
  labelPeriode,
  moisPrecedent,
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
  niveau: "" | NiveauRisque;
  type_impot: string;
  statut: "" | StatutAlerte;
  periode: string;
  centre_id: string;
}

interface ImportResult {
  message: string;
  erreurs: string[];
}

type SortField = "niveau" | "type_impot" | "periode";

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
    niveau: "",
    type_impot: "",
    statut: "a_traiter",
    periode: "",
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
  const [analysePeriode, setAnalysePeriode] = useState<string>(moisPrecedent());
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
          niveau: filters.niveau || undefined,
          type_impot: filters.type_impot || undefined,
          statut: filters.statut || undefined,
          periode: filters.periode || undefined,
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
      const res = await api.post<{ message: string }>("/defaillances/analyser", { periode: analysePeriode });
      setAnalyseMessage(res.data.message);
      setFilters((f) => ({ ...f, statut: "a_traiter", niveau: "", type_impot: "", periode: "" }));
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
      "nif;nom;centre;type_impot;periode;montant;date_depot",
      `NIF-0001;Contribuable exemple;${centre};TVA;2026-01;1500000;2026-02-10`,
      `NIF-0001;Contribuable exemple;${centre};TVA;2026-02;1620000;2026-03-12`,
      `NIF-0001;Contribuable exemple;${centre};IRSA;2026-01;400000;2026-02-15`,
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
          <h1 className="text-2xl font-bold text-brand-blue">Défaillances de déclaration</h1>
          <p className="text-sm text-gray-500">
            Contribuables habituellement déclarants qui ont cessé de déposer
            {role === "agent" || role === "admin" ? ` · ${user?.centre?.nom ?? "votre centre"}` : ""}
          </p>
          <p className="mt-1 text-xs text-gray-400">
            Analyse automatique toutes les 5 minutes
            {summary
              ? summary.derniere_analyse
                ? ` · dernière : ${formatDateHeure(summary.derniere_analyse.at)} (référence ${labelPeriode(
                    summary.derniere_analyse.periode
                  )})`
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
        <div className="rounded-2xl bg-white p-4 shadow-sm">
          <p className="text-xs text-gray-500">Alertes à traiter</p>
          <p className="text-3xl font-bold text-brand-blue">{summary ? summary.a_traiter : "—"}</p>
        </div>
        {cards.map((c) => (
          <button
            key={c.niveau}
            type="button"
            onClick={() => toggleNiveau(c.niveau)}
            aria-pressed={filters.niveau === c.niveau}
            className={`rounded-2xl bg-white p-4 text-left shadow-sm transition hover:shadow ${
              filters.niveau === c.niveau ? "ring-2 ring-brand-blue" : ""
            }`}
          >
            <p className="text-xs text-gray-500">Risque {NIVEAU_LABELS[c.niveau].toLowerCase()}</p>
            <p className="flex items-center gap-2 text-3xl font-bold text-brand-blue">
              {c.value ?? "—"}
              <NiveauBadge niveau={c.niveau} />
            </p>
          </button>
        ))}
      </div>

      <section className="mt-6 rounded-2xl bg-white p-5 shadow-sm sm:p-6">
        {/* Filtres */}
        <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
          <div className="relative lg:col-span-2">
            <IconSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Nom ou NIF..."
              aria-label="Rechercher un contribuable"
              className={`${inputClass} py-2 pl-9 text-sm`}
            />
          </div>

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
            type="month"
            aria-label="Défaillant depuis"
            title="Défaillant depuis (première période manquante)"
            className={selectClass}
            value={filters.periode}
            onChange={(e) => changeFilter("periode", e.target.value)}
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
          <table className="w-full min-w-[960px] text-left text-sm">
            <thead>
              <tr className="text-xs font-medium text-gray-400">
                <th className="px-3 py-3">Contribuable</th>
                <th className="px-3 py-3">Centre</th>
                <th className="px-3 py-3">
                  <button type="button" onClick={() => toggleSort("type_impot")} className="hover:text-brand-blue">
                    Impôt{sortMark("type_impot")}
                  </button>
                </th>
                <th className="px-3 py-3">
                  <button type="button" onClick={() => toggleSort("periode")} className="hover:text-brand-blue">
                    Défaillant depuis{sortMark("periode")}
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
                <tr key={d.id} className="border-t border-gray-100 align-top">
                  <td className="px-3 py-4">
                    <button
                      type="button"
                      onClick={() => setFicheId(d.contribuable.id)}
                      className="text-left font-medium text-brand-blue underline-offset-2 hover:underline"
                      title="Voir la fiche du contribuable"
                    >
                      {d.contribuable.nom}
                    </button>
                    <p className="text-xs text-gray-500">{d.contribuable.nif}</p>
                  </td>
                  <td className="px-3 py-4 text-gray-700">{d.contribuable.centre ?? "—"}</td>
                  <td className="px-3 py-4 font-medium text-gray-700">{d.type_impot}</td>
                  <td className="px-3 py-4 text-gray-700">
                    {labelPeriode(d.periode)}
                    <p className="text-xs text-gray-500">
                      {d.mois_manques} mois manqué{d.mois_manques > 1 ? "s" : ""}
                    </p>
                  </td>
                  <td className="px-3 py-4">
                    <NiveauBadge niveau={d.niveau} />
                  </td>
                  <td className="max-w-xs px-3 py-4 text-xs leading-relaxed text-gray-700">{d.motif}</td>
                  <td className="px-3 py-4">
                    <StatutBadge statut={d.statut} />
                    {d.statut === "traitee" && d.traite_par && (
                      <p className="mt-1 text-xs text-gray-500">
                        par {d.traite_par}
                        {d.traite_le ? `, le ${formatDate(d.traite_le)}` : ""}
                      </p>
                    )}
                    {d.commentaire && <p className="mt-1 max-w-[200px] break-words text-xs italic text-gray-500">{d.commentaire}</p>}
                  </td>
                  <td className="px-3 py-3 text-right">
                    <EditButton onClick={() => openTraitement(d)} label="Traiter l'alerte" />
                  </td>
                </tr>
              ))}
              {data && data.data.length === 0 && (
                <tr>
                  <td colSpan={8} className="px-3 py-10 text-center text-gray-500">
                    {canManage
                      ? "Aucune alerte. Importez des déclarations puis lancez l'analyse."
                      : "Aucune alerte de défaillance pour votre centre."}
                  </td>
                </tr>
              )}
              {!data && loading && (
                <tr>
                  <td colSpan={8} className="px-3 py-10 text-center text-gray-500">
                    Chargement...
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        {data && data.total > 0 && (
          <div className="mt-4 flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-xs text-gray-500">
              Affichage de {from} à {to} sur {data.total.toLocaleString("fr-FR")} alertes
            </p>
            <div className="flex items-center gap-1">
              <button
                type="button"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={data.current_page <= 1}
                aria-label="Page précédente"
                className="rounded-md px-2 py-1 text-sm text-gray-500 hover:bg-brand-green/20 disabled:opacity-40"
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
                    n === data.current_page ? "bg-brand-blue font-semibold text-white" : "text-gray-600 hover:bg-brand-green/20"
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
                className="rounded-md px-2 py-1 text-sm text-gray-500 hover:bg-brand-green/20 disabled:opacity-40"
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
            <div className="rounded-lg bg-brand-white p-3 text-sm">
              <p className="font-semibold text-brand-blue">
                {selected.contribuable.nom} <span className="font-normal text-gray-500">({selected.contribuable.nif})</span>
              </p>
              <p className="mt-1 text-gray-700">{selected.motif}</p>
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
              <p className="text-sm text-gray-600">
                Le contribuable a déposé depuis : l'alerte est régularisée. Vous pouvez tout de même ajouter un commentaire.
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
            <p className="text-sm text-gray-600">
              Un contribuable est signalé s'il a déclaré au moins 6 mois consécutifs, puis n'a plus rien déposé jusqu'à la
              période de référence choisie.
              {role === "admin" ? " L'analyse porte sur votre centre." : " L'analyse porte sur tous les centres."}
            </p>

            <ErrorBox message={analyseError} />
            {analyseMessage && (
              <p className="rounded-lg border border-brand-green bg-brand-green/20 px-3 py-2 text-sm text-brand-blue">
                {analyseMessage}
              </p>
            )}

            <Field label="Période de référence (dernier mois attendu)">
              <input
                type="month"
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
            <p className="text-sm text-gray-600">
              Colonnes : <code className="text-xs">nif ; nom ; centre ; type_impot ; periode ; montant ; date_depot</code>.
              Périodes au format <code className="text-xs">AAAA-MM</code>, impôts : TVA, IR, IS, IRSA. Une déclaration déjà
              présente est mise à jour.
            </p>
            <button type="button" onClick={downloadTemplate} className="text-sm font-medium text-brand-blue underline">
              Télécharger un modèle CSV
            </button>

            <ErrorBox message={importError} />

            {importResult && (
              <div className="rounded-lg border border-brand-green bg-brand-green/20 px-3 py-2 text-sm text-brand-blue">
                <p className="font-medium">{importResult.message}</p>
                {importResult.erreurs.length > 0 && (
                  <ul className="mt-2 list-disc space-y-0.5 pl-5 text-xs text-gray-700">
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
