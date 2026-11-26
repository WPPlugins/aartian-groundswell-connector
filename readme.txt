=== Aartian Groundswell Connector ===
Contributors: aartian
Tags: publishing, content, blog, rest-api, automation
Requires at least: 5.9
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.4.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lets the Groundswell content service publish articles to your site with a private token. It works on hosts that block WordPress app passwords.

== Description ==

Groundswell writes articles for your business and publishes them to your website. This plugin gives Groundswell a safe way in:

* A private token, made on your site, that only Groundswell holds. You can make a new one at any time from Settings → Groundswell.
* It works on hosts that remove the standard login header (many budget and LiteSpeed hosts), because the token travels in its own header.
* Articles are published under a real author you choose ("Publish as"), with their category and featured image.
* Groundswell can update an article it published, but it can never move it to a new address.

The plugin does nothing until you paste its Site URL and token into your Groundswell account.

== External services ==

This plugin connects your site to **Groundswell**, an article writing and publishing service run by Aartian Technologies (https://app.aartian.com). It is needed so Groundswell can publish to your site.

What is sent and when:

* **Groundswell calls your site.** Once you have pasted the token into Groundswell, it calls this plugin's REST routes (`/wp-json/groundswell/v1/...`) to check the connection and to publish, update or remove the articles it wrote. Each call carries your token.
* **Your site answers with:** your site name, its address, your WordPress version and this plugin's version (connection check), plus the address, status and author name of each article it publishes.
* **Your site downloads pictures from Groundswell.** When an article comes with a picture, your site downloads it from the address Groundswell sends, so it can become the article's featured image.

No visitor data is collected or sent. Nothing is sent before you connect the site in Groundswell.

* Terms of use: https://app.aartian.com/terms
* Privacy policy: https://app.aartian.com/privacy

== Installation ==

1. Install and activate the plugin, from Plugins → Add New or by uploading the zip.
2. Go to Settings → Groundswell and copy the Site URL and the token.
3. In Groundswell, connect your website and paste both values.
4. Optional: under "Publish as", choose whose name appears on the articles. It starts as the site's first Administrator.

== Frequently Asked Questions ==

= Whose name appears on the articles? =

The user chosen under Settings → Groundswell → Publish as. If that user is deleted or can no longer publish posts, the site's first Administrator is used. To change the author of one article, edit it as usual. Groundswell will not change it back.

= I updated from an older version. Do my old articles still have no author? =

No. Versions before 1.4.0 saved articles with no author, which some themes show as "By" with nothing after it. On its first run, 1.4.0 gives those articles the "Publish as" user, once. Their dates do not change.

= How do I disconnect Groundswell? =

Click "Regenerate token" on Settings → Groundswell, or deactivate the plugin. The old token stops working straight away.

== Changelog ==

= 1.4.3 =
* When Groundswell updates an article with a new picture, the new picture now becomes the featured image. Before, an update kept the old picture.
* Sending the same picture again no longer adds another copy to the Media Library.

= 1.4.2 =
* Internal names now use the prefix "aartgrco" so they cannot clash with other plugins. Your token and "Publish as" choice carry over by themselves, so connected sites keep publishing.

= 1.4.1 =
* Renamed to Aartian Groundswell Connector for the WordPress.org directory. No change in behaviour.

= 1.4.0 =
* Articles are saved under a real author: the new "Publish as" setting, or the first Administrator. They are no longer saved with no author.
* Articles saved with no author by older versions are given the "Publish as" user, once.
* The connection check and each publish now report the plugin version, and each publish reports the author name.
* Added this readme.

= 1.3.0 =
* Groundswell can update an article it published, in place.

== Upgrade Notice ==

= 1.4.3 =
Updated articles now get their new picture. Your connection keeps working.

= 1.4.2 =
Internal renames only. Your connection keeps working.

= 1.4.1 =
Name change only.

= 1.4.0 =
Fixes articles showing "By" with no author name, including ones already published.
