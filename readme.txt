=== Correct Audio/Video Uploads ===
Contributors: SergeyBiryukov
Tags: upload, media, mime, audio, video
Requires at least: 3.7.19
Tested up to: 4.7.3
Stable tag: 1.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Restores the ability to upload audio & video files in recent minor WordPress updates.

== Description ==

Recent minor updates for WordPress introduced a couple of regressions when uploading audio and video files:

* On WordPress 4.3.x and earlier branches, audio/video files cannot be uploaded at all due to a fatal error.

 Full list of affected versions: 3.7.19, 3.8.19, 3.9.17, 4.0.16, 4.1.16, 4.2.13, 4.3.9.

 This will be fixed in the next minor update, see the [Trac ticket #40085](https://core.trac.wordpress.org/ticket/40085).

* On WordPress 4.4.x and later branches, audio/video files are uploaded with corrupted thumbnails.

 Full list of affected versions: 4.4.8, 4.5.7, 4.6.4, 4.7.3.

 This will be fixed in the next minor update, see the [Trac ticket #40075](https://core.trac.wordpress.org/ticket/40075).

In the meantime, this plugin is a workaround that solves both issues and restores the upload functionality for audio and video files.

Don't forget to remove the plugin once the next minor WordPress update is available!

== Installation ==

1. Upload `correct-audio-video-uploads` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.

== Changelog ==

= 1.1 =
* Prevent the cover for older files from being deleted when uploading multiple files with the same cover.

= 1.0 =
* Initial release
