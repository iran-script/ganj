export type ApiEnvelope<T> = { data: T; meta?: { pagination?: PaginationMeta } };

export type PaginationMeta = {
  cursor?: string | null;
  next_cursor?: string | null;
  has_more?: boolean;
};

export type ApiError = {
  code: string;
  message: string;
  status: number;
  details?: Record<string, unknown>;
};

export type User = {
  id: string;
  name: string;
  email?: string | null;
  mobile?: string | null;
  status: string;
  roles?: string[];
};
export type Organization = { id: string; name?: string; title?: string; status?: string };

export type Game = {
  id: string;
  title?: string;
  name?: string;
  slug?: string;
  description?: string | null;
  default_timezone?: string;
  status?: string;
  pricing_type?: string;
  pricing?: { mode?: 'FREE' | 'PAID'; amount?: number; currency?: string } | null;
  scenario_version_id?: string | null;
  updated_at?: string;
  scenario?: { id: string; title?: string; status?: string } | null;
};

export type SessionStatus = 'DRAFT' | 'REGISTRATION' | 'READY' | 'RUNNING' | 'PAUSED' | 'FINISHED' | 'CANCELLED';

export type GameSession = {
  id: string;
  game_id?: string;
  title?: string;
  status: SessionStatus;
  game?: { id?: string; title?: string; name?: string } | null;
  teams_count?: number;
  team_count?: number;
  starts_at?: string | null;
  runtime?: { status?: string; current_node?: string | null; score?: number } | null;
  timezone?: string;
  registration_opens_at?: string | null;
  registration_closes_at?: string | null;
  scheduled_start_at?: string | null;
  started_at?: string | null;
  finished_at?: string | null;
  cancelled_at?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
  teams?: SessionTeam[];
};

export type Team = { id: string; name?: string; status?: string; member_count?: number };

export type SessionPlayer = { id: string; name?: string; user?: { id: string; name: string }; status?: string; presence?: string | null; device_bound?: boolean; device_last_seen_at?: string | null };
export type SessionTeam = Team & { team_id?: string; player_count?: number; players?: SessionPlayer[]; runtime?: Record<string, unknown> | null; score?: { score?: number; effective_elapsed_seconds?: number; hints_used?: number; finished_at?: string | null } | null };

export type Health = { status: 'ok' | 'degraded'; checks?: Record<string, string> };

export type ControlRoom = {
  session?: GameSession;
  teams?: SessionTeam[];
  runtime?: { status?: string; current_node?: string | null; timer?: number | null };
  events?: Array<{ id?: string; type?: string; occurred_at?: string; message?: string }>;
  live_challenges?: LiveChallenge[];
  timeline?: TimelineEvent[];
};

export type TimelineEvent = { event_id?: string; event_type?: string; reason?: string | null; occurred_at?: string; request_id?: string | null };
export type LiveChallenge = { id: string; type: string; status: string; priority?: number; starts_at?: string | null; expires_at?: string | null; interrupt_policy?: string; fairness_class?: string; config?: { message?: string; objective?: string }; targets?: Array<{ type: string; id?: string | null; status?: string }> };
export type SessionAction = 'open-registration' | 'close-registration' | 'ready' | 'start' | 'pause' | 'resume' | 'finish' | 'cancel';
