"use client";

import { App, Button } from "antd";
import { CloudUploadOutlined, EyeInvisibleOutlined, WarningFilled } from "@ant-design/icons";
import { useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { api, errorMessage } from "@/lib/api";
import { useCan } from "@/lib/session";

type Item = { kind: "event" | "microsite"; id: number; title: string; status: string; address: string };

const NAMES = { event: { one: "event", list: "events", perm: "events.manage" }, microsite: { one: "microsite", list: "microsites", perm: "microsites.manage" } } as const;

/** Publishes or unpublishes an event or microsite in the background, after a confirmation. */
function usePublish(item: Item) {
  const qc = useQueryClient();
  const { message, modal } = App.useApp();
  const [busy, setBusy] = useState(false);
  const n = NAMES[item.kind];
  return {
    busy,
    run: (publish: boolean) =>
      modal.confirm({
        title: publish ? `Publish “${item.title}”?` : `Unpublish “${item.title}”?`,
        content: publish ? `Visitors can open ${item.address} straight away.` : `It goes back to Draft and ${item.address} shows “Page not found” to visitors.`,
        okText: publish ? "Publish now" : "Unpublish",
        okButtonProps: publish ? undefined : { danger: true },
        onOk: async () => {
          setBusy(true);
          try {
            await api.put(`/${n.list}/${item.id}`, { status: publish ? "published" : "draft" });
            message.success(publish ? `Published. ${item.address} is live.` : "Back to Draft. Visitors can no longer open it.");
            qc.invalidateQueries({ queryKey: [item.kind] });
            qc.invalidateQueries({ queryKey: [n.list] });
          } catch (e) {
            message.error(errorMessage(e));
          } finally {
            setBusy(false);
          }
        },
      }),
  };
}

/** Header button: a strong "Publish" while it is a draft, a quiet "Unpublish" once it is live. */
export function PublishButton(item: Item) {
  const can = useCan();
  const { busy, run } = usePublish(item);
  if (!can(NAMES[item.kind].perm)) return null;
  return item.status === "published" ? (
    <Button icon={<EyeInvisibleOutlined />} loading={busy} onClick={() => run(false)}>
      Unpublish
    </Button>
  ) : (
    <Button type="primary" icon={<CloudUploadOutlined />} loading={busy} onClick={() => run(true)} className="ep-publish-btn">
      Publish {NAMES[item.kind].one}
    </Button>
  );
}

/** Banner across the page while it is a draft, so nobody forgets to publish. */
export function DraftBanner(item: Item) {
  const can = useCan();
  const { busy, run } = usePublish(item);
  if (item.status === "published") return null;
  return (
    <div className="ep-draft-banner" role="status">
      <WarningFilled className="ep-draft-banner__icon" aria-hidden="true" />
      <div className="min-w-0 flex-1">
        <strong>This {NAMES[item.kind].one} is a draft.</strong> Visitors cannot open {item.address} until it is published.
      </div>
      {can(NAMES[item.kind].perm) ? (
        <Button type="primary" size="large" icon={<CloudUploadOutlined />} loading={busy} onClick={() => run(true)}>
          Publish now
        </Button>
      ) : null}
    </div>
  );
}
