# Working on this repo

## Feature/change workflow

1. **Discuss first.** For a new feature or any non-trivial change, discuss the approach, scope, and tradeoffs with the user before writing code — don't jump straight to implementation.
2. **Create an issue once agreed.** After the user agrees on what to build, create a GitHub issue for it (`gh issue create`) instead of starting implementation. The issue records what was decided: title, description, and relevant context from the discussion.
3. **Wait for the go-ahead.** Creating the issue is not permission to implement it. Only start work when the user explicitly asks to (e.g. "implement issue #12", "let's build that now").
4. **Close on commit.** When implementing an issue, reference it in the commit message body with `Closes #<n>` (or `Fixes #<n>`) so GitHub auto-closes it once the commit lands on `main`.

Small, self-contained fixes the user directly asks for (typos, one-line bug fixes, config tweaks) don't need an issue first — this workflow is for features and non-trivial changes, not every edit.
