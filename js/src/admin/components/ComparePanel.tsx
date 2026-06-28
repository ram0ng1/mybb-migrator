import app from "flarum/admin/app";
import Component, { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import extractText from "flarum/common/utils/extractText";
import type Mithril from "mithril";

import type MigratorState from "../states/MigratorState";
import type { CompareResult } from "../types";

interface Attrs extends ComponentAttrs {
  state: MigratorState;
}

const trans = (key: string, args: Record<string, unknown> = {}): string =>
  extractText(app.translator.trans(`ramon-mybb-migrator.admin.${key}`, args));

/**
 * Comparação de fidelidade: post no MyBB antigo × no Flarum.
 *
 * O lado antigo não pode ser embutido (o site usa X-Frame-Options e/ou está
 * atrás de Cloudflare, que bloqueia leitura pelo servidor). Então mostramos um
 * link para abrir o post original em nova aba + o BBCode de origem, ao lado do
 * HTML renderizado pelo Flarum.
 */
export default class ComparePanel extends Component<Attrs> {
  private pid = "";
  private result: CompareResult | null = null;
  private attempted = false;
  private loading = false;

  view(): Mithril.Children {
    return (
      <div className="MmCard MmCompare">
        <h4>{trans("compare.title")}</h4>
        <p className="MmMuted">{trans("compare.intro")}</p>

        <div className="MmCompare-bar">
          <input
            className="FormControl"
            type="number"
            placeholder={trans("compare.pid_placeholder")}
            title={trans("compare.pid_placeholder")}
            value={this.pid}
            oninput={(e: InputEvent) => (this.pid = (e.target as HTMLInputElement).value)}
            onkeydown={(e: KeyboardEvent) => {
              if (e.key === "Enter") this.run(false);
            }}
          />
          <Button
            className="Button Button--primary"
            loading={this.loading}
            disabled={!this.pid}
            onclick={() => this.run(false)}
          >
            {trans("compare.compare")}
          </Button>
          <Button className="Button" loading={this.loading} onclick={() => this.run(true)}>
            {trans("compare.random")}
          </Button>
        </div>

        {this.result
          ? this.resultView(this.result)
          : this.attempted && !this.loading
            ? <div className="MmAlert MmAlert--warn">{trans("compare.none_found")}</div>
            : null}
      </div>
    );
  }

  private resultView(r: CompareResult): Mithril.Children {
    return (
      <div className="MmCompare-result">
        <div className="MmCompare-meta">
          <strong>pid {r.pid}</strong>
          {r.number ? <span className="MmMuted"> · #{r.number}</span> : null}
          {r.title ? <span className="MmCompare-title"> · {r.title}</span> : null}
        </div>

        <div className="MmCompare-cols">
          {this.oldCol(r)}
          {this.flarumCol(r)}
        </div>
      </div>
    );
  }

  private oldCol(r: CompareResult): Mithril.Children {
    return (
      <div className="MmCompare-col">
        <div className="MmCompare-colhead">
          <span>{trans("compare.old")}</span>
          {r.old_url ? (
            <a href={r.old_url} target="_blank" rel="noopener noreferrer" className="MmCompare-open">
              {trans("compare.open_old")} ↗
            </a>
          ) : null}
        </div>

        <div className="MmCompare-body">
          {this.oldRender(r)}

          {r.mybb_bbcode != null ? (
            <details className="MmCompare-bbcode">
              <summary>{trans("compare.bbcode_label")}</summary>
              <pre className="MmConsole MmConsole--light">{r.mybb_bbcode}</pre>
            </details>
          ) : (
            <div className="MmMuted">{trans("compare.no_mybb")}</div>
          )}
        </div>
      </div>
    );
  }

  /** Render do lado antigo: preferimos o HTML do banco (BBCode); senão o raspado. */
  private oldRender(r: CompareResult): Mithril.Children {
    const usingDb = !!r.mybb_html;
    const rendered = r.mybb_html || r.old_html;

    if (rendered) {
      return (
        <div>
          <div className="MmCompare-srclabel">
            {usingDb ? trans("compare.from_db") : trans("compare.from_site")}
          </div>
          <iframe
            className="MmCompare-frame"
            title={trans("compare.old")}
            sandbox=""
            srcdoc={this.srcdoc(r.old_site, rendered)}
          />
        </div>
      );
    }

    if (r.old_url) {
      return (
        <div className="MmCompare-cant">
          <p className="MmMuted">{trans("compare.cant_embed")}</p>
          <a
            href={r.old_url}
            target="_blank"
            rel="noopener noreferrer"
            className="Button Button--primary Button--sm"
          >
            {trans("compare.open_old")} ↗
          </a>
        </div>
      );
    }

    return <div className="MmAlert MmAlert--warn">{trans("compare.old_unavailable")}</div>;
  }

  private flarumCol(r: CompareResult): Mithril.Children {
    return (
      <div className="MmCompare-col">
        <div className="MmCompare-colhead">
          <span>{trans("compare.flarum")}</span>
        </div>
        <div className="MmCompare-body">
          {r.found_flarum && r.flarum_html ? (
            <div className="MmCompare-flarum">{m.trust(r.flarum_html)}</div>
          ) : (
            <div className="MmAlert MmAlert--warn">{trans("compare.not_migrated")}</div>
          )}
        </div>
      </div>
    );
  }

  /** Documento isolado para o iframe quando o HTML do post vem do servidor. */
  private srcdoc(base: string, html: string): string {
    const b = (base || "").replace(/\/+$/, "");
    return (
      `<!doctype html><html><head><meta charset="utf-8">` +
      (b ? `<base href="${b}/">` : "") +
      `<style>body{font:14px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;margin:8px;color:#111;background:#fff}` +
      `img{max-width:100%;height:auto}blockquote{border-left:3px solid #ccc;margin:8px 0;padding:4px 10px;color:#555}` +
      `a{color:#1d4ed8}</style></head><body>${html}</body></html>`
    );
  }

  private async run(random: boolean): Promise<void> {
    this.loading = true;
    this.attempted = true;
    const res = await this.attrs.state.compare(
      random ? { random: true } : { pid: parseInt(this.pid, 10) },
    );
    this.loading = false;
    this.result = res;
    if (res && random) this.pid = String(res.pid);
    m.redraw();
  }
}
