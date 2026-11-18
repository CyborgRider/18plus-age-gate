<?php
/**
 * Plugin Name: CyborgRider's Simple Age Gate
 * Description: Shows an 18+ confirmation popup over selected posts AND pages. "Yes" saves a site-wide cookie; "No" redirects. Gating is selected via tree-style checklists: posts flat, pages hierarchically (sub-pages inherit), plus categories (subcategories inherit) and tags.
 * Version: 1.1
 * Author: CyborgRider
 * License: GPLv2 or later.
 * Text Domain: 18plus-age-gate
 *
 * Install: drop the entire 18plus-age-gate folder into /wp-content/plugins/
 * (Or download from the WordPress Plugin Store!), then activate it in Plugins.
 * Configure under Settings -> Age Gate.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'SAG_OPTION', 'sag_settings' );
define( 'SAG_MENU_SLUG', '18plus-age-gate' );

/* -------------------------------------------------------------------------
 * Settings storage
 * ---------------------------------------------------------------------- */

/**
 * Default settings.
 */
function sag_defaults() {
	return array(
		'posts'         => array(),            // Post IDs to gate.
		'pages'         => array(),            // Page IDs to gate (sub-pages inherit).
		'categories'    => array(),            // Category term IDs to gate (children inherit).
		'tags'          => array(),            // Tag term IDs to gate.
		'cookie_name'   => 'smut_verified',
		'cookie_days'   => 365,
		'popup_title'   => __( 'Are you 18 or older?', '18plus-age-gate' ),
		'popup_text'    => __( 'This post contains content intended for adults. Please confirm your age to continue.', '18plus-age-gate' ),
		'yes_label'     => __( 'Yes', '18plus-age-gate' ),
		'no_label'      => __( 'No', '18plus-age-gate' ),
		'redirect_mode' => 'page',            // 'page' or 'url'.
		'redirect_page' => 0,                 // Page ID to send "No" visitors to.
		'redirect_url'  => '',                // Or a custom URL.
	);
}

/**
 * Get merged settings.
 */
function sag_get_settings() {
	$saved = get_option( SAG_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, sag_defaults() );
}

/* -------------------------------------------------------------------------
 * Core gating logic
 * ---------------------------------------------------------------------- */

/**
 * Is a given post or page age-gated?
 *
 * A POST is gated if:
 *  - Its ID was individually selected, OR
 *  - It has a selected tag, OR
 *  - It has a category whose ancestor chain (parent, grandparent, ...)
 *    includes a selected category. Subcategories therefore inherit
 *    their parent category's gating automatically.
 *
 * A PAGE is gated if:
 *  - Its ID was individually selected, OR
 *  - It sits somewhere beneath a selected page. Sub-pages inherit
 *    their parent page's gating automatically, at any depth.
 *
 * Because this check runs at render time, items published AFTER the
 * settings were saved (including items added to a gated category, tag,
 * or moved under a gated parent page) are gated automatically with no
 * extra action.
 */
