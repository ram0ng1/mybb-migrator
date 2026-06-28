import app from "flarum/admin/app";
import Component, { ComponentAttrs } from "flarum/common/Component";
import extractText from "flarum/common/utils/extractText";
import type Mithril from "mithril";

import type { MigratorStatus } from "../types";

interface Attrs extends ComponentAttrs {
  status: MigratorStatus;
}

const trans = (key: string): string =>
  extractText(app.translator.trans(`ramon-mybb-migrator.admin.${key}`));

/** Mapeia entidade -> (contagem origem MyBB, contagem destino Flarum). */
const ROWS: Array<{ key: string; source: string; target: string }> = [
  { key: "users", source: "users", target: "users" },
  { key: "discussions", source: "threads", target: "discussions" },
  { key: "posts", source: "posts", target: "posts" },
  { key: "tags", source: "forums", target: "tags" },
];

/** Barras comparando origem (MyBB) x destino (Flarum) por entidade. */
export default class ProgressOverview extends Component<Attrs> {
  view(): Mithril.Children {
    const { status } = this.attrs;
    const source = status.source?.counts ?? {};
    const target = status.target ?? {};
    const hasSource = !!status.source?.ok;

    return (
      <div className="MmCard MmProgress">
        <h4>{trans("progress.title")}</h4>
        {!hasSource && (
          <p className="MmMuted">{trans("progress.no_source")}</p>
        )}
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
