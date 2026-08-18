import app from "flarum/admin/app";
import Component, { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import extractText from "flarum/common/utils/extractText";
import type Mithril from "mithril";

import type MigratorState from "../states/MigratorState";

interface Attrs extends ComponentAttrs {
  state: MigratorState;
}

const trans = (key: string, args: Record<string, unknown> = {}): string =>
  extractText(app.translator.trans(`ramon-mybb-migrator.admin.${key}`, args));

/** Mapeia entidade -> (contagem origem MyBB, contagem destino Flarum). */
const ROWS: Array<{ key: string; source: string; target: string }> = [
  { key: "users", source: "users", target: "users" },
  { key: "discussions", source: "threads", target: "discussions" },
  { key: "posts", source: "posts", target: "posts" },
  { key: "tags", source: "forums", target: "tags" },
];

/**
 * Barras comparando origem (MyBB) x destino (Flarum) por entidade.
 *
 * As contagens chegam DEPOIS do primeiro render — são COUNT(*) caros no MyBB e
 * o painel não espera por elas. Enquanto não chegam, o card mostra o estado de
 * carregamento em vez de zeros, que pareceriam "nada migrado".
 */
export default class ProgressOverview extends Component<Attrs> {
  view(): Mithril.Children {
    const { state } = this.attrs;
    const status = state.status;
    if (!status) return null;

    const source = status.source?.counts ?? {};
    const target = status.target ?? {};
    const hasSource = !!status.source?.ok;
    const loaded = !!status.countsAt;

    return (
      <div className="MmCard MmProgress">
        <div className="MmProgress-head">
          <h4>{trans("progress.title")}</h4>
          <div className="MmProgress-meta">
            {loaded && (
              <span className="MmMuted">
                {trans("progress.measured_at", {
                  time: new Date((status.countsAt as number) * 1000).toLocaleTimeString(),
                })}
              </span>
            )}
            <Button
              className="Button Button--text Button--sm"
              loading={state.countsLoading}
              onclick={() => state.loadCounts(true)}
            >
              {trans("progress.recount")}
            </Button>
          </div>
        </div>

        {!loaded && state.countsLoading && (
          <p className="MmMuted">
            <LoadingIndicator display="inline" size="small" /> {trans("progress.loading")}
          </p>
        )}

        {loaded && !hasSource && <p className="MmMuted">{trans("progress.no_source")}</p>}

        <div className="MmProgress-rows">
          {ROWS.map((row) => this.row(row, source, target, hasSource))}
        </div>
      </div>
    );
  }

  private row(
    row: { key: string; source: string; target: string },
    source: Record<string, number>,
    target: Record<string, number>,
    hasSource: boolean,
  ): Mithril.Children {
    const src = source[row.source];
    const tgt = target[row.target];
    const tgtKnown = typeof tgt === "number" && tgt >= 0;
    const pct =
      hasSource && typeof src === "number" && src > 0 && tgtKnown
        ? Math.min(100, Math.round((tgt / src) * 100))
        : 0;

    return (
      <div className="MmProgress-row">
        <div className="MmProgress-label">{trans(`progress.entity.${row.key}`)}</div>
        <div className="MmProgress-bar">
          <div className="MmProgress-fill" style={{ width: `${pct}%` }} />
        </div>
        <div className="MmProgress-num">
          {tgtKnown ? tgt.toLocaleString() : "—"}
          {hasSource && typeof src === "number" ? (
            <span className="MmMuted"> / {src.toLocaleString()}</span>
          ) : null}
        </div>
      </div>
    );
  }
}
