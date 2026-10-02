import { useEffect, useState } from "react";

type SwState = {
  waiting: ServiceWorker | null;
  applyUpdate: () => void;
};

export function useServiceWorker(): SwState {
  const [waiting, setWaiting] = useState<ServiceWorker | null>(null);

  useEffect(() => {
    if (!("serviceWorker" in navigator)) return;
    let reg: ServiceWorkerRegistration | undefined;

    const onUpdateFound = () => {
      const installing = reg?.installing;
      if (!installing) return;
      installing.addEventListener("statechange", () => {
        if (installing.state === "installed" && navigator.serviceWorker.controller) {
          setWaiting(installing);
        }
      });
    };

    navigator.serviceWorker
      .register("/portal/sw.js", { scope: "/portal/" })
      .then((r) => {
        reg = r;
        if (r.waiting) setWaiting(r.waiting);
        r.addEventListener("updatefound", onUpdateFound);
        // Periodic check while app is open.
        const id = window.setInterval(() => r.update().catch(() => {}), 60_000);
        return () => window.clearInterval(id);
      })
      .catch(() => {
        /* SW optional in local vite without HTTPS; ignore */
      });
  }, []);

  const applyUpdate = () => {
    waiting?.postMessage({ type: "SKIP_WAITING" });
    setWaiting(null);
    window.location.reload();
  };

  return { waiting, applyUpdate };
}
