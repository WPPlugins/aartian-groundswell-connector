<?php
/**
 * Plugin Name: Aartian Groundswell Connector
 * Description: Lets Groundswell publish articles to this site via a token-authenticated REST endpoint. Uses a custom header (and query fallback), so it works even on hosts that strip the standard Authorization header.
 * Version: 1.4.2
 * Author: Aartian Technologies
 * Author URI: https://aartian.com
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: aartian-groundswell-connector
 */

if (!defined('ABSPATH')) exit;

define('AARTGRCO_OPT_TOKEN', 'aartgrco_token');
define('AARTGRCO_VERSION', '1.4.2');
define('AARTGRCO_OPT_AUTHOR', 'aartgrco_author');
define('AARTGRCO_OPT_AUTHOR_REPAIRED', 'aartgrco_author_repaired');

/** 1.4.2 renamed this plugin's options for WordPress.org (prefix "aartgrco"). A site updated
 *  from 1.4.1 or older keeps its token and "Publish as" choice: each old value is copied to
 *  its new name once and the old one removed. Without this the token would be regenerated
 *  and Groundswell would stop publishing until the site was reconnected. */
function aartgrco_migrate_options() {
    $renamed = array('gsw_token' => AARTGRCO_OPT_TOKEN, 'gsw_author' => AARTGRCO_OPT_AUTHOR,
                     'gsw_author_repaired' => AARTGRCO_OPT_AUTHOR_REPAIRED);
    foreach ($renamed as $old => $new) {
        $value = get_option($old, null);
        if ($value === null) continue;
        if (get_option($new, null) === null) update_option($new, $value);
        delete_option($old);
    }
}
add_action('plugins_loaded', 'aartgrco_migrate_options');

/** Get (or lazily create) the site's Groundswell token. */
function aartgrco_get_token() {
    $t = get_option(AARTGRCO_OPT_TOKEN);
    if (!$t) { $t = wp_generate_password(40, false, false); update_option(AARTGRCO_OPT_TOKEN, $t); }
    return $t;
}

/** The user Groundswell's posts are saved under: the "Publish as" choice on the settings page
 *  while that user still exists and may publish, else the first Administrator. A request made
 *  with the token has no logged-in user, so without this WordPress saves author 0 and the
 *  theme prints "By" with no name (aartian.com post 38, 22 Oct 2026). */
function aartgrco_author_id() {
    $id = (int) get_option(AARTGRCO_OPT_AUTHOR);
    if ($id) {
        $u = get_userdata($id);
        if ($u && user_can($u, 'publish_posts')) return $id;
    }
    $admins = get_users(array('role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC',
                              'number' => 1, 'fields' => 'ID'));
    return $admins ? (int) $admins[0] : 0;
}

/** Once per site: posts saved with no author by plugin versions before 1.4.0 get the
 *  "Publish as" user. Written straight to the table so the posts' modified dates stay. */
function aartgrco_repair_authors() {
    if (get_option(AARTGRCO_OPT_AUTHOR_REPAIRED)) return;
    $author = aartgrco_author_id();
    if (!$author) return;
    global $wpdb;
    // WP_Query cannot select author 0, and wp_update_post would move each post's modified
    // date. This runs once per site (the option above), so there is nothing to cache.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_author = 0 AND post_type = %s", 'post'));
    foreach ($ids as $pid) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($wpdb->posts, array('post_author' => $author), array('ID' => (int) $pid));
        clean_post_cache((int) $pid);
    }
    // Recorded even when nothing needed repair, so this query runs once per site, not per page load.
    update_option(AARTGRCO_OPT_AUTHOR_REPAIRED, 'done: ' . count($ids) . ' post(s)');
}
add_action('init', 'aartgrco_repair_authors');

function aartgrco_author_name($post_id) {
    $u = get_userdata((int) get_post_field('post_author', $post_id));
    return $u ? $u->display_name : '';
}

/** Auth for every endpoint: custom header preferred, query param as a fallback
 *  for hosts that strip even custom headers. Constant-time comparison. */
function aartgrco_check_auth(WP_REST_Request $req) {
    $provided = $req->get_header('X-Groundswell-Token');
    if (!$provided) $provided = $req->get_header('x_groundswell_token');
    if (!$provided) $provided = $req->get_param('aartgrco_token');
    $expected = aartgrco_get_token();
    if (!$provided || !hash_equals($expected, (string)$provided)) {
        return new WP_Error('aartgrco_forbidden', 'Invalid or missing Groundswell token.', array('status' => 401));
    }
    return true;
}

