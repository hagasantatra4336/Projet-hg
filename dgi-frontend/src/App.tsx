import { Routes, Route, Navigate } from "react-router-dom";
import LoginPage from "./pages/Auth/LoginPage";
import SignupPage from "./pages/Auth/SignupPage";
import ProtectedRoute from "./components/ProtectedRoute";
import AppLayout from "./layouts/AppLayout";
import AdminLayout from "./layouts/AdminLayout";
import UsersPage from "./pages/Admin/UsersPage";
import CentresPage from "./pages/Admin/CentresPage";
import FonctionsPage from "./pages/Admin/FonctionsPage";
import ReglesPage from "./pages/Admin/ReglesPage";
import ReglesHistoriquePage from "./pages/Admin/ReglesHistoriquePage";
import ReglesPersonnaliseesPage from "./pages/Admin/ReglesPersonnaliseesPage";
import DashboardPage from "./pages/DashboardPage";
import DefaillancesPage from "./pages/DefaillancesPage";
import TraitementsPage from "./pages/TraitementsPage";
import DossierTraitementPage from "./pages/DossierTraitementPage";
import ProfilePage from "./pages/ProfilePage";
import ProfileWaitingPage from "./pages/ProfileWaitingPage";
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
          {/* Attente de la validation par e-mail d'une modification du profil */}
          <Route path="/profile/attente" element={<ProfileWaitingPage />} />
          {/* Défaillances de déclaration : tous les rôles (filtrées par centre côté API) */}
          <Route path="/defaillances" element={<DefaillancesPage />} />
          {/* Dossiers de traitement : en cours de traitement et traitements effectués */}
          <Route path="/traitements" element={<TraitementsPage />} />
          {/* Page de traitement d'un dossier (alerte) */}
          <Route path="/defaillances/:id/traitement" element={<DossierTraitementPage />} />

          {/* Section Administration : utilisateurs + centres + fonctions */}
          <Route element={<ProtectedRoute roles={["superadmin", "central", "admin"]} />}>
            <Route path="/admin" element={<AdminLayout />}>
              <Route index element={<Navigate to="utilisateurs" replace />} />
              <Route path="utilisateurs" element={<UsersPage />} />
              <Route element={<ProtectedRoute roles={["superadmin", "central"]} />}>
                <Route path="centres" element={<CentresPage />} />
                {/* Règles de détection : réglages modifiables sans toucher au code */}
                <Route path="regles" element={<ReglesPage />} />
                <Route path="regles/historique" element={<ReglesHistoriquePage />} />
                <Route path="regles-personnalisees" element={<ReglesPersonnaliseesPage />} />
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
