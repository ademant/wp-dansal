---
name: bump-version
description: Apply a semver bump to a wp-dansal release-prep commit. Edits `wp-dansal.php` (Version header + `WPD_VERSION` constant) and `readme.txt` (Stable tag) with targeted edits (never `sed s/OLD/NEW/g`, which has bitten previous release preps by rewriting a past changelog heading), then inserts a fresh `= X.Y.Z =` heading placeholder in the changelog so the release-prep author can fill it in. Refuses if the target version already appears anywhere, or if the plugin file's current version doesn't parse. Takes the new version (`0.30.0` or `v0.30.0`); does not commit — leaves the diff staged for the human to review, add a real changelog entry, and commit.
---

# bump-version

The version-bump step of a release-prep commit, packaged as one careful skill. Three files need to change in lockstep:

- `wp-dansal.php` — the `Version:` header line and the `define( 'WPD_VERSION', '…' );` line.
- `readme.txt` — the `Stable tag:` line.
- `readme.txt` — a new `= X.Y.Z =` heading under `== Changelog ==`, above the previous version's heading, with a placeholder that the human replaces with real release-note bullets.

Doing this with `sed -i 's/OLD/NEW/g' …` has misfired twice: once rewriting a past `= OLD =` changelog heading into `= NEW =`, once because the file didn't have exactly the string sed expected. This skill uses targeted, line-scoped edits so it can't rewrite unrelated occurrences.

## When to run

- As the first step of a release-prep flow, before writing the changelog body and committing.
- User phrasing: **"bump to 0.30.0"**, **"prepare v0.30.0"**, **"cut a release-prep commit for 0.30.0"**.

Do **not** run this to fix a mistyped commit — a bad version bump should be reverted with `git checkout -- wp-dansal.php readme.txt` (if uncommitted) or a new corrective commit (if pushed).

## Inputs

One required positional argument: the new version. Both `v0.30.0` and `0.30.0` are accepted; the skill normalizes to `VERSION=0.30.0` internally.

## Procedure

1. **Refuse if the working tree already has uncommitted changes to `wp-dansal.php` or `readme.txt`.** `git status --porcelain wp-dansal.php readme.txt` must return empty. A partial in-progress bump plus this one could produce a broken state.

2. **Read the current version.** `make --silent version` (reads the plugin header) and `awk '/^Stable tag:/{print $NF}' readme.txt` must agree. Store as `OLD`. Refuse if they disagree — that's a pre-existing inconsistency worth fixing before bumping.

3. **Refuse if the target version is not strictly greater than the current one.** Compare with `sort -V`. Downgrades and same-version bumps are almost always mistakes.

4. **Refuse if the target `= VERSION =` heading already exists anywhere in `readme.txt`.** `grep -Fxq "= $VERSION =" readme.txt` must return non-zero. If it exists, the release-prep for that version is already in progress or has landed.

5. **Edit `wp-dansal.php`:**
    - Replace exactly the `Version:` header line: `sed -i -E "s/^( \\* Version: ).+$/\\1$VERSION/" wp-dansal.php` — anchored on `^ * Version: `, so it can only match the header line.
    - Replace exactly the `WPD_VERSION` define: `sed -i -E "s/^(define\\( 'WPD_VERSION', ').+(' \\);)$/\\1$VERSION\\2/" wp-dansal.php` — anchored on the full `define(` line.

6. **Edit `readme.txt`:**
    - Replace the `Stable tag:` line: `sed -i -E "s/^(Stable tag: ).+$/\\1$VERSION/" readme.txt`.

7. **Insert the new changelog heading.** Immediately after the `== Changelog ==` line (and its trailing blank line), prepend a block:

    ```
    = <VERSION> =
    * TODO: fill in this release's changelog bullets before running push-tag.

    ```

    Do this with `awk` in-place — the placement is deterministic (after line matching `^== Changelog ==` and the immediately-following blank), and the TODO text is a marker `push-tag` refuses on (see below), so an author who forgets to replace it can't ship a placeholder release.

8. **Verify.** Re-run `make --silent version` and `awk` on `Stable tag:` — both must equal `$VERSION`. `grep -Fxq "= $VERSION =" readme.txt` must succeed. If any check fails, restore the pre-edit state with `git checkout -- wp-dansal.php readme.txt` and refuse — a partial bump is worse than none.

9. **Print the diff summary** so the human can eyeball what changed before adding a real changelog entry and committing:
    - `git diff --stat wp-dansal.php readme.txt`
    - a reminder to replace the `TODO:` placeholder before invoking `push-tag`.

## Interaction with `push-tag`

The `push-tag` skill runs `grep -Fxq "= $VERSION =" readme.txt` in its precondition check. That heading exists after this skill runs — but the placeholder bullet still contains the `TODO:` marker. `push-tag` should refuse when the changelog block for its target version still has the `TODO:` marker on any line — that prevents shipping a release with unfilled notes. (The `push-tag` skill file above should be updated to add this check if it hasn't been already.)

## Failure modes worth handling

- Plugin file's Version header doesn't parse the way `make --silent version` expects → refuse and print the raw first 20 lines of `wp-dansal.php` for triage; do not attempt a fallback regex.
- `wp-dansal.php` and `readme.txt` disagree on the current version → surface both values and refuse. Fix the disagreement first (usually one file was hand-edited without the other).
- Target version's changelog heading already exists → refuse. If the target-version bump was aborted midway and left the heading in `readme.txt`, revert manually before rerunning.

## Not this skill's job

- Writing the real changelog bullets — that's the human's job, and `push-tag` refuses if the placeholder is still there.
- Committing — the diff is left staged for the human. Committing "bump to X.Y.Z" without the changelog body would be a broken release-prep commit.
- Tagging or pushing — that's `push-tag`, run after the release-prep commit lands on `main`.
