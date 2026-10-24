# Accessibility

Target: **WCAG 2.2 AA** (and therefore EN 301 549 / BFSG) for the login screens and the admin.

## Automated (runs in the test suite)

- **axe-core** on every screen, light and dark, desktop and mobile (`tests/e2e/accessibility.spec.mjs`).
- Target size ≥ 24 × 24 px (2.5.8), reflow at 200 % text size (1.4.4 / 1.4.10).
- Keyboard-only login, focus moves to the error summary / new heading (`tests/e2e/login.spec.mjs`).
- Colour contrast of all design tokens is checked during design (Figma contrast matrix).

Automated tests find roughly a third of all problems. Before every release, also do the
manual checks below.

## Manual checklist

Screen readers: **NVDA + Firefox/Chrome** (Windows) and **VoiceOver + Safari** (macOS and iOS).

- [ ] Page title and `<h1>` are announced on load; language (`lang`) is correct.
- [ ] Skip link appears on first Tab and moves to the form.
- [ ] Every field announces label, "required", hint and – after an error – the error text.
- [ ] After a failed login the error summary is announced and its links jump to the fields.
- [ ] "Show password" is announced as a toggle button with pressed state.
- [ ] Caps Lock hint is announced while typing the password.
- [ ] Loading state ("Logging in…") is announced; nothing happens twice on double submit.
- [ ] After moving to another screen (e.g. "Forgot password?") the new heading is announced
      and the browser back button returns to the previous screen.
- [ ] Language menu: opens with Enter/Space, closes with Escape, current language is marked.
- [ ] Windows high contrast mode: all borders, focus rings and buttons are visible.
- [ ] 400 % zoom on desktop / 320 px width: no horizontal scrolling, nothing cut off.
- [ ] `prefers-reduced-motion`: no aurora animation, no slide transitions.
- [ ] Password managers (1Password, Bitwarden, browser) fill login and save new passwords.
- [ ] Admin: all settings reachable and operable by keyboard; errors are announced.
