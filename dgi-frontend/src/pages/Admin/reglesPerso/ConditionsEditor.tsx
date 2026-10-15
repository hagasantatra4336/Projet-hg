import type { Condition, GroupeConditions, IndicateurOption, OptionsReglesPerso } from "../../../typesReglesPerso";
import { COMPARATEUR_LABELS, MESURE_LABELS, estGroupe } from "../../../typesReglesPerso";
import { DeleteButton, inputClass } from "../../../components/ui";

type Noeud = Condition | GroupeConditions;

export function nouvelleCondition(indicateurs: IndicateurOption[]): Condition {
  return { indicateur: indicateurs[0]?.code ?? "", mesure: "valeur", comparateur: ">=", valeur: 0 };
}

export function compter(g: GroupeConditions): number {
  return g.conditions.reduce((n, c) => n + (estGroupe(c) ? compter(c) : 1), 0);
}

/** Phrase lisible d'un arbre de conditions (affichage dans la carte de la règle). */
export function decrire(n: Noeud, indicateurs: IndicateurOption[]): string {
  const nom = (code?: string | null) => indicateurs.find((i) => i.code === code)?.libelle ?? code ?? "?";

  if (estGroupe(n)) {
    const parties = n.conditions.map((c) => (estGroupe(c) ? `(${decrire(c, indicateurs)})` : decrire(c, indicateurs)));
    return parties.join(n.operateur === "ou" ? " OU " : " ET ");
  }

  const sujet = n.mesure === "ratio_pct" ? `${nom(n.indicateur)} ÷ ${nom(n.indicateur_b)} (%)` : `${nom(n.indicateur)} — ${MESURE_LABELS[n.mesure] ?? n.mesure}`;
  const comp = COMPARATEUR_LABELS[n.comparateur] ?? n.comparateur;

  return n.comparateur === "vide" || n.comparateur === "non_vide" ? `${sujet} ${comp}` : `${sujet} ${comp} ${n.valeur ?? "?"}`;
}

function LigneCondition({
  c,
  options,
  onChange,
  onRemove,
  removable,
}: {
  c: Condition;
  options: OptionsReglesPerso;
  onChange: (c: Condition) => void;
  onRemove: () => void;
  removable: boolean;
}) {
  const sansValeur = c.comparateur === "vide" || c.comparateur === "non_vide";

  return (
    <div className="flex flex-wrap items-center gap-2 text-sm text-slate-700">
      <select className={`${inputClass} w-auto`} value={c.indicateur} onChange={(e) => onChange({ ...c, indicateur: e.target.value })} aria-label="Indicateur">
        {options.indicateurs.map((i) => (
          <option key={i.code} value={i.code}>
            {i.libelle}
          </option>
        ))}
      </select>
      <select
        className={`${inputClass} w-auto`}
        value={c.mesure}
        aria-label="Mesure"
        onChange={(e) => {
          const mesure = e.target.value;
          // « vide / renseignée » n'a de sens que sur la valeur déclarée
          const comparateur = mesure !== "valeur" && sansValeur ? ">=" : c.comparateur;
          onChange({ ...c, mesure, comparateur, indicateur_b: mesure === "ratio_pct" ? c.indicateur_b ?? options.indicateurs[0]?.code ?? null : null });
        }}
      >
        {options.mesures.map((m) => (
          <option key={m} value={m}>
            {MESURE_LABELS[m] ?? m}
          </option>
        ))}
      </select>
      {c.mesure === "ratio_pct" && (
        <>
          <span>÷</span>
          <select className={`${inputClass} w-auto`} value={c.indicateur_b ?? ""} onChange={(e) => onChange({ ...c, indicateur_b: e.target.value })} aria-label="Second indicateur">
            {options.indicateurs.map((i) => (
              <option key={i.code} value={i.code}>
                {i.libelle}
              </option>
            ))}
          </select>
        </>
      )}
      <select className={`${inputClass} w-auto`} value={c.comparateur} onChange={(e) => onChange({ ...c, comparateur: e.target.value })} aria-label="Comparateur">
        {options.comparateurs
          .filter((k) => c.mesure === "valeur" || (k !== "vide" && k !== "non_vide"))
          .map((k) => (
            <option key={k} value={k}>
              {COMPARATEUR_LABELS[k] ?? k}
            </option>
          ))}
      </select>
      {!sansValeur && (
        <input
          type="number"
          step="any"
          className={`${inputClass} w-32`}
          value={String(c.valeur ?? "")}
          onChange={(e) => onChange({ ...c, valeur: e.target.value === "" ? "" : Number(e.target.value) })}
          aria-label="Valeur"
        />
      )}
      {removable && <DeleteButton label="Retirer la condition" onClick={onRemove} />}
    </div>
  );
}

/** Éditeur d'arbre ET/OU : conditions et sous-groupes (2 niveaux au plus). */
export default function ConditionsEditor({
  groupe,
  options,
  onChange,
  profondeur = 1,
  onRemove,
  total,
}: {
  groupe: GroupeConditions;
  options: OptionsReglesPerso;
  onChange: (g: GroupeConditions) => void;
  profondeur?: number;
  onRemove?: () => void;
  total: number; // nombre de conditions de toute la règle (limite globale)
}) {
  const maj = (i: number, n: Noeud) => onChange({ ...groupe, conditions: groupe.conditions.map((x, k) => (k === i ? n : x)) });
  const retirer = (i: number) => onChange({ ...groupe, conditions: groupe.conditions.filter((_, k) => k !== i) });
  const peutAjouter = total < options.max_conditions;

  return (
    <div className={`space-y-3 rounded-xl border border-slate-300 p-3 ${profondeur > 1 ? "bg-slate-50" : ""}`}>
      <div className="flex flex-wrap items-center gap-2 text-sm text-slate-700">
        <span>{profondeur > 1 ? "Groupe :" : "La règle se déclenche si"}</span>
        <select className={`${inputClass} w-auto`} value={groupe.operateur} onChange={(e) => onChange({ ...groupe, operateur: e.target.value as "et" | "ou" })} aria-label="Opérateur">
          <option value="et">toutes les conditions sont vraies (ET)</option>
          <option value="ou">au moins une condition est vraie (OU)</option>
        </select>
        {onRemove && <DeleteButton label="Retirer le groupe" onClick={onRemove} />}
      </div>

      {groupe.conditions.map((n, i) =>
        estGroupe(n) ? (
          <ConditionsEditor key={i} groupe={n} options={options} profondeur={profondeur + 1} total={total} onChange={(g) => maj(i, g)} onRemove={() => retirer(i)} />
        ) : (
          <LigneCondition key={i} c={n} options={options} onChange={(c) => maj(i, c)} onRemove={() => retirer(i)} removable={groupe.conditions.length > 1 || profondeur > 1} />
        )
      )}

      <div className="flex flex-wrap gap-4 text-sm">
        <button type="button" disabled={!peutAjouter} className="font-medium text-brand-blue underline disabled:opacity-40" onClick={() => onChange({ ...groupe, conditions: [...groupe.conditions, nouvelleCondition(options.indicateurs)] })}>
          Ajouter une condition
        </button>
        {profondeur < options.max_profondeur && (
          <button
            type="button"
            disabled={!peutAjouter}
            className="font-medium text-brand-blue underline disabled:opacity-40"
            onClick={() => onChange({ ...groupe, conditions: [...groupe.conditions, { operateur: groupe.operateur === "et" ? "ou" : "et", conditions: [nouvelleCondition(options.indicateurs)] }] })}
          >
            Ajouter un groupe
          </button>
        )}
      </div>
    </div>
  );
}
