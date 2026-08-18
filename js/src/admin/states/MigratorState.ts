import app from "flarum/admin/app";
import extractText from "flarum/common/utils/extractText";

import apiCall, { apiUrl } from "../utils/apiCall";
import type {
  CompareResult,
  ConnectionPayload,
  DetectResult,
  MigratorStatus,
  RunPayload,
  StepStatus,
  TestResult,
} from "../types";

/**
 * Dono de todo o I/O do painel: carrega o status, faz polling enquanto há um
 * passo em execução e expõe as ações (salvar/testar conexão, rodar, cancelar).
 * As views nunca chamam `app.request` direto.
 */
export default class MigratorState {
  status: MigratorStatus | null = null;
  loading = true;
  /** Ação (run/cancel/save/test) em andamento — desabilita botões. */
  busy = false;
  /** As contagens origem/destino estão sendo buscadas em segundo plano. */
  countsLoading = false;

  private timer: number | null = null;
  private inFlight = false;
  private disposed = false;
  /** Resolve na primeira carga de status — quem depende dela espera, não sonda. */
  private ready!: Promise<void>;
  private markReady!: () => void;

  /**
   * Carga inicial em DUAS etapas, de propósito.
   *
   * As contagens de origem são COUNT(*) em tabelas grandes do MyBB — no fórum de
   * referência, ~12 s. Esperar por elas deixava a página da extensão em branco
   * todo esse tempo. Agora o painel pinta com o status (rápido) e as contagens
   * chegam depois, preenchendo as barras quando ficarem prontas.
   */
  start(): void {
    this.ready = new Promise<void>((resolve) => (this.markReady = resolve));
    void this.refresh(false).then(() => this.loadCounts());
  }

  /** Promessa cumprida quando o primeiro status chega. */
  whenReady(): Promise<void> {
    return this.ready ?? Promise.resolve();
  }

  dispose(): void {
    this.disposed = true;
    if (this.timer !== null) {
      window.clearInterval(this.timer);
      this.timer = null;
    }
  }

  stepStatus(key: string): StepStatus | null {
    return this.status?.steps?.[key] ?? null;
  }

  isRunning(): boolean {
    return !!this.status?.running;
  }

  async refresh(withCounts = false): Promise<void> {
    const res = await apiCall<MigratorStatus>(
      { method: "GET", url: `${apiUrl()}/mybb-migrator/status` },
      { silent: true },
    );
    if (res) this.status = this.merge(res);
    this.loading = false;
    this.markReady?.();
    m.redraw();
    this.managePolling();

    if (withCounts) await this.loadCounts();
  }

  /**
   * Busca as contagens sem travar a tela. `force` recalcula em vez de aceitar o
   * cache do servidor — é o que se quer depois que um passo terminou e os
   * números realmente mudaram.
   */
  async loadCounts(force = false): Promise<void> {
    this.countsLoading = true;
    m.redraw();

    const res = await apiCall<MigratorStatus>(
      {
        method: "GET",
        url: `${apiUrl()}/mybb-migrator/status?counts=1${force ? "&refresh=1" : ""}`,
      },
      { silent: true },
    );

    this.countsLoading = false;
    if (res) this.status = res;
    m.redraw();
  }

  /**
   * Preserva as contagens já carregadas quando chega um status "leve" — sem
   * isso as barras piscariam para vazio a cada ciclo de polling.
   */
  private merge(fresh: MigratorStatus): MigratorStatus {
    const old = this.status;
    if (!old) return fresh;

    return {
      ...fresh,
      source: fresh.source ?? old.source,
      target: fresh.target ?? old.target,
      mediaStats: fresh.mediaStats ?? old.mediaStats,
      countsAt: fresh.countsAt ?? old.countsAt,
    };
  }

