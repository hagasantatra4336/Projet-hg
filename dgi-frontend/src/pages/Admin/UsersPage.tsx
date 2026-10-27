import { useEffect, useState, type FormEvent } from "react";
import api, { getErrorMessage } from "../../services/api";
import { useAuth } from "../../context/AuthContext";
import { ROLE_LABELS, ROLE_RANK, type AppUser, type Paginated, type Role } from "../../types";
import {
  ConfirmDialog,
  DeleteButton,
  EditButton,
  ErrorBox,
  Field,
  FormActions,
  FormGrid,
  FormSection,
  Modal,
  PasswordInput,
  RoleBadge,
  inputClass,
  primaryButtonClass,
  secondaryButtonClass,
} from "../../components/ui";
import SearchSelect from "../../components/SearchSelect";

interface Options {
  centres: { id: number; nom: string }[];
  fonctions: { id: number; nom: string; role: Role }[];
}

interface UserForm {
  nom: string;
  email: string;
  im: string;
  telephone: string;
  adresse: string;
  centre_id: string;
  fonction_id: string;
  password: string;
}

const EMPTY_FORM: UserForm = {
  nom: "",
  email: "",
  im: "",
  telephone: "",
  adresse: "",
  centre_id: "",
  fonction_id: "",
  password: "",
};

/** Même règle que le serveur (qui reste l'autorité) : sert uniquement à masquer les boutons inutiles. */
function canManage(me: AppUser, target: AppUser): boolean {
  if (!me.role) return false;
  if (me.role === "superadmin") return true;
  const targetRank = target.role ? ROLE_RANK[target.role] : 0;
  if (targetRank >= ROLE_RANK[me.role]) return false;
  return me.role !== "admin" || target.centre_id === me.centre_id;
}

