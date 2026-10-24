# cPanel Website Package

The site is static HTML/CSS/JavaScript. Deployment needs no Node process, PHP,
containers, shell access or background services. Python/Pillow are optional local
build dependencies only. The canonical domain is https://respawn.in1.click/.
No DNS or hosting change was made. DNS did not resolve from this environment when
checked; the package can still be inspected locally.

## Build And Preview

```sh
python3 tools/build_site.py
python3 tools/package.py
python3 tests/site.py
```

Open `build/site/index.html` directly in a browser; relative links work without a
development server. The verification script temporarily serves only extracted ZIP
contents on loopback, checks eleven pages plus 404 at desktop, mobile and
narrow-mobile widths, then shuts down. The package includes the complete welcome
note and the dedicated Why Respawn? page. Golden-copy checks preserve both texts
with the approved telecoms terminology; internal fragments and every FAQ are
checked as well.
Screenshots are in `artifacts/screenshots/`; the validation JSON is local only.

## Upload Procedure For A Separately Authorised Deployment

1. Confirm the actual respawn.in1.click document root in cPanel Domains with the
   hosting operator. Do not assume public_html or any inherited Apache configuration.
2. Retain a dated private backup of existing site files outside the public document
   root and record permissions. Do not overwrite unrelated files or .htaccess rules.
3. Upload `artifacts/respawn-site-0.1.1.zip` via File Manager to an appropriate
   staging directory. Extract and inspect it before replacing the intended site.
   `index.html`, other HTML files, `.htaccess`, `assets/`, robots.txt and sitemap.xml
   belong directly at the archive root, with no extra site/ directory level. The
   package mapping is `ErrorDocument 404 /404.html`; merge that directive with any
   existing host-managed `.htaccess` rather than replacing unrelated rules.
4. Inspect preview access using the host's supported mechanism. Confirm all page
   links, menu, keyboard focus, FAQs, assets and code blocks. Verify HTTPS and the
   intended canonical host before enabling public access. Never upload module state,
   demo blueprints, credentials or screenshots containing private data.
5. After an authorised deployment, test direct .html URLs, robots/sitemap and an
   unknown URL. The generated 404.html has a dedicated title/description/social
   metadata, no canonical, noindex,follow and useful navigation. The archive ships
   the minimal Apache ErrorDocument mapping; verify the host permits it and that an
   unknown path returns status 404 with this body. If Apache overrides are disabled,
   configure the cPanel Error Pages setting instead. Host-specific mapping remains
   unverified. No rewrite, header or other Apache modules are assumed.

## Updates And Rollback

Build and validate a fresh ZIP, compare checksums and retain the previous version.
Replace only package-owned files. Remove obsolete package-owned pages after checking
links; do not delete host-managed files. For rollback, restore the last tested file
set and any independently managed routing/header configuration. Clear only caches
you control. Publishing and DNS changes require separate explicit permission.

HTTP security headers are host configuration, not guaranteed by static HTML.
Recommended CSP allows self scripts/styles/images, no objects or framing; verify
host support and inherited headers before adding rules. There are no external font,
analytics or script dependencies and no site data submission. Module GPLv3+ source
is linked publicly; external service terms remain separate.