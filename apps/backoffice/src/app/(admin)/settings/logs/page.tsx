"use client";

import Link from "next/link";
import { Alert, Form, InputNumber } from "antd";
import { useCan } from "@/lib/session";
import { SettingsForm } from "../use-settings";

export default function LogsSettings() {
  const can = useCan();
  return (
    <SettingsForm group="logs">
      {() => (
        <>
          <Alert
            className="!mb-4"
            type="info"
            showIcon
            title={
              <span>
                The audit log records every activity, successes and errors.{" "}
                {can("audit.view") ? <Link href="/audit-logs">Open the audit logs</Link> : null}
              </span>
            }
          />
          <Form.Item label="Keep audit log entries for (days)" name="retention_days" extra="Older entries are deleted automatically every night at 3:00 AM. Minimum 30 days.">
            <InputNumber min={30} max={3650} />
          </Form.Item>
        </>
      )}
    </SettingsForm>
  );
}
