import { useEffect, useState, type FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";
import api, { getErrorMessage } from "../../services/api";
import { useAuth } from "../../context/AuthContext";
import { PasswordInput } from "../../components/ui";
import AuthLayout from "../../components/AuthLayout";
import CentreSelect, { type CentreOption } from "../../components/CentreSelect";
import type { AppUser } from "../../types";

const STEP_INFOS = 1;
const STEP_OTP = 2;
const STEP_PASSWORD = 3;

interface InfosForm {
  nom: string;
  email: string;
  im: string;
  fonction_id: string;
  centre_id: string;
  telephone: string;
  adresse: string;
}

interface SignupOptions {
  centres: CentreOption[];
  fonctions: { id: number; nom: string }[];
}

interface PasswordForm {
  password: string;
  password_confirmation: string;
}

interface OtpSentResponse {
  message: string;
  debug_otp?: string; // renvoyé par l'API uniquement en mode développement (APP_DEBUG=true)
}

interface VerifyOtpResponse {
  message?: string;
  setup_token: string;
}

interface SetPasswordResponse {
  token: string;
  user: AppUser;
}

function StepIndicator({ step }: { step: number }) {
  const steps = [1, 2, 3];
  return (
    <div className="mb-6 mt-5 flex items-center">
      {steps.map((s, i) => (
        <div key={s} className={`flex items-center ${i < steps.length - 1 ? "flex-1" : ""}`}>
          <div
            className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold transition
              ${s < step ? "bg-brand-green text-brand-blue" : ""}
              ${s === step ? "bg-brand-blue text-white shadow-md shadow-brand-blue/30 ring-4 ring-brand-blue/10" : ""}
              ${s > step ? "bg-gray-100 text-gray-400" : ""}`}
          >
            {s < step ? "✓" : s}
          </div>
          {i < steps.length - 1 && <div className={`mx-2 h-0.5 flex-1 rounded-full ${s < step ? "bg-brand-green" : "bg-gray-200"}`} />}
        </div>
      ))}
    </div>
  );
}

const inputClass =
  "w-full rounded-xl border border-gray-200 bg-gray-50/70 px-4 py-3 text-sm text-gray-800 placeholder-gray-400 " +
  "focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-green/60 focus:border-brand-green transition";

const primaryButtonClass =
  "w-full rounded-xl bg-brand-blue py-3 text-sm font-semibold text-white shadow-md shadow-brand-blue/20 " +
  "hover:brightness-110 hover:shadow-lg active:scale-[0.99] disabled:opacity-50 disabled:cursor-not-allowed transition";

const linkButtonClass = "w-full text-sm font-medium text-brand-blue hover:underline disabled:opacity-50";

const errorClass = "mb-5 rounded-xl border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-700";
const infoClass = "mb-5 rounded-xl border-l-4 border-brand-green bg-brand-green/15 px-4 py-3 text-sm text-brand-blue";

export default function SignupPage() {
  const navigate = useNavigate();
  const { login } = useAuth();
  const [step, setStep] = useState<number>(STEP_INFOS);
  const [form, setForm] = useState<InfosForm>({
    nom: "",
    email: "",
    im: "",
    fonction_id: "",
    centre_id: "",
    telephone: "",
    adresse: "",
  });
  const [options, setOptions] = useState<SignupOptions>({ centres: [], fonctions: [] });
  const [otp, setOtp] = useState<string>("");
  const [setupToken, setSetupToken] = useState<string>("");
  const [passwords, setPasswords] = useState<PasswordForm>({
    password: "",
    password_confirmation: "",
  });
  const [error, setError] = useState<string>("");
  const [info, setInfo] = useState<string>("");
  const [loading, setLoading] = useState<boolean>(false);

  // Listes déroulantes : centres + fonctions ouvertes à l'inscription
  useEffect(() => {
    api
      .get<SignupOptions>("/signup-options")
      .then((res) => setOptions(res.data))
      .catch((err) => setError(getErrorMessage(err, "Impossible de charger les centres et fonctions.")));
  }, []);

  async function handleStep1(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError("");
    setInfo("");
    setLoading(true);
    try {
      const res = await api.post<OtpSentResponse>("/register", {
        ...form,
        centre_id: Number(form.centre_id),
        fonction_id: Number(form.fonction_id),
      });
      if (res.data.debug_otp) {
        setInfo(`Mode développement — code : ${res.data.debug_otp}`);
      }
      setStep(STEP_OTP);
    } catch (err) {
      setError(getErrorMessage(err, "Erreur lors de l'inscription."));
    } finally {
      setLoading(false);
    }
  }

  async function handleStep2(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError("");
    setInfo("");
    setLoading(true);
    try {
      const res = await api.post<VerifyOtpResponse>("/register/verify-otp", {
        email: form.email,
        otp_code: otp,
      });
      setSetupToken(res.data.setup_token);
      setStep(STEP_PASSWORD);
    } catch (err) {
      setError(getErrorMessage(err, "Code invalide ou expiré."));
    } finally {
      setLoading(false);
    }
  }

  async function handleResendOtp() {
    setError("");
    setInfo("");
    setLoading(true);
    try {
      const res = await api.post<OtpSentResponse>("/register/resend-otp", { email: form.email });
      setInfo(
        res.data.debug_otp
          ? `Mode développement — nouveau code : ${res.data.debug_otp}`
          : "Un nouveau code a été envoyé."
      );
    } catch (err) {
      setError(getErrorMessage(err, "Impossible de renvoyer le code."));
    } finally {
      setLoading(false);
    }
  }

  async function handleStep3(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError("");
    setInfo("");
    setLoading(true);
    try {
      const res = await api.post<SetPasswordResponse>("/register/set-password", {
        email: form.email,
        setup_token: setupToken,
        ...passwords,
      });
      login(res.data.token, res.data.user);
      navigate("/dashboard");
    } catch (err) {
      setError(getErrorMessage(err, "Erreur lors de la définition du mot de passe."));
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthLayout>
      <h1 className="text-2xl font-bold tracking-tight text-brand-blue">Créer un compte</h1>
      <p className="mt-1 text-sm text-gray-500">Étape {step} sur 3</p>

      <StepIndicator step={step} />

      {error && <p className={errorClass}>{error}</p>}
      {info && <p className={infoClass}>{info}</p>}

      {step === STEP_INFOS && (
        <form onSubmit={handleStep1} className="grid gap-4 sm:grid-cols-2">
          <input
            className={`${inputClass} sm:col-span-2`}
            placeholder="Nom"
            value={form.nom}
            onChange={(e) => setForm({ ...form, nom: e.target.value })}
            required
          />
          <input
            className={`${inputClass} sm:col-span-2`}
            type="email"
            placeholder="E-mail"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
            required
          />
          <input
            className={inputClass}
            placeholder="IM (matricule)"
            value={form.im}
            onChange={(e) => setForm({ ...form, im: e.target.value })}
          />
          <input
            className={inputClass}
            placeholder="Téléphone"
            value={form.telephone}
            onChange={(e) => setForm({ ...form, telephone: e.target.value })}
          />
          <div className="sm:col-span-2">
            <CentreSelect
              centres={options.centres}
              value={form.centre_id}
              onChange={(v) => setForm({ ...form, centre_id: v })}
              className={inputClass}
              placeholder="Centre (rechercher par nom ou adresse)"
              required
            />
          </div>
          <select
            className={`${inputClass} sm:col-span-2`}
            value={form.fonction_id}
            onChange={(e) => setForm({ ...form, fonction_id: e.target.value })}
            required
          >
            <option value="">Fonction</option>
            {options.fonctions.map((f) => (
              <option key={f.id} value={f.id}>
                {f.nom}
              </option>
            ))}
          </select>
          <input
            className={`${inputClass} sm:col-span-2`}
            placeholder="Adresse"
            value={form.adresse}
            onChange={(e) => setForm({ ...form, adresse: e.target.value })}
          />
          <button disabled={loading} type="submit" className={`${primaryButtonClass} sm:col-span-2`}>
            {loading ? "Envoi..." : "Continuer"}
          </button>
        </form>
      )}

      {step === STEP_OTP && (
        <form onSubmit={handleStep2} className="space-y-4">
          <p className="rounded-xl bg-gray-50 px-4 py-3 text-center text-sm text-gray-600">
            Code à 6 chiffres envoyé à <span className="font-semibold text-brand-blue">{form.email}</span>.
            <br />
            Valable 10 minutes.
          </p>
          <input
            className={`${inputClass} text-center tracking-[0.5em] text-xl font-semibold`}
            placeholder="------"
            maxLength={6}
            value={otp}
            onChange={(e) => setOtp(e.target.value.replace(/\D/g, ""))}
            required
          />
          <button disabled={loading} type="submit" className={primaryButtonClass}>
            {loading ? "Vérification..." : "Vérifier le code"}
          </button>
          <button type="button" onClick={handleResendOtp} disabled={loading} className={linkButtonClass}>
            Renvoyer le code
          </button>
        </form>
      )}

      {step === STEP_PASSWORD && (
        <form onSubmit={handleStep3} className="space-y-4">
          <PasswordInput
            className={inputClass}
            placeholder="Mot de passe"
            value={passwords.password}
            onChange={(e) => setPasswords({ ...passwords, password: e.target.value })}
            minLength={8}
            required
          />
          <PasswordInput
            className={inputClass}
            placeholder="Confirmer le mot de passe"
            value={passwords.password_confirmation}
            onChange={(e) =>
              setPasswords({ ...passwords, password_confirmation: e.target.value })
            }
            minLength={8}
            required
          />
          <p className="text-xs text-gray-500">8 caractères minimum.</p>
          <button disabled={loading} type="submit" className={primaryButtonClass}>
            {loading ? "Création..." : "Créer mon compte"}
          </button>
        </form>
      )}

      {step === STEP_INFOS && (
        <p className="mt-8 text-center text-sm text-gray-500">
          Déjà un compte ?{" "}
          <Link to="/" className="font-semibold text-brand-blue hover:underline">
            Se connecter
          </Link>
        </p>
      )}
    </AuthLayout>
  );
}
