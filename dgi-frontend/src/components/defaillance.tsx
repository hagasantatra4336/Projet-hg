import { useEffect, useState } from "react";
import api, { getErrorMessage } from "../services/api";
import {
  NIVEAU_LABELS,
  STATUT_LABELS,
  type ContribuableFiche,
  type NiveauRisque,
  type StatutAlerte,
} from "../types";
import { ErrorBox } from "./ui";

// ---------------------------------------------------------------------
//  Utilitaires partagés (page Défaillances, dashboard agent, fiche contribuable)
// ---------------------------------------------------------------------

const MOIS = [
  "janvier",
  "février",
  "mars",
  "avril",
  "mai",
  "juin",
  "juillet",
  "août",
  "septembre",
  "octobre",
  "novembre",
  "décembre",
];

const MOIS_COURTS = ["janv.", "févr.", "mars", "avr.", "mai", "juin", "juil.", "août", "sept.", "oct.", "nov.", "déc."];

/** "2026-07" → "juillet 2026" */
export function labelPeriode(periode: string): string {
  const [annee, mois] = periode.split("-");
  const nom = MOIS[Number(mois) - 1];
  return nom ? `${nom} ${annee}` : periode;
}

/** "2026-07" → "juil. 26" (en-têtes compacts de l'historique) */
function labelCourt(periode: string): string {
  const [annee, mois] = periode.split("-");
  return `${MOIS_COURTS[Number(mois) - 1] ?? mois} ${annee.slice(2)}`;
}

