import { useEffect, useState } from "react";
import api, { getErrorMessage } from "../../services/api";
import { useAuth } from "../../context/AuthContext";
import {
  ErrorBox,
  Modal,
  dangerButtonClass,
  inputClass,
  primaryButtonClass,
  secondaryButtonClass,
} from "../../components/ui";
import {
  NIVEAU_APPROBATION_LABELS,
  type DemandeInscription,
  type Paginated,
  type StatutDemande,
} from "../../types";

interface ListResponse extends Paginated<DemandeInscription> {
  en_attente_total: number;
}

const STATUT_LABELS: Record<StatutDemande, string> = {
  en_attente: "En attente",
  approuvee: "Approuvée",
  refusee: "Refusée",
  annulee: "Annulée",
  expiree: "Expirée",
  compte_cree: "Compte créé",
  introuvable: "Introuvable",
};

const STATUT_BADGES: Record<StatutDemande, string> = {
  en_attente: "bg-amber-100 text-amber-800",
  approuvee: "bg-emerald-100 text-emerald-800",
  refusee: "bg-red-100 text-red-700",
  annulee: "bg-slate-100 text-slate-600",
  expiree: "bg-slate-100 text-slate-500",
  compte_cree: "bg-sky-100 text-sky-800",
  introuvable: "bg-slate-100 text-slate-500",
};

function dateFr(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString("fr-FR", { dateStyle: "short", timeStyle: "short" });
}

/**
 * Boîte de réception du chef de centre (rôle admin) : les agents de son centre demandent un compte.
 * Approuver envoie immédiatement un code OTP de 5 minutes ; refuser bloque la création du compte.
 * Le central et le superadmin voient toutes les demandes et peuvent aussi les traiter.
 */
