import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import { NIVEAU_LABELS, type Defaillance, type DefaillanceSummary, type Paginated } from "../types";
import { ErrorBox } from "./ui";
import { IconAlert, IconChevron } from "./icons";
import { ContribuableModal, NiveauBadge, labelPeriode } from "./defaillance";

const REFRESH_MS = 60_000; // rafraîchissement automatique (l'analyse tourne toutes les 5 min côté serveur)
const MAX_LIGNES = 5;

/**
 * Bloc du tableau de bord de l'AGENT : les alertes de défaillance de SON centre
 * (le filtrage par centre est fait par l'API selon le compte connecté).
 */
export default function AgentAlertes() {
  const { user } = useAuth();
  const [summary, setSummary] = useState<DefaillanceSummary | null>(null);
  const [alertes, setAlertes] = useState<Defaillance[] | null>(null);
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
      api.get<Paginated<Defaillance>>("/defaillances", { params: { statut: "a_traiter", sort: "niveau", dir: "desc" } }),
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

  const stats: { label: string; value: number | undefined }[] = [
    { label: "Alertes à traiter", value: summary?.a_traiter },
    { label: `Risque ${NIVEAU_LABELS.eleve.toLowerCase()}`, value: summary?.eleve },
    { label: `Risque ${NIVEAU_LABELS.moyen.toLowerCase()}`, value: summary?.moyen },
    { label: `Risque ${NIVEAU_LABELS.faible.toLowerCase()}`, value: summary?.faible },
  ];

  return (
    <section className="mb-6">
      <h2 className="text-lg font-bold text-brand-blue">Alertes de défaillance de mon centre</h2>
      <p className="mb-3 text-sm text-gray-500">
        Contribuables de {user?.centre?.nom ?? "votre centre"} qui ont cessé de déposer leurs déclarations
      </p>

      <ErrorBox message={error} />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {stats.map((s) => (
          <div key={s.label} className="flex items-center gap-3 rounded-2xl bg-white p-4 shadow-sm">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-green/30 text-brand-blue">
              <IconAlert className="h-5 w-5" />
            </span>
            <div className="min-w-0">
              <p className="truncate text-xs text-gray-500">{s.label}</p>
              <p className="text-2xl font-bold text-brand-blue">{s.value ?? "—"}</p>
            </div>
          </div>
        ))}
      </div>

      <div className="mt-4 rounded-2xl bg-white p-5 shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead>
              <tr className="text-xs font-medium text-gray-400">
                <th className="px-3 py-2">Contribuable</th>
                <th className="px-3 py-2">Impôt</th>
                <th className="px-3 py-2">Défaillant depuis</th>
                <th className="px-3 py-2">Risque</th>
                <th className="px-3 py-2">Motif</th>
              </tr>
            </thead>
            <tbody>
              {alertes?.map((a) => (
                <tr key={a.id} className="border-t border-gray-100 align-top">
                  <td className="px-3 py-3">
                    <button
                      type="button"
                      onClick={() => setFicheId(a.contribuable.id)}
                      className="text-left font-medium text-brand-blue underline-offset-2 hover:underline"
                      title="Voir la fiche du contribuable"
                    >
                      {a.contribuable.nom}
                    </button>
                    <p className="text-xs text-gray-500">{a.contribuable.nif}</p>
                  </td>
                  <td className="px-3 py-3 font-medium text-gray-700">{a.type_impot}</td>
                  <td className="px-3 py-3 text-gray-700">
                    {labelPeriode(a.periode)}
                    <p className="text-xs text-gray-500">
                      {a.mois_manques} mois manqué{a.mois_manques > 1 ? "s" : ""}
                    </p>
                  </td>
                  <td className="px-3 py-3">
                    <NiveauBadge niveau={a.niveau} />
                  </td>
                  <td className="max-w-xs px-3 py-3 text-xs leading-relaxed text-gray-700">{a.motif}</td>
                </tr>
              ))}
              {alertes && alertes.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-3 py-8 text-center text-gray-500">
                    Aucune alerte à traiter pour votre centre.
                  </td>
                </tr>
              )}
              {!alertes && !error && (
                <tr>
                  <td colSpan={5} className="px-3 py-8 text-center text-gray-500">
                    Chargement...
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        <Link to="/defaillances" className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-brand-blue hover:underline">
          Voir toutes les alertes <IconChevron className="h-4 w-4" />
        </Link>
      </div>

      {ficheId !== null && <ContribuableModal id={ficheId} onClose={() => setFicheId(null)} />}
    </section>
  );
}
