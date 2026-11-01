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

The repository caps `bd prime` memory injection at four entries and an 8,000-byte
memory budget. These caps affect injection only: all history remains searchable
with `bd memories <keyword>`. Use `bd ready` and `bd show <id>` for current work.
Selection is alphabetical, not based on freshness or relevance.
`00-agent-context-index` supplies retrieval pointers rather than mutable task status;
AGENTS.md and current user instructions remain authoritative.

The byte budget covers whole memory entries, excluding headers and the core
workflow. Upstream always includes at least one entry, so an oversized first entry
can exceed that budget. This is not a hard limit on total injected bytes.

Normal Claude `bd prime --hook-json` and Codex `bd codex-hook` lifecycle wrappers
inherit the resolved Beads workspace configuration. Git worktrees can resolve to
the main checkout's `.beads` directory instead of their local configuration;
check `bd where` before assuming these caps apply. The resolved checkout needs the
updated configuration, as do other independent checkouts, before they benefit.

```bash
bd config get prime.max-memories
bd config get prime.max-memory-chars
bd memories search-absence-and-flexible-json-contracts
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
