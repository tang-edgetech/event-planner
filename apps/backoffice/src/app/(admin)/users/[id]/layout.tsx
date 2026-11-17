"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { Badge, Button, Skeleton, Tag } from "antd";
import { ArrowLeftOutlined } from "@ant-design/icons";
import { useCan } from "@/lib/session";
import { NotFoundView, PageHead, RouteTabs } from "@/components/ui";
import { UserContext, useUserQuery } from "../user-context";

/** /users/{id}/account | password | two-factor — each tab is its own address. */
export default function UserLayout({ children }: { children: React.ReactNode }) {
  const { id } = useParams<{ id: string }>();
  const can = useCan();
  const { data: user, isLoading, isError } = useUserQuery(id);

  if (isLoading) return <Skeleton active />;
  if (isError || !user) return <NotFoundView />;

  return (
    <UserContext.Provider value={user}>
      <PageHead
        title={
          <span>
            {user.is_self ? "My account" : user.name} {user.is_self ? null : <Badge status={user.online ? "success" : "default"} text={<span className="ep-muted text-sm font-normal">{user.online ? "online" : "offline"}</span>} />}
          </span>
        }
        sub={
          <span>
            @{user.username} · {user.role_label} · {user.status === "active" ? <Tag color="green">Active</Tag> : <Tag>Inactive</Tag>}
          </span>
        }
        actions={
          can("users.view") ? (
            <Link href="/users">
              <Button icon={<ArrowLeftOutlined />}>All users</Button>
            </Link>
          ) : null
        }
      />
      <RouteTabs
        items={[
          { href: `/users/${id}/account`, label: "Account information" },
          { href: `/users/${id}/password`, label: "Password", hidden: !user.is_self && !user.can_manage },
          { href: `/users/${id}/two-factor`, label: "Two-factor authentication" },
        ]}
      />
      <div className="ep-card">{children}</div>
    </UserContext.Provider>
  );
}
