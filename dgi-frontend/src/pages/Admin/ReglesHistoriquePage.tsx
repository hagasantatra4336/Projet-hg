import { useEffect, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import api, { getErrorMessage } from "../../services/api";
import type { EntreeHistoriqueRegle } from "../../types";
import { ErrorBox, inputClass, secondaryButtonClass } from "../../components/ui";
import { formatDateHeure } from "../../components/defaillance";
import { changements } from "../../regleHistorique";

/** Historique des modifications de toutes les règles (qui, quand, avant → après), filtrable par règle. */
export default function ReglesHistoriquePage() {
  const [params, setParams] = useSearchParams();
  const regle = params.get("regle") ?? "";
  const [entrees, setEntrees] = useState<EntreeHistoriqueRegle[] | null>(null);
  const [noms, setNoms] = useState<{ code: string; nom: string }[]>([]);
  const [error, setError] = useState<string>("");

  // Liste des règles (pour le filtre)
  useEffect(() => {
    api
      .get<{ regles: { code: string; nom: string }[] }>("/regles")
      .then((res) => setNoms(res.data.regles.map((r) => ({ code: r.code, nom: r.nom }))))
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    let annule = false;
    setEntrees(null);
    setError("");

    api
      .get<{ historique: EntreeHistoriqueRegle[] }>("/regles/historique", { params: { regle: regle || undefined } })
      .then((res) => {
        if (!annule) setEntrees(res.data.historique);
      })
      .catch((err) => {
        if (!annule) setError(getErrorMessage(err, "Impossible de charger l'historique."));
      });

    return () => {
      annule = true;
    };
  }, [regle]);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <label className="block">
          <span className="mb-1 block text-sm font-medium text-brand-blue">Règle</span>
          <select
            className={`${inputClass} min-w-[18rem]`}
            value={regle}
            onChange={(e) => setParams(e.target.value ? { regle: e.target.value } : {})}
          >
            <option value="">Toutes les règles</option>
            {noms.map((n) => (
              <option key={n.code} value={n.code}>
                {n.nom}
              </option>
            ))}
          </select>
        </label>
        <Link to="/admin/regles" className={`${secondaryButtonClass} inline-block`}>
          Retour aux règles
        </Link>
      </div>

      <ErrorBox message={error} />
      {!entrees && !error && <p className="text-sm text-slate-500">Chargement...</p>}
      {entrees && entrees.length === 0 && (
        <p className="rounded-xl border border-line bg-white p-5 text-sm text-slate-600">Aucune modification enregistrée pour le moment.</p>
      )}

      {entrees && entrees.length > 0 && (
        <ul className="space-y-3">
          {entrees.map((h) => {
            const lignes = changements(h, h.regle.schema);

            return (
              <li key={h.id} className="rounded-xl border border-line bg-white p-4 sm:p-5">
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                  <p className="text-sm font-semibold text-brand-blue">{h.regle.nom}</p>
                  <p className="text-xs text-slate-500">
                    {h.date ? formatDateHeure(h.date) : ""} · {h.par ?? "Utilisateur supprimé"}
                  </p>
                </div>

                {h.action === "reinitialisation" && <p className="mt-2 text-sm text-slate-700">Valeurs par défaut rétablies et règle réactivée.</p>}

                {h.action !== "reinitialisation" && lignes.length === 0 && <p className="mt-2 text-sm text-slate-500">Aucun changement de valeur.</p>}

                {lignes.length > 0 && (
                  <table className="mt-3 w-full text-sm">
                    <thead className="text-left text-xs font-medium text-slate-500">
                      <tr>
                        <th className="w-2/5 py-1 pr-3">Réglage</th>
                        <th className="py-1 pr-3">Avant</th>
                        <th className="py-1">Après</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                      {lignes.map((l) => (
                        <tr key={l.label}>
                          <td className="py-1.5 pr-3 text-slate-700">{l.label}</td>
                          <td className="py-1.5 pr-3 text-slate-500 line-through decoration-slate-300">{l.avant || "—"}</td>
                          <td className="py-1.5 font-medium text-slate-900">{l.apres || "—"}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
