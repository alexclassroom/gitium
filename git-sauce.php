<?php
/*
 * Plugin Name: git sauce
 */

define('GIT_BRANCH', 'master');
require_once __DIR__ . '/git-wrapper.php';

//-----------------------------------------------------------------------------
function _log() {
	if ( func_num_args() == 1 && is_string(func_get_arg(0)) ) {
		error_log(func_get_arg(0));
	} else {
		ob_start();
		$args = func_get_args();
		foreach ( $args as $arg )
			var_dump($arg);
		$out = ob_get_clean();
		error_log($out);
	}
}

//-----------------------------------------------------------------------------
/* Array
(
    [themes] => Array
        (
            [twentytwelve] => `Twenty Twelve` version 1.3
        )
    [plugins] => Array
        (
            [cron-view/cron-gui.php] => `Cron GUI` version 1.03
            [hello-dolly/hello.php] => `Hello Dolly` version 1.6
        )

) */
function git_update_versions() {
  $versions = get_option('git_all_versions', array());

  // get all themes from WP
  $all_themes = wp_get_themes( array( 'allowed' => true ) );
  foreach ( $all_themes as $theme ) :
    $theme_versions[ $theme->Template ] = $theme->Name;
    $version = $theme->Version;
    if ( '' < $version )
      $theme_versions[ $theme->Template ] .= " version $version";
  endforeach;
  if ( ! empty( $theme_versions ) )
    $new_versions['themes'] = $theme_versions;

  // get all plugins from WP
  if ( ! function_exists( 'get_plugins' ) )
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
  $all_plugins = get_plugins();
  foreach ( $all_plugins as $name => $data ) :
    $plugin_versions[ $name ] = $data['Name'];
    if ( '' < $data['Version'] )
      $plugin_versions[ $name ] .= " version " . $data['Version'];
  endforeach;
  if ( ! empty( $plugin_versions ) )
    $new_versions['plugins'] = $plugin_versions;

  update_option('git_all_versions', $new_versions);
}

//-----------------------------------------------------------------------------
function _git_commit_changes($message, $dir = '.', $push_commits = true) {
  global $git;
  $git->add($dir);
  $git->commit($message);
  if ( $push_commits ) {
    $git->pull();
    $git->push('origin', GIT_BRANCH);
  }
  git_update_versions();
}

//-----------------------------------------------------------------------------
function _git_format_message($name, $version = false, $prefix = '') {
  $commit_message = "update";
  if ( $name && $version ) {
    $commit_message = "`$name version $version`";
  } else if ( $name ) {
    $commit_message = "`$name`";
  }
  if ( $prefix ) {
    $commit_message = "$prefix $commit_message";
  }
  return $commit_message;
}

//-----------------------------------------------------------------------------
function git_upgrader_post_install($res, $hook_extra, $result) {
  global $git_changes, $git;

  $type = isset($hook_extra['type']) ? $hook_extra['type'] : 'plugin';
  $action = isset($hook_extra['action']) ? $hook_extra['action'] : 'update';
  $git_dir = $result['destination'];

  if (substr($git_dir, 0, strlen(ABSPATH)) == ABSPATH) {
    $git_dir = substr($git_dir, strlen(ABSPATH));
  }

  switch ( $type ) {
  case 'theme':
    wp_clean_themes_cache();
    $theme_data = wp_get_theme( $result['destination_name'] );
    $name = $theme_data->get('Name');
    $version = $theme_data->get('Version');
    break;
  case 'plugin':
    foreach ( $result['source_files'] as $file ) {
      if ( '.php' != substr($file,-4) ) continue;
      // every .php file is a possible plugin so we check if it's a plugin
      $filepath = trailingslashit($result['destination']) . $file;
      $plugin_data = get_plugin_data( $filepath );
      if ( $plugin_data['Name'] ) {
        $name = $plugin_data['Name'];
        $version = $plugin_data['Version'];
        // We get info from the first plugin in the package
        break;
      }
    }
    break;
  }

  if ( empty( $name ) )
    $name = $result['destination_name'];

  $commit_message = _git_format_message($name,$version,"$action $type");
  _git_commit_changes($commit_message, $git_dir, false);

  return $res;
}
add_filter('upgrader_post_install', 'git_upgrader_post_install', 10, 3);

