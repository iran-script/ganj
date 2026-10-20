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
  organization_id?: string;
  organization?: { id: string; name?: string; status?: string } | null;
  title?: string;
  name?: string;
  slug?: string;
  description?: string | null;
  default_timezone?: string;
  status?: string;
  pricing_type?: string;
    pricing?: {
      mode?: 'FREE' | 'PAID' | 'PER_ROOM' | 'PER_PLAYER';
      amount?: number;
      currency?: string;
      booking_available?: boolean;
      payment_deadline_minutes?: number | null;
      max_active_reservations?: number | null;
      refund_policy?: string | null;
      cancellation_policy?: string | null;
    } | null;
  scenario_version_id?: string | null;
  scenario?: { id: string; title?: string; status?: string; version_no?: number; version_id?: string } | null;
  city?: string | null;
  archived_at?: string | null;
  updated_at?: string;
};

export type SessionStatus = 'DRAFT' | 'REGISTRATION' | 'READY' | 'RUNNING' | 'PAUSED' | 'FINISHED' | 'CANCELLED';

export type GameSession = {
  id: string;
  organization_id?: string;
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

export type Team = { id: string; name?: string; status?: string; member_count?: number; readiness?: string; device_status?: string; session_id?: string | null; session?: { id?: string; title?: string } | null; runtime?: { current_node?: string | null; status?: string } | null; score?: { score?: number } | null; members?: SessionPlayer[]; players?: SessionPlayer[]; updated_at?: string | null; captain_user_id?: string; captain?: { id?: string; name?: string } | null; eligible_for_game?: boolean; organization?: { id: string; name?: string } | null; archived_at?: string | null; created_at?: string | null };

export type RoomTeam = { id: string; name?: string; captain?: string | null; member_count?: number; eligible?: boolean; actor_is_member?: boolean; can_add_to_room?: boolean };
export type Room = { id: string; game_id: string; game_name?: string; city?: string | null; name: string; status: 'OPEN' | 'FULL' | 'CLOSED' | string; session_status?: SessionStatus | null; capacity: number; team_count: number; teams?: RoomTeam[]; created_at?: string | null };

export type SessionPlayer = { id: string; name?: string; user?: { id: string; name: string }; status?: string; presence?: string | null; device_bound?: boolean; device_last_seen_at?: string | null };
export type Device = { id: string; public_id?: string; name?: string; platform?: string; status?: string; last_seen_at?: string | null; user?: { id: string; name?: string } | null; readiness?: string };
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
export type LeaderboardRow = { rank: number; team_id?: string | null; score?: number | null; effective_elapsed_seconds?: number; hints_used?: number; finished_at?: string | null; own_team?: boolean };
export type Leaderboard = { visibility?: string; rankings?: LeaderboardRow[] };

export type TimelineEvent = { event_id?: string; event_type?: string; reason?: string | null; occurred_at?: string; request_id?: string | null };
export type LiveChallenge = { id: string; type: string; status: string; priority?: number; starts_at?: string | null; expires_at?: string | null; interrupt_policy?: string; fairness_class?: string; config?: { message?: string; objective?: string }; targets?: Array<{ type: string; id?: string | null; status?: string }> };
export type SessionAction = 'open-registration' | 'close-registration' | 'ready' | 'start' | 'pause' | 'resume' | 'finish' | 'cancel';