export default function DemandesInscriptionPage() {
  const { user } = useAuth();

  const [data, setData] = useState<ListResponse | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string>("");
  const [info, setInfo] = useState<string>("");

  const [statut, setStatut] = useState<string>("en_attente");
  const [page, setPage] = useState<number>(1);
  const [reloadKey, setReloadKey] = useState<number>(0);

  const [action, setAction] = useState<{ demande: DemandeInscription; type: "approuver" | "refuser" } | null>(null);
  const [motif, setMotif] = useState<string>("");
  const [busy, setBusy] = useState<boolean>(false);

  // Changement de filtre : retour à la première page
  useEffect(() => {
    setPage(1);
  }, [statut]);

  // Chargement + rafraîchissement toutes les 30 s (un nouvel agent peut arriver à tout moment)
  useEffect(() => {
    let cancelled = false;

    const charger = async (silencieux: boolean) => {
      if (!silencieux) setLoading(true);

      try {
        const res = await api.get<ListResponse>("/demandes-inscription", {
          params: { page, statut },
        });
        if (cancelled) return;
        setData(res.data);
        setError("");
      } catch (err) {
        if (!cancelled) setError(getErrorMessage(err, "Impossible de charger les demandes."));
      } finally {
        if (!cancelled && !silencieux) setLoading(false);
      }
    };

    void charger(false);
    const timer = window.setInterval(() => void charger(true), 30_000);

    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
  }, [page, statut, reloadKey]);

  async function traiter() {
    if (!action) return;

    setBusy(true);
    setError("");

    try {
      if (action.type === "approuver") {
        const res = await api.post<{ message: string }>(`/demandes-inscription/${action.demande.id}/approuver`);
        setInfo(res.data.message);
      } else {
        const res = await api.post<{ message: string }>(`/demandes-inscription/${action.demande.id}/refuser`, {
          motif: motif.trim() || null,
        });
        setInfo(res.data.message);
      }

      setAction(null);
      setMotif("");
      setReloadKey((k) => k + 1);
      window.dispatchEvent(new Event("notifications:maj"));
    } catch (err) {
      setError(
        getErrorMessage(
          err,
          action.type === "approuver" ? "Impossible d'approuver la demande." : "Impossible de refuser la demande."
        )
      );
    } finally {
      setBusy(false);
    }
  }

  const portee =
    user?.role === "admin"
      ? `Demandes des agents de votre centre${user.centre ? ` (${user.centre.nom})` : ""}.`
      : "Toutes les demandes. Chaque demande est traitée selon la fonction demandée : agent → chef de centre, chef de centre → administration centrale.";

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <p className="text-sm text-slate-600">{portee}</p>
        <div className="flex items-center gap-2">
          <select className={`${inputClass} w-auto`} value={statut} onChange={(e) => setStatut(e.target.value)}>
            <option value="en_attente">En attente ({data?.en_attente_total ?? 0})</option>
            <option value="tous">Toutes</option>
            <option value="approuvee">Approuvées</option>
            <option value="refusee">Refusées</option>
            <option value="compte_cree">Comptes créés</option>
            <option value="annulee">Annulées</option>
            <option value="expiree">Expirées</option>
          </select>
          <button
            type="button"
            className={secondaryButtonClass}
            onClick={() => setReloadKey((k) => k + 1)}
            disabled={loading}
          >
            Actualiser
          </button>
        </div>
      </div>

      <ErrorBox message={error} />
      {info && (
        <p className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
          {info}
        </p>
      )}

      <div className="rounded-xl border border-line bg-white">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[820px] text-left text-sm">
            <thead>
              <tr className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                <th className="px-3 py-2">Demandeur</th>
                <th className="px-3 py-2">Centre</th>
                <th className="px-3 py-2">Fonction</th>
                <th className="px-3 py-2">Approbation</th>
                <th className="px-3 py-2">Déposée le</th>
                <th className="px-3 py-2">Statut</th>
                <th className="sticky right-0 z-10 border-l border-line bg-slate-50 px-3 py-2 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading && !data && (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-slate-500">
                    Chargement...
                  </td>
                </tr>
              )}

              {!loading && data && data.data.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-slate-500">
                    Aucune demande.
                  </td>
                </tr>
              )}

              {data?.data.map((d) => (
                <tr key={d.id} className="group border-t border-line align-top hover:bg-slate-50">
                  <td className="px-3 py-2">
                    <div className="font-medium text-brand-blue">{d.nom}</div>
                    <div className="text-xs text-slate-500">{d.email}</div>
                    <div className="text-xs text-slate-500">
                      IM : {d.im ?? "—"} · Tél. : {d.telephone ?? "—"}
                    </div>
                  </td>
                  <td className="px-3 py-2 text-slate-700">{d.centre?.nom ?? "—"}</td>
                  <td className="px-3 py-2 text-slate-700">{d.fonction?.nom ?? "—"}</td>
                  <td className="px-3 py-2 text-slate-700">
                    {d.niveau_approbation ? NIVEAU_APPROBATION_LABELS[d.niveau_approbation] : "—"}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2 text-slate-600">{dateFr(d.created_at)}</td>
                  <td className="px-3 py-2">
                    <span className={`inline-block rounded px-2 py-0.5 text-xs font-semibold ${STATUT_BADGES[d.statut]}`}>
                      {STATUT_LABELS[d.statut]}
                    </span>
                    {d.statut === "refusee" && d.motif_refus && (
                      <p className="mt-0.5 line-clamp-2 max-w-[180px] text-xs text-slate-500" title={d.motif_refus}>{d.motif_refus}</p>
                    )}
                    {d.traite_par && (
                      <p className="mt-0.5 text-xs text-slate-500">
                        par {d.traite_par} le {dateFr(d.traite_le)}
                      </p>
                    )}
                  </td>
                  <td className="sticky right-0 z-[1] whitespace-nowrap border-l border-line bg-white px-3 py-2 text-right group-hover:bg-slate-50">
                    {d.statut === "en_attente" ? (
                      <div className="flex justify-end gap-1.5">
                        <button
                          type="button"
                          className={primaryButtonClass + " px-2.5 py-1 text-xs"}
                          onClick={() => {
                            setError("");
                            setInfo("");
                            setAction({ demande: d, type: "approuver" });
                          }}
                        >
                          Approuver
                        </button>
                        <button
                          type="button"
                          className={dangerButtonClass + " px-2.5 py-1 text-xs"}
                          onClick={() => {
                            setError("");
                            setInfo("");
                            setMotif("");
                            setAction({ demande: d, type: "refuser" });
                          }}
                        >
                          Refuser
                        </button>
                      </div>
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {data && data.last_page > 0 && (
          <div className="flex items-center justify-between border-t border-line px-4 py-3 text-sm text-slate-600">
            <span>
              Page {data.current_page} / {data.last_page} — {data.total} demande(s)
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

      {action && (
        <Modal
          title={action.type === "approuver" ? "Approuver la demande" : "Refuser la demande"}
          onClose={() => {
            if (!busy) setAction(null);
          }}
        >
          <ErrorBox message={error} />

          {action.type === "approuver" ? (
            <p className="text-sm text-slate-700">
              Approuver la demande de <span className="font-semibold text-brand-blue">{action.demande.nom}</span> ?
              <br />
              Un code de vérification valable <strong>5 minutes</strong> sera envoyé immédiatement à{" "}
              <span className="font-semibold">{action.demande.email}</span>.
            </p>
          ) : (
            <>
              <p className="text-sm text-slate-700">
                Refuser la demande de <span className="font-semibold text-brand-blue">{action.demande.nom}</span> ?
                <br />
                Aucun code ne sera envoyé et aucun compte ne sera créé.
              </p>
              <label className="mt-4 block">
                <span className="mb-1 block text-sm font-medium text-slate-700">Motif (facultatif)</span>
                <textarea
                  className={inputClass}
                  rows={3}
                  maxLength={500}
                  value={motif}
                  onChange={(e) => setMotif(e.target.value)}
                  placeholder="Ex. : matricule inconnu, demande à refaire avec le bon centre…"
                />
              </label>
            </>
          )}

          <div className="mt-6 flex justify-end gap-3">
            <button type="button" className={secondaryButtonClass} onClick={() => setAction(null)} disabled={busy}>
              Annuler
            </button>
            <button
              type="button"
              className={action.type === "approuver" ? primaryButtonClass : dangerButtonClass}
              onClick={traiter}
              disabled={busy}
            >
              {busy
                ? "Traitement..."
                : action.type === "approuver"
                  ? "Approuver et envoyer le code"
                  : "Refuser la demande"}
            </button>
          </div>
        </Modal>
      )}
    </div>
  );
}
