<?php 
/*
Plugin Name: Hide Inactive Sites
Plugin URI: http://judenware.com/projects/wordpress/hide-inactive-sites/
Description: Changes visibility of a blog after it has had no activity for a specified amount of time.
Author: ericjuden
Version: 1.0
Author URI: http://www.judenware.com
Network: true
*/

require_once(ABSPATH . 'wp-admin/includes/plugin.php');    // Needed for is_plugin_active_for_network()

define('HIS_ONE_WEEK',  60*60*24*7);
define('HIS_ONE_MONTH', 60*60*24*30);
define('HIS_THREE_MONTH', 60*60*24*30*3);
define('HIS_SIX_MONTH', 60*60*24*30*6);
define('HIS_ONE_YEAR', 60*60*24*365);
define('HIS_TWO_YEAR', 60*60*24*365*2);

class Hide_Inactive_Sites {
	var $is_network;
	var $options;
	
	function __construct(){
		$this->is_network = (function_exists('is_plugin_active_for_network') ? is_plugin_active_for_network('hide-inactive-sites/hide-inactive-sites.php') : false);
		$this->options = ($this->is_network ? get_site_option('hide-inactive-sites-options') : get_option('hide-inactives-site-options'));
		if(empty($this->options)){
			$this->options = array();
		}

        add_action('hide_inactive_sites_cron', array(&$this, 'cron'));
		add_action(($this->is_network ? 'network_admin_menu' : 'admin_menu'), array(&$this, 'admin_menu'));
		add_action('init', array(&$this, 'init'));
		add_filter('cron_schedules', array(&$this, 'cron_schedules'));
	}
	
	function admin_menu(){
		if($this->is_network){
			add_submenu_page('settings.php', _('Inactive Site Options'), _('Hide Inactive Sites'), 'manage_sites', 'hide-inactive-sites-options', array(&$this, 'plugin_options'));
		} else {
			add_options_page(_('Inactive Site Options'), _('Hide Inactive Sites'), 8, 'hide-inactive-sites-options', array(&$this, 'plugin_options'));
		}
	}
    
    function cron(){
        global $wpdb;
        
        $timezone_offset = get_option('gmt_offset');
		$query = "SELECT blog_id, last_updated FROM " . $wpdb->base_prefix . "blogs WHERE spam != '1' AND archived != '1' AND deleted != '1' AND blog_id != '1' AND last_updated <= '". date('Y-m-d H:i:s', time()-$this->options['inactivity_threshold']+ $timezone_offset * 3600) ."' AND public <> '". $this->options['site_visibility'] ."' ORDER BY last_updated ASC";
		$query = apply_filters('hide_inactive_sites_edit_query', $query);
		$blogs = $wpdb->get_results($query);

		foreach($blogs as $blog){
		    set_time_limit(60);
		    $args = array(
		        'last_updated' => $blog->last_updated,    // Keep last_updated the same as it currently is
		        'public' => $this->options['site_visibility'] // Change visibility of blog based on settings
		    );
		    $args = apply_filters('hide_inactive_sites_update_blog', $args, $blog->blog_id);
		    update_blog_details($blog->blog_id, $args);
		}
    }
    
    function cron_schedules(){
        return array(
            'monthly' => array(
                'interval' => '60*60*24*30',
                'display' => __('Once Monthly')
            )
        );
    }
	
	function init(){	    
		if(!wp_next_scheduled('hide_inactive_sites_cron') && !empty($this->options)){
	        // Schedule next run
		    wp_schedule_event(time() + $timezone_offset * 3600, $this->options['update_frequency'], 'hide_inactive_sites_cron');
	        
		    // Go ahead and run
		    $this->cron();
		}
	}
	
