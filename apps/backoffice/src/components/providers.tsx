"use client";

import { App, ConfigProvider } from "antd";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { createContext, useContext, useMemo, useState } from "react";
import { ANT_PREFIX, antTheme, findTheme, themeVars, type EpTheme } from "@/theme/themes";

type ThemeCtx = { theme: EpTheme; setThemeKey: (key: string | null) => void };
const ThemeContext = createContext<ThemeCtx>({ theme: findTheme(), setThemeKey: () => {} });

/** The active colour scheme. The shell sets it from Settings → Appearance (or the user's own choice). */
export const useEpTheme = () => useContext(ThemeContext);

export function Providers({ children }: { children: React.ReactNode }) {
  const [client] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: { retry: false, refetchOnWindowFocus: false, staleTime: 15_000 },
          mutations: { retry: false },
        },
      }),
  );
  const [themeKey, setThemeKey] = useState<string | null>(null);
  const theme = findTheme(themeKey);
  const ctx = useMemo(() => ({ theme, setThemeKey }), [theme]);

  return (
    <QueryClientProvider client={client}>
      <ThemeContext.Provider value={ctx}>
        <ConfigProvider prefixCls={ANT_PREFIX} iconPrefixCls={`${ANT_PREFIX}-icon`} theme={antTheme(theme)}>
          <App>
            <div className="ep-root" style={themeVars(theme) as React.CSSProperties}>
              {children}
            </div>
          </App>
        </ConfigProvider>
      </ThemeContext.Provider>
    </QueryClientProvider>
  );
}
