import { Navigate, Outlet } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import type { Role } from "../types";

/** Sans "roles" : il suffit d'être connecté. Avec "roles" : le rôle doit être dans la liste. */
export default function ProtectedRoute({ roles }: { roles?: Role[] }) {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <div className="min-h-screen bg-brand-white flex items-center justify-center">
        <p className="text-brand-blue">Chargement...</p>
      </div>
    );
  }

  if (!user) return <Navigate to="/" replace />;

  if (roles && (!user.role || !roles.includes(user.role))) {
    return <Navigate to="/dashboard" replace />;
  }

  return <Outlet />;
}
