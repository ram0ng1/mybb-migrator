import app from "flarum/admin/app";
import Component, { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import extractText from "flarum/common/utils/extractText";
import type Mithril from "mithril";

import type MigratorState from "../states/MigratorState";
import type {
  ConnectionPayload,
  DetectResult,
  MediaConfig,
  MediaStats,
} from "../types";

interface Attrs extends ComponentAttrs {
  state: MigratorState;
}

const trans = (key: string, args: Record<string, unknown> = {}): string =>
  extractText(app.translator.trans(`ramon-mybb-migrator.admin.${key}`, args));

/**
 * Configuração da internalização de mídia + o atalho "só esta discussão".
 *
 * Nada aqui precisa ser digitado no primeiro uso: quando as duas configurações
 * chegam vazias, a autodetecção roda sozinha uma vez — os hosts saem do ranking
 * das imagens dos posts já migrados, e a pasta de uploads é procurada perto da
 * instalação e VALIDADA contra anexos reais.
 */
export default class ImagesCard extends Component<Attrs> {
  private form: ConnectionPayload = {};
  private seeded = false;
  private detection: DetectResult | null = null;
  private discussion = "";
  private showAllHosts = false;

  /**
   * A autodetecção inicial roda AQUI, não no view().
   *
   * Disparar um POST e mexer no estado compartilhado durante a renderização é o
   * caminho curto para o Mithril redesenhar sobre uma árvore que ainda está
   * sendo montada. `oninit` roda uma vez, no momento certo.
   */
  oninit(vnode: Mithril.Vnode<Attrs, this>): void {
    super.oninit(vnode);
    this.maybeAutoDetect();
  }

  view(): Mithril.Children {
    const media = this.attrs.state.status?.media;
    if (!media) return null;

    this.seed(media);

    const hostCount = (this.form.image_hosts ?? "")
      .split(/[\s,;]+/)
      .filter((h) => h !== "").length;

    return (
      <div className="MmCard MmImages">
        <h4>{trans("images.title")}</h4>
        <p className="MmMuted">{trans("images.intro")}</p>

        {this.uploadWarning(media)}
        {this.discussionRow()}

        <div className="MmField MmField--wide">
          <label>
            {trans("images.hosts")}
            {hostCount > 0 && (
              <span className="MmMuted"> — {trans("images.hosts_count", { count: hostCount })}</span>
            )}
          </label>
          {/* textarea, não input: a lista detectada passa de 400 hosts e uma
              linha só tornaria impossível conferir ou podar o que veio. */}
          <textarea
            className="FormControl MmHostsInput"
            rows={4}
            placeholder="i.imgur.com, damnfineshave.com"
            title={trans("images.hosts")}
            value={this.form.image_hosts ?? ""}
            oninput={(e: InputEvent) =>
              (this.form.image_hosts = (e.target as HTMLTextAreaElement).value)
            }
          />
          <div className="MmHint">{trans("images.hosts_hint")}</div>
        </div>

        <div className="MmGrid">
          {this.numberField("image_limit", "images.limit")}
          {this.numberField("image_max_mb", "images.max_mb")}
          {this.numberField("image_max_file_mb", "images.max_file_mb")}
        </div>

        {this.optimizationBlock(media)}

        <div className="MmField MmField--wide">
          <label>{trans("images.attachments_dir")}</label>
          <input
            className="FormControl"
            type="text"
            placeholder="C:\\mybb\\uploads"
            title={trans("images.attachments_dir")}
            value={this.form.attachments_dir ?? ""}
            oninput={(e: InputEvent) =>
              (this.form.attachments_dir = (e.target as HTMLInputElement).value)
            }
          />
          <div className="MmHint">{trans("images.attachments_dir_hint")}</div>
        </div>

        <div className="MmActions">
          <Button
            className="Button Button--primary"
            loading={this.attrs.state.busy}
            onclick={() => this.onSave()}
          >
            {trans("images.save")}
          </Button>
          <Button
            className="Button"
            loading={this.attrs.state.busy}
            onclick={() => this.onDetect()}
          >
            {trans("images.detect")}
          </Button>
        </div>

        {this.detectionView()}

        <div className="MmHint">
          {trans("images.directory")}: <code>{media.directory}</code>
        </div>

        {this.statsView(this.attrs.state.status?.mediaStats)}
      </div>
    );
  }

  /**
   * Migrar as imagens de UMA discussão: cola-se a URL da barra do navegador. É o
   * teste mais barato possível — dá para olhar o resultado no fórum antes de
   * soltar a varredura completa.
   */
  private discussionRow(): Mithril.Children {
    const running = this.attrs.state.isRunning();

    return (
      <div className="MmField MmField--wide MmDiscussion">
        <label>{trans("images.discussion")}</label>
        <div className="MmDiscussion-row">
          <input
            className="FormControl"
            type="text"
            placeholder="https://exemplo.com/d/1661-titulo-da-discussao"
            title={trans("images.discussion")}
            value={this.discussion}
            oninput={(e: InputEvent) =>
              (this.discussion = (e.target as HTMLInputElement).value)
            }
          />
          <Button
            className="Button Button--primary"
            disabled={running || this.discussion.trim() === ""}
            onclick={() => this.onRunDiscussion()}
          >
            {trans("images.discussion_run")}
          </Button>
        </div>
        <div className="MmHint">{trans("images.discussion_hint")}</div>
      </div>
    );
  }

  /**
   * Otimização na entrada + ritmo de rede.
   *
   * Os dois grupos moram juntos de propósito: são as duas decisões que só têm
   * efeito NA HORA de baixar. Depois que a migração passou, mudar qualquer um
   * deles exige rebaixar as imagens e reescrever os posts.
   */
  private optimizationBlock(media: MediaConfig): Mithril.Children {
    return (
      <div className="MmField MmField--wide">
        <label>{trans("images.optimize")}</label>
        <div className="MmHint">{trans("images.optimize_hint")}</div>

        <div className="MmOpts">
          {this.boolField("image_optimize", "images.optimize_on")}
          {this.boolField("image_webp", "images.webp")}
        </div>

        {!media.webp_supported && (
          <div className="MmAlert MmAlert--warn">{trans("images.no_webp_support")}</div>
        )}

        <div className="MmGrid">
          {this.numberField("image_quality", "images.quality")}
          {this.numberField("image_max_dim", "images.max_dim")}
          {this.numberField("image_host_delay", "images.host_delay")}
          {this.numberField("image_retries", "images.retries")}
        </div>
        <div className="MmHint">{trans("images.host_delay_hint")}</div>

        {this.exitIpsField()}
      </div>
    );
  }

  /**
   * Os IPs por onde os downloads saem.
   *
   * Mora junto do ritmo de rede porque resolve o MESMO problema por outro lado:
   * o intervalo por host faz o run esperar a cota do imgur; vários IPs dão
   * várias cotas. Textarea, e não input: são endereços longos (proxy com
   * usuário e senha passa fácil de 40 caracteres) e conferir a lista numa linha
   * só é impossível.
   */
  private exitIpsField(): Mithril.Children {
    const exits = (this.form.image_exit_ips ?? "")
      .split(/[\s,;]+/)
      .filter((ip) => ip !== "").length;

    return (
      <div className="MmField MmField--wide">
        <label>
          {trans("images.exit_ips")}
          {exits > 0 && (
            <span className="MmMuted"> — {trans("images.hosts_count", { count: exits })}</span>
          )}
        </label>
        <textarea
          className="FormControl MmHostsInput"
          rows={3}
          placeholder={"203.0.113.9\nsocks5://user:pass@proxy.exemplo:1080"}
          title={trans("images.exit_ips")}
          value={this.form.image_exit_ips ?? ""}
          oninput={(e: InputEvent) =>
            (this.form.image_exit_ips = (e.target as HTMLTextAreaElement).value)
          }
        />
        <div className="MmHint">{trans("images.exit_ips_hint")}</div>
      </div>
    );
  }

  private seed(media: MediaConfig): void {
    if (this.seeded) return;
    this.form = {
      image_hosts: media.image_hosts,
      image_limit: media.image_limit,
      image_max_mb: media.image_max_mb,
      image_max_file_mb: media.image_max_file_mb,
      image_optimize: media.image_optimize,
      image_webp: media.image_webp,
      image_quality: media.image_quality,
      image_max_dim: media.image_max_dim,
      image_host_delay: media.image_host_delay,
      image_retries: media.image_retries,
      image_exit_ips: media.image_exit_ips,
      attachments_dir: media.attachments_dir,
    };
    this.seeded = true;
  }

  /**
   * Primeira abertura com tudo vazio: detecta sozinho, sem pedir nada. Se o
   * status ainda não chegou, espera-o — sem ficar sondando a cada render.
   */
  private maybeAutoDetect(): void {
    const media = this.attrs.state.status?.media;

    if (!media) {
      void this.attrs.state.whenReady().then(() => this.maybeAutoDetect());

      return;
    }

    if (media.image_hosts !== "" && media.attachments_dir !== "") return;

    void this.onDetect();
  }

  private boolField(
    name: "image_optimize" | "image_webp",
    labelKey: string
  ): Mithril.Children {
    return (
      <label className="MmOpt">
        <input
          type="checkbox"
          checked={this.form[name] !== false}
          onchange={(e: Event) =>
            (this.form[name] = (e.target as HTMLInputElement).checked)
          }
        />
        {trans(labelKey)}
      </label>
    );
  }

  private numberField(name: keyof ConnectionPayload, labelKey: string): Mithril.Children {
    return (
      <div className="MmField">
        <label>{trans(labelKey)}</label>
        <input
          className="FormControl"
          type="number"
          min="0"
          title={trans(labelKey)}
          value={(this.form[name] as string | number | undefined) ?? ""}
          oninput={(e: InputEvent) =>
            ((this.form[name] as unknown) = (e.target as HTMLInputElement).value)
          }
        />
      </div>
    );
  }

  /**
   * Sem fof/upload os arquivos ainda são baixados e apontados (o post passa a
   * carregar a imagem local) — só não aparecem no gerenciador de mídia. Vale
   * como aviso, não como bloqueio.
   */
  private uploadWarning(media: MediaConfig): Mithril.Children {
    if (!media.map_table) {
      return (
        <div className="MmAlert MmAlert--error">{trans("images.no_map_table")}</div>
      );
    }
    if (media.upload_table) return null;

    return <div className="MmAlert MmAlert--warn">{trans("images.no_fof_upload")}</div>;
  }

  /**
   * Ranking detectado. Todos os hosts entram no filtro, então os chips servem
   * para VER de onde vêm as imagens (e podar à mão o que não interessar), não
   * para descobrir o que foi cortado.
   */
  private detectionView(): Mithril.Children {
    const d = this.detection;
    if (!d) return null;

    const shown = this.showAllHosts ? d.hosts.ranking : d.hosts.ranking.slice(0, 24);
    const hidden = d.hosts.total_hosts - shown.length;

    return (
      <div className="MmDetect">
        <div className="MmDetect-head">
          {trans("images.detect_result", {
            hosts: d.hosts.applied.length,
            images: d.hosts.total_images.toLocaleString(),
            scanned: d.hosts.scanned.toLocaleString(),
          })}
          {d.hosts.truncated ? ` ${trans("images.detect_sample")}` : ""}
        </div>
        <div className="MmChips">
          {shown.map((entry) => (
            <span className="MmChip MmChip--on">
              {entry.host}: {entry.count}
            </span>
          ))}
        </div>
        {hidden > 0 && (
          <Button
            className="Button Button--text Button--sm"
            onclick={() => (this.showAllHosts = true)}
          >
            {trans("images.detect_show_all", { count: hidden })}
          </Button>
        )}
        <div className="MmHint">
          {d.uploads.path
            ? `${trans("images.detect_uploads_ok")}: ${d.uploads.path}`
            : trans("images.detect_uploads_none")}
        </div>
      </div>
    );
  }

  private statsView(stats?: MediaStats): Mithril.Children {
    if (!stats) return null;

    const mb = (stats.bytes / 1048576).toFixed(1);

    return (
      <div className="MmChips MmChips--summary">
        <span className="MmChip">
          {trans("images.stats.images_ok")}: {stats.images_ok.toLocaleString()}
        </span>
        <span className="MmChip">
          {trans("images.stats.images_failed")}: {stats.images_failed.toLocaleString()}
        </span>
        {/* Adiadas só aparecem quando existem: um contador zerado de "volta
            sozinha" é ruído. */}
        {(stats.images_deferred > 0 || stats.attachments_deferred > 0) && (
          <span className="MmChip">
            {trans("images.stats.deferred")}:{" "}
            {(stats.images_deferred + stats.attachments_deferred).toLocaleString()}
          </span>
        )}
        <span className="MmChip">
          {trans("images.stats.attachments_ok")}: {stats.attachments_ok.toLocaleString()}
        </span>
        <span className="MmChip">
          {trans("images.stats.attachments_failed")}:{" "}
          {stats.attachments_failed.toLocaleString()}
        </span>
        <span className="MmChip">{trans("images.stats.disk")}: {mb} MB</span>
      </div>
    );
  }

  private async onSave(): Promise<void> {
    await this.attrs.state.saveConnection(this.form);
    // Recarrega o que o servidor normalizou (ex.: URL completa vira só o host).
    this.seeded = false;
    app.alerts.show({ type: "success" }, trans("images.saved"));
  }

  private async onDetect(): Promise<void> {
    this.showAllHosts = false;
    this.detection = await this.attrs.state.detectMedia();
    // Os campos passam a refletir o que foi detectado e gravado.
    this.seeded = false;
    m.redraw();
  }

  private onRunDiscussion(): void {
    const discussion = this.discussion.trim();
    if (discussion === "") return;

    void this.attrs.state.run({
      step: "images",
      extra: { images: { discussion } },
    });
  }
}