//-----------------------------------------------------------------------------
function git_upgrader_process_complete($upgrader, $hook_extra) {
  global $git;
  $git->pull();
  $git->push('origin', GIT_BRANCH);
  git_update_versions();
}
add_action('upgrader_process_complete', 'git_upgrader_process_complete', 11, 2);

//-----------------------------------------------------------------------------
function git_check_post_activate_modifications($plugin) {
  global $git;
  if ( $git->is_dirty() ) {
    $plugin_data = get_plugin_data( trailingslashit( WP_PLUGIN_DIR ) . $plugin );
    if ( $plugin_data['Name'] ) {
      $name = $plugin_data['Name'];
      $version = $plugin_data['Version'];
    } else {
      $name = $plugin;
    }
    
    $commit_message = _git_format_message($name,$version,"post activation of");
    _git_commit_changes($commit_message);
  }
}
add_action('activated_plugin','git_check_post_activate_modifications',999);

//-----------------------------------------------------------------------------
function git_check_post_deactivate_modifications($plugin) {
  global $git;
  if ( $git->is_dirty() ) {
    $plugin_data = get_plugin_data( trailingslashit( WP_PLUGIN_DIR ) . $plugin );
    if ( $plugin_data['Name'] ) {
      $name = $plugin_data['Name'];
      $version = $plugin_data['Version'];
    } else {
      $name = $plugin;
    }
    $commit_message = _git_format_message($name,$version,"post deactivation of");
    _git_commit_changes($commit_message);
  }
}
add_action('deactivated_plugin','git_check_post_deactivate_modifications',999);

//-----------------------------------------------------------------------------
function git_check_for_plugin_deletions() {
  global $git;
	if ( 'true' == $_GET['deleted'] ) {
	  $versions = get_option('git_all_versions', array());
	  $all_plugins = $versions['plugins'];
    $uncommited_changes = $git->get_uncommited_changes();
    $removed_plugins = array();
    if ( isset( $uncommited_changes['plugins'] ) ) {
      foreach ( $uncommited_changes['plugins'] as $name => $action )
        if ( 'deleted' == $action )
          $removed_plugins[] = $all_plugins[ $name ];
    }
    $commit_message  = "removed plugin";
    if ( 1 < count( $removed_plugins ) )
      $commit_message .= "s";
	  $removed_plugins = '`' . join('`, `', $removed_plugins) . '`';
	}
  _git_commit_changes("$commit_message $removed_plugins");	
}
add_action('load-plugins.php', 'git_check_for_plugin_deletions');

//-----------------------------------------------------------------------------
function git_check_for_themes_deletions() {
  global $git;
	if ( 'true' == $_GET['deleted'] ) {
	  $versions = get_option('git_all_versions', array());
	  $all_themes = $versions['themes'];
    $uncommited_changes = $git->get_uncommited_changes();
    $removed_themes = array();
    if ( isset( $uncommited_changes['themes'] ) ) {
      foreach ( $uncommited_changes['themes'] as $name => $action )
        if ( 'deleted' == $action )
          $removed_themes[] = $all_themes[ $name ];
    }
    $commit_message  = "removed theme";
    if ( 1 < count( $removed_themes ) )
      $commit_message .= "s";
	  $removed_themes = '`' . join('`, `', $removed_themes) . '`';
	  _git_commit_changes("$commit_message $removed_themes");
	}
}
add_action('load-themes.php', 'git_check_for_themes_deletions');

//-----------------------------------------------------------------------------
// Remove theme/plugin edit page from WP Dashboard
function git_remove_admin_menu_pages() {
	remove_submenu_page( 'themes.php', 'theme-editor.php' );
	remove_submenu_page( 'plugins.php', 'plugin-editor.php' );
}
add_action('admin_init', 'git_remove_admin_menu_pages', 102);

