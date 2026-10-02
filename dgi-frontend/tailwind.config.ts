import type { Config } from "tailwindcss";

export default {
  content: ["./index.html", "./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        "brand-green": "#7AA95C",
        "brand-white": "#F8F8F8",
        "brand-blue": "#212E53",
      },
    },
  },
  plugins: [],
} satisfies Config;
