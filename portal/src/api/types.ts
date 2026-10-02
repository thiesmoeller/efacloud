/** Portal API v1 types — aligned with docs/api/portal-v1.md */

export type BoatView = "available" | "onwater" | "unavailable";

export type AckKind = "status_notavailable" | "reservation" | "damage";

export type DamageSeverity = "NOTUSEABLE" | "LIMITEDUSEABLE" | "FULLYUSEABLE";

export interface PortalUser {
  efaCloudUserID: number;
  email: string;
  firstName: string;
  lastName: string;
  role: string;
  personId: string;
}

export interface PortalPrivileges {
  trainer: boolean;
  canReportDamage: boolean;
  canManageOwnTrips: boolean;
  canManageAnyTrips: boolean;
  isAdmin: boolean;
  passwordReset: string;
}

export interface SessionResponse {
  authenticated: boolean;
  user?: PortalUser;
  csrfToken: string;
  privileges?: PortalPrivileges;
}

export interface SeatCategory {
  code: string;
  label: string;
}

export interface DamageSummary {
  hasOpen: boolean;
  mostSevere: DamageSeverity | string | null;
  mostSevereLabel?: string;
  count: number;
  indicator: string | null;
  openSeverities?: string[];
}

export interface BoatVariantRow {
  boatId: string;
  name: string;
  variantIndex: number;
  variant: string;
  typeSeats: string;
  seatCategory: string;
  seatCategoryLabel: string;
  typeRigging: string;
  typeCoxing: string;
  typeType: string;
  typeDescription: string;
  defaultVariant: string;
  status: string;
  showInList: string;
  listView: BoatView | string;
  statusComment: string;
  entryNo: string | null;
  logbook: string | null;
  damage: DamageSummary;
  badges: {
    rigging: string;
    coxing: string;
    hullType: string;
  };
}

export interface BoatsListResponse {
  boats: BoatVariantRow[];
  seatCategories: SeatCategory[];
}

export interface DamageRecord {
  boatId: string;
  damage: string;
  severity: string;
  severityLabel: string;
  description: string;
  reportDate: string;
  reportTime: string;
  reportedByPersonId: string;
  reportedByPersonName: string;
  fixed: boolean;
  logbookText: string;
  changeCount: number | null;
  ecrid: string | null;
}

export interface BoatDetailResponse {
  boat: Record<string, unknown>;
  variants: Array<{
    boatId: string;
    name: string;
    variantIndex: number;
    variant: string;
    typeSeats: string;
    seatCategory: string;
    seatCategoryLabel: string;
    typeRigging: string;
    typeCoxing: string;
    typeType: string;
    typeDescription: string;
    defaultVariant: string;
  }>;
  status: Record<string, unknown>;
  listView: string;
  openDamages: DamageRecord[];
  reservations: Array<Record<string, unknown>>;
  damage: DamageSummary;
}

export interface Person {
  id: string;
  firstName: string;
  lastName: string;
  displayName: string;
}

export interface Destination {
  id: string;
  name: string;
  distance: string;
}

export interface TripCrewMember {
  position: number;
  id: string;
  name: string;
}

export interface Trip {
  logbookName: string;
  entryId: string;
  boatId: string;
  boatName: string;
  boatVariant: string;
  date: string;
  endDate: string;
  startTime: string;
  endTime: string;
  cox: { id: string; name: string };
  crew: TripCrewMember[];
  boatCaptain: string;
  destinationId: string;
  destinationName: string;
  distance: string;
  sessionType: string;
  open: boolean;
  changeCount: number;
  lastModified: string | null;
  lastModification: string | null;
  ecrid: string | null;
}

export interface AckCheck {
  kind: AckKind;
  title: string;
  message: string;
  snapshotHash: string;
  details?: Record<string, unknown>;
}

export interface AckRequiredDetails {
  checks?: AckCheck[];
  requiredAcknowledgments?: AckKind[];
  staleKinds?: AckKind[];
  acknowledgmentKind?: AckKind;
}

export interface PortalErrorBody {
  error: {
    code: string;
    message: string;
    details?: AckRequiredDetails & Record<string, unknown>;
  };
}

export interface StartTripBody {
  boatId: string;
  boatVariant: string;
  crew: Array<{ id: string; name?: string }>;
  coxId?: string;
  boatCaptain?: string;
  destinationId?: string;
  destinationName?: string;
  distance?: string;
  acknowledgmentTokens?: string[];
  idempotencyKey: string;
  expectedChangeCount?: number;
}

export interface ClubConfigResponse {
  config: Record<string, unknown>;
  logbookName: string;
  trainerPrivilege: Record<string, unknown>;
  passwordReset: {
    mode: string;
    message: string;
    deskForm?: string;
    apiEndpoint?: string;
    steps?: string[];
  };
}
