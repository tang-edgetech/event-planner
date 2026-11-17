"use client";

import { useQueryClient } from "@tanstack/react-query";
import { TwoFactorManager } from "@/components/account-forms";
import { useRefreshMe } from "@/lib/session";
import { useUserRow } from "../../user-context";

export default function TwoFactorTab() {
  const user = useUserRow();
  const qc = useQueryClient();
  const refreshMe = useRefreshMe();
  return (
    <TwoFactorManager
      self={user.is_self}
      userId={user.id}
      canManage={user.can_manage}
      onChanged={() => {
        qc.invalidateQueries({ queryKey: ["user", String(user.id)] });
        if (user.is_self) refreshMe();
      }}
    />
  );
}
