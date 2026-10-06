import { useState, type FormEvent } from "react";
import { useAuth } from "../auth/AuthContext";
import { PortalApiError } from "../api/errors";
import { useOnline } from "../hooks/useOnline";

export function LoginScreen() {
  const { login } = useAuth();
  const online = useOnline();
  const [account, setAccount] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    if (!online) {
      setError("Keine Verbindung. Anmeldung nur online möglich.");
      return;
    }
    setBusy(true);
    try {
      await login(account.trim(), password);
      // Success only after server confirmation (api.login throws otherwise).
    } catch (err) {
      if (err instanceof PortalApiError) {
        if (err.code === "LOGIN_THROTTLED") {
          setError("Zu viele Fehlversuche. Bitte später erneut versuchen.");
        } else {
          setError(err.message);
        }
      } else {
        setError("Anmeldung fehlgeschlagen.");
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="screen">
      <div className="login-panel">
        <div>
          <div className="brand-mark">efaPortal</div>
          <p className="muted" style={{ margin: "6px 0 0" }}>
            Steg-Anmeldung — ein Verein, Europa/Berlin
          </p>
        </div>
        <form className="stack" onSubmit={onSubmit}>
          <div className="field">
            <label htmlFor="account">Konto</label>
            <input
              id="account"
              autoComplete="username"
              value={account}
              onChange={(e) => setAccount(e.target.value)}
              required
            />
          </div>
          <div className="field">
            <label htmlFor="password">Passwort</label>
            <input
              id="password"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </div>
          {error && <div className="error-box">{error}</div>}
          <button type="submit" className="btn btn-primary btn-block" disabled={busy || !online}>
            {busy ? "Anmelden…" : "Anmelden"}
          </button>
        </form>
        <p className="muted" style={{ fontSize: "0.85rem", margin: 0 }}>
          Passwort-Reset nur über einen Administrator.
        </p>
      </div>
    </div>
  );
}