add_action('rest_api_init', function () {
    register_rest_route('groundswell/v1', '/ping', array(
        'methods'             => 'GET',
        'permission_callback' => 'aartgrco_check_auth',
        'callback'            => function () {
            return array('ok' => true, 'site' => get_bloginfo('name'),
                         'url' => home_url(), 'wp_version' => get_bloginfo('version'),
                         'plugin_version' => AARTGRCO_VERSION);
        },
    ));
    register_rest_route('groundswell/v1', '/publish', array(
        'methods'             => 'POST',
        'permission_callback' => 'aartgrco_check_auth',
        'callback'            => 'aartgrco_publish',
    ));
    // GS-778: replace an existing post by id. Never creates, never moves it to a new address.
    register_rest_route('groundswell/v1', '/post/(?P<id>\d+)', array(
        'methods'             => 'POST',
        'permission_callback' => 'aartgrco_check_auth',
        'callback'            => 'aartgrco_update',
    ));
    register_rest_route('groundswell/v1', '/post/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'permission_callback' => 'aartgrco_check_auth',
        'callback'            => function (WP_REST_Request $req) {
            $force = in_array($req->get_param('force'), array('true', true, '1'), true);
            $res = wp_delete_post((int)$req['id'], $force);
            return array('ok' => (bool)$res);
        },
    ));
});

/** Create or update a post, idempotent by slug. */
function aartgrco_publish(WP_REST_Request $req) {
    $p = $req->get_json_params();
    if (empty($p['title'])) return new WP_Error('aartgrco_bad', 'title is required', array('status' => 400));

    $slug    = sanitize_title(!empty($p['slug']) ? $p['slug'] : $p['title']);
    $status  = (isset($p['status']) && $p['status'] === 'draft') ? 'draft' : 'publish';
    $content = isset($p['content']) ? $p['content'] : (isset($p['body_html']) ? $p['body_html'] : '');

    // dry run: prove reachability + auth without writing anything
    if (!empty($p['dry_run'])) {
        return array('ok' => true, 'action' => 'dry_run', 'slug' => $slug);
    }

    $existing = get_page_by_path($slug, OBJECT, 'post');
    $postarr = array(
        'post_title'   => wp_strip_all_tags($p['title']),
        'post_name'    => $slug,
        'post_content' => wp_kses_post($content),
        'post_status'  => $status,
        'post_excerpt' => isset($p['excerpt']) ? sanitize_text_field($p['excerpt']) : '',
        'post_type'    => 'post',
    );
    // A new post gets the "Publish as" user. An existing one keeps the author it has, unless
    // that is nobody (a post an older version of this plugin saved).
    if (!$existing || !(int) $existing->post_author) $postarr['post_author'] = aartgrco_author_id();
    if ($existing) { $postarr['ID'] = $existing->ID; $id = wp_update_post($postarr, true); $action = 'updated'; }
    else           { $id = wp_insert_post($postarr, true);                                  $action = 'created'; }
    if (is_wp_error($id)) return new WP_Error('aartgrco_fail', $id->get_error_message(), array('status' => 500));

    // Optional page template (e.g. a theme's custom blog template like "pds-blank").
    // Lets the post render with the site's real blog design on hosts where the
    // standard REST auth is blocked, without any per-site server changes.
    if (!empty($p['template'])) {
        update_post_meta($id, '_wp_page_template', sanitize_text_field($p['template']));
    }

    // optional category by name (find or create)
    if (!empty($p['category'])) {
        $term = term_exists($p['category'], 'category');
        if (!$term) $term = wp_insert_term($p['category'], 'category');
        if (!is_wp_error($term)) wp_set_post_categories($id, array((int)$term['term_id']));
    }

    // GS-773: the article's picture becomes the featured image. A picture that cannot be
    // fetched NEVER fails the post: it is published without one, and the response says so
    // and why, so Groundswell can record it instead of guessing.
    list($picture, $picture_reason) = aartgrco_set_featured($id, $p);

    return array('ok' => true, 'action' => $action, 'id' => $id,
                 'url' => get_permalink($id), 'status' => get_post_status($id), 'slug' => $slug,
                 'picture' => $picture, 'picture_reason' => $picture_reason,
                 'author' => aartgrco_author_name($id), 'plugin_version' => AARTGRCO_VERSION);
}

/** GS-778: update the post with this id in place. Refuses (404) a post that does not exist
 *  and (409) a slug that differs from the post's own: the address never changes. No date is
 *  touched, so the original publish date stays and WordPress records the modified date. */
