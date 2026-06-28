import Component, { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";

interface Attrs extends ComponentAttrs {
  text: string;
  /** Auto-scroll para o fim quando em execução. */
  follow?: boolean;
}

/** Caixa de console monoespaçada para o tail do log de um passo. */
export default class LiveConsole extends Component<Attrs> {
  view(): Mithril.Children {
    const text = (this.attrs.text || "").trim();
    return (
      <pre
        className="MmConsole"
        onupdate={(vnode: Mithril.VnodeDOM<Attrs, this>) =>
          this.maybeScroll(vnode)
        }
        oncreate={(vnode: Mithril.VnodeDOM<Attrs, this>) =>
          this.maybeScroll(vnode)
        }
      >
        {text || "—"}
      </pre>
    );
  }

  private maybeScroll(vnode: Mithril.VnodeDOM<Attrs, this>): void {
    if (this.attrs.follow === false) return;
    const el = vnode.dom as HTMLElement;
    el.scrollTop = el.scrollHeight;
  }
}