	function plugin_options(){
?>
	<div class="wrap">
		<h2><?php _e('Inactive Site Options')?></h2>
	<?php 
		$action = "";
		if(isset($_GET['action'])){
			$action = $_GET['action'];
		}
		
		switch($action){
			case "update":
			    if(isset($_POST['update_frequency'])){
			        if($_POST['update_frequency'] != $this->options['update_frequency']){
    			        $this->options['update_frequency'] = $_POST['update_frequency'];
    			        wp_clear_scheduled_hook('hide_inactive_sites_cron');
			        }
			    }
                if(isset($_POST['inactivity_threshold'])){
                    $this->options['inactivity_threshold'] = $_POST['inactivity_threshold'];
                }
                if(isset($_POST['blog_public'])){
                    $this->options['site_visibility'] = $_POST['blog_public'];
                }
			    
			    if($this->is_network){
			    	update_site_option('hide-inactive-sites-options', $this->options);
			    } else {
			    	update_option('hide-inactive-sites-options', $this->options);
			    }
			    
			    $current_page = substr($_SERVER["SCRIPT_NAME"],strrpos($_SERVER["SCRIPT_NAME"],"/")+1);
    ?>
    		<script>
				window.location="<?php echo $current_page ?>?page=hide-inactive-sites-options&updated=true&updatedmsg=<?php echo urlencode(__('Settings Saved')); ?>";
			</script>
    <?php
		    	break;
		    
        	default:
    ?>
    		<form method="post" action="<?php echo $current_page ?>?page=hide-inactive-sites-options&action=update">
    		<?php wp_nonce_field('update-options'); ?>
    		<table class="form-table">
    		<tr valign="top">
    			<th scope="row">
    				<strong><?php _e('How Often to Check?'); ?></strong>
    			</th>
    			<td>
    				<select name="update_frequency" id="update_frequency">
    					<option value="daily"<?php echo ($this->options['update_frequency'] == 'daily') ? ' selected="selected"' : ''; ?>><?php _e('Daily')?></option>
    					<option value="monthly"<?php echo ($this->options['update_frequency'] == 'monthly') ? ' selected="selected"' : ''; ?>><?php _e('Monthly')?></option>
    					<?php do_action('hide-inactive-sites-add-update-frequency', $this->options); ?>
    				</select>
    			</td>
    		</tr>
    		<tr valign="top">
    			<th scope="row">
    				<strong><?php _e('Inactivity Threshold'); ?></strong><br />
    				<em><?php _e('(How long before a site is inactive?)');?></em>
    			</th>
    			<td>
    				<select name="inactivity_threshold" id="inactivity_threshold">
    					<option value="<?php echo HIS_ONE_WEEK; ?>"<?php echo ($this->options['inactivity_threshold'] == HIS_ONE_WEEK ? ' selected="selected"' : '') ?>><?php _e('1 Week'); ?></option>
    					<option value="<?php echo HIS_ONE_MONTH; ?>"<?php echo ($this->options['inactivity_threshold'] == HIS_ONE_MONTH ? ' selected="selected"' : '') ?>><?php _e('1 Month'); ?></option>
    					<option value="<?php echo HIS_THREE_MONTH; ?>"<?php echo ($this->options['inactivity_threshold'] == HIS_THREE_MONTH ? ' selected="selected"' : '') ?>><?php _e('3 Months'); ?></option>
    					<option value="<?php echo HIS_SIX_MONTH; ?>"<?php echo ($this->options['inactivity_threshold'] == HIS_SIX_MONTH ? ' selected="selected"' : '') ?>><?php _e('6 Months'); ?></option>
    					<option value="<?php echo HIS_ONE_YEAR; ?>"<?php echo ($this->options['inactivity_threshold'] == HIS_ONE_YEAR ? ' selected="selected"' : '') ?>><?php _e('1 Year'); ?></option>
    					<option value="<?php echo HIS_TWO_YEAR; ?>"<?php echo ($this->options['inactivity_threshold'] == HIS_TWO_YEAR ? ' selected="selected"' : '') ?>><?php _e('2 Years'); ?></option>
    					<?php do_action('hide-inactive-sites-add-inactivity-thresholds', $this->options); ?>
    				</select>
    			</td>
    		</tr>
    		<tr valign="top">
    			<th scope="row">
    				<strong><?php _e('Inactive Site Visibility'); ?></strong>
    			</th>
    			<td>
    				<input id="blog-public" type="radio" name="blog_public" value="1"<?php echo ($this->options['site_visibility'] == 1 ? ' checked="checked"' : ''); ?> />
					<label for="blog-public"><?php _e( 'Allow search engines to index this site.' );?></label><br/>
					<input id="blog-norobots" type="radio" name="blog_public" value="0"<?php echo ($this->options['site_visibility'] == 0 ? ' checked="checked"' : ''); ?> />
					<label for="blog-norobots"><?php _e( 'Ask search engines not to index this site.' ); ?></label>
					<?php do_action('blog_privacy_selector'); ?>
    			</td>
    		</tr>
    		</table>
    		<input type="hidden" name="action" value="update" />
    		<input type="hidden" name="page_options" value="update_frequency,inactivity_threshold,blog_public" />
    		<?php settings_fields('hide-inactive-sites_group'); ?>
			<p class="submit"><input type="submit" class="button-primary" value="<?php _e('Save Changes'); ?>" /></p>
    		</form>
    <?php
        break;
	}
		    
    ?>
	</div>
<?php				
	}
}

$hide_inactive_sites = new Hide_Inactive_Sites();
?>