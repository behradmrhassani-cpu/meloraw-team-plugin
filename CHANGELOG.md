# Changelog

## 3.0.0
- Board: "my tasks" / "all", two columns, compact cards, readable hover/focus states.
- Task codes per creator (MH-1, MA-1, CH-1, AU-1), several assignees per task.
- One template for tasks and routines: fixed estimate (min 5 min), due date, done criteria, routine end date, "incomplete" label.
- Estimate extension only with a reason; the original estimate is always kept.
- Work timer with pauses (reason required), net and total time, long-pause and forgotten-timer prompts.
- Points on done (priority weight x full / half / very low), weekly view, recalculation.
- "My day" page, time and points report, announcement bar, dismissible "why we log time" note.
- All displayed times and routine period boundaries are UTC; live date/time bar.
- Import: new fields, row-numbered dry run, `GET /schema`, downloadable templates (`docs/import-template/`).
- Health check page (13 independent checks, manual Telegram test message).
- Agent handoff text (no secrets) and `docs/agent-complete-tasks.md`.

### Behaviour changes for agents
- `POST /tasks` requires `estimate_minutes` (min 5).
- `PATCH /routines/{key}` no longer requires `status`.

### Known limits
- `GET /export` and `POST /import` do not carry timers, extensions or points; back up the database for a full copy.
- Points have no anti-cheat besides the activity log.

## 0.1.0 - 2.x
Initial plugin: tasks, comments and flags, routines, idea bank, reports, log, import/export, manual Telegram, security headers.
