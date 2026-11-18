# Beads - AI-Native Issue Tracking

Welcome to Beads! This repository uses **Beads** for issue tracking - a modern, AI-native tool designed to live directly in your codebase alongside your code.

## What is Beads?

Beads is issue tracking that lives in your repo, making it perfect for AI coding agents and developers who want their issues close to their code. No web UI required - everything works through the CLI and integrates seamlessly with git.

**Learn more:** [github.com/steveyegge/beads](https://github.com/steveyegge/beads)

## Quick Start

### Essential Commands

```bash
# Create new issues
bd create "Add user authentication"

# View all issues
bd list

# View issue details
bd show <issue-id>

# Update issue status
bd update <issue-id> --claim
bd update <issue-id> --status done

# Sync with Dolt remote
bd dolt push
```

### Working with Issues

Issues in Beads are:
- **Git-native**: Stored in Dolt database with version control and branching
- **AI-friendly**: CLI-first design works perfectly with AI coding agents
- **Branch-aware**: Issues can follow your branch workflow
- **Sync-ready**: Uses Dolt remotes for backup and team sharing

## Bounded agent context

Claude Code truncates a session-start hook's output above 10,000 characters to a
2,000-character preview, so everything `bd prime` injects has to fit below that.
Two files share the budget:

- `PRIME.md` replaces the default Beads workflow text (about 6,400 characters)
  with a short project form. Keep it under 2,200 bytes; CI checks this.
  `bd prime --export` still prints the default command reference.
- `config.yaml` sets `prime.max-memory-chars: 7400` with no count cap.

Selection is alphabetical, not based on freshness or relevance, so the memories
that every session needs have keys starting with `00-`: `00-agent-context-index`,
`00-policy-*` (standing owner policies), `00-env-*` (environment gotchas) and
`00-lesson-*` (review lessons). Keep that set under the 7,400-byte budget; a
`00-` memory that does not fit is silently left out along with everything after
it. The caps affect injection only: search the other memories with
`bd memories <keyword>`, which prints a short preview of each match, and read a
full record with `bd recall <key>`. Use `bd ready` and `bd show <id>` for current
work. AGENTS.md and current user instructions remain authoritative.

A memory is a standing fact of one or two sentences. Reports, handoffs, rulings
and review ledgers belong on the bead or the pull request. The store was pruned
from 478 memories (10 MB) to 50 on 2026-11-17 for this reason.

The byte budget covers whole memory entries, excluding the section header and the
workflow text. Upstream always includes at least one entry, so an oversized first
entry can exceed that budget.

Normal Claude `bd prime --hook-json` and Codex `bd codex-hook` lifecycle wrappers
inherit the resolved Beads workspace configuration. Git worktrees can resolve to
the main checkout's `.beads` directory instead of their local configuration;
check `bd where` before assuming these caps apply. The resolved checkout needs the
updated configuration, as do other independent checkouts, before they benefit.

```bash
bd config get prime.max-memory-chars
bd prime --hook-json | wc -m
bd memories search-absence
bd recall 00-policy-search-absence-and-flexible-json-contracts
bd ready
bd show <id>
```

## Why Beads?

✨ **AI-Native Design**
- Built specifically for AI-assisted development workflows
- CLI-first interface works seamlessly with AI coding agents
- No context switching to web UIs

🚀 **Developer Focused**
- Issues live in your repo, right next to your code
- Works offline, syncs when you push
- Fast, lightweight, and stays out of your way

🔧 **Git Integration**
- Dolt-native sync via bd dolt push / bd dolt pull
- Branch-aware issue tracking
- Dolt-native three-way merge resolution

## Get Started with Beads

Try Beads in your own projects:

```bash
# Install Beads
curl -sSL https://raw.githubusercontent.com/steveyegge/beads/main/scripts/install.sh | bash

# Initialize in your repo
bd init

# Create your first issue
bd create "Try out Beads"
```

## Learn More

- **Documentation**: [github.com/steveyegge/beads/docs](https://github.com/steveyegge/beads/tree/main/docs)
- **Quick Start Guide**: Run `bd quickstart`
- **Examples**: [github.com/steveyegge/beads/examples](https://github.com/steveyegge/beads/tree/main/examples)

---

*Beads: Issue tracking that moves at the speed of thought* ⚡
