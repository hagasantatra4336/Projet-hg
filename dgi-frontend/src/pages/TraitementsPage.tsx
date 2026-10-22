import { useCallback, useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import type { Defaillance, DefaillanceSummary, Paginated } from "../types";
import { ErrorBox, inputClass, primaryButtonClass } from "../components/ui";
import { IconSearch } from "../components/icons";
import {
  ContribuableModal,
  NiveauBadge,
  PeriodeAnomalie,
  RegleBadge,
  formatDate,
  formatDateHeure,
} from "../components/defaillance";

// ---------------------------------------------------------------------
//  Petits utilitaires
// ---------------------------------------------------------------------

/** Rafraîchissement automatique des listes (les verrous et traitements évoluent pendant la consultation). */
const REFRESH_MS = 60_000;

function pageWindow(current: number, last: number): number[] {
  const size = Math.min(5, last);
  const start = Math.max(1, Math.min(current - 2, last - size + 1));
  return Array.from({ length: size }, (_, i) => start + i);
}

type Onglet = "en_cours" | "effectues";

// ---------------------------------------------------------------------
//  Page Traitements : dossiers pris (en cours) et traitements effectués
// ---------------------------------------------------------------------

export default function TraitementsPage() {
  const { user } = useAuth();
  const role = user?.role ?? null;
  const canManage = role === "superadmin" || role === "central" || role === "admin";

  const [onglet, setOnglet] = useState<Onglet>("en_cours");
  const [data, setData] = useState<Paginated<Defaillance> | null>(null);
  const [summary, setSummary] = useState<DefaillanceSummary | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string>("");
  const [actionError, setActionError] = useState<string>("");
  const [busyId, setBusyId] = useState<number | null>(null);
  const [reloadKey, setReloadKey] = useState<number>(0);
  const silentRef = useRef<boolean>(false);
  const [ficheId, setFicheId] = useState<number | null>(null);
  const navigate = useNavigate();

  const [searchInput, setSearchInput] = useState<string>("");
  const [search, setSearch] = useState<string>("");
  const [page, setPage] = useState<number>(1);

  // Recherche avec un petit délai
  useEffect(() => {
    const t = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(t);
  }, [searchInput]);

  // Compteurs des cartes et des onglets
  useEffect(() => {
    api
      .get<DefaillanceSummary>("/defaillances/summary")
      .then((res) => setSummary(res.data))
      .catch(() => undefined);
  }, [reloadKey]);

  // Liste du moment (en cours = dossiers pris ; effectués = dossiers traités)
  useEffect(() => {
    let cancelled = false;
    if (!silentRef.current) setLoading(true);
    silentRef.current = false;

    api
      .get<Paginated<Defaillance>>("/defaillances", {
        params: {
          page,
          search: search || undefined,
          statut: onglet === "en_cours" ? "en_traitement" : "traitee",
          sort: onglet === "en_cours" ? "niveau" : "traite_le",
          dir: "desc",
        },
      })
      .then((res) => {
        if (cancelled) return;
        setData(res.data);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(getErrorMessage(err, "Impossible de charger les dossiers."));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [onglet, page, search, reloadKey]);

  const reload = useCallback(() => setReloadKey((k) => k + 1), []);

  // Rafraîchissement automatique : un dossier libéré ou traité ailleurs disparaît de la liste
  useEffect(() => {
    const t = setInterval(() => {
      silentRef.current = true;
      setReloadKey((k) => k + 1);
    }, REFRESH_MS);
    return () => clearInterval(t);
  }, []);

  function changerOnglet(o: Onglet) {
    setOnglet(o);
    setPage(1);
    setActionError("");
  }

  /** Ouvre le dossier ; « Traiter »/« Continuer »/« Reprendre » prend d'abord le dossier (atomique côté API). */
  async function ouvrir(d: Defaillance, mode: "consulter" | "prendre" | "reprendre") {
    setActionError("");

    if (mode === "consulter") {
      navigate(`/defaillances/${d.id}/traitement`);
      return;
    }

    setBusyId(d.id);
    try {
      await api.post(`/defaillances/${d.id}/prendre`, mode === "reprendre" ? { forcer: true } : {});
      navigate(`/defaillances/${d.id}/traitement`);
    } catch (err) {
      setActionError(getErrorMessage(err, "Impossible de prendre ce dossier."));
      reload(); // l'état du dossier a changé : les deux listes sont mises à jour
    } finally {
      setBusyId(null);
    }
  }

  const from = data && data.total > 0 ? (data.current_page - 1) * 10 + 1 : 0;
  const to = data ? Math.min(data.current_page * 10, data.total) : 0;

  const onglets: { cle: Onglet; label: string; n: number | undefined }[] = [
    { cle: "en_cours", label: "Dossiers en cours", n: summary?.en_traitement },
    { cle: "effectues", label: "Traitements effectués", n: summary?.traitees },
  ];

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold tracking-tight text-brand-blue">Traitements</h1>
        <p className="text-sm text-slate-500">
          Dossiers pris par les agents (en cours) et traitements effectués
          {role === "agent" || role === "admin" ? ` · ${user?.centre?.nom ?? "votre centre"}` : ""}
        </p>
        <p className="mt-1 text-xs text-slate-400">
          Un dossier pris depuis la page Défaillances (bouton « Traiter ») apparaît ici jusqu'à sa clôture ; sans activité, le verrou est
          libéré automatiquement après 4 h.
        </p>
      </div>

      {/* Onglets : en cours / effectués */}
      <div className="flex flex-wrap gap-2" role="tablist" aria-label="Listes des traitements">
        {onglets.map((o) => (
          <button
            key={o.cle}
            type="button"
            role="tab"
            aria-selected={onglet === o.cle}
            onClick={() => changerOnglet(o.cle)}
            className={`rounded-xl border border-line px-4 py-3 text-left transition ${
              onglet === o.cle ? "border-brand-blue ring-2 ring-brand-blue" : "bg-white hover:border-brand-blue/40"
            }`}
          >
            <p className="text-xs text-slate-500">{o.label}</p>
            <p className="text-2xl font-semibold text-brand-blue">{o.n ?? "—"}</p>
          </button>
        ))}
      </div>

      <section className="mt-6 rounded-xl bg-white p-5 border border-line sm:p-6">
        {/* Recherche */}
        <div className="relative mb-5 sm:max-w-xs">
          <IconSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            placeholder="Nom ou NIF..."
            aria-label="Rechercher un contribuable"
            className={`${inputClass} py-2 pl-9 text-sm`}
          />
        </div>

        <ErrorBox message={error} />
        <ErrorBox message={actionError} />

        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-left text-sm">
            <thead>
              <tr className="bg-slate-50 text-xs font-semibold text-slate-600">
                <th className="px-3 py-3">Contribuable</th>
                <th className="px-3 py-3">Anomalie</th>
                <th className="px-3 py-3">Impôt</th>
                <th className="px-3 py-3">Période</th>
                <th className="px-3 py-3">Risque</th>
                {onglet === "en_cours" ? (
                  <>
                    <th className="px-3 py-3">Pris par</th>
                    <th className="px-3 py-3 text-right">Action</th>
                  </>
                ) : (
                  <>
                    <th className="px-3 py-3">Traité par</th>
                    <th className="px-3 py-3">Commentaire</th>
                    <th className="px-3 py-3 text-right">Dossier</th>
                  </>
                )}
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
                    <p className="text-xs text-slate-400">{d.contribuable.centre ?? "—"}</p>
                  </td>
                  <td className="px-3 py-4">
                    <RegleBadge regle={d.regle} />
                    <p className="mt-1 max-w-[260px] text-xs leading-relaxed text-slate-700">{d.motif}</p>
                  </td>
                  <td className="px-3 py-4 font-medium text-slate-700">{d.type_impot}</td>
                  <td className="px-3 py-4 text-slate-700">
                    <PeriodeAnomalie a={d} />
                  </td>
                  <td className="px-3 py-4">
                    <NiveauBadge niveau={d.niveau} />
                  </td>
                  {onglet === "en_cours" ? (
                    <>
                      <td className="px-3 py-4 text-slate-700">
                        {d.pris_par_moi ? <span className="font-medium text-brand-blue">vous</span> : (d.pris_par ?? "—")}
                        {d.pris_le && <p className="text-xs text-slate-500">depuis {formatDateHeure(d.pris_le)}</p>}
                      </td>
                      <td className="px-3 py-3 text-right">
                        {d.pris_par_moi ? (
                          <button
                            type="button"
                            className={primaryButtonClass + " px-3 py-1.5 text-sm"}
                            disabled={busyId === d.id}
                            onClick={() => void ouvrir(d, "prendre")}
                          >
                            {busyId === d.id ? "..." : "Continuer"}
                          </button>
                        ) : canManage ? (
                          <button
                            type="button"
                            className={primaryButtonClass + " px-3 py-1.5 text-sm"}
                            disabled={busyId === d.id}
                            onClick={() => {
                              if (window.confirm(`Ce dossier est pris par ${d.pris_par ?? "un autre utilisateur"}. Le reprendre ?`)) {
                                void ouvrir(d, "reprendre");
                              }
                            }}
                          >
                            {busyId === d.id ? "..." : "Reprendre"}
                          </button>
                        ) : (
                          <button
                            type="button"
                            className="text-xs font-medium text-brand-blue underline disabled:opacity-50"
                            disabled={busyId === d.id}
                            onClick={() => void ouvrir(d, "consulter")}
                          >
                            Consulter
                          </button>
                        )}
                      </td>
                    </>
                  ) : (
                    <>
                      <td className="px-3 py-4 text-slate-700">
                        {d.traite_par ?? "—"}
                        {d.traite_le && <p className="text-xs text-slate-500">le {formatDate(d.traite_le)}</p>}
                      </td>
                      <td className="max-w-xs px-3 py-4 text-xs italic text-slate-500">{d.commentaire ?? "—"}</td>
                      <td className="px-3 py-3 text-right">
                        <button
                          type="button"
                          className="text-xs font-medium text-brand-blue underline"
                          onClick={() => void ouvrir(d, "consulter")}
                        >
                          Voir le dossier
                        </button>
                      </td>
                    </>
                  )}
                </tr>
              ))}
              {data && data.data.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-3 py-10 text-center text-slate-500">
                    {onglet === "en_cours"
                      ? "Aucun dossier en cours de traitement : prenez-en un depuis la page Défaillances (bouton « Traiter »)."
                      : "Aucun traitement effectué pour l'instant."}
                  </td>
                </tr>
              )}
              {!data && loading && (
                <tr>
                  <td colSpan={7} className="px-3 py-10 text-center text-slate-500">
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
              Affichage de {from} à {to} sur {data.total.toLocaleString("fr-FR")} dossiers
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

      {ficheId !== null && <ContribuableModal id={ficheId} onClose={() => setFicheId(null)} />}
    </div>
  );
}
