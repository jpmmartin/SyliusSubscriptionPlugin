# Releasing

This package is installed with a caret constraint, so its version number is a promise to every
store that depends on it. This file says who makes that promise, what it means, and how a release
is cut.

## Who tags

**The maintainer of this repository, and nobody else.** There is one, and releases are not
automated: a tag is pushed by hand, after the checks below pass. Contributors open pull requests;
merging one does not release anything.

## What the version number promises

The commit type decides the next number, and
[Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) is how it is declared:

| Commits since the last tag | Next release |
|---|---|
| `fix:` only | PATCH — 1.0.0 → 1.0.1 |
| any `feat:` | MINOR — 1.0.0 → 1.1.0 |
| any `!` or `BREAKING CHANGE:` footer | MAJOR — 1.0.0 → 2.0.0 |

## What counts as a breaking change

In this package, and only these:

- A change to a **public interface, service id or DI tag** a store could be decorating or replacing:
  the ones the README documents, such as `RenewalChargerInterface`, `RetryPolicyInterface`,
  `MissedCyclePolicyInterface`, `CycleGateInterface`, `TrialEligibilityInterface` and
  `SubscriptionCommitmentInterface`.
- An **entity or schema change whose migration is not backward compatible** — anything a store
  cannot roll forward without downtime or data loss.
- A change to the **configuration** under `jpm_martin_sylius_subscription`: a key removed or renamed,
  or a default that makes an existing store behave differently.
- A change to an **event** a store listens to: its class renamed or removed, or a property removed or
  renamed. A property added, with a default, is not breaking.
- A change to the **shop API** that removes or renames an operation, or a field of what it takes or
  answers. A field added is not breaking.
- A change to the **workflows** `jpm_martin_sylius_subscription` and
  `jpm_martin_sylius_subscription_cycle`: a state or a transition removed or renamed.

A change that is any of those requires all three of:

1. `!` after the type or scope in the commit subject — `feat(renewals)!: …`;
2. a `BREAKING CHANGE:` footer in that commit explaining **what a store must do to upgrade**, not
   merely what changed;
3. an entry under `### Changed` or `### Removed` in the changelog repeating that migration note.

A breaking change without a migration note is not releasable. The footer is the only place a store
finds out what to do, and by the time they read it the upgrade has already failed.

## Cutting a release

1. Both workflows pass on `main`: Build, on PHP 8.2 and 8.4 and with the migrations on MySQL, and
   Install, on a store installed from the README (`gh workflow run install.yaml --ref main` when no
   change since the last run started it).
2. Move the `## [Unreleased]` section of `CHANGELOG.md` under the new version heading with today's
   date, and open a fresh empty `## [Unreleased]`. Update the link definitions at the foot of the
   file.
3. Commit that as `docs: release X.Y.Z` and push it. A push that only changes Markdown files does not
   start the CI, so start it by hand on that commit — `gh workflow run build.yaml --ref main` — and
   wait for the run to pass.
4. Tag it — `git tag -a vX.Y.Z -m "X.Y.Z"` — and push the tag.
5. Create the GitHub release of the tag, with that version's section of the changelog.
6. Packagist publishes from the tag. There is nothing to upload.

## The development branch alias

`composer.json` aliases `dev-main` to the next minor version, `1.1-dev` after 1.0.0, so a store can
follow the branch under a `^1.0@dev` constraint. The release commit of a minor or major version moves
it on: to `1.2-dev` with 1.1.0, to `2.1-dev` with 2.0.0.
