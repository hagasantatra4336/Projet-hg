import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { getErrorMessage } from "../../services/api";
import type { JournalActivitesAnomalies } from "../../types";
import { ErrorBox, inputClass, secondaryButtonClass } from "../../components/ui";
import { formatDateHeure } from "../../components/defaillance";

export default function ActivitesAnomaliesPage() {
  const [data, setData] = useState<JournalActivitesAnomalies | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string>("");
  const [reloadKey, setReloadKey] = useState<number>(0);
  const [page, setPage] = useState<number>(1);
  const [searchInput, setSearchInput] = useState<string>("");
  const [search, setSearch] = useState<string>("");
  const [action, setAction] = useState<string>("");
  const [periode, setPeriode] = useState<string>("tout");

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);

    api
      .get<JournalActivitesAnomalies>("/defaillances/activites", {
        params: { page, search: search || undefined, action: action || undefined, periode },
      })
      .then((response) => {
        if (cancelled) return;
        setData(response.data);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(getErrorMessage(err, "Impossible de charger les activités des anomalies."));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [page, search, action, periode, reloadKey]);

  const activites = data?.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <input
          className={`${inputClass} max-w-sm`}
          placeholder="Rechercher un dossier, NIF ou auteur"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          aria-label="Rechercher dans les activités des anomalies"
        />
        <select
          className={`${inputClass} w-full max-w-[15rem]`}
          value={action}
          onChange={(event) => {
            setAction(event.target.value);
            setPage(1);
          }}
          aria-label="Filtrer par activité"
        >
          <option value="">Toutes les activités</option>
          {(data?.actions ?? []).map((item) => (
            <option key={item.value} value={item.value}>
              {item.label}
            </option>
          ))}
        </select>
        <select
          className={`${inputClass} w-full max-w-[13rem]`}
          value={periode}
          onChange={(event) => {
            setPeriode(event.target.value);
            setPage(1);
          }}
          aria-label="Filtrer par période"
        >
          <option value="tout">Toute la période</option>
          <option value="jour">Aujourd'hui</option>
          <option value="semaine">7 derniers jours</option>
          <option value="mois">30 derniers jours</option>
        </select>
        <div className="flex-1" />
        <button
          type="button"
          className={secondaryButtonClass}
          onClick={() => setReloadKey((value) => value + 1)}
          disabled={loading}
        >
          Actualiser
        </button>
        
      </div>

      <ErrorBox message={error} />

      <div className="overflow-x-auto rounded-xl border border-line bg-white">
        <table className="w-full text-sm">
          <thead className="border-b border-line bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">
            <tr>
              <th className="px-3 py-2">Date</th>
              <th className="px-3 py-2">Dossier</th>
              <th className="px-3 py-2">Activité</th>
              <th className="px-3 py-2">Auteur</th>
              <th className="px-3 py-2">État</th>
              <th className="px-3 py-2">Accès</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {loading && (
              <tr>
                <td colSpan={6} className="px-3 py-4 text-center text-slate-500">
                  Chargement...
                </td>
              </tr>
            )}
            {!loading && activites.length === 0 && (
              <tr>
                <td colSpan={6} className="px-3 py-4 text-center text-slate-500">
                  Aucune activité d'anomalie enregistrée pour ces critères.
                </td>
              </tr>
            )}
            {!loading &&
              activites.map((item) => (
                <tr key={item.id} className="transition hover:bg-slate-50/70">
                  <td className="whitespace-nowrap px-3 py-2 text-slate-600">
                    {item.cree_le ? formatDateHeure(item.cree_le) : "—"}
                  </td>
                  <td className="px-3 py-2">
                    <div className="font-medium text-brand-blue">{item.contribuable.nom ?? "Contribuable inconnu"}</div>
                    <div className="mt-0.5 text-xs text-slate-500">
                      NIF : {item.contribuable.nif ?? "—"}
                      {item.contribuable.centre ? ` · ${item.contribuable.centre}` : ""}
                    </div>
                  </td>
                  <td className="px-3 py-2">
                    <div className="font-medium text-slate-700">{item.action_libelle}</div>
                    {item.detail && <div className="mt-0.5 text-xs text-slate-500">{item.detail}</div>}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2 text-slate-700">{item.auteur}</td>
                  <td className="whitespace-nowrap px-3 py-2 text-slate-600">
                    {item.statut_avant_libelle} → {item.statut_apres_libelle}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2">
                    {item.alerte_id ? (
                      <Link
                        to={`/defaillances/${item.alerte_id}/traitement`}
                        className="font-medium text-brand-azure hover:underline"
                      >
                        Ouvrir
                      </Link>
                    ) : (
                      "—"
                    )}
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>

      {data && (
        <div className="flex items-center justify-between text-sm text-slate-600">
          <span>
            Page {data.current_page} / {data.last_page} — {data.total} activité(s)
          </span>
          <div className="flex gap-2">
            <button
              type="button"
              className={secondaryButtonClass}
              disabled={loading || page <= 1}
              onClick={() => setPage((value) => Math.max(1, value - 1))}
            >
              Précédent
            </button>
            <button
              type="button"
              className={secondaryButtonClass}
              disabled={loading || page >= data.last_page}
              onClick={() => setPage((value) => Math.min(data.last_page, value + 1))}
            >
              Suivant
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
