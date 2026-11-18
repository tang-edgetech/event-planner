"use client";

import Link from "next/link";
import { App, Badge, Button, Dropdown, Input, Modal, Select, Switch, Table, Tag, Tooltip, Typography } from "antd";
import { CheckCircleOutlined, KeyOutlined, LogoutOutlined, MoreOutlined, PlusOutlined, SafetyOutlined, StopOutlined } from "@ant-design/icons";
import { keepPreviousData, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { api, errorMessage } from "@/lib/api";
import { fmtDateTime, fromNow } from "@/lib/format";
import { useCan, useMe } from "@/lib/session";
import type { Paged, UserRow } from "@/lib/types";
import { BulkBar, NotFoundView, PageHead, sortParams } from "@/components/ui";

export default function UsersPage() {
  const me = useMe();
  const can = useCan();
  const qc = useQueryClient();
  const { message, modal } = App.useApp();
  const [params, setParams] = useState({ q: "", role: undefined as string | undefined, status: undefined as string | undefined, sort: undefined as string | undefined, order: undefined as string | undefined, page: 1, per_page: 20 });
  const [selected, setSelected] = useState<number[]>([]);
  const { data, isFetching } = useQuery({
    queryKey: ["users", params],
    queryFn: async () => (await api.get<Paged<UserRow>>("/users", { params })).data,
    placeholderData: keepPreviousData,
    enabled: can("users.view"),
  });
  const [temp, setTemp] = useState<{ name: string; password: string } | null>(null);

  if (!can("users.view")) return <NotFoundView />;
  const refresh = () => qc.invalidateQueries({ queryKey: ["users"] });

  const toggle = async (u: UserRow, active: boolean) => {
    try {
      await api.patch(`/users/${u.id}/status`, { status: active ? "active" : "inactive" });
      message.success(`${u.name} is now ${active ? "active" : "inactive"}`);
      refresh();
    } catch (e) {
      message.error(errorMessage(e));
    }
  };

  const action = (u: UserRow, kind: "reset-password" | "reset-two-factor" | "force-logout") => {
    const text = {
      "reset-password": { title: `Reset ${u.name}'s password?`, body: "A temporary password is created and shown once. They are signed out and must choose a new password at the next sign-in.", ok: "Reset password" },
      "reset-two-factor": { title: `Reset 2FA for ${u.name}?`, body: "All their authenticator devices are removed. They can sign in with just the password until they link a device again.", ok: "Reset 2FA" },
      "force-logout": { title: `Sign ${u.name} out everywhere?`, body: "All their open sessions end immediately. They can sign in again.", ok: "Force logout" },
    }[kind];
    modal.confirm({
      title: text.title,
      content: text.body,
      okText: text.ok,
      okButtonProps: { danger: true },
      onOk: async () => {
        try {
          const res = await api.post(`/users/${u.id}/${kind}`);
          if (kind === "reset-password") setTemp({ name: u.name, password: res.data.temporary_password });
          else message.success(kind === "force-logout" ? `${u.name} was signed out (${res.data.sessions} session(s))` : "2FA reset");
          refresh();
        } catch (e) {
          message.error(errorMessage(e));
        }
      },
    });
  };

  const bulk = (action: "activate" | "deactivate" | "force_logout") => {
    const text = {
      activate: { title: `Activate ${selected.length} user(s)?`, body: "They can sign in again.", ok: "Activate" },
      deactivate: { title: `Deactivate ${selected.length} user(s)?`, body: "They are signed out at once and can no longer sign in.", ok: "Deactivate" },
      force_logout: { title: `Sign ${selected.length} user(s) out everywhere?`, body: "All their open sessions end immediately. They can sign in again.", ok: "Force logout" },
    }[action];
    modal.confirm({
      title: text.title,
      content: text.body,
      okText: text.ok,
      okButtonProps: action === "activate" ? undefined : { danger: true },
      onOk: async () => {
        try {
          const res = await api.post("/users/bulk", { ids: selected, action });
          message.success(`${res.data.changed} user(s) updated${res.data.skipped ? ` · ${res.data.skipped} skipped (you cannot manage them)` : ""}`);
          setSelected([]);
          refresh();
        } catch (e) {
          message.error(errorMessage(e));
        }
      },
    });
  };

  return (
    <>
      <PageHead
        title="Users"
        sub="Who can sign in to the backoffice, and what they can do."
        actions={
          can("users.manage") ? (
            <Link href="/users/new">
              <Button type="primary" icon={<PlusOutlined />}>
                Add user
              </Button>
            </Link>
          ) : null
        }
      />
      <div className="ep-filters">
        <Input.Search allowClear placeholder="Search name, username or email" onSearch={(q) => setParams((p) => ({ ...p, q, page: 1 }))} style={{ width: 280 }} />
        <Select allowClear placeholder="All roles" style={{ width: 160 }} options={me.roles.map((r) => ({ value: r.value, label: r.label }))} onChange={(role) => setParams((p) => ({ ...p, role, page: 1 }))} />
        <Select
          allowClear
          placeholder="All statuses"
          style={{ width: 150 }}
          options={[
            { value: "active", label: "Active" },
            { value: "inactive", label: "Inactive" },
          ]}
          onChange={(status) => setParams((p) => ({ ...p, status, page: 1 }))}
        />
      </div>
      {can("users.manage") ? (
        <BulkBar count={selected.length} onClear={() => setSelected([])} hint="Tick users to activate, deactivate or sign out several at once.">
          <Button icon={<CheckCircleOutlined />} disabled={!selected.length} onClick={() => bulk("activate")}>
            Activate
          </Button>
          <Button icon={<StopOutlined />} disabled={!selected.length} onClick={() => bulk("deactivate")}>
            Deactivate
          </Button>
          <Button danger icon={<LogoutOutlined />} disabled={!selected.length} onClick={() => bulk("force_logout")}>
            Force logout
          </Button>
        </BulkBar>
      ) : null}
      <Table<UserRow>
        rowKey="id"
        loading={isFetching}
        dataSource={data?.data}
        scroll={{ x: 900 }}
        rowSelection={
          can("users.manage")
            ? { selectedRowKeys: selected, onChange: (k) => setSelected(k as number[]), preserveSelectedRowKeys: true, getCheckboxProps: (u) => ({ disabled: !u.can_manage, "aria-label": `Select ${u.name}` }) }
            : undefined
        }
        onChange={(_, __, sorter, extra) => {
          if (extra.action === "sort") setParams((p) => ({ ...p, ...sortParams(sorter), page: 1 }));
        }}
        pagination={{ current: params.page, pageSize: params.per_page, total: data?.meta.total, showSizeChanger: true, showTotal: (t) => `${t} user(s)`, onChange: (page, per_page) => setParams((p) => ({ ...p, page, per_page })) }}
        columns={[
          {
            title: "Name",
            key: "name",
            sorter: true,
            render: (_, u) => (
              <div>
                <Badge status={u.online ? "success" : "default"} />
                <Link href={`/users/${u.id}/account`} className="font-semibold">
                  {u.name}
                </Link>
                {u.is_self ? <Tag className="!ml-2">You</Tag> : null}
                <div className="ep-muted text-xs">
                  @{u.username} · {u.email}
                </div>
              </div>
            ),
          },
          { title: "Role", key: "role", dataIndex: "role_label", sorter: true, width: 140 },
          {
            title: "2FA",
            key: "two_factor_count",
            sorter: true,
            width: 90,
            render: (_, u) => (u.two_factor_count ? <Tag color="green" icon={<SafetyOutlined />}>{u.two_factor_count}</Tag> : <Tag>Off</Tag>),
          },
          {
            title: "Last sign-in",
            key: "last_login_at",
            sorter: true,
            width: 170,
            render: (_, u) => (
              <Tooltip title={fmtDateTime(u.last_login_at)}>
                <span className="ep-muted">{fromNow(u.last_login_at)}</span>
              </Tooltip>
            ),
          },
          {
            title: "Active",
            key: "status",
            sorter: true,
            width: 100,
            render: (_, u) => <Switch checked={u.status === "active"} disabled={!u.can_manage} onChange={(v) => toggle(u, v)} aria-label={`${u.name} active`} />,
          },
          {
            title: "",
            width: 60,
            render: (_, u) =>
              u.can_manage ? (
                <Dropdown
                  trigger={["click"]}
                  menu={{
                    items: [
                      { key: "p", icon: <KeyOutlined />, label: "Reset password", onClick: () => action(u, "reset-password") },
                      { key: "t", icon: <SafetyOutlined />, label: "Reset 2FA", disabled: !u.two_factor_count, onClick: () => action(u, "reset-two-factor") },
                      { key: "l", icon: <LogoutOutlined />, label: "Force logout", onClick: () => action(u, "force-logout") },
                    ],
                  }}
                >
                  <Button type="text" icon={<MoreOutlined />} aria-label={`Actions for ${u.name}`} />
                </Dropdown>
              ) : null,
          },
        ]}
      />
      <Modal open={!!temp} title="Temporary password" onCancel={() => setTemp(null)} footer={<Button type="primary" onClick={() => setTemp(null)}>Done</Button>}>
        <Typography.Paragraph>
          Give this password to <strong>{temp?.name}</strong> in a safe way. It is shown only once; they must change it when they sign in.
        </Typography.Paragraph>
        <Typography.Paragraph>
          <Typography.Text code copyable className="text-base">
            {temp?.password}
          </Typography.Text>
        </Typography.Paragraph>
      </Modal>
    </>
  );
}
