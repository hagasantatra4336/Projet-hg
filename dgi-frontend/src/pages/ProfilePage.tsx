import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import { ErrorBox, Field, PasswordInput, RoleBadge, inputClass } from "../components/ui";
import CentreSelect, { type CentreOption } from "../components/CentreSelect";

interface ProfileForm {
  nom: string;
  telephone: string;
  adresse: string;
  centre_id: string;
}

interface PasswordForm {
  current_password: string;
  password: string;
  password_confirmation: string;
}

interface RequestChangeResponse {
  message: string;
  email: string;
  debug_confirm_url?: string; // renvoyé par l'API uniquement en mode développement (APP_DEBUG=true)
}

const EMPTY_PASSWORDS: PasswordForm = { current_password: "", password: "", password_confirmation: "" };

function initials(nom: string): string {
  const parts = nom.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "?";
  const letters = parts.length === 1 ? parts[0].slice(0, 2) : parts[0][0] + parts[parts.length - 1][0];
  return letters.toUpperCase();
}

function LockIcon() {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
      className="h-4 w-4 shrink-0 text-gray-400"
      aria-hidden="true"
    >
      <rect x="4" y="11" width="16" height="10" rx="2" />
      <path d="M8 11V7a4 4 0 0 1 8 0v4" />
    </svg>
  );
}

/** Ligne « libellé / valeur » non modifiable, alignée à gauche, avec un cadenas à droite. */
function InfoRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3 py-3">
      <div className="min-w-0">
        <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</dt>
        <dd className="break-words text-sm font-medium text-brand-blue">{children}</dd>
      </div>
      <LockIcon />
    </div>
  );
}

/** Carte blanche avec liseré vert et titre bleu. */
function Section({ title, hint, children }: { title: string; hint?: string; children: ReactNode }) {
  return (
    <section className="bg-white rounded-2xl shadow-md border-t-4 border-brand-green p-6 space-y-4">
      <div>
        <h2 className="font-bold text-brand-blue">{title}</h2>
        {hint && <p className="text-xs text-gray-500 mt-0.5">{hint}</p>}
      </div>
      {children}
    </section>
  );
}