function aartgrco_update(WP_REST_Request $req) {
    $id = (int) $req['id'];
    $existing = get_post($id);
    if (!$existing || $existing->post_type !== 'post' || $existing->post_status === 'trash') {
        return new WP_Error('aartgrco_not_found', 'No post with that id exists.', array('status' => 404));
    }
    $p = $req->get_json_params();
    if (!empty($p['slug']) && sanitize_title($p['slug']) !== $existing->post_name) {
        return new WP_Error('aartgrco_slug_changed', 'The address of a live post cannot be changed.', array('status' => 409));
    }
    $postarr = array('ID' => $id);
    if (!empty($p['title']))           $postarr['post_title']   = wp_strip_all_tags($p['title']);
    if (isset($p['content']))          $postarr['post_content'] = wp_kses_post($p['content']);
    if (isset($p['excerpt']))          $postarr['post_excerpt'] = sanitize_text_field($p['excerpt']);
    $res = wp_update_post($postarr, true);
    if (is_wp_error($res)) return new WP_Error('aartgrco_fail', $res->get_error_message(), array('status' => 500));
    return array('ok' => true, 'action' => 'updated', 'id' => $id, 'url' => get_permalink($id),
                 'status' => get_post_status($id), 'slug' => $existing->post_name);
}

/** GS-773: sideload `images.featured.url` and set it as the post's featured image.
 *  Returns array(picture, reason): ('set', null) or ('not_set', why). Never throws. */
function aartgrco_set_featured($post_id, $p) {
    $url = isset($p['images']['featured']['url']) ? esc_url_raw($p['images']['featured']['url']) : '';
    if (!$url) return array('not_set', 'no picture was sent with this article');
    if (!function_exists('media_sideload_image')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $alt = isset($p['images']['featured']['alt']) ? sanitize_text_field($p['images']['featured']['alt']) : null;
    $att = media_sideload_image($url, $post_id, $alt, 'id');
    if (is_wp_error($att)) return array('not_set', 'the picture could not be downloaded: ' . $att->get_error_message());
    if ($alt) update_post_meta($att, '_wp_attachment_image_alt', $alt);
    if (!set_post_thumbnail($post_id, $att)) return array('not_set', 'WordPress would not set the featured image');
    return array('set', null);
}

/** Settings screen: shows the site URL + token to paste into Groundswell. */
add_action('admin_menu', function () {
    add_options_page('Groundswell', 'Groundswell', 'manage_options', 'aartgrco-settings', 'aartgrco_settings_page');
});
function aartgrco_settings_page() {
    if (!current_user_can('manage_options')) return;
    if (isset($_POST['aartgrco_regen']) && check_admin_referer('aartgrco_regen')) {
        update_option(AARTGRCO_OPT_TOKEN, wp_generate_password(40, false, false));
        echo '<div class="notice notice-success"><p>Token regenerated. Update it in Groundswell.</p></div>';
    }
    if (isset($_POST['aartgrco_author']) && check_admin_referer('aartgrco_author')) {
        update_option(AARTGRCO_OPT_AUTHOR, absint($_POST['aartgrco_author']));
        echo '<div class="notice notice-success"><p>Saved. New articles from Groundswell will show this author.</p></div>';
    }
    $token = aartgrco_get_token();
    echo '<div class="wrap"><h1>Groundswell Connector</h1>';
    echo '<p>Paste these two values into Groundswell when connecting this site. Keep the token secret.</p>';
    echo '<table class="form-table"><tr><th>Site URL</th><td><code>' . esc_html(home_url()) . '</code></td></tr>';
    echo '<tr><th>Token</th><td><code style="font-size:15px;user-select:all">' . esc_html($token) . '</code></td></tr></table>';
    echo '<form method="post" style="margin-top:12px">';
    wp_nonce_field('aartgrco_regen');
    echo '<button class="button" name="aartgrco_regen" value="1" onclick="return confirm(\'Regenerate the token? Groundswell will need the new one.\')">Regenerate token</button>';
    echo '</form>';
    echo '<h2>Publish as</h2>';
    echo '<p>Articles from Groundswell are shown on your site under this person\'s name.</p>';
    echo '<form method="post">';
    wp_nonce_field('aartgrco_author');
    wp_dropdown_users(array('name' => 'aartgrco_author', 'capability' => array('publish_posts'),
                            'selected' => aartgrco_author_id()));
    echo ' <button class="button button-primary">Save</button></form></div>';
}
