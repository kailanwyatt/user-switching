<?php
/*
Plugin Name: User Switching
Description: Instant switching between user accounts in WordPress
Version:     0.9
Plugin URI:  https://johnblackbourn.com/wordpress-plugin-user-switching/
Author:      John Blackbourn
Author URI:  https://johnblackbourn.com/
Text Domain: user-switching
Domain Path: /languages/
License:     GPL v2 or later
Network:     true

Copyright © 2014 John Blackbourn

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

*/

class user_switching {

	/**
	 * Class constructor. Set up some filters and actions.
	 */
	public function __construct() {

		# Required functionality:
		add_filter( 'user_has_cap',                    array( $this, 'filter_user_has_cap' ), 10, 3 );
		add_filter( 'map_meta_cap',                    array( $this, 'filter_map_meta_cap' ), 10, 4 );
		add_filter( 'user_row_actions',                array( $this, 'filter_user_row_actions' ), 10, 2 );
		add_action( 'plugins_loaded',                  array( $this, 'action_plugins_loaded' ) );
		add_action( 'init',                            array( $this, 'action_init' ) );
		add_action( 'all_admin_notices',               array( $this, 'action_admin_notices' ), 1 );
		add_action( 'wp_logout',                       'wp_clear_olduser_cookie' );
		add_action( 'wp_login',                        'wp_clear_olduser_cookie' );

		# Nice-to-haves:
		add_filter( 'ms_user_row_actions',             array( $this, 'filter_user_row_actions' ), 10, 2 );
		add_filter( 'login_message',                   array( $this, 'filter_login_message' ), 1 );
		add_action( 'wp_footer',                       array( $this, 'action_wp_footer' ) );
		add_action( 'personal_options',                array( $this, 'action_personal_options' ) );
		add_action( 'admin_bar_menu',                  array( $this, 'action_admin_bar_menu' ), 11 );
		add_action( 'bp_member_header_actions',        array( $this, 'action_bp_button' ), 11 );
		add_action( 'bp_directory_members_actions',    array( $this, 'action_bp_button' ), 11 );
		add_action( 'bbp_template_after_user_details', array( $this, 'action_bbpress_button' ) );

	}

	/**
	 * Define the name of the old user cookie. Uses WordPress' cookie hash for increased security.
	 *
	 */
	public function action_plugins_loaded() {
		if ( !defined( 'OLDUSER_COOKIE' ) ) {
			define( 'OLDUSER_COOKIE', 'wordpress_olduser_' . COOKIEHASH );
		}
	}

	/**
	 * Output the 'Switch To' link on the user editing screen if we have permission to switch to this user.
	 *
	 * @param WP_User $user User object for this screen
	 */
	public function action_personal_options( WP_User $user ) {

		if ( ! $link = self::maybe_switch_url( $user ) ) {
			return;
		}

		?>
		<tr>
			<th scope="row"><?php _ex( 'User Switching', 'User Switching title on user profile screen', 'user-switching' ); ?></th>
			<td><a href="<?php echo $link; ?>"><?php _e( 'Switch&nbsp;To', 'user-switching' ); ?></a></td>
		</tr>
		<?php
	}

	/**
	 * Return whether or not the current logged in user is being remembered in the form of a persistent browser
	 * cookie (ie. they checked the 'Remember Me' check box when they logged in). This is used to persist the
	 * 'remember me' value when the user switches to another user.
	 *
	 * @return bool Whether the current user is being 'remembered' or not.
	 */
	public static function remember() {

		$current     = wp_parse_auth_cookie( '', 'logged_in' );
		$cookie_life = apply_filters( 'auth_cookie_expiration', 172800, get_current_user_id(), false );

		# Here we calculate the expiration length of the current auth cookie and compare it to the default expiration.
		# If it's greater than this, then we know the user checked 'Remember Me' when they logged in.
		return ( ( $current['expiration'] - time() ) > $cookie_life );

	}

