<?php
/*
Plugin Name: Zippem
Plugin URI: wordpress.org/plugins/zippem/
Description: Zip and download any folder on your site
Version: 0.1.5
Author: Eliot Akira
Author URI: eliotakira.com
License: GPL2
*/

class Zippem {

	private $path;
	private $settings;

	function __construct() {

		$this->define_paths();
		$this->settings = get_option('zippem_settings');

		add_action( 'admin_init', array($this, 'zip_register_settings'));
		add_action('admin_menu', array($this, 'zip_create_menu'));
		// Remove "Settings saved" message on admin page
		add_action( 'admin_notices', array($this, 'my_validation_notice'));

		add_action( 'admin_print_scripts', array($this, 'admin_header_scripts'));
		add_action( 'admin_print_footer_scripts', array($this, 'admin_footer_scripts'));
	}


	/*========================================================================
	 *
	 * Define path variables
	 *
	 *=======================================================================*/

	function define_paths() {
		$this->path['root_dir'] = trailingslashit( home_url() );
		$this->path['root_path'] = dirname(dirname(dirname(dirname(__FILE__)))) . '/';
		$this->path['content_dir'] = trailingslashit( content_url() );
		$this->path['content_dir_slug'] = untrailingslashit( str_replace($this->path['root_dir'], "", $this->path['content_dir']) );
		$this->path['theme_slug'] = get_stylesheet();
		// $zip_it_plugin_url = plugins_url() . '/' . basename(dirname(__FILE__)) . '/'; 


		$this->path['plugins_dir_slug'] = $this->path['content_dir_slug'].'/plugins';
		$this->path['uploads_dir_slug'] = $this->path['content_dir_slug'].'/uploads';
		$this->path['theme_dir_slug'] = $this->path['content_dir_slug'].'/themes/'.$this->path['theme_slug'];

	}	

	/*========================================================================
	 *
	 * Get list of files and folders
	 *
	 *=======================================================================*/

	function find_files_and_folders($dir) {

		if (! file_exists($dir) ) {
			echo 'Directory doesn\'t exist.';
			return;
		}

	    $root = scandir($dir);

	    $exclude_files = array('.DS_Store');

	    foreach($root as $value) 
	    { 
	    	// Exclude
	        if($value === '.' || $value === '..' ||
	        	in_array($value,$exclude_files)) {
	        		continue; }

	        // Files
	        if(is_file("$dir/$value")) {
	        	$result['files'][]="$dir/$value"; continue;
	        }

	        // Folders
	        if(is_dir("$dir/$value")) {
	        	$result['folders'][]=$value; continue;
	        }
	    }
	    return $result;
	}


	/*========================================================================
	 *
	 * Recursive zip
	 *
	 *=======================================================================*/

	function zip_scan($zip, $source_dir, $folder_tag) {

		$folder_tag .= '/';

		$folder_content = $this->find_files_and_folders($source_dir);

		$files = $folder_content['files'];
		$subfolders = $folder_content['folders'];

		// Zip all files

		if (!empty($files)) {

			foreach($files as $file) {

	/* For now, .zip files under the source folder will also be zipped

				if ( ($this->ends_with(strtolower($file), ".zip")) ) { // Don't zip zip files
				}
	*/
				// Make sure it doesn't zip itself

				if ( ( $file!=($this->path['root_path'] . $this->zip_target_file()) ) &&
					( $file!=($this->path['root_dir'] . $this->zip_target_file()) ) ) {

					// Add the file to zip

						$zip->addFile($file, 
							$folder_tag . str_replace('/', '', strrchr($file, '/')));
	//				echo 'File: ' . $file . '<br>';
					}
			}
		}

		// Zip all subfolders

		if (!empty($subfolders)) {
			foreach($subfolders as $subfolder) {
	//			echo 'Subfolder: ' . $subfolder . '<br>';
				$this->zip_scan($zip, $source_dir . '/' . $subfolder, $folder_tag . $subfolder );
			}
		}
	}


	/*========================================================================
	 *
	 * Create or delete target zip file
	 *
	 *=======================================================================*/

