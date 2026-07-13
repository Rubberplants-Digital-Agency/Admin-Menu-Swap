=== Admin Menu Swap ===
Contributors: rubberplants
Tags: admin menu, reorder, drag and drop, separators, admin
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reorder the WordPress admin menu with drag and drop, and add or remove separators to group menu items.

== Description ==

Admin Menu Swap adds a **Settings → Menu Order** page where the current top-level admin menu is shown as a drag and drop list. Drag items into any order, drag separators around, remove them, or add new ones to group related items — then save. The order applies to all users (each user only sees the items their role allows, in your saved order).

* Drag & drop reordering of all top-level menu items.
* Separators are draggable too, can be removed, and new ones can be added.
* One click "Reset to default order".
* Zero footprint until you save an order for the first time.
* Uses the WordPress core `menu_order` filter — cooperates with, rather than overwrites, the admin menu.

== Installation ==

1. Upload the `admin-menu-swap` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Go to **Settings → Menu Order**, drag things around and save.

== Frequently Asked Questions ==

= What happens when I activate a new plugin after saving an order? =

Its menu items (and its own separators) appear at the bottom of the menu until you visit Settings → Menu Order and save again, which bakes them into your order.

= Does it work on multisite? =

The order is saved per site and only affects the regular site admin menu. The network admin menu is never touched.

= Can it hide or rename menu items? =

No. Admin Menu Swap only changes the order and the separators, on purpose.

= Another plugin also reorders the admin menu — who wins? =

Admin Menu Swap registers its `menu_order` filter at a very late priority, so its saved order wins. A plugin that force-disables `custom_menu_order` at an even later priority would disable sorting entirely; that is rare.

== Changelog ==

= 1.0.0 =
* Initial release.