  /** Autodetecta hosts de imagem + pasta de uploads e grava nas configurações. */
  async detectMedia(): Promise<DetectResult | null> {
    this.busy = true;
    const res = await apiCall<DetectResult>(
      { method: "POST", url: `${apiUrl()}/mybb-migrator/detect-media`, body: { apply: true } },
      { errorKey: "ramon-mybb-migrator.admin.images.detect_failed" },
    );
    this.busy = false;
    await this.refresh(false);
    return res;
  }

  private managePolling(): void {
    // Depois de descartado nunca reabrimos o timer: um poller órfão continuaria
    // redesenhando uma árvore que não está mais montada.
    if (this.disposed) return;

    const running = this.isRunning();
    if (running && this.timer === null) {
      this.timer = window.setInterval(() => void this.tick(), 1500);
    } else if (!running && this.timer !== null) {
      window.clearInterval(this.timer);
      this.timer = null;
      // terminou: recalcula as contagens (agora elas mudaram de verdade)
      void this.loadCounts(true);
    }
  }

  private async tick(): Promise<void> {
    if (this.inFlight) return;
    this.inFlight = true;
    const res = await apiCall<MigratorStatus>(
      { method: "GET", url: `${apiUrl()}/mybb-migrator/status` },
      { silent: true },
    );
    this.inFlight = false;
    if (res) {
      this.status = this.merge(res);
      m.redraw();
      this.managePolling();
    }
  }

  async saveConnection(payload: ConnectionPayload): Promise<unknown> {
    this.busy = true;
    const res = await apiCall(
      { method: "POST", url: `${apiUrl()}/mybb-migrator/connection`, body: payload },
      { errorKey: "ramon-mybb-migrator.admin.connection.save_failed" },
    );
    this.busy = false;
    await this.refresh(false);
    return res;
  }

  async test(payload: ConnectionPayload): Promise<TestResult | null> {
    this.busy = true;
    const res = await apiCall<TestResult>(
      { method: "POST", url: `${apiUrl()}/mybb-migrator/test`, body: payload },
      { errorKey: "ramon-mybb-migrator.admin.connection.test_failed" },
    );
    this.busy = false;
    m.redraw();
    return res;
  }

  async run(payload: RunPayload): Promise<unknown> {
    this.busy = true;
    const res = await apiCall<{ note?: string }>(
      { method: "POST", url: `${apiUrl()}/mybb-migrator/run`, body: payload },
      { errorKey: "ramon-mybb-migrator.admin.run_failed" },
    );
    this.busy = false;
    // Sequência cujos passos já estavam todos concluídos: avisa (senão o clique
    // parece não fazer nada). Para re-rodar um passo concluído, use "Resetar".
    if (res?.note === "all-done") {
      app.alerts.show(
        { type: "success" },
        extractText(app.translator.trans("ramon-mybb-migrator.admin.run.all_done")),
      );
    }
    await this.refresh(false);
    return res;
  }

  async cancel(action: "cancel" | "reset", step?: string): Promise<unknown> {
    this.busy = true;
    const res = await apiCall(
      {
        method: "POST",
        url: `${apiUrl()}/mybb-migrator/cancel`,
        body: { action, step },
      },
      { errorKey: "ramon-mybb-migrator.admin.cancel_failed" },
    );
    this.busy = false;
    await this.refresh(false);
    return res;
  }

  async compare(opts: { pid?: number; random?: boolean }): Promise<CompareResult | null> {
    const qs = opts.random
      ? "random=1"
      : `pid=${encodeURIComponent(String(opts.pid ?? ""))}`;
    return apiCall<CompareResult>(
      { method: "GET", url: `${apiUrl()}/mybb-migrator/compare?${qs}` },
      { silent: true },
    );
  }

  async fetchLog(step: string): Promise<string> {
    const res = await apiCall<{ step: string; log: string }>(
      {
        method: "GET",
        url: `${apiUrl()}/mybb-migrator/log?step=${encodeURIComponent(step)}`,
      },
      { silent: true },
    );
    return res?.log ?? "";
  }
}
