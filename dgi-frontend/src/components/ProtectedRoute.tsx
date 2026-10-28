import { Navigate, Outlet } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import AccessDeniedPage from "../pages/AccessDeniedPage";
import type { Role } from "../types";

/**
 * Sans "roles" : il suffit d'être connecté. Avec "roles" : le rôle doit être dans la liste.
 * Une URL saisie directement dans la barre d'adresse passe par les mêmes contrôles qu'un clic :
 * un rôle non autorisé reçoit une page 403, jamais le contenu de la section.
 */
export default function ProtectedRoute({ roles }: { roles?: Role[] }) {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <div className="min-h-screen bg-canvas flex items-center justify-center">
        <p className="text-brand-blue">Chargement...</p>
      </div>
    );
  }

  if (!user) return <Navigate to="/" replace />;

  if (roles && (!user.role || !roles.includes(user.role))) {
    return <AccessDeniedPage />;
  }

  return <Outlet />;
}
