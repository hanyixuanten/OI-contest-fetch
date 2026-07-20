# AGENTS.md

Guidance for AI coding agents working in this repository.

## Project Shape

- `fetch_contests.py` is the data generator. It fetches contests, normalizes records with `platform`, `title`, `start_time`, `end_time`, `status`, and `url`, then writes JSON files in the repository root.
- `.github/workflows/deploy.yml` is the GitHub Actions automation. It installs Python dependencies, runs `fetch_contests.py`, and commits generated JSON changes.
- `server/index.php` is the serving/display layer. It reads the public raw `contests.json` and `contests_all.json`, caches them locally, and renders upcoming plus recently finished contests.
- User-facing documentation lives in [README.md](README.md) and [README_zh.md](README_zh.md). Keep both languages in sync.

## Actions vs Server Responsibilities

- Actions side: update contest data only. Change `.github/workflows/deploy.yml` when editing schedule triggers, Python version, dependency installation, generated files to commit, or commit/push behavior.
- Data-fetch side: change `fetch_contests.py` when editing providers, normalization, filtering, output JSON shape, or output file policy.
- Server side: change `server/index.php` when editing presentation, caching, raw JSON URL, platform labels/classes, countdown behavior, or PHP deployment behavior. The current page requires PHP 5.4 or newer.
- Server localization: `server/index.php` detects browser/system language and time zone, syncs them to cookies, and uses those cookies for first-render localized text and contest times.
- Do not put scraping logic in `server/index.php`; keep upstream fetching in `fetch_contests.py` so Actions can generate static JSON.

## Adding or Removing a Contest Provider

When adding a new contest provider, check all relevant surfaces:

- `fetch_contests.py`: add a `fetch_<provider>()` function, normalize records through `make_contest()`, add it to both output flows in `main()`, and preserve `status` semantics.
- `server/index.php`: add the platform name to `$platform_class` and define a matching `.platform.<class>` CSS rule if the provider appears in `contests.json`.
- `README.md` and `README_zh.md`: update supported platforms, data-source notes, and any changed usage/output details.
- `AGENTS.md`: update this guidance if the provider introduces a new pattern, dependency, output contract, or operational caveat.
- `.github/workflows/deploy.yml`: update only if the provider needs new Python dependencies, generated files, secrets, or schedule behavior.

When removing a provider, remove or adjust the same surfaces so stale platform labels, CSS classes, docs, and workflow dependencies do not remain.

## Output Contracts

- `contests.json` contains contests that have not ended yet: `upcoming` and `running`.
- `contests_all.json` contains contests that finished within the last 30 days.
- JSON timestamps are Unix timestamps in seconds.
- Keep record keys stable unless the README files and server rendering are updated in the same change.

## Validation

Do not proactively create a Python virtual environment unless the user asks for one. Run Python commands directly with `python`.

After changing fetching, filtering, output shape, provider support, or workflow-generated files, run:

```bash
python fetch_contests.py
```

Then inspect the generated files with `jq` when available, for example:

```bash
jq 'group_by(.platform) | map({platform: .[0].platform, count: length})' contests.json
jq 'group_by(.status) | map({status: .[0].status, count: length})' contests_all.json
```

For PHP-only presentation changes, also check that `server/index.php` still reads `contests.json` and handles unknown platforms with the default styling.

## Documentation Rule

Every feature addition, feature removal, provider change, output contract change, or workflow behavior change must update all three documentation files in the same change:

- [README.md](README.md)
- [README_zh.md](README_zh.md)
- [AGENTS.md](AGENTS.md)

Link to existing docs instead of duplicating long explanations. Keep agent guidance concise and operational.
