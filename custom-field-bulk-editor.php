<?php 
require_once ('vendor/autoload.php');
use \Statickidz\GoogleTranslate;
/*

**************************************************************************
Plugin Name: Custom Field Bulk Editor
Plugin URI: http://wordpress.org/extend/plugins/custom-field-bulk-editor/
Description: Allows you to edit your custom fields in bulk. Works with custom post types.
Author: SparkWeb Interactive, Inc.
Version: 1.9.1
Author URI: http://www.sparkweb.net/

**************************************************************************

Copyright (C) 2014 SparkWeb Interactive, Inc.

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

**************************************************************************/

add_action( 'wp_ajax_my_action_submit_terms', 'my_action_submit_terms' );
function my_action_submit_terms() {
	global $wpdb; // this is how you get access to the database
	$arr1 = explode(',', $_POST['id1']);
	$arr2 = explode(',', $_POST['id2']);

	$term1 = get_term_by('slug', $arr1[1], $arr1[0]) ;
	$term2 = get_term_by('slug', $arr2[1], $arr2[0]) ;

	$id1 = $term1->term_taxonomy_id;
	$id2 = $term2->term_taxonomy_id;

	if ($id1 && $id2 && $id1 != $id2) {
		$sql = "insert into $wpdb->term_relationships(object_id,term_taxonomy_id)
			select object_id, " . $id2 . " from $wpdb->term_relationships
			where term_taxonomy_id = '" .$id1 ."' 
			and object_id not in (select object_id from wp_term_relationships where term_taxonomy_id = '" .$id2. "')";

		$result = $wpdb->query($sql);
			echo 'sql:   ' . $sql ;
			echo '    result: ' . var_dump($result) ;
	}else {
		echo 'wrong slugs';
	}

	wp_die(); // this is required to terminate immediately and return a proper response
}


add_action( 'wp_ajax_my_action_create_user', 'my_action_create_user' );
function my_action_create_user() {
	global $wpdb; // this is how you get access to the database
	global $post_id;
	$id1 = intval( $_POST['id1'] );
	$id2 = intval( $_POST['id2'] );

	$user_id = cfbe_create_user	($id1, $id2);
   	echo $user_id;	
	wp_die(); // this is required to terminate immediately and return a proper response
}


add_action( 'wp_ajax_my_action_save', 'my_action_save' );
function my_action_save() {
	global $wpdb; // this is how you get access to the database
	global $post_id;
	$post_id = intval( $_POST['id'] );
	$user_id = intval( $_POST['user_id']);
	cfbe_translate();
	cfbe_replace();
	cfbe_generate_excerpt();
	cfbe_reset_permlink();
	cfbe_assign_user($user_id);
	echo $post_id . 'is done';
	wp_die(); // this is required to terminate immediately and return a proper response
}

//Init
add_action('admin_menu', 'cfbe_init');
function cfbe_init() {

	//Create or Set Settings
	global $cfbe_post_types;
	$cfbe_post_types = get_option("cfbe_post_types");

	//Check for Double Serialization
	if (is_serialized($cfbe_post_types)) {
		$cfbe_post_types = unserialize($cfbe_post_types);
		update_option("cfbe_post_types", $cfbe_post_types);
	}

	//Create Settings if New
	if (!is_array($cfbe_post_types)) cfbe_create_settings();

	//Create Menus
	$post_types = get_post_types();
	$skip_array = array("revision", "attachment", "nav_menu_item", "acf", "acf-field-group", "acf-field");
	foreach ($post_types as $post_type ) {
		if (in_array($post_type, $skip_array)) continue;
		if (in_array($post_type, $cfbe_post_types)) add_submenu_page("edit.php".($post_type != "post" ? "?post_type=".$post_type : ""), __('Bulk Edit Fields'), __('Bulk Edit Fields'), apply_filters('cfbe_menu_display_' . $post_type, 'manage_options'), 'cfbe_editor-'.$post_type, 'cfbe_editor');
	}

	if (isset($_REQUEST['page'])) {
		if (strpos($_REQUEST['page'], "cfbe_editor-") !== false) {
			wp_enqueue_style('cfbe-style', WP_PLUGIN_URL . "/custom-field-bulk-editor/cfbe-style.css");
		}
	}
}


