import { useEffect, useState } from "react";

type SwState = {
  waiting: ServiceWorker | null;
  applyUpdate: () => void;
};

export function useServiceWorker(): SwState {
  const [waiting, setWaiting] = useState<ServiceWorker | null>(null);

  useEffect(() => {
    if (!("serviceWorker" in navigator)) return;
    let timer: number | undefined;
    let cancelled = false;
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
        if (cancelled) return;
        reg = r;
        if (r.waiting) setWaiting(r.waiting);
        r.addEventListener("updatefound", onUpdateFound);
        // Periodic check while app is open.
        timer = window.setInterval(() => r.update().catch(() => {}), 60_000);

      })
      .catch(() => {
        /* SW optional in local vite without HTTPS; ignore */
      });
    return () => { cancelled = true; clearInterval(timer); reg?.removeEventListener("updatefound", onUpdateFound); };
  }, []);

  const applyUpdate = () => {
    if (!waiting) return;
    navigator.serviceWorker.addEventListener("controllerchange", () => window.location.reload(), { once: true });
    waiting.postMessage({ type: "SKIP_WAITING" });
    setWaiting(null);

  };

  return { waiting, applyUpdate };
}
