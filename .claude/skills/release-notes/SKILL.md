---
name: release-notes
description: Publish or update a GitHub Release for a wp-dansal version tag, using the matching changelog entry from readme.txt as the release body. Invoke this after every `git push origin vX.Y.Z` (or when the user says "release notes for vX.Y.Z" / "publish release vX.Y.Z"). Takes an optional tag argument; defaults to the newest `v*` tag in the repo. Idempotent — running it again for a tag whose release already exists updates the release instead of erroring.
---

# release-notes

Every wp-dansal version already ships its changelog inside `readme.txt` (WordPress plugin-directory convention). This skill lifts that same text into a GitHub Release, so the tag and the release notes stay in sync automatically, and the repo's Releases page becomes a browsable version log without extra bookkeeping.

## When to run

- Right after `git push origin v<version>` — the closing step of the "push and tag" flow (see this repo's `CLAUDE.md`, "Version-log workflow").
- When the user asks for release notes for a specific tag by name.
- When a tag's release exists but its body is out of date (e.g. `readme.txt` was corrected after tagging) — rerunning updates the body in place.
- **After the CI `build-zip` job has finished on a tag push.** That job uses `softprops/action-gh-release@v2` with `generate_release_notes: true`, which can overwrite this skill's changelog-based body with commit-based auto-notes on the release GitHub-side create/edit. If the release body ever looks like GitHub's auto-generated "What's Changed" list instead of the readme changelog, rerun this skill to restore it (idempotent — same URL, body replaced in place).

Do **not** run this on tags that don't correspond to a `readme.txt` changelog entry (early plugin tags before the changelog convention landed). The skill will refuse and say so.

## Inputs

One optional positional argument: the tag to publish, e.g. `v0.17.0`. The `v` prefix is accepted with or without.

If omitted, the skill uses the newest tag matching `v[0-9]*.[0-9]*.[0-9]*` in the local repo (`git tag --list --sort=-v:refname 'v[0-9]*.[0-9]*.[0-9]*' | head -1`). Print which tag you resolved before proceeding, so the user can course-correct if it's the wrong one.

## Procedure

1. **Resolve the tag.** Normalize the argument to a `vX.Y.Z` form. Confirm the tag exists locally (`git rev-parse --verify refs/tags/<tag>`) and on origin (`git ls-remote --tags origin <tag>`) — if only local, tell the user to push it first rather than creating a release ahead of the ref.

2. **Extract the changelog body from `readme.txt`.** The version heading looks like `= X.Y.Z =` (no `v`). Body is every line after that heading until the next `= X.Y.Z =` heading or the next `== Section ==` heading, trimmed of trailing blank lines. If no matching heading exists, refuse — the release body is the plugin's changelog and there's nothing to publish without one.

   Use `awk` for the extraction (portable, no jq dependency for readme text). A working pattern:

   ```bash
   VERSION="0.17.0"  # without leading v
   awk -v v="$VERSION" '
     $0 == "= " v " =" { grab=1; next }
     grab && (/^= [0-9]+\.[0-9]+\.[0-9]+ =/ || /^== /) { exit }
     grab { print }
   ' readme.txt | sed -e '/./,$!d' -e :a -e '/^$/{$d;N;ba' -e '}'
   ```

3. **Append a compare link.** Add a trailing `**Full Changelog**: https://github.com/ademant/wp-dansal/compare/<prev>...<tag>` line, where `<prev>` is the tag immediately below `<tag>` in a descending semver sort. A working awk pattern that handles the edge cases (`<tag>` is the oldest → no prev → omit the line; `<tag>` isn't in the tag list → same):

   ```bash
   PREV=$(git tag --list --sort=-v:refname 'v[0-9]*.[0-9]*.[0-9]*' \
     | awk -v t="$TAG" '$0 == t { seen=1; next } seen { print; exit }')
   [ -n "$PREV" ] && printf '\n**Full Changelog**: https://github.com/ademant/wp-dansal/compare/%s...%s\n' "$PREV" "$TAG" >> "$BODY"
   ```

4. **Publish or update.** Check whether the release already exists: `gh release view <tag> --json tagName 2>/dev/null`. If it does, run `gh release edit <tag> --notes-file <tmpfile>` (never `--notes "$(cat …)"` — the body may contain backticks/dollar signs that shell-expand). If it doesn't, run `gh release create <tag> --title "<tag>" --notes-file <tmpfile>`.

5. **Do not attach binaries.** The `make zip` release artifact is uploaded by the `build-zip` CI job in `.github/workflows/ci.yml` after every tag push — this skill only publishes the notes. If CI has already run and attached the zip, `gh release edit` on the same tag leaves the assets untouched.

6. **Print the release URL** at the end so the user can eyeball the result: `gh release view <tag> --json url --jq .url`.

## Failure modes worth handling

- Tag exists locally but not on origin → tell the user to `git push origin <tag>` first; don't create a release for an unpushed tag.
- `readme.txt` has no `= X.Y.Z =` entry for the tag → refuse and say the changelog needs to be filled in before releasing.
- `gh` not authenticated → surface the `gh` error verbatim; don't retry with a workaround.
- Non-fast-forward release update: `gh release edit` never conflicts with anyone, so no special handling needed.

## Not this skill's job

- Creating the tag (`git tag`), pushing it (`git push origin <tag>`), or bumping versions in `wp-dansal.php` / `readme.txt` — those happen in the commit that prepares the release, not here.
- Writing the changelog entry — that lives in `readme.txt` under `== Changelog ==` and is part of the release-prep commit.
- Attaching build artifacts — CI does that.
