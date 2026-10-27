import { useTheme } from "../context/ThemeContext";
import { IconMoon, IconSun } from "./icons";

/** Bascule clair / sombre. `sidebar` s'accorde au fond bleu de la navigation. */
export default function ThemeToggle({
  variant = "plain",
  className = "",
}: {
  variant?: "plain" | "sidebar";
  className?: string;
}) {
  const { theme, toggle } = useTheme();
  const dark = theme === "dark";
  const label = dark ? "Passer en mode clair" : "Passer en mode sombre";

  const tone =
    variant === "sidebar"
      ? "text-white/60 hover:bg-white/10 hover:text-white"
      : "border border-line bg-white text-slate-500 hover:text-brand-blue";

  return (
    <button
      type="button"
      onClick={toggle}
      title={label}
      aria-label={label}
      aria-pressed={dark}
      className={`inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition ${tone} ${className}`}
    >
      {dark ? <IconSun className="h-4 w-4" /> : <IconMoon className="h-4 w-4" />}
    </button>
  );
}
