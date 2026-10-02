export function SwUpdateBanner({
  visible,
  onUpdate,
}: {
  visible: boolean;
  onUpdate: () => void;
}) {
  if (!visible) return null;
  return (
    <div className="banner banner-update" role="status">
      <span>Neue Version verfügbar</span>
      <button type="button" className="btn btn-secondary" onClick={onUpdate}>
        Aktualisieren
      </button>
    </div>
  );
}
