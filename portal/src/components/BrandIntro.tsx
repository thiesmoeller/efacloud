export function BrandIntro({ showGuide = false }: { showGuide?: boolean }) {
  return (
    <section className="brand-intro" aria-label="efaCloud Fahrtenbuch">
      <img className="brand-logo" src="/portal/icons/icon.svg" alt="" />
      <div>
        <div className="brand-mark"><span>efa</span>Cloud</div>
        <p className="brand-tagline">Fahrtenbuch am Steg</p>
        {showGuide && (
          <p className="brand-guide">
            Boot wählen, Mannschaft eintragen und Fahrt starten. Nach der Rückkehr die offene Fahrt hier auswählen und beenden.
          </p>
        )}
      </div>
    </section>
  );
}
