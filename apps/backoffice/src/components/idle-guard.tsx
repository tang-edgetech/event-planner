"use client";

import { Button, Modal, Typography } from "antd";
import { ClockCircleOutlined } from "@ant-design/icons";
import { useCallback, useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";

const WARNING_SECONDS = 120;
const HEARTBEAT_MS = 5 * 60 * 1000;
const ACTIVITY = ["mousemove", "mousedown", "keydown", "scroll", "touchstart", "wheel"] as const;

/**
 * Signs the person out after `idleMinutes` without activity (Settings → Security, default 2 hours).
 * Two minutes before, a pop-up offers "Stay signed in". At zero, a pop-up that cannot be dismissed
 * signs them out. While active, a heartbeat keeps the server's own idle timer in step.
 */
export function IdleGuard({ idleMinutes, onSignedOut }: { idleMinutes: number; onSignedOut: () => void }) {
  const limit = Math.max(5, idleMinutes) * 60;
  const lastActive = useRef(0);
  const lastBeat = useRef(0);
  const [left, setLeft] = useState(limit);
  const [expired, setExpired] = useState(false);

  const markActive = useCallback(() => {
    lastActive.current = Date.now();
  }, []);

  useEffect(() => {
    lastActive.current = Date.now();
    lastBeat.current = Date.now();
    ACTIVITY.forEach((e) => window.addEventListener(e, markActive, { passive: true }));
    const tick = window.setInterval(() => {
      // Activity in another tab of the backoffice counts too.
      const shared = Number(localStorage.getItem("ep-last-active") ?? 0);
      if (shared > lastActive.current) lastActive.current = shared;
      const idle = Math.floor((Date.now() - lastActive.current) / 1000);
      const remaining = limit - idle;
      setLeft(remaining);
      if (remaining <= 0) {
        setExpired(true);
        return;
      }
      if (idle < 60) {
        try {
          localStorage.setItem("ep-last-active", String(lastActive.current));
        } catch {}
        if (Date.now() - lastBeat.current > HEARTBEAT_MS) {
          lastBeat.current = Date.now();
          api.post("/auth/heartbeat", null, { headers: { "X-Background": "1" } }).catch(() => {});
        }
      }
    }, 1000);
    return () => {
      ACTIVITY.forEach((e) => window.removeEventListener(e, markActive));
      window.clearInterval(tick);
    };
  }, [limit, markActive]);

  useEffect(() => {
    if (expired) {
      // Tell the shell this sign-out is already explained by the inactivity pop-up.
      window.dispatchEvent(new CustomEvent("ep:signed-out", { detail: "idle_local" }));
      api.post("/auth/logout", { reason: "idle" }).catch(() => {});
    }
  }, [expired]);

  const warning = !expired && left <= WARNING_SECONDS;
  const mm = Math.floor(Math.max(0, left) / 60);
  const ss = String(Math.max(0, left) % 60).padStart(2, "0");
  const hours = idleMinutes >= 60 && idleMinutes % 60 === 0 ? `${idleMinutes / 60} hour${idleMinutes === 60 ? "" : "s"}` : `${idleMinutes} minutes`;

  return (
    <>
      <Modal
        open={warning}
        title={
          <span>
            <ClockCircleOutlined /> Are you still there?
          </span>
        }
        closable={false}
        mask={{ closable: false }}
        keyboard={false}
        footer={
          <Button
            type="primary"
            onClick={() => {
              markActive();
              lastBeat.current = Date.now();
              api.post("/auth/heartbeat").catch(() => {});
            }}
          >
            Stay signed in
          </Button>
        }
      >
        <Typography.Paragraph>
          You will be signed out in{" "}
          <strong>
            {mm}:{ss}
          </strong>{" "}
          because there has been no activity.
        </Typography.Paragraph>
      </Modal>
      <Modal open={expired} title="Signed out" closable={false} mask={{ closable: false }} keyboard={false} footer={<Button type="primary" onClick={onSignedOut}>Sign in again</Button>}>
        <Typography.Paragraph>For your security you were signed out after {hours} without activity.</Typography.Paragraph>
      </Modal>
    </>
  );
}
