import './globals.css';
import React from 'react';
import ThemeProvider from './components/ThemeProvider';

export const metadata = {
  title: 'Transaction Retry Dashboard',
  icons: {
    icon: '/transaction-retry/logo-cropped.svg',
  },
};

const themeInitScript = `(function () {
  try {
    var stored = localStorage.getItem('dashboard-theme');
    var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    var theme = stored === 'light' || stored === 'dark' ? stored : (prefersDark ? 'dark' : 'light');
    document.documentElement.dataset.theme = theme;
  } catch (e) {
    // Ignore storage/SSR issues.
  }
})();`;

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en" suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{__html: themeInitScript}} />
      </head>
      <body>
        <ThemeProvider>{children}</ThemeProvider>
      </body>
    </html>
  );
}
