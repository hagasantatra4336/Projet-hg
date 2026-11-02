import type { Config } from "tailwindcss";

export default {
  darkMode: "class",
  content: ["./index.html", "./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        // Bleu institutionnel : structure, titres et actions principales
        "brand-blue": "#1E3A8A",
        "brand-white": "#F8FAFC",
        "brand-blue-dark": "#172554",
        "brand-blue-light": "#1D4ED8",
        // Azur : liens, anneaux de focus, états sélectionnés
        "brand-azure": "#2563EB",
        "brand-azure-soft": "#EFF4FF",
        // Accents
        "brand-teal": "#2B7A86",
        "brand-gold": "#C4952B",
        "brand-gold-dark": "#8A6414",
        "brand-gold-soft": "#FBF3E1",
        "brand-green": "#4F8437",
        "brand-green-soft": "#EEF6E7",
        // Surfaces
        canvas: "#F4F6FB",
        line: "#E2E7F1",
        leaf: { 50: "#EFF6E9", 100: "#DFEED2" },
      },
      fontFamily: {
        sans: ['"Libre Franklin"', "ui-sans-serif", "system-ui", "-apple-system", '"Segoe UI"', "Roboto", "Arial", "sans-serif"],
      },
      // Rayons hiérarchisés : puces 6px, champs/boutons 8px, panneaux 12px, fenêtres 16px
      borderRadius: {
        md: "6px",
        lg: "8px",
        xl: "12px",
        "2xl": "16px",
      },
      // Les panneaux se détachent par leur bordure ; l'ombre est réservée à ce qui flotte
      boxShadow: {
        sm: "0 1px 2px 0 rgb(15 23 42 / 0.05)",
        DEFAULT: "0 1px 2px 0 rgb(15 23 42 / 0.05), 0 1px 3px 0 rgb(15 23 42 / 0.06)",
        md: "0 4px 12px -4px rgb(15 23 42 / 0.12), 0 2px 4px -2px rgb(15 23 42 / 0.06)",
        lg: "0 12px 30px -12px rgb(15 23 42 / 0.22), 0 4px 10px -4px rgb(15 23 42 / 0.08)",
        xl: "0 24px 60px -20px rgb(15 23 42 / 0.35), 0 8px 20px -8px rgb(15 23 42 / 0.12)",
      },
    },
  },
  plugins: [],
} satisfies Config;
