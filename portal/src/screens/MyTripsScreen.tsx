import { useCallback, useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { api } from "../api/client";
import type { Trip } from "../api/types";
import { useAuth } from "../auth/AuthContext";
import { ScreenHeader } from "../components/ScreenHeader";
import { useVisibleRefresh } from "../hooks/useVisibleRefresh";

export function MyTripsScreen() {
  const { user, logout } = useAuth();
  const [trips, setTrips] = useState<Trip[]>([]);
  const [error, setError] = useState("");
  const [loaded, setLoaded] = useState(false);
  const generation = useRef(0);
  const load = useCallback(async () => {
    const request = ++generation.current;
    try {
      const result = await api.listMyTrips();
      if (request !== generation.current) return;
      setTrips(result.trips);
      setError("");
      setLoaded(true);
    } catch (err) {
      if (request === generation.current) setError(err instanceof Error ? err.message : "Fahrten konnten nicht geladen werden.");
    }
  }, []);
  useEffect(() => { void load(); return () => { generation.current++; }; }, [load]);
  useVisibleRefresh(load);
  return <main className="screen">
    <ScreenHeader title="Meine gestarteten Fahrten" trailing={<button className="btn btn-ghost" onClick={() => void logout()}>Abmelden</button>} />
    <p className="muted">{user?.firstName} {user?.lastName} · Am Steg</p>
    <Link className="btn btn-primary" to="/boats">Weitere Fahrt starten</Link>
    {error && <div role="alert" className="error-box">{error}<button className="btn btn-secondary" onClick={() => void load()}>Erneut laden</button></div>}
    {!loaded && !error && <p role="status">Fahrten werden geladen…</p>}
    {loaded && trips.length === 0 && <p className="empty">Du hast keine offenen Fahrten gestartet.</p>}
    <ul className="boat-list">
      {trips.map(t => <li key={`${t.logbookName}:${t.ecrid}`} className="trip-card stack">
        <span className="badge">Auf Fahrt</span>
        <h2 className="boat-name">{t.boatName}</h2>
        <p>{t.crew.map(c => `${c.position}. ${c.name}`).join(" · ")}{t.cox.name && ` · Stm. ${t.cox.name}`}</p>
        <p className="muted">Start {t.date} · {t.startTime} Uhr<br />Ziel: {t.destinationName || "Offen"}</p>
        <Link className="btn btn-primary" to={`/trips/${encodeURIComponent(t.entryId)}?logbookName=${encodeURIComponent(t.logbookName)}`}>Fahrt beenden</Link>
      </li>)}
    </ul>
  </main>;
}