	function zip_it() {

		if (!($this->ends_with(strtolower($this->zip_target_file()), ".zip"))) {
			echo 'Target file must end with &#34;.zip&#34;.' . '<br>';
			return;
		}

		if ($this->zip_delete_target()!="on")
			echo 'Zipping..';

		$ultimate_source = $this->path['root_path'] . $this->zip_source_folder();
		$ultimate_target = $this->path['root_path'] . $this->zip_target_file();

		if ($this->zip_delete_target()=="on") {
			if (file_exists( $ultimate_target )) {
				if (is_dir( $ultimate_target ))
					echo 'Cannot delete a folder.' . '<br>';
				else {
					unlink($ultimate_target);
					echo 'Deleted: ' . $this->path['root_dir'] . $this->zip_target_file() . '<br>';

				}
			}
			else
				echo 'The target file doesn&#39;t exist.' . '<br>';

			$this->settings['main_options']['delete_target'] = "off"; // Reset: delete target
			update_option( 'zippem_settings', $this->settings );
			return;
		}

		// Delete previous target file

		if (file_exists($ultimate_target))
			unlink($ultimate_target);

		$zip = new ZipArchive();
		$overwrite = true;

		if ($zip->open($ultimate_target, $overwrite ?
			ZIPARCHIVE::OVERWRITE : ZIPARCHIVE::CREATE) !== true) {
				echo '<br><br>';
				echo "There was an error opening the target file.<br/>";
				return;
		}

		// Zip all files and folders

		$this->zip_scan($zip, $ultimate_source, basename($ultimate_target, ".zip") );

		// Close the zip -- done!

		$numFiles = $zip->numFiles;
		$zip->close();

		// Check to make sure the file exists

		if (file_exists( $ultimate_target )) {

			$size = filesize( $ultimate_target );

			if ($numFiles=="1")
				$numFiles = "1 file";
			else
				$numFiles .= " files";

			echo 'done.</div><div id="zippem-keep-message"><br>Created: '. $this->path['root_dir'] . $this->zip_target_file() . '<br><br>';
			echo '<a href="' . $this->path['root_dir'] . $this->zip_target_file() . 
				'">Download</a> (' . $this->format_bytes($size) . ' in ' . $numFiles .
				')<br><br>';
		} else {
			echo '<br><br>';
			echo 'The target file was not created.<br>';
		}
	}


	/*========================================================================
	 *
	 * Admin settings: register page and fields
	 *
	 *=======================================================================*/

	function zip_register_settings() {
		register_setting( 'zip_settings_field', 'zippem_settings', array($this, 'zip_settings_field_validate') );
		add_settings_section('zip_settings_section', '', array($this, 'zip_settings_section_page'), 'zip_settings_section_page_name');
		add_settings_field('zip_settings_source_folder', 'Source folder', array($this, 'zip_settings_field_input_source_folder'), 'zip_settings_section_page_name', 'zip_settings_section');
		add_settings_field('zip_settings_target_file', 'Target file', array($this, 'zip_settings_field_input_target_file'), 'zip_settings_section_page_name', 'zip_settings_section');
		add_settings_field('zip_settings_delete_target', 'Delete target file', array($this, 'zip_settings_field_input_delete_target'), 'zip_settings_section_page_name', 'zip_settings_section');
		add_settings_field('zip_settings_automatic', 'Are you ready?', array($this, 'zip_settings_field_input_automatic'), 'zip_settings_section_page_name', 'zip_settings_section');
	}

	function zip_settings_section_page() { /* */ }

	function zip_settings_field_input_source_folder() {
		?>
			<?php echo $this->path['root_dir']; ?>
				<input type="text" size="30"
					id="zip_settings_source_folder"
					name="zippem_settings[main_options][source_folder]"
					value="<?php echo $this->zip_source_folder(); ?>" />
			&nbsp;
			<div class="zippem-set-source" data-source="">All files</div>
			<div class="zippem-set-source" data-source="<?php echo $this->path['plugins_dir_slug']; ?>">Plugins</div>
			<div class="zippem-set-source" data-source="<?php echo $this->path['uploads_dir_slug']; ?>">Media library</div>
			<div class="zippem-set-source" data-source="<?php echo $this->path['theme_dir_slug']; ?>">Current theme</div>
<?php /*
			<div class="zippem-set-source" data-source="">All files</div> - <i>leave field empty</i><br>
			<div class="zippem-set-source" data-source="<?php echo $this->path['plugins_dir_slug']; ?>">Plugins</div> - <i><?php echo $this->path['plugins_dir_slug']; ?></i><br>
			<div class="zippem-set-source" data-source="<?php echo $this->path['uploads_dir_slug']; ?>">Media library</div> - <i><?php echo $this->path['uploads_dir_slug']; ?></i><br>
			<div class="zippem-set-source" data-source="<?php echo $this->path['theme_dir_slug']; ?>">Current theme</div> - <i><?php echo $this->path['theme_dir_slug']; ?></i><br>
*/ 

	}

