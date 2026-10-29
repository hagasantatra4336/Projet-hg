import { useCallback, useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import { REGLE_LABELS, type ContribuableFiche, type DossierAlerte, type GroupeAlerte, type LigneBaseRisque } from "../types";
import { ConfirmDialog, ErrorBox, IconButton, iconButtonClass, inputClass } from "../components/ui";
import { IconCheck, IconFile, IconLock, IconRefresh, IconSave, IconUnlock } from "../components/icons";
import {
  EtatBadge,
  Matrice,
  NiveauBadge,
  PeriodeAnomalie,
  RegleBadge,
  StatutBadge,
  formatDate,
  formatDateHeure,
  libellePeriode,
} from "../components/defaillance";

const MOIS_COURTS = ["janv.", "févr.", "mars", "avr.", "mai", "juin", "juil.", "août", "sept.", "oct.", "nov.", "déc."];
const HEARTBEAT_MS = 5 * 60_000; // tant que la page est ouverte, le verrou est prolongé

const LIBELLES: Record<string, string> = {
  ca: "Chiffre d'affaires",
  resultat: "Résultat",
  reduction: "Réduction",
  ir_a_payer: "IR à payer",
  ca_taxable: "CA taxable",
  ca_exonere: "CA exonéré",
  tva_deduc_bl: "TVA déd. biens locaux",
  tva_deduc_bi: "TVA déd. biens importés",
  tva_deduc_il: "TVA déd. invest. locaux",
  tva_deduc_ii: "TVA déd. invest. importés",
  tva_deduc_abl: "TVA déd. autres biens locaux",
  tva_deduc_abi: "TVA déd. autres biens importés",
  credit_tva: "Crédit de TVA",
  ca_taxable_export: "CA taxable export",
  prestations_de_service: "Prestations de service",
  achats_effectues: "Achats effectués",
  achats_consommes: "Achats consommés",
  produits_locaux_vendus: "Produits locaux vendus",
  exportations: "Exportations",
  importations: "Importations",
  montant_total: "Montant total",
  tva_ded_achat: "TVA déd. achats",
  tva_ded_imp: "TVA déd. importations",
  marche_public: "Marchés publics",
  dau_tva_im4: "TVA des DAU IM4",
  dau_caf_ex1: "Valeur CAF des DAU EX1",
};

const SOURCES: { cle: "IR" | "TVA" | "RECOUPEMENT" | "DAU"; titre: string; parMois?: boolean }[] = [
  { cle: "IR", titre: "Déclarations IR (par exercice)" },
  { cle: "TVA", titre: "Déclarations TVA (par mois)", parMois: true },
  { cle: "RECOUPEMENT", titre: "Recoupement (par exercice)" },
  { cle: "DAU", titre: "Douane : DAU (par exercice)" },
];

const nombre = (v: number | null | undefined) => (v === null || v === undefined ? "—" : v.toLocaleString("fr-FR", { maximumFractionDigits: 2 }));

const ACTIONS_DOSSIER: Record<string, string> = {
  prise: "Dossier pris",
  reprise: "Dossier repris",
  liberation: "Dossier libéré",
  cloture: "Dossier clôturé",
  reouverture: "Dossier rouvert",
};

/** Tableau d'une source : une ligne par année (ou par mois), une colonne par valeur renseignée. */
function TableauSource({ titre, lignes, parMois }: { titre: string; lignes: LigneBaseRisque[]; parMois?: boolean }) {
  if (lignes.length === 0) return null;

  const colonnes = Object.keys(lignes[0].valeurs).filter((c) => lignes.some((l) => l.valeurs[c] !== null));
  if (colonnes.length === 0) return null;

  return (
    <div>
      <h4 className="mb-1 text-xs font-semibold text-slate-600">{titre}</h4>
      <div className="overflow-x-auto rounded-lg border border-line">
        <table className="w-full min-w-[520px] text-xs">
          <thead className="bg-slate-50 text-left text-slate-500">
            <tr>
              <th className="whitespace-nowrap px-3 py-2 font-medium">Période</th>
              {colonnes.map((c) => (
                <th key={c} className="px-3 py-2 text-right font-medium">
                  {LIBELLES[c] ?? c}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {lignes.map((l) => (
              <tr key={`${l.annee}-${l.mois}`}>
                <td className="whitespace-nowrap px-3 py-1.5 font-medium text-slate-700">
                  {parMois && l.mois > 0 ? `${MOIS_COURTS[l.mois - 1]} ${l.annee}` : l.annee}
                </td>
                {colonnes.map((c) => (
                  <td key={c} className={`whitespace-nowrap px-3 py-1.5 text-right ${(l.valeurs[c] ?? 0) < 0 ? "text-red-700" : "text-slate-800"}`}>
                    {nombre(l.valeurs[c])}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function Bandeau({
  groupe,
  verrouMinutes,
  canManage,
  onReprendre,
  busy,
}: {
  groupe: GroupeAlerte;
  verrouMinutes: number;
  canManage: boolean;
  onReprendre: () => void;
  busy: boolean;
}) {
  const anomalies = `${groupe.nb_alertes} anomalie${groupe.nb_alertes > 1 ? "s" : ""}`;

  if (groupe.statut === "en_traitement" && groupe.pris_par_moi) {
    return (
      <p className="rounded-xl border border-brand-green/60 bg-leaf-50 px-4 py-3 text-sm text-brand-blue">
        Ce dossier ({anomalies}) est <strong>réservé pour vous</strong>
        {groupe.pris_le ? ` depuis ${formatDateHeure(groupe.pris_le)}` : ""}. Les autres utilisateurs le voient « En traitement » et ne
        peuvent pas le prendre. Pensez à le marquer comme traité, ou à le libérer ; sans activité, il est libéré automatiquement après{" "}
        {Math.round(verrouMinutes / 60)} h.
      </p>
    );
  }

  if (groupe.statut === "en_traitement") {
    return (
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <p>
          Dossier ({anomalies}) <strong>en cours de traitement par {groupe.pris_par ?? "un autre utilisateur"}</strong>
          {groupe.pris_le ? ` depuis ${formatDateHeure(groupe.pris_le)}` : ""}. Vous pouvez le consulter, pas le traiter.
        </p>
        {canManage && (
          <IconButton label="Reprendre le dossier" onClick={onReprendre} disabled={busy}>
            <IconRefresh className="h-4 w-4" />
          </IconButton>
        )}
      </div>
    );
  }

  if (groupe.statut === "traitee") {
    return (
      <p className="rounded-xl border border-line bg-slate-50 px-4 py-3 text-sm text-slate-700">
        Dossier traité ({anomalies}){groupe.traite_par ? ` par ${groupe.traite_par}` : ""}
        {groupe.traite_le ? `, le ${formatDate(groupe.traite_le)}` : ""}.
        {!canManage && <span className="text-slate-500"> Seul votre chef de centre peut le rouvrir.</span>}
      </p>
    );
  }

  if (groupe.statut === "regularisee") {
    return (
      <p className="rounded-xl border border-line bg-slate-50 px-4 py-3 text-sm text-slate-700">
        Les anomalies de ce dossier ne sont plus constatées (déclarations déposées ou corrigées) : elles sont régularisées.
      </p>
    );
  }

  return (
    <p className="rounded-xl border border-line bg-slate-50 px-4 py-3 text-sm text-slate-700">
      Ce dossier ({anomalies}) n'est pris par personne. Cliquez sur « Prendre le dossier » pour le traiter : il passera « En traitement »
      pour les autres.
    </p>
  );
}

/** Page de traitement d'un dossier contribuable : toutes ses anomalies (valeurs, formules), son historique et les actions. */
export default function DossierTraitementPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const canManage = user?.role === "superadmin" || user?.role === "central" || user?.role === "admin";

  const [dossier, setDossier] = useState<DossierAlerte | null>(null);
  const [fiche, setFiche] = useState<ContribuableFiche | null>(null);
  const [commentaire, setCommentaire] = useState<string>("");
  const [error, setError] = useState<string>("");
  const [message, setMessage] = useState<string>("");
  const [busy, setBusy] = useState<boolean>(false);
  const [aConfirmer, setAConfirmer] = useState<"traiter" | "liberer" | "rouvrir" | null>(null);

  const charger = useCallback(async () => {
    try {
      const res = await api.get<DossierAlerte>(`/defaillances/${id}/dossier`);
      setDossier(res.data);
      setCommentaire((c) => (c === "" ? (res.data.groupe.commentaire ?? "") : c));
      setError("");

      const f = await api.get<ContribuableFiche>(`/contribuables/${res.data.alerte.contribuable.id}`);
      setFiche(f.data);
    } catch (err) {
      setError(getErrorMessage(err, "Impossible de charger le dossier."));
    }
  }, [id]);

  useEffect(() => {
    void charger();
  }, [charger]);

  const enTraitementParMoi = dossier?.groupe.statut === "en_traitement" && dossier.groupe.pris_par_moi;

  // Tant que la page est ouverte, le verrou est prolongé
  useEffect(() => {
    if (!enTraitementParMoi) return;
    const t = setInterval(() => {
      api.post(`/defaillances/${id}/prendre`, { groupe: true }).catch(() => undefined);
    }, HEARTBEAT_MS);
    return () => clearInterval(t);
  }, [enTraitementParMoi, id]);

  async function action(fn: () => Promise<void>, ok?: string) {
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await fn();
      if (ok) setMessage(ok);
      window.dispatchEvent(new Event("notifications:maj"));
    } catch (err) {
      setError(getErrorMessage(err, "Action impossible."));
      void charger(); // l'état du dossier a peut-être changé (pris par un autre, traité...)
    } finally {
      setBusy(false);
    }
  }

  const prendre = (forcer = false) =>
    action(async () => {
      await api.post(`/defaillances/${id}/prendre`, { groupe: true, ...(forcer ? { forcer: true } : {}) });
      await charger();
    }, "Dossier pris : toutes ses anomalies sont « En traitement » pour les autres utilisateurs.");

  const enregistrer = () =>
    action(async () => {
      await api.patch(`/defaillances/${id}`, { groupe: true, commentaire });
      await charger();
    }, "Commentaire enregistré sur toutes les anomalies du dossier.");

  const marquerTraite = () =>
    action(async () => {
      await api.patch(`/defaillances/${id}`, { groupe: true, statut: "traitee", commentaire });
      navigate("/defaillances");
    });

  const liberer = () =>
    action(async () => {
      await api.post(`/defaillances/${id}/liberer`, { groupe: true });
      navigate("/defaillances");
    });

  const rouvrir = () =>
    action(async () => {
      await api.patch(`/defaillances/${id}`, { groupe: true, statut: "a_traiter" });
      await charger();
    }, "Dossier rouvert : ses anomalies non régularisées sont de nouveau à traiter.");

  async function confirmer() {
    const choix = aConfirmer;
    setAConfirmer(null);
    if (choix === "traiter") await marquerTraite();
    else if (choix === "liberer") await liberer();
    else if (choix === "rouvrir") await rouvrir();
  }

  if (!dossier) {
    return (
      <div className="space-y-4">
        <Link to="/defaillances" className="text-sm font-medium text-brand-blue underline">
          ← Retour aux alertes
        </Link>
        <ErrorBox message={error} />
        {!error && <p className="text-sm text-slate-500">Chargement du dossier...</p>}
      </div>
    );
  }

  const groupe = dossier.groupe;
  const baisses = new Set((fiche?.alertes ?? []).filter((x) => x.regle === "baisse_ca").map((x) => `${x.type_impot}|${x.annee_fiscale}|${x.mois}`));
  const aDesDonnees = SOURCES.some((s) => dossier.indicateurs[s.cle].length > 0);
  const lectureSeule = !(groupe.statut === "en_traitement" && groupe.pris_par_moi);

  return (
    <div className="space-y-5">
      <Link to="/defaillances" className="text-sm font-medium text-brand-blue underline">
        ← Retour aux alertes
      </Link>

      {/* En-tête */}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-brand-blue">{groupe.contribuable.nom}</h1>
          <p className="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <span>
              NIF {groupe.contribuable.nif} · {groupe.contribuable.centre ?? "Aucun centre"}
            </span>
            {fiche && <EtatBadge fiche={fiche.contribuable} />}
          </p>
          <p className="mt-1 text-xs text-slate-500">
            Dossier unique · {groupe.nb_alertes} anomalie{groupe.nb_alertes > 1 ? "s" : ""} ·{" "}
            {groupe.types_impot.join(" / ")} · exercice{groupe.annees.length > 1 ? "s" : ""} {groupe.annees.join(", ")}
          </p>
        </div>
        <StatutBadge statut={groupe.statut} />
      </div>

      {/* Coordonnées du contribuable : utiles pour le contacter pendant le traitement */}
      {fiche && (
        <section className="rounded-xl border border-line bg-white p-4">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <h2 className="text-sm font-semibold text-brand-blue">Coordonnées du contribuable</h2>
              <p className="mt-0.5 text-sm text-slate-700">
                {fiche.contribuable.activite || "Activité non renseignée"} · {fiche.contribuable.adresse || "Adresse non renseignée"}
              </p>
              <p className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                {fiche.contribuable.telephone ? (
                  <a href={`tel:${fiche.contribuable.telephone.replace(/\s/g, "")}`} className="font-medium text-brand-blue underline">
                    {fiche.contribuable.telephone}
                  </a>
                ) : (
                  <span className="text-slate-400">Téléphone non renseigné</span>
                )}
                {fiche.contribuable.email ? (
                  <a href={`mailto:${fiche.contribuable.email}`} className="font-medium text-brand-blue underline">
                    {fiche.contribuable.email}
                  </a>
                ) : (
                  <span className="text-slate-400">E-mail non renseigné</span>
                )}
              </p>
            </div>
            <Link
              to={`/contribuables/${fiche.contribuable.id}`}
              className={`${iconButtonClass()} no-print`}
              title="Fiche complète du contribuable"
              aria-label="Fiche complète du contribuable"
            >
              <IconFile className="h-4 w-4" />
            </Link>
          </div>
        </section>
      )}

      <Bandeau
        groupe={groupe}
        verrouMinutes={dossier.verrou_minutes}
        canManage={canManage}
        busy={busy}
        onReprendre={() => void prendre(true)}
      />
      <ErrorBox message={error} />
      {message && <p className="rounded-lg border border-brand-green/60 bg-leaf-50 px-3 py-2 text-sm text-brand-blue">{message}</p>}

      {/* Les anomalies du dossier */}
      <section className="rounded-xl border border-line bg-white p-5">
        <h2 className="text-sm font-semibold text-brand-blue">Anomalies du dossier ({groupe.nb_alertes})</h2>
        <p className="text-xs text-slate-500">
          Toutes les anomalies constatées pour ce contribuable. Les commentaires et le statut s'appliquent à l'ensemble du dossier.
        </p>
        <ul className="mt-3 space-y-2">
          {groupe.alertes.map((x) => {
            const formule = dossier.formules?.[x.regle];
            const indicateurs = Object.entries(x.details?.indicateurs ?? {});

            return (
              <li key={x.id} className="rounded-lg border border-line p-3 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <RegleBadge regle={x.regle} />
                  <NiveauBadge niveau={x.niveau} />
                  <StatutBadge statut={x.statut} />
                  <span className="font-medium text-slate-700">{x.type_impot}</span>
                  <span className="text-slate-500">
                    · {x.regle === "baisse_ca" ? libellePeriode(x.annee_fiscale, x.mois) : `exercice ${x.annee_fiscale}`}
                  </span>
                </div>
                <p className="mt-2 text-sm leading-relaxed text-slate-800">{x.motif}</p>
                <div className="mt-1 text-xs text-slate-500">
                  <PeriodeAnomalie a={x} />
                </div>

                {indicateurs.length > 0 && (
                  <dl className="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2">
                    {indicateurs.map(([k, v]) => (
                      <div key={k} className="flex justify-between gap-3 border-b border-line pb-1 text-xs">
                        <dt className="text-slate-500">{LIBELLES[k] ?? k.replace(/_/g, " ")}</dt>
                        <dd className="font-medium text-slate-900">{typeof v === "number" ? nombre(v) : String(v ?? "—")}</dd>
                      </div>
                    ))}
                  </dl>
                )}

                {x.commentaire && <p className="mt-2 text-xs italic text-slate-500">Commentaire : {x.commentaire}</p>}

                {formule && formule.formules.length > 0 && (
                  <details className="mt-2 rounded-lg border border-line bg-slate-50/70 p-2">
                    <summary className="cursor-pointer text-xs font-medium text-brand-blue">
                      Comment « {REGLE_LABELS[x.regle] ?? x.regle} » est calculé
                    </summary>
                    <dl className="mt-2 space-y-1.5">
                      {formule.formules.map((f) => (
                        <div key={f.label} className="grid gap-x-4 gap-y-0.5 sm:grid-cols-[11rem_1fr]">
                          <dt className="text-xs font-medium text-slate-500">{f.label}</dt>
                          <dd className="text-sm text-slate-800">{f.expr}</dd>
                        </div>
                      ))}
                    </dl>
                  </details>
                )}
              </li>
            );
          })}
        </ul>
      </section>

      {/* Données du dossier */}
      <section className="space-y-4 rounded-xl border border-line bg-white p-5">
        <div>
          <h2 className="text-sm font-semibold text-brand-blue">Données du dossier</h2>
          <p className="text-xs text-slate-500">Valeurs lues dans les déclarations, le recoupement et la douane, sur les exercices des anomalies et les deux précédents.</p>
        </div>
        {aDesDonnees ? (
          SOURCES.map((s) => <TableauSource key={s.cle} titre={s.titre} lignes={dossier.indicateurs[s.cle]} parMois={s.parMois} />)
        ) : (
          <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">
            Aucune donnée dans la base d'analyse risque pour ce contribuable (elle se remplit avec « php artisan dgi:importer-risque »).
          </p>
        )}
      </section>

      {/* Historique des déclarations et obligations */}
      {fiche && (
        <section className="space-y-4 rounded-xl border border-line bg-white p-5">
          <div>
            <h2 className="text-sm font-semibold text-brand-blue">Historique des déclarations</h2>
            <p className="text-xs text-slate-500">
              ✔ déclaré (survolez pour le CA et les dates) · ▼ baisse du CA · ✘ non déposé, date limite dépassée · … pas encore dû · · aucune obligation
            </p>
          </div>
          {fiche.historique.mensuel.impots.length === 0 && fiche.historique.annuel.impots.length === 0 ? (
            <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">Aucune déclaration enregistrée.</p>
          ) : (
            <>
              <Matrice
                titre="Déclarations mensuelles (12 derniers mois)"
                entetes={fiche.historique.mensuel.periodes.map((p) => `${MOIS_COURTS[p.mois - 1]} ${String(p.annee).slice(2)}`)}
                lignes={fiche.historique.mensuel.impots}
                baisses={baisses}
              />
              <Matrice
                titre="Déclarations annuelles (5 derniers exercices)"
                entetes={fiche.historique.annuel.annees.map(String)}
                lignes={fiche.historique.annuel.impots}
                baisses={baisses}
              />
            </>
          )}

          {fiche.obligations.length > 0 && (
            <div>
              <h4 className="mb-1 text-xs font-semibold text-slate-600">Obligations déclaratives</h4>
              <ul className="flex flex-wrap gap-2 text-xs">
                {fiche.obligations.map((o) => (
                  <li key={`${o.type_impot}-${o.annee_fiscale}`} className="rounded border border-line bg-slate-50 px-2 py-1 text-slate-700">
                    {o.type_impot} {o.annee_fiscale} · {o.periodicite}
                    {o.exonere ? " · exonéré" : ""}
                    {!o.actif ? " · inactif" : ""}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </section>
      )}

      {/* Journal des actions */}
      <section className="rounded-xl border border-line bg-white p-5">
        <h2 className="text-sm font-semibold text-brand-blue">Journal du dossier</h2>
        <p className="text-xs text-slate-500">Prise, reprise, libération, clôture et réouverture, avec leur auteur et leur date.</p>
        {dossier.historique.length === 0 ? (
          <p className="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-500">Aucune action enregistrée pour ce dossier.</p>
        ) : (
          <ul className="mt-3 space-y-2">
            {dossier.historique.map((h) => (
              <li key={h.id} className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-line pb-2 text-sm last:border-0 last:pb-0">
                <span className="text-slate-800">
                  <strong className="font-medium">{ACTIONS_DOSSIER[h.action] ?? h.action}</strong> par {h.auteur}
                </span>
                <span className="text-xs text-slate-500">{h.le ? formatDateHeure(h.le) : ""}</span>
              </li>
            ))}
          </ul>
        )}
      </section>

      {/* Traitement */}
      <section className="rounded-xl border border-line bg-white p-5">
        <h2 className="text-sm font-semibold text-brand-blue">Traitement</h2>
        <p className="mt-1 text-xs text-slate-500">
          Le commentaire s'applique à toutes les anomalies de ce dossier et reste visible dans son historique.
        </p>
        <label className="mt-3 block">
          <span className="mb-1 flex items-baseline justify-between gap-3">
            <span className="text-sm font-medium text-slate-800">Commentaire</span>
            <span className="text-xs font-normal text-slate-400">{commentaire.length} / 2000</span>
          </span>
          <textarea
            className={inputClass}
            rows={4}
            maxLength={2000}
            value={commentaire}
            disabled={lectureSeule}
            onChange={(e) => setCommentaire(e.target.value)}
            placeholder="Ex. : contribuable contacté, déclaration attendue avant le 15..."
          />
        </label>

        <div className="mt-4 flex flex-wrap items-center justify-end gap-3 border-t border-line pt-4">
          {groupe.statut === "a_traiter" && (
            <IconButton label="Prendre le dossier" tone="primary" disabled={busy} onClick={() => void prendre()}>
              <IconLock className="h-4 w-4" />
            </IconButton>
          )}
          {enTraitementParMoi && (
            <>
              <IconButton label="Libérer le dossier" disabled={busy} onClick={() => setAConfirmer("liberer")}>
                <IconUnlock className="h-4 w-4" />
              </IconButton>
              <IconButton label="Enregistrer le commentaire" disabled={busy} onClick={() => void enregistrer()}>
                <IconSave className="h-4 w-4" />
              </IconButton>
              <IconButton label="Marquer comme traité" tone="primary" disabled={busy} onClick={() => setAConfirmer("traiter")}>
                <IconCheck className="h-4 w-4" />
              </IconButton>
            </>
          )}
          {groupe.statut === "traitee" && canManage && (
            <IconButton label="Rouvrir le dossier" disabled={busy} onClick={() => setAConfirmer("rouvrir")}>
              <IconRefresh className="h-4 w-4" />
            </IconButton>
          )}
        </div>
      </section>

      {aConfirmer === "traiter" && (
        <ConfirmDialog
          title="Marquer le dossier comme traité"
          message="Clôturer le traitement de ce dossier ?"
          detail="Le commentaire saisi est enregistré sur toutes les anomalies, puis vous revenez à la liste des alertes. Seul un chef de centre pourra rouvrir le dossier si nécessaire."
          confirmLabel="Marquer comme traité"
          loadingLabel="Enregistrement..."
          tone="primary"
          loading={busy}
          onConfirm={() => void confirmer()}
          onCancel={() => setAConfirmer(null)}
        />
      )}

      {aConfirmer === "liberer" && (
        <ConfirmDialog
          title="Libérer le dossier"
          message="Libérer ce dossier sans le marquer comme traité ?"
          detail="Il redeviendra « À traiter » pour les autres utilisateurs. Le commentaire en cours de saisie n'est pas enregistré."
          confirmLabel="Libérer le dossier"
          loadingLabel="Libération..."
          tone="primary"
          loading={busy}
          onConfirm={() => void confirmer()}
          onCancel={() => setAConfirmer(null)}
        />
      )}

      {aConfirmer === "rouvrir" && (
        <ConfirmDialog
          title="Rouvrir le dossier"
          message="Rouvrir ce dossier traité ?"
          detail="Ses anomalies non régularisées repasseront « À traiter » et le dossier sera libéré. Le commentaire déjà enregistré est conservé ; l'agent pourra le reprendre."
          confirmLabel="Rouvrir le dossier"
          loadingLabel="Réouverture..."
          tone="primary"
          loading={busy}
          onConfirm={() => void confirmer()}
          onCancel={() => setAConfirmer(null)}
        />
      )}
    </div>
  );
}
