---
name: push-tag
description: End-to-end publish a wp-dansal release from a local release-prep commit already on `main` at HEAD — pushes `main`, creates the annotated tag `vX.Y.Z` at HEAD, pushes the tag, and invokes the `release-notes` skill so the GitHub Release picks up the readme changelog body. One shot instead of four manual git+gh commands. Takes the target version (`0.30.0` or `v0.30.0`); refuses if the working tree is dirty, HEAD isn't `main`, the wp-dansal.php Version header disagrees with the arg, or the readme.txt changelog has no `= X.Y.Z =` entry (which would leave `release-notes` refusing on the next step anyway). Invoke as the closing step of a release-prep flow — e.g. "push and tag v0.30.0", "release 0.30.0", "ship this".
---

# push-tag

Batches the mechanical push/tag/push-tag/release-notes sequence into one skill so a release can't skip a step or fire them out of order. It's the "publish" half of a release — the "prepare" half (version bump, changelog entry, commit) is either `bump-version` or a hand-written commit, and happens before this.

## When to run

- Immediately after a release-prep commit is on `main` at HEAD, and you're ready to publish it.
- User phrasing: **"push and tag vX.Y.Z"**, **"release X.Y.Z"**, **"ship this"** when the release-prep commit is the last commit on the branch.

Do **not** run this to push a working-in-progress commit — the safety checks below will refuse.

## Inputs

One required positional argument: the target version. Both `v0.30.0` and `0.30.0` are accepted; the skill normalizes internally to both `TAG=v0.30.0` and `VERSION=0.30.0`.

## Procedure

1. **Refuse if the working tree isn't clean.** `git status --porcelain` must return empty. Uncommitted changes mean the release-prep commit isn't finished; publishing part-way is a hazard.

2. **Refuse if HEAD isn't `main`.** `git rev-parse --abbrev-ref HEAD` must equal `main`. Tag pushes on other branches would surprise the maintainer.

3. **Refuse if HEAD isn't fresh with origin/main.** If `git rev-list --count origin/main..HEAD` is 0, there's nothing to push and the tag already exists on the remote or was never created — call it out and stop.

4. **Refuse if the target tag already exists locally.** `git rev-parse --verify --quiet refs/tags/<TAG>` finding it means an earlier attempt was left half-done — direct the user to `git tag -d <TAG>` and rerun.

5. **Verify the plugin's stated version matches the arg.** Use the Makefile's `version` target (`make --silent version`) — it reads the Version header the same way CI's `build-zip` job does, so what this skill checks is exactly what CI will re-check on the tag push. Mismatch → refuse and print both values.

6. **Verify the readme changelog has a `= VERSION =` heading.** `grep -Fxq "= $VERSION =" readme.txt`. This is the precondition for the `release-notes` skill; catching it here is friendlier than pushing a tag whose release-notes call then refuses. If missing, refuse and tell the user to add the entry to `readme.txt`'s `== Changelog ==` section (or run `bump-version` if that's what they meant to do first).

    Additionally, check that the block for `= VERSION =` no longer contains the `TODO:` placeholder that `bump-version` inserts. Refuse if it does — that means the release-prep commit was made without filling in the changelog bullets:

    ```bash
    awk -v v="$VERSION" '$0 == "= " v " =" { grab=1; next } grab && (/^= [0-9]+\.[0-9]+\.[0-9]+ =/ || /^== /) { exit } grab && /TODO:/ { print; found=1 } END { exit !found }' readme.txt
    ```

    If that succeeds (exit 0), a TODO marker was found — refuse. Point the user at the offending line so they can replace it with real release notes and amend or add a follow-up commit before pushing.

7. **Push `main`** — `git push origin main`. If this fails (e.g. non-fast-forward because someone landed on main since the local branch was fetched), don't proceed; the user needs to rebase.

8. **Tag HEAD.** `git tag <TAG> <HEAD-sha>`. Local-only for now; step 9 pushes it.

9. **Push the tag.** `git push origin <TAG>`. This is what triggers CI's tag-scoped `build-zip` job, which builds the release zip and, via `softprops/action-gh-release@v2`, creates the GitHub Release with auto-generated notes.

10. **Invoke the `release-notes` skill** with the tag as argument. That overwrites CI's auto-generated notes with the readme changelog body — idempotent, safe to rerun.

11. **Print the resulting GitHub Release URL and the pushed commit sha** so the user has both for follow-up (issue links, release announcements, etc.).

## Failure modes worth handling

- Not in a git repo, or not in the wp-dansal working tree → surface the missing tool/path error verbatim.
- `gh` not authenticated → the `release-notes` skill's own gh error surfaces on step 10; step 9's `git push` still works.
- Tag already exists on origin but not locally → step 4's local check passes; step 9's push fails with "reference already exists". Suggest fetching tags and picking a new version.
- Network error mid-flight (main pushed, tag push failed) → the release-prep commit is safe on the remote; rerun the skill with the same version to complete.
- CI's release-publish overwrites the notes → known race; step 10 restores them. The `release-notes` skill's own "when to run" section covers this — no special handling here.

## Not this skill's job

- Bumping the version in `wp-dansal.php` / `readme.txt` — that's `bump-version` (or a hand-written commit) and must land before this skill runs.
- Writing the changelog entry — that's part of the release-prep commit, checked in step 6.
- Creating the GitHub Release itself — CI's `build-zip` job does that on tag push; this skill just makes sure the notes carry the readme changelog.
- Force pushing, amending, or deleting tags — a failed run leaves the user in a recoverable state; the skill never overwrites history.
