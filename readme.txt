=== Admin Menu Swap ===
Contributors: rubberplants
Tags: admin menu, reorder, drag and drop, hide, separators
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reorder the WordPress admin menu with drag and drop, hide items you never use, and add separators to group menu items.

== Description ==

Admin Menu Swap adds a **Settings → Menu Order** page where the current top-level admin menu is shown as a drag and drop list. Drag items into any order, hide the ones you never use, drag separators around, remove them, or add new ones to group related items — then save. The result applies to all users (each user only sees the items their role allows, in your saved order), unless a role has a menu profile of its own.

* Drag & drop reordering of all top-level menu items.
* Show/hide toggle per item — hidden items keep their place in the list, so switching one back on returns it to where it was.
* Separators are draggable too, can be removed, and new ones can be added.
* Pick a color for the menu separators, previewed live in the admin menu.
* One click "Reset to default menu" (or "Reset to preset").
* Presets: group rules that order a menu the plugin has never seen, including items of plugins activated later (filter `amswap_preset`, see `includes/class-amswap-preset.php`; an example ships in `presets/`).
* Menu profiles per role: each role (Administrator, Editor, Shop Manager, ...) can hide its own items and follow the default order or have its own. Agency accounts (an email domain, default rubberplants.nl, or picked administrators) always get the default menu and are the only ones who can open the settings page.
* Abilities for MCP clients and the Abilities REST API (WordPress 6.9+): `amswap/get-menu`, `amswap/update-menu-order`, `amswap/reset-menu-order`.
* WP-CLI: `wp amswap list`, `wp amswap refresh`, `wp amswap reset`, `wp amswap agency`.
* Can be built into a site plugin: put a copy in its own `admin-menu-swap/` folder inside the site plugin (`site-plugin/admin-menu-swap/admin-menu-swap.php`) and require that file. When several copies are present, the newest one runs.
* Without a preset, nothing changes until you save. The plugin does keep a small snapshot of the menu (option `amswap_menu_snapshot`) whenever an administrator opens wp-admin, so the abilities and WP-CLI know which items exist.
* Uses the WordPress core `menu_order` filter — cooperates with, rather than overwrites, the admin menu.

== Installation ==