//Main Editor Menu
function cfbe_editor() {
	$post_type = str_replace("cfbe_editor-", "", $_REQUEST['page']);
	$obj = get_post_type_object($post_type);

	$edit_mode = isset($_REQUEST['edit_mode']) ? $_REQUEST['edit_mode'] : 'single';
	$edit_mode_button =  ' <a class="' . (version_compare(get_bloginfo('version'), '3.2', "<") ? "button " : "") . 'add-new-h2" href="edit.php?' . ($post_type != "post" ? "post_type=$post_type&" : "") . 'page=cfbe_editor-' . $post_type . '&edit_mode=' . ($edit_mode == "single" ? "multi" : "single") . '">' . ($edit_mode == "single" ? __("Switch to Multi Value Mode") : __("Switch to Single Value Mode")) . '</a>';

	$multi_value_mode = isset($_REQUEST['multi_value_mode']) ? $_REQUEST['multi_value_mode'] : 'single';
	$multi_mode_button =  ' <a href="edit.php?' . ($post_type != "post" ? "post_type=$post_type&" : "") . 'page=cfbe_editor-' . $post_type . '&edit_mode=multi&multi_value_mode=' . ($multi_value_mode == "single" ? "bulk" : "single") . '">' . ($multi_value_mode == "single" ? __("Switch to Bulk Entry Mode") : __("Switch to Single Entry Mode")) . '</a>';


	echo '<div class="wrap">';
	echo '<div class="icon32 icon32-posts-page" id="icon-edit-pages"><br></div>';
	echo '<h2>Edit Custom Fields For ' . $obj->labels->name . $edit_mode_button . '</h2>';

	//Saved Notice
	if (isset($_GET['saved'])) echo '<div class="updated"><p>' . __('Success! Custom field values have been saved.') . '</p></div>';

	echo "<br />";
	$posts_per_page = isset($_GET['posts_per_page']) ? (int)$_GET['posts_per_page'] : 150;
	$page_number = isset($_GET['page_number']) ? (int)$_GET['page_number'] : 1;
	if ($edit_mode == "multi") {
		echo "<p>" . $multi_mode_button . "</p>";
	}
	echo '<form action="edit.php" name="cfbe_form_1" id="cfbe_form_1" method="get">';
	if ($post_type != "post") echo '<input type="hidden" name="post_type" value="' . htmlspecialchars($post_type) . '" />'."\n";
	echo '<input type="hidden" name="page" value="cfbe_editor-' . htmlspecialchars($post_type) . '" />'."\n";
	echo '<input type="hidden" name="edit_mode" value="' . htmlspecialchars($edit_mode) . '" />'."\n";
	echo '<input type="hidden" name="multi_value_mode" value="' . htmlspecialchars($multi_value_mode) . '" />'."\n";
	echo 'page number:<input type="text" name="page_number" value="' . htmlspecialchars($page_number) . '" />'."\n";
	echo 'posts_per_page:<input type="text" name="posts_per_page" value="' . htmlspecialchars($posts_per_page) . '" />'."\n";

	$args = array(
		"post_type" => $post_type,
		"posts_per_page" => $posts_per_page,
		"post_status" => array("publish", "pending", "draft", "future", "private"),
		"order" => "ASC",
		"orderby" => "id",
		"paged" => $page_number,
	);

	//Search
	$searchtext = "";
	if (isset($_GET['searchtext'])) {
		$searchtext = esc_attr($_GET["searchtext"]);

		//Date
		if (strpos($searchtext, "..") !== false) {
			$date_array = explode("..", $searchtext);
			$start_date = trim($date_array[0]);
			$end_date = trim($date_array[1]);
			if (!$start_date || $start_date == "x") {
				$start_date = "1970-01-01";
			}
			if (!$end_date || $end_date == "x") {
				$end_date = "now";
			}
			$args["date_query"] = array(
				array(
					'after'     => $start_date,
					'before'    => $end_date,
					'inclusive' => true,
				),
			);
		//Regular Search
		} else {
			$args["s"] = $_GET["searchtext"];
		}
	}
	$source_term = '<label > source</label>';
	$source_term .=  '<select name="source_term" id="source_term">';
	$target_term = '<label > target</label>';
	$target_term .=  '<select name="target_term" id="target_term">';

	$taxonomies = get_object_taxonomies($post_type);
	foreach ($taxonomies AS $taxonomy) {
		$tax = get_taxonomy($taxonomy);
		$terms = get_terms($taxonomy, array('parent'=>0, 'orderby' => 'count', 'order' => 'DESC'));
		if (count($terms) == 0) continue;
		$tax_name = $tax->label;
		if (isset($_GET[$taxonomy])) {
			$query_slug = $_GET[$taxonomy];
			$arg_taxonomy = $taxonomy;
			if ($arg_taxonomy == "post_tag") $arg_taxonomy = "tag";
			if ($arg_taxonomy == "category") $arg_taxonomy = "category_name";
			if ($query_slug != ""){
				$args[$arg_taxonomy] = $query_slug;
			}
		} else {
			$query_slug = "";
		}

		$source_term .='<option value="' . $taxonomy . '">' . $tax_name . '</option>';
		$target_term .='<option value="' . $taxonomy . '">' . $tax_name . '</option>';
		echo '<label for="' . $taxonomy . '">' . $tax_name . ' :  </label>';
		echo '<select name="' . $taxonomy . '" id="' . $taxonomy . '" class="postform">';
		echo '<option value="">' . sprintf(__('Show All of the %s'), $tax_name) . '</option>'."\n";
		foreach ($terms as $term) {
			echo '<option value='. $term->slug . ($term->slug == $query_slug ? ' selected="selected"' : '') . '>' . $term->term_id . $term->name .' (' . $term->count .')</option>';
			//get sub category0
	    $subterms = get_terms($taxonomy, array('parent'=>$term->term_id, 'orderby' => 'count', 'order' => 'DESC'));
			foreach ($subterms as $sub) {
				echo '<option value='. $sub->slug . ($sub->slug == $query_slug ? ' selected="selected"' : '') . '>--'. $sub->term_id .  $sub->name .' (' . $sub->count .')</option>';
			}
		}
		echo "</select>";
	}
	$source_term .= '</select>';
	$target_term .= '</select>';
	echo $source_term;
	echo $target_term;

	echo '<label for="searchtext">' . __("Search") . '</label>';
	echo '<input type="text" name="searchtext" id="searchtext" value="' . $searchtext . '" />';
	echo '<input type="submit" value="Apply" class="button" />';
	echo '<input type="button" class="button-primary" name="ajax_submit_terms" id="ajax_submit_terms" value="Ajax submit terms" style="margin-right: 15px;" />';
	echo '<label for="searchtext">' ._("&#39640;&#23572;&#22827;&#29699;") . '&#39640;&#23572;&#22827;&#29699;' . '</label>';
	echo '</form>'."\n\n";



	echo '<form action="options.php" name="cfbe_form_2" id="cfbe_form_2" method="post">';
	echo '<input type="hidden" name="cfbe_save" value="1" />'."\n";
	echo '<input type="hidden" name="cfbe_current_max" id="cfbe_current_max" value="3" />'."\n";
	echo '<input type="hidden" name="cfbe_post_type" value="' . esc_attr($post_type) . '" />'."\n";
	echo '<input type="hidden" name="edit_mode" value="' . esc_attr($edit_mode) . '" />'."\n";
	echo '<input type="hidden" name="multi_value_mode" value="' . esc_attr($multi_value_mode) . '" />'."\n";
	if (isset($_REQUEST['search'])) {
		echo '<input type="hidden" name="search" value="' . esc_attr($_REQUEST['search']) . '" />'."\n";
	}
	if (isset($_REQUEST['search'])) {
		echo '<input type="hidden" name="search" value="' . esc_attr($_REQUEST['search']) . '" />'."\n";
	}
	if (isset($_REQUEST['page_number'])) {
		echo '<input type="hidden" name="page_number" value="' . esc_attr($_REQUEST['page_number']) . '" />'."\n";
	}
	if (isset($_REQUEST['posts_per_page'])) {
		echo '<input type="hidden" name="posts_per_page" value="' . esc_attr($_REQUEST['posts_per_page']) . '" />'."\n";
	}
	wp_nonce_field('cfbe-save');

	$all_posts = new WP_Query($args);
	
	echo "<pre>" . $all_posts->request . "</pre>";

	//echo "<pre>" . print_r($all_posts, 1) . "</pre>";
	?>
	<table cellspacing="0" class="wp-list-table widefat fixed posts cfbe-table">
	<thead>
	<tr>
		<?php if ($edit_mode == "single") { ?><th class="manage-column column-cb check-column" id="cb" scope="col"><input type="checkbox"></th><?php } ?>
		<th style="" class="manage-column column-id" id="id" scope="col"><?php _e("ID") ?></th>
		<th style="" class="manage-column column-title desc" id="title" scope="col"><span><?php _e("Title") ?></span></th>
		<?php if ($edit_mode == "multi" && $multi_value_mode != "bulk") { ?>
		<th class="manage-column column-fieldname desc" id="fieldname" scope="col"><span><?php _e("Field Name") ?></span></th>
		<th class="manage-column column-fieldvalue desc" id="fieldname" scope="col"><span><?php _e("Field Value") ?></span></th>
		<?php } ?>
	</tr>
	</thead>

	<tfoot>
	<tr>
		<?php if ($edit_mode == "single") { ?><th class="manage-column column-cb check-column" id="cb" scope="col"><input type="checkbox"></th><?php } ?>
		<th style="" class="manage-column column-id" id="id" scope="col"><?php _e("ID") ?></th>
		<th style="" class="manage-column column-title desc" id="title" scope="col"><span><?php _e("Title") ?></span></th>
		<?php if ($edit_mode == "multi" && $multi_value_mode != "bulk") { ?>
		<th class="manage-column column-fieldname desc" id="fieldname" scope="col"><span><?php _e("Field Name") ?></span></th>
		<th class="manage-column column-fieldvalue desc" id="fieldname" scope="col"><span><?php _e("Field Value") ?></span></th>
		<?php } ?>
	</tr>
	</tfoot>

	<tbody id="the-list">

	<?php
	$i = 1;
	$tabindex = 10000;
	while ($all_posts->have_posts()) {
		$all_posts->the_post();
		$post = $all_posts->post;
		echo '<tr valign="top" class="' . ($i % 2 ? 'alternate ' : '') . 'format-default" id="post-' . $post->ID . '" rel="' . $i . '">';
		if ($edit_mode == "single") echo '<th class="check-column" scope="row" style="padding: 9px 0;"><input type="checkbox" value="' . $post->ID . '" name="post[]"></th>';
		echo '<td class="id column-id">' . $post->ID . '</td>';
		echo '<td class="post-title page-title column-title"><strong><a title="Edit" href="post.php?post=' . $post->ID . '&amp;action=edit" class="row-title">' . $i . ')' . $post->post_title . '</a>' . ($post->post_status != 'publish' ? ' - ' . ucwords($post->post_status) : '') . '</strong></td>';
		if ($edit_mode == "multi") {
			echo '<input type="hidden" value="' . $post->ID . '" name="post[]">' . "\n";
			if ($multi_value_mode != "bulk") {
				echo '<td class="post-fieldname column-fieldname"><input type="text" name="cfbe_multi_fieldname_' . $post->ID . '" id="cfbe_multi_fieldname_' . $post->ID . '" value="" class="cfbe_multi_fieldname" data-postid="' . $post->ID . '" tabindex="' . ($tabindex) .'" /> <a href="#" class="fill_down button" rel="' . $post->ID . '">Fill</a></td>';
				echo '<td class="post-fieldvalue column-fieldnvalue"><textarea name="cfbe_multi_fieldvalue_' . $post->ID . '" id="cfbe_multi_fieldvalue_' . $post->ID . '" class="cfbe_multi_fieldvalue" tabindex="' . ($tabindex + 1) .'"></textarea></td>';
			}
		}
		echo "</tr>\n";
		$i++;
		$tabindex = $tabindex + 2;
	}
	?>
		</tbody>
	</table>

	<?php
	if ($edit_mode == "multi" && $multi_value_mode == "bulk") {

		echo '<p>Please enter the field names in the left column and the field values in the right column. They will be applied to the post ID\'s in the order they appear above. You can leave a field blank to not apply anything.</p>';

		echo '<textarea name="multi_bulk_name" style="float: left; height: 180px; width: 40%; margin-right: 10px;"></textarea>';
		echo '<textarea name="multi_bulk_value" style="float: left; height: 180px; width: 40%;"></textarea>';
		echo '<div style="clear: both;"></div>' . "\n";



	} elseif ($edit_mode == "single") {
		do_action('cfbe_before_metabox', $post_type);
		?>

	<table class="widefat cfbe_table">
		<thead>
			<tr>
				<th><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABmJLR0QAAAAAAAD5Q7t/AAABM0lEQVR42sWSv0sCcRjGP/mDhiSCTEUQCa4xcGhqcKjBCKGlza2lxT+gob8naEtuSIgMd8WpLTy973EcceqJh1zp3bepg2gpL+iBF97pfT/P+7zwBzoH5IpVW4vF4vL2ponvB/hL/8dbhRBcXV+SCAIfz3vn6aHzK+y554a9rNfrMoIN5Crq9/sSkLGoCSQ+m7eXZxbmEN/USRYVBsU8YiTQxzpKRiG/vodh2RiWzW4hSyGdAiAkWJhDUuVT4js53FYDMRJU9ivkNnOoPRXDsjk+LJHZ3qLZ7oYE4QDf1HEf71gaGsF0gj7WUXsqg9EAZ+5gWDb37Q66+Yozc79bSBYV3FaDYDph4+gMJZNG7ak4c4dqqUpaZmm2uzgzl5PywZc7REohJNA0beUkahGe6IJ/1wc0yhZckNURBgAAAABJRU5ErkJggg==" alt="" /><?php _e('Set Custom Field Values For Checked '); echo $obj->labels->name; ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			do_action('cfbe_before_fields', $post_type);
			for ($i = 1; $i <= 3; $i++) :
			?>
			<tr>
				<td>
					<label for="cfbe_name_<?php echo $i; ?>"><?php _e('Custom Field Name'); ?>:</label>
					<input type="text" name="cfbe_name_<?php echo $i; ?>" id="cfbe_name_<?php echo $i; ?>" value="" class="cfbe_field_name" />

					<label for="cfbe_value_<?php echo $i; ?>"><?php _e('Value'); ?>:</label>
					<textarea name="cfbe_value_<?php echo $i; ?>" id="cfbe_value_<?php echo $i; ?>" class="cfbe_field_value"></textarea>

					<div style="clear: both;"></div>

				</td>
			</tr>
			<?php endfor; ?>
			<tr id="cfbe_more_tr">
				<td>
					<input type="button" id="cfbe_morebutton" name="cfbe_morebutton" class="button" value="<?php _e('Add More Fields'); ?>" />
					<span class="cfbe_hint">Hint: To remove a field from a record, enter its name and leave its value empty</span>

				</td>

			</tr>
		</tbody>
	</table>

	<!-- Rename Custom Field Name -->
	<p><a href="#" id="change_cf_name"><?php _e('Want to change a custom field name?'); ?></a></p>
	<table class="widefat cfbe_table" id="change_cf_name_table" style="display: none;">
		<thead>
			<tr>
				<th><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABmJLR0QAAAAAAAD5Q7t/AAABM0lEQVR42sWSv0sCcRjGP/mDhiSCTEUQCa4xcGhqcKjBCKGlza2lxT+gob8naEtuSIgMd8WpLTy973EcceqJh1zp3bepg2gpL+iBF97pfT/P+7zwBzoH5IpVW4vF4vL2ponvB/hL/8dbhRBcXV+SCAIfz3vn6aHzK+y554a9rNfrMoIN5Crq9/sSkLGoCSQ+m7eXZxbmEN/USRYVBsU8YiTQxzpKRiG/vodh2RiWzW4hSyGdAiAkWJhDUuVT4js53FYDMRJU9ivkNnOoPRXDsjk+LJHZ3qLZ7oYE4QDf1HEf71gaGsF0gj7WUXsqg9EAZ+5gWDb37Q66+Yozc79bSBYV3FaDYDph4+gMJZNG7ak4c4dqqUpaZmm2uzgzl5PywZc7REohJNA0beUkahGe6IJ/1wc0yhZckNURBgAAAABJRU5ErkJggg==" alt="" /><?php _e('Change Custom Field Name'); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td>
					<label for="cfbe_fieldname_1"><?php _e('Original Custom Field Name'); ?>:</label>
					<input type="text" name="cfbe_fieldname_1" id="cfbe_fieldname_1" value="" class="cfbe_field_name" />
					<label for="cfbe_fieldname_2"><?php _e('New Custom Field Name'); ?>:</label>
					<input type="text" name="cfbe_fieldname_2" id="cfbe_fieldname_2" value="" class="cfbe_field_name" />
					<div style="clear: both;"></div>

				</td>
			</tr>
		</tbody>
	</table>

	<?php }
?>
	<p> Translate option
		<select name="translation_operation" id='translation_operation'>
			<option value="none">Don't translate</option>
			<option value="en:zh_CN">en:zh_CN</option>
			<option value="zh_TW:zh_CN">zh_TW:zh_CN</option>
			<option value="zh_CN:zh_TW">zh_CN:zh_TW</option>
		</select>
	</p>
	<p> Regex Replace Option:
		<input placeholder="source pattern" type="text" name="pattern" id="pattern" value="" class="cfbe_field_name" />
		<input placeholder='replacement' type="text" name="replacement" id="replacement" value="" class="cfbe_field_name" />
	</p>

	<p> 
		<input type="checkbox" name="gen_excerpt" id='gen_excerpt' value='on'  />generate excerpt
		<input type="checkbox" name="res_permlink" id="res_permlink" value="on"  />reset permlink
	</p>

	<p> 
		<span id="submittername">...</span>
	</p>

	<p>
		<input type="submit" class="button-primary" value="<?php _e('Save Custom Fields'); ?>" style="margin-right: 15px;" />

		<input type="button" class="button-primary" name='ajax_submit' id='ajax_submit' value="<?php _e('Ajax submit'); ?>" style="margin-right: 15px;" />
		<label for="cfbe_add_new_values"><input type="checkbox" name="cfbe_add_new_values" id="cfbe_add_new_values"<?php if (isset($_GET['cfbe_add_new_values'])) echo ' checked="checked"'; ?> /> Add New Custom Fields Instead of Updating (this allows you to create multiple values per name)</label>
	</p>
	</form>

	<div style="clear: both;"></div>

	<script type="text/javascript">
	jQuery(document).ready(function($){

		$("#change_cf_name").click(function() {
			$(this).hide();
			$("#change_cf_name_table").show();
			$("#cfbe_fieldname_1").focus();
			return false;
		});

		$("#ajax_submit_terms").click(function() {
			var term1 = $("#source_term").val();
			var term2 = $("#target_term").val();

			var id1 = term1 + ',' + $("#" + term1).val();
			var id2 = term2 + ',' + $("#" + term2).val();

			console.log('id1= ' + id1);
			console.log('id2= ' + id2);

			var data = {
				'action': 'my_action_submit_terms',
				'id1': id1 ,
				'id2': id2
			};

			alert('about to submit:');
			jQuery.post(ajaxurl, data, function(response) {
				alert(response);
			});
			return false;
		});


		$("#ajax_submit").click(function() {
		    var arr = $( 'input:checkbox:checked').map(function(){
				if($(this).val() != 'on') {
					return $(this).val();
				}
	      	}).get(); // <---- get all checked checkbox

			if (arr.length == 0) {
				return false;
			}
			var data = {
				'action': 'my_action_create_user',
				'id1': arr[0],
				'id2': arr[arr.length -1] 
			};

			jQuery.post(ajaxurl, data, function(response) {
				console.log('create user Got this from the server: ' + response);
				console.log('translation: ' +  $("#translation_operation").val());
				$.ajaxSetup({
				    async: false
				});

				jQuery.each( arr, function( i, val ) {
					if (val != 'on') {
						var data = {
							'action': 'my_action_save',
							'id': val,
							'user_id': response,
							'gen_excerpt':  $("#gen_excerpt").val(),
							'res_permlink':  $("#res_permlink").val(),
							'translation_operation':  $("#translation_operation").val(),
							'pattern':  $("#pattern").val(),
							'replacement':  $("#replacement").val()
						};

						jQuery.post(ajaxurl, data, function(response) {
							$("#submittername").text( i + ': ' + response);
						});

					}
				});
			});
			return false;
		});
		//Set To Make Sure We Aren't Getting Funny Values
		$("#cfbe_current_max").val(3);

		$("#cfbe_morebutton").click(function() {
			current_max = parseInt($("#cfbe_current_max").val());

			var newfields = "";
			for (i = 1; i <= 3; i++) {
				new_id = current_max + i;
				newfields += '<tr><td>';
				newfields += '<label for="cfbe_name_' + new_id + '"><?php _e('Custom Field Name'); ?>:</label>';
				newfields += '<input type="text" name="cfbe_name_' + new_id + '" id="cfbe_name_' + new_id + '" value="" class="cfbe_field_name" />';
				newfields += '<label for="cfbe_value_' + new_id + '"><?php _e('Value'); ?>:</label>';
				newfields += '<textarea name="cfbe_value_' + new_id + '" id="cfbe_value_' + new_id + '" class="cfbe_field_value"></textarea>';
				newfields += '<div style="clear: both;"></div>';
				newfields += '</td></tr>';
			}
			$("#cfbe_more_tr").before(newfields);

			$("#cfbe_current_max").val(current_max + 3);
			return false;
		});

		$(".cfbe_multi_fieldname").blur(function() {
			var postid = $(this).data("postid");
			var data = {
				'action': 'cfbe_lookup_meta_value',
				'post_id': postid,
				'field_name': $(this).val()
			};
			$.post(ajaxurl, data, function(response) {
				$("#cfbe_multi_fieldvalue_" + postid).val(response);
			});
		});

		$(".fill_down").click(function() {
			var fieldname = $("#cfbe_multi_fieldname_" + $(this).attr("rel")).val();
			var parent_rel = $(this).parents("tr").attr("rel");
			$(".cfbe-table > tbody > tr").each(function() {
				this_rel = $(this).attr("rel");
				if (parseInt(this_rel) > parseInt(parent_rel)) {
					$(this).find(".cfbe_multi_fieldname").val(fieldname).trigger("blur");
				}
			});
			return false;
		});

	});
	</script>

	<?php

	echo '</form>';
	echo '</div">';
}

