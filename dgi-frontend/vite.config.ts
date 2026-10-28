import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  server: {
    // Écoute sur toutes les interfaces (0.0.0.0) pour tester depuis un smartphone du réseau local :
    // http://<IPv4 du PC>:5173. Sans cela, Vite n'accepte que localhost.
    host: true,
    // Le front appelle /api : Vite le transmet à Laravel. La page et l'API ont ainsi la même
    // adresse, quel que soit l'hôte utilisé (localhost, IP du réseau local, nom de domaine).
    proxy: {
      "/api": {
        target: "http://127.0.0.1:8000",
        changeOrigin: true,
      },
    },
  },
});
