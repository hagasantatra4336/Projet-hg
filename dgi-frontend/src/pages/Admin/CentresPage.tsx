import { useCallback, useEffect, useState, type FormEvent } from "react";
import api, { getErrorMessage } from "../../services/api";
import { useAuth } from "../../context/AuthContext";
import type { Centre, TypeCentre } from "../../types";
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

interface CentreForm {
  nom: string;
  adresse: string;
  type_centre: TypeCentre;
  affiche: boolean;
}

const EMPTY_FORM: CentreForm = { nom: "", adresse: "", type_centre: "-", affiche: true };

export default function CentresPage() {
  const { user } = useAuth();
  const peutGererAffichage = user?.role === "superadmin";

  const [centres, setCentres] = useState<Centre[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string>("");

  const [editing, setEditing] = useState<Centre | "new" | null>(null);
  const [form, setForm] = useState<CentreForm>(EMPTY_FORM);
  const [formError, setFormError] = useState<string>("");
  const [saving, setSaving] = useState<boolean>(false);

  const [toDelete, setToDelete] = useState<Centre | null>(null);
  const [deleting, setDeleting] = useState<boolean>(false);

  const [toToggle, setToToggle] = useState<Centre | null>(null);
  const [toggling, setToggling] = useState<boolean>(false);

  const load = useCallback(async () => {
    try {
      // Liste complète (?tous=1) : la page de gestion doit afficher aussi les services
      // centraux masqués pour permettre de les réafficher.
      const res = await api.get<Centre[]>("/centres", { params: { tous: 1 } });
      setCentres(res.data);
      setError("");
    } catch (err) {
      setError(getErrorMessage(err, "Impossible de charger les centres."));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  function openNew() {
    setForm(EMPTY_FORM);
    setFormError("");
    setEditing("new");
  }

  function openEdit(c: Centre) {
    setForm({ nom: c.nom, adresse: c.adresse ?? "", type_centre: c.type_centre, affiche: c.affiche });
    setFormError("");
    setEditing(c);
  }

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setFormError("");
    setSaving(true);
    // Le type et l'affichage ne sont modifiables que par le super admin : les autres rôles n'envoient que nom et adresse.
    const payload = peutGererAffichage
      ? form
      : { nom: form.nom, adresse: form.adresse };
    try {
      if (editing === "new") {
        await api.post("/centres", payload);
      } else if (editing) {
        await api.put(`/centres/${editing.id}`, payload);
      }
      setEditing(null);
      await load();
    } catch (err) {
      setFormError(getErrorMessage(err, "Enregistrement impossible."));
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    if (!toDelete) return;
    setDeleting(true);
    try {
      await api.delete(`/centres/${toDelete.id}`);
      setToDelete(null);
      await load();
    } catch (err) {
      setToDelete(null);
      setError(getErrorMessage(err, "Suppression impossible."));
    } finally {
      setDeleting(false);
    }
  }

  async function handleToggle() {
    if (!toToggle) return;
    setToggling(true);
    try {
      await api.patch(`/centres/${toToggle.id}/affichage`, { affiche: !toToggle.affiche });
      setToToggle(null);
      await load();
    } catch (err) {
      setToToggle(null);
      setError(getErrorMessage(err, "Modification de l'affichage impossible."));
    } finally {
      setToggling(false);
    }
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <p className="text-sm text-slate-600">{centres.length} centre(s)</p>
        <button type="button" className={primaryButtonClass} onClick={openNew}>
          + Ajouter un centre
        </button>
      </div>

      <ErrorBox message={error} />

      {!peutGererAffichage && (
        <p className="mb-3 text-xs text-slate-500">
          Le super admin définit le type d'un centre (service central ou centre fiscal) et peut masquer un
          service central dans l'inscription.
        </p>
      )}

      <div className="bg-white rounded-xl border border-line overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="border-b border-line bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">
            <tr>
              <th className="px-3 py-2">Nom</th>
              <th className="px-3 py-2">Type</th>
              <th className="px-3 py-2">Adresse</th>
              <th className="px-3 py-2">Inscription</th>
              <th className="px-3 py-2">Utilisateurs</th>
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
            {!loading && centres.length === 0 && (
              <tr>
                <td colSpan={6} className="px-3 py-4 text-center text-slate-500">
                  Aucun centre. Ajoutez le premier.
                </td>
              </tr>
            )}
            {centres.map((c) => (
              <tr key={c.id} className="transition hover:bg-slate-50/70">
                <td className="px-3 py-2 font-medium text-brand-blue">{c.nom}</td>
                <td className="px-3 py-2 whitespace-nowrap">
                  {c.type_centre === "central" ? (
                    <span className="inline-flex items-center rounded-full bg-violet-50 px-2 py-0.5 text-[11px] font-semibold text-violet-700">
                      Central
                    </span>
                  ) : (
                    <span className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                      Centre fiscal
                    </span>
                  )}
                </td>
                <td className="px-3 py-2 text-slate-600">{c.adresse ?? "—"}</td>
                <td className="px-3 py-2">
                  {c.type_centre === "central" ? (
                    <label
                      className={`inline-flex items-center gap-2 ${peutGererAffichage ? "cursor-pointer" : "cursor-not-allowed opacity-70"}`}
                      title={
                        peutGererAffichage
                          ? "Cocher pour afficher dans l'inscription, décocher pour masquer"
                          : "Seul le super admin peut modifier l'affichage."
                      }
                    >
                      <input
                        type="checkbox"
                        className="h-4 w-4 accent-brand-blue"
                        checked={c.affiche}
                        disabled={!peutGererAffichage}
                        onChange={() => setToToggle(c)}
                      />
                      <span className="text-slate-600">{c.affiche ? "Affiché" : "Masqué"}</span>
                    </label>
                  ) : (
                    <span className="text-slate-400" title="Un centre fiscal est toujours proposé.">
                      Toujours affiché
                    </span>
                  )}
                </td>
                <td className="px-3 py-2 text-slate-600">{c.users_count ?? 0}</td>
                <td className="sticky right-0 z-[1] border-l border-line bg-white px-3 py-2 text-right whitespace-nowrap">
                  <EditButton onClick={() => openEdit(c)} />
                  <DeleteButton onClick={() => setToDelete(c)} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {editing && (
        <Modal title={editing === "new" ? "Nouveau centre" : "Modifier le centre"} onClose={() => setEditing(null)}>
          <ErrorBox message={formError} />
          <form onSubmit={handleSubmit} className="space-y-5">
            <FormGrid>
              <Field
                label="Nom du centre"
                required
                className="sm:col-span-2"
                hint="Nom de nouveau centre"
              >
                <input
                  className={inputClass}
                  value={form.nom}
                  onChange={(e) => setForm({ ...form, nom: e.target.value })}
                  placeholder="Ex. : Centre fiscal d'Antananarivo"
                  required
                />
              </Field>
              <Field label="Adresse" optional className="sm:col-span-2">
                <input
                  className={inputClass}
                  value={form.adresse}
                  onChange={(e) => setForm({ ...form, adresse: e.target.value })}
                  placeholder="Ex. : Rue Rainitovo, Antananarivo 101"
                />
              </Field>
              {peutGererAffichage && (
                <div className="sm:col-span-2 space-y-2 rounded-xl border border-line bg-slate-50/70 p-3.5">
                  <label className="flex cursor-pointer items-start gap-2.5">
                    <input
                      type="checkbox"
                      className="mt-0.5 h-4 w-4 accent-brand-blue"
                      checked={form.type_centre === "central"}
                      onChange={(e) =>
                        setForm({
                          ...form,
                          type_centre: e.target.checked ? "central" : "-",
                          affiche: e.target.checked ? form.affiche : true,
                        })
                      }
                    />
                    <span>
                      <span className="block text-sm font-medium text-slate-800">Service central</span>
                      <span className="block text-xs text-slate-500">
                        Cocher si c'est un centre de type central
                      </span>
                    </span>
                  </label>
                  {form.type_centre === "central" && (
                    <label className="flex cursor-pointer items-center gap-2.5 pl-6">
                      <input
                        type="checkbox"
                        className="h-4 w-4 accent-brand-blue"
                        checked={form.affiche}
                        onChange={(e) => setForm({ ...form, affiche: e.target.checked })}
                      />
                      <span className="text-sm text-slate-700">Proposé dans l'inscription</span>
                    </label>
                  )}
                </div>
              )}
            </FormGrid>
            <FormActions>
              <button type="button" className={secondaryButtonClass} onClick={() => setEditing(null)}>
                Annuler
              </button>
              <button type="submit" className={primaryButtonClass} disabled={saving}>
                {saving ? "Enregistrement..." : "Enregistrer"}
              </button>
            </FormActions>
          </form>
        </Modal>
      )}

      {toDelete && (
        <ConfirmDialog
          title="Supprimer le centre"
          message={`Supprimer définitivement le centre « ${toDelete.nom} » ?`}
          detail={
            (toDelete.users_count ?? 0) > 0
              ? `${toDelete.users_count} utilisateur(s) y sont rattachés : déplacez-les d'abord vers un autre centre, sinon la suppression sera refusée.`
              : "Cette action est définitive."
          }
          confirmLabel="Supprimer"
          loadingLabel="Suppression..."
          loading={deleting}
          onConfirm={handleDelete}
          onCancel={() => setToDelete(null)}
        />
      )}

      {toToggle && (
        <ConfirmDialog
          title={toToggle.affiche ? "Masquer le centre" : "Afficher le centre"}
          message={`${toToggle.affiche ? "Masquer" : "Afficher"} le centre « ${toToggle.nom} » dans l'inscription et les listes de choix ?`}
          detail="Le centre n'est pas supprimé : ses utilisateurs et ses dossiers restent inchangés."
          confirmLabel={toToggle.affiche ? "Masquer" : "Afficher"}
          loadingLabel={toToggle.affiche ? "Masquage..." : "Affichage..."}
          tone="primary"
          loading={toggling}
          onConfirm={handleToggle}
          onCancel={() => setToToggle(null)}
        />
      )}
    </div>
  );
}
