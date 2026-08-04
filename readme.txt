=== Admin Menu Swap ===
Contributors: rubberplants
Tags: admin menu, reorder, drag and drop, hide, separators
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reorder the WordPress admin menu with drag and drop, hide items you never use, and add separators to group menu items.

== Description ==

Admin Menu Swap adds a **Settings → Menu Order** page where the current top-level admin menu is shown as a drag and drop list. Drag items into any order, hide the ones you never use, drag separators around, remove them, or add new ones to group related items — then save. The result applies to all users (each user only sees the items their role allows, in your saved order).

* Drag & drop reordering of all top-level menu items.
* Show/hide toggle per item — hidden items keep their place in the list, so switching one back on returns it to where it was.
* Separators are draggable too, can be removed, and new ones can be added.
* One click "Reset to default menu".
* Zero footprint until you save for the first time.
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

= Does hiding an item stop people from opening it? =

No, and it is not meant to. Hiding takes the item out of the admin menu; the page behind it stays reachable by its direct URL, by bookmarks and by any link pointing at it. Treat it as decluttering, not as a permission. Use user roles and capabilities if you need to actually restrict access.

= Why can't I hide Settings? =

Because this plugin's own page lives under it. Hiding Settings would leave no route back to the toggle that undoes it, so the Settings row is locked.

= What happens to a hidden item's position? =

It is kept. Hidden items stay in the list on the Menu Order page (dimmed and struck through) and hold their place in your saved order, so switching one back on drops it exactly where it was.

= Can it rename menu items? =

No. Admin Menu Swap changes order, visibility and separators, on purpose.

= Another plugin also reorders the admin menu — who wins? =

Admin Menu Swap registers its `menu_order` filter at a very late priority, so its saved order wins. A plugin that force-disables `custom_menu_order` at an even later priority would disable sorting entirely; that is rare.

== Changelog ==

= 1.1.0 =
* Added a show/hide toggle to every top-level menu item. Hidden items keep their position in the saved order.
* Settings cannot be hidden, so the plugin's own page always stays reachable.
* Hiding, separator removal and reordering now apply independently — hiding works even if you have never changed the order.

= 1.0.0 =
* Initial release.
