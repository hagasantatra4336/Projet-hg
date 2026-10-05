import { Routes, Route, Navigate } from "react-router-dom";
import LoginPage from "./pages/Auth/LoginPage";
import SignupPage from "./pages/Auth/SignupPage";
import ProtectedRoute from "./components/ProtectedRoute";
import AppLayout from "./layouts/AppLayout";
import AdminLayout from "./layouts/AdminLayout";
import UsersPage from "./pages/Admin/UsersPage";
import CentresPage from "./pages/Admin/CentresPage";
import FonctionsPage from "./pages/Admin/FonctionsPage";
import DashboardPage from "./pages/DashboardPage";
import DefaillancesPage from "./pages/DefaillancesPage";
import ProfilePage from "./pages/ProfilePage";
import ConfirmChangePage from "./pages/ConfirmChangePage";

export default function App() {
  return (
    <Routes>
      {/* La page d'accueil ("/") affiche directement le Login */}
      <Route path="/" element={<LoginPage />} />
      <Route path="/signup" element={<SignupPage />} />
      {/* Lien reçu par e-mail pour confirmer une modification du profil (accessible sans être connecté) */}
      <Route path="/profile/confirm" element={<ConfirmChangePage />} />

      {/* Pages réservées aux utilisateurs connectés */}
      <Route element={<ProtectedRoute />}>
        <Route element={<AppLayout />}>
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route path="/profile" element={<ProfilePage />} />
          {/* Défaillances de déclaration : tous les rôles (filtrées par centre côté API) */}
          <Route path="/defaillances" element={<DefaillancesPage />} />

          {/* Section Administration : utilisateurs + centres + fonctions */}
          <Route element={<ProtectedRoute roles={["superadmin", "central", "admin"]} />}>
            <Route path="/admin" element={<AdminLayout />}>
              <Route index element={<Navigate to="utilisateurs" replace />} />
              <Route path="utilisateurs" element={<UsersPage />} />
              <Route element={<ProtectedRoute roles={["superadmin", "central"]} />}>
                <Route path="centres" element={<CentresPage />} />
              </Route>
              <Route element={<ProtectedRoute roles={["superadmin"]} />}>
                <Route path="fonctions" element={<FonctionsPage />} />
              </Route>
            </Route>
          </Route>
        </Route>
      </Route>

      {/* Toute route inconnue redirige vers le login */}
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
