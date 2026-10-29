import { useState, type FormEvent } from "react";
import { Link, Navigate, useNavigate } from "react-router-dom";
import api, { getErrorMessage } from "../../services/api";
import { useAuth } from "../../context/AuthContext";
import { PasswordInput } from "../../components/ui";
import AuthLayout from "../../components/AuthLayout";
import type { AppUser } from "../../types";

const STEP_CREDENTIALS = 1;
const STEP_OTP = 2;

interface LoginForm {
  email: string;
  password: string;
}

interface OtpSentResponse {
  message: string;
  otp_required: boolean;
  debug_otp?: string; // renvoyé par l'API uniquement en mode développement (APP_DEBUG=true)
}

interface LoginResponse {
  token: string;
  user: AppUser;
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

export default function LoginPage() {
  const navigate = useNavigate();
  const { user, login } = useAuth();
  const [step, setStep] = useState<number>(STEP_CREDENTIALS);
  const [form, setForm] = useState<LoginForm>({ email: "", password: "" });
  const [otp, setOtp] = useState<string>("");
  const [error, setError] = useState<string>("");
  const [info, setInfo] = useState<string>(() => {
    const fermee = sessionStorage.getItem("connexion:fermee") ?? "";
    sessionStorage.removeItem("connexion:fermee");
    return fermee;
  });
  const [loading, setLoading] = useState<boolean>(false);

  // Étape 1 : e-mail + mot de passe → l'API envoie un code par e-mail
  async function handleCredentials(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError("");
    setInfo("");
    setLoading(true);
    try {
      const res = await api.post<OtpSentResponse>("/login", form);
      if (res.data.debug_otp) {
        setInfo(`Mode développement — code : ${res.data.debug_otp}`);
      }
      setOtp("");
      setStep(STEP_OTP);
    } catch (err) {
      setError(getErrorMessage(err, "Identifiants incorrects."));
    } finally {
      setLoading(false);
    }
  }

  // Étape 2 : code reçu par e-mail → l'API renvoie le jeton
  async function handleOtp(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError("");
    setInfo("");
    setLoading(true);
    try {
      const res = await api.post<LoginResponse>("/login/verify-otp", {
        email: form.email,
        otp_code: otp,
      });
      login(res.data.token, res.data.user);
      navigate("/dashboard");
    } catch (err) {
      setError(getErrorMessage(err, "Code invalide ou expiré."));
    } finally {
      setLoading(false);
    }
  }

  async function handleResend() {
    setError("");
    setInfo("");
    setLoading(true);
    try {
      const res = await api.post<OtpSentResponse>("/login/resend-otp", { email: form.email });
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

  function handleBack() {
    setError("");
    setInfo("");
    setOtp("");
    setForm({ ...form, password: "" });
    setStep(STEP_CREDENTIALS);
  }

  // Déjà connecté : inutile d'afficher le formulaire
  if (user) return <Navigate to="/dashboard" replace />;

  return (
    <AuthLayout>
      <h1 className="text-2xl font-bold tracking-tight text-brand-blue">Connexion</h1>

      <div className="mb-6 mt-5 flex items-center gap-2">
        {[1, 2].map((s) => (
          <span key={s} className={`h-1.5 flex-1 rounded-full transition-colors ${s <= step ? "bg-brand-blue" : "bg-gray-200"}`} />
        ))}
        <span className="ml-1 text-xs font-medium text-gray-500">Étape {step} sur 2</span>
      </div>

      {error && <p className={errorClass}>{error}</p>}
      {info && <p className={infoClass}>{info}</p>}

      {step === STEP_CREDENTIALS && (
        <form onSubmit={handleCredentials} className="space-y-4">
          <input
            className={inputClass}
            type="email"
            placeholder="E-mail"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
            autoComplete="username"
            required
          />
          <PasswordInput
            className={inputClass}
            placeholder="Mot de passe"
            value={form.password}
            onChange={(e) => setForm({ ...form, password: e.target.value })}
            autoComplete="current-password"
            required
          />
          <button disabled={loading} type="submit" className={primaryButtonClass}>
            {loading ? "Vérification..." : "Se connecter"}
          </button>
        </form>
      )}

      {step === STEP_OTP && (
        <form onSubmit={handleOtp} className="space-y-4">
          <p className="rounded-xl bg-gray-50 px-4 py-3 text-center text-sm text-gray-600">
            Code à 6 chiffres envoyé à <span className="font-semibold text-brand-blue">{form.email}</span>.
            <br />
            Valable 10 minutes.
          </p>
          <input
            className={`${inputClass} text-center tracking-[0.5em] text-xl font-semibold`}
            placeholder="------"
            maxLength={6}
            inputMode="numeric"
            autoComplete="one-time-code"
            value={otp}
            onChange={(e) => setOtp(e.target.value.replace(/\D/g, ""))}
            required
          />
          <button disabled={loading} type="submit" className={primaryButtonClass}>
            {loading ? "Vérification..." : "Valider le code"}
          </button>
          <div className="flex items-center justify-between gap-4 pt-1">
            <button type="button" onClick={handleBack} disabled={loading} className="text-sm text-gray-500 hover:text-brand-blue hover:underline disabled:opacity-50">
              ← Retour
            </button>
            <button type="button" onClick={handleResend} disabled={loading} className={`${linkButtonClass} w-auto`}>
              Renvoyer le code
            </button>
          </div>
        </form>
      )}

      {step === STEP_CREDENTIALS && (
        <p className="mt-8 text-center text-sm text-gray-500">
          Pas encore de compte ?{" "}
          <Link to="/signup" className="font-semibold text-brand-blue hover:underline">
            Sign up
          </Link>
        </p>
      )}
    </AuthLayout>
  );
}