function cfbe_reset_permlink(){
	global $post_id;
	$permlink = isset($_POST['res_permlink'])? $_POST['res_permlink']:'';
	if ($permlink == 'on') {
		$post = get_post($post_id);
		$post->post_name = '';//reset permlink
		wp_update_post($post);
	}
}

function cfbe_generate_excerpt(){
	global $post_id;
	global $wpdb;
	$excerpt = isset($_POST['gen_excerpt'])? $_POST['gen_excerpt']:'';
	if ($excerpt == 'on') {
		$sql="update wp_posts set post_excerpt=left(replace(replace(post_content,'<p>',''),'</p>',''),100)  where id='" . $post_id . "'";
	    $wpdb->query($sql);
	}
}

function cfbe_translate_one($source, $target, $str) {
	if($source == 'zh_CN' && $target == 'zh_TW') {
		return str_chinese_trad($str);	
	}else if ($source == 'zh_TW' && $target == 'zh_CN') {
		return str_chinese_simp($str);
	}else {
		$trans = new GoogleTranslate();
		return $trans->translate($source, $target, $post->post_title);
	}
}
function cfbe_translate(){
	global $post_id;
	//jchen translation
	$op = isset($_POST['translation_operation']) ? $_POST['translation_operation']: ' ';	
	$arr = explode(":", $op);
	if(sizeof($arr) > 1) {
		$source = $arr[0]; 
		$target = $arr[1];
		$trans = new GoogleTranslate();
		$post = get_post($post_id);
		$post->post_title = cfbe_translate_one($source, $target, $post->post_title);
		$post->post_content = cfbe_translate_one($source, $target, $post->post_content);
		$post->post_excerpt = cfbe_translate_one($source, $target, $post->post_excerpt);
		wp_update_post($post);
	}
}

function cfbe_replace(){
	global $post_id;
	//jschen replace
	$pattern = isset($_POST['pattern'])? $_POST['pattern']:'';
	if (strlen($pattern) > 0){
		$pattern = '/' . str_replace('\\\\','\\',$pattern) . '/i';
	}
	$replacement = isset($_POST['replacement']) ? $_POST['replacement'] :'';

	if (strlen($pattern) > 0) {
		$post = get_post($post_id);
		$post->post_title =preg_replace($pattern, $replacement, $post->post_title);
		$post->post_content = preg_replace($pattern, $replacement, $post->post_content);
		wp_update_post($post);
	}
}

function cfbe_create_user($start_id, $end_id) {
	$user_name = $start_id .	'-' . $end_id;
	$user_id = username_exists( $user_name );
	if (!$user_id) {
		$user_id = wp_create_user( $user_name, $user_name, $user_name . '@gmail.com' );
	}
	return $user_id;
}
function cfbe_assign_user($user_id){
	global $post_id;
	global $wpdb;
	if($user_id != '-1') {
		$sql = "update $wpdb->posts set post_author=" . $user_id . ' where id='. $post_id . " or (post_type='attachment' and post_parent=" . $post_id . ')';
	    $wpdb->query($sql);
	}
}
//Save Custom Field
add_action('admin_init', 'cfbe_save');
function cfbe_save() {
	global $post_id, $wpdb;

	//Bail if not called or authenticated
	$actionkey = (isset($_POST['cfbe_save']) ? $_POST['cfbe_save'] : "");
	if ($actionkey != "1" || !check_admin_referer('cfbe-save')) return;

	//Setup
	$post_type = (isset($_POST['cfbe_post_type']) ? $_POST['cfbe_post_type'] : "");
	$posts = (isset($_POST['post']) ? $_POST['post'] : array());
	$edit_mode = $_POST['edit_mode'] == "multi" ? "multi" : "single";

	//Multi-value Method Array Setup
	$multi_value_mode = isset($_POST['multi_value_mode']) ? $_POST['multi_value_mode'] : 'single';
	$arr_names = array();
	$arr_values = array();
	if ($multi_value_mode == "bulk") {
		$lines1 = preg_split("/(\r\n|\n|\r)/", trim($_POST['multi_bulk_name']));
		$lines2 = preg_split("/(\r\n|\n|\r)/", trim($_POST['multi_bulk_value']));
		for ($i = 0; $i < count($lines1); $i++) {
			$arr_names[$i] = isset($lines1[$i]) ? $lines1[$i] : '';
			$arr_values[$i] = isset($lines2[$i]) ? $lines2[$i] : '';
		}
	}

	if(sizeof($posts) > 0) {
		$user_id =cfbe_create_user($posts[0], $posts[sizeof($posts) - 1]);
	}
	//Loop Through Each Saved Post
	$current_record_count = 0;
	foreach ($posts AS $post) {
		$post_id = (int)$post;

		cfbe_translate();
		cfbe_replace();
		cfbe_generate_excerpt();
		cfbe_reset_permlink();
		cfbe_assign_user($user_id);

		//Multi Value
		if ($edit_mode == "multi") {

			//Bulk Edit Mode
			if ($multi_value_mode == "bulk") {

				$skip = 0;
				if (!isset($lines1[$current_record_count]) || !isset($lines2[$current_record_count])) $skip = 1;
				if (!$skip) if (!$lines1[$current_record_count] || !$lines2[$current_record_count]) $skip = 1;
				if (!$skip) {
					//echo $post_id . " write: " . $arr_names[$current_record_count] . " = " . $arr_values[$current_record_count] . "<br>\n";
					cfbe_save_meta_data($arr_names[$current_record_count], $arr_values[$current_record_count]);
				}



			//Multi-edit Mode
			} elseif (!empty($_POST['cfbe_multi_fieldname_'.$post_id])) {
				//echo 'EDIT ' . $post_id . ': ' . $_POST['cfbe_multi_fieldname_'.$post_id] . ' = ' . $_POST['cfbe_multi_fieldvalue_'.$post_id] . '<br />';
				cfbe_save_meta_data($_POST['cfbe_multi_fieldname_'.$post_id], $_POST['cfbe_multi_fieldvalue_'.$post_id]);
			}


		//Single Value
		} else {

			do_action('cfbe_save_fields', $post_type, $post_id);
			for($i=1; $i<=$_POST['cfbe_current_max']; $i++) {
				if (!empty($_POST['cfbe_name_'.$i])) {
					//echo 'EDIT ' . $post_id . ': ' . $_POST['cfbe_name_'.$i] . ' = ' . $_POST['cfbe_value_'.$i] . '<br />';
					cfbe_save_meta_data($_POST['cfbe_name_'.$i], $_POST['cfbe_value_'.$i]);
				}
			}
		}

		//Change Field Name
		if (isset($_POST['cfbe_fieldname_1']) && isset($_POST['cfbe_fieldname_2'])) {
			if ($_POST['cfbe_fieldname_1'] && $_POST['cfbe_fieldname_2']) {
				$sql = "UPDATE $wpdb->postmeta SET meta_key = '" . esc_sql($_POST['cfbe_fieldname_2']) . "' WHERE post_id = {$post_id} AND meta_key = '" . esc_sql($_POST['cfbe_fieldname_1']) . "'";
				$wpdb->query($sql);
			}
		}

		$current_record_count++;
	}

	$post_link = $post_type != "post" ? "post_type=$post_type&" : "";
	$url = "edit.php?" . $post_link . "page=cfbe_editor-$post_type&edit_mode={$edit_mode}&saved=1";
	$url .= "&multi_value_mode=" . $multi_value_mode;
	$url .= isset($_POST['cfbe_add_new_values']) ? '&cfbe_add_new_values=1' : '';
	$url .= isset($_POST['search']) ? '&search=' . $_POST['search'] : '';
	$url .= isset($_POST['posts_per_page']) ? '&posts_per_page=' . $_POST['posts_per_page'] : '';
	$url .= isset($_POST['page_number']) ? '&page_number=' . $_POST['page_number'] : '';
	wp_redirect(admin_url($url));
	exit;
}


//Settings Menu
add_action('admin_menu', 'cfbe_settings_menu');
function cfbe_settings_menu() {
	add_submenu_page('options-general.php', __('Custom Fields Bulk Editor Settings'), __('Bulk Editor Settings'), 'manage_options', 'cfbe_settings', 'cfbe_settings');
}
function cfbe_settings() {
	global $cfbe_post_types;

	echo '<div class="wrap">';
	echo '<div class="icon32" id="icon-options-general"><br></div>';
	echo '<h2>' . __('Custom Fields Bulk Editor Settings') . '</h2>';

	//Saved Notice
	if (isset($_GET['saved'])) echo '<div class="updated"><p>' . __('Your Settings Have Been Saved.') . '</p></div>';

	echo '<form action="options.php" name="cfbe_form_1" id="cfbe_form_1" method="post">';
	echo '<input type="hidden" name="cfbe_settings_save" value="1" />'."\n";
	wp_nonce_field('cfbe-settings-save');

	?>
	<br />

	<table class="widefat cfbe_table">
		<thead>
			<tr>
				<th><img src="data:image/png;base64,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" alt="" /><?php _e('Enable on Post Types'); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			$post_types = get_post_types();
			$skip_array = array("revision", "attachment", "nav_menu_item", "acf");
			foreach ($post_types as $post_type ) :
				if (in_array($post_type, $skip_array)) continue;
				$obj = get_post_type_object($post_type);
			?>
			<tr>
				<td>
					<input type="checkbox" name="cfbe_post_type_<?php echo $post_type; ?>" id="cfbe_post_type_<?php echo $post_type; ?>"<?php if (in_array($post_type, $cfbe_post_types)) echo ' checked="checked"'; ?> />
					<label for="cfbe_post_type_<?php echo $post_type; ?>"><?php echo $obj->labels->name; ?></label>
					<div style="clear: both;"></div>

				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p><input type="submit" class="button-primary" value="<?php _e('Save Settings'); ?>" /></p>
	</form>

	<div style="clear: both;"></div>
	<?php
}

//Save Settings
add_action('admin_init', 'cfbe_save_settings');
function cfbe_save_settings() {

	//Bail if not called or authenticated
	$actionkey = (isset($_POST['cfbe_settings_save']) ? $_POST['cfbe_settings_save'] : "");
	if ($actionkey != "1" || !check_admin_referer('cfbe-settings-save')) return;

	//Save Settings
	$cfbe_post_types = array();
	$post_types = get_post_types();
	$skip_array = array("revision", "attachment", "nav_menu_item");
	foreach ($post_types as $post_type ) {
		if (isset($_POST['cfbe_post_type_'.$post_type])) $cfbe_post_types[] = $post_type;
	}
	update_option("cfbe_post_types", $cfbe_post_types);

	wp_redirect(admin_url("options-general.php?page=cfbe_settings&saved=1"));
	exit;
}




//Saving Functions
function cfbe_save_meta_data($fieldname,$input) {
	global $post_id;
	$current_data = get_post_meta($post_id, $fieldname, TRUE);
 	$new_data = $input;
 	if (!$new_data || $new_data == "") $new_data = NULL;
 	cfbe_meta_clean($new_data);

	if ($current_data && is_null($new_data)) {
		delete_post_meta($post_id,$fieldname);
	} elseif ($current_data && !isset($_POST['cfbe_add_new_values'])) {
		update_post_meta($post_id,$fieldname,$new_data);
	} elseif (!is_null($new_data)) {
		add_post_meta($post_id,$fieldname,$new_data);
	}
}


function cfbe_meta_clean(&$arr) {
	if (is_array($arr)) {
		foreach ($arr as $i => $v) {
			if (is_array($arr[$i]))  {
				cfbe_meta_clean($arr[$i]);
				if (!count($arr[$i])) unset($arr[$i]);
			} else  {
				if (trim($arr[$i]) == '') unset($arr[$i]);
			}
		}
		if (!count($arr)) $arr = NULL;
	}
}

