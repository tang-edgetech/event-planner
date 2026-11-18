"use client";

import { App, Alert, Button, Checkbox, Drawer, Form, Input, InputNumber, Space, Table, Tag, Tooltip } from "antd";
import { CheckOutlined, DeleteOutlined, EditOutlined, LockOutlined, PlusOutlined } from "@ant-design/icons";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { api, errorMessage, formErrors } from "@/lib/api";
import { useMe, useRefreshMe } from "@/lib/session";
import { NotFoundView, PageHead } from "@/components/ui";

type RoleRow = { key: string; label: string; description: string | null; rank: number; permissions: string[]; is_system: boolean; users_count: number; editable: boolean; deletable: boolean };
type Catalog = { group: string; items: { key: string; label: string; description: string }[] }[];
type Values = { label: string; description?: string; rank: number; permissions: string[] };

/**
 * Roles & permissions (developer only): who can do what, and who may manage whom (rank).
 * Everyone else gets "Page not found" here, and the API answers 404 too.
 */
export default function RolesPage() {
  const me = useMe();
  const qc = useQueryClient();
  const refreshMe = useRefreshMe();
  const { message, modal } = App.useApp();
  const [editing, setEditing] = useState<RoleRow | "new" | null>(null);
  const { data, isLoading } = useQuery({
    queryKey: ["roles"],
    queryFn: async () => (await api.get<{ data: RoleRow[]; catalog: Catalog }>("/roles")).data,
    enabled: me.is_developer,
  });

  if (!me.is_developer) return <NotFoundView />;
  const catalog = data?.catalog ?? [];
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ["roles"] });
    refreshMe();
  };

  const remove = (r: RoleRow) =>
    modal.confirm({
      title: `Delete the role “${r.label}”?`,
      content: "Nobody has this role, so no account is affected.",
      okText: "Delete",
      okButtonProps: { danger: true },
      onOk: async () => {
        try {
          await api.delete(`/roles/${r.key}`);
          message.success("Role deleted");
          refresh();
        } catch (e) {
          message.error(errorMessage(e));
        }
      },
    });

  const allPerms = catalog.flatMap((g) => g.items);

  return (
    <>
      <PageHead
        title="Roles & permissions"
        sub="Only you (Developer) can see this page. Changes apply at once to everyone with the role."
        actions={
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditing("new")}>
            New role
          </Button>
        }
      />
      <Alert
        className="!mb-4"
        type="info"
        showIcon
        title="Rank decides who manages whom: a user can only add, edit or reset users whose role has a lower rank. The Developer role is always on top and always has every permission."
      />
      <Table<RoleRow>
        rowKey="key"
        loading={isLoading}
        dataSource={data?.data}
        pagination={false}
        scroll={{ x: 820 }}
        columns={[
          {
            title: "Role",
            render: (_, r) => (
              <div>
                <span className="font-semibold">{r.label}</span>
                {r.is_system ? <Tag className="!ml-2">Built-in</Tag> : null}
                {!r.editable ? <LockOutlined className="ep-muted !ml-1" aria-label="Locked" /> : null}
                <div className="ep-muted text-xs">{r.description || r.key}</div>
              </div>
            ),
          },
          { title: "Rank", dataIndex: "rank", width: 90, render: (n: number) => (n >= 1000 ? "Top" : n) },
          { title: "Permissions", width: 140, render: (_, r) => `${r.permissions.length} of ${allPerms.length}` },
          { title: "Users", dataIndex: "users_count", width: 90 },
          {
            title: "",
            width: 190,
            render: (_, r) => (
              <Space>
                <Button size="small" icon={<EditOutlined />} disabled={!r.editable} onClick={() => setEditing(r)}>
                  Edit
                </Button>
                {!r.is_system ? (
                  <Tooltip title={r.deletable ? undefined : "Give its users another role first"}>
                    <Button size="small" danger icon={<DeleteOutlined />} disabled={!r.deletable} onClick={() => remove(r)}>
                      Delete
                    </Button>
                  </Tooltip>
                ) : null}
              </Space>
            ),
          },
        ]}
      />

      <h2 className="mb-3 mt-8 text-base font-semibold">Who can do what</h2>
      <Table
        rowKey="key"
        size="small"
        pagination={false}
        scroll={{ x: 300 + (data?.data.length ?? 0) * 120 }}
        dataSource={allPerms}
        columns={[
          { title: "Permission", fixed: "left", width: 260, render: (_, p) => <Tooltip title={p.description}>{p.label}</Tooltip> },
          ...(data?.data ?? []).map((r) => ({
            title: r.label,
            key: r.key,
            width: 120,
            align: "center" as const,
            render: (_: unknown, p: { key: string }) => (r.permissions.includes(p.key) ? <CheckOutlined className="ep-check" aria-label="Yes" /> : <span className="ep-muted" aria-label="No">—</span>),
          })),
        ]}
      />

      <RoleDrawer role={editing} catalog={catalog} onClose={() => setEditing(null)} onSaved={refresh} />
    </>
  );
}