	function zip_settings_field_input_target_file() {
		?>
			<div><?php echo $this->path['root_dir']; ?>
				<input type="text" size="30"
					id="zip_settings_target_file"
					name="zippem_settings[main_options][target_file]"
					value="<?php echo $this->zip_target_file(); ?>" />
<?php 
	$ultimate_target = $this->path['root_path'] . $this->zip_target_file();
	if ( $this->zip_automatic()!="on" &&
		$this->zip_delete_target()!="on" && file_exists( $ultimate_target ) ) {
		$size = filesize( $ultimate_target );
		echo '&nbsp;&nbsp;<a href="' . $this->path['root_dir'] . $this->zip_target_file() . 
			'">Download</a> (' . $this->format_bytes($size) . ')';
	}
?>
				<br />
			</div>
		<?php
	}

	function zip_settings_field_input_delete_target() {

			$delete_target_setting = $this->zip_delete_target();

			?>
				<input type="checkbox" id="zip_settings_delete_target"
					name="zippem_settings[main_options][delete_target]"
					<?php checked( $delete_target_setting, 'on' ); ?>/>
				<br />
			<?php
	}


	function zip_settings_field_input_automatic() {

			$automatic_setting = $this->zip_automatic();

			?>
				<input type="checkbox" id="zip_settings_automatic"
					name="zippem_settings[main_options][automatic]"
					<?php checked( $automatic_setting, 'on' ); ?>/>
				<input type="hidden" id="zip_settings_hidden"
					name="zippem_settings[main_options][hidden]" />
				<br />
			<?php
	}

	function zip_settings_field_validate($input) {
		return $input; // Validate somehow?
	}


	/*========================================================================
	 *
	 * Admin settings page
	 *
	 *=======================================================================*/

	function zip_settings_page() {
		?>
		<div class="wrap zippem">
			<h2>Zippem</h2>

			<form method="post" action="options.php">
			    <?php settings_fields( 'zip_settings_field' ); ?>
			    <?php do_settings_sections( 'zip_settings_section_page_name' ); ?>
			    <?php submit_button('Go!'); ?>
			</form>
			<div id="zippem-message">
			<?php

//				$this->zip_it();

				if ($this->zip_automatic()=="on") {
					$this->zip_it();
				}
				elseif ($this->zip_delete_target()=="on") {
					echo 'Make sure you&#39;re ready.';
				}
				$this->settings['main_options']['automatic'] = "off"; // Reset: are you ready?
				update_option( 'zippem_settings', $this->settings );
			?>
			</div>
			<br/><br/>
		</div>
		<?php
	}


	function admin_header_scripts() {
		if ($this->is_plugin_page()) { 
		?>
<style type="text/css" media="screen">
		.zippem-set-source {
			font-weight: bold;
			font-size: 14px;
			display: inline-block;
			background-color: #fff;

			margin: 0 3px 4px 0;
			padding: 4px 8px;

			border: 1px solid #ddd;
			box-shadow: 0 1px 2px rgba(0, 0, 0, 0.07) inset;
			border-radius: 3px;

			color : #555;

			cursor: pointer;
			-webkit-user-select: none;  /* Chrome all / Safari all */
			-moz-user-select: none;     /* Firefox all */
			-ms-user-select: none;      /* IE 10+ */
			-o-user-select: none;
			user-select: none;
		}
		.zippem-set-source:active {
/*			padding: 2px 5px;
			margin: 1px 3px 5px 0px;
*/			background-color: #eee;
		}
		.form-table {
			margin-top: 15px;
		}
		.wrap h2 {
			font-family: serif;
			font-style: italic;
			font-size: 32px;
		}
		.wrap h2, #submit, #zippem-message, #zippem-keep-message {
			margin-left: 10px;
		}
		.form-table tr {
			border: 6px solid #ddd;
/*			border-bottom: 12px solid #eee;
			background-color: #eaeaea; */
		}
		.form-table th {
			width: 180px;
			height: 70px;
			line-height: 40px;
			padding: 0 0 4px 15px;
			border-right: 6px solid #ddd;
/*			border-right: 12px solid #eee; */
			vertical-align: middle;
		}
/*		.form-table td {
			background: #eee;
		}
*/		.form-table th, .form-table td, #submit, #zippem-message, #zippem-keep-message {
			font-size: 16px;
		}
		.zippem input[type="text"] {
			line-height: 18px;
			padding: 3px 4px;
    	}
		#submit {
			height: 30px;
			width: 100px;
		}
