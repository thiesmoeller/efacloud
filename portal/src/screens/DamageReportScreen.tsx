import { useState, useRef, type FormEvent } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { MutationAttempt } from "../lib/mutationAttempt";
import { useOnline } from "../hooks/useOnline";
import { api } from "../api/client";
import { PortalApiError } from "../api/errors";
import type { DamageSeverity } from "../api/types";
import { ScreenHeader } from "../components/ScreenHeader";
import { SeverityPicker } from "../components/SeverityPicker";

export function DamageReportScreen({ forBoat, onDone, onBusyChange }: { forBoat?: string; onDone?: () => void; onBusyChange?: (busy: boolean) => void } = {}) {
  const { boatId: routeBoat = "" } = useParams();
  const boatId = forBoat ?? routeBoat;
  const attempt = useRef(new MutationAttempt());
  const online = useOnline();
  const navigate = useNavigate();
  const [severity, setSeverity] = useState<DamageSeverity>("LIMITEDUSEABLE");
  const [description, setDescription] = useState("");
  const [logbookText, setLogbookText] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    onBusyChange?.(true);
    setError(null);
    setSuccess(null);
    try {
      const res = await attempt.current.run({
        severity,
        description: description.trim(),
        logbookText: logbookText.trim() || undefined,
      }, b => api.reportDamage(boatId, b as Parameters<typeof api.reportDamage>[1]));
      // Reporting must not imply the trip finished.
      const note =
        res.message ||
        (res.tripAborted === false
          ? "Schaden gemeldet. Die Fahrt wurde nicht beendet oder abgebrochen."
          : "Schaden gemeldet.");
      setSuccess(note);
      if (!onDone) navigate(`/boats/${encodeURIComponent(boatId)}/damages`, { replace: true });
    } catch (err) {
      setError(err instanceof PortalApiError ? err.message : "Melden fehlgeschlagen.");
    } finally {
      setBusy(false);
      onBusyChange?.(false);
    }
  };

  return (
    <div className="screen">
      {!onDone && <ScreenHeader title="Schaden melden" backTo={`/boats/${encodeURIComponent(boatId)}`} />}
      <p className="muted" style={{ margin: 0 }}>
        Melden beendet keine Fahrt und bricht sie nicht ab.
      </p>
      <form className="stack" onSubmit={(e) => void onSubmit(e)}>
        <SeverityPicker value={severity} onChange={setSeverity} />
        <div className="field">
          <label htmlFor="desc">Beschreibung</label>
          <textarea
            id="desc"
            required
            value={description}
            onChange={(e) => setDescription(e.target.value)}
          />
        </div>
        <div className="field">
          <label htmlFor="lb">Fahrtenbuch-Text (optional)</label>
          <input
            id="lb"
            value={logbookText}
            onChange={(e) => setLogbookText(e.target.value)}
          />
        </div>
        {error && <div className="error-box">{error}</div>}
        {success && <div className="success-box">{success}</div>}
        <button type="submit" className="btn btn-primary btn-block" disabled={busy || !online || !!success}>
          Melden
        </button>
      </form>
      {onDone && <button type="button" disabled={busy} className="btn btn-secondary" onClick={onDone}>Zurück zur Fahrt</button>}
    </div>
  );
}
