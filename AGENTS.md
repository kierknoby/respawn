# Contributor And Coding-Agent Rules

Read README and docs/architecture.md before modifying core. Keep executable,
module-by-module reconstruction central. Preserve GPLv3+ and original notices.
Keep provider services outside module core and use neutral provider terminology.
Never name private connector products, invent private APIs or include credentials.

Unless separately authorised, work only locally: no staging, commits, pushes,
branches, issues, PRs, tags, publishing, deployments, DNS changes or live PBX tests.
Inspect dirty state and preserve unrelated work. Keep README progress accurate.

Use supported FreePBX interfaces; do not edit generated Asterisk files. Exporters
must be read-only. Validate structured operations, dependencies and parameters;
never turn browser/exported data into arbitrary shell/dialplan. Reject unsupported
state, conflicts and unknown outcomes. Distinguish submission, acceptance,
configuration, readiness, routing preparation and activation. Never fake success.

Add focused synthetic tests before declaring behaviour. Run `sh tools/check.sh`
and `php tools/demo.php`; disclose unavailable checks. Mark mocks clearly and
keep all generated artefacts under ignored build/ or artifacts/. Use synthetic
addresses, identities and data only. Record exact live versions only when tested.

Website: static cPanel package, canonical respawn.in1.click, British English,
gold #c29200, blue #30beff, grey #808080, light grey #dedede, black and white only.
Never mix gold and blue on one element. Validate every page desktop/mobile,
keyboard navigation, FAQs, links, assets and extracted ZIP. Do not deploy.