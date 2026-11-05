<style>
/* /journal pages: phone first, built on theme.css's tokens so dark mode follows. */
.journal { max-width: 720px; margin: 0 auto; padding: 12px 16px 40px; display: grid; gap: 10px; }
.journal-head { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; }
.journal-head h1 { margin: 0; font-size: 1.4rem; }
.journal-back { color: var(--text-nav-link); text-decoration: none; }
.journal-muted { color: var(--text-muted); font-size: .9rem; }
.journal-sync { margin: 0; }
.journal-day { font-size: .8rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin: 10px 0 0; }
.journal-item { display: grid; gap: 4px; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 10px;
    background: var(--bg-card); color: var(--text-primary); text-decoration: none; }
.journal-item:focus-visible, .journal button:focus-visible { outline: 2px solid var(--input-border-focus); outline-offset: 2px; }
.journal-title { font-weight: 600; color: var(--text-nav-link); }
.journal-path { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .78rem; color: var(--text-muted);
    overflow-wrap: anywhere; }
.journal-pill { justify-self: start; font-size: .75rem; padding: 2px 9px; border-radius: 999px; font-weight: 500; }
.journal-pill-new { background: var(--alert-warn-bg); color: var(--alert-warn-text); }
.journal-pill-edit { background: var(--info-subtle-bg); color: var(--text-nav-link); }
.journal-pill-done { background: var(--alert-success-bg); color: var(--alert-success-text); }
.journal-pill-warn { background: var(--alert-error-bg); color: var(--alert-error-text); }
.journal label { font-size: .85rem; color: var(--text-muted); }
.journal input[type=text], .journal textarea { width: 100%; box-sizing: border-box; font-family: ui-monospace, Menlo, Consolas, monospace;
    background: var(--input-bg); color: var(--text-primary); border: 1px solid var(--border-input); border-radius: 8px; padding: 8px; }
.journal textarea { min-height: 60vh; font-size: .85rem; line-height: 1.5; resize: vertical; }
.journal input[readonly] { color: var(--text-muted); }
.journal-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.journal-row input { flex: 1 1 240px; min-width: 0; }
.journal button { font: inherit; padding: 8px 16px; border-radius: 8px; border: 1px solid var(--border-color);
    background: var(--bg-card); color: var(--text-primary); cursor: pointer; }
.journal button.journal-save { background: var(--success); color: var(--text-on-accent); border-color: transparent; font-weight: 600; }
.journal button:disabled { opacity: .6; cursor: wait; }
.journal-banner { border-radius: 8px; padding: 10px 12px; font-size: .9rem; }
.journal-banner ul { margin: 4px 0 0; padding-left: 20px; }
.journal-banner-ok { background: var(--alert-success-bg); color: var(--alert-success-text); }
.journal-banner-bad { background: var(--alert-error-bg); color: var(--alert-error-text); }
.journal-banner-warn { background: var(--alert-warn-bg); color: var(--alert-warn-text); }
</style>
