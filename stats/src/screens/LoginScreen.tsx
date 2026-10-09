import { useState, type FormEvent } from "react";

export function LoginScreen({ onLogin }: { onLogin: (account: string, password: string) => Promise<void> }) {
  const [account, setAccount] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError("");
    try { await onLogin(account, password); }
    catch (reason) { setError(reason instanceof Error ? reason.message : "Anmeldung fehlgeschlagen."); }
    finally { setBusy(false); }
  }

  return <main className="login-page">
    <section className="login-story">
      <p className="brand">efa<span>Stats</span></p>
      <p className="eyebrow">Wilhelmsburger Ruderclub</p>
      <h1>Jede Fahrt hinterlässt eine Spur.</h1>
      <p>Zwanzig Jahre auf dem Wasser — als Jahreszeiten, Rhythmen und Geschichten unserer Flotte.</p>
      <div className="water-lines" aria-hidden="true"><i/><i/><i/></div>
    </section>
    <form className="login-card" onSubmit={submit}>
      <p className="eyebrow">Mitgliederbereich</p><h2>Willkommen zurück</h2>
      <label>efaCloud-Konto<input autoComplete="username" value={account} onChange={(e) => setAccount(e.target.value)} required /></label>
      <label>Passwort<input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required /></label>
      {error && <p className="error" role="alert">{error}</p>}
      <button className="primary" disabled={busy}>{busy ? "Anmelden …" : "Statistik öffnen"}</button>
      <small>Deine Anmeldung wird mit efaCloud geteilt. Andere Mitglieder sehen keine persönliche Statistik von dir.</small>
    </form>
  </main>;
}
