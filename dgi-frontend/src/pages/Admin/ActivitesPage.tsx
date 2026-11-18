import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { getErrorMessage } from "../../services/api";
import type { JournalActivites } from "../../types";
import { RoleBadge, ErrorBox, inputClass, secondaryButtonClass } from "../../components/ui";
import { formatDateHeure } from "../../components/defaillance";

const CATEGORIE_STYLES: Record<string, string> = {
  connexion: "bg-brand-green-soft text-brand-green ring-brand-green/25",
  administration: "bg-brand-azure-soft text-brand-azure ring-brand-azure/25",
  metier: "bg-slate-50 text-slate-700 ring-slate-200",
  regles: "bg-brand-gold-soft text-brand-gold-dark ring-brand-gold/30",
  profil: "bg-slate-50 text-slate-600 ring-slate-200",
};

/** Libellé court de l'appareil, à partir de la chaîne User-Agent enregistrée. */
function appareil(userAgent: string | null): string {
  if (!userAgent) return "—";

  const navigateur = [
    ["Edg/", "Edge"],
    ["OPR/", "Opera"],
    ["Firefox/", "Firefox"],
    ["Chrome/", "Chrome"],
    ["Safari/", "Safari"],
  ].find(([motif]) => userAgent.includes(motif));

  const systeme = [
    ["Windows", "Windows"],
    ["Android", "Android"],
    ["iPhone", "iPhone"],
    ["iPad", "iPad"],
    ["Mac OS", "macOS"],
    ["Linux", "Linux"],
  ].find(([motif]) => userAgent.includes(motif));

  const libelle = [navigateur?.[1], systeme?.[1]].filter(Boolean).join(" · ");

  return libelle || "Navigateur";
}

/**
 * Journal d'activités du système : connexions, déconnexions et actions qui modifient les données.
 * Réservé au central et au superadmin ; les entrées sont en lecture seule.
 */