function custom_excerpt_length( $length ) {
	return 200;
}
add_filter( 'excerpt_length', 'custom_excerpt_length', 999 );

//Display Settings Link on Plugin Screen
add_filter('plugin_action_links', 'cfbe_plugin_action_links', 10, 2);
function cfbe_plugin_action_links($links, $file) {
	static $this_plugin;
	if (!$this_plugin) $this_plugin = "custom-field-bulk-editor/custom-field-bulk-editor.php";
	if ($file == $this_plugin) {
		$settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/options-general.php?page=cfbe_settings">Settings</a>';
		array_unshift($links, $settings_link);
	}
	return $links;
}

//Create Settings for Post Type Selection
function cfbe_create_settings() {
	global $cfbe_post_types;
	$cfbe_post_types = array();
	$post_types = get_post_types();
	$skip_array = array("revision", "attachment", "nav_menu_item");
	foreach ($post_types as $post_type ) {
		if (in_array($post_type, $skip_array)) continue;
		$cfbe_post_types[] = $post_type;
	}
	update_option("cfbe_post_types", $cfbe_post_types);
}

add_action( 'wp_ajax_cfbe_lookup_meta_value', 'cfbe_lookup_meta_value_callback' );
function cfbe_lookup_meta_value_callback() {
	echo get_post_meta($_POST['post_id'], $_POST['field_name'], 1);
	die();
}
/*
	
  *mbstring are required*
  Convert to Traditional Chinese 
  Convert to Simplified Chinese
  
  author: Francis Sem
  	 
  Copyright 2007 francissem@gmail.com 
  Licensed under the Apache License, Version 2.0 (the "License"); 
  you may not use this file except in compliance with the License. 
  You may obtain a copy of the License at http://www.apache.org/licenses/
  LICENSE-2.0 Unless required by applicable law or agreed to in writing,
  software distributed under the License is distributed on an "AS IS" BASIS,
  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. 
  See the License for the specific language governing permissions and
  limitations under the License. 
  
  ---------------------------------------------------------------------------
  
  Example:
  
  //to Simplified
  str_chinese_simp("教育部標準字體");
  
  //to Traditional
  str_chinese_trad("教育部标准字体");
  
*/

function uniord($c) {
    $h = ord($c{0});
    if ($h <= 0x7F) {
        return $h;
    } else if ($h < 0xC2) {
        return false;
    } else if ($h <= 0xDF) {
        return ($h & 0x1F) << 6 | (ord($c{1}) & 0x3F);
    } else if ($h <= 0xEF) {
        return ($h & 0x0F) << 12 | (ord($c{1}) & 0x3F) << 6
                                 | (ord($c{2}) & 0x3F);
    } else if ($h <= 0xF4) {
        return ($h & 0x0F) << 18 | (ord($c{1}) & 0x3F) << 12
                                 | (ord($c{2}) & 0x3F) << 6
                                 | (ord($c{3}) & 0x3F);
    } else {
        return false;
    }
}


function unichr($c) {
    if ($c <= 0x7F) {
        return chr($c);
    } else if ($c <= 0x7FF) {
        return chr(0xC0 | $c >> 6) . chr(0x80 | $c & 0x3F);
    } else if ($c <= 0xFFFF) {
        return chr(0xE0 | $c >> 12) . chr(0x80 | $c >> 6 & 0x3F)
                                    . chr(0x80 | $c & 0x3F);
    } else if ($c <= 0x10FFFF) {
        return chr(0xF0 | $c >> 18) . chr(0x80 | $c >> 12 & 0x3F)
                                    . chr(0x80 | $c >> 6 & 0x3F)
                                    . chr(0x80 | $c & 0x3F);
    } else {
        return false;
    }
}



mb_internal_encoding("UTF-8");



