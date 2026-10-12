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

export type Game = {
  id: string;
  title?: string;
  name?: string;
  status?: string;
  pricing_type?: string;
  updated_at?: string;
  scenario?: { id: string; title?: string; status?: string } | null;
};

export type SessionStatus = 'DRAFT' | 'REGISTRATION' | 'READY' | 'RUNNING' | 'PAUSED' | 'FINISHED' | 'CANCELLED';

export type GameSession = {
  id: string;
  title?: string;
  status: SessionStatus;
  game?: { id?: string; title?: string; name?: string } | null;
  teams_count?: number;
  team_count?: number;
  starts_at?: string | null;
  runtime?: { status?: string; current_node?: string | null; score?: number } | null;
};

export type Team = { id: string; name?: string; status?: string; member_count?: number };

export type Health = { status: 'ok' | 'degraded'; checks?: Record<string, string> };

export type ControlRoom = {
  session?: GameSession;
  teams?: Array<Team & { score?: number; status?: string }>;
  runtime?: { status?: string; current_node?: string | null; timer?: number | null };
  events?: Array<{ id?: string; type?: string; occurred_at?: string; message?: string }>;
};
