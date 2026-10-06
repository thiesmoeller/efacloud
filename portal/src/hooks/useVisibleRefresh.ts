import { useEffect } from "react";

/** No background polling; also refresh when an installed app resumes. */
export function useVisibleRefresh(refresh: () => void) {
  useEffect(() => {
    const visible = () => { if (document.visibilityState === "visible") refresh(); };
    const interval = window.setInterval(visible, 30_000);
    document.addEventListener("visibilitychange", visible);
    window.addEventListener("pageshow", visible);
    window.addEventListener("online", visible);
    return () => {
      clearInterval(interval);
      document.removeEventListener("visibilitychange", visible);
      window.removeEventListener("pageshow", visible);
      window.removeEventListener("online", visible);
    };
  }, [refresh]);
}
