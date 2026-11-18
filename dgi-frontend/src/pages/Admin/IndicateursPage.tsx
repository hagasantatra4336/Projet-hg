import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import api, { getErrorMessage } from "../../services/api";
import type { IndicateurAdmin } from "../../typesReglesPerso";
import {
  ConfirmDialog,
  DeleteButton,
  EditButton,
  ErrorBox,
  Field,
  FormActions,
  FormGrid,
  Modal,
  inputClass,
  primaryButtonClass,
  secondaryButtonClass,
} from "../../components/ui";

interface Reponse {
  indicateurs: IndicateurAdmin[];
  colonnes_disponibles: { colonne: string; type: string }[];
  impots_disponibles: string[];
}

interface IndicateurForm {
  code: string;
  libelle: string;
  description: string;
  colonne: string;
  unite: string;
  impots: string[];
  actif: boolean;
}

const EMPTY_FORM: IndicateurForm = {
  code: "",
  libelle: "",
  description: "",
  colonne: "",
  unite: "",
  impots: [],
  actif: true,
};

const normalize = (value: string): string =>
  value.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();

/** Badge de statut : actif ou inactif. */
function StatutBadge({ actif }: { actif: boolean }) {
  return (
    <span
      className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold leading-5 ring-1 ring-inset ${
        actif
          ? "bg-brand-green-soft text-brand-green ring-brand-green/25"
          : "bg-slate-50 text-slate-600 ring-slate-200"
      }`}
    >
      {actif ? "Actif" : "Inactif"}
    </span>
  );
}

/**
 * Catalogue des indicateurs : chaque indicateur lit une colonne numérique de la table
 * « declarations ». Les règles personnalisées s'appuient sur ces indicateurs.
 */
export default function IndicateursPage() {
  const [data, setData] = useState<Reponse | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string>("");
  const [search, setSearch] = useState<string>("");
  const [statutFilter, setStatutFilter] = useState<"tous" | "actifs" | "inactifs">("tous");

  const [editing, setEditing] = useState<IndicateurAdmin | "new" | null>(null);
  const [form, setForm] = useState<IndicateurForm>(EMPTY_FORM);
  const [formError, setFormError] = useState<string>("");
  const [saving, setSaving] = useState<boolean>(false);

  const [toDelete, setToDelete] = useState<IndicateurAdmin | null>(null);
  const [deleting, setDeleting] = useState<boolean>(false);

  const load = useCallback(async () => {
    try {
      const res = await api.get<Reponse>("/indicateurs");
      setData(res.data);
      setError("");
    } catch (err) {
      setError(getErrorMessage(err, "Impossible de charger les indicateurs."));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const filtered = useMemo(() => {
    const term = normalize(search.trim());
    return (data?.indicateurs ?? []).filter((i) => {
      const correspondStatut =
        statutFilter === "tous" || (statutFilter === "actifs" ? i.actif : !i.actif);
      const correspondRecherche =
        !term ||
        normalize(`${i.libelle} ${i.code} ${i.colonne} ${i.unite ?? ""}`).includes(term);
      return correspondStatut && correspondRecherche;
    });
  }, [data, search, statutFilter]);

  const nbActifs = (data?.indicateurs ?? []).filter((i) => i.actif).length;
  const nbUtilises = (data?.indicateurs ?? []).filter((i) => i.utilise).length;

  function openNew() {
    setForm({ ...EMPTY_FORM, colonne: data?.colonnes_disponibles[0]?.colonne ?? "" });
    setFormError("");
    setEditing("new");
  }

  function openEdit(i: IndicateurAdmin) {
    setForm({
      code: i.code,
      libelle: i.libelle,
      description: i.description ?? "",
      colonne: i.colonne,
      unite: i.unite ?? "",
      impots: i.impots ?? [],
      actif: i.actif,
    });
    setFormError("");
    setEditing(i);
  }

  async function enregistrer(e: FormEvent) {
    e.preventDefault();
    setFormError("");
    setSaving(true);

    const payload = {
      code: form.code,
      libelle: form.libelle,
      description: form.description.trim() === "" ? null : form.description,
      colonne: form.colonne,
      unite: form.unite.trim() === "" ? null : form.unite,
      impots: form.impots.length > 0 ? form.impots : null,
      actif: form.actif,
    };

    try {
      if (editing === "new") {
        await api.post("/indicateurs", payload);
      } else if (editing) {
        await api.put(`/indicateurs/${editing.id}`, payload);
      }
      setEditing(null);
      await load();
    } catch (err) {
      setFormError(getErrorMessage(err, "Enregistrement impossible."));
    } finally {
      setSaving(false);
    }
  }

  async function supprimer() {
    if (!toDelete) return;
    setDeleting(true);
    try {
      await api.delete(`/indicateurs/${toDelete.id}`);
      setToDelete(null);
      await load();
    } catch (err) {
      setToDelete(null);
      setError(getErrorMessage(err, "Suppression impossible."));
    } finally {
      setDeleting(false);
    }
  }

  const creation = editing === "new";
  const verrouille = editing !== null && editing !== "new" && editing.systeme;
  // Un indicateur déjà utilisé par une règle garde son code et sa colonne : les changer
  // modifierait silencieusement les conditions de la règle.
  const champsFiges = editing !== null && editing !== "new" && editing.utilise;
  const desactivationBloquee =
    editing !== null && editing !== "new" && editing.utilise && editing.actif;

  return (
    <div className="space-y-5">
      <p className="max-w-3xl text-sm text-slate-600">
        Un indicateur est une valeur lue dans une colonne numérique de la table des déclarations
        (chiffre d'affaires, montant dû, etc.). Les <strong>règles personnalisées</strong> s'appuient
        sur ces indicateurs pour construire leurs conditions.
      </p>

      {/* Barre d'outils : recherche, filtre et création */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-1 flex-wrap items-center gap-2 sm:flex-nowrap">
          <input
            className={`${inputClass} h-10 sm:w-72`}
            type="search"
            aria-label="Rechercher un indicateur"
            placeholder="Rechercher par libellé, code ou colonne..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          <select
            className={`${inputClass} h-10 sm:w-48`}
            aria-label="Filtrer par statut"
            value={statutFilter}
            onChange={(e) => {
              const v = e.target.value;
              if (v === "tous" || v === "actifs" || v === "inactifs") setStatutFilter(v);
            }}
          >
            <option value="tous">Tous les statuts</option>
            <option value="actifs">Actifs</option>
            <option value="inactifs">Inactifs</option>
          </select>
          <p className="text-sm text-slate-600 sm:ml-1">
            {filtered.length} indicateur(s)
            {(search.trim() || statutFilter !== "tous") &&
              ` sur ${data?.indicateurs.length ?? 0}`}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Link
            to="/admin/regles-personnalisees"
            className={`${secondaryButtonClass} inline-flex items-center justify-center`}
          >
            Règles personnalisées
          </Link>
          <button type="button" className={primaryButtonClass} onClick={openNew}>
            + Nouvel indicateur
          </button>
        </div>
      </div>

      {/* Repères rapides */}
      <div className="grid gap-3 sm:grid-cols-3">
        <div className="rounded-xl border border-line bg-white px-4 py-3">
          <p className="text-xs font-medium uppercase tracking-wider text-slate-500">Catalogue</p>
          <p className="mt-1 text-2xl font-semibold text-brand-blue">
            {data?.indicateurs.length ?? "…"}
          </p>
        </div>
        <div className="rounded-xl border border-line bg-white px-4 py-3">
          <p className="text-xs font-medium uppercase tracking-wider text-slate-500">Actifs</p>
          <p className="mt-1 text-2xl font-semibold text-brand-green">{nbActifs}</p>
        </div>
        <div className="rounded-xl border border-line bg-white px-4 py-3">
          <p className="text-xs font-medium uppercase tracking-wider text-slate-500">
            Utilisés par une règle
          </p>
          <p className="mt-1 text-2xl font-semibold text-brand-gold-dark">{nbUtilises}</p>
        </div>
      </div>

      <ErrorBox message={error} />

      <div className="overflow-x-auto rounded-xl border border-line bg-white">
        <table className="w-full text-sm">
          <thead className="border-b border-line bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">
            <tr>
              <th className="px-3 py-2">Indicateur</th>
              <th className="px-3 py-2">Colonne</th>
              <th className="px-3 py-2">Unité</th>
              <th className="px-3 py-2">Impôts</th>
              <th className="px-3 py-2">Statut</th>
              <th className="sticky right-0 z-10 w-[92px] border-l border-line bg-slate-50 px-3 py-2" />
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {loading && (
              <tr>
                <td colSpan={6} className="px-3 py-4 text-center text-slate-500">
                  Chargement...
                </td>
              </tr>
            )}
            {!loading && filtered.length === 0 && (
              <tr>
                <td colSpan={6} className="px-3 py-4 text-center text-slate-500">
                  {search.trim() || statutFilter !== "tous"
                    ? "Aucun indicateur ne correspond aux filtres sélectionnés."
                    : "Aucun indicateur. Ajoutez le premier."}
                </td>
              </tr>
            )}
            {filtered.map((i) => (
              <tr key={i.id} className="transition hover:bg-slate-50/70">
                <td className="px-3 py-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium text-brand-blue">{i.libelle}</span>
                    {i.systeme && (
                      <span
                        className="inline-flex items-center rounded-full bg-brand-azure-soft px-2 py-0.5 text-[10px] font-semibold text-brand-azure"
                        title="Indicateur fourni avec l'application : non supprimable."
                      >
                        Fourni
                      </span>
                    )}
                    {i.utilise && (
                      <span
                        className="inline-flex items-center rounded-full bg-brand-gold-soft px-2 py-0.5 text-[10px] font-semibold text-brand-gold-dark"
                        title="Utilisé par au moins une règle personnalisée."
                      >
                        Utilisé
                      </span>
                    )}
                  </div>
                  <span className="mt-0.5 block font-mono text-xs text-slate-500">{i.code}</span>
                  {i.description && (
                    <span className="mt-0.5 block max-w-xl text-xs text-slate-500">
                      {i.description}
                    </span>
                  )}
                </td>
                <td className="px-3 py-2 whitespace-nowrap font-mono text-xs text-slate-600">
                  {i.colonne}
                </td>
                <td className="px-3 py-2 text-slate-600">{i.unite ?? "—"}</td>
                <td className="px-3 py-2 text-slate-600">
                  {i.impots && i.impots.length > 0 ? i.impots.join(", ") : "Tous"}
                </td>
                <td className="px-3 py-2">
                  <StatutBadge actif={i.actif} />
                </td>
                <td className="sticky right-0 z-[1] border-l border-line bg-white px-3 py-2 text-right whitespace-nowrap">
                  <EditButton onClick={() => openEdit(i)} />
                  {!i.systeme && !i.utilise ? (
                    <DeleteButton
                      label="Supprimer l'indicateur"
                      onClick={() => setToDelete(i)}
                    />
                  ) : (
                    <span
                      className="inline-flex h-8 w-8 items-center justify-center text-slate-300"
                      title={
                        i.systeme
                          ? "Indicateur fourni avec l'application : non supprimable."
                          : "Utilisé par une règle personnalisée : modifiez la règle d'abord."
                      }
                      aria-hidden="true"
                    >
                      —
                    </span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {editing && (
        <Modal
          title={creation ? "Nouvel indicateur" : "Modifier l'indicateur"}
          onClose={() => setEditing(null)}
        >
          <ErrorBox message={formError} />
          <form onSubmit={enregistrer} className="space-y-5">
            <FormGrid>
              <Field
                label="Libellé"
                required
                className="sm:col-span-2"
                hint="Nom affiché dans les règles et les conditions."
              >
                <input
                  className={inputClass}
                  value={form.libelle}
                  onChange={(e) => setForm({ ...form, libelle: e.target.value })}
                  required
                  maxLength={150}
                  placeholder="Ex. : Chiffre d'affaires déclaré"
                />
              </Field>

              <Field
                label="Code"
                required
                hint={
                  verrouille
                    ? "Indicateur fourni : son code est figé."
                    : champsFiges
                      ? "Utilisé par une règle : le code est figé."
                      : "Lettres minuscules, chiffres et _, commençant par une lettre (ex. ca_declare)."
                }
              >
                <input
                  className={`${inputClass} font-mono`}
                  value={form.code}
                  onChange={(e) => setForm({ ...form, code: e.target.value })}
                  required
                  disabled={verrouille || champsFiges}
                  pattern="[a-z][a-z0-9_]{1,39}"
                />
              </Field>

              <Field
                label="Colonne des déclarations"
                required
                hint={
                  verrouille
                    ? "Indicateur fourni : sa colonne est figée."
                    : champsFiges
                      ? "Utilisé par une règle : la colonne est figée."
                      : undefined
                }
              >
                <select
                  className={inputClass}
                  value={form.colonne}
                  onChange={(e) => setForm({ ...form, colonne: e.target.value })}
                  required
                  disabled={verrouille || champsFiges}
                >
                  <option value="">— choisir —</option>
                  {data?.colonnes_disponibles.map((c) => (
                    <option key={c.colonne} value={c.colonne}>
                      {c.colonne}
                    </option>
                  ))}
                  {verrouille && !data?.colonnes_disponibles.some((c) => c.colonne === form.colonne) && (
                    <option value={form.colonne}>{form.colonne}</option>
                  )}
                </select>
              </Field>

              <Field label="Unité" optional hint="Affichée à côté des valeurs (ex. Ar, %, nombre).">
                <input
                  className={inputClass}
                  value={form.unite}
                  onChange={(e) => setForm({ ...form, unite: e.target.value })}
                  maxLength={20}
                  placeholder="Ar"
                />
              </Field>

              <div className="sm:col-span-2">
                <Field label="Description" optional>
                  <textarea
                    className={inputClass}
                    rows={2}
                    value={form.description}
                    onChange={(e) => setForm({ ...form, description: e.target.value })}
                    maxLength={500}
                    placeholder="Ce que représente la valeur et où elle se lit dans la déclaration."
                  />
                </Field>
              </div>

              <div className="sm:col-span-2">
                <span className="mb-1 block text-sm font-medium text-slate-700">
                  Impôts concernés{" "}
                  <span className="text-xs font-normal text-slate-400">
                    (aucun coché = tous)
                  </span>
                </span>
                <div className="flex flex-wrap gap-2">
                  {(data?.impots_disponibles ?? []).map((impot) => {
                    const coche = form.impots.includes(impot);
                    return (
                      <label
                        key={impot}
                        className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-1.5 text-sm transition ${
                          coche
                            ? "border-brand-azure bg-brand-azure-soft font-medium text-brand-azure ring-1 ring-inset ring-brand-azure/20"
                            : "border-line text-slate-600 hover:border-brand-azure/40 hover:bg-slate-50"
                        }`}
                      >
                        <input
                          type="checkbox"
                          className="h-4 w-4 accent-brand-blue"
                          checked={coche}
                          onChange={(e) =>
                            setForm({
                              ...form,
                              impots: e.target.checked
                                ? [...form.impots, impot]
                                : form.impots.filter((x) => x !== impot),
                            })
                          }
                        />
                        {impot}
                      </label>
                    );
                  })}
                </div>
              </div>

              <div className="sm:col-span-2 space-y-1 rounded-xl border border-line bg-slate-50/70 p-3.5">
                <label
                  className={`flex items-start gap-2.5 ${
                    desactivationBloquee ? "cursor-not-allowed" : "cursor-pointer"
                  }`}
                >
                  <input
                    type="checkbox"
                    className="mt-0.5 h-4 w-4 accent-brand-blue"
                    checked={form.actif}
                    disabled={desactivationBloquee}
                    onChange={(e) => setForm({ ...form, actif: e.target.checked })}
                  />
                  <span>
                    <span className="block text-sm font-medium text-slate-800">Indicateur actif</span>
                    <span className="block text-xs text-slate-500">
                      {desactivationBloquee
                        ? "Utilisé par une règle personnalisée : désactivez ou modifiez d'abord la règle."
                        : "Un indicateur inactif n'est plus proposé dans les conditions des règles."}
                    </span>
                  </span>
                </label>
              </div>
            </FormGrid>

            <FormActions>
              <button type="button" className={secondaryButtonClass} onClick={() => setEditing(null)}>
                Annuler
              </button>
              <button type="submit" className={primaryButtonClass} disabled={saving}>
                {saving ? "Enregistrement..." : creation ? "Ajouter" : "Enregistrer"}
              </button>
            </FormActions>
          </form>
        </Modal>
      )}

      {toDelete && (
        <ConfirmDialog
          title="Supprimer l'indicateur"
          message={`Supprimer définitivement l'indicateur « ${toDelete.libelle} » ?`}
          detail="Il ne sera plus proposé dans les conditions des règles personnalisées."
          confirmLabel="Supprimer"
          loadingLabel="Suppression..."
          loading={deleting}
          onConfirm={() => void supprimer()}
          onCancel={() => setToDelete(null)}
        />
      )}
    </div>
  );
}
