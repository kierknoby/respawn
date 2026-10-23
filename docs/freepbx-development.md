# FreePBX 17 Development And Packaging

Target: FreePBX 17, PHP 8.2 or newer. Locally tested: Ubuntu 24.04.5 container,
PHP CLI 8.4.15. There is no FreePBX/Asterisk installation in this workspace; no
exact real framework/core/firewall/ringgroups version is tested. Public release/17.0
source was inspected, not pinned as a supported binary environment.

`module/` is the package source. `module.xml` declares Connectivity placement,
GPLv3+ and framework/PHP requirements. `Respawn.class.php` follows BMO conventions;
installation creates disabled local settings and an immutable random PBX identity.
Subsequent install/upgrade calls preserve settings. No remote actions run on install
or page view. Uninstall intentionally does not delete remote data or revoke services,
and preserves local evidence. Export it privately before any manual local cleanup.
Automatic restored identity reuse is rejected; replacement authorisation needs an
explicit provider enrolment/authority handoff, not cloning a source credential.

The page is `page.respawn.php`; CLI registration is `Console/Respawn.class.php`.
Settings use FreePBX getConfig/setConfig. State is below ASTVARLIBDIR/respawn and
must be writable privately by the normal FreePBX account. No root daemon or shell
subprocess is added. Reconstruction events are retained locally; integration with
FreePBX's central logger and asynchronous scheduler is still a gap.

The source-only Firewall adapter calls isEnabled() and getTrustedZone('trusted').
Missing, disabled or incompatible APIs fail explicitly. Export covers explicit
trusted host records only, not trusted interfaces/dynamic sources. /32 is the only
accepted CIDR. Fixture mode deliberately replaces this with documented synthetic
addresses. Tests do not prove Firewall runtime behaviour or SIP enforcement.

## Local Build

```sh
python3 -m pip install -r requirements-dev.txt
python3 -m playwright install chromium
sh tools/check.sh
XDEBUG_MODE=off php tools/demo.php
```

On a normal development host, Chromium's OS dependencies must be supplied by the
host administrator. This container used downloaded libraries extracted only under
build/browser-deps; set LD_LIBRARY_PATH as described in validation.md. No elevated
system package installation was performed. CI uses its ephemeral runner's normal
Playwright dependency installation; no workflow was triggered here.

The package tool copies the preserved original LICENSE into the archive, puts
module files under `respawn/`, and sets the optional wrapper executable in the tar.
The module is unsigned. Archive structure and PHP syntax are testable locally;
actual installability, FreePBX permission handling, menus, settings persistence,
uninstall/upgrade and fwconsole discovery remain integration gates, not passed tests.

Use an isolated, separately authorised lab and the reconstruction runbook before
considering a production provider adapter. Never suppress module-signature warnings
on a live PBX as a substitute for development acceptance.