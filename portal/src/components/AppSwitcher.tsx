type AppId = "cloud" | "portal" | "stats";

const APPS: { id: AppId; href: string; label: string }[] = [
  { id: "cloud", href: "/pages/home.php", label: "Verwaltung" },
  { id: "portal", href: "/portal/", label: "Portal" },
  { id: "stats", href: "/stats/", label: "Statistik" },
];

export function AppSwitcher({ active }: { active: AppId }) {
  return (
    <nav className="app-switcher" aria-label="Vereins-Apps">
      {APPS.map((app) => (
        <a
          key={app.id}
          className={`app-switcher-link${app.id === active ? " is-active" : ""}`}
          href={app.href}
          aria-current={app.id === active ? "page" : undefined}
        >
          {app.label}
        </a>
      ))}
    </nav>
  );
}
