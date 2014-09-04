<?php 
/*
Plugin Name: Hide Inactive Sites
Plugin URI: http://judenware.com/projects/wordpress/hide-inactive-sites/
Description: Changes visibility of a blog after it has had no activity for a specified amount of time.
Author: ericjuden
Version: 1.2.2
Author URI: http://www.judenware.com
Network: true
*/

require_once(ABSPATH . 'wp-admin/includes/plugin.php');    // Needed for is_plugin_active_for_network()
require_once(ABSPATH . 'wp-includes/pluggable.php');

define('HIS_ONE_DAY', 60*60*24*1);
define('HIS_ONE_WEEK',  60*60*24*7);
define('HIS_TWO_WEEK',  60*60*24*7*2);
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

        add_action('hide_inactive_sites_cron', array($this, 'cron'));
        add_action('hide_inactive_sites_warning', array($this, 'warning'));
		add_action(($this->is_network ? 'network_admin_menu' : 'admin_menu'), array($this, 'admin_menu'));
		add_action('init', array($this, 'init'));
		add_filter('cron_schedules', array($this, 'cron_schedules'));
	}
	
	function admin_menu(){
		if($this->is_network){
			add_submenu_page('settings.php', _('Inactive Site Options'), _('Hide Inactive Sites'), 'manage_sites', 'hide-inactive-sites-options', array($this, 'plugin_options'));
		} else {
			add_options_page(_('Inactive Site Options'), _('Hide Inactive Sites'), 8, 'hide-inactive-sites-options', array($this, 'plugin_options'));
		}
	}
    
    function cron(){
        global $wpdb, $blog_id;
        
        $timezone_offset = get_option('gmt_offset');
		$query = "SELECT blog_id, last_updated FROM " . $wpdb->base_prefix . "blogs WHERE spam != '1' AND archived != '1' AND deleted != '1'";
		
		// Check for excluded sites...if none, still exclude site #1
		if(!empty($this->options['excluded_sites']) && $this->options['excluded_sites'][0] != ''){
			$query .= " AND blog_id NOT IN (1,". implode(',', $this->options['excluded_sites']) .")";
		} else {
			$query .= " AND blog_id != '1'";
		}
		
		$query .= " AND last_updated <= '". date('Y-m-d H:i:s', time()-$this->options['inactivity_threshold']+ $timezone_offset * 3600) ."' AND public <> '". $this->options['site_visibility'] ."' ORDER BY last_updated ASC";
		$query = apply_filters('hide_inactive_sites_edit_query', $query);
		$blogs = $wpdb->get_results($query);
		
		foreach($blogs as $blog){
		    // Extend php processing time each time we loop...just to make sure this doesn't fail
			set_time_limit(60);
		    
		    // Get site information
		    $site_details = get_blog_details($blog->blog_id);
		    
		    // Check when site was warned
		    switch_to_blog($blog->blog_id);
		    $warning_time = get_option('hide-inactive-sites-warned', false);
		    switch_to_blog($blog_id);
		    
		    // Has site been warned yet?
		    if($warning_time === false){
		        // Give $this->warning() a chance to work!
		        continue;
		    }
		    
		    // Check if warning threshold has passed
		    if(time() <= $warning_time + $this->options['inactivity_warning_threshold']){
		        continue;
		    }
		        
		    // Check for minimum # posts to be met
		    if(isset($this->options['min_posts']) && $this->options['min_posts'] > 0){
		    	if($site_details->post_count >= $this->options['min_posts']){
		    		continue;	// Has enough post...skip hiding this one
		        }
		    }
		    
		    // Array of arguments to be updated
		    $args = array(
		        'last_updated' => $blog->last_updated,    // Keep last_updated the same as it currently is
		    );
		    
		    if($this->options['site_visibility'] != 'no_change'){
		    	$args['public'] = $this->options['site_visibility']; // Change visibility of blog based on settings
		    }
		    
		    if($this->options['archive_site'] == '1'){
		    	$args['archived'] = 1;
		    }
		    
		    if($this->options['delete_site'] == '1'){
		    	$args['deleted'] = 1;
		    }
		    
		    // Allow developers to update additional info for the site
		    $args = apply_filters('hide_inactive_sites_update_blog', $args, $blog->blog_id);
		    
		    // Update site
		    update_blog_details($blog->blog_id, $args);
		    
		    // Remove option for when the site was warned
		    switch_to_blog($blog->blog_id);
		    delete_option('hide-inactive-sites-warned');
		    switch_to_blog($blog_id);
		    
		    // Send email notifying the user
		    $this->send_site_hidden_email($site_details);
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
    
    function get_inactivity_thresholds(){
        $inactivity_thresholds = array(
	        HIS_ONE_MONTH => __('1 Month'),
	        HIS_THREE_MONTH => __('3 Months'),
	        HIS_SIX_MONTH => __('6 Months'),
	        HIS_ONE_YEAR => __('1 Year'),
	        HIS_TWO_YEAR => __('2 Years')
	    );
	    
	    return apply_filters('hide-inactive-sites-manage-inactivity-thresholds', $inactivity_thresholds);
    }
    
    function get_inactivity_warning_thresholds(){
        $inactivity_warning_thresholds = array(
            HIS_ONE_DAY => __('1 Day'),
            HIS_ONE_WEEK => __('1 Week'),
            HIS_TWO_WEEK => __('2 Weeks'),
	        HIS_ONE_MONTH => __('1 Month'),
	        HIS_THREE_MONTH => __('3 Months'),
	        HIS_SIX_MONTH => __('6 Months'),
	        HIS_ONE_YEAR => __('1 Year')
	    );
	    
	    return apply_filters('hide-inactive-sites-manage-inactivity-warning-thresholds', $inactivity_warning_thresholds);
    }
    
    function get_min_posts(){
        $min_posts = array(
            '0' => __('0'),
            '1' => __('1'),
            '2' => __('2'),
            '3' => __('3'),
            '4' => __('4'),
            '5' => __('5'),
            '10' => __('10'),
            '15' => __('15'),
            '20' => __('20'),
            '25' => __('25')
        );
        
        return apply_filters('hide-inactive-sites-manage-min-posts', $min_posts);
    }
    
    function get_site_admins($blog_id){
	    global $wpdb;
	    
	    $blog_prefix = $wpdb->get_blog_prefix($blog_id);
		$users = $wpdb->get_results( "SELECT user_id, user_id AS ID, user_login, display_name, user_email, meta_value FROM $wpdb->users, $wpdb->usermeta WHERE {$wpdb->users}.ID = {$wpdb->usermeta}.user_id AND meta_key = '{$blog_prefix}capabilities' ORDER BY {$wpdb->users}.display_name" );
	    
	    $admins = array();
		foreach($users as $user){
			$user_details = get_user_by('login', $user->user_login);
			if(user_can($user_details->ID, 'manage_options')){
				$admins[$user_details->ID] = $user_details->user_email;	// Add admin email to array
			}
		}
		$admin_emails = implode(',', $admins);
		
		return $admin_emails;
	}
	
	function get_update_frequencies(){
	    $update_frequencies = array(
	        'daily' => __('Daily'),
	        'monthly' => __('Monthly')
	    );
	    
	    return apply_filters('hide-inactive-sites-manage-update-frequency', $update_frequencies);
	}
	
	function init(){
	    $timezone_offset = get_option('gmt_offset');
        
	    if(!wp_next_scheduled('hide_inactive_sites_warning') && !empty($this->options)){
		    // Schedule next run for warning message
		    wp_schedule_event(time() + $timezone_offset * 3600, $this->options['update_frequency'], 'hide_inactive_sites_warning');
		    
		    $this->warning();
		}
        
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

		$current_page = substr($_SERVER["SCRIPT_NAME"],strrpos($_SERVER["SCRIPT_NAME"],"/")+1);
		
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
		        if(isset($_POST['inactivity_warning_threshold'])){
                    $this->options['inactivity_warning_threshold'] = $_POST['inactivity_warning_threshold'];
                }
                if(isset($_POST['blog_public'])){
                    $this->options['site_visibility'] = $_POST['blog_public'];
                }
                if(isset($_POST['archive_site'])){
                	$this->options['archive_site'] = $_POST['archive_site'];
                } else {
                	$this->options['archive_site'] = '0';
                }
                if(isset($_POST['delete_site'])){
                	$this->options['delete_site'] = $_POST['delete_site'];
                } else {
                	$this->options['delete_site'] = '0';
                }
                if(isset($_POST['excluded_sites'])){
                    if($_POST['excluded_sites'] != ''){
                        $this->options['excluded_sites'] = explode(',', $_POST['excluded_sites']);
                    	if($this->options['excluded_sites'] === false){
                    		$this->options['excluded_sites'] = array();	
                    	}
                    } else {
                	    $this->options['excluded_sites'] = array();
                    }
                }
                if(isset($_POST['min_posts'])){
                	$this->options['min_posts'] = $_POST['min_posts'];
                }
			    
			    if($this->is_network){
			    	update_site_option('hide-inactive-sites-options', $this->options);
			    } else {
			    	update_option('hide-inactive-sites-options', $this->options);
			    }
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
    				    <?php foreach($this->get_update_frequencies() as $key=>$label){ ?>
    					<option value="<?php echo $key; ?>"<?php echo ($this->options['update_frequency'] == $key ? ' selected="selected"' : ''); ?>><?php echo $label; ?></option>
    				    <?php } ?>
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
    				    <?php foreach($this->get_inactivity_thresholds() as $key=>$label){ ?>
    					<option value="<?php echo $key; ?>"<?php echo ($this->options['inactivity_threshold'] == $key ? ' selected="selected"' : ''); ?>><?php echo $label; ?></option>
    				    <?php } ?>
    					<?php do_action('hide-inactive-sites-add-inactivity-thresholds', $this->options); ?>
    				</select>
    			</td>
    		</tr>
    		<tr valign="top">
    			<th scope="row">
    				<strong><?php _e('Inactivity Warning Threshold'); ?></strong><br />
    				<em><?php _e('(How many days before the site is inactive should they be warned?)');?></em>
    			</th>
    			<td>
    				<select name="inactivity_warning_threshold" id="inactivity_warning_threshold">
    				    <?php foreach($this->get_inactivity_warning_thresholds() as $key=>$label){ ?>
    					<option value="<?php echo $key; ?>"<?php echo ($this->options['inactivity_warning_threshold'] == $key ? ' selected="selected"' : ''); ?>><?php echo $label; ?></option>
    				    <?php } ?>
    					<?php do_action('hide-inactive-sites-add-inactivity-warning-thresholds', $this->options); ?>
    				</select>
    			</td>
    		</tr>
    		<tr valign="top">
    			<th scope="row">
    				<strong><?php _e('Inactive Site Visibility'); ?></strong>
    			</th>
    			<td>
    				<input id="blog-nochange" type="radio" name="blog_public" value="no_change"<?php echo ($this->options['site_visibility'] == 'no_change' ? ' checked="checked"' : ''); ?> />
    				<label for="blog-nochange"><?php _e('Do not change'); ?></label><br />
    				<input id="blog-public" type="radio" name="blog_public" value="1"<?php echo ($this->options['site_visibility'] == '1' ? ' checked="checked"' : ''); ?> />
					<label for="blog-public"><?php _e( 'Allow search engines to index this site.' );?></label><br />
					<input id="blog-norobots" type="radio" name="blog_public" value="0"<?php echo ($this->options['site_visibility'] == '0' ? ' checked="checked"' : ''); ?> />
					<label for="blog-norobots"><?php _e( 'Ask search engines not to index this site.' ); ?></label>
					<?php do_action('blog_privacy_selector', $this->options); ?>
    			</td>
    		</tr>
    		<tr valign="top">
    			<th scope="row">
    				<strong><?php _e('Site Options')?></strong>
    			</th>
    			<td>
    				<input type="checkbox" id="archive_site" name="archive_site" value="1"<?php echo ($this->options['archive_site'] == '1' ? ' checked="checked"' : ''); ?> />
    				<label for="archive_site"><?php _e('Archive'); ?></label><br />
    				<input type="checkbox" id="delete_site" name="delete_site" value="1"<?php echo ($this->options['delete_site'] == '1' ? ' checked="checked"' : ''); ?> />
    				<label for="delete_site"><?php _e('Delete'); ?></label><br />
    			</td>
    		</tr>
    		<tr valign="top">
    			<th scope="row">
    				<strong><?php _e('Excluded Sites'); ?></strong><br />
    				<em><?php _e('Blog IDs separated by comma. Do not include main site (1), as it will <strong>always</strong> be excluded.')?></em>
    			</th>
    			<td>
    				<input type="text" name="excluded_sites" id="excluded_sites" value="<?php echo (isset($this->options['excluded_sites'])) ? implode(',', $this->options['excluded_sites']) : ''; ?>" />
    			</td>
    		</tr>
    		<tr valign="top">
    			<th scope="row">
    				<strong><?php _e('Minimum # Posts'); ?></strong><br />
    				<em><?php _e('How many posts will keep this site from being hidden? Leave at <strong>0</strong> to not use.')?></em>
    			</th>
    			<td>
    				<select name="min_posts" id="min_posts">
    				    <?php foreach($this->get_min_posts() as $key=>$label){ ?>
    					<option value="<?php echo $key; ?>"<?php echo ($this->options['min_posts'] == $key ? ' selected="selected"' : ''); ?>><?php echo $label; ?></option>
    				    <?php } ?>
    					<?php do_action('hide-inactive-sites-add-min-posts', $this->options); ?>
    				</select>
    			</td>
    		</tr>
    		</table>
    		<input type="hidden" name="action" value="update" />
    		<input type="hidden" name="page_options" value="update_frequency,inactivity_threshold,inactivity_warning_threshold,blog_public,excluded_sites" />
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
	
    function send_site_warning_email($site_details){
	    global $wpdb, $blog_id;
		
		// Get list of admins to send email to
		$old_blog_id = $blog_id;
		switch_to_blog($site_details->blog_id);
		
		$admin_emails = $this->get_site_admins($site_details->blog_id);
		
		$headers = "From: ". get_site_option('admin_email') ."\r\n";
		$headers .= "Content-Type: text/html\r\n";
		$subject = '[' . get_site_option('site_name') . '] ' . _('Your Site Is Going To Be Hidden');
		$message = '<html><body>';
		$message .= '<p>' . _('Dear Site Administrator,') . '</p>'; 
		$message .= '<p>' . sprintf(__('Your site, %s has been inactive for %d days and will be automatically hidden in %d days if it continues to have no activity.'), '<a href="'. $site_details->siteurl .'">' . $site_details->siteurl .'</a>', round($this->options['inactivity_threshold']/60/60/24), round($this->options['inactivity_warning_threshold']/60/60/24)) . '</p>';
		$message .= '<p>' . _('If you think this was in error, please reply to this email.') . '</p>';
		$message .= '<p>' . _('Thanks,') . '</p>';
		$message .= '<p>' . get_site_option('site_name') . ' ' . _('Administrators') .'</p>';
		$message .= '</body></html>';
		
		$admin_emails = apply_filters('hide_inactive_sites_edit_site_almost_hidden_to_emails', $admin_emails, $site_details);
		$headers = apply_filters('hide_inactive_sites_edit_site_almost_hidden_headers', $headers, $site_details);
		$subject = apply_filters('hide_inactive_sites_edit_site_almost_hidden_subject', $subject, $site_details);
		$message = apply_filters('hide_inactive_sites_edit_site_almost_hidden_message', $message, $site_details);
		
		switch_to_blog($old_blog_id);
		
		return wp_mail($admin_emails, $subject, $message, $headers);
	}
	
    function send_site_hidden_email($site_details){
		global $wpdb, $blog_id;
		
		// Get list of admins to send email to
		$old_blog_id = $blog_id;
		switch_to_blog($site_details->blog_id);
		
		$admin_emails = $this->get_site_admins($site_details->blog_id);
		
		$headers = "From: ". get_site_option('admin_email') ."\r\n";
		$headers .= "Content-Type: text/html\r\n";
		$subject = '[' . get_site_option('site_name') . '] ' . _('Your Site Has Been Hidden');
		$message = '<html><body>';
		$message .= '<p>' . _('Dear Site Administrator,') . '</p>'; 
		$message .= '<p>' . sprintf(__('Your site, %s has been inactive for %d days and was automatically hidden.'), '<a href="'. $site_details->siteurl .'">' . $site_details->siteurl .'</a>', round($this->options['inactivity_threshold']/60/60/24)) . '</p>';
		$message .= '<p>' . _('If you think this was in error, please reply to this email.') . '</p>';
		$message .= '<p>' . _('Thanks,') . '</p>';
		$message .= '<p>' . get_site_option('site_name') . ' ' . _('Administrators') .'</p>';
		$message .= '</body></html>';
		
		$admin_emails = apply_filters('hide_inactive_sites_edit_site_hidden_to_emails', $admin_emails, $site_details);
		$headers = apply_filters('hide_inactive_sites_edit_site_hidden_headers', $headers, $site_details);
		$subject = apply_filters('hide_inactive_sites_edit_site_hidden_subject', $subject, $site_details);
		$message = apply_filters('hide_inactive_sites_edit_site_hidden_message', $message, $site_details);
		
		switch_to_blog($old_blog_id);
		
		return wp_mail($admin_emails, $subject, $message, $headers);
	}
	
	function warning(){
	    global $wpdb;
        
        $timezone_offset = get_option('gmt_offset');
		$query = "SELECT blog_id, last_updated FROM " . $wpdb->base_prefix . "blogs WHERE spam != '1' AND archived != '1' AND deleted != '1'";
		
		// Check for excluded sites...if none, still exclude site #1
		if(!empty($this->options['excluded_sites']) && $this->options['excluded_sites'][0] != ''){
			$query .= " AND blog_id NOT IN (1,". implode(',', $this->options['excluded_sites']) .")";
		} else {
			$query .= " AND blog_id != '1'";
		}
		
		$query .= " AND last_updated <= '". date('Y-m-d H:i:s', time() - $this->options['inactivity_threshold'] - $this->options['inactivity_warning_threshold'] + $timezone_offset * 3600) ."' AND public <> '". $this->options['site_visibility'] ."' ORDER BY last_updated ASC";
		$query = apply_filters('hide_inactive_sites_edit_warning_query', $query);
		$blogs = $wpdb->get_results($query);

		foreach($blogs as $blog){
		    // Extend php processing time each time we loop...just to make sure this doesn't fail
			set_time_limit(60);
		    
		    // Get site information
		    $site_details = get_blog_details($blog->blog_id);
		    
		    // Check for minimum # posts to be met
		    if(isset($this->options['min_posts']) && $this->options['min_posts'] > 0){
		    	if($site_details->post_count >= $this->options['min_posts']){
		    		continue;	// Has enough post...skip hiding this one
		        }
		    }
		    
		    // Check when site was warned
		    switch_to_blog($blog->blog_id);
		    $warning_time = get_option('hide-inactive-sites-warned', false);
		    switch_to_blog($blog_id);
		    
		    // Has site been warned yet?
		    if($warning_time === false){
    		    // Send email notifying the user
    		    $this->send_site_warning_email($site_details);
    		    
    		    // Site has been warned
    		    update_option('hide-inactive-sites-warned', time() + $timezone_offset * 3600);
		    }
		}
	}
}

$hide_inactive_sites = new Hide_Inactive_Sites();
?>