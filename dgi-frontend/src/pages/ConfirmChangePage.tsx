import { useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import api, { getErrorMessage } from "../services/api";
import { useAuth } from "../context/AuthContext";
import { primaryButtonClass } from "../components/ui";

interface ConfirmResponse {
  message: string;
  password_changed: boolean;
}

type Status = "idle" | "loading" | "done" | "error";

/** Page ouverte depuis le lien de l'e-mail. La confirmation se fait au clic (pas automatiquement à l'ouverture). */
export default function ConfirmChangePage() {
  const [params] = useSearchParams();
  const token = params.get("token") ?? "";
  const { user, refreshUser } = useAuth();

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
      // Si l'utilisateur est connecté dans ce navigateur, on met à jour ses infos
      // (si le mot de passe a changé, ses sessions sont révoquées : il sera déconnecté).
      await refreshUser();
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
            <p className="text-sm text-brand-blue bg-brand-green/20 border border-brand-green rounded-lg px-3 py-2 mb-4">
              {message}
            </p>
            {passwordChanged && (
              <p className="text-sm text-gray-600 mb-4">
                Votre mot de passe a été modifié : toutes vos sessions ont été fermées, reconnectez-vous.
              </p>
            )}
            <Link to={user && !passwordChanged ? "/profile" : "/"} className="text-brand-blue font-medium hover:underline">
              {user && !passwordChanged ? "Retour à mon profil" : "Se connecter"}
            </Link>
          </>
        )}

        {status === "error" && (
          <>
            <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2 mb-4">
              {message}
            </p>
            <Link to="/profile" className="text-brand-blue font-medium hover:underline">
              Refaire une demande depuis mon profil
            </Link>
          </>
        )}
      </div>
    </div>
  );
}