function str_chinese_convert($str,$cv="t2s"){
	static $_t2smap;
	static $_s2tmap;
	
	//var_dump(mb_convert_encoding($str,"BIG5"));
	if(!isset($_t2smap)){
		$_t2smap=array
		(
			23586 => 23588,
			20839 => 20869,
			21243 => 21248,
			24340 => 21514,
			25142 => 25143,
			20874 => 20876,
			26414 => 26415,
			27710 => 27867,
			19999 => 20002,
			20121 => 20120,
			20235 => 27762,
			20807 => 20982,
			27737 => 27745,
			27726 => 27867,
			20295 => 20267,
			20308 => 21344,
			20296 => 24067,
			20812 => 20817,
			21029 => 21035,
			21034 => 21024,
			21555 => 21556,
			21570 => 21525,
			21558 => 21584,
			22250 => 22257,
			22767 => 22766,
			22846 => 22841,
			22941 => 22918,
			24439 => 20223,
			27784 => 27785,
			27770 => 20915,
			27798 => 20914,
			27794 => 27809,
			27789 => 20913,
			28797 => 28798,
			29280 => 23427,
			31167 => 31171,
			35211 => 35265,
			35997 => 36125,
			36554 => 36710,
			36806 => 36836,
			20006 => 24182,
			20126 => 20122,
			20358 => 26469,
			20341 => 24182,
			20374 => 20177,
			20818 => 20799,
			20841 => 20004,
			21332 => 21327,
			21369 => 24676,
			22989 => 22999,
			23622 => 23626,
			23713 => 20872,
			24447 => 20315,
			25291 => 25243,
			25306 => 25340,
			26044 => 20110,
			26119 => 21319,
			26481 => 19996,
			27519 => 27521,
			27841 => 20917,
			29229 => 20105,
			29376 => 29366,
			31176 => 31868,
			31998 => 32416,
			32651 => 33416,
			33253 => 21351,
			36555 => 36711,
			38263 => 38271,
			38272 => 38376,
			20448 => 20384,
			20406 => 20387,
			20418 => 31995,
			20407 => 23616,
			20823 => 20822,
			20881 => 32964,
			21070 => 21049,
			21067 => 20811,
			21063 => 21017,
			21185 => 21170,
			21371 => 21364,
			22864 => 22850,
			23014 => 22904,
			23629 => 23608,
			24101 => 24069,
			24421 => 24422,
			24460 => 21518,
			24646 => 24658,
			26613 => 26629,
			26548 => 25296,
			27958 => 27769,
			27945 => 27844,
			28858 => 20026,
			30403 => 26479,
			31047 => 21482,
			32002 => 32419,
			32005 => 32418,
			32000 => 32426,
			32009 => 32427,
			32007 => 32421,
			32004 => 32422,
			32006 => 32417,
			33511 => 33486,
			35284 => 31563,
			35336 => 35745,
			35330 => 35746,
			35331 => 35747,
			35998 => 36126,
			36000 => 36127,
			36557 => 20891,
			36556 => 36712,
			38274 => 38377,
			38859 => 38886,
			38913 => 39029,
			39080 => 39118,
			39131 => 39134,
			20515 => 20223,
			20502 => 24184,
			20486 => 20457,
			20497 => 20204,
			20480 => 20261,
			20491 => 20010,
			20523 => 20262,
			20489 => 20179,
			20941 => 20923,
			21083 => 21018,
			21085 => 21093,
			21729 => 21592,
			23067 => 23089,
			23403 => 23385,
			23470 => 23467,
			23805 => 23777,
			23798 => 23707,
			23796 => 23704,
			24107 => 24072,
			24235 => 24211,
			24465 => 24452,
			24677 => 32827,
			24709 => 24742,
			25406 => 25375,
			26178 => 26102,
			26185 => 26187,
			26360 => 20070,
			27683 => 27668,
			28039 => 27902,
			28025 => 27971,
			28879 => 20044,
			29433 => 29421,
			29437 => 29384,
			29574 => 20857,
			30045 => 20137,
			30384 => 30129,
			30770 => 28846,
			31061 => 31192,
			31056 => 20305,
			32033 => 32442,
			32023 => 32433,
			32011 => 32441,
			32020 => 32431,
			32016 => 32445,
			32021 => 32432,
			32026 => 32423,
			32028 => 32429,
			32013 => 32435,
			32025 => 32440,
			32027 => 32439,
			33029 => 32961,
			33032 => 33033,
			33467 => 21005,
			33610 => 33606,
			33586 => 20857,
			34937 => 21482,
			35352 => 35760,
			35344 => 35750,
			35342 => 35752,
			35340 => 35751,
			35349 => 35754,
			35338 => 35759,
			35351 => 25176,
			35347 => 35757,
			35350 => 35755,
			35912 => 23682,
			36001 => 36130,
			36002 => 36129,
			36562 => 36713,
			36564 => 36715,
			36852 => 22238,
			37336 => 38025,
			37341 => 38024,
			37335 => 38026,
			37337 => 38027,
			38275 => 38378,
			38499 => 38453,
			38493 => 38485,
			38488 => 38473,
			38494 => 21319,
			38587 => 21482,
			39138 => 39269,
			39340 => 39532,
			39717 => 26007,
			20094 => 24178,
			20602 => 21681,
			20605 => 20266,
			20553 => 20255,
			20597 => 20390,
			20596 => 20391,
			21209 => 21153,
			21205 => 21160,
			21312 => 21306,
			21443 => 21442,
			21854 => 21713,
			21839 => 38382,
			21816 => 24565,
			21859 => 34900,
			21847 => 21846,
			22283 => 22269,
			22533 => 22362,
			22538 => 22441,
			22519 => 25191,
			22816 => 22815,
			23105 => 23044,
			23142 => 22919,
			23560 => 19987,
			23559 => 23558,
			23644 => 23625,
			23842 => 23781,
			23825 => 26118,
			23833 => 20177,
			23847 => 23913,
			23831 => 23703,
			24118 => 24102,
			24115 => 24080,
			24373 => 24352,
			24375 => 24378,
			24427 => 38613,
			24478 => 20174,
			24480 => 24469,
			24765 => 20932,
			24757 => 24581,
			25458 => 21367,
			25457 => 25384,
			25475 => 25195,
			25499 => 25346,
			25451 => 25194,
			25476 => 25249,
			25497 => 25379,
			25505 => 37319,
			25448 => 33293,
			25943 => 36133,
			21855 => 21551,
			25944 => 21465,
			26028 => 26025,
			26205 => 26172,
			21207 => 21206,
			26751 => 26438,
			26820 => 24323,
			26772 => 26624,
			26781 => 26465,
			26783 => 26541,
			27578 => 26432,
			27691 => 27682,
			28092 => 20937,
			28154 => 27973,
			28149 => 28170,
			28114 => 20932,
			28122 => 27882,
			28138 => 27814,
			28136 => 20928,
			29309 => 29301,
			29465 => 29424,
			29694 => 29616,
			29986 => 20135,
			30050 => 27605,
			30064 => 24322,
			30526 => 20247,
			30787 => 26417,
			32070 => 32458,
			32067 => 24358,
			32113 => 32479,
			32046 => 25166,
			32057 => 32461,
			32060 => 32459,
			32064 => 32460,
			32048 => 32454,
			32051 => 32453,
			32068 => 32452,
			32066 => 32456,
			32050 => 32449,
			32049 => 32450,
			32573 => 38069,
			32722 => 20064,
			33059 => 21767,
			33067 => 33073,
			33065 => 20462,
			33698 => 33626,
			33686 => 33550,
			33674 => 24196,
			33703 => 33483,
			34389 => 22788,
			34899 => 26415,
			34974 => 34926,
			35219 => 35269,
			35215 => 35268,
			35370 => 35775,
			35357 => 35766,
			35363 => 35776,
			35365 => 35767,
			35377 => 35768,
			35373 => 35774,
			35359 => 35772,
			35355 => 35769,
			35362 => 27427,
			36009 => 36137,
			36012 => 36131,
			36011 => 36143,
			36008 => 36135,
			36010 => 36138,
			36007 => 36139,
			36571 => 36717,
			36575 => 36719,
			36889 => 36825,
			36899 => 36830,
			36885 => 24452,
			37365 => 38039,
			37350 => 25187,
			37347 => 38035,
			37351 => 38031,
			37353 => 38034,
			38281 => 38381,
			38515 => 38472,
			38520 => 38470,
			38512 => 38452,
			38914 => 39030,
			38915 => 39031,
			39770 => 40060,
			40165 => 40479,
			40565 => 21348,
			40613 => 40614,
			20642 => 23478,
			20633 => 22791,
			20625 => 26480,
			20630 => 20263,
			20632 => 20254,
			20634 => 25928,
			20977 => 20975,
			21108 => 21056,
			21109 => 21019,
			21214 => 21171,
			21213 => 32988,
			21211 => 21195,
			21930 => 20007,
			21934 => 21333,
			21938 => 21727,
			21914 => 21796,
			21932 => 20052,
			21931 => 21507,
			22285 => 22260,
			22575 => 23591,
			22580 => 22330,
			22577 => 25253,
			22557 => 22490,
			22778 => 22774,
			23207 => 23090,
			23563 => 23547,
			23888 => 23706,
			24128 => 24103,
			24131 => 24079,
			24190 => 20960,
			24257 => 21397,
			24258 => 21410,
			24489 => 22797,
			24801 => 24694,
			24758 => 38391,
			24860 => 24812,
			24827 => 24699,
			24817 => 24700,
			25536 => 25315,
			25582 => 25381,
			25563 => 25442,
			25562 => 25196,
			25593 => 32972,
			26839 => 26531,
			26847 => 26635,
			26855 => 26632,
			26866 => 26646,
			27453 => 38054,
			27544 => 27531,
			27580 => 22771,
			27692 => 27689,
			28263 => 28044,
			28234 => 20945,
			28187 => 20943,
			28198 => 28065,
			28271 => 27748,
			28204 => 27979,
			28222 => 27985,
			28185 => 28067,
			28961 => 26080,
			29494 => 29369,
			29754 => 29648,
			29990 => 33487,
			30059 => 30011,
			30169 => 30153,
			30176 => 37240,
			30332 => 21457,
			30428 => 30423,
			30543 => 22256,
			30831 => 30746,
			31240 => 31174,
			31237 => 31246,
			31558 => 31508,
			31565 => 31499,
			32094 => 32478,
			32080 => 32467,
			32104 => 32466,
			32085 => 32477,
			32114 => 19997,
			32097 => 32476,
			32102 => 32473,
			32098 => 32474,
			32112 => 32470,
			32115 => 32475,
			32901 => 32899,
			33102 => 32958,
			33081 => 32960,
			33784 => 28895,
			33775 => 21326,
			33780 => 24245,
			33879 => 30528,
			33802 => 33713,
			33799 => 33484,
			34395 => 34394,
			35222 => 35270,
			35387 => 27880,
			35424 => 21647,
			35413 => 35780,
			35422 => 35789,
			35388 => 35777,
			35393 => 35778,
			35412 => 35791,
			35419 => 35781,
			35408 => 35784,
			35398 => 35787,
			35380 => 35785,
			35386 => 35786,
			35382 => 35779,
			36015 => 36142,
			36028 => 36148,
			36019 => 36144,
			36029 => 36155,
			36033 => 36146,
			36027 => 36153,
			36032 => 36154,
			36020 => 36149,
			36023 => 20080,
			36022 => 36140,
			36031 => 36152,
			36024 => 36151,
			36603 => 36722,
			36600 => 36724,
			36604 => 36726,
			36913 => 21608,
			36914 => 36827,
			37109 => 37038,
			37129 => 20065,
			37396 => 38046,
			37397 => 38062,
			37411 => 38041,
			37385 => 38048,
			37406 => 38055,
			37389 => 38045,
			37392 => 38052,
			37393 => 38051,
			38292 => 38389,
			38287 => 38384,
			38283 => 24320,
			38289 => 38386,
			38291 => 38388,
			38290 => 38386,
			38286 => 38387,
			38538 => 38431,
			38542 => 38454,
			38525 => 38451,
			38532 => 22564,
			38642 => 20113,
			38860 => 38887,
			38917 => 39033,
			38918 => 39034,
			38920 => 39035,
			39146 => 39274,
			39151 => 39277,
			39145 => 39272,
			39154 => 39278,
			39149 => 39276,
			39342 => 20911,
			39341 => 39533,
			40643 => 40644,
			20098 => 20081,
			20653 => 20323,
			20661 => 20538,
			20659 => 20256,
			20677 => 20165,
			20670 => 20542,
			20663 => 20260,
			20655 => 20588,
			20679 => 25134,
			21111 => 38130,
			21222 => 21119,
			21218 => 21183,
			21219 => 31215,
			21295 => 27719,
			21966 => 21527,
			21959 => 21868,
			21978 => 21596,
			21958 => 21595,
			22290 => 22253,
			22291 => 22278,
			22615 => 28034,
			22618 => 20898,
			22602 => 22359,
			22626 => 22366,
			22610 => 22488,
			22603 => 33556,
			22887 => 22885,
			23229 => 22920,
			23228 => 23210,
			24185 => 24178,
			24264 => 21414,
			24338 => 24337,
			24409 => 27719,
			24492 => 24439,
			24859 => 29233,
			24900 => 26647,
			24909 => 24864,
			24894 => 24574,
			24884 => 24582,
			24887 => 24698,
			25662 => 27048,
			25613 => 25439,
			25654 => 25250,
			25622 => 25671,
			25623 => 25443,
			25606 => 26500,
			26249 => 26198,
			26248 => 26197,
			26264 => 26104,
			26371 => 20250,
			26989 => 19994,
			26997 => 26497,
			26954 => 26472,
			26984 => 26722,
			26963 => 26539,
			27506 => 23681,
			27584 => 27585,
			28317 => 27807,
			28357 => 28781,
			28348 => 28287,
			28331 => 28201,
			28310 => 20934,
			28356 => 27815,
			29017 => 28895,
			29033 => 28902,
			29001 => 28860,
			29036 => 28800,
			29029 => 28949,
			29014 => 26262,
			29242 => 29239,
			29509 => 29422,
			29807 => 29701,
			29759 => 29682,
			30070 => 24403,
			30194 => 40635,
			30202 => 30201,
			30430 => 30415,
			30558 => 30544,
			30556 => 30529,
			31103 => 31108,
			33836 => 19975,
			31260 => 26865,
			31263 => 31104,
			31680 => 33410,
			31591 => 31509,
			31925 => 31908,
			32147 => 32463,
			32121 => 32482,
			32145 => 25414,
			32129 => 32465,
			32143 => 32485,
			32091 => 32486,
			32681 => 20041,
			32680 => 32673,
			32854 => 22307,
			33144 => 32928,
			33139 => 33050,
			33131 => 32959,
			33126 => 33041,
			33911 => 33636,
			33894 => 33479,
			33865 => 21494,
			33845 => 33716,
			34396 => 34383,
			34399 => 21495,
			34555 => 34581,
			34566 => 34476,
			35036 => 34917,
			35037 => 35013,
			35041 => 37324,
			35018 => 34949,
			35228 => 30522,
			35435 => 35815,
			35442 => 35813,
			35443 => 35814,
			35430 => 35797,
			35433 => 35799,
			35440 => 35800,
			35463 => 22840,
			35452 => 35801,
			35427 => 35811,
			35488 => 35802,
			35441 => 35805,
			35461 => 35803,
			35437 => 35809,
			35426 => 35810,
			35438 => 35808,
			35436 => 35807,
			36042 => 36156,
			36039 => 36164,
			36040 => 36158,
			36036 => 36159,
			36018 => 36160,
			36035 => 36161,
			36034 => 36162,
			36037 => 36165,
			36321 => 36857,
			36611 => 36739,
			36617 => 36733,
			36606 => 36732,
			36618 => 36734,
			36786 => 20892,
			36939 => 36816,
			36938 => 28216,
			36948 => 36798,
			36949 => 36829,
			36942 => 36807,
			37138 => 37049,
			37431 => 38068,
			37463 => 38067,
			37432 => 38073,
			37437 => 38072,
			37440 => 38078,
			37438 => 38080,
			37467 => 38085,
			37451 => 21032,
			37476 => 38057,
			37457 => 38082,
			37428 => 38083,
			37449 => 38089,
			37453 => 38091,
			37445 => 24040,
			37433 => 38093,
			37439 => 38079,
			37466 => 38086,
			38296 => 38392,
			38549 => 38504,
			38603 => 38589,
			38651 => 30005,
			38928 => 39044,
			38929 => 39037,
			38931 => 39039,
			38922 => 39036,
			38930 => 39041,
			38924 => 39042,
			39164 => 39282,
			39156 => 39284,
			39165 => 39281,
			39166 => 39280,
			39347 => 39536,
			39345 => 39534,
			39348 => 39535,
			40169 => 40480,
			20718 => 31461,
			20709 => 20389,
			20693 => 20166,
			20689 => 20392,
			20721 => 38599,
			21123 => 21010,
			21297 => 21294,
			21421 => 21388,
			22039 => 23581,
			22036 => 21589,
			22022 => 21497,
			22029 => 21949,
			22038 => 21863,
			22006 => 21716,
			22296 => 22242,
			22294 => 22270,
			22645 => 23576,
			22666 => 22443,
			22649 => 22545,
			22781 => 23551,
			22821 => 20249,
			22818 => 26790,
			22890 => 22842,
			22889 => 22849,
			23255 => 22954,
			23527 => 23425,
			23526 => 23454,
			23522 => 23517,
			23565 => 23545,
			23650 => 23649,
			23940 => 23853,
			23943 => 23702,
			24163 => 24065,
		
			24151 => 24124,
			24390 => 21035,
			24505 => 24443,
			24903 => 27575,
			24907 => 24577,
			24931 => 24815,
			24927 => 24696,
			24922 => 24813,
			24920 => 24808,
			25695 => 25602,
			25722 => 25240,
			25681 => 25524,
			25723 => 25530,
			26274 => 30021,
			27054 => 33635,
			27091 => 26464,
			27083 => 26500,
			27085 => 26538,
			27046 => 24178,
			27699 => 27698,
			28414 => 28378,
			28460 => 28173,
			28450 => 27721,
			28415 => 28385,
			28399 => 28382,
			28472 => 28176,
			28466 => 28072,
			28451 => 28063,
			28396 => 27818,
			28417 => 28180,
			28402 => 28183,
			28364 => 28068,
			28407 => 21348,
			29074 => 33639,
			29246 => 23572,
			29334 => 33638,
			29508 => 29425,
			29796 => 29814,
			29795 => 29712,
			29802 => 29595,
			30247 => 30111,
			30221 => 30113,
			30219 => 30127,
			30217 => 24840,
			30227 => 30186,
			30433 => 23613,
			30435 => 30417,
			30889 => 30805,
			31118 => 31087,
			31117 => 31096,
			31278 => 31181,
			31281 => 31216,
			31402 => 27964,
			31401 => 31389,
			31627 => 31546,
			31645 => 38067,
			31631 => 31581,
			32187 => 32509,
			32176 => 32510,
			32156 => 32508,
			32189 => 32496,
			32190 => 32491,
			32160 => 32511,
			32202 => 32039,
			32180 => 32512,
			32178 => 32593,
			32177 => 32434,
			32186 => 32494,
			32162 => 32504,
			32191 => 32501,
			32181 => 24425,
			32184 => 32438,
			32173 => 32500,
			32210 => 32490,
			32199 => 32513,
			32172 => 32502,
			32624 => 32602,
			32862 => 38395,
			33274 => 21488,
			33287 => 19982,
			33990 => 24109,
			33950 => 33669,
			33995 => 30422,
			33984 => 33642,
			33936 => 25628,
			33980 => 33485,
			34645 => 34432,
			35069 => 21046,
			35494 => 35829,
			35468 => 24535,
			35486 => 35821,
			35491 => 35820,
			35469 => 35748,
			35489 => 35819,
			35492 => 35823,
			35498 => 35828,
			35493 => 35824,
			35496 => 35826,
			35480 => 35825,
			35473 => 35827,
			35482 => 35822,
			35981 => 29432,
			36051 => 23486,
			36049 => 36168,
			36050 => 36170,
			36249 => 36213,
			36245 => 36214,
			36628 => 36741,
			36626 => 36740,
			36629 => 36731,
			36627 => 25405,
			36960 => 36828,
			36956 => 36874,
			36953 => 36965,
			36958 => 36882,
			37496 => 38128,
			37504 => 38134,
			37509 => 38108,
			37528 => 38125,
			37526 => 38114,
			37499 => 38124,
			37523 => 38120,
			37532 => 34900,
			37544 => 38133,
			37521 => 38115,
			38305 => 38402,
			38312 => 38394,
			38313 => 38397,
			38307 => 38401,
			38309 => 38400,
			38308 => 21512,
			38555 => 38469,
			38935 => 39047,
			38936 => 39046,
			39087 => 39122,
			39089 => 21488,
			39171 => 39290,
			39173 => 39292,
			39180 => 39285,
			39177 => 39287,
			39361 => 39539,
			39599 => 32942,
			40180 => 40483,
			40182 => 40482,
			40179 => 20964,
			40636 => 20040,
			40778 => 40784,
			20740 => 20159,
			20736 => 20202,
			20729 => 20215,
			20738 => 20396,
			20744 => 20393,
			20745 => 20461,
			20741 => 24403,
			20956 => 20955,
			21127 => 21095,
			21129 => 21016,
			21133 => 21073,
			21130 => 21053,
			21426 => 21385,
			22062 => 21792,
			22057 => 21719,
			22099 => 22040,
			22132 => 21943,
			22063 => 21880,
			22064 => 21501,
			22707 => 22367,
			22684 => 22368,
			22702 => 22549,
			23291 => 23092,
			23307 => 23157,
			23285 => 22953,
			23308 => 23047,
			23304 => 23046,
			23532 => 23485,
			23529 => 23457,
			23531 => 20889,
			23652 => 23618,
			23956 => 23898,
			24159 => 24092,
			24290 => 24223,
			24282 => 21416,
			24287 => 24217,
			24285 => 21422,
			24291 => 24191,
			24288 => 21378,
			24392 => 24377,
			24501 => 24449,
			24950 => 24198,
			24942 => 34385,
			24962 => 24551,
			24956 => 25114,
			24939 => 24578,
			24958 => 27442,
			24976 => 24604,
			25003 => 24751,
			24986 => 24814,
			24996 => 24868,
			25006 => 24579,
			25711 => 25370,
			25778 => 25169,
			25736 => 25438,
			25744 => 25745,
			25765 => 25320,
			25747 => 25376,
			25771 => 25242,
			25754 => 25467,
			25779 => 25599,
			25973 => 25932,
			25976 => 25968,
			26283 => 26242,
			26289 => 26165,
			27171 => 26679,
			27112 => 26881,
			27137 => 26729,
			27166 => 26530,
			27161 => 26631,
			27155 => 27004,
			27123 => 26728,
			27138 => 20048,
			27141 => 26526,
			27153 => 26753,
			27472 => 27431,
			27470 => 21497,
			27556 => 27527,
			27590 => 27572,
			28479 => 27974,
			28497 => 27900,
			28500 => 27905,
			28550 => 27975,
			28507 => 28508,
			28528 => 28291,
			28516 => 28070,
			28567 => 28071,
			28527 => 27988,
			28511 => 27899,
			29105 => 28909,
			29339 => 29286,
			29518 => 22870,
			29801 => 33721,
			30241 => 30126,
			30362 => 30353,
			30394 => 30385,
			30436 => 30424,
			30599 => 30511,
			30906 => 30830,
			30908 => 30721,
			31296 => 35895,
			31407 => 31377,
			31406 => 31351,
			31684 => 33539,
			32224 => 32532,
			32244 => 32451,
			32239 => 32428,
			32251 => 33268,
			32216 => 32516,
			32236 => 32517,
			32221 => 32521,
			32232 => 32534,
			32227 => 32536,
			32218 => 32447,
			32222 => 32526,
			32233 => 32531,
			32158 => 32525,
			32217 => 32514,
			32242 => 32520,
			32249 => 32519,
			32629 => 39554,
			32631 => 32610,
			33184 => 33014,
			33178 => 32932,
			34030 => 33714,
			34093 => 33643,
			34083 => 33931,
			34068 => 21340,
			34085 => 33905,
			34054 => 33777,
			34662 => 34430,
			34680 => 34583,
			34664 => 34417,
			34907 => 21355,
			34909 => 20914,
			35079 => 22797,
			35516 => 35850,
			35538 => 35845,
			35527 => 35848,
			35524 => 35846,
			35477 => 35806,
			35531 => 35831,
			35576 => 35832,
			35506 => 35838,
			35529 => 35839,
			35522 => 35844,
			35519 => 35843,
			35504 => 35841,
			35542 => 35770,
			35533 => 35812,
			35510 => 35847,
			35513 => 35837,
			35547 => 35840,
			35918 => 31446,
			35948 => 29482,
			36064 => 36180,
			36062 => 36175,
			36070 => 36171,
			36068 => 36145,
			36076 => 36134,
			36077 => 36172,
			36066 => 36132,
			36067 => 21334,
			36060 => 36176,
			36074 => 36136,
			36065 => 36179,
			36368 => 36341,
			36385 => 34615,
			36637 => 36745,
			36635 => 36742,
			36639 => 36749,
			36649 => 36744,
			36646 => 36743,
			36650 => 36718,
			36636 => 36750,
			36638 => 36747,
			36645 => 36746,
			36969 => 36866,
			36983 => 36801,
			37168 => 37051,
			37165 => 37073,
			37159 => 37011,
			37251 => 33100,
			37573 => 38156,
			37563 => 38161,
			37559 => 38144,
			37610 => 38138,
			37548 => 38096,
			37604 => 38148,
			37569 => 38109,
			37555 => 38160,
			37564 => 38153,
			37586 => 38155,
			37575 => 38049,
			37616 => 38146,
			37554 => 28938,
			38317 => 38398,
			38321 => 38405,
			38799 => 24041,
			38945 => 39049,
			38955 => 20463,
			38940 => 39052,
			39091 => 21038,
			39178 => 20859,
			39187 => 39295,
			39186 => 39297,
			39192 => 20313,
			39389 => 39548,
			39376 => 39547,
			39391 => 39543,
			39387 => 39542,
			39377 => 39549,
			39381 => 39550,
			39378 => 39545,
			39385 => 39544,
			39662 => 21457,
			39719 => 38393,
			39799 => 40063,
			39791 => 40065,
			40198 => 40489,
			40201 => 40486,
			40617 => 40632,
			40786 => 40831,
			20760 => 23613,
			20756 => 20454,
			20752 => 20647,
			20757 => 20394,
			20906 => 24130,
			21137 => 21058,
			21235 => 21195,
			22137 => 24403,
			22136 => 21544,
			22117 => 21725,
			22127 => 22003,
			22718 => 22438,
			22727 => 22363,
			22894 => 22859,
			23325 => 34949,
			23416 => 23398,
			23566 => 23548,
			24394 => 24378,
			25010 => 23466,
			24977 => 20973,
			24970 => 24811,
			25037 => 25044,
			25014 => 24518,
			25136 => 25112,
			25793 => 25317,
			25803 => 25377,
			25787 => 25374,
			25818 => 25454,
			25796 => 25523,
			25799 => 25321,
			25791 => 25441,
			25812 => 25285,
			25790 => 25373,
			26310 => 21382,
			26313 => 26195,
			26308 => 26196,
			26311 => 26137,
			26296 => 20102,
			27192 => 26420,
			27194 => 26726,
			27243 => 27178,
			27193 => 26641,
			27234 => 26925,
			27211 => 26725,
			27231 => 26426,
			27208 => 26721,
			27511 => 21382,
			28593 => 28096,
			28611 => 27987,
			28580 => 27901,
			28609 => 27978,
			28582 => 28394,
			28576 => 28177,
			29118 => 28861,
			29129 => 28822,
			29138 => 28903,
			29128 => 28783,
			29145 => 28907,
			29148 => 28950,
			29124 => 28976,
			29544 => 29420,
			29859 => 29585,
			29964 => 29935,
			30266 => 30232,
			30439 => 21346,
			30622 => 30610,
			30938 => 30742,
			30951 => 30875,
			31142 => 24481,
			31309 => 31215,
			31310 => 39062,
			31308 => 31267,
			31418 => 31397,
			31761 => 34001,
			31689 => 31569,
			31716 => 31491,
			31721 => 31579,
			32266 => 32546,
			32273 => 32547,
			32264 => 33830,
			32283 => 32538,
			32291 => 21439,
			32286 => 32543,
			32285 => 32540,
			32265 => 32537,
			32272 => 32457,
			33193 => 33147,
			33288 => 20852,
			33369 => 33329,
			34153 => 33633,
			34157 => 33831,
			34154 => 33436,
			34718 => 34434,
			34722 => 33828,
			35122 => 35044,
			35242 => 20146,
			35238 => 35278,
			35558 => 35867,
			35578 => 35866,
			35563 => 35855,
			35569 => 35763,
			35584 => 35851,
			35548 => 35853,
			35559 => 35856,
			35566 => 21672,
			35582 => 35834,
			35585 => 35858,
			35586 => 35859,
			35575 => 35773,
			35565 => 35861,
			35571 => 35865,
			35574 => 35852,
			35580 => 35862,
			35987 => 29483,
			36084 => 36182,
			36404 => 36362,
			36667 => 36752,
			36655 => 36753,
			36664 => 36755,
			36659 => 36751,
			36774 => 21150,
			36984 => 36873,
			36978 => 36831,
			36988 => 36797,
			36986 => 36951,
			37172 => 37050,
			37664 => 38189,
			37686 => 34920,
			37624 => 38191,
			37683 => 38192,
			37679 => 38169,
			37666 => 38065,
			37628 => 38050,
			37675 => 38177,
			37636 => 24405,
			37658 => 38126,
			37648 => 38181,
			37670 => 38182,
			37665 => 38172,
			37653 => 38175,
			37678 => 38178,
			37657 => 38193,
			38331 => 38414,
			38568 => 38543,
			38570 => 38505,
			38673 => 27838,
			38748 => 38745,
			38758 => 33148,
			38960 => 39050,
			38968 => 39048,
			38971 => 39057,
			38967 => 39060,
			38957 => 22836,
			38969 => 39059,
			38948 => 39056,
			39208 => 39302,
			39198 => 39279,
			39195 => 39300,
			39201 => 39301,
			39194 => 32948,
			39405 => 39559,
			39394 => 39560,
			39409 => 39558,
			39720 => 21700,
			39825 => 40077,
			40213 => 40501,
			40227 => 40490,
			40230 => 40495,
			40232 => 40493,
			40210 => 40496,
			40219 => 40499,
			40845 => 40857,
			40860 => 40863,
			20778 => 20248,
			20767 => 20607,
			20786 => 20648,
			21237 => 21169,
			22144 => 21659,
			22160 => 23581,
			22151 => 21523,
			22739 => 21387,
			23344 => 23156,
			23338 => 23252,
			23332 => 23351,
			23607 => 23604,
			23656 => 23654,
			23996 => 23679,
			23994 => 23725,
			23997 => 23731,
			23992 => 23896,
			24171 => 24110,
			24396 => 24357,
			25033 => 24212,
			25031 => 24691,
			25138 => 25103,
			25802 => 20987,
			25824 => 25380,
			25840 => 25319,
			25836 => 25311,
			25841 => 25601,
			25986 => 25947,
			25987 => 27609,
			26326 => 26279,
			27284 => 26723,
			27298 => 26816,
			27292 => 26727,
			27355 => 26633,
			27299 => 27183,
			27566 => 27539,
			27656 => 27617,
			28632 => 27870,
			28657 => 28392,
			28639 => 27982,
			28635 => 33945,
			28644 => 28059,
			28651 => 28389,
			28544 => 28073,
			28652 => 27994,
			28629 => 28287,
			28656 => 28493,
			29151 => 33829,
			29158 => 28799,
			29165 => 28891,
			29164 => 27585,
			29172 => 28905,
			29254 => 22681,
			29552 => 29406,
			29554 => 33719,
			29872 => 29615,
			29862 => 29815,
			30278 => 30184,
			30274 => 30103,
			30442 => 33633,
			30637 => 20102,
			30703 => 30699,
			30959 => 30710,
			31146 => 31109,
			31757 => 31699,
			31712 => 31601,
			31966 => 31914,
			31970 => 27169,
			31965 => 31937,
			32302 => 32553,
			32318 => 32489,
			32326 => 32554,
			32311 => 32533,
			32306 => 32551,
			32323 => 32503,
			32299 => 32541,
			32317 => 24635,
			32305 => 32437,
			32325 => 32555,
			32308 => 32420,
			32313 => 32549,
			32328 => 35137,
			32309 => 32550,
			32303 => 28436,
			32882 => 22768,
			32880 => 32874,
			32879 => 32852,
			32883 => 32824,
			33215 => 33043,
			33213 => 32966,
			33225 => 33080,
			33214 => 33037,
			33256 => 20020,
			33289 => 20030,
			33393 => 33392,
			34193 => 23004,
			34196 => 34103,
			34186 => 34015,
			34407 => 20111,
			34747 => 34684,
			34760 => 34632,
			35131 => 20149,
			35128 => 35099,
			35244 => 35274,
			35598 => 35868,
			35607 => 35876,
			35609 => 35878,
			35611 => 35762,
			35594 => 35854,
			35616 => 35875,
			35613 => 35874,
			35588 => 35466,
			35600 => 35879,
			35903 => 28330,
			36090 => 36186,
			36093 => 36187,
			36092 => 36141,
			36088 => 21097,
			36091 => 36185,
			36264 => 36235,
			36676 => 36758,
			36670 => 36759,
			36674 => 27586,
			36677 => 36757,
			36671 => 33286,
			36996 => 36824,
			36993 => 36808,
			37283 => 31958,
			37278 => 37213,
			37276 => 19985,
			37709 => 38208,
			37762 => 38209,
			37672 => 38170,
			37749 => 38190,
			37706 => 38142,
			37733 => 38194,
			37707 => 38149,
			37656 => 38180,
			37758 => 38047,
			37740 => 38201,
			37723 => 38203,
			37744 => 38206,
			37722 => 38038,
			37716 => 38199,
			38346 => 38420,
			38347 => 38421,
			38348 => 38417,
			38344 => 38385,
			38342 => 26495,
			38577 => 38544,
			38584 => 38582,
			38614 => 34429,
			38867 => 38889,
			38982 => 39063,
			39094 => 39123,
			39221 => 21890,
			39425 => 39563,
			39423 => 39567,
			39854 => 40092,
			39851 => 40091,
			39850 => 40084,
			39853 => 40081,
			40251 => 40511,
			40255 => 40509,
			40670 => 28857,
			40779 => 25995,
			21474 => 19995,
			22165 => 22108,
			22190 => 21521,
			22745 => 22329,
			22744 => 22418,
			23352 => 23158,
			25059 => 25041,
			25844 => 25193,
			25842 => 25527,
			25854 => 25200,
			25862 => 25781,
			25850 => 25670,
			25851 => 25822,
			25847 => 25783,
			26039 => 26029,
			27315 => 27103,
			27331 => 26588,
			27323 => 27099,
			27320 => 26592,
			27330 => 26873,
			27310 => 26812,
			27311 => 21488,
			27487 => 27428,
			27512 => 24402,
			27567 => 27553,
			28681 => 27899,
			28683 => 27784,
			28670 => 28388,
			28678 => 28174,
			28666 => 28293,
			28687 => 27983,
			29179 => 29071,
			29180 => 28908,
			29182 => 28952,
			29559 => 29367,
			29557 => 29454,
			29973 => 29934,
			30296 => 30112,
			30290 => 24840,
			30652 => 30545,
			30990 => 30784,
			31150 => 31036,
			31329 => 31313,
			31330 => 31229,
			31328 => 31230,
			31428 => 31388,
			31429 => 31373,
			31787 => 31659,
			31774 => 31658,
			31779 => 31697,
			31777 => 31616,
			31975 => 31918,
			32340 => 32455,
			32341 => 32558,
			32350 => 32469,
			32346 => 32557,
			32353 => 32483,
			32338 => 32559,
			32345 => 32763,
			32584 => 22363,
			32761 => 32728,
			32887 => 32844,
			32886 => 32834,
			33229 => 33040,
			33231 => 33169,
			33290 => 26087,
			34217 => 33832,
			34253 => 34013,
			34249 => 20511,
			34234 => 33632,
			34214 => 33616,
			34799 => 34546,
			34796 => 34633,
			34802 => 34411,
			35250 => 35280,
			35316 => 35294,
			35624 => 35871,
			35641 => 35880,
			35628 => 35884,
			35627 => 35882,
			35920 => 20016,
			36101 => 36184,
			36451 => 36434,
			36452 => 36394,
			36447 => 36857,
			36437 => 36344,
			36544 => 36527,
			36681 => 36716,
			36685 => 36761,
			36999 => 36841,
			37291 => 21307,
			37292 => 37233,
			37328 => 21400,
			37780 => 38229,
			37770 => 38225,
			37782 => 38145,
			37794 => 38056,
			37811 => 38221,
			37806 => 38215,
			37804 => 38224,
			37808 => 38226,
			37784 => 38217,
			37786 => 38180,
			37783 => 26538,
			38356 => 38422,
			38358 => 38383,
			38352 => 38423,
			38357 => 38425,
			38626 => 31163,
			38620 => 26434,
			38617 => 21452,
			38619 => 38607,
			38622 => 40481,
			38692 => 28316,
			38822 => 31179,
			38989 => 39069,
			38991 => 39068,
			38988 => 39064,
			38990 => 39066,
			38995 => 39067,
			39098 => 25196,
			39230 => 39311,
			39231 => 39306,
			39229 => 39304,
			39438 => 39569,
			39686 => 26494,
			39758 => 39753,
			39882 => 40104,
			39881 => 40100,
			39933 => 40107,
			39880 => 40102,
			39872 => 40103,
			40273 => 40515,
			40285 => 40517,
			40288 => 40516,
			40725 => 20908,
			22181 => 21693,
			22750 => 22351,
			22751 => 22404,
			22754 => 22364,
			23541 => 23456,
			40848 => 24222,
			24300 => 24208,
			25074 => 24809,
			25079 => 24576,
			25078 => 25042,
			25871 => 25314,
			26336 => 26103,
			27365 => 27249,
			27357 => 26911,
			27354 => 27016,
			27347 => 27257,
			28703 => 28487,
			28712 => 28625,
			28701 => 27813,
			28693 => 28626,
			28696 => 27896,
			29197 => 28865,
			29272 => 29261,
			29346 => 29322,
			29560 => 20861,
			29562 => 29549,
			29885 => 29626,
			29898 => 29756,
			30087 => 30068,
			30303 => 30250,
			30305 => 30196,
			30663 => 33945,
			31001 => 30861,
			31153 => 31095,
			31339 => 33719,
			31337 => 31283,
			31806 => 24088,
			31805 => 31614,
			31799 => 27280,
			32363 => 31995,
			32365 => 33575,
			32377 => 32462,
			32361 => 32499,
			32362 => 32472,
			32645 => 32599,
			32371 => 32564,
			32694 => 33211,
			33240 => 33098,
			34269 => 33402,
			34282 => 34222,
			34277 => 33647,
			34295 => 34223,
			34811 => 34433,
			34821 => 34631,
			34829 => 34638,
			35168 => 35014,
			35158 => 34948,
			35649 => 21719,
			35676 => 35889,
			35672 => 35782,
			35657 => 35777,
			35674 => 35885,
			35662 => 35890,
			35663 => 35749,
			35654 => 22075,
			35673 => 35887,
			36104 => 36192,
			36106 => 36190,
			36474 => 36343,
			36692 => 36762,
			36686 => 36735,
			36781 => 36766,
			37002 => 36793,
			37297 => 37222,
			37857 => 38236,
			37841 => 38237,
			37855 => 38130,
			37827 => 38238,
			37832 => 38142,
			37852 => 38231,
			37853 => 38232,
			37858 => 38230,
			37837 => 38233,
			37848 => 38197,
			37860 => 38210,
			37847 => 38143,
			37864 => 37694,
			38364 => 20851,
			38580 => 38471,
			38627 => 38590,
			38695 => 38654,
			38876 => 38892,
			38907 => 38901,
			39006 => 31867,
			39000 => 24895,
			39003 => 39072,
			39100 => 39125,
			39237 => 39314,
			39241 => 39313,
			39446 => 39579,
			39449 => 39575,
			39693 => 32993,
			39912 => 40120,
			39911 => 40115,
			39894 => 40109,
			39899 => 40119,
			40329 => 40529,
			40289 => 40521,
			40306 => 40522,
			40298 => 40524,
			40300 => 40527,
			40599 => 20029,
			21240 => 21149,
			22184 => 21657,
			22198 => 22052,
			22196 => 20005,
			23363 => 23064,
			23542 => 23453,
			25080 => 24748,
			25082 => 24527,
			25876 => 25318,
			25881 => 25600,
			26407 => 32999,
			27372 => 27015,
			28734 => 28572,
			28720 => 24357,
			28722 => 28491,
			29200 => 28809,
			29563 => 29486,
			29903 => 29649,
			30306 => 30162,
			30309 => 30151,
			31014 => 30719,
			31018 => 30778,
			31020 => 30718,
			31019 => 30782,
			31431 => 31398,
			31478 => 31454,
			31820 => 31609,
			31811 => 31726,
			31984 => 22242,
			36782 => 36779,
			32381 => 32548,
			32380 => 32487,
			32588 => 32578,
			33242 => 33002,
			33382 => 33328,
			34297 => 34108,
			34298 => 34106,
			34310 => 33446,
			34315 => 33529,
			34311 => 33487,
			34314 => 34164,
			34836 => 34461,
			35172 => 35124,
			35258 => 35273,
			35320 => 35302,
			35696 => 35758,
			35695 => 35793,
			35679 => 22122,
			35691 => 35893,
			36111 => 36194,
			36109 => 36193,
			36489 => 36280,
			36482 => 36342,
			37323 => 37322,
			37912 => 38047,
			37891 => 38105,
			37885 => 38152,
			38369 => 38416,
			39108 => 39128,
			39250 => 39286,
			39249 => 39269,
			39467 => 39582,
			39472 => 33150,
			39479 => 39578,
			39955 => 40131,
			39949 => 40133,
			40569 => 21688,
			40629 => 38754,
			40680 => 20826,
			40799 => 40835,
			40803 => 20986,
			40801 => 40836,
			20791 => 20458,
			20792 => 13417,
			22209 => 21995,
			22208 => 21869,
			22210 => 22179,
			23660 => 23646,
			25084 => 24807,
			25086 => 24913,
			25885 => 25668,
			25884 => 25658,
			26005 => 26003,
			27387 => 27185,
			27396 => 26639,
			27386 => 26818,
			27570 => 27516,
			29211 => 28866,
			29351 => 29306,
			29908 => 29838,
			30313 => 30302,
			30675 => 32999,
			31824 => 34276,
			32399 => 32544,
			32396 => 32493,
			34327 => 34326,
			34349 => 20848,
			34330 => 34259,
			34851 => 34510,
			34847 => 34593,
			35178 => 34972,
			35180 => 25670,
			35261 => 35272,
			35700 => 35892,
			35703 => 25252,
			35709 => 35465,
			36115 => 36163,
			36490 => 36364,
			36493 => 36291,
			36491 => 36347,
			36703 => 36720,
			36783 => 36777,
			37934 => 38256,
			37939 => 38253,
			37941 => 38081,
			37946 => 38107,
			37944 => 38094,
			37938 => 38255,
			37931 => 38220,
			38370 => 36767,
			38911 => 21709,
			39015 => 39038,
			39013 => 39074,
			39255 => 39144,
			39493 => 39537,
			39491 => 39584,
			39488 => 34022,
			39486 => 39585,
			39631 => 39621,
			39981 => 40141,
			39973 => 40143,
			40367 => 33722,
			40372 => 40548,
			40386 => 40542,
			40796 => 40839,
			40806 => 40840,
			40807 => 21870,
			20796 => 20456,
			20795 => 20645,
			22216 => 21587,
			22217 => 21872,
			23423 => 23402,
			24020 => 24005,
			24018 => 23782,
			24398 => 24367,
			25892 => 25674,
			27402 => 26435,
			27489 => 27426,
			28753 => 27922,
			28760 => 28393,
			29568 => 29473,
			30090 => 21472,
			30318 => 30270,
			30316 => 30307,
			31840 => 31548,
			31839 => 31809,
			32894 => 32843,
			32893 => 21548,
			33247 => 33039,
			35186 => 34989,
			35183 => 34924,
			35712 => 35835,
			36118 => 36174,
			36119 => 36189,
			36497 => 36399,
			36499 => 36396,
			36705 => 36756,
			37192 => 37094,
			37956 => 38136,
			37969 => 37492,
			37970 => 37492,
			38717 => 38657,
			38851 => 38801,
			38849 => 32560,
			39019 => 39076,
			39509 => 39556,
			39501 => 39553,
			39634 => 33039,
			39706 => 39035,
			40009 => 40150,
			39985 => 40098,
			39998 => 40148,
			39995 => 40151,
			40403 => 40551,
			40407 => 40485,
			40756 => 40761,
			40812 => 40841,
			40810 => 40842,
			40852 => 40858,
			22220 => 33487,
			24022 => 23721,
			25088 => 24651,
			25891 => 25371,
			25898 => 25605,
			26348 => 26194,
			29914 => 29906,
			31434 => 31363,
			31844 => 31614,
			32403 => 32552,
			32406 => 32420,
			32404 => 25165,
			33250 => 33244,
			34367 => 33821,
			34865 => 34506,
			35722 => 21464,
			37008 => 36902,
			37007 => 36923,
			37987 => 38259,
			37984 => 38084,
			38760 => 38757,
			39023 => 26174,
			39260 => 39181,
			39514 => 24778,
			39515 => 39551,
			39511 => 39564,
			39636 => 20307,
			40020 => 40157,
			40023 => 40158,
			40022 => 40156,
			40421 => 40502,
			40692 => 38665,
			22225 => 22065,
			22761 => 22365,
			25900 => 25597,
			30321 => 30251,
			30322 => 30315,
			32648 => 32641,
			34870 => 34453,
			35731 => 35753,
			35730 => 35863,
			35734 => 35894,
			33399 => 33395,
			36123 => 36195,
			37312 => 37247,
			37994 => 28809,
			38722 => 38643,
			38728 => 28789,
			38724 => 38701,
			38854 => 21315,
			39024 => 39078,
			39519 => 39588,
			39714 => 39699,
			39768 => 39751,
			40031 => 40078,
			40441 => 40560,
			40442 => 40557,
			40572 => 30897,
			40573 => 30416,
			40711 => 40140,
			40823 => 40844,
			40818 => 40843,
			24307 => 21381,
			27414 => 27012,
			28771 => 28286,
			31852 => 31729,
			31854 => 31657,
			34875 => 34542,
			35264 => 35266,
			36513 => 36433,
			37313 => 34885,
			38002 => 38262,
			38000 => 38053,
			39025 => 39045,
			39262 => 39307,
			39638 => 39627,
			40652 => 40649,
			28772 => 28390,
			30682 => 30633,
			35738 => 36190,
			38007 => 38218,
			38857 => 38831,
			39522 => 39540,
			39525 => 39589,
			32412 => 32518,
			35740 => 35872,
			36522 => 36495,
			37317 => 37245,
			38013 => 38075,
			38014 => 37550,
			38012 => 38179,
			40055 => 40132,
			40056 => 40072,
			40695 => 40681,
			35924 => 33395,
			38015 => 20991,
			40474 => 40550,
			39530 => 39562,
			39729 => 37057,
			40475 => 40563,
			40478 => 40510,
			31858 => 21505,
			20189 => 21516,
			27596 => 27595,
			24194 => 20164,
			22908 => 23033,
			25182 => 25421,
			25184 => 21449,
			20322 => 28192,
			24567 => 24671,
			25911 => 32771,
			30337 => 30338,
			32912 => 33011,
			33424 => 33476,
			38440 => 21380,
			38447 => 22336,
			22389 => 19992,
			24354 => 38892,
			24627 => 24653,
			25108 => 25099,
			25297 => 38067,
			26514 => 26720,
			27520 => 22829,
			27869 => 28335,
			30015 => 27667,
			34415 => 34412,
			20417 => 20451,
			21060 => 21037,
			21398 => 24222,
			21401 => 21389,
			33549 => 33503,
			25295 => 25343,
			25923 => 26251,
			26591 => 26976,
			26618 => 25296,
			27608 => 27607,
			31045 => 31046,
			31190 => 31047,
			32008 => 32424,
			33530 => 33683,
			37332 => 38023,
			20936 => 20928,
			21764 => 21591,
			24371 => 24362,
			25360 => 25343,
			25404 => 25386,
			26050 => 26071,
			27436 => 21683,
			30143 => 30193,
			32029 => 32436,
			32024 => 32430,
			32019 => 32446,
			34456 => 34516,
			36859 => 31227,
			37087 => 37071,
			37092 => 21364,
			37333 => 38028,
			38492 => 38485,
			39139 => 39268,
			20586 => 36924,
			21102 => 21072,
			21293 => 21286,
			22279 => 22261,
			22492 => 37326,
			22515 => 22350,
			22497 => 22445,
			22512 => 37319,
			23149 => 23045,
			23148 => 28139,
			23488 => 37319,
			23821 => 23811,
			25533 => 30896,
			26734 => 26479,
			28137 => 20940,
			28150 => 28062,
			28133 => 28172,
			28123 => 27993,
			28106 => 28153,
			28916 => 28867,
			30160 => 34516,
			30501 => 30502,
			30758 => 23528,
			32053 => 32443,
			32058 => 32448,
			32063 => 32464,
			33051 => 33003,
			33651 => 35910,
			33685 => 33607,
			34898 => 28843,
			35285 => 31895,
			37206 => 40489,
			37356 => 38030,
			37348 => 38032,
			37369 => 38037,
			37367 => 38029,
			38278 => 38414,
			38280 => 38380,
			39141 => 39270,
			21412 => 21382,
			21926 => 23721,
			23215 => 22955,
			23505 => 23522,
			23890 => 23721,
			24818 => 24701,
			24824 => 33557,
			25564 => 25513,
			26235 => 26263,
			26895 => 26720,
			26838 => 26536,
			26903 => 30855,
			27581 => 28102,
			28296 => 27817,
			28254 => 27976,
			28194 => 27816,
			28960 => 28140,
			29259 => 31546,
			29999 => 23425,
			30060 => 30066,
			30820 => 30806,
			30812 => 30785,
			30824 => 30743,
			31547 => 31559,
			32079 => 32449,
			32078 => 32471,
			32574 => 29942,
			32674 => 32466,
			34901 => 21516,
			35008 => 33589,
			35224 => 35271,
			35406 => 35765,
			35416 => 35790,
			35410 => 35794,
			36026 => 36150,
			36016 => 36147,
			36309 => 36398,
			36602 => 36730,
			36601 => 36725,
			36587 => 36728,
			37126 => 37075,
			37377 => 38059,
			37379 => 38040,
			37414 => 38043,
			37376 => 38063,
			37380 => 38061,
			37415 => 38058,
			38284 => 38390,
			38537 => 38503,
			38640 => 27675,
			38919 => 39032,
			39147 => 39275,
			20682 => 20185,
			20660 => 20251,
			20674 => 20603,
			20681 => 20325,
			21962 => 21789,
			21993 => 21794,
			22628 => 22489,
			22607 => 22450,
			22780 => 22776,
			23243 => 34949,
			23231 => 24871,
			23510 => 28024,
			23512 => 32622,
			23583 => 40092,
			24048 => 24047,
			24823 => 34850,
			25609 => 27063,
			25636 => 25212,
			25667 => 25179,
			25640 => 25299,
			25647 => 25487,
			25664 => 25410,
			25637 => 25462,
			25639 => 25159,
			28339 => 28066,
			28999 => 36745,
			29010 => 28828,
			29026 => 33557,
			29499 => 29426,
			29771 => 29614,
			30560 => 30519,
			31590 => 31649,
			31604 => 16882,
			31600 => 31534,
			32136 => 32488,
			32134 => 32480,
			32131 => 32481,
			32677 => 32671,
			32801 => 38148,
			33121 => 33078,
			33309 => 36758,
			34033 => 33805,
			33842 => 33841,
			33861 => 33785,
			33874 => 33645,
			33903 => 33647,
			33888 => 21442,
			34571 => 34690,
			34554 => 34545,
			35462 => 35795,
			35455 => 35798,
			35425 => 35817,
			35460 => 35796,
			35445 => 35804,
			36322 => 36346,
			36613 => 36738,
			36615 => 36737,
			36616 => 36736,
			37142 => 37095,
			37140 => 37036,
			37448 => 38090,
			37424 => 38088,
			37434 => 38064,
			37478 => 38066,
			37427 => 38070,
			37470 => 38074,
			37507 => 38131,
			37422 => 38092,
			37446 => 38075,
			37485 => 38077,
			37484 => 38076,
			38927 => 39043,
			38926 => 39040,
			40167 => 20971,
			40701 => 40702,
			20712 => 20606,
			22044 => 21787,
			22652 => 30742,
			23937 => 23901,
			24152 => 24123,
			24153 => 24149,
			24277 => 33643,
			24872 => 24747,
			24947 => 24749,
			24948 => 24913,
			24938 => 24580,
			25129 => 25132,
			25127 => 25111,
			25718 => 25247,
			25715 => 25248,
			25692 => 25532,
			26272 => 30355,
			26402 => 26395,
			27071 => 26724,
			27050 => 26473,
			27550 => 27538,
			28366 => 33637,
			28408 => 27986,
			28411 => 27984,
			28442 => 27812,
			28426 => 28295,
			29079 => 28829,
			29507 => 21574,
			29810 => 29617,
			30392 => 30386,
			30893 => 30720,
			31125 => 31054,
			31630 => 31722,
			21124 => 26413,
			32163 => 32507,
			32196 => 32498,
			32203 => 32495,
			32185 => 32506,
			33155 => 33149,
			33940 => 33715,
			33960 => 33564,
			34600 => 34678,
			34618 => 38675,
			35233 => 35275,
			35474 => 35830,
			35478 => 24726,
			36053 => 36167,
			37541 => 38129,
			37494 => 38095,
			37531 => 38118,
			37498 => 38098,
			37536 => 38097,
			37546 => 38101,
			37517 => 38106,
			37542 => 38111,
			37530 => 38123,
			37547 => 38127,
			37503 => 38122,
			37539 => 38135,
			37614 => 38102,
			38784 => 40727
		);
	}
	
	
	if(!isset($_s2tmap) && $cv=="s2t") {
		$exc=array
		(
			 23588,
			 21514,
			 27867,
			 27762,
			 27745,
			 27867,
			 24067,
			 20223,
			 27785,
			 23427,
			 36836,
			 20315,
			 25340,
			 21319,
			 31995,
			 23616,
			 20811,
			 25296,
			 27844,
			 26479,
			 21482,
			 31563,
			 20223,
			 24184,
			 28846,
			 31192,
			 20305,
			 21482,
			 25176,
			 22238,
			 21319,
			 21482,
			 21681,
			 24565,
			 21846,
			 26118,
			 23913,
			 38613,
			 21367,
			 25384,
			 33293,
			 26417,
			 24358,
			 25166,
			 21767,
			 20462,
			 27427,
			 25187,
			 23478,
			 25928,
			 21507,
			 22797,
			 32972,
			 37240,
			 22256,
			 32470,
			 24245,
			 27880,
			 22564,
			 25134,
			 21119,
			 24439,
			 26647,
			 27048,
			 26104,
			 26262,
			 40635,
			 26865,
			 31104,
			 25414,
			 30522,
			 21032,
			 24040,
			 31461,
			 27575,
			 25240,
			 24840,
			 24425,
			 21488,
			 24109,
			 25628,
			 21046,
			 24535,
			 29432,
			 25405,
			 21512,
			 21488,
			 23898,
			 25114,
			 27442,
			 25467,
			 26753,
			 35895,
			 33268,
			 21340,
			 33777,
			 22797,
			 34615,
			 28938,
			 20463,
			 21038,
			 20102,
			 28096,
			 28394,
			 28976,
			 34001,
			 34920,
			 38172,
			 27838,
			 21700,
			 40496,
			 23731,
			 33945,
			 27994,
			 20102,
			 31601,
			 27169,
			 35137,
			 28436,
			 23004,
			 28330,
			 21097,
			 31958,
			 38038,
			 26495,
			 21521,
			 26873,
			 26812,
			 21488,
			 27784,
			 29071,
			 24840,
			 31230,
			 32763,
			 20511,
			 36394,
			 21400,
			 38229,
			 28316,
			 31179,
			 26494,
			 20908,
			 21693,
			 30196,
			 33945,
			 31995,
			 34223,
			 22075,
			 37222,
			 32993,
			 23064,
			 30151,
			 22122,
			 36342,
			 21688,
			 38754,
			 20986,
			 13417,
			 34276,
			 21872,
			 23721,
			 25165,
			 33244,
			 21315,
			 21516,
			 27595,
			 20164,
			 25421,
			 21449,
			 28192,
			 24671,
			 32771,
			 30338,
			 33011,
			 21380,
			 22336,
			 19992,
			 24653,
			 22829,
			 28335,
			 27667,
			 33503,
			 25343,
			 26251,
			 26976,
			 25296,
			 27607,
			 31046,
			 31047,
			 33683,
			 25343,
			 25386,
			 26071,
			 21683,
			 30193,
			 32436,
			 32430,
			 34516,
			 31227,
			 39268,
			 36924,
			 37326,
			 22350,
			 28139,
			 30896,
			 26479,
			 20940,
			 27993,
			 28153,
			 34516,
			 23528,
			 32443,
			 35910,
			 33607,
			 28843,
			 31895,
			 38380,
			 39270,
			 23721,
			 23522,
			 23721,
			 25513,
			 26263,
			 30855,
			 28102,
			 27816,
			 28140,
			 30785,
			 31559,
			 29942,
			 21516,
			 33589,
			 36398,
			 38040,
			 27675,
			 20185,
			 21789,
			 22776,
			 24871,
			 28024,
			 32622,
			 34850,
			 27063,
			 25212,
			 25179,
			 25299,
			 25487,
			 25410,
			 25462,
			 25159,
			 28066,
			 30519,
			 31649,
			 16882,
			 31534,
			 33805,
			 33841,
			 33785,
			 34690,
			 36346,
			 36736,
			 24149,
			 30355,
			 26395,
			 27984,
			 28295,
			 21574,
			 29617,
			 31054,
			 31722,
			 26413,
			 33564,
			 34678,
			 38675,
			 24726,
			 38095,
			 38118,
			 38106
		);

		$_s2tmap=array_combine(array_values($_t2smap),array_keys($_t2smap));
		foreach($exc as $code) unset($_s2tmap[$code]);
	}

	$map = $cv=="s2t"?$_s2tmap:$_t2smap;
	
	for($i=0;$i<mb_strlen($str);$i++){
		$rstr.=($c=$map[uniord($tc=mb_substr($str,$i,1))])?unichr($c):$tc;
	}
	return $rstr;
}

function str_chinese_simp($str){
	return str_chinese_convert($str);
}

function str_chinese_trad($str){
	return str_chinese_convert($str,"s2t");
}




?>
