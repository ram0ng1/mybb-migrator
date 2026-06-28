import app from "flarum/common/app";
import extractText from "flarum/common/utils/extractText";
import type { FlarumRequestOptions } from "flarum/common/Application";

interface ApiCallOptions {
  /** Translation key for the error alert. */
  errorKey?: string;
  /** Suppress the error alert entirely (promise still resolves to null). */
  silent?: boolean;
}

interface FlarumApiError {
  response?: {
    errors?: Array<{ detail?: string; title?: string; code?: string }>;
  };
  message?: string;
  status?: number;
}

/** Base da API (admin tem `app.forum.attribute('apiUrl')`). */
export const apiUrl = (): string =>
  (app.forum.attribute<string>("apiUrl") || "/api").replace(/\/+$/, "");

/**
 * Envolve `app.request` com feedback de erro consistente. Em sucesso devolve a
 * resposta tipada; em falha mostra um alerta e devolve null.
 */
export default async function apiCall<T = unknown>(
  options: FlarumRequestOptions<T>,
  opts: ApiCallOptions = {},
): Promise<T | null> {
  try {
    return await app.request<T>(options);
  } catch (raw) {
    const err = raw as FlarumApiError;

    if (!opts.silent) {
      const detail = err?.response?.errors?.[0]?.detail;
      const title = err?.response?.errors?.[0]?.title;

      let msg: string;
      if (detail) {
        msg = detail;
      } else if (title) {
        msg = title;
      } else if (!err?.response) {
        msg = extractText(
          app.translator.trans("ramon-mybb-migrator.lib.errors.network"),
        );
      } else {
        msg = extractText(
          app.translator.trans(
            opts.errorKey || "ramon-mybb-migrator.lib.errors.generic",
          ),
        );
      }

      app.alerts.show({ type: "error" }, msg);
    }

    return null;
  }
}
