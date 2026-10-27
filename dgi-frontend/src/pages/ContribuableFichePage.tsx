import { useCallback, useEffect, useState, type ReactNode } from "react";
import { Link, useParams } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { ErrorBox, Field, IconButton, inputClass } from "../components/ui";
import { IconCheck, IconClose, IconDownload, IconEdit, IconPlus, IconPrinter } from "../components/icons";
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
import {
  REGLE_LABELS,
  type ContribuableFiche as Fiche,
  type Regle,
} from "../types";

const STAT_TONE: Record<string, string> = {
  neutral: "text-brand-blue",
  warning: "text-amber-700",
  danger: "text-red-700",
  success: "text-brand-blue",
  muted: "text-slate-500",
};

function nombre(v: number | null | undefined): string {
  return v === null || v === undefined ? "—" : `${v.toLocaleString("fr-FR")} Ar`;
}

function pourcent(v: number | null): string {
  return v === null ? "—" : `${v.toLocaleString("fr-FR", { maximumFractionDigits: 1 })} %`;
}

function Info({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-0.5 text-sm text-slate-900">{children}</dd>
    </div>
  );
}

function Stat({ label, value, tone = "neutral" }: { label: string; value: number | string; tone?: keyof typeof STAT_TONE }) {
  return (
    <div className="rounded-xl bg-slate-50 p-3">
      <p className="text-xs text-slate-500">{label}</p>
      <p className={`text-xl font-semibold ${STAT_TONE[tone]}`}>{value}</p>
    </div>
  );
}

type ContactForm = { telephone: string; email: string; adresse: string; activite: string };

