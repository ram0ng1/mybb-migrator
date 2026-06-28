import app from "flarum/admin/app";
import extractText from "flarum/common/utils/extractText";

import apiCall, { apiUrl } from "../utils/apiCall";
import type {
  CompareResult,
  ConnectionPayload,
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

  private timer: number | null = null;
  private inFlight = false;

  /** Carga inicial (com contagens). */
  start(): void {
    void this.refresh(true);
  }

  dispose(): void {
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
      {
        method: "GET",
        url: `${apiUrl()}/mybb-migrator/status${withCounts ? "?counts=1" : ""}`,
      },
      { silent: true },
    );
    if (res) this.status = res;
    this.loading = false;
    m.redraw();
    this.managePolling();
  }

  private managePolling(): void {
    const running = this.isRunning();
    if (running && this.timer === null) {
      this.timer = window.setInterval(() => void this.tick(), 1500);
    } else if (!running && this.timer !== null) {
      window.clearInterval(this.timer);
      this.timer = null;
      // terminou: uma última atualização para refletir contagens finais
      void this.refresh(true);
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
      this.status = res;
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
    await this.refresh(true);
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
    await this.refresh(true);
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
