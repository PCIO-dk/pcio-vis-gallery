=== PCIO VIS Gallery ===
Contributors:      pcio
Author:            PCIO
Author URI:        https://www.pcio.dk
Plugin URI:        https://www.pcio.dk/vis-plugins
Tags:              gallery, photos, events, media, images
Requires at least: 6.2
Tested up to:      7.0
Requires PHP:      8.1
Requires Plugins:  pcio-vis-member-event
Stable tag:        1.0.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Adds event-linked photo galleries to PCIO VIS Member Event, using standard WordPress Media Library attachments.

== Description ==

PCIO VIS Gallery is an extension for the PCIO VIS Member Event plugin. It adds event-linked photo galleries. Galleries store images as standard WordPress Media Library attachments and can be embedded with the `[pcio_me_gallery id="N"]` shortcode or browsed at `/vis/galleries/`.

This plugin requires the PCIO VIS Member Event core plugin to be installed and active.

== Installation ==

1. Install and activate the **PCIO VIS Member Event** core plugin first.
2. Upload the `pcio-vis-gallery` directory to `/wp-content/plugins/`.
3. Activate the plugin in **Plugins** in the WordPress admin.
4. Create galleries under the **Vis** admin menu and embed them with `[pcio_me_gallery id="N"]`.

== Frequently Asked Questions ==

= Where are the images stored? =
As standard WordPress Media Library attachments.

= How do I show a gallery on a page? =
Use the `[pcio_me_gallery id="N"]` shortcode, or browse all galleries at `/vis/galleries/`.


== Screenshots ==

1. VIS Manage galleries 

== Changelog ==

= 1.0.0 =
* Initial release.
