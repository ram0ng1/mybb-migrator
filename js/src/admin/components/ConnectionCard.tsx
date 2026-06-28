import app from "flarum/admin/app";
import Component, { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import extractText from "flarum/common/utils/extractText";
import type Mithril from "mithril";

import type MigratorState from "../states/MigratorState";
import type { ConnectionPayload, TestResult } from "../types";

interface Attrs extends ComponentAttrs {
  state: MigratorState;
}

const trans = (key: string, args: Record<string, unknown> = {}): string =>
  extractText(app.translator.trans(`ramon-mybb-migrator.admin.${key}`, args));

/** Configura a conexão MyBB + caminho do PHP CLI, testa e mostra a pré-checagem. */
export default class ConnectionCard extends Component<Attrs> {
  private form: ConnectionPayload = {};
  private seeded = false;
  private testResult: TestResult | null = null;

  view(): Mithril.Children {
    const { state } = this.attrs;
    const status = state.status;
    if (!status) return null;

    this.seed();
    const conn = status.connection;

    return (
      <div className="MmCard MmConnection">
        <h4>{trans("connection.title")}</h4>
        <p className="MmMuted">{trans("connection.intro")}</p>

        <div className="MmGrid">
          {this.field("host", "text")}
          {this.field("port", "number")}
          {this.field("user", "text")}
          {this.passwordField(conn.password_set)}
          {this.field("db", "text")}
          {this.field("prefix", "text")}
        </div>

        <div className="MmField MmField--wide">
          <label>{trans("connection.php_binary")}</label>
          <input
            className="FormControl"
            type="text"
            placeholder={conn.php_resolved || trans("connection.php_auto")}
            value={this.form.php_binary ?? ""}
            oninput={(e: InputEvent) =>
              (this.form.php_binary = (e.target as HTMLInputElement).value)
            }
          />
          {this.phpHint(conn)}
        </div>

        <div className="MmField MmField--wide">
          <label>{trans("connection.old_site_url")}</label>
          <input
            className="FormControl"
            type="text"
            placeholder="https://exemplo.com"
            title={trans("connection.old_site_url")}
            value={this.form.old_site_url ?? ""}
            oninput={(e: InputEvent) =>
              (this.form.old_site_url = (e.target as HTMLInputElement).value)
            }
          />
          <div className="MmHint">{trans("connection.old_site_hint")}</div>
        </div>

        <div className="MmActions">
          <Button
            className="Button"
            loading={state.busy}
            onclick={() => this.onTest()}
          >
            {trans("connection.test")}
          </Button>
          <Button
            className="Button Button--primary"
            loading={state.busy}
            onclick={() => this.onSave()}
          >
            {trans("connection.save")}
          </Button>
        </div>

        {this.testResultView()}
        {this.preflightView()}
      </div>
    );
  }

  private seed(): void {
    if (this.seeded) return;
    const conn = this.attrs.state.status?.connection;
    if (!conn) return;
    this.form = {
      host: conn.host,
      port: conn.port,
      user: conn.user,
      db: conn.db,
      prefix: conn.prefix,
      php_binary: conn.php_binary,
      old_site_url: conn.old_site_url,
    };
    this.seeded = true;
  }

  private field(name: keyof ConnectionPayload, type: string): Mithril.Children {
    return (
      <div className="MmField">
        <label>{trans(`connection.fields.${name}`)}</label>
        <input
          className="FormControl"
          type={type}
          title={trans(`connection.fields.${name}`)}
          value={(this.form[name] as string | number | undefined) ?? ""}
          oninput={(e: InputEvent) =>
            ((this.form[name] as unknown) = (e.target as HTMLInputElement).value)
          }
        />
      </div>
    );
  }

  private passwordField(isSet: boolean): Mithril.Children {
    return (
      <div className="MmField">
        <label>{trans("connection.fields.password")}</label>
        <input
          className="FormControl"
          type="password"
          placeholder={
            isSet ? trans("connection.password_set") : trans("connection.password_empty")
          }
          value={this.form.password ?? ""}
          oninput={(e: InputEvent) =>
            (this.form.password = (e.target as HTMLInputElement).value)
          }
        />
      </div>
    );
  }

  private phpHint(conn: {
    php_resolved: string;
    php_autodetected: boolean;
  }): Mithril.Children {
    if (!conn.php_resolved) {
      return <div className="MmHint MmHint--error">{trans("connection.php_none")}</div>;
    }
    const tested = this.testResult?.php;
    const cls = tested ? (tested.ok ? "MmHint--ok" : "MmHint--error") : "";
    const source = conn.php_autodetected
      ? trans("connection.php_from_flarum")
      : trans("connection.php_override");
    return (
      <div className={`MmHint ${cls}`}>
        {tested ? (tested.ok ? "✓ " : "✗ ") : ""}
        {conn.php_resolved}
        {tested?.version ? ` (PHP ${tested.version})` : ""}
        {` — ${source}`}
      </div>
    );
  }

  private testResultView(): Mithril.Children {
    const r = this.testResult;
    if (!r) return null;

    if (!r.ok) {
      return (
        <div className="MmAlert MmAlert--error">
          {trans("connection.test_fail")}: {r.error}
        </div>
      );
    }

    return (
      <div className="MmAlert MmAlert--ok">
        {trans("connection.test_ok")}
        <span className="MmChips">
          {Object.entries(r.counts).map(([k, v]) => (
            <span className="MmChip">
              {k}: {v.toLocaleString()}
            </span>
          ))}
        </span>
      </div>
    );
  }

  private preflightView(): Mithril.Children {
    const pf = this.attrs.state.status?.preflight;
    if (!pf) return null;

    return (
      <div className="MmPreflight">
        <h5>{trans("preflight.title")}</h5>
        <ul className="MmCheckList">
          {this.check(pf.legacy_table, trans("preflight.legacy_table"))}
          {this.check(pf.steps_table, trans("preflight.steps_table"))}
        </ul>
        <h5>{trans("preflight.extensions")}</h5>
        <ul className="MmCheckList MmCheckList--exts">
          {pf.extensions.map((ext) =>
            this.check(
              ext.enabled,
              ext.id + (ext.required ? ` (${trans("preflight.required")})` : ""),
              ext.required && !ext.enabled,
            ),
          )}
        </ul>
      </div>
    );
  }

  private check(ok: boolean, label: string, danger = false): Mithril.Children {
    const cls = ok ? "is-ok" : danger ? "is-danger" : "is-off";
    return (
      <li className={`MmCheck ${cls}`}>
        <span className="MmCheck-icon">{ok ? "✓" : "✗"}</span>
        {label}
      </li>
    );
  }

  private async onTest(): Promise<void> {
    this.testResult = await this.attrs.state.test(this.form);
    m.redraw();
  }

  private async onSave(): Promise<void> {
    await this.attrs.state.saveConnection(this.form);
    // não reenviar a senha em saves seguintes
    this.form.password = "";
    app.alerts.show({ type: "success" }, trans("connection.saved"));
  }
}
