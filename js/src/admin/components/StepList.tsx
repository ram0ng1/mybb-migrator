import app from "flarum/admin/app";
import Component, { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import extractText from "flarum/common/utils/extractText";
import type Mithril from "mithril";

import type MigratorState from "../states/MigratorState";
import type { Phase, StepDef, StepStatus } from "../types";
import LiveConsole from "./LiveConsole";
import ProgressBar from "./ProgressBar";

interface Attrs extends ComponentAttrs {
  state: MigratorState;
  phases: Phase[];
  /** Mostra o número de ordem (1..N) na sequência Fase 1+2. */
  numbered?: boolean;
}

const BOOL_OPTS = [
  "dry-run",
  "like",
  "skip-file-check",
  "skip-soft-deleted",
  "recover-likers",
  "all-hosts",
  "retry-failed",
  "relink-only",
  "include-hidden",
];

/** Opções cujo input é numérico (as demais com valor são texto livre). */
const NUMBER_OPTS = ["limit", "max-mb", "max-file-mb", "posts"];

const trans = (key: string, args: Record<string, unknown> = {}): string =>
  extractText(app.translator.trans(`ramon-mybb-migrator.admin.${key}`, args));

/** Tradução opcional: devolve null quando a chave não existe. */
const transOpt = (key: string): string | null => {
  const full = `ramon-mybb-migrator.admin.${key}`;
  const t = extractText(app.translator.trans(full));
  return t === full ? null : t;
};

const humanize = (key: string): string =>
  key.replace(/-/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());

/** Lista de cards de passo para as fases informadas. */
export default class StepList extends Component<Attrs> {
  private opts: Record<string, Record<string, unknown>> = {};
  private expanded: Record<string, boolean> = {};
  private logs: Record<string, string> = {};

  view(): Mithril.Children {
    const { state, phases } = this.attrs;
    const catalog = state.status?.catalog ?? [];
    const steps = catalog.filter((s) => phases.includes(s.phase));

    return <div className="MmSteps">{steps.map((def) => this.card(def))}</div>;
  }

  /** Número de ordem do passo na sequência Fase 1 + Fase 2 (0 = fora dela). */
  private orderNumber(key: string): number {
    const catalog = this.attrs.state.status?.catalog ?? [];
    const order = catalog
      .filter((s) => (s.phase === "1" || s.phase === "2") && !s.manual)
      .map((s) => s.key);
    return order.indexOf(key) + 1;
  }

  private card(def: StepDef): Mithril.Children {
    const st = this.attrs.state.stepStatus(def.key);
    const status = st?.status ?? "pending";
    const isThisRunning = this.attrs.state.status?.running === def.key;
    const num = this.attrs.numbered ? this.orderNumber(def.key) : 0;

    return (
      <div className={`MmStep MmStep--${status}`}>
        <div className="MmStep-head">
          <div className="MmStep-title">
            {num > 0 && <span className="MmStep-num">{num}</span>}
            <span className="MmStep-name">{humanize(def.key)}</span>
            <code className="MmStep-cmd">{def.command}</code>
            {def.dangerous && (
              <span className="MmStep-danger">{trans("steps.dangerous")}</span>
            )}
            {def.manual && (
              <span className="MmStep-manual">{trans("steps.manual")}</span>
            )}
          </div>
          {this.badge(status, st)}
        </div>

        {transOpt(`steps.desc.${def.key}`) && (
          <p className="MmStep-desc">{transOpt(`steps.desc.${def.key}`)}</p>
        )}

        {/* Sempre renderizado: é o COMPONENTE que decide não desenhar nada sem
            progresso. Alternar o vnode entre null e elemento no meio de irmãos
            sem `key` faz o Mithril inserir em vez de atualizar, deixando o nó
            anterior órfão no DOM — foi o que duplicava os cards em execução. */}
        <ProgressBar progress={st?.progress ?? null} />

        {this.optionsRow(def)}

        <div className="MmStep-actions">
          <Button
            className="Button Button--primary Button--sm"
            disabled={this.attrs.state.isRunning()}
            loading={isThisRunning}
            onclick={() => this.run(def)}
          >
            {trans(def.options.includes("dry-run") && this.opts[def.key]?.["dry-run"] ? "steps.dry_run" : "steps.run")}
          </Button>
          <Button
            className="Button Button--text Button--sm"
            onclick={() => this.toggleLog(def.key, isThisRunning)}
          >
            {this.expanded[def.key] ? trans("steps.hide_log") : trans("steps.show_log")}
          </Button>
          {(status === "done" || status === "failed") && (
            <Button
              className="Button Button--text Button--sm"
              disabled={this.attrs.state.isRunning()}
              onclick={() => this.attrs.state.cancel("reset", def.key)}
            >
              {trans("steps.reset")}
            </Button>
          )}
        </div>

        {this.summaryView(st)}

        {/* Mesmo motivo: o container fica, só o conteúdo dele muda. */}
        <div className="MmStep-log">
          {this.expanded[def.key] ? (
            <LiveConsole
              text={isThisRunning ? this.attrs.state.status?.runningLog ?? "" : this.logs[def.key] ?? ""}
              follow={isThisRunning}
            />
          ) : null}
        </div>
      </div>
    );
  }

  private badge(status: string, st: StepStatus | null): Mithril.Children {
    const label = st?.stale ? trans("status.stale") : trans(`status.${status}`);
    const cls = st?.stale ? "stale" : status;
    return (
      <span className={`MmBadge MmBadge--${cls}`}>
        {status === "running" && <span className="MmSpinner" />}
        {label}
        {status === "failed" && st?.exit_code != null ? ` (${st.exit_code})` : ""}
      </span>
    );
  }

  private optionsRow(def: StepDef): Mithril.Children {
    if (!def.options.length && !def.requiresUsername) return null;
    this.opts[def.key] ??= {};
    const store = this.opts[def.key];

    return (
      <div className="MmStep-opts">
        {def.options.map((opt) => {
          if (BOOL_OPTS.includes(opt)) {
            return (
              <label className="MmOpt">
                <input
                  type="checkbox"
                  checked={!!store[opt]}
                  onchange={(e: Event) =>
                    (store[opt] = (e.target as HTMLInputElement).checked)
                  }
                />
                {opt}
              </label>
            );
          }
          // opções com valor (limit, username, ...)
          return (
            <label className="MmOpt">
              {opt}
              <input
                className="FormControl FormControl--sm"
                type={NUMBER_OPTS.includes(opt) ? "number" : "text"}
                title={opt}
                value={(store[opt] as string) ?? ""}
                oninput={(e: InputEvent) =>
                  (store[opt] = (e.target as HTMLInputElement).value)
                }
              />
            </label>
          );
        })}
      </div>
    );
  }

  private summaryView(st: StepStatus | null): Mithril.Children {
    const counts = st?.summary?.counts;
    const warnings = st?.summary?.warnings ?? [];
    const hasCounts = counts && Object.keys(counts).length > 0;
    if (!hasCounts && warnings.length === 0) return null;

    return (
      <div>
        {hasCounts && (
          <div className="MmChips MmChips--summary">
            {Object.entries(counts!)
              .slice(0, 10)
              .map(([k, v]) => (
                <span className="MmChip">
                  {k}: {Number(v).toLocaleString()}
                </span>
              ))}
          </div>
        )}
        {warnings.length > 0 && (
          <div className="MmWarnings">
            <div className="MmWarnings-head">
              {trans("steps.warnings", { count: warnings.length })}
            </div>
            {warnings.slice(0, 20).map((w) => (
              <div className="MmWarn">⚠ {w}</div>
            ))}
            {warnings.length > 20 && (
              <div className="MmWarn MmWarn--more">
                {trans("steps.warnings_more", { count: warnings.length - 20 })}
              </div>
            )}
          </div>
        )}
      </div>
    );
  }

  private run(def: StepDef): void {
    if (def.dangerous && !confirm(trans("steps.confirm_dangerous", { name: def.command }))) {
      return;
    }
    const extra = this.cleanOpts(def);
    void this.attrs.state.run({ step: def.key, extra: { [def.key]: extra } });
    this.expanded[def.key] = true;
  }

  /** Remove opções vazias/false antes de enviar. */
  private cleanOpts(def: StepDef): Record<string, unknown> {
    const raw = this.opts[def.key] ?? {};
    const out: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(raw)) {
      if (v === true) out[k] = true;
      else if (v !== false && v !== null && v !== "" && v !== undefined) out[k] = v;
    }
    return out;
  }

  private async toggleLog(key: string, isThisRunning: boolean): Promise<void> {
    this.expanded[key] = !this.expanded[key];
    if (this.expanded[key] && !isThisRunning) {
      this.logs[key] = await this.attrs.state.fetchLog(key);
      m.redraw();
    }
  }
}
