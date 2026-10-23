# Inspection Record And Website Design Specification

The strengthening pass pinned Core fb43dea0ba9133319a21e6ea9e2ee3c6f7bc61f0
(17.0.18.56), Ringgroups 3b6f9a90c3fe56d27d460fdc74a4dda24390fecc
(17.0.2.8) and Framework 90929231b801d865556a57875ddd25a74d283f8d
(17.0.33.2). Core/PJSip/Sip write/read semantics and Framework Modules/Reload were
inspected at those revisions. See reconstruction-v2.md for exact method contracts,
the read-only snapshot tradeoff and compatibility gaps. None is a tested native
runtime version. The house-style material below was retained; the updated site
keeps its established styling and is rendered/checked locally again in this pass.

Inspected 22 October 2026, read-only. Repository baseline was clean branch
0-0-1_Dev at 147925b, containing only README and LICENSE. There was no repository
AGENTS.md or local toolchain/source/prototype implementation. Initial README lab
claims are retained as historical reports, not independently repeated evidence.

## Dial-Tone Is Not AI Dial-Tone

- `https://api.github.com/repos/kierknoby/dial-tone` resolved to public
  EgyptianEyes/Dial-Tone. Repository metadata and the public main README excerpt
  were inspected. It is a native Windows/MicroSIP/PJSUA softphone project, not this
  module's provider integration. No native executable was built or run here.
- `https://github.com/kierknoby/AI-Dial-Tone` was located separately; public README
  excerpts were inspected, including its distinction between open-source agent
  facilities and managed/provider-controlled telephony, synthetic calling and
  unverified PSTN/payment work. No private provider source was available or inferred.
- `https://ai.dial-tone.uk/` home HTML, public reference.css excerpts, rendered
  home, overview.html and faqs.html at 1440x1000 and 390x844 were inspected. Six
  local reference screenshots and computed H1 typography are retained under
  artifacts/reference/. Those pages returned 200 and no horizontal overflow in
  the measured viewports. A reference subpage/interaction audit beyond these
  pages is not claimed.

## Derived Design Specification

Technical-preview strip, compact dark navigation, high-contrast dark first band,
gold actions, light-grey/white editorial sections, numbered process explanations,
black code panels, native FAQ disclosure controls and a dense utility footer.
Use substantial technical explanations and separate implemented versus intended
capability. Keep all assets local, responsive stacking and visible keyboard focus.

The reference computed H1 stack was Inter/Arial/Helvetica/sans-serif, approximately
106.56px home desktop and 62.4px home mobile, with serif italic accents visible.
Respawn intentionally uses a smaller Georgia/Liberation Serif display hierarchy,
Trebuchet/Liberation Sans body and monospace engineering labels. It uses fixed
breakpoint font sizes, not viewport-scaled type or negative tracking. The reference
has a split mock call-session hero and decorative orbits; Respawn instead uses an
unframed raster reconstruction graph and no simulated management console on its
public home. Blue is reserved for a separate lifecycle band; no blue/gold mixing
on a single element. Focus on a gold action is white, not blue.

Palette: #c29200, #30beff, #808080, #dedede, #000000, #ffffff only. Primary text
uses black/white or black on light/gold/blue; grey is mainly dividers. British
English, no em dashes, no invented timings or generic production success claims.

## FreePBX Public Source Anchors

Read public release/17.0 snippets from FreePBX/firewall Firewall.class.php
(isEnabled/getTrustedZone), FreePBX/core Console/Trunks.class.php (Symfony CLI),
Core.class.php (addUser/getUser/listUsers), and FreePBX/ringgroups functions.inc.php
and Ringgroups.class.php (get/add/listRinggroups). Core getUser's incidental AstDB
writes ruled it out as a read-only exporter. Ringgroups get/add shaped the narrow
implemented slice. These branches are mutable: no exact runtime version was tested.

Private hosting/WHMCS/DOMAINTAINS/SBC APIs, controller internals, credentials and
earlier prototype code were unavailable. Integration requirements derive from the
user's explicit architecture, not unseen source. The neutral HTTPS profile is a
new public contract proposal implemented locally, not an assertion about private
endpoints. No private connector product name is used.

Respawn DNS did not resolve from this container during the initial public check.
No DNS, deployment or hosting configuration was modified.