export default function ProfilePage() {
  const { user, refreshUser } = useAuth();

  const [form, setForm] = useState<ProfileForm>({
    nom: user?.nom ?? "",
    telephone: user?.telephone ?? "",
    adresse: user?.adresse ?? "",
    centre_id: user?.centre_id ? String(user.centre_id) : "",
  });
  const [passwords, setPasswords] = useState<PasswordForm>(EMPTY_PASSWORDS);
  const [centres, setCentres] = useState<CentreOption[]>([]);
  const [error, setError] = useState<string>("");
  const [sentTo, setSentTo] = useState<string>("");
  const [debugUrl, setDebugUrl] = useState<string>("");
  const [loading, setLoading] = useState<boolean>(false);

  // Liste des centres (route publique de l'inscription)
  useEffect(() => {
    api
      .get<{ centres: CentreOption[] }>("/signup-options")
      .then((res) => setCentres(res.data.centres))
      .catch((err) => setError(getErrorMessage(err, "Impossible de charger la liste des centres.")));
  }, []);

  // Quand les valeurs enregistrées changent (après confirmation), le formulaire suit.
  useEffect(() => {
    setForm({
      nom: user?.nom ?? "",
      telephone: user?.telephone ?? "",
      adresse: user?.adresse ?? "",
      centre_id: user?.centre_id ? String(user.centre_id) : "",
    });
  }, [user?.nom, user?.telephone, user?.adresse, user?.centre_id]);

  // L'utilisateur confirme dans sa boîte mail (souvent un autre onglet) puis revient ici :
  // on recharge alors ses informations pour afficher les nouvelles valeurs.
  useEffect(() => {
    if (!sentTo) return;
    const onFocus = () => void refreshUser();
    window.addEventListener("focus", onFocus);
    return () => window.removeEventListener("focus", onFocus);
  }, [sentTo, refreshUser]);

  if (!user) return null;

  const isAdmin = user.role === "admin";
  const centreRequired = user.role === "agent" || user.role === "admin";

  // Le centre actuel reste sélectionnable même s'il manquait dans la liste chargée.
  const centreOptions =
    user.centre && !centres.some((c) => c.id === user.centre!.id) ? [...centres, user.centre] : centres;

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError("");
    setSentTo("");
    setDebugUrl("");
    setLoading(true);

    const payload: Record<string, string | number | null> = {
      nom: form.nom,
      telephone: form.telephone,
      adresse: form.adresse,
    };
    if (!isAdmin) payload.centre_id = form.centre_id ? Number(form.centre_id) : null;
    if (passwords.password || passwords.current_password || passwords.password_confirmation) {
      Object.assign(payload, passwords);
    }

    try {
      const res = await api.post<RequestChangeResponse>("/profile/request-change", payload);
      setSentTo(res.data.email);
      setDebugUrl(res.data.debug_confirm_url ?? "");
      setPasswords(EMPTY_PASSWORDS);
    } catch (err) {
      setError(getErrorMessage(err, "Impossible d'envoyer la demande."));
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="w-full">
      {/* Titre aligné à gauche, sur toute la largeur */}
      <div className="mb-6 border-l-4 border-brand-green pl-4">
        <h1 className="text-2xl font-bold text-brand-blue">Mon profil</h1>
        <p className="text-sm text-gray-600">
          Les modifications ne sont appliquées qu'après confirmation par un lien envoyé à votre adresse e-mail.
        </p>
      </div>

      <ErrorBox message={error} />

      {sentTo && (
        <div className="mb-6 rounded-xl border border-brand-green bg-brand-green/20 px-4 py-3 text-sm text-brand-blue">
          <p>
            Un e-mail de confirmation a été envoyé à <span className="font-semibold">{sentTo}</span>. Cliquez sur le
            lien qu'il contient (valable 30 minutes) pour appliquer vos modifications.
          </p>
          {debugUrl && (
            <p className="mt-2 break-all">
              Mode développement — lien :{" "}
              <a href={debugUrl} className="underline">
                {debugUrl}
              </a>
            </p>
          )}
        </div>
      )}

      {/* Deux colonnes : identité à gauche, formulaires à droite (une seule colonne sur mobile) */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
        <aside className="space-y-6 lg:sticky lg:top-6">
          <div className="overflow-hidden rounded-2xl bg-white shadow-lg">
            <div className="relative h-20 bg-brand-blue">
              <div className="absolute inset-x-0 bottom-0 h-1.5 bg-brand-green" />
            </div>
            <div className="px-6 pb-6">
              <div className="relative z-10 -mt-10 flex h-20 w-20 items-center justify-center rounded-full bg-brand-green text-2xl font-bold text-brand-blue shadow ring-4 ring-white">
                {initials(user.nom)}
              </div>
              <h2 className="mt-3 break-words text-lg font-bold text-brand-blue">{user.nom}</h2>
              <div className="mt-3 flex flex-wrap items-center gap-2">
                <RoleBadge role={user.role} />
                {user.fonction && (
                  <span className="rounded-full bg-brand-blue/10 px-2.5 py-0.5 text-xs font-semibold text-brand-blue">
                    {user.fonction.nom}
                  </span>
                )}
                {user.centre && (
                  <span className="rounded-full bg-brand-green/30 px-2.5 py-0.5 text-xs font-semibold text-brand-blue">
                    {user.centre.nom}
                  </span>
                )}
              </div>
            </div>
          </div>

          <Section title="Informations non modifiables" hint="L'IM, l'e-mail et la fonction sont gérés par un responsable.">
            <dl className="-my-2 divide-y divide-gray-100">
              <InfoRow label="IM (matricule)">{user.im ?? "—"}</InfoRow>
              <InfoRow label="E-mail">{user.email}</InfoRow>
              <InfoRow label="Fonction">{user.fonction?.nom ?? "—"}</InfoRow>
            </dl>
          </Section>
        </aside>

        <form onSubmit={handleSubmit} className="space-y-6 lg:col-span-2">
          <Section title="Mes informations">
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <Field label="Nom">
                <input
                  className={inputClass}
                  value={form.nom}
                  onChange={(e) => setForm({ ...form, nom: e.target.value })}
                  required
                />
              </Field>
              <Field label="Téléphone">
                <input
                  className={inputClass}
                  value={form.telephone}
                  onChange={(e) => setForm({ ...form, telephone: e.target.value })}
                />
              </Field>
              <Field label="Adresse">
                <input
                  className={inputClass}
                  value={form.adresse}
                  onChange={(e) => setForm({ ...form, adresse: e.target.value })}
                />
              </Field>
              <div>
                <span className="mb-1 block text-sm font-medium text-brand-blue">
                  {centreRequired ? "Centre" : "Centre (facultatif)"}
                </span>
                <CentreSelect
                  centres={centreOptions}
                  value={form.centre_id}
                  onChange={(v) => setForm({ ...form, centre_id: v })}
                  className={inputClass}
                  disabled={isAdmin}
                  required={centreRequired && !isAdmin}
                />
              </div>
            </div>
            {isAdmin && (
              <p className="text-xs text-gray-500">
                Un admin gère les agents de son centre : pour changer de centre, contactez un responsable central ou un
                super admin.
              </p>
            )}
          </Section>

          <Section title="Changer mon mot de passe" hint="Laissez vide pour conserver votre mot de passe actuel.">
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
              <Field label="Mot de passe actuel">
                <PasswordInput
                  className={inputClass}
                  value={passwords.current_password}
                  onChange={(e) => setPasswords({ ...passwords, current_password: e.target.value })}
                  autoComplete="current-password"
                />
              </Field>
              <Field label="Nouveau mot de passe">
                <PasswordInput
                  className={inputClass}
                  value={passwords.password}
                  onChange={(e) => setPasswords({ ...passwords, password: e.target.value })}
                  minLength={8}
                  autoComplete="new-password"
                />
              </Field>
              <Field label="Confirmer le nouveau">
                <PasswordInput
                  className={inputClass}
                  value={passwords.password_confirmation}
                  onChange={(e) => setPasswords({ ...passwords, password_confirmation: e.target.value })}
                  minLength={8}
                  autoComplete="new-password"
                />
              </Field>
            </div>
          </Section>

          <div className="flex justify-end">
            <button
              type="submit"
              disabled={loading}
              className="rounded-lg bg-brand-blue px-8 py-3 font-semibold text-white shadow transition hover:bg-brand-green hover:text-brand-blue disabled:cursor-not-allowed disabled:opacity-50"
            >
              {loading ? "Envoi..." : "Enregistrer et demander la confirmation"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}