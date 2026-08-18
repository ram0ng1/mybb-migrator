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
import ImagesCard from "./ImagesCard";
import LiveConsole from "./LiveConsole";
import ProgressBar from "./ProgressBar";
import ProgressOverview from "./ProgressOverview";
import StepList from "./StepList";

type Tab = "connection" | "migrate" | "images" | "compare" | "cleanup" | "diag";

const TABS: Array<{ id: Tab; icon: string }> = [
  { id: "connection", icon: "fas fa-plug" },
  { id: "migrate", icon: "fas fa-play" },
  { id: "images", icon: "fas fa-image" },
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
    // Encerra o polling: um timer sobrevivente redesenharia sobre uma árvore que
    // o Mithril já não controla.
    this.migrator.dispose();
  }

  /**
   * A árvore tem SEMPRE a mesma forma: um container de banner (vazio quando nada
   * roda), a barra de abas e o corpo.
   *
   * Isso não é estilo — é correção. Antes o banner era o primeiro de três irmãos
   * sem `key`, alternando entre `null` e elemento a cada polling; nessa troca o
   * Mithril inseria um nó novo em vez de atualizar o existente, e o antigo ficava
   * órfão no DOM, congelado no último progresso que tinha (era a "duplicação":
   * um card a cada ciclo, parados em 8, 9, 10...). Com um container fixo, o que
   * muda é só o filho DELE, que o Mithril substitui no lugar. Pelo mesmo motivo o
   * estado de carregamento agora vive DENTRO do corpo, em vez de trocar a árvore
   * inteira por um spinner.
   */
  view(): Mithril.Children {
    const booting = this.migrator.loading && !this.migrator.status;

    return (
      <div className="MmDashboard">
        <div className="MmDashboard-banner">{this.runningBanner()}</div>
        {this.tabBar()}
        <div className="MmDashboard-body">
          {booting ? <LoadingIndicator /> : this.tabContent()}
        </div>
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
      case "images":
        return this.imagesTab();
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
        {this.migrator.status && <ProgressOverview state={this.migrator} />}

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

  /**
   * Mídia: configuração (quais URLs internalizar, orçamento) + os dois passos.
   * Fora das sequências guiadas de propósito — baixar arquivos consome banda e
   * disco, então é sempre uma decisão explícita.
   */
  private imagesTab(): Mithril.Children {
    return (
      <div>
        <ImagesCard state={this.migrator} />
        <div className="MmAlert MmAlert--warn">{trans("images.warning")}</div>
        <StepList state={this.migrator} phases={["media"] as Phase[]} />
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
        <ProgressBar progress={this.migrator.stepStatus(running)?.progress ?? null} />
        <LiveConsole text={this.migrator.status?.runningLog ?? ""} follow={true} />
      </div>
    );
  }
}