function sag_is_post_gated( $post_id ) {
	if ( empty( $post_id ) || ! is_numeric( $post_id ) ) {
		return false;
	}
	$post_id = (int) $post_id;

	$post = get_post( $post_id );
	if ( ! $post ) {
		return false;
	}

	$s = sag_get_settings();

	// 1. Individually selected posts OR pages.
	$posts = array_map( 'absint', (array) $s['posts'] );
	if ( in_array( $post_id, $posts, true ) ) {
		return true;
	}

	// 2. Pages: inherit gating from the full parent chain (self + ancestors).
	if ( 'page' === $post->post_type ) {
		$selected_pages = array_map( 'absint', (array) $s['pages'] );
		if ( empty( $selected_pages ) ) {
			return false;
		}
		// get_ancestors() returns the parent chain, outermost first.
		$chain = array_merge( array( $post_id ), array_map( 'absint', get_ancestors( $post_id, 'page' ) ) );
		return (bool) array_intersect( $chain, $selected_pages );
	}

	// 3. Posts: tags.
	$tag_ids = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'ids' ) );
	if ( ! is_wp_error( $tag_ids ) && array_intersect( array_map( 'absint', $tag_ids ), array_map( 'absint', (array) $s['tags'] ) ) ) {
		return true;
	}

	// 4. Posts: categories, including full ancestor chains (subcategory inheritance).
	$cat_ids = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
	if ( is_wp_error( $cat_ids ) ) {
		return false;
	}
	$selected_cats = array_map( 'absint', (array) $s['categories'] );
	foreach ( array_map( 'absint', $cat_ids ) as $cat_id ) {
		// get_ancestors() returns the parent chain, outermost first.
		$chain = array_merge( array( $cat_id ), array_map( 'absint', get_ancestors( $cat_id, 'category' ) ) );
		if ( array_intersect( $chain, $selected_cats ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Resolve the "No" redirect target URL.
 */
function sag_get_redirect_url( $s ) {
	if ( 'page' === $s['redirect_mode'] && ! empty( $s['redirect_page'] ) ) {
		$url = get_permalink( (int) $s['redirect_page'] );
		if ( $url ) {
			return $url;
		}
	}
	if ( 'url' === $s['redirect_mode'] && ! empty( $s['redirect_url'] ) ) {
		return $s['redirect_url'];
	}
	return home_url( '/' ); // Sensible fallback if nothing is configured.
}

/* -------------------------------------------------------------------------
 * Frontend
 * ---------------------------------------------------------------------- */

/**
 * Runs on every page load once the query is set. Decides whether the
 * age gate must be injected for the current view.
 */
function sag_maybe_init() {
	if ( ! is_singular( array( 'post', 'page' ) ) ) {
		return; // Gates apply to single post AND page views.
	}
	$post_id = get_queried_object_id();
	if ( ! sag_is_post_gated( $post_id ) ) {
		return;
	}

	$s = sag_get_settings();

	// Already verified? Skip entirely (saves output + helps caches/CDNs).
	if ( isset( $_COOKIE[ $s['cookie_name'] ] ) && 'yes' === $_COOKIE[ $s['cookie_name'] ] ) {
		return;
	}

	add_action( 'wp_footer', 'sag_render_gate' );
}
add_action( 'wp', 'sag_maybe_init' );

/**
 * Output the overlay markup, styles and behavior script in the footer.
 */
function sag_render_gate() {
	$s        = sag_get_settings();
	$redirect = sag_get_redirect_url( $s );

	$cfg = array(
		'cookie'      => (string) $s['cookie_name'],
		'days'        => max( 1, (int) $s['cookie_days'] ),
		'redirectUrl' => $redirect,
	);
	?>
<style id="sag-style">
#sag-overlay{position:fixed;inset:0;background:rgba(0,0,0,.86);display:flex;align-items:center;justify-content:center;z-index:2147483000;padding:24px;text-align:center}
.sag-box{background:#fff;color:#222;border-radius:12px;padding:36px 32px;max-width:460px;width:100%;box-shadow:0 12px 48px rgba(0,0,0,.4);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.sag-box h2{margin:0 0 14px;font-size:22px;line-height:1.3}
.sag-box p{margin:0 0 26px;font-size:15px;line-height:1.6;color:#444}
.sag-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
.sag-actions button{cursor:pointer;border:none;border-radius:8px;padding:12px 22px;font-size:15px;font-weight:600}
#sag-btn-yes{background:#217a3b;color:#fff}
#sag-btn-no{background:#e2e4e8;color:#222}
html.sag-lock,html.sag-lock body{overflow:hidden!important}
</style>
<div id="sag-overlay" role="dialog" aria-modal="true" aria-labelledby="sag-title">
	<div class="sag-box">
		<h2 id="sag-title"><?php echo esc_html( $s['popup_title'] ); ?></h2>
		<p><?php echo esc_html( $s['popup_text'] ); ?></p>
		<div class="sag-actions">
			<button type="button" id="sag-btn-yes"><?php echo esc_html( $s['yes_label'] ); ?></button>
			<button type="button" id="sag-btn-no"><?php echo esc_html( $s['no_label'] ); ?></button>
		</div>
	</div>
</div>
<script>
window.SAG_CFG = <?php echo wp_json_encode( $cfg ); ?>;
(function () {
	var overlay = document.getElementById('sag-overlay');
	if (!overlay) { return; }

	function closeGate() {
		if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
		document.documentElement.classList.remove('sag-lock');
	}

	function setVerified() {
		var d = new Date();
		d.setTime(d.getTime() + window.SAG_CFG.days * 86400000);
		document.cookie = window.SAG_CFG.cookie + '=yes; expires=' + d.toUTCString() +
			'; path=/; SameSite=Lax';
	}

	// Cache-safety: if a cached page ships the markup even though the
	// visitor already has the cookie, silently remove the gate.
	var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + window.SAG_CFG.cookie + '=yes'));
	if (m) {
		closeGate();
		return;
	}

	document.documentElement.classList.add('sag-lock');

	document.getElementById('sag-btn-yes').addEventListener('click', function () {
		setVerified();
		closeGate();
	});

	document.getElementById('sag-btn-no').addEventListener('click', function () {
		window.location.href = window.SAG_CFG.redirectUrl;
	});
})();
</script>
	<?php
}

/* -------------------------------------------------------------------------
 * Settings page
 * ---------------------------------------------------------------------- */

/**
 * Register the settings page.
 */
function sag_register_admin_menu() {
	add_options_page(
		__( 'Age Gate', '18plus-age-gate' ),
		__( 'Age Gate', '18plus-age-gate' ),
		'manage_options',
		SAG_MENU_SLUG,
		'sag_render_settings_page'
	);
}
add_action( 'admin_menu', 'sag_register_admin_menu' );

/**
 * Register the option with the Settings API.
 */
function sag_register_settings() {
	register_setting(
		'sag_settings_group',
		SAG_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'sag_sanitize_settings',
			'default'           => array(),
		)
	);

	add_settings_section( 'sag_section_content', __( 'Popup content', '18plus-age-gate' ), '__return_false', SAG_MENU_SLUG );
	add_settings_section( 'sag_section_selection', __( 'What should be age-gated?', '18plus-age-gate' ), '__return_false', SAG_MENU_SLUG );
	add_settings_section( 'sag_section_behavior', __( 'Behavior', '18plus-age-gate' ), '__return_false', SAG_MENU_SLUG );

	add_settings_field( 'popup_title', __( 'Popup title', '18plus-age-gate' ), 'sag_field_text', SAG_MENU_SLUG, 'sag_section_content', array( 'key' => 'popup_title' ) );
	add_settings_field( 'popup_text', __( 'Popup message', '18plus-age-gate' ), 'sag_field_text', SAG_MENU_SLUG, 'sag_section_content', array( 'key' => 'popup_text', 'long' => true ) );
	add_settings_field( 'yes_label', __( '"Yes" button label', '18plus-age-gate' ), 'sag_field_text', SAG_MENU_SLUG, 'sag_section_content', array( 'key' => 'yes_label' ) );
	add_settings_field( 'no_label', __( '"No" button label', '18plus-age-gate' ), 'sag_field_text', SAG_MENU_SLUG, 'sag_section_content', array( 'key' => 'no_label' ) );

	add_settings_field( 'gated_posts', __( 'Individual posts', '18plus-age-gate' ), 'sag_field_posts', SAG_MENU_SLUG, 'sag_section_selection' );
	add_settings_field( 'gated_pages', __( 'Individual pages', '18plus-age-gate' ), 'sag_field_pages', SAG_MENU_SLUG, 'sag_section_selection' );
	add_settings_field( 'gated_categories', __( 'Categories', '18plus-age-gate' ), 'sag_field_categories', SAG_MENU_SLUG, 'sag_section_selection' );
	add_settings_field( 'gated_tags', __( 'Tags', '18plus-age-gate' ), 'sag_field_tags', SAG_MENU_SLUG, 'sag_section_selection' );

	add_settings_field( 'redirect_target', __( 'Where "No" visitors go', '18plus-age-gate' ), 'sag_field_redirect', SAG_MENU_SLUG, 'sag_section_behavior' );
	add_settings_field( 'cookie_lifetime', __( 'Remember choice for (days)', '18plus-age-gate' ), 'sag_field_cookie_days', SAG_MENU_SLUG, 'sag_section_behavior' );
	add_settings_field( 'cookie_name', __( 'Cookie name (advanced)', '18plus-age-gate' ), 'sag_field_cookie_name', SAG_MENU_SLUG, 'sag_section_behavior' );
}
add_action( 'admin_init', 'sag_register_settings' );

/**
 * Sanitize all settings on save.
 */
function sag_sanitize_settings( $input ) {
	$input = is_array( $input ) ? $input : array();
	$out   = sag_defaults();

	foreach ( array( 'popup_title', 'popup_text', 'yes_label', 'no_label' ) as $key ) {
		if ( isset( $input[ $key ] ) ) {
			$out[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
		}
	}

	$int_arrays = array( 'posts', 'pages', 'categories', 'tags' );
	foreach ( $int_arrays as $key ) {
		if ( isset( $input[ $key ] ) && is_array( $input[ $key ] ) ) {
			$out[ $key ] = array_values( array_filter( array_unique( array_map( 'absint', wp_unslash( $input[ $key ] ) ) ) ) );
		}
	}

	if ( isset( $input['cookie_days'] ) ) {
		$out['cookie_days'] = max( 1, min( 3650, absint( $input['cookie_days'] ) ) );
	}
	if ( isset( $input['cookie_name'] ) && '' !== trim( (string) wp_unslash( $input['cookie_name'] ) ) ) {
		$name = sanitize_key( wp_unslash( $input['cookie_name'] ) );
		if ( '' !== $name ) {
			$out['cookie_name'] = $name;
		}
	}

	$mode = isset( $input['redirect_mode'] ) ? sanitize_key( wp_unslash( $input['redirect_mode'] ) ) : 'page';
	$out['redirect_mode'] = in_array( $mode, array( 'page', 'url' ), true ) ? $mode : 'page';
	$out['redirect_page'] = isset( $input['redirect_page'] ) ? absint( $input['redirect_page'] ) : 0;
	if ( isset( $input['redirect_url'] ) ) {
		$out['redirect_url'] = esc_url_raw( wp_unslash( $input['redirect_url'] ) );
	}

	return $out;
}

/**
 * Generic text field renderer.
 */
function sag_field_text( $args ) {
	$s   = sag_get_settings();
	$key = $args['key'];
	if ( ! empty( $args['long'] ) ) {
		printf(
			'<input type="text" class="large-text" name="%1$s[%2$s]" value="%3$s">',
			esc_attr( SAG_OPTION ),
			esc_attr( $key ),
			esc_attr( $s[ $key ] )
		);
	} else {
		printf(
			'<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s">',
			esc_attr( SAG_OPTION ),
			esc_attr( $key ),
			esc_attr( $s[ $key ] )
		);
	}
}

/**
 * Shared checkbox-list renderer for posts and pages.
 *
 * Pages are rendered as an indented hierarchy (same visual style as the
 * category tree). Posts are rendered as a flat alphabetical list, since
 * posts have no parent/child structure. Both lists sit in a scrollable
 * box with a client-side text filter above them.
 *
 * @param string $type Either 'post' or 'page'.
 */
function sag_render_checkbox_list( $type ) {
	$settings_key = ( 'page' === $type ) ? 'pages' : 'posts';
	$selected     = array_map( 'absint', (array) sag_get_settings()[ $settings_key ] );

	$items = get_posts(
		array(
			'post_type'      => $type,
			'post_status'    => 'publish',
			'numberposts'    => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);

	if ( empty( $items ) ) {
		echo '<p class="description">' .
			esc_html( 'page' === $type ? __( 'No pages found.', '18plus-age-gate' ) : __( 'No posts found.', '18plus-age-gate' ) ) .
			'</p>';
		return;
	}

	$list_id = ( 'page' === $type ) ? 'sag-page-list' : 'sag-post-list';
	?>
	<input type="search" class="regular-text sag-tree-filter" data-target="<?php echo esc_attr( $list_id ); ?>"
		placeholder="<?php esc_attr_e( 'Filter by title…', '18plus-age-gate' ); ?>">
	<div id="<?php echo esc_attr( $list_id ); ?>" class="sag-tree-box">
	<?php
	if ( 'page' === $type ) {
		// Build a parent -> children map and walk it like the category tree.
		$children = array();
		$by_id    = array();
		foreach ( $items as $item ) {
			$by_id[ (int) $item->ID ] = $item;
			$children[ (int) $item->post_parent ][] = $item;
		}

		$walk = function ( $parent_id, $depth ) use ( &$walk, $children, $selected ) {
			if ( empty( $children[ $parent_id ] ) ) {
				return;
			}
			foreach ( $children[ $parent_id ] as $item ) {
				printf(
					'<label class="sag-tree-item" style="margin:%1$s"><input type="checkbox" name="%2$s[pages][]" value="%3$s"%4$s> %5$s</label>',
					esc_attr( '2px 0 2px ' . ( $depth * 24 ) . 'px' ),
					esc_attr( SAG_OPTION ),
					esc_attr( $item->ID ),
					checked( in_array( (int) $item->ID, $selected, true ), true, false ),
					esc_html( get_the_title( $item ) )
				);
				$walk( (int) $item->ID, $depth + 1 );
			}
		};
		$walk( 0, 0 );

		// Orphaned items (parent is draft/private/trash but child published)
		// would vanish from the tree walk — render them at top level so
		// they remain selectable.
		$rendered = array();
		$collect  = function ( $parent_id ) use ( &$collect, $children, &$rendered ) {
			if ( empty( $children[ $parent_id ] ) ) {
				return;
			}
			foreach ( $children[ $parent_id ] as $item ) {
				$rendered[] = (int) $item->ID;
				$collect( (int) $item->ID );
			}
		};
		$collect( 0 );
		foreach ( $items as $item ) {
			if ( ! in_array( (int) $item->ID, $rendered, true ) && (int) $item->post_parent !== 0 ) {
				printf(
					'<label class="sag-tree-item" style="margin:2px 0"><input type="checkbox" name="%1$s[pages][]" value="%2$s"%3$s> %4$s</label>',
					esc_attr( SAG_OPTION ),
					esc_attr( $item->ID ),
					checked( in_array( (int) $item->ID, $selected, true ), true, false ),
					esc_html( get_the_title( $item ) )
				);
			}
		}
	} else {
		// Posts: flat alphabetical list.
		foreach ( $items as $item ) {
			printf(
				'<label class="sag-tree-item" style="margin:2px 0"><input type="checkbox" name="%1$s[posts][]" value="%2$s"%3$s> %4$s</label>',
				esc_attr( SAG_OPTION ),
				esc_attr( $item->ID ),
				checked( in_array( (int) $item->ID, $selected, true ), true, false ),
				esc_html( get_the_title( $item ) )
			);
		}
	}
	?>
	</div>
	<p class="description">
		<?php
		if ( 'page' === $type ) {
			esc_html_e( 'Indented items are sub-pages. Checking a page gates every existing and future sub-page beneath it, no matter how deeply nested.', '18plus-age-gate' );
		} else {
			esc_html_e( 'Check each post you want to gate.', '18plus-age-gate' );
		}
		?>
	</p>
	<?php
}

/**
 * Post checklist field.
 */
function sag_field_posts() {
	sag_render_checkbox_list( 'post' );
}

/**
 * Page tree checklist field.
 */
function sag_field_pages() {
	sag_render_checkbox_list( 'page' );
}

/**
 * Hierarchical category checklist. Children are indented; a note explains
 * that selecting a parent gates every existing and future post in its
 * subcategories too.
 */
function sag_field_categories() {
	$selected = array_map( 'absint', (array) sag_get_settings()['categories'] );
	$terms    = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		echo '<p class="description">' . esc_html__( 'No categories found.', '18plus-age-gate' ) . '</p>';
		return;
	}

	$children = array();
	foreach ( $terms as $t ) {
		$children[ (int) $t->parent ][] = $t;
	}

	echo '<p class="description" style="margin-top:0"><strong>' .
		esc_html__( 'Selecting a category also gates every post in its subcategories — including posts you publish later.', '18plus-age-gate' ) .
		'</strong></p>';

	$walk = function ( $parent_id, $depth ) use ( &$walk, $children, $selected ) {
		if ( empty( $children[ $parent_id ] ) ) {
			return;
		}
		foreach ( $children[ $parent_id ] as $term ) {
			printf(
				'<label style="display:block;margin:%1$s"><input type="checkbox" name="%2$s[categories][]" value="%3$s"%4$s> %5$s</label>',
				esc_attr( '4px 0 4px ' . ( $depth * 24 ) . 'px' ),
				esc_attr( SAG_OPTION ),
				esc_attr( $term->term_id ),
				checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
				esc_html( $term->name . ' (' . (int) $term->count . ')' )
			);
			$walk( (int) $term->term_id, $depth + 1 );
		}
	};
	$walk( 0, 0 );
}

/**
 * Flat tag checklist.
 */
function sag_field_tags() {
	$selected = array_map( 'absint', (array) sag_get_settings()['tags'] );
	$terms    = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		echo '<p class="description">' . esc_html__( 'No tags found.', '18plus-age-gate' ) . '</p>';
		return;
	}
	foreach ( $terms as $term ) {
		printf(
			'<label style="display:inline-block;margin:4px 18px 4px 0"><input type="checkbox" name="%1$s[tags][]" value="%2$s"%3$s> %4$s</label>',
			esc_attr( SAG_OPTION ),
			esc_attr( $term->term_id ),
			checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
			esc_html( $term->name . ' (' . (int) $term->count . ')' )
		);
	}
}

/**
 * Redirect target: a page picker or a custom URL.
 */
function sag_field_redirect() {
	$s = sag_get_settings();
	?>
	<fieldset>
		<label style="display:block;margin-bottom:6px">
			<input type="radio" name="<?php echo esc_attr( SAG_OPTION ); ?>[redirect_mode]" value="page"<?php checked( $s['redirect_mode'], 'page' ); ?>>
			<?php esc_html_e( 'Send them to a page:', '18plus-age-gate' ); ?>
			<?php
			wp_dropdown_pages(
				array(
					'name'             => SAG_OPTION . '[redirect_page]',
					'selected'         => (int) $s['redirect_page'],
					'show_option_none' => __( '— Select a page —', '18plus-age-gate' ),
					'post_type'        => 'page',
					'post_status'      => 'publish',
				)
			);
			?>
		</label>
		<label style="display:block">
			<input type="radio" name="<?php echo esc_attr( SAG_OPTION ); ?>[redirect_mode]" value="url"<?php checked( $s['redirect_mode'], 'url' ); ?>>
			<?php esc_html_e( 'Send them to this URL:', '18plus-age-gate' ); ?>
			<input type="url" class="regular-text" name="<?php echo esc_attr( SAG_OPTION ); ?>[redirect_url]" value="<?php echo esc_attr( $s['redirect_url'] ); ?>" placeholder="https://www.cyborgwriter.us/age">
		</label>
		<p class="description"><?php esc_html_e( 'Tip: create a dedicated page such as "Sorry, this content is restricted" and select it here.', '18plus-age-gate' ); ?></p>
	</fieldset>
	<?php
}

/**
 * Cookie lifetime field.
 */
function sag_field_cookie_days() {
	$s = sag_get_settings();
	printf(
		'<input type="number" min="1" max="3650" step="1" class="small-text" name="%1$s[cookie_days]" value="%2$d"> %3$s',
		esc_attr( SAG_OPTION ),
		(int) $s['cookie_days'],
		esc_html__( 'days', '18plus-age-gate' )
	);
}

/**
 * Cookie name field.
 */
function sag_field_cookie_name() {
	$s = sag_get_settings();
	printf(
		'<input type="text" class="regular-text" name="%1$s[cookie_name]" value="%2$s">',
		esc_attr( SAG_OPTION ),
		esc_attr( $s['cookie_name'] )
	);
	echo '<p class="description">' . esc_html__( 'Only change this if you need to; renaming it resets everyone\'s saved choice.', '18plus-age-gate' ) . '</p>';
}

/**
 * Full settings page markup, including the JS for the tree filter boxes.
 */
function sag_render_settings_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Age Gate', '18plus-age-gate' ); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'sag_settings_group' );
			do_settings_sections( SAG_MENU_SLUG );
			submit_button( __( 'Save settings', '18plus-age-gate' ) );
			?>
		</form>
	</div>
	<style>
	.sag-tree-box{max-height:320px;overflow-y:auto;border:1px solid #c3c4c7;border-radius:4px;padding:8px 12px;background:#fff;max-width:560px}
	.sag-tree-item{display:block}
	</style>
	<script>
	jQuery( function ( $ ) {
		// Client-side filter for the post/page checkbox trees.
		$( '.sag-tree-filter' ).on( 'input', function () {
			var q    = $( this ).val().toLowerCase();
			var list = $( '#' + $( this ).data( 'target' ) );
			list.find( '.sag-tree-item' ).each( function () {
				$( this ).toggle( '' === q || $( this ).text().toLowerCase().indexOf( q ) !== -1 );
			} );
		} );
	} );
	</script>
	<?php
}

/* -------------------------------------------------------------------------
 * Housekeeping
 * ---------------------------------------------------------------------- */

/**
 * Clean up the stored option when the plugin is deleted.
 */
function sag_uninstall() {
	delete_option( SAG_OPTION );
}
register_uninstall_hook( __FILE__, 'sag_uninstall' );