	/**
	 * Load localisation files and route actions depending on the 'action' query var.
	 *
	 */
	public function action_init() {

		load_plugin_textdomain( 'user-switching', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( !isset( $_REQUEST['action'] ) ) {
			return;
		}

		switch ( $_REQUEST['action'] ) {

			# We're attempting to switch to another user:
			case 'switch_to_user':
				$user_id = absint( $_REQUEST['user_id'] );

				check_admin_referer( "switch_to_user_{$user_id}" );

				# Switch user:
				$user = switch_to_user( $user_id, self::remember() );
				if ( $user ) {

					$redirect_to = self::get_redirect( $user );

					# Redirect to the dashboard or the home URL depending on capabilities:
					if ( $redirect_to ) {
						wp_safe_redirect( add_query_arg( array( 'user_switched' => 'true' ), $redirect_to ) );
					} else if ( !current_user_can( 'read' ) ) {
						wp_redirect( add_query_arg( array( 'user_switched' => 'true' ), home_url() ) );
					} else {
						wp_redirect( add_query_arg( array( 'user_switched' => 'true' ), admin_url() ) );
					}
					die();

				} else {
					wp_die( __( 'Could not switch users.', 'user-switching' ) );
				}
				break;

			# We're attempting to switch back to the originating user:
			case 'switch_to_olduser':

				# Fetch the originating user data:
				if ( !$old_user = self::get_old_user() ) {
					wp_die( __( 'Could not switch users.', 'user-switching' ) );
				}

				check_admin_referer( "switch_to_olduser_{$old_user->ID}" );

				# Switch user:
				if ( switch_to_user( $old_user->ID, self::remember(), false ) ) {

					$redirect_to = self::get_redirect( $old_user );

					if ( $redirect_to ) {
						wp_safe_redirect( add_query_arg( array( 'user_switched' => 'true', 'switched_back' => 'true' ), $redirect_to ) );
					} else {
						wp_redirect( add_query_arg( array( 'user_switched' => 'true', 'switched_back' => 'true' ), admin_url( 'users.php' ) ) );
					}
					die();
				} else {
					wp_die( __( 'Could not switch users.', 'user-switching' ) );
				}
				break;

			# We're attempting to switch off the current user:
			case 'switch_off':

				$user = wp_get_current_user();

				check_admin_referer( "switch_off_{$user->ID}" );

				# Switch off:
				if ( switch_off_user() ) {
					$redirect_to = self::get_redirect();
					if ( $redirect_to ) {
						wp_safe_redirect( add_query_arg( array( 'switched_off' => 'true' ), $redirect_to ) );
					} else {
						wp_redirect( add_query_arg( array( 'switched_off' => 'true' ), home_url() ) );
					}
					die();
				} else {
					wp_die( __( 'Could not switch off.', 'user-switching' ) );
				}
				break;

		}

	}

	/**
	 * Fetch the URL to redirect to for a given user (used after switching).
	 *
	 * @param WP_User|null A WP_User object (optional).
	 * @return string      The URL to redirect to.
	 */
	protected static function get_redirect( WP_User $user = null ) {

		if ( isset( $_REQUEST['redirect_to'] ) and !empty( $_REQUEST['redirect_to'] ) ) {
			$redirect_to = self::remove_query_args( $_REQUEST['redirect_to'] );
		} else {
			$redirect_to = '';
		}

		if ( $user ) {
			$requested_redirect_to = isset( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : '';
			$redirect_to = apply_filters( 'login_redirect', $redirect_to, $requested_redirect_to, $user );
		}

		return $redirect_to;

	}

	/**
	 * Display the 'Switched to {user}' and 'Switch back to {user}' messages in the admin area.
	 *
	 */
	public function action_admin_notices() {
		$user = wp_get_current_user();

		if ( $old_user = self::get_old_user() ) {

			?>
			<div id="user_switching" class="updated">
				<p><?php
					if ( isset( $_GET['user_switched'] ) ) {
						printf( __( 'Switched to %1$s (%2$s).', 'user-switching' ), $user->display_name, $user->user_login );
					}
					$url = add_query_arg( array(
						'redirect_to' => urlencode( self::current_url() )
					), self::switch_back_url( $old_user ) );
					printf( ' <a href="%s">%s</a>.', $url, sprintf( __( 'Switch back to %1$s (%2$s)', 'user-switching' ), $old_user->display_name, $old_user->user_login ) );
				?></p>
			</div>
			<?php

		} else if ( isset( $_GET['user_switched'] ) ) {

			?>
			<div id="user_switching" class="updated">
				<p><?php
					if ( isset( $_GET['switched_back'] ) ) {
						printf( __( 'Switched back to %1$s (%2$s).', 'user-switching' ), $user->display_name, $user->user_login );
					} else {
						printf( __( 'Switched to %1$s (%2$s).', 'user-switching' ), $user->display_name, $user->user_login );
					}
				?></p>
			</div>
			<?php

		}
	}

	/**
	 * Validate the latest item in the old_user cookie and return its user data.
	 *
	 * @return bool|WP_User False if there's no old user cookie or it's invalid, WP_User object if it's present and valid.
	 */
	public static function get_old_user() {
		$cookie = wp_get_olduser_cookie();
		if ( !empty( $cookie ) ) {
			if ( $old_user_id = wp_validate_auth_cookie( end( $cookie ), 'old_user' ) ) {
				return get_userdata( $old_user_id );
			}
		}
		return false;
	}

	/**
	 * Adds a 'Switch back to {user}' link to the account menu in WordPress' admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The admin bar object
	 */
	public function action_admin_bar_menu( WP_Admin_Bar $wp_admin_bar ) {

		if ( !function_exists( 'is_admin_bar_showing' ) ) {
			return;
		}
		if ( !is_admin_bar_showing() ) {
			return;
		}

		if ( method_exists( $wp_admin_bar, 'get_node' ) and $wp_admin_bar->get_node( 'user-actions' ) ) {
			$parent = 'user-actions';
		} else if ( get_option( 'show_avatars' ) ) {
			$parent = 'my-account-with-avatar';
		} else {
			$parent = 'my-account';
		}

		if ( $old_user = self::get_old_user() ) {

			$wp_admin_bar->add_menu( array(
				'parent' => $parent,
				'id'     => 'switch-back',
				'title'  => sprintf( __( 'Switch back to %1$s (%2$s)', 'user-switching' ), $old_user->display_name, $old_user->user_login ),
				'href'   => add_query_arg( array(
					'redirect_to' => urlencode( self::current_url() )
				), self::switch_back_url( $old_user ) )
			) );

		}

		if ( current_user_can( 'switch_off' ) ) {

			$url = self::switch_off_url( wp_get_current_user() );
			if ( !is_admin() ) {
				$url = add_query_arg( array(
					'redirect_to' => urlencode( self::current_url() )
				), $url );
			}

			$wp_admin_bar->add_menu( array(
				'parent' => $parent,
				'id'     => 'switch-off',
				'title'  => __( 'Switch Off', 'user-switching' ),
				'href'   => $url
			) );

		}

	}

	/**
	 * Adds a 'Switch back to {user}' link to the WordPress footer if the admin toolbar isn't showing.
	 *
	 */
	public function action_wp_footer() {

		if ( !is_admin_bar_showing() and $old_user = self::get_old_user() ) {
			$link = sprintf( __( 'Switch back to %1$s (%2$s)', 'user-switching' ), $old_user->display_name, $old_user->user_login );
			$url = add_query_arg( array(
				'redirect_to' => urlencode( self::current_url() )
			), self::switch_back_url( $old_user ) );
			echo '<p id="user_switching_switch_on"><a href="' . $url . '">' . $link . '</a></p>';
		}

	}

	/**
	 * Adds a 'Switch back to {user}' link to the WordPress login screen.
	 *
	 * @param string $message The login screen message
	 * @return string The login screen message
	 */
	public function filter_login_message( $message ) {

		if ( $old_user = self::get_old_user() ) {
			$link = sprintf( __( 'Switch back to %1$s (%2$s)', 'user-switching' ), $old_user->display_name, $old_user->user_login );
			$url = self::switch_back_url( $old_user );
			if ( isset( $_REQUEST['redirect_to'] ) and !empty( $_REQUEST['redirect_to'] ) ) {
				$url = add_query_arg( array(
					'redirect_to' => urlencode( $_REQUEST['redirect_to'] )
				), $url );
			}
			$message .= '<p class="message"><a href="' . $url . '">' . $link . '</a></p>';
		}

		return $message;

	}

	/**
	 * Adds a 'Switch To' link to each list of user actions on the Users screen.
	 *
	 * @param array   $actions The actions to display for this user row
	 * @param WP_User $user    The user object displayed in this row
	 * @return array The actions to display for this user row
	 */
	public function filter_user_row_actions( array $actions, WP_User $user ) {

		if ( ! $link = self::maybe_switch_url( $user ) ) {
			return $actions;
		}

		$actions['switch_to_user'] = '<a href="' . $link . '">' . __( 'Switch&nbsp;To', 'user-switching' ) . '</a>';

		return $actions;
	}

	/**
	 * Adds a 'Switch To' link to each member's profile page and profile listings in BuddyPress.
	 *
	 */
	public function action_bp_button() {

		global $bp, $members_template;

		if ( !empty( $members_template ) and empty( $bp->displayed_user->id ) ) {
			$user = get_userdata( $members_template->member->id );
		} else {
			$user = get_userdata( $bp->displayed_user->id );
		}

		if ( ! $user ) {
			return;
		}
		if ( ! $link = self::maybe_switch_url( $user ) ) {
			return;
		}

		$link = add_query_arg( array(
			'redirect_to' => urlencode( bp_core_get_user_domain( $user->ID ) )
		), $link );

		# Workaround for https://buddypress.trac.wordpress.org/ticket/4212
		$components = array_keys( $bp->active_components );
		if ( !empty( $components ) ) {
			$component = reset( $components );
		} else {
			$component = 'core';
		}

		echo bp_get_button( array(
			'id'         => 'user_switching',
			'component'  => $component,
			'link_href'  => $link,
			'link_text'  => __( 'Switch&nbsp;To', 'user-switching' )
		) );

	}

	/**
	 * Adds a 'Switch To' link to each member's profile page in bbPress.
	 *
	 */
	public function action_bbpress_button() {

		if ( ! $user = get_userdata( bbp_get_user_id() ) ) {
			return;
		}
		if ( ! $link = self::maybe_switch_url( $user ) ) {
			return;
		}

		$link = add_query_arg( array(
			'redirect_to' => urlencode( bbp_get_user_profile_url( $user->ID ) )
		), $link );

		?>
		<ul>
			<li><a href="<?php echo $link; ?>"><?php _e( 'Switch&nbsp;To', 'user-switching' ); ?></a></li>
		</ul>
		<?php

	}

	/**
	 * Helper function. Returns the switch to or switch back URL for a given user.
	 *
	 * @param WP_User $user The user to be switched to.
	 * @return string|bool The required URL, or false if there's no old user or the user doesn't have the required capability.
	 */
	public static function maybe_switch_url( WP_User $user ) {

		$old_user = self::get_old_user();

		if ( $old_user and ( $old_user->ID == $user->ID ) ) {
			return self::switch_back_url( $old_user );
		} else if ( current_user_can( 'switch_to_user', $user->ID ) ) { 
			return self::switch_to_url( $user );
		} else {
			return false;
		}

	}

	/**
	 * Helper function. Returns the nonce-secured URL needed to switch to a given user ID.
	 *
	 * @param WP_User $user The user to be switched to.
	 * @return string The required URL
	 */
	public static function switch_to_url( WP_User $user ) {
		return wp_nonce_url( add_query_arg( array(
			'action'  => 'switch_to_user',
			'user_id' => $user->ID
		), wp_login_url() ), "switch_to_user_{$user->ID}" );
	}

	/**
	 * Helper function. Returns the nonce-secured URL needed to switch back to the originating user.
	 *
	 * @param  WP_User $user The old user.
	 * @return string The required URL
	 */
	public static function switch_back_url( WP_User $user ) {
		return wp_nonce_url( add_query_arg( array(
			'action' => 'switch_to_olduser'
		), wp_login_url() ), "switch_to_olduser_{$user->ID}" );
	}

	/**
	 * Helper function. Returns the nonce-secured URL needed to switch off the current user.
	 *
	 * @param WP_User $user The user to be switched off.
	 * @return string The required URL
	 */
	public static function switch_off_url( WP_User $user ) {
		return wp_nonce_url( add_query_arg( array(
			'action' => 'switch_off'
		), wp_login_url() ), "switch_off_{$user->ID}" );
	}

	/**
	 * Helper function. Returns the current URL.
	 *
	 * @return string The current URL
	 */
	public static function current_url() {
		return ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
	}

	/**
	 * Helper function. Removes a list of common confirmation-style query args from a URL.
	 *
	 * @param string $url A URL
	 * @return string The URL with the listed query args removed
	 */
	public static function remove_query_args( $url ) {
		return remove_query_arg( array(
			'user_switched', 'switched_off', 'switched_back',
			'message', 'update', 'updated', 'settings-updated', 'saved',
			'activated', 'activate', 'deactivate', 'enabled', 'disabled',
			'locked', 'skipped', 'deleted', 'trashed', 'untrashed'
		), $url );
	}

	/**
	 * Helper function. Is the site using SSL?
	 *
	 * This is used to set the 'secure' flag on the 'old_user' cookie, for enhanced security.
	 * Related: https://core.trac.wordpress.org/ticket/15330
	 * 
	 * @return boolean Whether the site is using SSL.
	 */
	public static function is_site_ssl() {
		return ( is_ssl() and 'https' === parse_url( get_option( 'home' ), PHP_URL_SCHEME ) );
	}

	/**
	 * Filter the user's capabilities so they can be added/removed on the fly.
	 *
	 * This is used to grant the 'switch_to_user' capability to a user if they have the ability to edit the user
	 * they're trying to switch to (and that user is not themselves), and to grant the 'switch_off' capability to
	 * a user if they can edit users.
	 *
	 * Important: This does not get called for Super Admins. See filter_map_meta_cap() below.
	 *
	 * @param array $user_caps     User's capabilities
	 * @param array $required_caps Actual required capabilities for the requested capability
	 * @param array $args          Arguments that accompany the requested capability check:
	 *                             [0] => Requested capability from current_user_can()
	 *                             [1] => Current user ID
	 *                             [2] => Optional second parameter from current_user_can()
	 * @return array User's capabilities
	 */
	public function filter_user_has_cap( array $user_caps, array $required_caps, array $args ) {
		if ( 'switch_to_user' == $args[0] ) {
			$user_caps['switch_to_user'] = ( user_can( $args[1], 'edit_user', $args[2] ) and ( $args[2] != $args[1] ) );
		} else if ( 'switch_off' == $args[0] ) {
			$user_caps['switch_off'] = user_can( $args[1], 'edit_users' );
		}
		return $user_caps;
	}

	/**
	 * Filters the actual required capabilities for a given capability or meta capability.
	 *
	 * This is used to add the 'do_not_allow' capability to the list of required capabilities when a super admin
	 * is trying to switch to themselves. It affects nothing else as super admins can do everything by default.
	 *
	 * @param array  $required_caps Actual required capabilities for the requested action
	 * @param string $cap           Capability or meta capability being checked
	 * @param string $user_id       Current user ID
	 * @param array  $args          Arguments that accompany this capability check
	 * @return array Required capabilities for the requested action
	 */
	public function filter_map_meta_cap( array $required_caps, $cap, $user_id, array $args ) {
		if ( ( 'switch_to_user' == $cap ) and ( $args[0] == $user_id ) ) {
			$required_caps[] = 'do_not_allow';
		}
		return $required_caps;
	}

}

/**
 * Sets an authorisation cookie containing the originating user, or appends it if there's more than one.
 *
 * @param int $old_user_id The ID of the originating user, usually the current logged in user.
 */
if ( !function_exists( 'wp_set_olduser_cookie' ) ) {
function wp_set_olduser_cookie( $old_user_id ) {
	$expiration = time() + 172800; # 48 hours
	$cookie     = wp_get_olduser_cookie();
	$cookie[]   = wp_generate_auth_cookie( $old_user_id, $expiration, 'old_user' );
	$secure     = apply_filters( 'secure_logged_in_cookie', user_switching::is_site_ssl(), $old_user_id, is_ssl() );
	setcookie( OLDUSER_COOKIE, json_encode( $cookie ), $expiration, COOKIEPATH, COOKIE_DOMAIN, $secure, true );
}
}

/**
 * Clears the cookie containing the originating user, or pops the latest item off the end if there's more than one.
 *
 * @param bool $clear_all Whether to clear the cookie or just pop the last user information off the end.
 */
if ( !function_exists( 'wp_clear_olduser_cookie' ) ) {
function wp_clear_olduser_cookie( $clear_all = true ) {
	$cookie = wp_get_olduser_cookie();
	if ( !empty( $cookie ) ) {
		array_pop( $cookie );
	}
	if ( $clear_all or empty( $cookie ) ) {
		setcookie( OLDUSER_COOKIE, ' ', time() - 31536000, COOKIEPATH, COOKIE_DOMAIN );
	} else {
		$expiration = time() + 172800; # 48 hours
		$secure = apply_filters( 'secure_logged_in_cookie', user_switching::is_site_ssl(), get_current_user_id(), is_ssl() );
		setcookie( OLDUSER_COOKIE, json_encode( $cookie ), $expiration, COOKIEPATH, COOKIE_DOMAIN, $secure, true );
	}
}
}

/**
 * Gets the value of the cookie containing the list of originating users.
 *
 * @return array Array of originating user authentication cookies. @see wp_generate_auth_cookie()
 */
if ( !function_exists( 'wp_get_olduser_cookie' ) ) {
function wp_get_olduser_cookie() {
	if ( isset( $_COOKIE[OLDUSER_COOKIE] ) ) {
		$cookie = json_decode( stripslashes( $_COOKIE[OLDUSER_COOKIE] ) );
	}
	if ( !isset( $cookie ) or !is_array( $cookie ) ) {
		$cookie = array();
	}
	return $cookie;
}
}

/**
 * Switches the current logged in user to the specified user.
 *
 * @param int  $user_id      The ID of the user to switch to.
 * @param bool $remember     Whether to 'remember' the user in the form of a persistent browser cookie. Optional.
 * @param bool $set_old_user Whether to set the old user cookie. Optional.
 * @return bool|WP_User      WP_User object on success, false on failure.
 */
if ( !function_exists( 'switch_to_user' ) ) {
function switch_to_user( $user_id, $remember = false, $set_old_user = true ) {
	if ( !$user = get_userdata( $user_id ) ) {
		return false;
	}

	if ( $set_old_user and is_user_logged_in() ) {
		$old_user_id = get_current_user_id();
		wp_set_olduser_cookie( $old_user_id );
	} else {
		$old_user_id = false;
		wp_clear_olduser_cookie( false );
	}

	wp_clear_auth_cookie();
	wp_set_auth_cookie( $user_id, $remember );
	wp_set_current_user( $user_id );

	if ( $set_old_user ) {
		do_action( 'switch_to_user', $user_id, $old_user_id );
	} else {
		do_action( 'switch_back_user', $user_id, $old_user_id );
	}

	return $user;
}
}

/**
 * Switches off the current logged in user. This logs the current user out while retaining a cookie allowing them to log straight
 * back in using the 'Switch back to {user}' system.
 *
 * @return bool True on success, false on failure.
 */
if ( !function_exists( 'switch_off_user' ) ) {
function switch_off_user() {
	if ( !$old_user_id = get_current_user_id() ) {
		return false;
	}

	wp_set_olduser_cookie( $old_user_id );
	wp_clear_auth_cookie();

	do_action( 'switch_off_user', $old_user_id );

	return true;
}
}

/**
 * Helper function. Did the current user switch into their account?
 *
 * @return bool|object False if the user isn't logged in or they didn't switch in; old user object (which evalutes to true) if the user switched into the current user account.
 */
if ( !function_exists( 'current_user_switched' ) ) {
function current_user_switched() {
	if ( !is_user_logged_in() ) {
		return false;
	}

	return user_switching::get_old_user();
}
}

global $user_switching;

$user_switching = new user_switching;
