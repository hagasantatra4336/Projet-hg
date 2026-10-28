// Applique le thème avant le premier rendu pour éviter un flash de page claire.
// Fichier externe (et non script en ligne) pour permettre une CSP stricte sans 'unsafe-inline'.
(function () {
  try {
    var saved = localStorage.getItem("theme");
    var dark = saved ? saved === "dark" : window.matchMedia("(prefers-color-scheme: dark)").matches;
    var root = document.documentElement;
    root.classList.toggle("dark", dark);
    root.style.colorScheme = dark ? "dark" : "light";
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute("content", dark ? "#0E1524" : "#212E53");
  } catch (e) {}
})();
