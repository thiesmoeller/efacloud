export type Metric = { trips: number; kilometers: number; hours: number };
export type MetricRow = Metric & { label: string };
export type AnnualRow = Metric & { year: number };

export type Metadata = {
  earliestDate: string | null;
  latestDate: string | null;
  years: number[];
  tripCount: number;
  lastUpdated?: string | null;
};

export type Overview = {
  range: { from: string; to: string };
  headline: Metric & { activeBoats: number; participants: number };
  coverage: { distancePercent: number; durationPercent: number };
  annual: AnnualRow[];
  months: (Metric & { year: number; month: number })[];
  weekdayHours: { weekday: number; hour: number; trips: number }[];
  boatClasses: MetricRow[];
  destinations: MetricRow[];
  seasonPace: {
    currentYear: number;
    points: { day: string; current: number; previous: number; median: number }[];
  };
  facts: {
    busiestDay: { date: string; trips: number } | null;
    longestDistance: number | null;
    medianDistance: number | null;
  };
};

export type FleetBoat = {
  id: string;
  name: string;
  class: string;
  currentlyActive: boolean;
  trips: number;
  kilometers: number;
  hours: number;
  activeDays: number;
  averageDistance: number | null;
  previousTrips: number;
  changePercent: number | null;
  byYear: { year: number; trips: number }[];
};

export type Fleet = { range: { from: string; to: string }; boats: FleetBoat[] };

export type Personal = {
  available: boolean;
  reason?: string;
  range?: { from: string; to: string };
  headline?: Metric & { coxTrips: number; rowedTrips: number };
  coverage?: { distancePercent: number; durationPercent: number };
  annual?: AnnualRow[];
  boats?: MetricRow[];
  destinations?: MetricRow[];
};

export type Session = {
  authenticated: boolean;
  csrfToken?: string;
  user?: { firstName: string; lastName: string; role: string; personId: string };
};
