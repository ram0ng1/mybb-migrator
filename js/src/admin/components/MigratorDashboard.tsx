import app from "flarum/admin/app";
import Component, { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import extractText from "flarum/common/utils/extractText";
import type Mithril from "mithril";

import MigratorState from "../states/MigratorState";
import type { Phase } from "../types";
import ComparePanel from "./ComparePanel";
import ConnectionCard from "./ConnectionCard";
import LiveConsole from "./LiveConsole";
import ProgressOverview from "./ProgressOverview";
import StepList from "./StepList";

type Tab = "connection" | "migrate" | "compare" | "cleanup" | "diag";

const TABS: Array<{ id: Tab; icon: string }> = [
  { id: "connection", icon: "fas fa-plug" },
  { id: "migrate", icon: "fas fa-play" },
  { id: "compare", icon: "fas fa-columns" },
  { id: "cleanup", icon: "fas fa-broom" },
  { id: "diag", icon: "fas fa-stethoscope" },
];

const trans = (key: string, args: Record<string, unknown> = {}): string =>
  extractText(app.translator.trans(`ramon-mybb-migrator.admin.${key}`, args));

/** Console de migração embutido na página da extensão (estilo Tallyst). */
export default class MigratorDashboard extends Component<ComponentAttrs> {
  private migrator!: MigratorState;
  private tab: Tab = "connection";

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>): void {
    super.oninit(vnode);
    this.migrator = new MigratorState();
    this.migrator.start();
  }

  onremove(): void {
    this.migrator.dispose();
  }

  view(): Mithril.Children {
    if (this.migrator.loading && !this.migrator.status) {
      return (
        <div className="MmDashboard">
          <LoadingIndicator />
        </div>
      );
    }

    return (
      <div className="MmDashboard">
        {this.runningBanner()}
        {this.tabBar()}
        <div className="MmDashboard-body">{this.tabContent()}</div>
      </div>
    );
  }

  private tabBar(): Mithril.Children {
    return (
      <nav className="MmTabs">
        {TABS.map((t) => (
          <button
            type="button"
            className={`MmTab ${this.tab === t.id ? "is-active" : ""}`}
            onclick={() => (this.tab = t.id)}
          >
            <i className={t.icon} /> {trans(`tabs.${t.id}`)}
          </button>
        ))}
      </nav>
    );
  }

  private tabContent(): Mithril.Children {
    switch (this.tab) {
      case "connection":
        return <ConnectionCard state={this.migrator} />;
      case "migrate":
        return this.migrateTab();
      case "compare":
        return <ComparePanel state={this.migrator} />;
      case "cleanup":
        return this.cleanupTab();
      case "diag":
        return <StepList state={this.migrator} phases={["diag"] as Phase[]} />;
    }
  }

  private migrateTab(): Mithril.Children {
    const running = this.migrator.isRunning();
    return (
      <div>
        {this.migrator.status && <ProgressOverview status={this.migrator.status} />}

        <div className="MmCard MmGuide">
          <h4>{trans("run.title")}</h4>
          <p className="MmMuted">{trans("run.intro")}</p>
          <div className="MmActions">
            <Button
              className="Button Button--primary"
              disabled={running}
              onclick={() => this.migrator.run({ sequence: "all" })}
            >
              {trans("run.all")}
            </Button>
          </div>
        </div>

        {/* Fase 0 — preparação (opcional, destrutiva) */}
        {this.phaseSection("0", null)}

        {/* Fase 1 — núcleo (ordem importa) */}
        {this.phaseSection("1", "phase1")}

        {/* Fase 2 — secundário */}
        {this.phaseSection("2", "phase2")}
      </div>
    );
  }

  /** Seção de uma fase: cabeçalho + descrição + botão "rodar fase" + passos numerados. */
  private phaseSection(
    phase: Phase,
    sequence: "phase1" | "phase2" | null,
  ): Mithril.Children {
    const running = this.migrator.isRunning();
    return (
      <div className="MmPhase">
        <div className="MmPhase-head">
          <div>
            <h4 className="MmPhase-title">{trans(`phases.${phase}.title`)}</h4>
            <p className="MmPhase-desc">{trans(`phases.${phase}.desc`)}</p>
          </div>
          {sequence && (
            <Button
              className="Button Button--primary Button--sm"
              disabled={running}
              onclick={() => this.migrator.run({ sequence })}
            >
              {trans(`run.${sequence}`)}
            </Button>
          )}
        </div>
        <StepList state={this.migrator} phases={[phase]} numbered={phase !== "0"} />
      </div>
    );
  }

  private cleanupTab(): Mithril.Children {
    return (
      <div>
        <div className="MmAlert MmAlert--warn">{trans("cleanup.warning")}</div>
        <StepList state={this.migrator} phases={["3"] as Phase[]} />
      </div>
    );
  }

  private runningBanner(): Mithril.Children {
    const running = this.migrator.status?.running;
    if (!running) return null;

    return (
      <div className="MmRunning">
        <div className="MmRunning-head">
          <span className="MmSpinner" />
          <strong>{trans("run.running", { step: running })}</strong>
          <Button
            className="Button Button--sm"
            loading={this.migrator.busy}
            onclick={() => this.migrator.cancel("cancel")}
          >
            {trans("run.cancel")}
          </Button>
        </div>
        <LiveConsole text={this.migrator.status?.runningLog ?? ""} follow={true} />
      </div>
    );
  }
}
