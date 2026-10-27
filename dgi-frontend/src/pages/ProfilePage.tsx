import { useEffect, useRef, useState, type FormEvent, type ReactNode } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import { ErrorBox, Field, FormActions, PasswordInput, RoleBadge, inputClass } from "../components/ui";
import CentreSelect, { type CentreOption } from "../components/CentreSelect";
import { savePendingRequest } from "../services/profileRequest";

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
  request_id: string;
  champs: string[];
  expires_at: string;
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
      className="h-4 w-4 shrink-0 text-slate-400"
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
        <dt className="text-xs font-medium text-slate-500">{label}</dt>
        <dd className="break-words text-sm font-medium text-brand-blue">{children}</dd>
      </div>
      <LockIcon />
    </div>
  );
}

/** Carte blanche avec liseré vert et titre bleu. */
function Section({ title, hint, children }: { title: string; hint?: string; children: ReactNode }) {
  return (
    <section className="bg-white rounded-xl border border-line p-6 space-y-4">
      <div className="border-b border-line pb-3">
        <h2 className="font-semibold text-brand-blue">{title}</h2>
        {hint && <p className="text-xs text-slate-500 mt-0.5">{hint}</p>}
      </div>
      {children}
    </section>
  );
}

export default function ProfilePage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  const [form, setForm] = useState<ProfileForm>({
    nom: user?.nom ?? "",
    telephone: user?.telephone ?? "",
    adresse: user?.adresse ?? "",
    centre_id: user?.centre_id ? String(user.centre_id) : "",
  });
  const [passwords, setPasswords] = useState<PasswordForm>(EMPTY_PASSWORDS);
  const [centres, setCentres] = useState<CentreOption[]>([]);
  const [error, setError] = useState<string>("");
  const [loading, setLoading] = useState<boolean>(false);
  // Arrivée depuis la page d'attente après validation de l'e-mail : on affiche une confirmation.
  const [updated, setUpdated] = useState<boolean>(
    () => (location.state as { updated?: boolean } | null)?.updated === true
  );

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

  // Le message disparaît tout seul et ne réapparaît pas si on recharge la page (l'état de navigation est effacé une fois).
  const etatEfface = useRef<boolean>(false);
  useEffect(() => {
    if (!updated) return;
    if (!etatEfface.current) {
      etatEfface.current = true;
      navigate(location.pathname, { replace: true, state: null });
    }
    const timer = window.setTimeout(() => setUpdated(false), 8000);
    return () => window.clearTimeout(timer);
  }, [updated, navigate, location.pathname]);

  if (!user) return null;

  const isAdmin = user.role === "admin";
  const centreRequired = user.role === "agent" || user.role === "admin";

  // Le centre actuel reste sélectionnable même s'il manquait dans la liste chargée.
  const centreOptions =
    user.centre && !centres.some((c) => c.id === user.centre!.id) ? [...centres, user.centre] : centres;

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError("");
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
      // Page d'attente : elle suit la demande et affiche les nouvelles données dès que l'e-mail est validé.
      savePendingRequest({
        request_id: res.data.request_id,
        email: res.data.email,
        champs: res.data.champs,
        expires_at: res.data.expires_at,
        debug_confirm_url: res.data.debug_confirm_url,
      });
      setPasswords(EMPTY_PASSWORDS);
      navigate("/profile/attente");
    } catch (err) {
      setError(getErrorMessage(err, "Impossible d'envoyer la demande."));
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="w-full">
      {/* Titre aligné à gauche, sur toute la largeur */}
      <div className="mb-6 border-b border-line pb-4">
        <h1 className="text-2xl font-semibold tracking-tight text-brand-blue">Mon profil</h1>
      </div>

      <ErrorBox message={error} />

      {updated && (
        <div
          role="status"
          className="mb-6 rounded-lg border border-brand-green/60 bg-leaf-50 px-4 py-3 text-sm font-medium text-brand-blue"
        >
          ✓ Vos modifications ont été appliquées.
        </div>
      )}

      {/* Deux colonnes : identité à gauche, formulaires à droite (une seule colonne sur mobile) */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
        <aside className="space-y-6 lg:sticky lg:top-6">
          <div className="overflow-hidden rounded-xl border border-line bg-white">
            <div className="relative h-16 bg-brand-blue">
              <div className="absolute inset-x-0 bottom-0 h-1 bg-brand-gold" />
            </div>
            <div className="px-6 pb-6">
              <div className="relative z-10 -mt-8 flex h-16 w-16 items-center justify-center rounded-lg bg-brand-green text-xl font-semibold text-brand-blue ring-4 ring-white">
                {initials(user.nom)}
              </div>
              <h2 className="mt-3 break-words text-lg font-semibold text-brand-blue">{user.nom}</h2>
              <div className="mt-3 flex flex-wrap items-center gap-2">
                <RoleBadge role={user.role} />
                {user.fonction && (
                  <span className="rounded-full bg-brand-blue/10 px-2.5 py-0.5 text-xs font-semibold text-brand-blue">
                    {user.fonction.nom}
                  </span>
                )}
                {user.centre && (
                  <span className="rounded-full bg-leaf-100 px-2.5 py-0.5 text-xs font-semibold text-brand-blue">
                    {user.centre.nom}
                  </span>
                )}
              </div>
            </div>
          </div>

          <Section title="Informations non modifiables" hint="L'IM, l'e-mail et la fonction sont gérés par un responsable.">
            <dl className="-my-2 divide-y divide-line">
              <InfoRow label="IM (matricule)">{user.im ?? "—"}</InfoRow>
              <InfoRow label="E-mail">{user.email}</InfoRow>
              <InfoRow label="Fonction">{user.fonction?.nom ?? "—"}</InfoRow>
            </dl>
          </Section>
        </aside>

        <form onSubmit={handleSubmit} className="space-y-6 lg:col-span-2">
          <Section title="Mes informations">
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <Field label="Nom" required>
                <input
                  className={inputClass}
                  value={form.nom}
                  onChange={(e) => setForm({ ...form, nom: e.target.value })}
                  autoComplete="name"
                  required
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
              <Field label="Adresse" optional>
                <input
                  className={inputClass}
                  value={form.adresse}
                  onChange={(e) => setForm({ ...form, adresse: e.target.value })}
                  autoComplete="street-address"
                />
              </Field>
              <div>
                <span className="mb-1 flex items-baseline gap-1 text-sm font-medium text-slate-700">
                  <span>Centre</span>
                  {centreRequired ? (
                    <span className="text-red-600" aria-hidden="true" title="Champ obligatoire">
                      *
                    </span>
                  ) : (
                    <span className="text-xs font-normal text-slate-400">facultatif</span>
                  )}
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
              <p className="text-xs text-slate-500">
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
              <Field label="Nouveau mot de passe" hint="8 caractères minimum.">
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

          <FormActions className="rounded-xl border border-line bg-white px-6 py-4">
            <button
              type="submit"
              disabled={loading}
              className="rounded-lg bg-brand-blue px-6 py-2.5 font-medium text-white transition hover:bg-brand-blue-light disabled:cursor-not-allowed disabled:opacity-50"
            >
              {loading ? "Envoi..." : "Enregistrer et demander la confirmation"}
            </button>
          </FormActions>
        </form>
      </div>
    </div>
  );
}