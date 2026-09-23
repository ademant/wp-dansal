# Working on this repo

## Feature/change workflow

1. **Discuss first.** For a new feature or any non-trivial change, discuss the approach, scope, and tradeoffs with the user before writing code — don't jump straight to implementation.
2. **Create an issue once agreed.** After the user agrees on what to build, create a GitHub issue for it (`gh issue create`) instead of starting implementation. The issue records what was decided: title, description, and relevant context from the discussion.
3. **Wait for the go-ahead.** Creating the issue is not permission to implement it. Only start work when the user explicitly asks to (e.g. "implement issue #12", "let's build that now").
4. **Close on commit.** When implementing an issue, reference it in the commit message body with `Closes #<n>` (or `Fixes #<n>`) so GitHub auto-closes it once the commit lands on `main`. Closing **more than one** issue from a single commit: repeat the keyword on its own line per issue (e.g. a `Closes #12.` line followed by a separate `Closes #13.` line), not a comma-separated list (`Closes #12, #13`) — GitHub's keyword parser only reliably auto-closes the first issue in a comma list, silently leaving the rest merely referenced. After pushing, verify every issue the commit was meant to close actually did (`gh issue view <n> --json state`) and close any stragglers manually (`gh issue close <n> --comment "..."`).

Small, self-contained fixes the user directly asks for (typos, one-line bug fixes, config tweaks) don't need an issue first — this workflow is for features and non-trivial changes, not every edit.

## Issue triage: phase-* labels

For sequencing a batch of open issues, use the `phase-1`/`phase-2`/`phase-3` labels (created ad hoc, not a fixed enum — reuse or extend as needed):

- **`phase-1`** — ready to implement now, no blocking decision.
- **`phase-2`** — needs a decision (often paired with `needs-decision`) before any implementation starts.
- **`phase-3`** — blocked (e.g. on a future version-floor bump) or deliberately deferred.

When asked to plan/triage open issues, apply these labels to reflect the plan rather than only describing it in chat, so the labels stay the durable record of the sequencing decision.

## Version-log workflow

Every version tag on this repo must have a matching GitHub Release, so the repo's Releases page is the canonical version log for the plugin.

After `git push origin v<version>` completes as part of a "push and tag" flow, invoke the `release-notes` skill (`/release-notes v<version>`) as the very next step — before considering the release flow finished. The skill lifts the `= <version> =` block from `readme.txt`'s `== Changelog ==` section into the GitHub Release body, so the readme changelog and the release notes stay in sync automatically. It is idempotent: rerunning it on a tag whose release already exists updates the body in place.

Consequence: whenever you bump the version in `wp-dansal.php`/`readme.txt`, the release-prep commit must include a matching `= X.Y.Z =` entry under `== Changelog ==` in `readme.txt`. Without one, the `release-notes` skill refuses and the tag is left without release notes.