export default function UsersPage() {
  const { user: me } = useAuth();

  const [data, setData] = useState<Paginated<AppUser> | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string>("");
  const [reloadKey, setReloadKey] = useState<number>(0);

  const [page, setPage] = useState<number>(1);
  const [searchInput, setSearchInput] = useState<string>("");
  const [search, setSearch] = useState<string>("");
  const [centreFilter, setCentreFilter] = useState<string>("");

  const [options, setOptions] = useState<Options>({ centres: [], fonctions: [] });

  const [editing, setEditing] = useState<AppUser | "new" | null>(null);
  const [form, setForm] = useState<UserForm>(EMPTY_FORM);
  const [formError, setFormError] = useState<string>("");
  const [saving, setSaving] = useState<boolean>(false);

  const [toDelete, setToDelete] = useState<AppUser | null>(null);
  const [deleting, setDeleting] = useState<boolean>(false);

  // Listes du formulaire (centres / fonctions que je peux attribuer)
  useEffect(() => {
    api
      .get<Options>("/users/options")
      .then((res) => setOptions(res.data))
      .catch((err) => setError(getErrorMessage(err, "Impossible de charger les listes.")));
  }, []);

  // Recherche avec un petit délai pour ne pas appeler l'API à chaque lettre
  useEffect(() => {
    const t = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(t);
  }, [searchInput]);

  // Chargement de la liste (pagination + recherche + filtre centre)
  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    api
      .get<Paginated<AppUser>>("/users", {
        params: {
          page,
          search: search || undefined,
          centre_id: centreFilter || undefined,
        },
      })
      .then((res) => {
        if (cancelled) return;
        setData(res.data);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(getErrorMessage(err, "Impossible de charger les utilisateurs."));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [page, search, centreFilter, reloadKey]);

  if (!me) return null;

  const isAdmin = me.role === "admin";
  const selectedFonction = options.fonctions.find((f) => String(f.id) === form.fonction_id);
  const needsCentre = selectedFonction?.role === "agent" || selectedFonction?.role === "admin";

  function openNew() {
    // Un admin crée toujours dans son propre centre
    setForm({ ...EMPTY_FORM, centre_id: isAdmin && me?.centre_id ? String(me.centre_id) : "" });
    setFormError("");
    setEditing("new");
  }

  function openEdit(u: AppUser) {
    setForm({
      nom: u.nom,
      email: u.email,
      im: u.im ?? "",
      telephone: u.telephone ?? "",
      adresse: u.adresse ?? "",
      centre_id: u.centre_id ? String(u.centre_id) : "",
      fonction_id: u.fonction_id ? String(u.fonction_id) : "",
      password: "",
    });
    setFormError("");
    setEditing(u);
  }

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setFormError("");
    setSaving(true);

    const payload: Record<string, unknown> = {
      nom: form.nom,
      email: form.email,
      im: form.im,
      telephone: form.telephone,
      adresse: form.adresse,
      centre_id: form.centre_id ? Number(form.centre_id) : null,
      fonction_id: Number(form.fonction_id),
    };
    if (form.password) payload.password = form.password;

    try {
      if (editing === "new") {
        await api.post("/users", payload);
      } else if (editing) {
        await api.put(`/users/${editing.id}`, payload);
      }
      setEditing(null);
      setReloadKey((k) => k + 1);
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
      await api.delete(`/users/${toDelete.id}`);
      // si on supprime le dernier élément d'une page > 1, on recule d'une page
      if (data && data.data.length === 1 && page > 1) setPage(page - 1);
      setToDelete(null);
      setReloadKey((k) => k + 1);
    } catch (err) {
      setToDelete(null);
      setError(getErrorMessage(err, "Suppression impossible."));
    } finally {
      setDeleting(false);
    }
  }

  const users = data?.data ?? [];

  return (
    <div>
      <div className="flex flex-wrap items-center gap-3 mb-4">
        <input
          className={`${inputClass} max-w-xs`}
          placeholder="Rechercher (nom, e-mail, IM, téléphone)"
          value={searchInput}
          onChange={(e) => setSearchInput(e.target.value)}
        />
        {!isAdmin && (
          <div className="w-full max-w-[16rem]">
            <SearchSelect
              options={options.centres.map((c) => ({ value: String(c.id), label: c.nom }))}
              value={centreFilter}
              onChange={(v) => {
                setCentreFilter(v);
                setPage(1);
              }}
              className={inputClass}
              label="Filtrer par centre"
              clearLabel="Tous les centres"
              emptyLabel="Aucun centre trouvé"
            />
          </div>
        )}
        <div className="flex-1" />
        <button type="button" className={primaryButtonClass} onClick={openNew}>
          + Ajouter un utilisateur
        </button>
      </div>

      <ErrorBox message={error} />

      <div className="bg-white rounded-xl border border-line overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="border-b border-line bg-slate-50 text-left text-slate-600">
            <tr>
              <th className="px-3 py-2 font-semibold">Nom</th>
              <th className="px-3 py-2 font-semibold">IM</th>
              <th className="px-3 py-2 font-semibold">Téléphone</th>
              <th className="px-3 py-2 font-semibold">Centre</th>
              <th className="px-3 py-2 font-semibold">Fonction</th>
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
            {!loading && users.length === 0 && (
              <tr>
                <td colSpan={6} className="px-3 py-4 text-center text-slate-500">
                  Aucun utilisateur.
                </td>
              </tr>
            )}
            {!loading &&
              users.map((u) => (
                <tr key={u.id}>
                  <td className="px-3 py-2">
                    <div className="font-medium text-brand-blue">{u.nom}</div>
                    <div className="text-xs text-slate-500">{u.email}</div>
                  </td>
                  <td className="px-3 py-2 text-slate-600">{u.im ?? "—"}</td>
                  <td className="px-3 py-2 text-slate-600">{u.telephone ?? "—"}</td>
                  <td className="px-3 py-2 text-slate-600">{u.centre?.nom ?? "—"}</td>
                  <td className="px-3 py-2">
                    <div className="text-slate-700 mb-1">{u.fonction?.nom ?? "—"}</div>
                    <RoleBadge role={u.role} />
                  </td>
                  <td className="sticky right-0 z-[1] border-l border-line bg-white px-3 py-2 text-right whitespace-nowrap">
                    {canManage(me, u) && (
                      <>
                        <EditButton onClick={() => openEdit(u)} />
                        {u.id !== me.id && (
                          <DeleteButton onClick={() => setToDelete(u)} />
                        )}
                      </>
                    )}
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>

      {data && data.last_page > 0 && (
        <div className="flex items-center justify-between mt-4 text-sm text-slate-600">
          <span>
            Page {data.current_page} / {data.last_page} — {data.total} utilisateur(s)
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

      {editing && (
        <Modal
          title={editing === "new" ? "Nouvel utilisateur" : "Modifier l'utilisateur"}
          onClose={() => setEditing(null)}
        >
          <ErrorBox message={formError} />
          <form onSubmit={handleSubmit} className="space-y-6">
            <FormSection title="Identité">
              <FormGrid>
                <Field label="Nom" required className="sm:col-span-2">
                  <input
                    className={inputClass}
                    value={form.nom}
                    onChange={(e) => setForm({ ...form, nom: e.target.value })}
                    autoComplete="name"
                    required
                  />
                </Field>
                <Field label="E-mail" required className="sm:col-span-2" hint="Sert d'identifiant de connexion et reçoit les codes de validation.">
                  <input
                    className={inputClass}
                    type="email"
                    value={form.email}
                    onChange={(e) => setForm({ ...form, email: e.target.value })}
                    autoComplete="email"
                    required
                  />
                </Field>
                <Field label="IM (matricule)" optional>
                  <input
                    className={inputClass}
                    value={form.im}
                    onChange={(e) => setForm({ ...form, im: e.target.value })}
                  />
                </Field>
                <Field label="Téléphone" optional>
                  <input
                    className={inputClass}
                    value={form.telephone}
                    onChange={(e) => setForm({ ...form, telephone: e.target.value })}
                    autoComplete="tel"
                  />
                </Field>
                <Field label="Adresse" optional className="sm:col-span-2">
                  <input
                    className={inputClass}
                    value={form.adresse}
                    onChange={(e) => setForm({ ...form, adresse: e.target.value })}
                    autoComplete="street-address"
                  />
                </Field>
              </FormGrid>
            </FormSection>

            <FormSection title="Accès et sécurité">
              <FormGrid>
                <Field
                  label="Fonction"
                  required
                  className="sm:col-span-2"
                  hint="La fonction détermine le rôle et les droits de l'utilisateur."
                >
                  <SearchSelect
                    options={options.fonctions.map((f) => ({
                      value: String(f.id),
                      label: f.nom,
                      description: ROLE_LABELS[f.role],
                    }))}
                    value={form.fonction_id}
                    onChange={(v) => setForm({ ...form, fonction_id: v })}
                    className={inputClass}
                    disabled={editing !== "new" && editing.id === me.id}
                    required
                    label="Fonction"
                    placeholder="— Choisir —"
                    emptyLabel="Aucune fonction trouvée"
                  />
                </Field>
                <Field
                  label="Centre"
                  required={needsCentre}
                  optional={!needsCentre}
                  className="sm:col-span-2"
                  hint={isAdmin ? "Un admin est rattaché à son propre centre." : undefined}
                >
                  <SearchSelect
                    options={options.centres.map((c) => ({ value: String(c.id), label: c.nom }))}
                    value={form.centre_id}
                    onChange={(v) => setForm({ ...form, centre_id: v })}
                    className={inputClass}
                    disabled={isAdmin}
                    required={needsCentre}
                    label="Centre"
                    placeholder={needsCentre ? "Rechercher un centre..." : "— Aucun —"}
                    clearLabel={needsCentre ? undefined : "— Aucun —"}
                    emptyLabel="Aucun centre trouvé"
                  />
                </Field>
                <Field
                  label="Mot de passe"
                  required={editing === "new"}
                  optional={editing !== "new"}
                  className="sm:col-span-2"
                  hint={
                    editing === "new"
                      ? "8 caractères minimum."
                      : "Laissez vide pour conserver le mot de passe actuel."
                  }
                >
                  <PasswordInput
                    className={inputClass}
                    value={form.password}
                    onChange={(e) => setForm({ ...form, password: e.target.value })}
                    minLength={8}
                    required={editing === "new"}
                    autoComplete="new-password"
                  />
                </Field>
              </FormGrid>
            </FormSection>

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
          title="Supprimer l'utilisateur"
          message={`Supprimer définitivement le compte de « ${toDelete.nom} » ?`}
          detail="L'utilisateur ne pourra plus se connecter. Ses traitements déjà enregistrés conservent son nom."
          confirmLabel="Supprimer"
          loadingLabel="Suppression..."
          loading={deleting}
          onConfirm={handleDelete}
          onCancel={() => setToDelete(null)}
        />
      )}
    </div>
  );
}
