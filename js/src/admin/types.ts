export type Phase = "0" | "1" | "2" | "3" | "diag";
export type StepStatusName =
  | "pending"
  | "running"
  | "done"
  | "failed"
  | "skipped";

export interface StepDef {
  key: string;
  command: string;
  phase: Phase;
  force: boolean;
  dangerous: boolean;
  options: string[];
  requiresUsername: boolean;
  /** Fora das sequências guiadas (Rodar Fase / Rodar tudo); só execução individual. */
  manual: boolean;
}

export interface StepSummary {
  counts?: Record<string, number>;
  warnings?: string[];
  tail?: string[];
  error?: string;
}

export interface StepStatus {
  status: StepStatusName;
  exit_code: number | null;
  summary: StepSummary | null;
  started_at: string | null;
  finished_at: string | null;
  stale: boolean;
}

export interface ConnectionMeta {
  host: string;
  port: number;
  user: string;
  db: string;
  prefix: string;
  old_site_url: string;
  password_set: boolean;
  php_binary: string;
  php_resolved: string;
  php_autodetected: boolean;
  php_valid: boolean | null;
  php_version: string | null;
}

export interface PreflightExtension {
  id: string;
  enabled: boolean;
  required: boolean;
}

export interface Preflight {
  legacy_table: boolean;
  steps_table: boolean;
  extensions: PreflightExtension[];
}

export interface SourceCounts {
  ok: boolean;
  error: string | null;
  counts: Record<string, number>;
}

export interface MigratorStatus {
  connection: ConnectionMeta;
  preflight: Preflight;
  running: string | null;
  runningLog: string;
  steps: Record<string, StepStatus>;
  catalog: StepDef[];
  source?: SourceCounts;
  target?: Record<string, number>;
}

export interface TestResult {
  ok: boolean;
  error: string | null;
  counts: Record<string, number>;
  php: {
    ok: boolean;
    version: string | null;
    resolved: string;
    autodetected: boolean;
    error?: string | null;
  };
}

export interface ConnectionPayload {
  host?: string;
  port?: string | number;
  user?: string;
  password?: string;
  db?: string;
  prefix?: string;
  php_binary?: string;
  old_site_url?: string;
}

export interface CompareResult {
  pid: number;
  title: string | null;
  number: number | null;
  found_flarum: boolean;
  found_mybb: boolean;
  old_url: string | null;
  old_site: string;
  old_html: string | null;
  mybb_html: string | null;
  mybb_bbcode: string | null;
  mybb_error: string | null;
  flarum_html: string | null;
}

export interface RunPayload {
  sequence?: "phase1" | "phase2" | "all";
  steps?: string[];
  step?: string;
  extra?: Record<string, Record<string, unknown>>;
}
