=== Agent Bridge ===
Contributors: humanwebx
Tags: development, rest-api, tooling
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A guarded REST surface so an AI coding agent can read and write the source of plugins you nominate.

== Description ==

Plugin source cannot be reached over the core REST API, so an agent asked to fix
a bug on a site has no way to see the code. This plugin is that missing surface.

It is deliberately narrow:

* Only plugin folders an administrator ticks are reachable. Core, themes and
  every other plugin are invisible.
* Writes require both an application password belonging to an administrator and
  a separate secret header this plugin issues.
* PHP is syntax-checked before it is written; a file that does not parse is
  refused rather than written and left to fatal.
* Every overwrite and delete keeps a copy first, and can be undone.
* A write must carry the SHA-256 of the version the caller read, so a hand edit
  made in the meantime is never silently clobbered.
* Everything the bridge does is recorded with user, IP, path and hashes.

== Installation ==

1. Upload the `wp-agent-bridge` folder to `wp-content/plugins/` and activate it.
2. Go to Tools -> Agent Bridge and generate a bridge secret. Copy it; it is shown
   once.
3. Tick the plugins the bridge may read and write.
4. Create an application password for your administrator account under
   Users -> Profile.

== Frequently Asked Questions ==

= How do I turn writing off without deactivating? =

Add `define( 'AGENT_BRIDGE_READONLY', true );` to `wp-config.php`. Reads keep
working; every mutating route refuses.

= How do I switch the whole thing off? =

`define( 'AGENT_BRIDGE_DISABLE', true );` in `wp-config.php`. No routes are
registered at all.

= Can it edit itself? =

No. The bridge's own folder is refused by the resolver, not merely hidden in the
admin screen.

== Changelog ==

= 0.1.0 =
* First release.
