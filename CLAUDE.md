# Working on this repo

## Feature/change workflow

1. **Discuss first.** For a new feature or any non-trivial change, discuss the approach, scope, and tradeoffs with the user before writing code — don't jump straight to implementation.
2. **Create an issue once agreed.** After the user agrees on what to build, create a GitHub issue for it (`gh issue create`) instead of starting implementation. The issue records what was decided: title, description, and relevant context from the discussion.
3. **Wait for the go-ahead.** Creating the issue is not permission to implement it. Only start work when the user explicitly asks to (e.g. "implement issue #12", "let's build that now").
4. **Close on commit.** When implementing an issue, reference it in the commit message body with `Closes #<n>` (or `Fixes #<n>`) so GitHub auto-closes it once the commit lands on `main`. Closing **more than one** issue from a single commit: repeat the keyword on its own line per issue (e.g. a `Closes #12.` line followed by a separate `Closes #13.` line), not a comma-separated list (`Closes #12, #13`) — GitHub's keyword parser only reliably auto-closes the first issue in a comma list, silently leaving the rest merely referenced. After pushing a multi-close commit, invoke the `close-verify` skill (`/close-verify`) to check each referenced issue and close any stragglers automatically.

Small, self-contained fixes the user directly asks for (typos, one-line bug fixes, config tweaks) don't need an issue first — this workflow is for features and non-trivial changes, not every edit.

## Issue triage: phase-* labels

For sequencing a batch of open issues, use the `phase-1`/`phase-2`/`phase-3` labels (created ad hoc, not a fixed enum — reuse or extend as needed):

- **`phase-1`** — ready to implement now, no blocking decision.
- **`phase-2`** — needs a decision (often paired with `needs-decision`) before any implementation starts.
- **`phase-3`** — blocked (e.g. on a future version-floor bump) or deliberately deferred.

When asked to plan/triage open issues, apply these labels to reflect the plan rather than only describing it in chat, so the labels stay the durable record of the sequencing decision.

## Version-log workflow

Every version tag on this repo must have a matching GitHub Release, so the repo's Releases page is the canonical version log for the plugin.

A release goes through three skill-shaped steps:

1. **`bump-version <X.Y.Z>`** — safely edits `wp-dansal.php` (Version header + `WPD_VERSION`) and `readme.txt` (Stable tag) with targeted edits, and inserts a `= X.Y.Z =` heading placeholder in the changelog. Leaves the diff staged; the author fills in the real changelog bullets, adds any accompanying code changes, and commits the release-prep commit by hand.
2. **`push-tag <X.Y.Z>`** — from a clean `main` at HEAD, pushes `main`, tags HEAD `vX.Y.Z`, pushes the tag, and invokes `release-notes` on the tag. Refuses if the working tree is dirty, HEAD isn't `main`, the Version header disagrees with the arg, the changelog has no matching heading, or that heading's block still contains `bump-version`'s `TODO:` placeholder.
3. **`release-notes <vX.Y.Z>`** — called by `push-tag`, but standalone-usable when CI's `build-zip` job overwrote the release body with GitHub's auto-generated notes (rerun it to restore the readme changelog body — idempotent).

Consequence: the release-prep commit must include a real `= X.Y.Z =` entry under `== Changelog ==` (not just the `TODO:` placeholder `bump-version` inserts), and it must land on `main` at HEAD before `push-tag`. Skipping any of the three steps leaves either the tag without release notes or the release page showing GitHub's auto-generated commit list instead of the curated changelog.
