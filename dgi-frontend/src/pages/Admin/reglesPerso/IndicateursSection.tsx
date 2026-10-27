import { useEffect, useState, type FormEvent } from "react";
import api, { getErrorMessage } from "../../../services/api";
import type { IndicateurAdmin } from "../../../typesReglesPerso";
import {
  ConfirmDialog,
  DeleteButton,
  ErrorBox,
  Field,
  FormActions,
  FormGrid,
  inputClass,
  primaryButtonClass,
} from "../../../components/ui";

interface Reponse {
  indicateurs: IndicateurAdmin[];
  colonnes_disponibles: { colonne: string; type: string }[];
}

/** Indicateurs : valeurs lues dans les colonnes numériques de la table des déclarations. */
export default function IndicateursSection({ impots, onChange }: { impots: string[]; onChange: () => void }) {
  const [data, setData] = useState<Reponse | null>(null);
  const [error, setError] = useState<string>("");
  const [saving, setSaving] = useState<boolean>(false);
  const [code, setCode] = useState<string>("");
  const [libelle, setLibelle] = useState<string>("");
  const [colonne, setColonne] = useState<string>("");
  const [unite, setUnite] = useState<string>("");
  const [choisis, setChoisis] = useState<string[]>([]);
  const [aSupprimer, setASupprimer] = useState<IndicateurAdmin | null>(null);
  const [supprimant, setSupprimant] = useState<boolean>(false);

  function charger() {
    api
      .get<Reponse>("/indicateurs")
      .then((res) => setData(res.data))
      .catch((err) => setError(getErrorMessage(err, "Impossible de charger les indicateurs.")));
  }

  useEffect(charger, []);

  async function ajouter(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError("");
    try {
      await api.post("/indicateurs", { code, libelle, colonne, unite: unite || null, impots: choisis.length > 0 ? choisis : null });
      setCode("");
      setLibelle("");
      setColonne("");
      setUnite("");
      setChoisis([]);
      charger();
      onChange();
    } catch (err) {
      setError(getErrorMessage(err, "Ajout impossible."));
    } finally {
      setSaving(false);
    }
  }

  async function basculer(i: IndicateurAdmin) {
    setError("");
    try {
      await api.put(`/indicateurs/${i.id}`, { actif: !i.actif });
      charger();
      onChange();
    } catch (err) {
      setError(getErrorMessage(err, "Modification impossible."));
    }
  }

  async function supprimer(i: IndicateurAdmin) {
    setSupprimant(true);
    setError("");
    try {
      await api.delete(`/indicateurs/${i.id}`);
      setASupprimer(null);
      charger();
      onChange();
    } catch (err) {
      setASupprimer(null);
      setError(getErrorMessage(err, "Suppression impossible."));
    } finally {
      setSupprimant(false);
    }
  }

  return (
    <details className="rounded-xl bg-white p-5 border border-line sm:p-6">
      <summary className="cursor-pointer text-lg font-semibold text-brand-blue">Indicateurs ({data?.indicateurs.length ?? "…"})</summary>
      <p className="mt-2 max-w-3xl text-sm text-slate-600">
        Un indicateur est une valeur lue dans une colonne numérique de la table des déclarations (chiffre d'affaires, montant dû, etc.). Les règles personnalisées s'appuient sur eux.
      </p>

      <ErrorBox message={error} />

      <ul className="mt-4 divide-y divide-line">
        {data?.indicateurs.map((i) => (
          <li key={i.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
            <div className="min-w-0">
              <span className="font-medium text-brand-blue">{i.libelle}</span>{" "}
              <span className="text-xs text-slate-500">
                ({i.code} · colonne {i.colonne}
                {i.unite ? ` · ${i.unite}` : ""}
                {i.impots ? ` · ${i.impots.join(", ")}` : ""})
              </span>
              {i.systeme && <span className="ml-2 text-xs text-slate-500">fourni</span>}
              {i.utilise && <span className="ml-2 text-xs text-slate-500">utilisé</span>}
            </div>
            <div className="flex items-center gap-3">
              <label className="flex items-center gap-2 text-xs text-slate-700">
                <input type="checkbox" className="h-4 w-4 accent-brand-blue" checked={i.actif} onChange={() => basculer(i)} />
                Actif
              </label>
              {!i.systeme && !i.utilise && <DeleteButton label="Supprimer l'indicateur" onClick={() => setASupprimer(i)} />}
            </div>
          </li>
        ))}
      </ul>

      <form onSubmit={ajouter} className="mt-5 border-t border-line pt-4">
        <p className="mb-3 text-sm font-semibold text-brand-blue">Ajouter un indicateur</p>
        <FormGrid>
          <Field label="Libellé" required hint="Nom affiché dans les règles et les conditions.">
            <input className={inputClass} value={libelle} onChange={(e) => setLibelle(e.target.value)} required maxLength={150} />
          </Field>
          <Field label="Code" required hint="Lettres minuscules, chiffres et _, commençant par une lettre (ex. ca_declare).">
            <input className={inputClass} value={code} onChange={(e) => setCode(e.target.value)} required pattern="[a-z][a-z0-9_]{1,39}" />
          </Field>
          <Field label="Colonne de la table des déclarations" required className="sm:col-span-2">
            <select className={inputClass} value={colonne} onChange={(e) => setColonne(e.target.value)} required>
              <option value="">— choisir —</option>
              {data?.colonnes_disponibles.map((c) => (
                <option key={c.colonne} value={c.colonne}>
                  {c.colonne}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Unité" optional hint="Affichée à côté des valeurs (ex. Ar, %, nombre).">
            <input className={inputClass} value={unite} onChange={(e) => setUnite(e.target.value)} maxLength={20} placeholder="Ar" />
          </Field>
          <div className="sm:col-span-2">
            <span className="mb-1 block text-sm font-medium text-slate-700">
              Impôts concernés <span className="text-xs font-normal text-slate-400">(aucun coché = tous)</span>
            </span>
            <div className="flex flex-wrap gap-3">
              {impots.map((impot) => (
                <label key={impot} className="flex items-center gap-2 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    className="h-4 w-4 accent-brand-blue"
                    checked={choisis.includes(impot)}
                    onChange={(e) => setChoisis(e.target.checked ? [...choisis, impot] : choisis.filter((x) => x !== impot))}
                  />
                  {impot}
                </label>
              ))}
            </div>
          </div>
        </FormGrid>
        <FormActions>
          <button type="submit" className={primaryButtonClass} disabled={saving}>
            {saving ? "Ajout..." : "Ajouter"}
          </button>
        </FormActions>
      </form>

      {aSupprimer && (
        <ConfirmDialog
          title="Supprimer l'indicateur"
          message={`Supprimer l'indicateur « ${aSupprimer.libelle} » ?`}
          detail="Les règles personnalisées qui l'utilisent ne pourront plus être simulées tant qu'elles ne seront pas modifiées."
          confirmLabel="Supprimer"
          loadingLabel="Suppression..."
          loading={supprimant}
          onConfirm={() => void supprimer(aSupprimer)}
          onCancel={() => setASupprimer(null)}
        />
      )}
    </details>
  );
}