/** Fiche de résultat d'un contribuable : synthèse de l'analyse, coordonnées, observations, export PDF. */
export default function ContribuableFichePage() {
  const { id } = useParams();
  const [fiche, setFiche] = useState<Fiche | null>(null);
  const [error, setError] = useState<string>("");
  const [message, setMessage] = useState<string>("");
  const [busy, setBusy] = useState<boolean>(false);

  const [contactOuvert, setContactOuvert] = useState<boolean>(false);
  const [contact, setContact] = useState<ContactForm>({ telephone: "", email: "", adresse: "", activite: "" });
  const [observation, setObservation] = useState<string>("");

  const charger = useCallback(async () => {
    try {
      const res = await api.get<Fiche>(`/contribuables/${id}`);
      setFiche(res.data);
      setError("");
    } catch (err) {
      setError(getErrorMessage(err, "Impossible de charger la fiche du contribuable."));
    }
  }, [id]);

  useEffect(() => {
    void charger();
  }, [charger]);

  async function action(fn: () => Promise<void>, ok?: string) {
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await fn();
      if (ok) setMessage(ok);
    } catch (err) {
      setError(getErrorMessage(err, "Action impossible."));
    } finally {
      setBusy(false);
    }
  }

  const exporterPdf = () =>
    action(async () => {
      const res = await api.get(`/contribuables/${id}/fiche.pdf`, { responseType: "blob" });
      const url = URL.createObjectURL(res.data as Blob);
      const lien = document.createElement("a");
      lien.href = url;
      lien.download = `fiche-${fiche?.contribuable.nif ?? id}.pdf`;
      document.body.appendChild(lien);
      lien.click();
      lien.remove();
      URL.revokeObjectURL(url);
    });

  function ouvrirContact() {
    if (!fiche) return;
    setContact({
      telephone: fiche.contribuable.telephone ?? "",
      email: fiche.contribuable.email ?? "",
      adresse: fiche.contribuable.adresse ?? "",
      activite: fiche.contribuable.activite ?? "",
    });
    setContactOuvert(true);
  }

  const enregistrerContact = () =>
    action(async () => {
      const res = await api.patch<Fiche>(`/contribuables/${id}/contact`, contact);
      setFiche(res.data);
      setContactOuvert(false);
    }, "Coordonnées enregistrées.");

  const ajouterObservation = () =>
    action(async () => {
      if (!observation.trim()) return;
      const res = await api.post<Fiche>(`/contribuables/${id}/observations`, { texte: observation.trim() });
      setFiche(res.data);
      setObservation("");
    }, "Observation ajoutée au journal.");

  if (!fiche) {
    return (
      <div className="space-y-4">
        <Link to="/defaillances" className="text-sm font-medium text-brand-blue underline">
          ← Retour aux alertes
        </Link>
        <ErrorBox message={error} />
        {!error && <p className="text-sm text-slate-500">Chargement de la fiche...</p>}
      </div>
    );
  }

  const c = fiche.contribuable;
  const r = fiche.resultat;
  const baisses = new Set(
    fiche.alertes.filter((a) => a.regle === "baisse_ca").map((a) => `${a.type_impot}|${a.annee_fiscale}|${a.mois}`)
  );

  const reglesPresentes = (Object.entries(r.par_regle) as [Regle, number][]).filter(([, n]) => n > 0);

  const chiffres: { label: string; valeur: string }[] = [];
  if (fiche.chiffres.ir) {
    const ir = fiche.chiffres.ir;
    chiffres.push(
      { label: `CA déclaré à l'IR (exercice ${ir.annee})`, valeur: nombre(ir.ca) },
      { label: `Résultat fiscal IR (exercice ${ir.annee})`, valeur: nombre(ir.resultat) },
      { label: `IR à payer (exercice ${ir.annee})`, valeur: nombre(ir.ir_a_payer) }
    );
  }
  if (fiche.chiffres.tva) {
    const tva = fiche.chiffres.tva;
    chiffres.push(
      { label: `CA déclaré à la TVA (${tva.nb_mois} mois, ${tva.annee})`, valeur: nombre(tva.ca) },
      { label: `CA taxable à l'export (${tva.annee})`, valeur: nombre(tva.export) },
      { label: `Crédit de TVA (${libellePeriode(tva.annee, tva.dernier_mois)})`, valeur: nombre(tva.credit_tva) }
    );
  }
  if (fiche.chiffres.recoupement) {
    const rec = fiche.chiffres.recoupement;
    chiffres.push(
      { label: `Recoupement — montant total (${rec.annee})`, valeur: nombre(rec.montant_total) },
      { label: `Recoupement — prestations de service (${rec.annee})`, valeur: nombre(rec.prestations) }
    );
  }
  if (fiche.chiffres.dau) {
    const dau = fiche.chiffres.dau;
    chiffres.push(
      { label: `DAU modèle IM4 — TVA (${dau.annee})`, valeur: nombre(dau.tva_im4) },
      { label: `DAU modèle EX1 — valeur CAF (${dau.annee})`, valeur: nombre(dau.caf_ex1) }
    );
  }
  if (fiche.chiffres.ecart_ca_pct !== null) {
    chiffres.push({ label: "Écart entre CA IR et CA TVA", valeur: pourcent(fiche.chiffres.ecart_ca_pct) });
  }

  return (
    <div className="space-y-5">
      <Link to="/defaillances" className="no-print inline-block text-sm font-medium text-brand-blue underline">
        ← Retour aux alertes
      </Link>

      <div className="hidden border-b-2 border-brand-blue pb-2 print:block">
        <p className="text-xs text-slate-600">République de Madagascar · Direction Générale des Impôts</p>
        <p className="text-lg font-semibold text-brand-blue">Fiche contribuable — résultat d'analyse</p>
      </div>

      <ErrorBox message={error} />
      {message && (
        <p className="rounded-lg border border-brand-green/60 bg-leaf-50 px-3 py-2 text-sm text-brand-blue">{message}</p>
      )}

      {/* Identité et coordonnées */}
      <section className="rounded-xl border border-line bg-white p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-brand-blue">{c.nom}</h1>
            <p className="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-slate-500">
              <span>
                NIF {c.nif} · {c.centre ?? "Aucun centre"}
              </span>
              <EtatBadge fiche={c} />
            </p>
          </div>
          <div className="no-print flex flex-wrap gap-2">
            <IconButton label="Exporter en PDF" tone="primary" onClick={() => void exporterPdf()} disabled={busy}>
              <IconDownload className="h-4 w-4" />
            </IconButton>
            <IconButton label="Imprimer" onClick={() => window.print()} disabled={busy}>
              <IconPrinter className="h-4 w-4" />
            </IconButton>
            <IconButton label="Modifier les coordonnées" onClick={ouvrirContact} disabled={busy}>
              <IconEdit className="h-4 w-4" />
            </IconButton>
          </div>
        </div>

        <dl className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Info label="Activité">{c.activite || <span className="text-slate-400">Non renseignée</span>}</Info>
          <Info label="Téléphone">
            {c.telephone ? (
              <a href={`tel:${c.telephone.replace(/\s/g, "")}`} className="text-brand-blue underline">
                {c.telephone}
              </a>
            ) : (
              <span className="text-slate-400">Non renseigné</span>
            )}
          </Info>
          <Info label="E-mail">
            {c.email ? (
              <a href={`mailto:${c.email}`} className="text-brand-blue underline">
                {c.email}
              </a>
            ) : (
              <span className="text-slate-400">Non renseigné</span>
            )}
          </Info>
          <Info label="Adresse">{c.adresse || <span className="text-slate-400">Non renseignée</span>}</Info>
        </dl>

        {contactOuvert && (
          <form
            className="no-print mt-4 grid gap-3 rounded-xl border border-line bg-slate-50 p-4 sm:grid-cols-2"
            onSubmit={(e) => {
              e.preventDefault();
              void enregistrerContact();
            }}
          >
            <Field label="Téléphone" optional>
              <input
                className={inputClass}
                value={contact.telephone}
                onChange={(e) => setContact({ ...contact, telephone: e.target.value })}
                placeholder="034 00 000 00"
              />
            </Field>
            <Field label="E-mail" optional>
              <input
                type="email"
                className={inputClass}
                value={contact.email}
                onChange={(e) => setContact({ ...contact, email: e.target.value })}
                placeholder="contact@societe.mg"
              />
            </Field>
            <Field label="Adresse" optional>
              <input
                className={inputClass}
                value={contact.adresse}
                onChange={(e) => setContact({ ...contact, adresse: e.target.value })}
                placeholder="Lot II M 12 bis, Antananarivo"
              />
            </Field>
            <Field label="Activité" optional>
              <input
                className={inputClass}
                value={contact.activite}
                onChange={(e) => setContact({ ...contact, activite: e.target.value })}
                placeholder="Commerce de gros"
              />
            </Field>
            <div className="flex items-end gap-2 sm:col-span-2">
              <IconButton label="Enregistrer les coordonnées" tone="primary" type="submit" disabled={busy}>
                <IconCheck className="h-4 w-4" />
              </IconButton>
              <IconButton label="Annuler" onClick={() => setContactOuvert(false)} disabled={busy}>
                <IconClose className="h-4 w-4" />
              </IconButton>
            </div>
          </form>
        )}
      </section>

      {/* Résultat de l'analyse */}
      <section className="rounded-xl border border-line bg-white p-5">
        <h2 className="text-sm font-semibold text-brand-blue">Résultat de l'analyse</h2>
        <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <Stat label="Anomalies" value={r.alertes_total} />
          <Stat label="À traiter" value={r.par_statut.a_traiter} tone="warning" />
          <Stat label="En traitement" value={r.par_statut.en_traitement} tone="warning" />
          <Stat label="Traitées" value={r.par_statut.traitee} tone="success" />
          <Stat label="Régularisées" value={r.par_statut.regularisee} tone="muted" />
        </div>

        <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-600">
          <span className="flex items-center gap-2">
            Niveau global :
            {r.niveau_global ? <NiveauBadge niveau={r.niveau_global} /> : <span className="font-medium text-slate-500">aucun risque en cours</span>}
          </span>
          <span>
            Élevé {r.par_niveau.eleve} · moyen {r.par_niveau.moyen} · faible {r.par_niveau.faible}
          </span>
          <span>
            Dernière analyse :{" "}
            {r.derniere_analyse?.at ? formatDateHeure(r.derniere_analyse.at) : "non encore exécutée"}
          </span>
        </div>

        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[420px] text-sm">
            <thead>
              <tr className="border-b border-line text-left text-xs uppercase tracking-wide text-slate-500">
                <th className="py-2">Règle</th>
                <th className="py-2 text-right">Anomalies</th>
                <th className="py-2 text-right">Part</th>
              </tr>
            </thead>
            <tbody>
              {reglesPresentes.length === 0 ? (
                <tr>
                  <td colSpan={3} className="py-3 text-slate-500">
                    Aucune anomalie enregistrée pour ce contribuable.
                  </td>
                </tr>
              ) : (
                reglesPresentes.map(([code, n]) => (
                  <tr key={code} className="border-b border-line/70">
                    <td className="py-2">
                      <RegleBadge regle={code} />
                      <span className="ml-2 text-slate-500">{REGLE_LABELS[code]}</span>
                    </td>
                    <td className="py-2 text-right font-semibold text-slate-800">{n}</td>
                    <td className="py-2 text-right text-slate-600">
                      {r.alertes_total > 0 ? pourcent((n / r.alertes_total) * 100) : "—"}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>

      {/* Chiffres clés */}
      <section className="rounded-xl border border-line bg-white p-5">
        <h2 className="text-sm font-semibold text-brand-blue">Chiffres clés</h2>
        <p className="text-xs text-slate-500">
          Valeurs du dernier exercice disponible dans la base d'analyse risque (déclarations, recoupement, douane).
        </p>
        {chiffres.length === 0 ? (
          <p className="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-500">
            Aucune donnée dans la base d'analyse risque pour ce contribuable.
          </p>
        ) : (
          <dl className="mt-3 grid gap-x-8 gap-y-1 sm:grid-cols-2">
            {chiffres.map((ligne) => (
              <div key={ligne.label} className="flex items-baseline justify-between gap-3 border-b border-line pb-1.5">
                <dt className="text-sm text-slate-600">{ligne.label}</dt>
                <dd className="shrink-0 text-sm font-semibold text-slate-900">{ligne.valeur}</dd>
              </div>
            ))}
          </dl>
        )}
      </section>

      {/* Observations */}
      <section className="rounded-xl border border-line bg-white p-5">
        <h2 className="text-sm font-semibold text-brand-blue">Observations</h2>
        <p className="text-xs text-slate-500">
          Constats de l'agent, décision et suites à donner. Chaque note est datée et signée ; les observations sont reprises dans le PDF.
        </p>

        <form
          className="no-print mt-3"
          onSubmit={(e) => {
            e.preventDefault();
            void ajouterObservation();
          }}
        >
          <textarea
            className={`${inputClass} min-h-[90px]`}
            value={observation}
            onChange={(e) => setObservation(e.target.value)}
            maxLength={2000}
            placeholder="Ex. : déclarations TVA de mars à juin non déposées ; relance téléphonique faite le 12/10, promesse de dépôt sous 15 jours."
            aria-label="Nouvelle observation"
          />
          <div className="mt-2 flex justify-end">
            <IconButton
              label="Ajouter l'observation"
              tone="primary"
              type="submit"
              disabled={busy || !observation.trim()}
            >
              <IconPlus className="h-4 w-4" />
            </IconButton>
          </div>
        </form>

        {fiche.observations.length === 0 ? (
          <p className="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-500">Aucune observation enregistrée.</p>
        ) : (
          <ul className="mt-3 space-y-2">
            {fiche.observations.map((o) => (
              <li key={o.id} className="rounded-lg border-l-4 border-brand-blue bg-slate-50 px-3 py-2">
                <p className="text-xs text-slate-500">
                  {o.le ? formatDateHeure(o.le) : ""} · {o.auteur ?? "Auteur supprimé"}
                </p>
                <p className="mt-1 whitespace-pre-line text-sm text-slate-800">{o.texte}</p>
              </li>
            ))}
          </ul>
        )}
      </section>

      {/* Obligations déclaratives */}
      <section className="rounded-xl border border-line bg-white p-5">
        <h2 className="text-sm font-semibold text-brand-blue">Obligations déclaratives</h2>
        {fiche.obligations.length === 0 ? (
          <p className="mt-2 rounded-lg bg-slate-50 p-3 text-sm text-slate-500">
            Aucun assujettissement connu : l'obligation est déduite de la première déclaration de chaque impôt.
          </p>
        ) : (
          <div className="mt-2 flex flex-wrap gap-2">
            {fiche.obligations.map((o) => (
              <span
                key={`${o.type_impot}-${o.annee_fiscale}`}
                className={`rounded-lg px-2.5 py-1 text-xs ${
                  o.exonere || !o.actif ? "bg-slate-100 text-slate-500 line-through" : "bg-slate-50 text-brand-blue"
                }`}
                title={o.exonere ? "Exonéré" : o.actif ? "Actif" : "Inactif"}
              >
                {o.type_impot} {o.annee_fiscale} · {o.periodicite}
                {o.exonere ? " · exonéré" : ""}
              </span>
            ))}
          </div>
        )}
      </section>

      {/* Anomalies constatées */}
      <section className="rounded-xl border border-line bg-white p-5">
        <h2 className="text-sm font-semibold text-brand-blue">Anomalies constatées ({fiche.alertes.length})</h2>
        {fiche.alertes.length === 0 ? (
          <p className="mt-2 rounded-lg bg-slate-50 p-3 text-sm text-slate-500">Aucune alerte pour ce contribuable.</p>
        ) : (
          <ul className="mt-3 space-y-2">
            {fiche.alertes.map((a) => (
              <li key={a.id} className="rounded-lg border border-line p-3 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <RegleBadge regle={a.regle} />
                  <NiveauBadge niveau={a.niveau} />
                  <StatutBadge statut={a.statut} />
                  <span className="font-medium text-slate-700">{a.type_impot}</span>
                  <span className="text-slate-500">
                    · {a.regle === "baisse_ca" ? libellePeriode(a.annee_fiscale, a.mois) : `exercice ${a.annee_fiscale}`}
                  </span>
                </div>
                <p className="mt-2 leading-relaxed text-slate-800">{a.motif}</p>
                <div className="mt-1 text-xs text-slate-500">
                  <PeriodeAnomalie a={a} />
                </div>
                {a.commentaire && <p className="mt-2 text-xs italic text-slate-500">Commentaire : {a.commentaire}</p>}
                {a.statut === "traitee" && a.traite_par && (
                  <p className="mt-1 text-xs text-slate-500">
                    Traitée par {a.traite_par}
                    {a.traite_le ? `, le ${formatDate(a.traite_le)}` : ""}
                  </p>
                )}
                {a.statut !== "regularisee" && (
                  <p className="no-print mt-2">
                    <Link to={`/defaillances/${a.id}/traitement`} className="text-xs font-medium text-brand-blue underline">
                      Ouvrir le dossier de traitement →
                    </Link>
                  </p>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>

      {/* Historique des déclarations */}
      <section className="space-y-4 rounded-xl border border-line bg-white p-5">
        <div>
          <h2 className="text-sm font-semibold text-brand-blue">Historique des déclarations</h2>
          <p className="text-xs text-slate-500">
            ✔ déclaré · ▼ baisse du CA · ✘ non déposé, date limite dépassée · … pas encore dû · · aucune obligation
          </p>
        </div>
        {fiche.historique.mensuel.impots.length === 0 && fiche.historique.annuel.impots.length === 0 ? (
          <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-500">Aucune déclaration enregistrée.</p>
        ) : (
          <>
            <Matrice
              titre="Déclarations mensuelles (12 derniers mois)"
              entetes={fiche.historique.mensuel.periodes.map((p) => `${String(p.mois).padStart(2, "0")}/${String(p.annee).slice(2)}`)}
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
        <p className="text-xs text-slate-500">
          {fiche.stats.nb_declarations} déclaration{fiche.stats.nb_declarations > 1 ? "s" : ""} enregistrée
          {fiche.stats.nb_declarations > 1 ? "s" : ""} · première année {fiche.stats.premiere_annee ?? "—"} · dernière année{" "}
          {fiche.stats.derniere_annee ?? "—"}
        </p>
      </section>

      <p className="text-xs text-slate-500">Document interne — montants exprimés en ariary.</p>
    </div>
  );
}
