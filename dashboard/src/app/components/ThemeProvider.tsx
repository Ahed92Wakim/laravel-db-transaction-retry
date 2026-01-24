'use client';

import {
  createContext,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';

type Theme = 'light' | 'dark';

type ThemeContextValue = {
  theme: Theme;
  setTheme: (theme: Theme) => void;
  toggleTheme: () => void;
};

const ThemeContext = createContext<ThemeContextValue | null>(null);

export function useTheme() {
  const ctx = useContext(ThemeContext);
  if (!ctx) {
    throw new Error('useTheme must be used within ThemeProvider');
  }
  return ctx;
}

export default function ThemeProvider({children}: {children: ReactNode}) {
  const [theme, setTheme] = useState<Theme>('light');
  const [hasHydrated, setHasHydrated] = useState(false);

  useEffect(() => {
    const stored = window.localStorage.getItem('dashboard-theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const nextTheme =
      stored === 'light' || stored === 'dark' ? stored : prefersDark ? 'dark' : 'light';

    setTheme(nextTheme);
    document.documentElement.dataset.theme = nextTheme;
    setHasHydrated(true);
  }, []);

  useEffect(() => {
    if (!hasHydrated) {
      return;
    }

    document.documentElement.dataset.theme = theme;
    window.localStorage.setItem('dashboard-theme', theme);
  }, [theme, hasHydrated]);

  const value = useMemo<ThemeContextValue>(
    () => ({
      theme,
      setTheme,
      toggleTheme: () => setTheme((prev) => (prev === 'dark' ? 'light' : 'dark')),
    }),
    [theme]
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}
