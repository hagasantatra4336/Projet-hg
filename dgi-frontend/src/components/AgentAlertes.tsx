import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import { NIVEAU_LABELS, REGLE_LABELS, type DefaillanceSummary, type GroupeAlerte, type Paginated } from "../types";
import { ErrorBox } from "./ui";
import { IconAlert, IconChevron } from "./icons";
import { ContribuableModal, NiveauBadge, RegleBadge } from "./defaillance";

const REFRESH_MS = 60_000; // rafraîchissement automatique (l'analyse tourne toutes les 5 min côté serveur)
const MAX_LIGNES = 5;

/**
 * Bloc du tableau de bord de l'AGENT : les dossiers que son centre lui a assignés
 * (le filtrage par agent est fait par l'API selon le compte connecté).
 */
export default function AgentAlertes() {
  const { user } = useAuth();
  const [summary, setSummary] = useState<DefaillanceSummary | null>(null);
  const [alertes, setAlertes] = useState<GroupeAlerte[] | null>(null);
  const [error, setError] = useState<string>("");
  const [tick, setTick] = useState<number>(0);
  const [ficheId, setFicheId] = useState<number | null>(null);

  useEffect(() => {
    const t = setInterval(() => setTick((n) => n + 1), REFRESH_MS);
    return () => clearInterval(t);
  }, []);

  useEffect(() => {
    let cancelled = false;

    Promise.all([
      api.get<DefaillanceSummary>("/defaillances/summary"),
      api.get<Paginated<GroupeAlerte>>("/defaillances", {
        params: { grouper: 1, sort: "niveau", dir: "desc" },
      }),
    ])
      .then(([s, l]) => {
        if (cancelled) return;
        setSummary(s.data);
        setAlertes(l.data.data.slice(0, MAX_LIGNES));
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(getErrorMessage(err, "Impossible de charger les alertes."));
      });

    return () => {
      cancelled = true;
    };
  }, [tick]);

  const stats: { label: string; value: number | undefined; bord: string; icone: string }[] = [
    { label: "Alertes à traiter", value: summary?.a_traiter, bord: "border-l-brand-azure", icone: "bg-brand-azure-soft text-brand-azure" },
    { label: `Risque ${NIVEAU_LABELS.eleve.toLowerCase()}`, value: summary?.eleve, bord: "border-l-red-500", icone: "bg-red-50 text-red-600" },
    { label: `Risque ${NIVEAU_LABELS.moyen.toLowerCase()}`, value: summary?.moyen, bord: "border-l-amber-500", icone: "bg-amber-50 text-amber-700" },
    { label: `Risque ${NIVEAU_LABELS.faible.toLowerCase()}`, value: summary?.faible, bord: "border-l-emerald-500", icone: "bg-emerald-50 text-emerald-700" },
  ];

  return (
    <section className="mb-6">
      <h2 className="text-lg font-semibold text-brand-blue">Mes dossiers assignés</h2>
      <p className="mb-3 text-sm text-slate-500">
        Dossiers de {user?.centre?.nom ?? "votre centre"} que votre centre vous a confiés, réunis par contribuable
      </p>

      <ErrorBox message={error} />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {stats.map((s) => (
          <div
            key={s.label}
            className={`flex flex-row-reverse items-start justify-between gap-3 rounded-xl border border-line border-l-4 bg-white p-4 shadow-sm transition hover:shadow-md ${s.bord}`}
          >
            <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${s.icone}`}>
              <IconAlert className="h-5 w-5" />
            </span>
            <div className="min-w-0">
              <p className="truncate text-sm text-slate-600">{s.label}</p>
              <p className="text-2xl font-semibold tabular-nums text-brand-blue">{s.value ?? "—"}</p>
            </div>
          </div>
        ))}
      </div>

      <div className="mt-4 rounded-xl border border-line bg-white p-4">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead>
              <tr className="bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                <th className="px-2 py-2">Contribuable</th>
                <th className="px-2 py-2">Anomalie</th>
                <th className="px-2 py-2">Impôt</th>
                <th className="px-2 py-2">Exercices</th>
                <th className="px-2 py-2">Risque</th>
                <th className="px-2 py-2">Motif</th>
              </tr>
            </thead>
            <tbody>
              {alertes?.map((g) => {
                const principal = g.alertes[0];
                const motifs = g.alertes.map((a) => `${REGLE_LABELS[a.regle] ?? a.regle} — ${a.motif}`).join(" ");

                return (
                  <tr key={g.contribuable.id} className="border-t border-line hover:bg-slate-50 align-top">
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
                    </td>
                    <td className="px-2 py-2.5">
                      <div className="flex max-w-[210px] flex-wrap gap-1">
                        {g.alertes.map((a) => (
                          <RegleBadge key={a.id} regle={a.regle} court />
                        ))}
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
                      <div className="line-clamp-3 max-w-[220px] text-xs leading-snug text-slate-700" title={motifs}>
                        <span className="font-medium">{REGLE_LABELS[principal.regle] ?? principal.regle}</span> — {principal.motif}
                      </div>
                      {g.nb_alertes > 1 && (
                        <p className="mt-0.5 text-[12px] text-slate-500">+ {g.nb_alertes - 1} autre(s) anomalie(s)</p>
                      )}
                    </td>
                  </tr>
                );
              })}
              {alertes && alertes.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-2 py-6 text-center text-slate-500">
                    Aucun dossier ne vous est assigné.
                  </td>
                </tr>
              )}
              {!alertes && !error && (
                <tr>
                  <td colSpan={6} className="px-3 py-8 text-center text-slate-500">
                    Chargement...
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        <Link to="/traitements" className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-brand-azure hover:text-brand-blue hover:underline">
          Voir mes dossiers <IconChevron className="h-4 w-4" />
        </Link>
      </div>

      {ficheId !== null && <ContribuableModal id={ficheId} onClose={() => setFicheId(null)} />}
    </section>
  );
}
