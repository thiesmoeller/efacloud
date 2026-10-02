export function OfflineBanner({ online }: { online: boolean }) {
  if (online) return null;
  return (
    <div className="banner banner-offline" role="status">
      Offline — Fahrten nur online speicherbar. Bitte Verbindung prüfen.
    </div>
  );
}
