import type { DamageSeverity } from "../api/types";

const OPTIONS: Array<{ value: DamageSeverity; label: string }> = [
  { value: "NOTUSEABLE", label: "Boot nicht benutzbar" },
  { value: "LIMITEDUSEABLE", label: "Boot eingeschränkt benutzbar" },
  { value: "FULLYUSEABLE", label: "Boot voll benutzbar" },
];

export function SeverityPicker({
  value,
  onChange,
}: {
  value: DamageSeverity;
  onChange: (v: DamageSeverity) => void;
}) {
  return (
    <div className="field">
      <label htmlFor="severity">Schwere</label>
      <select
        id="severity"
        value={value}
        onChange={(e) => onChange(e.target.value as DamageSeverity)}
      >
        {OPTIONS.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </div>
  );
}
