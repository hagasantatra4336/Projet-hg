import type { Config } from "tailwindcss";

export default {
  content: ["./index.html", "./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        // Couleurs d'origine (inchangées)
        "brand-green": "#7AA95C",
        "brand-white": "#F8F8F8",
        "brand-blue": "#212E53",
        // Nuances tirées des logos DGI et MEF
        "brand-blue-dark": "#161F3A",
        "brand-blue-light": "#2E3F72",
        "brand-teal": "#90C0C0",
        "brand-gold": "#C4952B",
        // Surfaces
        canvas: "#EDEFF3",
        line: "#D9DEE7",
        leaf: { 50: "#EEF5E9", 100: "#DDEBD2" },
      },
      fontFamily: {
        sans: ['"Libre Franklin"', "ui-sans-serif", "system-ui", "-apple-system", '"Segoe UI"', "Roboto", "Arial", "sans-serif"],
      },
      // Rayons hiérarchisés : champs/boutons 4px, panneaux 6px, fenêtres 8px
      borderRadius: {
        md: "3px",
        lg: "4px",
        xl: "6px",
        "2xl": "8px",
      },
      // Les panneaux se détachent par leur bordure ; l'ombre est réservée à ce qui flotte
      boxShadow: {
        sm: "0 1px 0 rgb(22 31 58 / 0.04)",
        DEFAULT: "0 1px 0 rgb(22 31 58 / 0.04)",
        md: "0 2px 6px -2px rgb(22 31 58 / 0.18)",
        lg: "0 14px 34px -14px rgb(22 31 58 / 0.4)",
        xl: "0 18px 44px -16px rgb(22 31 58 / 0.45)",
      },
    },
  },
  plugins: [],
} satisfies Config;
