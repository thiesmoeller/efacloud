import { lazy, Suspense, useCallback, useEffect, useMemo, useState } from "react";
import { api, ApiError } from "./api/client";
import type { Fleet, Metadata, Overview, Personal, Session } from "./api/types";
import { yearRange } from "./lib/format";
import { LoginScreen } from "./screens/LoginScreen";

const OverviewScreen = lazy(() => import("./screens/OverviewScreen").then((module) => ({ default: module.OverviewScreen })));
const FleetScreen = lazy(() => import("./screens/FleetScreen").then((module) => ({ default: module.FleetScreen })));
const PersonalScreen = lazy(() => import("./screens/PersonalScreen").then((module) => ({ default: module.PersonalScreen })));

type View = "overview" | "fleet" | "me";
type Preset = "all" | "five" | "current";

function viewFromPath(): View {
  if (location.pathname.endsWith("/fleet")) return "fleet";
  if (location.pathname.endsWith("/me")) return "me";
  return "overview";
}

export function App() {
  const [session, setSession] = useState<Session | null>(null);
  const [metadata, setMetadata] = useState<Metadata | null>(null);
  const [view, setView] = useState<View>(viewFromPath);
  const [preset, setPreset] = useState<Preset>("all");
  const [overview, setOverview] = useState<Overview | null>(null);
  const [fleet, setFleet] = useState<Fleet | null>(null);
  const [personal, setPersonal] = useState<Personal | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const range = useMemo(() => metadata?.years.length ? yearRange(metadata.years, preset) : null, [metadata, preset]);

  useEffect(() => { api.session().then(setSession).catch(() => setSession({ authenticated: false })).finally(() => setLoading(false)); }, []);
  useEffect(() => {
    const onPop = () => setView(viewFromPath()); window.addEventListener("popstate", onPop); return () => window.removeEventListener("popstate", onPop);
  }, []);
  useEffect(() => {
    if (!session?.authenticated) return;
    api.metadata().then(setMetadata).catch((reason) => setError(reason instanceof Error ? reason.message : "Metadaten fehlen."));
  }, [session]);

  const load = useCallback(async () => {
    if (!range || !session?.authenticated) return;
    setLoading(true); setError("");
    try {
      if (view === "overview") setOverview(await api.overview(range.from, range.to));
      if (view === "fleet") setFleet(await api.fleet(range.from, range.to));
      if (view === "me") setPersonal(await api.personal(range.from, range.to));
    } catch (reason) {
      if (reason instanceof ApiError && reason.status === 401) setSession({ authenticated: false });
      else setError(reason instanceof Error ? reason.message : "Statistik konnte nicht geladen werden.");
    } finally { setLoading(false); }
  }, [range, session, view]);
  useEffect(() => { void load(); }, [load]);

  function navigate(next: View) {
    const suffix = next === "overview" ? "" : next === "fleet" ? "fleet" : "me";
    history.pushState({}, "", `/stats/${suffix}`); setView(next); window.scrollTo({ top: 0, behavior: "smooth" });
  }
  async function login(account: string, password: string) { setSession(await api.login(account, password)); }
  async function logout() { try { await api.logout(); } finally { setSession({ authenticated: false }); setMetadata(null); } }

  if (!session || (loading && !session)) return <div className="splash"><span>efaStats</span><i /></div>;
  if (!session.authenticated) return <LoginScreen onLogin={login} />;

  return <div className="site-shell">
    <header className="topbar"><a className="brand" href="/stats/" onClick={(event) => { event.preventDefault(); navigate("overview"); }}>efa<span>Stats</span></a>
      <nav aria-label="Hauptnavigation"><button className={view === "overview" ? "active" : ""} onClick={() => navigate("overview")}>Übersicht</button><button className={view === "fleet" ? "active" : ""} onClick={() => navigate("fleet")}>Flotte</button><button className={view === "me" ? "active" : ""} onClick={() => navigate("me")}>Meine Statistik</button></nav>
      <div className="account"><span>{session.user?.firstName || "Mitglied"}</span><button onClick={() => void logout()}>Abmelden</button></div>
    </header>
    <main>
      {metadata && <section className="period-bar"><span>Zeitraum</span><div>{(["all", "five", "current"] as Preset[]).map((value) => <button key={value} className={preset === value ? "active" : ""} onClick={() => setPreset(value)}>{value === "all" ? `${metadata.years[0]}–${metadata.years.at(-1)}` : value === "five" ? "Letzte 5 Jahre" : "Aktuelles Jahr"}</button>)}</div></section>}
      {error && <section className="error-panel" role="alert"><strong>Gegenwind.</strong><span>{error}</span><button onClick={() => void load()}>Noch einmal</button></section>}
      {loading && metadata && <div className="loading-line" role="status"><i />Daten werden sortiert …</div>}
      <Suspense fallback={<div className="loading-line" role="status"><i />Ansicht wird vorbereitet …</div>}>
        {!loading && !error && view === "overview" && overview && <OverviewScreen data={overview} />}
        {!loading && !error && view === "fleet" && fleet && <FleetScreen data={fleet} />}
        {!loading && !error && view === "me" && personal && <PersonalScreen data={personal} firstName={session.user?.firstName || ""} />}
      </Suspense>
    </main>
    <footer><p><span className="brand">efa<span>Stats</span></span> · Aus unserem Fahrtenbuch, für unseren Verein.</p><p>Keine Ranglisten. Keine fremden persönlichen Daten.</p></footer>
  </div>;
}
