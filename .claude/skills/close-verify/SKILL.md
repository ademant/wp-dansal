---
name: close-verify
description: After pushing a commit that references issues with `Closes #N` / `Fixes #N`, verify each referenced issue actually went to `closed` state, and close any that didn't (GitHub's parser silently ignores comma-separated multi-close lists — see this repo's CLAUDE.md). Takes an optional commit ref (defaults to `HEAD`); scans its message for keyword references, polls `gh issue view N --json state` for each, and closes stragglers with a manual `gh issue close --comment "closed by <sha>"`. Idempotent — issues already closed are left alone. Invoke as the closing step of a "closes multiple issues" commit — e.g. "verify closes", "did those issues actually close?".
---

# close-verify

CLAUDE.md's feature-workflow rule requires that a commit closing multiple issues repeat the keyword on its own line per issue (`Closes #12.` then `Closes #13.`), because GitHub's parser silently drops all but the first entry in a comma-separated list like `Closes #12, #13`. This skill catches the miss: after such a commit has been pushed, it verifies each referenced issue actually reached `closed`, and closes the stragglers manually.

Small skill — often skippable if the commit's `Closes` lines are one-per and the push landed on the tracking branch (which the auto-closer respects). But cheap enough to run on every multi-close commit, and the failure mode it catches is silent.

## When to run

- Right after pushing a commit whose message references two or more issues via `Closes`/`Fixes`. GitHub's auto-closer runs on the push to the default branch, so give it a few seconds first (a short `sleep 3` before the first `gh issue view` is fine).
- User phrasing: **"verify closes"**, **"did those issues auto-close?"**, **"check closes for HEAD"**.

Do **not** run this on commits that reference issues via bare `#N` (no keyword) — those aren't intended to auto-close, and this skill would misidentify them.

## Inputs

One optional positional argument: the commit ref (any git-resolvable form — `HEAD`, a sha, a tag). Defaults to `HEAD`.

## Procedure

1. **Resolve the ref.** `git rev-parse --verify --quiet <ref>` must succeed. Refuse if not.

2. **Read the commit message.** `git log -1 --format=%B <ref>`. Store as `$MSG`.

3. **Extract the issue numbers referenced with a close keyword.** GitHub's keywords are `close|closes|closed|fix|fixes|fixed|resolve|resolves|resolved`, case-insensitive, followed by `#N`. Extract with an anchored grep:

    ```bash
    ISSUES=$(printf '%s\n' "$MSG" \
      | grep -oiE '(close[sd]?|fix(e[sd])?|resolve[sd]?)[[:space:]]+#[0-9]+' \
      | grep -oE '#[0-9]+' \
      | tr -d '#' \
      | sort -u)
    ```

    Refuse (with an explanatory message, not an error) if `$ISSUES` is empty — nothing to verify.

4. **For each issue number:**
    - `gh issue view $N --json state --jq .state` — expect `CLOSED` or `OPEN`.
    - If `CLOSED`, print `#N: OK` and continue.
    - If `OPEN`, print `#N: STILL OPEN` and close it: `gh issue close $N --comment "Closed by <shortsha> (auto-closer missed a comma-separated Closes list; see CLAUDE.md)."` where `<shortsha>` is `git rev-parse --short <ref>`.
    - If `gh` returns any other state (or an error): print the raw response and continue with the next issue — don't let one bad row block the rest.

5. **Print a summary** at the end: `N issues referenced, K needed manual close`. Non-zero `K` is a signal that the commit's `Closes` lines should be reformatted before the next multi-close commit — flag that in the summary too.

## Failure modes worth handling

- `gh` not authenticated → propagate the gh error verbatim; don't retry.
- Referenced issue doesn't exist (typo in the commit message) → `gh issue view` errors with `not found`; print `#N: NOT FOUND` and continue.
- Cross-repo references (`ademant/dansal#123`) → out of scope. The regex above only matches bare `#N`. This skill only touches the current repo. If a cross-repo close is intended, GitHub handles it via the `owner/repo#N` syntax and this skill just skips it.
- Rate limit → `gh` returns a rate-limit error; propagate.

## Not this skill's job

- Amending or rewriting the offending commit message. The auto-closer already fired (or didn't) on the push — rewording the commit later has no effect on issue state. This skill is a corrective, not a preventive.
- Verifying single-issue commits. A commit with one `Closes #N` line is reliably auto-closed; running this skill on it is harmless but adds no signal. If invoked on such a commit, it'll simply print `#N: OK` and exit.
- Cross-repo issue verification — same-repo only.
