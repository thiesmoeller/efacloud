import { Fragment, type ReactNode } from "react";
import { Navigate, Route, Routes, useLocation } from "react-router-dom";
import { useAuth } from "./auth/AuthContext";
import { OfflineBanner } from "./components/OfflineBanner";
import { SwUpdateBanner } from "./components/SwUpdateBanner";
import { useOnline } from "./hooks/useOnline";
import { useServiceWorker } from "./hooks/useServiceWorker";
import { MyTripsScreen } from "./screens/MyTripsScreen";
import { BoatDetailScreen } from "./screens/BoatDetailScreen";
import { BoatListScreen } from "./screens/BoatListScreen";
import { DamageListScreen } from "./screens/DamageListScreen";
import { DamageReportScreen } from "./screens/DamageReportScreen";
import { LoginScreen } from "./screens/LoginScreen";
import { TripFormScreen } from "./screens/TripFormScreen";
import { VariantPickScreen } from "./screens/VariantPickScreen";

function Protected({ children }: { children: ReactNode }) {
  const { loading, authenticated, user } = useAuth();
  if (loading) {
    return (
      <div className="screen">
        <p className="muted">Sitzung prüfen…</p>
      </div>
    );
  }
  if (!authenticated) return <Navigate to="/login" replace />;
  return <Fragment key={user?.efaCloudUserID}>{children}</Fragment>;
}

export function App() {
  const online = useOnline();
  const location = useLocation();
  const editing = location.pathname.startsWith("/trips/") || location.pathname.endsWith("/damages/new");
  const { waiting, applyUpdate } = useServiceWorker();
  const { authenticated } = useAuth();

  return (
    <div className="app-shell">
      <OfflineBanner online={online} />
      <SwUpdateBanner visible={!!waiting && !editing} onUpdate={applyUpdate} />
      <Routes>
        <Route
          path="/login"
          element={authenticated ? <Navigate to="/" replace /> : <LoginScreen />}
        />
        <Route
          path="/"
          element={
            <Protected>
              <MyTripsScreen />
            </Protected>
          }
        />
        <Route path="/boats" element={<Protected><BoatListScreen /></Protected>} />
        <Route
          path="/boats/:boatId"
          element={
            <Protected>
              <BoatDetailScreen />
            </Protected>
          }
        />
        <Route
          path="/boats/:boatId/variants"
          element={
            <Protected>
              <VariantPickScreen />
            </Protected>
          }
        />
        <Route
          path="/boats/:boatId/damages"
          element={
            <Protected>
              <DamageListScreen />
            </Protected>
          }
        />
        <Route
          path="/boats/:boatId/damages/new"
          element={
            <Protected>
              <DamageReportScreen />
            </Protected>
          }
        />
        <Route
          path="/trips/new"
          element={
            <Protected>
              <TripFormScreen />
            </Protected>
          }
        />
        <Route
          path="/trips/:entryId"
          element={
            <Protected>
              <TripFormScreen />
            </Protected>
          }
        />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </div>
  );
}
