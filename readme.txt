=== AVIF Support by thisismyurl ===
Contributors: thisismyurl
Author: thisismyurl
Author URI: https://thisismyurl.com/
Donate link: https://thisismyurl.com/donate/
Support Link: https://thisismyurl.com/contact/
Tags: avif, optimization, speed, image-optimizer, performance, next-gen
Requires at least: 5.3
Tested up to: 6.4
Stable tag: 1.26010212
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
GitHub Plugin URI: https://github.com/thisismyurl/thisismyurl-avif-support/
Primary Branch: main

Next-generation non-destructive AVIF for WordPress: Enable AVIF uploads, auto-optimize new media, and bulk-convert existing files with secure backups.

== Description ==

**AVIF Support by thisismyurl** is a lightweight, high-performance utility designed to maximize your site speed using the most advanced image codec available today. 

By converting your images to the **AVIF** format, you can achieve significantly better compression than even WebP, reducing file sizes by up to 50% or more compared to JPEG without sacrificing visual quality.

This plugin follows our **Safety-First** philosophy: every time an image is converted, the original file is archived in a secure backup folder. If you ever need to revert, the plugin offers simple restoration tools to bring back your original JPEG or PNG files.

### Key Features:
* **Enable AVIF Uploads:** Bypasses WordPress defaults to allow .avif files to be uploaded directly to the Media Library.
* **Bulk Processing:** Convert your entire historical library using an AJAX-powered tool designed to handle large libraries without server timeouts.
* **Non-Destructive Workflow:** Original images are moved to `/uploads/avif-backups/` for safe keeping.
* **GD & Imagick Ready:** Utilizes server-side libraries to ensure high-quality, efficient conversions.
* **Individual & Bulk Restore:** Revert changes for a single image or your entire library with a few clicks.
* **GitHub Integrated:** Includes a built-in updater to ensure you always have the latest security and performance patches.

== Installation ==

1. Upload the `thisismyurl-avif-support` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Tools > AVIF Support** to access the optimization dashboard.

== Frequently Asked Questions ==

= Does this delete my original images? =
No. It moves them to a secure `avif-backups` folder within your uploads directory. This ensures you never lose your original high-resolution files.

= Does my server support AVIF? =
This plugin requires PHP 7.4+ and a version of GD or Imagick compiled with AVIF support. Most modern hosting environments now include this by default.

= Will my images break if I delete the plugin? =
The AVIF files will remain on your server, but we recommend using the "Restore All" button in the dashboard before uninstallation if you wish to go back to standard JPEGs and PNGs.

== Changelog ==

= 1.26010212 =
* TIMU_Core updated to version 1.26010212

= 1.260101 =
* Initial release of AVIF Support.
* Refactored from WebP Support engine for next-gen codec support.
* Implemented AVIF-specific backup and restoration logic.
* Updated core library to version 1.260101.
