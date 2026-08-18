import app from "flarum/admin/app";
import Component, { ComponentAttrs } from "flarum/common/Component";
import extractText from "flarum/common/utils/extractText";
import type Mithril from "mithril";

import type { StepProgress } from "../types";

interface Attrs extends ComponentAttrs {
  progress: StepProgress | null;
}

const trans = (key: string, args: Record<string, unknown> = {}): string =>
  extractText(app.translator.trans(`ramon-mybb-migrator.admin.${key}`, args));

/**
 * Barra de progresso de um passo em execução.
 *
 * Com `total` conhecido vira percentual; sem ele, uma barra INDETERMINADA (faixa
 * deslizante) com a contagem absoluta. Essa distinção é proposital: numa
 * varredura completa o total só sairia de um COUNT que custa mais que o próprio
 * trabalho, e uma porcentagem chutada é pior que nenhuma.
 */
export default class ProgressBar extends Component<Attrs> {
  view(): Mithril.Children {
    const p = this.attrs.progress;
    if (!p) return null;

    const known = typeof p.total === "number" && p.total > 0;
    const pct = known ? Math.min(100, Math.round((p.done / (p.total as number)) * 100)) : 0;

    return (
      <div className={`MmBar ${known ? "" : "MmBar--indeterminate"}`}>
        <div className="MmBar-track">
          <div className="MmBar-fill" style={known ? { width: `${pct}%` } : undefined} />
        </div>
        <div className="MmBar-text">
          {known
            ? trans("progress.of_total", {
                done: p.done.toLocaleString(),
                total: (p.total as number).toLocaleString(),
                pct,
              })
            : trans("progress.scanned", { done: p.done.toLocaleString() })}
          {p.label ? <span className="MmMuted"> — {p.label}</span> : null}
        </div>
      </div>
    );
  }
}
