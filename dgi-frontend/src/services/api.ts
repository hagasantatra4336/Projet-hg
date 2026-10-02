import axios, { type AxiosError, type InternalAxiosRequestConfig } from "axios";

export interface ApiErrorResponse {
  message?: string;
  errors?: Record<string, string[]>;
}

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? "http://127.0.0.1:8000/api", // adaptez dans .env si besoin
  headers: {
    "Content-Type": "application/json",
    Accept: "application/json",
  },
});

api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = localStorage.getItem("token");
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiErrorResponse>) => {
    if (error.response?.status === 401) {
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