1. Download `admin-menu-swap.zip` from the latest release (https://github.com/Rubberplants-Digital-Agency/Admin-Menu-Swap/releases/latest), or with WP-CLI: `wp plugin install https://github.com/Rubberplants-Digital-Agency/Admin-Menu-Swap/releases/latest/download/admin-menu-swap.zip --activate`.
2. Upload it under Plugins → Add New → Upload Plugin and activate it.
3. The Rubberplants preset orders the menu right away. Go to **Settings → Menu Order** to change it.

Updates come from the GitHub releases and show under Dashboard → Updates like any other plugin's, auto-updates included. Install only from the `admin-menu-swap.zip` release asset, so the plugin sits in a folder named `admin-menu-swap`: GitHub's "Source code" zips unpack to another folder name (such as `Admin-Menu-Swap-main`) and do not get updates.

== Frequently Asked Questions ==

= What happens when I activate a new plugin after saving an order? =

Its menu items (and its own separators) appear at the bottom of the menu until you visit Settings → Menu Order and save again, which bakes them into your order. While a preset applies (nothing saved), the new items are grouped by the preset straight away.

= How do updates work? =

The plugin header names the GitHub repository as its Update URI, so WordPress asks the plugin, not wordpress.org, whether a new version exists. The plugin looks up the latest release (cached for 12 hours; "Check again" under Dashboard → Updates skips the cache) and offers its admin-menu-swap.zip. Releases are published by tagging v<version> on main; a GitHub Action builds the zip.

Each release carries two files built by that Action: admin-menu-swap.zip and info.json, with the required WordPress and PHP versions, the tested WordPress version and the changelog that "View details" shows. A release without a valid info.json, or with files not uploaded by the Action, is not offered. Only the plugin in `wp-content/plugins/admin-menu-swap/` is updated, never a copy built into another plugin.

To check for updates the plugin sends GitHub nothing but the user agent `Admin-Menu-Swap/<version>`: no site URL or other site data. GitHub does see the server's IP address, as with any request.

= Can I switch the built-in preset off? =

Yes: `add_filter( 'amswap_use_default_preset', '__return_false' );` leaves the WordPress menu as it is until you save an order. Return your own preset from the `amswap_preset` filter to replace it.

= Who sees which menu? =

Agency accounts get the default menu. Everybody else gets the profile of their highest role that has one (Administrator before Shop Manager before Editor and so on), or the default menu when none of their roles has a profile. A profile hides items on top of what the default menu hides. Pick a profile at the top of Settings → Menu Order to edit it; the page shows who it applies to and which items the role cannot open anyway.

= What is an agency account? =

An administrator whose email address is on an agency domain (default rubberplants.nl), or who is ticked under Agency accounts on the settings page (or added with `wp amswap agency add <user>`). As long as no administrator qualifies, every administrator counts as one, so nobody is ever locked out of the settings page. The page refuses a change that would take away your own access.

On a multisite, super admins always are agency accounts, so the "every administrator counts" fallback does not apply there: a subsite's own administrators only become agency accounts by email domain or by being ticked.

Agency accounts keep the menu settings out of clients' everyday reach. They are not a security boundary against other administrators: any administrator can add a user on an agency domain, change an email address or deactivate the plugin. Switch the default domain off with `add_filter( 'amswap_agency_domains', '__return_empty_array' );`.

= How do presets and the saved order work together? =

A preset orders the menu as long as nothing is saved. The settings page shows the preset's result; saving there turns it into your own order, which the preset no longer touches. "Reset to preset" deletes the saved order and the preset applies again.

The preset's role profiles work per role: each applies until that role's own profile is saved, even when the default menu is saved. With the built-in preset this means that on a site with an agency account, other administrators do not see the developer and WPMU DEV items (such as Plugins, Tools and Settings) until you save the Administrator profile the way you want it. The pages stay reachable by their URL.

= Why does "wp amswap refresh" need --exec? =

Plugins only register their admin menus in wp-admin, so WordPress has to load as if it were wp-admin: `wp amswap list --refresh --exec='define( "WP_ADMIN", true );' --user=<administrator>`. WP-CLI's own `--context=admin` builds the menu incompletely, so the command refuses it. Without a refresh the commands and abilities read the snapshot from the last time an administrator opened wp-admin.

= The plugin and a site plugin both contain Admin Menu Swap. Which one runs? =

The newest version; with equal versions, one of them. Copies older than 1.3.0 always run themselves, and a 1.3 copy then steps aside. Activating a pre-1.3 copy while a 1.3 copy is running fails safely (WordPress reports a fatal error and leaves it inactive): update that copy instead.

A built-in copy must sit in its own folder two levels deep (`site-plugin/admin-menu-swap/admin-menu-swap.php`). WordPress does not list a file that deep as a plugin, so it never tries to update or delete it on its own.

= What does uninstalling remove? =

Deleting the plugin removes its saved menu order, profiles, agency settings and menu snapshot. While another active plugin on the site has Admin Menu Swap built in, nothing is removed, because that copy still uses these settings. On multisite, each subsite where such a plugin is active keeps its settings. Version 1.6.1 or later notes a built-in copy the first time an administrator opens that subsite's dashboard, so after updating, open the dashboard of each such subsite once before you uninstall from the network admin.

= What do the abilities share with an MCP client? =

`amswap/get-menu` returns the menu and the number of users each profile applies to. User logins are personal data the menu does not need, so they are only included when you pass `include_users: true`. `amswap/update-menu-order` overwrites the profile's saved order (and its hidden items when you send them); to undo it, send the previous settings again, so get them first with `amswap/get-menu`. Because it is marked destructive, the Abilities REST API runs it with the DELETE method.

= Does it work on multisite? =

The order is saved per site and only affects the regular site admin menu. The network admin menu is never touched.

= Does hiding an item stop people from opening it? =

No, and it is not meant to. Hiding takes the item out of the admin menu; the page behind it stays reachable by its direct URL, by bookmarks and by any link pointing at it. Treat it as decluttering, not as a permission. Use user roles and capabilities if you need to actually restrict access.

= Why can't I hide Settings? =

Because this plugin's own page lives under it. Hiding Settings would leave no route back to the toggle that undoes it, so the Settings row is locked.

= What happens to a hidden item's position? =

It is kept. Hidden items stay in the list on the Menu Order page (dimmed and struck through) and hold their place in your saved order, so switching one back on drops it exactly where it was.

= Can it rename menu items? =

No. Admin Menu Swap changes order, visibility and separators, on purpose.

= How does the separator color work? =

WordPress draws each menu separator as an invisible line. Pick a color under Settings → Menu Order and every separator in the admin menu becomes a thin 1px line in that color. Clear the color to go back to the admin color scheme's default.

= Another plugin also reorders the admin menu — who wins? =

Admin Menu Swap registers its `menu_order` filter at a very late priority, so its saved order wins. A plugin that force-disables `custom_menu_order` at an even later priority would disable sorting entirely; that is rare.

== Changelog ==

= 1.6.2 =
* Each role's tab counts the users with that role, also while the role has no profile yet and gets the default menu. The default tab counts the agency accounts and names the roles that use it.
* A role's list shows the menu as that role gets it: items the role cannot open (by the item's own capability or any of its pages) are folded away behind a button, and Users shows as Profile for roles that get Profile instead.
* Separators the real menu leaves out (leading, doubled or trailing) are dimmed in the list.
* Hiding and ordering Users now also apply to Profile, and an item core leads to another page for a role keeps its place in the order.
* A notice names the file of an old copy (before 1.3) that runs in the place of the installed version.