</style>
		<?php
		}
	}

	function admin_footer_scripts() {
		if ($this->is_plugin_page()) { 
		?>
<script type="text/javascript">
jQuery(document).ready(function($){
	$('.zippem-set-source').on('click', function(event) {
		event.preventDefault();
		val = $(this).attr('data-source');
		$('input#zip_settings_source_folder').val(val);
	});
	$('#submit').on('click', function(event) {
		ready = $('#zip_settings_automatic').prop("checked");
		if (!ready) {
			event.preventDefault(); // Don't submit if not ready
			$('#zippem-message').html("You're not ready.");
		}
		target = $('input#zip_settings_target_file').val();
		ext = target.substr(target.length - 4);
		if (ext!=".zip") {
			event.preventDefault(); // Don't submit if not ready
			$('#zippem-message').html('Target file must end with ".zip".');
		}
	});
<?php if ($this->zip_automatic()!="on") { ?>
		$('#zip_settings_automatic').prop("checked",false);
<?php } ?>
<?php if ($this->zip_delete_target()!="on") { ?>
		$('#zip_settings_delete_target').prop("checked",false);
<?php } ?>
});
</script>
		<?php
		}
	}



/*========================================================================
 *
 * Helper functions
 *
 *=======================================================================*/

	function is_plugin_page() {
		global $pagenow;
		return ($pagenow == 'tools.php' && $_GET['page'] == 'zippem');
	}


	/*========================================================================
	 *
	 * Settings page callback
	 *
	 *=======================================================================*/

	function my_validation_notice(){
		if ($this->is_plugin_page()) { 
			if ( (isset($_GET['updated']) && $_GET['updated'] == 'true') ||
				(isset($_GET['settings-updated']) && $_GET['settings-updated'] == 'true') ) {
		      //this will clear the update message "Settings Saved" totally
				unset($_GET['settings-updated']);
			}
		}
	}


	/*========================================================================
	 *
	 * Create menu page under Tools -> Zippem
	 *
	 *=======================================================================*/

	function zip_create_menu() {
		add_management_page('Zippem', 'Zippem', 'manage_options', 'zippem', array($this, 'zip_settings_page'));
	}

	/*========================================================================
	 *
	 * Get saved settings
	 *
	 *=======================================================================*/

	function zip_source_folder() {

		if (!isset( $this->settings['main_options']['source_folder'] ))
			return $this->path['uploads_dir_slug'];
		else
			return $this->settings['main_options']['source_folder'];
	}

	function zip_target_file() {

		if( (!isset( $this->settings['main_options']['target_file'] )) ||
			($this->settings['main_options']['target_file'] == '') )
			return $this->path['content_dir_slug'].'/zippem.zip';
//			return $this->path['content_dir_slug'].'/uploads/' . $this->path['theme_slug'] . '.zip';
		else
			return $this->settings['main_options']['target_file'];
	}

	function zip_delete_target() {
		return isset($this->settings['main_options']['delete_target']) ? $this->settings['main_options']['delete_target'] : "off";
	}


	function zip_automatic() {
		return isset($this->settings['main_options']['automatic']) ? $this->settings['main_options']['automatic'] : "off";
	}



	/*========================================================================
	 *
	 * Helper functions
	 *
	 *=======================================================================*/


	function format_bytes($a_bytes) {
	    if ($a_bytes < 1024) {
	        return $a_bytes .' B';
	    } elseif ($a_bytes < 1048576) {
	        return round($a_bytes / 1024, 2) .' KB';
	    } elseif ($a_bytes < 1073741824) {
	        return round($a_bytes / 1048576, 2) . ' MB';
	    } elseif ($a_bytes < 1099511627776) {
	        return round($a_bytes / 1073741824, 2) . ' GB';
	    } elseif ($a_bytes < 1125899906842624) {
	        return round($a_bytes / 1099511627776, 2) .' TB';
	    } elseif ($a_bytes < 1152921504606846976) {
	        return round($a_bytes / 1125899906842624, 2) .' PB';
	    } elseif ($a_bytes < 1180591620717411303424) {
	        return round($a_bytes / 1152921504606846976, 2) .' EB';
	    } elseif ($a_bytes < 1208925819614629174706176) {
	        return round($a_bytes / 1180591620717411303424, 2) .' ZB';
	    } else {
	        return round($a_bytes / 1208925819614629174706176, 2) .' YB';
	    }
	}

	// Check tail of filename

	function ends_with($haystack, $needle) {
	    return $needle === "" || substr($haystack, -strlen($needle)) === $needle;
	}

}

new Zippem;
