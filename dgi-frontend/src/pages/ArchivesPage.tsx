import { useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import {
  REGLE_LABELS,
  TYPES_IMPOT,
  type Centre,
  type DefaillanceSummary,
  type GroupeAlerte,
  type Paginated,
} from "../types";
import { ErrorBox, primaryButtonClass } from "../components/ui";
import { IconSearch } from "../components/icons";
import SearchSelect from "../components/SearchSelect";
import {
  ContribuableModal,
  NiveauBadge,
  RegleBadge,
  StatutBadge,
  formatDate,
} from "../components/defaillance";

// ---------------------------------------------------------------------
//  Petits utilitaires
// ---------------------------------------------------------------------

/** Rafraîchissement automatique (un dossier peut être rouvert ailleurs). */
const REFRESH_MS = 60_000;

function pageWindow(current: number, last: number): number[] {
  const size = Math.min(5, last);
  const start = Math.max(1, Math.min(current - 2, last - size + 1));
  return Array.from({ length: size }, (_, i) => start + i);
}

// Mêmes contrôles compacts que la page Anomalies
const filterInput =
  "rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs text-slate-900 placeholder-slate-400 " +
  "focus:outline-none focus:border-brand-blue focus:ring-1 focus:ring-brand-blue transition " +
  "disabled:bg-slate-100 disabled:text-slate-500";

const filterLabel = "mb-1 block text-xs font-medium text-slate-500";

// ---------------------------------------------------------------------
//  Page Archives : dossiers clôturés (toutes anomalies traitées/régularisées)
// ---------------------------------------------------------------------

export default function ArchivesPage() {
  const { user } = useAuth();
  const role = user?.role ?? null;
  const canPickCentre = role === "superadmin" || role === "central";

  const [data, setData] = useState<Paginated<GroupeAlerte> | null>(null);
  const [summary, setSummary] = useState<DefaillanceSummary | null>(null);
  const [centres, setCentres] = useState<Centre[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string>("");
  const [reloadKey, setReloadKey] = useState<number>(0);
  const silentRef = useRef<boolean>(false);
  const [ficheId, setFicheId] = useState<number | null>(null);
  const navigate = useNavigate();

  const [searchInput, setSearchInput] = useState<string>("");
  const [search, setSearch] = useState<string>("");
  const [annee, setAnnee] = useState<string>("");
  const [impot, setImpot] = useState<string>("");
  const [centreId, setCentreId] = useState<string>("");
  const [page, setPage] = useState<number>(1);

  // Recherche avec un petit délai
  useEffect(() => {
    const t = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(t);
  }, [searchInput]);

  // Centres pour le filtre (central / superadmin uniquement)
  useEffect(() => {
    if (!canPickCentre) return;
    api
      .get<Centre[]>("/centres")
      .then((res) => setCentres(res.data))
      .catch(() => undefined);
  }, [canPickCentre]);

  // Exercices proposés (même périmètre que les listes)
  useEffect(() => {
    api
      .get<DefaillanceSummary>("/defaillances/summary")
      .then((res) => setSummary(res.data))
      .catch(() => undefined);
  }, [reloadKey]);

  // Liste des dossiers clôturés
  useEffect(() => {
    let cancelled = false;
    if (!silentRef.current) setLoading(true);
    silentRef.current = false;

    api
      .get<Paginated<GroupeAlerte>>("/defaillances", {
        params: {
          page,
          grouper: 1,
          archive: 1,
          search: search || undefined,
          annee_fiscale: annee || undefined,
          type_impot: impot || undefined,
          centre_id: centreId || undefined,
          sort: "traite_le",
          dir: "desc",
        },
      })
      .then((res) => {
        if (cancelled) return;
        setData(res.data);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(getErrorMessage(err, "Impossible de charger les archives."));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [page, search, annee, impot, centreId, reloadKey]);

  // Rafraîchissement silencieux périodique
  useEffect(() => {
    const t = setInterval(() => {
      silentRef.current = true;
      setReloadKey((k) => k + 1);
    }, REFRESH_MS);
    return () => clearInterval(t);
  }, []);

  const from = data && data.total > 0 ? (data.current_page - 1) * 10 + 1 : 0;
  const to = data ? Math.min(data.current_page * 10, data.total) : 0;

  return (
    <div>
      <div className="mb-4">
        <h1 className="text-xl font-semibold tracking-tight text-brand-blue">Archives</h1>
      </div>

      <section className="rounded-xl bg-white p-4 border border-line sm:p-5">
        {/* Filtres : une seule ligne */}
        <div className="mb-3 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
          <label className="block">
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
            <span className={filterLabel}>Exercice</span>
            <select
              aria-label="Exercice"
              className={`${filterInput} w-full`}
              value={annee}
              onChange={(e) => {
                setAnnee(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Tous les exercices</option>
              {(summary?.exercices ?? []).map((a) => (
                <option key={a} value={String(a)}>
                  {a}
                </option>
              ))}
            </select>
          </label>

          <label className="block">
            <span className={filterLabel}>Impôt</span>
            <select
              aria-label="Type d'impôt"
              className={`${filterInput} w-full`}
              value={impot}
              onChange={(e) => {
                setImpot(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Tous les impôts</option>
              {TYPES_IMPOT.map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
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
                value={centreId}
                onChange={(v) => {
                  setCentreId(v);
                  setPage(1);
                }}
                className={`${filterInput} w-full`}
                label="Centre"
                clearLabel="Tous les centres"
                emptyLabel="Aucun centre trouvé"
              />
            </label>
          )}
        </div>

        <ErrorBox message={error} />

        <div className="overflow-x-auto">
          <table className="w-full min-w-[860px] text-left text-sm">
            <thead>
              <tr className="bg-slate-50 text-xs font-semibold text-slate-600">
                <th className="px-2 py-2">Contribuable</th>
                <th className="px-2 py-2">Anomalie</th>
                <th className="px-2 py-2">Impôt</th>
                <th className="px-2 py-2">Exercices</th>
                <th className="px-2 py-2">Risque</th>
                <th className="px-2 py-2">Statut</th>
                <th className="px-2 py-2">Clôture</th>
                <th className="sticky right-0 z-10 border-l border-line bg-slate-50 px-2 py-2 text-right">Dossier</th>
              </tr>
            </thead>
            <tbody className={loading ? "opacity-50 transition" : "transition"}>
              {data?.data.map((g) => {
                const principal = g.alertes[0];
                const motifs = g.alertes.map((a) => `${REGLE_LABELS[a.regle] ?? a.regle} — ${a.motif}`).join(" ");

                return (
                  <tr key={g.contribuable.id} className="group border-t border-line hover:bg-slate-50 align-top">
                    <td className="px-2 py-2.5">
                      <button
                        type="button"
                        onClick={() => setFicheId(g.contribuable.id)}
                        className="text-left font-medium text-brand-blue underline-offset-2 hover:underline"
                        title="Voir la fiche du contribuable"
                      >
                        {g.contribuable.nom}
                      </button>
                      <p className="text-xs text-slate-500">{g.contribuable.nif}</p>
                      {canPickCentre && <p className="text-xs text-slate-400">{g.contribuable.centre ?? "—"}</p>}
                    </td>
                    <td className="px-2 py-2.5">
                      <div className="flex max-w-[230px] flex-wrap gap-1">
                        {g.alertes.map((a) => (
                          <RegleBadge key={a.id} regle={a.regle} court />
                        ))}
                      </div>
                      <div className="mt-1 line-clamp-2 max-w-[230px] text-xs leading-snug text-slate-700" title={motifs}>
                        {principal?.motif}
                      </div>
                      <p className="mt-0.5 text-[12px] text-slate-500">
                        {g.nb_alertes} anomalie{g.nb_alertes > 1 ? "s" : ""}
                      </p>
                    </td>
                    <td className="px-2 py-2.5 font-medium text-slate-700">{g.types_impot.join(" / ")}</td>
                    <td className="px-2 py-2.5 text-slate-700">{g.annees.join(", ")}</td>
                    <td className="px-2 py-2.5">
                      <NiveauBadge niveau={g.niveau} />
                    </td>
                    <td className="px-2 py-2.5">
                      <StatutBadge statut={g.statut} />
                    </td>
                    <td className="px-2 py-2.5 text-slate-700">
                      {g.traite_le ? formatDate(g.traite_le) : "—"}
                      {g.traite_par && <p className="text-xs text-slate-500">par {g.traite_par}</p>}
                    </td>
                    <td className="sticky right-0 z-[1] border-l border-line bg-white px-2 py-2 text-right group-hover:bg-slate-50">
                      <button
                        type="button"
                        className={primaryButtonClass + " px-2.5 py-1 text-xs"}
                        onClick={() => navigate(`/defaillances/${g.representant_id}/traitement`)}
                      >
                        Voir le dossier
                      </button>
                    </td>
                  </tr>
                );
              })}
              {data && data.data.length === 0 && (
                <tr>
                  <td colSpan={8} className="px-3 py-10 text-center">
                    <p className="font-medium text-brand-blue">Aucune archive</p>
                    <p className="mt-1 text-slate-500">
                      Les dossiers clôturés (toutes anomalies traitées ou régularisées) apparaîtront ici.
                    </p>
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {data && data.total > 0 && (
          <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3 text-sm text-slate-600">
            <p>
              Affichage de <span className="font-medium">{from}</span> à <span className="font-medium">{to}</span> sur{" "}
              <span className="font-medium">{data.total.toLocaleString("fr-FR")}</span> dossiers archivés
            </p>
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={data.current_page <= 1}
                aria-label="Page précédente"
                className="flex h-8 w-8 items-center justify-center rounded-full border border-line bg-white text-slate-500 transition hover:border-brand-blue hover:text-brand-blue disabled:opacity-40 disabled:hover:border-line disabled:hover:text-slate-500"
              >
                ‹
              </button>
              {pageWindow(data.current_page, data.last_page).map((n) => (
                <button
                  key={n}
                  type="button"
                  onClick={() => setPage(n)}
                  aria-current={n === data.current_page ? "page" : undefined}
                  className={`h-8 min-w-8 rounded-full px-2.5 text-sm tabular-nums transition ${
                    n === data.current_page
                      ? "bg-brand-blue font-semibold text-white shadow-sm"
                      : "border border-transparent text-slate-600 hover:border-line hover:bg-white"
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
                className="flex h-8 w-8 items-center justify-center rounded-full border border-line bg-white text-slate-500 transition hover:border-brand-blue hover:text-brand-blue disabled:opacity-40 disabled:hover:border-line disabled:hover:text-slate-500"
              >
                ›
              </button>
            </div>
          </div>
        )}
      </section>

      {ficheId !== null && <ContribuableModal id={ficheId} onClose={() => setFicheId(null)} />}
    </div>
  );
}
