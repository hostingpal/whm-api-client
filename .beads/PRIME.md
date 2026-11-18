# Beads Workflow Context

> Run `bd prime` after compaction, clear, or a new session. This is the project's
> short form: `AGENTS.md` is authoritative for session completion and git workflow,
> and `bd <command> --help` documents each command.

## Session close protocol

Before saying "done" or "complete":

1. `bd close <id1> <id2> ...` for finished issues; file beads for remaining work.
2. Run the quality gates listed in `AGENTS.md`.
3. `git status`, then commit, push and open a pull request against `master`.
   agent.profile=team-maintainer makes this routine unless current instructions say otherwise.
4. `bd dolt push`.

## Core rules

- Use beads for ALL task tracking. Do NOT use TodoWrite, TaskCreate or markdown TODO lists.
- Create the bead before writing code and claim it with `bd update <id> --claim`.
- Use `bd remember "<fact>" --key <key>` for persistent knowledge; do NOT use MEMORY.md files.
  A memory is a standing fact of one or two sentences. Reports, handoffs and review
  ledgers go on the bead (`bd update <id> --append-notes`) or the pull request.
- Never use `bd edit`: it opens an editor and blocks agents.

## Essential commands

- Find work: `bd ready`, `bd list --status=in_progress`, `bd show <id>`, `bd blocked`, `bd search <query>`
- Create: `bd create --title="..." --description="..." --type=task|bug|feature --priority=2`
  (priority is 0-4, not words; `--parent=<id>` makes a child)
- Update: `bd update <id> --claim`, `bd update <id> --notes "..."`, `bd dep add <issue> <depends-on>`, `bd close <id> --reason="..."`
- Memories: `bd memories <keyword>` (previews), `bd recall <key>` (full text), `bd forget <key>`
