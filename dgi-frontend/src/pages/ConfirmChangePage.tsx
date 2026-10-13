import { useState } from "react";
import { useSearchParams } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { primaryButtonClass } from "../components/ui";

interface ConfirmResponse {
  message: string;
  password_changed: boolean;
}

type Status = "idle" | "loading" | "done" | "error";

/**
 * Page ouverte depuis le lien de l'e-mail (ordinateur ou smartphone, connecté ou non).
 * Elle sert UNIQUEMENT à valider : aucune redirection et aucun lien de navigation. C'est la page d'attente restée
 * ouverte dans l'application qui détecte la validation et redirige vers le profil.
 * La confirmation se fait au clic (pas automatiquement à l'ouverture).
 */
export default function ConfirmChangePage() {
  const [params] = useSearchParams();
  const token = params.get("token") ?? "";

  const [status, setStatus] = useState<Status>("idle");
  const [message, setMessage] = useState<string>("");
  const [passwordChanged, setPasswordChanged] = useState<boolean>(false);

  async function handleConfirm() {
    setStatus("loading");
    setMessage("");
    try {
      const res = await api.post<ConfirmResponse>("/profile/confirm", { token });
      setPasswordChanged(res.data.password_changed);
      setMessage(res.data.message);
      setStatus("done");
    } catch (err) {
      setMessage(getErrorMessage(err, "Lien invalide ou expiré."));
      setStatus("error");
    }
  }

  return (
    <div className="min-h-screen bg-brand-white flex items-center justify-center px-4">
      <div className="w-full max-w-md bg-white rounded-2xl shadow-lg p-8 text-center">
        <h1 className="text-xl font-bold text-brand-blue mb-4">Confirmation des modifications</h1>

        {!token && (
          <p className="text-sm text-red-600">Lien incomplet. Utilisez le lien reçu par e-mail.</p>
        )}

        {token && (status === "idle" || status === "loading") && (
          <>
            <p className="text-sm text-gray-600 mb-6">
              Cliquez sur le bouton pour appliquer les modifications demandées sur votre compte.
            </p>
            <button
              type="button"
              className={`${primaryButtonClass} w-full`}
              onClick={handleConfirm}
              disabled={status === "loading"}
            >
              {status === "loading" ? "Confirmation..." : "Confirmer les modifications"}
            </button>
          </>
        )}

        {status === "done" && (
          <>
            <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-brand-green text-2xl font-bold text-brand-blue">
              ✓
            </div>
            <p className="text-sm text-brand-blue bg-brand-green/20 border border-brand-green rounded-lg px-3 py-2 mb-4">
              {message}
            </p>
            <p className="text-sm text-gray-600">
              {passwordChanged
                ? "Votre mot de passe a été modifié : toutes vos sessions ont été fermées. Reconnectez-vous depuis l'application."
                : "La page d'attente de l'application se met à jour et vous redirige vers votre profil. Vous pouvez fermer cette page."}
            </p>
          </>
        )}

        {status === "error" && (
          <>
            <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2 mb-4">
              {message}
            </p>
            <p className="text-sm text-gray-600">
              Refaites une demande depuis la page « Mon profil » de l'application.
            </p>
          </>
        )}
      </div>
    </div>
  );
}
