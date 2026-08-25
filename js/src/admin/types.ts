export type Phase = "0" | "1" | "2" | "3" | "media" | "diag";
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
  /** Flags sempre passados ao comando (ex.: passwords-only), sem UI. */
  fixedArgs?: string[];
}

export interface StepSummary {
  counts?: Record<string, number>;
  warnings?: string[];
  tail?: string[];
  error?: string;
}

/** Progresso ao vivo de um passo em execução (null quando não está rodando). */
export interface StepProgress {
  done: number;
  /** null = total desconhecido de propósito → barra indeterminada. */
  total: number | null;
  label: string | null;
}

export interface StepStatus {
  status: StepStatusName;
  exit_code: number | null;
  summary: StepSummary | null;
  started_at: string | null;
  finished_at: string | null;
  stale: boolean;
  progress: StepProgress | null;
}

export interface ConnectionMeta {
  host: string;
  port: number;
  user: string;
  db: string;
  prefix: string;
  old_site_url: string;
  /** Idioma da saída dos comandos; vazio = default_locale do fórum. */
  cli_locale: string;
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

/** Configuração da aba Imagens (migração de mídia). */
export interface MediaConfig {
  image_hosts: string;
  image_limit: number;
  image_max_mb: number;
  image_max_file_mb: number;
  /** Re-encode na entrada (webp/redimensionamento) ligado? */
  image_optimize: boolean;
  image_webp: boolean;
  image_quality: number;
  image_max_dim: number;
  /** Ritmo de rede: ms entre requisições ao mesmo host e retentativas. */
  image_host_delay: number;
  image_retries: number;
  /**
   * IPs (ou proxies) de saída em rodízio, separados por vírgula. Vazio = o IP
   * do próprio servidor.
   */
  image_exit_ips: string;
  /** O PHP do servidor sabe gravar WebP (GD com libwebp)? */
  webp_supported: boolean;
  attachments_dir: string;
  /** fof/upload habilitado — necessário para o gerenciador de mídia. */
  fof_upload: boolean;
  /** Tabela fof_upload_files presente (extensão já migrou o schema). */
  upload_table: boolean;
  /** Tabela de mapa mybb_migrated_images presente. */
  map_table: boolean;
  /** Pasta absoluta onde os arquivos são gravados. */
  directory: string;
}

/** Agregados do mapa de mídia (só vêm com ?counts=1). */
export interface MediaStats {
  images_ok: number;
  images_failed: number;
  /** Adiadas por 429/timeout: voltam sozinhas na próxima execução do passo. */
  images_deferred: number;
  attachments_ok: number;
  attachments_failed: number;
  attachments_deferred: number;
  bytes: number;
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
  media: MediaConfig;
  mediaStats?: MediaStats;
  /** Unix timestamp de quando as contagens foram calculadas. */
  countsAt?: number;
  countsCached?: boolean;
}

/** Resultado da autodetecção de hosts de imagem + pasta de uploads. */
export interface DetectResult {
  hosts: {
    ranking: Array<{ host: string; count: number }>;
    applied: string[];
    scanned: number;
    truncated: boolean;
    total_hosts: number;
    total_images: number;
  };
  uploads: {
    path: string | null;
    checked: string[];
    samples: string[];
    reason: string | null;
  };
  applied: boolean;
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
  cli_locale?: string;
  image_hosts?: string;
  image_limit?: string | number;
  image_max_mb?: string | number;
  image_max_file_mb?: string | number;
  image_optimize?: boolean;
  image_webp?: boolean;
  image_quality?: string | number;
  image_max_dim?: string | number;
  image_host_delay?: string | number;
  image_retries?: string | number;
  image_exit_ips?: string;
  attachments_dir?: string;
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
