import axios, { type AxiosError, type InternalAxiosRequestConfig } from "axios";

export interface ApiErrorResponse {
  message?: string;
  errors?: Record<string, string[]>;
}

// L'API est jointe sur la même adresse que la page : en développement Vite transmet /api à Laravel
// (voir vite.config.ts), en production le serveur web sert les deux. VITE_API_URL permet de viser
// une API hébergée ailleurs (ex. http://192.168.1.20:8000/api).
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? "/api",
  headers: {
    "Content-Type": "application/json",
    Accept: "application/json",
  },
});

// Ajoute le jeton de connexion à chaque appel
api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = localStorage.getItem("token");
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Session expirée ou fermée (jeton révoqué, ou connexion sur un autre appareil) :
// supprime le jeton et prévient l'application.
api.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiErrorResponse>) => {
    if (error.response?.status === 401) {
      // Une déconnexion volontaire ne doit pas afficher « session fermée ».
      const volontaire = (error.config?.url ?? "").endsWith("/logout");
      if (!volontaire && localStorage.getItem("token")) {
        sessionStorage.setItem(
          "connexion:fermee",
          "Votre session a été fermée : elle a expiré, ou le compte s'est connecté sur un autre appareil.",
        );
      }
      localStorage.removeItem("token");
      window.dispatchEvent(new Event("auth:expired"));
    }
    return Promise.reject(error);
  }
);

/** Extrait un message d'erreur lisible depuis une erreur axios (Laravel). */
export function getErrorMessage(error: unknown, fallback: string): string {
  if (axios.isAxiosError<ApiErrorResponse>(error)) {
    if (!error.response) {
      return "Impossible de joindre le serveur. Vérifiez que Laravel tourne (php artisan serve) et l'URL de l'API.";
    }
    const data = error.response.data;
    const firstValidationError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
    return firstValidationError ?? data?.message ?? fallback;
  }
  return fallback;
}

export default api;