/** Mois précédent au format "AAAA-MM" (période de référence par défaut). */
export function moisPrecedent(): string {
  const d = new Date();
  d.setDate(1);
  d.setMonth(d.getMonth() - 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

export function formatDate(iso: string | null): string {
  return iso ? new Date(iso).toLocaleDateString("fr-FR") : "";
}

export function formatDateHeure(iso: string): string {
  return new Date(iso).toLocaleString("fr-FR", { dateStyle: "short", timeStyle: "short" });
}

const NIVEAU_BADGE: Record<NiveauRisque, string> = {
  eleve: "bg-red-100 text-red-700",
  moyen: "bg-amber-100 text-amber-800",
  faible: "bg-brand-green/30 text-brand-blue",
};

const STATUT_BADGE: Record<StatutAlerte, string> = {
  a_traiter: "bg-blue-100 text-blue-800",
  traitee: "bg-brand-green/30 text-brand-blue",
  regularisee: "bg-gray-100 text-gray-600",
};

export function NiveauBadge({ niveau }: { niveau: NiveauRisque }) {
  return (
    <span className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold ${NIVEAU_BADGE[niveau]}`}>
      {NIVEAU_LABELS[niveau]}
    </span>
  );
}

export function StatutBadge({ statut }: { statut: StatutAlerte }) {
  return (
    <span className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold ${STATUT_BADGE[statut]}`}>
      {STATUT_LABELS[statut]}
    </span>
  );
}

// ---------------------------------------------------------------------
//  Fiche contribuable (fenêtre)
// ---------------------------------------------------------------------

export function ContribuableModal({ id, onClose }: { id: number; onClose: () => void }) {
  const [fiche, setFiche] = useState<ContribuableFiche | null>(null);
  const [error, setError] = useState<string>("");

  useEffect(() => {
    let cancelled = false;
    setFiche(null);
    setError("");

    api
      .get<ContribuableFiche>(`/contribuables/${id}`)
      .then((res) => {
        if (!cancelled) setFiche(res.data);
      })
      .catch((err) => {
        if (!cancelled) setError(getErrorMessage(err, "Impossible de charger la fiche du contribuable."));
      });

    return () => {
      cancelled = true;
    };
  }, [id]);

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-2xl bg-white p-6 shadow-xl">
        <div className="mb-4 flex items-start justify-between gap-4">
          <div>
            <h2 className="text-lg font-bold text-brand-blue">{fiche ? fiche.contribuable.nom : "Fiche contribuable"}</h2>
            {fiche && (
              <p className="text-sm text-gray-500">
                NIF {fiche.contribuable.nif} · {fiche.contribuable.centre ?? "Aucun centre"}
              </p>
            )}
          </div>
          <button
            type="button"
            onClick={onClose}
            className="text-2xl leading-none text-gray-400 hover:text-gray-600"
            aria-label="Fermer"
          >
            ×
          </button>
        </div>

        <ErrorBox message={error} />
        {!fiche && !error && <p className="py-10 text-center text-sm text-gray-500">Chargement...</p>}

        {fiche && (
          <div className="space-y-6">
            {/* Chiffres clés */}
            <div className="grid gap-3 sm:grid-cols-3">
              <div className="rounded-xl bg-brand-white p-3">
                <p className="text-xs text-gray-500">Déclarations enregistrées</p>
                <p className="text-xl font-bold text-brand-blue">{fiche.stats.nb_declarations}</p>
              </div>
              <div className="rounded-xl bg-brand-white p-3">
                <p className="text-xs text-gray-500">Première déclaration</p>
                <p className="text-xl font-bold text-brand-blue">
                  {fiche.stats.premiere_periode ? labelPeriode(fiche.stats.premiere_periode) : "—"}
                </p>
              </div>
              <div className="rounded-xl bg-brand-white p-3">
                <p className="text-xs text-gray-500">Dernière déclaration</p>
                <p className="text-xl font-bold text-brand-blue">
                  {fiche.stats.derniere_periode ? labelPeriode(fiche.stats.derniere_periode) : "—"}
                </p>
              </div>
            </div>

            {/* Alertes du contribuable */}
            <section>
              <h3 className="mb-2 text-sm font-bold text-brand-blue">Alertes ({fiche.alertes.length})</h3>
              {fiche.alertes.length === 0 ? (
                <p className="rounded-xl bg-brand-white p-3 text-sm text-gray-500">Aucune alerte pour ce contribuable.</p>
              ) : (
                <ul className="space-y-2">
                  {fiche.alertes.map((a) => (
                    <li key={a.id} className="rounded-xl border border-gray-100 p-3 text-sm">
                      <div className="flex flex-wrap items-center gap-2">
                        <NiveauBadge niveau={a.niveau} />
                        <StatutBadge statut={a.statut} />
                        <span className="font-medium text-gray-700">{a.type_impot}</span>
                        <span className="text-gray-500">· depuis {labelPeriode(a.periode)}</span>
                      </div>
                      <p className="mt-2 text-xs leading-relaxed text-gray-700">{a.motif}</p>
                      {a.commentaire && <p className="mt-1 text-xs italic text-gray-500">Commentaire : {a.commentaire}</p>}
                      {a.statut === "traitee" && a.traite_par && (
                        <p className="mt-1 text-xs text-gray-500">
                          Traitée par {a.traite_par}
                          {a.traite_le ? `, le ${formatDate(a.traite_le)}` : ""}
                        </p>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </section>

            {/* Historique des 12 derniers mois */}
            <section>
              <h3 className="mb-1 text-sm font-bold text-brand-blue">Historique des déclarations (12 derniers mois)</h3>
              <p className="mb-2 text-xs text-gray-500">
                ✔ déclaré (survolez pour le montant) · ✘ aucune déclaration · · avant la première déclaration
              </p>
              {fiche.historique.impots.length === 0 ? (
                <p className="rounded-xl bg-brand-white p-3 text-sm text-gray-500">Aucune déclaration enregistrée.</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[640px] border-separate border-spacing-1 text-center text-xs">
                    <thead>
                      <tr className="text-gray-500">
                        <th className="px-2 py-1 text-left font-medium">Impôt</th>
                        {fiche.historique.periodes.map((p) => (
                          <th key={p} className="px-1 py-1 font-medium">
                            {labelCourt(p)}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {fiche.historique.impots.map((ligne) => (
                        <tr key={ligne.type_impot}>
                          <td className="px-2 py-1 text-left font-semibold text-brand-blue">{ligne.type_impot}</td>
                          {ligne.cellules.map((c) => {
                            const avant = ligne.premiere_periode !== null && c.periode < ligne.premiere_periode;

                            if (c.declare) {
                              const detail = `${c.montant !== null ? c.montant.toLocaleString("fr-FR") + " Ar" : ""}${
                                c.date_depot ? ` · déposé le ${formatDate(c.date_depot)}` : ""
                              }`;
                              return (
                                <td
                                  key={c.periode}
                                  title={`${labelPeriode(c.periode)} : ${detail || "déclaré"}`}
                                  className="rounded bg-brand-green/30 py-1.5 font-semibold text-brand-blue"
                                >
                                  ✔
                                </td>
                              );
                            }

                            return avant ? (
                              <td key={c.periode} className="rounded bg-gray-50 py-1.5 text-gray-300">
                                ·
                              </td>
                            ) : (
                              <td
                                key={c.periode}
                                title={`${labelPeriode(c.periode)} : aucune déclaration`}
                                className="rounded bg-red-100 py-1.5 font-semibold text-red-600"
                              >
                                ✘
                              </td>
                            );
                          })}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>
          </div>
        )}
      </div>
    </div>
  );
}