function RoleDrawer({ role, catalog, onClose, onSaved }: { role: RoleRow | "new" | null; catalog: Catalog; onClose: () => void; onSaved: () => void }) {
  const [form] = Form.useForm<Values>();
  const { message } = App.useApp();
  const [saving, setSaving] = useState(false);
  const creating = role === "new";
  const current = role && role !== "new" ? role : null;

  const save = async (v: Values) => {
    setSaving(true);
    try {
      if (creating) await api.post("/roles", v);
      else await api.put(`/roles/${current!.key}`, v);
      message.success(creating ? "Role created" : "Role saved. It applies to its users straight away.");
      onSaved();
      onClose();
    } catch (e) {
      form.setFields(formErrors(e));
      message.error(errorMessage(e, "Please check the highlighted fields."));
    } finally {
      setSaving(false);
    }
  };

  // Managing or exporting something needs seeing it.
  const withViews = (list: string[]) => {
    const out = new Set(list);
    list.forEach((p) => {
      const m = p.match(/^(\w+)\.(manage|export)$/);
      if (m) out.add(`${m[1]}.view`);
    });
    return [...out];
  };

  return (
    <Drawer
      open={role !== null}
      onClose={onClose}
      size="large"
      title={creating ? "New role" : `Edit role: ${current?.label}`}
      destroyOnHidden
      extra={
        <Button type="primary" loading={saving} onClick={() => form.submit()}>
          {creating ? "Create role" : "Save role"}
        </Button>
      }
    >
      <Form<Values>
        form={form}
        layout="vertical"
        requiredMark={false}
        onFinish={save}
        initialValues={current ? { label: current.label, description: current.description ?? "", rank: current.rank, permissions: current.permissions } : { label: "", description: "", rank: 1, permissions: ["dashboard.view"] }}
        onValuesChange={(changed) => {
          if (changed.permissions) form.setFieldValue("permissions", withViews(changed.permissions));
        }}
      >
        <div className="grid gap-x-4 md:grid-cols-3">
          <Form.Item label="Name" name="label" rules={[{ required: true, message: "Give the role a name." }]} className="md:col-span-2">
            <Input maxLength={60} placeholder="e.g. Event editor" />
          </Form.Item>
          <Form.Item label="Rank" name="rank" rules={[{ required: true }]} extra="1 = lowest. Higher ranks manage lower ones.">
            <InputNumber min={1} max={999} className="!w-full" />
          </Form.Item>
        </div>
        <Form.Item label="Description (optional)" name="description">
          <Input maxLength={255} placeholder="What this role is for" />
        </Form.Item>
        <Form.Item label="Permissions" name="permissions" extra="Ticking a “Manage” or “Export” permission also ticks the matching “See” permission.">
          <Checkbox.Group className="!block">
            <div className="flex flex-col gap-4">
              {catalog.map((g) => (
                <div key={g.group} className="ep-perm-group" role="group" aria-label={g.group}>
                  <div className="mb-1 text-sm font-semibold">{g.group}</div>
                  {g.items.map((p) => (
                    <Checkbox key={p.key} value={p.key} className="!flex !py-1">
                      <span className="font-medium">{p.label}</span>
                      <span className="ep-muted block text-xs">{p.description}</span>
                    </Checkbox>
                  ))}
                </div>
              ))}
            </div>
          </Checkbox.Group>
        </Form.Item>
      </Form>
    </Drawer>
  );
}
