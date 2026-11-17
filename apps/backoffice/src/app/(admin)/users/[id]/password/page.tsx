"use client";

import { Typography } from "antd";
import { useQueryClient } from "@tanstack/react-query";
import { PasswordForm } from "@/components/account-forms";
import { NotFoundView } from "@/components/ui";
import { useUserRow } from "../../user-context";

export default function PasswordTab() {
  const user = useUserRow();
  const qc = useQueryClient();
  if (!user.is_self && !user.can_manage) return <NotFoundView />;
  return (
    <>
      <Typography.Paragraph className="ep-muted">
        {user.is_self
          ? "Enter your current password, then the new one twice. Your other signed-in devices will be signed out."
          : `Set a new password for ${user.name}. They will be signed out everywhere. For a one-time password they must replace, use "Reset password" in the Users list instead.`}
      </Typography.Paragraph>
      <PasswordForm self={user.is_self} userId={user.id} onDone={() => qc.invalidateQueries({ queryKey: ["user", String(user.id)] })} />
    </>
  );
}
