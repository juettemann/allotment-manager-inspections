<?php
/**
 * Plugin Name: Allotment Manager - Field Inspector
 * Plugin URI: https://github.com/juettemann/allotment-manager-inspections
 * Description: Mobile-first PWA for committee members to record plot inspections in the field. Depends on the main Allotment Manager plugin for data, AJAX handlers and Google Drive photo storage.
 * Version: 1.9.0
 * Author: Thomas Juettemann
 * Author URI: https://juettemann.co.uk
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: allotment-manager-inspections
 * Domain Path: /languages
 * Requires at least: 6.7
 * Requires PHP: 8.1
 *
 * @package AllotmentManagerInspections
 */

namespace AllotmentManagerInspections;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version.
 *
 * Busts the JS/CSS module URLs (see shell.php + Route), so it MUST be bumped
 * with any front-end change or phones keep serving the cached app. Keep it in
 * step with the `Version:` header above — the two had drifted (1.4.3 vs 1.4.4)
 * and the header is the one WordPress shows on the Plugins screen, while this
 * one is what the app displays and what actually busts the cache.
 */
define( 'AMI_VERSION', '1.9.0' );

/**
 * Plugin directory path.
 */
define( 'AMI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin URL.
 */
define( 'AMI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin file.
 */
define( 'AMI_PLUGIN_FILE', __FILE__ );

/**
 * Inspector capability slug.
 */
define( 'AMI_CAPABILITY', 'am_field_inspector' );

/**
 * Capability set version. Bump when the set of granted caps changes; the
 * activation re-sync runs whenever the stored version differs.
 *
 * v2: re-grant am_field_inspector to the committee roles (am_site_chair,
 * am_site_secretary, am_site_manager, am_committee, am_it_admin). On the
 * existing installs these roles were created by the main plugin AFTER the
 * inspector was first activated, so the original v1 sync — which skips
 * roles that don't exist yet — only ever reached `administrator`.
 *
 * v3: grant `edit_any_inspection_finding` to am_site_chair (lets the chair
 * edit any finding; inspectors can already edit their own, admins via
 * manage_options).
 *
 * v4: drop am_it_admin from default_roles(). IT Admin is a system-config
 * role, not an inspector, and never held record_inspection_findings (granted
 * in allotment-manager), so PWA access alone 403'd every save. A MemberManager
 * ROLES_VERSION rebuild — which removes-and-recreates each custom role from the
 * am_role_capabilities filter set — strips the stale am_field_inspector grant
 * from existing installs once this version is live (deploy inspections first).
 *
 * v5: grant edit_any_inspection_finding to the whole committee (am_site_secretary,
 * am_site_manager, am_committee), not just am_site_chair, so any committee member
 * can edit / add photos to a finding another inspector recorded. maybe_resync()
 * applies it on the next page load after deploy; no re-login needed (role caps
 * are read per request).
 */
define( 'AMI_CAPS_VERSION', '5' );

require_once AMI_PLUGIN_DIR . 'includes/class-plugin.php';
require_once AMI_PLUGIN_DIR . 'includes/class-capabilities.php';
require_once AMI_PLUGIN_DIR . 'includes/class-route.php';
require_once AMI_PLUGIN_DIR . 'includes/ajax/class-inspect-ajax.php';

/**
 * Minimum required base-plugin version.
 */
define( 'AMI_REQUIRED_AM_VERSION', '2.4.0' );

/**
 * Check whether the main Allotment Manager plugin is active and at the
 * required minimum version. Without it, the inspector plugin's AJAX
 * endpoints and routes can't function — they call into the main plugin's
 * services + capabilities. Audit finding D (May 21 2026 audit).
 *
 * @return bool
 */
function is_base_plugin_active(): bool {
	// Main plugin's Plugin class is only defined when the plugin is
	// active and its autoloader is registered. Lightweight check —
	// avoids loading wp-admin/includes/plugin.php for is_plugin_active().
	if ( ! class_exists( 'AllotmentManager\\Plugin' ) ) {
		return false;
	}

	if ( defined( 'AM_VERSION' ) ) {
		return version_compare( AM_VERSION, AMI_REQUIRED_AM_VERSION, '>=' );
	}

	// AM_VERSION not yet defined (very early in plugins_loaded ordering)
	// — assume compatible. Late requests will re-check.
	return true;
}

/**
 * Show an admin notice when the base plugin is missing.
 */
function base_plugin_required_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	printf(
		/* translators: %s: minimum AM version */
		esc_html__( 'Allotment Manager - Field Inspector requires the Allotment Manager plugin (version %s or higher) to be installed and activated.', 'allotment-manager-inspections' ),
		esc_html( AMI_REQUIRED_AM_VERSION )
	);
	echo '</p></div>';
}

/**
 * Boot the plugin if its base plugin is active. Wraps Plugin::instance so
 * we can gate execution behind the base-plugin check — register_*_hook
 * callbacks fire from the standalone bootstrap and don't need the gate.
 */
function init_plugin(): void {
	if ( ! is_base_plugin_active() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\base_plugin_required_notice' );
		return;
	}
	Plugin::instance();
}

// Activation / deactivation hooks: register/remove the capability.
\register_activation_hook( __FILE__, [ Capabilities::class, 'on_activate' ] );
\register_deactivation_hook( __FILE__, [ Capabilities::class, 'on_deactivate' ] );

// Boot. Run at priority 20 so the base plugin's plugins_loaded hook
// (default priority 10) has already fired and registered its Plugin class.
\add_action( 'plugins_loaded', __NAMESPACE__ . '\\init_plugin', 20 );
