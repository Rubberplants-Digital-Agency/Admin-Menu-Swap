=== Admin Menu Swap ===
Contributors: rubberplants
Tags: admin menu, reorder, drag and drop, hide, separators
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
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
* Can be built into a site plugin: require `admin-menu-swap.php` from the site plugin's own copy. When several copies are present, the newest one runs.
* Without a preset, nothing changes until you save. The plugin does keep a small snapshot of the menu (option `amswap_menu_snapshot`) whenever an administrator opens wp-admin, so the abilities and WP-CLI know which items exist.
* Uses the WordPress core `menu_order` filter — cooperates with, rather than overwrites, the admin menu.

== Installation ==

1. Upload the `admin-menu-swap` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Go to **Settings → Menu Order**, drag things around and save.

== Frequently Asked Questions ==

= What happens when I activate a new plugin after saving an order? =

Its menu items (and its own separators) appear at the bottom of the menu until you visit Settings → Menu Order and save again, which bakes them into your order. While a preset applies (nothing saved), the new items are grouped by the preset straight away.

= Who sees which menu? =

Agency accounts get the default menu. Everybody else gets the profile of their highest role that has one (Administrator before Shop Manager before Editor and so on), or the default menu when none of their roles has a profile. A profile hides items on top of what the default menu hides. Pick a profile at the top of Settings → Menu Order to edit it; the page shows who it applies to and which items the role cannot open anyway.

= What is an agency account? =

An administrator whose email address is on an agency domain (default rubberplants.nl), or who is ticked under Agency accounts on the settings page (or added with `wp amswap agency add <user>`). Super admins on a multisite always are. As long as no administrator qualifies, every administrator counts as one, so nobody is ever locked out of the settings page. The page refuses a change that would take away your own access.

= How do presets and the saved order work together? =

A preset orders the menu as long as nothing is saved. The settings page shows the preset's result; saving there turns it into your own order, which the preset no longer touches. "Reset to preset" deletes the saved order and the preset applies again.

= Why does "wp amswap refresh" need --exec? =

Plugins only register their admin menus in wp-admin, so WordPress has to load as if it were wp-admin: `wp amswap list --refresh --exec='define( "WP_ADMIN", true );' --user=<administrator>`. WP-CLI's own `--context=admin` builds the menu incompletely, so the command refuses it. Without a refresh the commands and abilities read the snapshot from the last time an administrator opened wp-admin.

= The plugin and a site plugin both contain Admin Menu Swap. Which one runs? =

The newest version; with equal versions, one of them. Copies older than 1.3.0 always run themselves, and a 1.3 copy then steps aside. Activating a pre-1.3 copy while a 1.3 copy is running fails safely (WordPress reports a fatal error and leaves it inactive): update that copy instead. Uninstalling the plugin keeps the saved order while a built-in copy is active.

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

WordPress draws each menu separator as an invisible 2px line. Pick a color under Settings → Menu Order and every separator line in the admin menu is drawn in that color. Clear the color to go back to the admin color scheme's default.

= Another plugin also reorders the admin menu — who wins? =

Admin Menu Swap registers its `menu_order` filter at a very late priority, so its saved order wins. A plugin that force-disables `custom_menu_order` at an even later priority would disable sorting entirely; that is rare.

== Changelog ==

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
