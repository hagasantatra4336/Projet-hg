import type { Config } from "tailwindcss";

export default {
  content: ["./index.html", "./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        "brand-green": "#9AD59A",
        "brand-white": "#F8F8F8",
        "brand-blue": "#31315F",
      },
    },
  },
  plugins: [],
} satisfies Config;
