import type { ReactNode } from "react";
import { useNavigate } from "react-router-dom";

type Props = {
  title: string;
  backTo?: string;
  onBack?: () => void;
  trailing?: ReactNode;
};

export function ScreenHeader({ title, backTo, onBack, trailing }: Props) {
  const navigate = useNavigate();
  const goBack = () => {
    if (onBack) onBack();
    else if (backTo) navigate(backTo);
    else navigate(-1);
  };

  return (
    <header className="screen-header">
      {(backTo || onBack) && (
        <button type="button" className="btn btn-ghost" onClick={goBack} aria-label="Zurück">
          ←
        </button>
      )}
      <h1>{title}</h1>
      {trailing}
    </header>
  );
}