export default function ActivitesPage() {
  const [data, setData] = useState<JournalActivites | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string>("");
  const [reloadKey, setReloadKey] = useState<number>(0);

  const [page, setPage] = useState<number>(1);
  const [searchInput, setSearchInput] = useState<string>("");
  const [search, setSearch] = useState<string>("");
  const [categorie, setCategorie] = useState<string>("");
  const [periode, setPeriode] = useState<string>("tout");

  // Recherche avec un petit délai pour ne pas appeler l'API à chaque lettre
  useEffect(() => {
    const t = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(t);
  }, [searchInput]);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);

    api
      .get<JournalActivites>("/activites", {
        params: {
          page,
          search: search || undefined,
          categorie: categorie || undefined,
          periode,
        },
      })
      .then((res) => {
        if (cancelled) return;
        setData(res.data);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(getErrorMessage(err, "Impossible de charger le journal d'activités."));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [page, search, categorie, periode, reloadKey]);

  const activites = data?.data ?? [];
  const compteurs = data?.compteurs;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <input
          className={`${inputClass} max-w-xs`}
          placeholder="Rechercher (utilisateur, action, adresse IP)"
          value={searchInput}
          onChange={(e) => setSearchInput(e.target.value)}
        />
        <select
          className={`${inputClass} w-full max-w-[15rem]`}
          value={categorie}
          onChange={(e) => {
            setCategorie(e.target.value);
            setPage(1);
          }}
          aria-label="Filtrer par catégorie"
        >
          <option value="">Toutes les catégories</option>
          {(data?.categories ?? []).map((c) => (
            <option key={c.value} value={c.value}>
              {c.label}
            </option>
          ))}
        </select>
        <select
          className={`${inputClass} w-full max-w-[13rem]`}
          value={periode}
          onChange={(e) => {
            setPeriode(e.target.value);
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
          onClick={() => setReloadKey((k) => k + 1)}
          disabled={loading}
        >
          Actualiser
        </button>
        <Link to="/admin/utilisateurs" className={`${secondaryButtonClass} inline-flex items-center`}>
          Retour aux utilisateurs
        </Link>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div className="rounded-xl border border-line bg-white px-4 py-3">
          <p className="text-xs font-medium uppercase tracking-wider text-slate-500">Entrées</p>
          <p className="mt-1 text-2xl font-semibold text-brand-blue">
            {compteurs ? compteurs.total.toLocaleString("fr-FR") : "."}
          </p>
        </div>
        <div className="rounded-xl border border-line bg-white px-4 py-3">
          <p className="text-xs font-medium uppercase tracking-wider text-slate-500">Aujourd'hui</p>
          <p className="mt-1 text-2xl font-semibold text-brand-blue">
            {compteurs ? compteurs.aujourdhui.toLocaleString("fr-FR") : "."}
          </p>
        </div>
        <div className="rounded-xl border border-line bg-white px-4 py-3">
          <p className="text-xs font-medium uppercase tracking-wider text-slate-500">Connexions</p>
          <p className="mt-1 text-2xl font-semibold text-brand-green">
            {compteurs ? compteurs.connexions.toLocaleString("fr-FR") : "."}
          </p>
        </div>
        <div className="rounded-xl border border-line bg-white px-4 py-3">
          <p className="text-xs font-medium uppercase tracking-wider text-slate-500">Utilisateurs</p>
          <p className="mt-1 text-2xl font-semibold text-brand-blue">
            {compteurs ? compteurs.utilisateurs.toLocaleString("fr-FR") : "."}
          </p>
        </div>
      </div>

      <ErrorBox message={error} />

      <div className="bg-white rounded-xl border border-line overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="border-b border-line bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">
            <tr>
              <th className="px-3 py-2">Date</th>
              <th className="px-3 py-2">Utilisateur</th>
              <th className="px-3 py-2">Catégorie</th>
              <th className="px-3 py-2">Activité</th>
              <th className="px-3 py-2">Adresse IP</th>
              <th className="px-3 py-2">Appareil</th>
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
                  Aucune activité enregistrée pour ces critères.
                </td>
              </tr>
            )}
            {!loading &&
              activites.map((a) => (
                <tr key={a.id} className="transition hover:bg-slate-50/70">
                  <td className="whitespace-nowrap px-3 py-2 text-slate-600">
                    {a.cree_le ? formatDateHeure(a.cree_le) : "—"}
                  </td>
                  <td className="px-3 py-2">
                    <div className="font-medium text-brand-blue">{a.user_nom}</div>
                    <div className="mt-1">
                      <RoleBadge role={a.role} />
                    </div>
                  </td>
                  <td className="px-3 py-2">
                    <span
                      className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold leading-5 ring-1 ring-inset ${
                        CATEGORIE_STYLES[a.categorie] ?? "bg-slate-50 text-slate-600 ring-slate-200"
                      }`}
                    >
                      {a.categorie_libelle}
                    </span>
                  </td>
                  <td className="px-3 py-2">
                    <div className="text-slate-700">{a.description}</div>
                    {a.cible && <div className="mt-0.5 text-xs text-slate-500">{a.cible}</div>}
                  </td>
                  <td className="px-3 py-2 font-mono text-xs text-slate-600">{a.ip ?? "—"}</td>
                  <td className="px-3 py-2 text-xs text-slate-600" title={a.user_agent ?? undefined}>
                    {appareil(a.user_agent)}
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>

      {data && data.last_page > 0 && (
        <div className="flex items-center justify-between text-sm text-slate-600">
          <span>
            Page {data.current_page} / {data.last_page} — {data.total} entrée(s)
          </span>
          <div className="flex gap-2">
            <button
              type="button"
              className={secondaryButtonClass}
              disabled={page <= 1 || loading}
              onClick={() => setPage(page - 1)}
            >
              Précédent
            </button>
            <button
              type="button"
              className={secondaryButtonClass}
              disabled={page >= data.last_page || loading}
              onClick={() => setPage(page + 1)}
            >
              Suivant
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