= 1.6.1 =
* Updates are only offered to the plugin in the `admin-menu-swap` folder, never to a copy built into another plugin (updating such a copy could delete that plugin's folder).
* Update checks no longer send the site's URL to GitHub, and time out after 3 seconds in wp-admin.
* "View details" shows the full changelog and the real required and tested versions, read from a new info.json release asset.
* Only release files uploaded by the release workflow are installed. Releases are only built from version tags on main.
* Multisite: subsite administrators no longer all count as agency accounts when none of them qualifies; super admins always do.
* Whether an agency account exists no longer depends on the first 200 administrators, and the answer is cached for an hour.
* Picked agency accounts are no longer dropped when the agency form is saved on sites with many administrators.
* Role profiles whose key has capitals (such as Shop_Support) can be edited. An unknown profile in the URL shows a warning, and saving or resetting a profile that no longer exists is refused instead of changing the default menu.
* On sites with more than 500 dashboard users, profile counts are shown as a lower bound (such as "37+").
* Abilities: `amswap/get-menu` leaves user logins out unless `include_users` is true. `amswap/update-menu-order` is marked destructive (DELETE over REST) and no longer fails on items of deactivated plugins that a profile already holds.
* WP-CLI: `wp amswap agency add/remove` reads only digits as a user ID, falls back to the login and the email, and names the accounts it changed.
* The menu snapshot is no longer rewritten on every page when administrators use different languages.
* Uninstalling keeps the settings while a copy older than 1.3 is active, and on multisite keeps them on subsites where a built-in copy is active.
* Translations load when the plugin is built into a must-use plugin.
* Fixed the arrow on the Agency accounts section.
* Added the GPL-2.0 license text.

= 1.6.0 =
* The Rubberplants preset is now built in and applies by default (switch it off with the amswap_use_default_preset filter). Its role profiles also apply on sites that already saved a default menu: other administrators than agency accounts no longer see the developer and WPMU DEV items until their profile is saved.
* Updates from GitHub releases through WordPress' Update URI: new versions show under Dashboard → Updates, with "View details" and auto-updates.
* Releases are built by a GitHub Action from a tag v<version>.

= 1.5.2 =
* Fixed: rows could not be dragged with the mouse. jQuery UI ignored presses on the handle because it is a button.
* Colored separators are now a thin 1px line with a little space above it, instead of a 2px bar.
* Added a Dutch (nl_NL) translation and a .pot template. The settings page is now called "Menu Volgorde" in Dutch.

= 1.5.1 =
* The profile tabs and "Who sees this menu" count only users who can open the dashboard: with WooCommerce, users with edit_posts, manage_woocommerce or view_admin_dashboard, so a shop's customers no longer fill the list (filter: amswap_dashboard_caps).
* The tab shows the real number of users; the list names the first 50 and says how many more there are.

= 1.5.0 =
* New settings page: the menu is edited as a copy of the admin menu itself (dark, with the real icons, slugs and separator color), next to a sidebar with who sees the profile, the separator color, saving and the agency accounts.
* Profiles are tabs, with the number of users each applies to.
* Rows can be moved with the arrow keys on their handle (announced to screen readers); the item count updates live and unsaved changes are marked, with a warning before leaving the page.

= 1.4.0 =
* Added menu profiles per role: hidden items and, optionally, an order of their own. Presets can define them (`profiles`, with `hide_groups`, `show` and `hidden`); the Rubberplants preset hides the developer and WPMU DEV items from client administrators.
* Added agency accounts (email domains, default rubberplants.nl, plus picked administrators). Only they reach the settings page and the abilities, and they always get the default menu.
* The settings page has a profile picker, shows who a profile applies to and which items its role cannot open, and manages the agency accounts.
* The abilities and `wp amswap list|reset` take a profile; added `wp amswap agency list|add|remove|domains`.
* Role profiles may hide Settings: only agency accounts use this page.

= 1.3.0 =
* Added presets (filter `amswap_preset`): group rules with slug patterns, optional groups that need a plugin, and catch-alls for post types and unknown items. Without a saved order the preset is the menu; "Reset to preset" returns to it.
* Added the abilities `amswap/get-menu`, `amswap/update-menu-order` and `amswap/reset-menu-order` for MCP clients and the Abilities REST API (WordPress 6.9+, capability `manage_options`).
* Added the WP-CLI command `wp amswap` (list, refresh, reset).
* The top-level menu is stored as a snapshot when an administrator opens wp-admin, so the abilities and WP-CLI know the real menu items.
* The plugin can be built into a site plugin; when several copies are loaded, the newest one runs.
* Uninstalling also removes the snapshot, and keeps everything while a built-in copy is still active.

= 1.2.0 =
* Added a color picker for the admin menu separators, with a live preview in the menu.

= 1.1.0 =
* Added a show/hide toggle to every top-level menu item. Hidden items keep their position in the saved order.
* Settings cannot be hidden, so the plugin's own page always stays reachable.
* Hiding, separator removal and reordering now apply independently — hiding works even if you have never changed the order.

= 1.0.0 =
* Initial release.
