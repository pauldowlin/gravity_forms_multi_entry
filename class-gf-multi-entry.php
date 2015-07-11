<?php
/* ------------------------------------------
   To get addon settings
   
		$settings = $this->get_form_settings();
		$mode     = $this->get_setting( 'api_mode', '', $settings );
		
-------------------------------------------------
*/

//------------------------------------------
if (class_exists("GFForms")) {
    GFForms::include_addon_framework();

    class GFMultiEntry extends GFAddOn {

        protected $_version = "0.5";
        protected $_min_gravityforms_version = "1.7.9999";
        protected $_slug = "multientry";
        protected $_path = "gravityforms-multi-entry/multientry.php";
        protected $_full_path = __FILE__;
        protected $_title = "Gravity Forms Multi Entry Add-On";
        protected $_short_title = "Multi Entry";
		protected $_meta_table_name = "me_meta";
		protected $payment_addon_array = array('date_created', 'payment_status', 'payment_date', 'transaction_id', 'payment_amount', 'payment_method', 'is_fulfulled', 'transaction_type', 'status');
		
		private static $_instance = null;
		
		public static function get_instance() {
			if ( self::$_instance == null ) {
				self::$_instance = new GFMultiEntry();
			}

			return self::$_instance;
		}
		// Don't Need this
		/*
        public function plugin_page() {
            ?>
            This page appears in the Forms menu
        <?php
        }
		*/	
		
		public function init_admin() {
			parent::init_admin();
			$settings = $this->get_multi_entry_settings();
			
			if(!empty($settings)) {
				if($settings['enabled'] == 1) {
					
					//Checks if multi entry is enabled and creates the db table
					add_action("gform_admin_pre_render", array( $this, 'pre_render_function'));
					//Updates tables after creation
					
					//Hooks into save_form and updates table if any changes are detected
					add_action("gform_after_save_form", array( $this, 'update_multi_table'));
					
					//Adds our delimiter checkbox to different fields in Form Editor
					add_action('gform_field_standard_settings', array( $this, 'my_standard_settings'), 10, 2);
					add_action('gform_editor_js', array( $this, 'editor_script'));
					add_filter('gform_tooltips', array( $this, 'add_encryption_tooltips'));
					
				}
			}
		}
		
		public function init_frontend() {
			parent::init_frontend();
			
			add_filter('gform_after_submission_7', array( $this, 'multi_after_submit' ), 10, 2);
			/*
			$form_ids = $this->get_multi_entry_enabled();
			foreach($form_ids as $form_id) {
				add_filter("gform_after_submission_$form_id", array( $this, 'multi_after_submit' ), 10, 2);
			}
			*/
		}
		
		// Adds Form settings content 
        public function form_settings_fields($form) {
			return array(
				array(
					"title"  => "Multi Entry Settings",
					"fields" => array(
						array(
							"label"   => "Multi Entry",
							"type"    => "checkbox",
							"name"    => "enabled",
							"tooltip" => "Blah",
							"choices" => array(
								array(
									"label" => "Enabled",
									"name"  => "enabled"
								)
							)
						)
					)
				)
			);
		}
		
		
        public function scripts() {
            $scripts = array(
                array("handle"  => "my_script_js",
                      "src"     => $this->get_base_url() . "/js/my_script.js",
                      "version" => $this->_version,
                      "deps"    => array("jquery"),
                      "strings" => array(
                          'first'  => __("First Choice", "multientry"),
                          'second' => __("Second Choice", "multientry"),
                          'third'  => __("Third Choice", "multientry")
                      ),
                      "enqueue" => array(
                          array(
                              "admin_page" => array("form_settings"),
                              "tab"        => "multientry"
                          )
                      )
                ),

            );

            return array_merge(parent::scripts(), $scripts);
        }

        public function styles() {

            $styles = array(
                array("handle"  => "my_styles_css",
                      "src"     => $this->get_base_url() . "/css/my_styles.css",
                      "version" => $this->_version,
                      "enqueue" => array(
                          array("field_types" => array("poll"))
                      )
                )
            );

            return array_merge(parent::styles(), $styles);
        }
		
		/*
		 * Creates a Multi Entry setting on each section field in the Form Editor
		 * -- uses editor_script & add_encryption_tooltips functions
		 *
		 */
		public function my_standard_settings($position, $form_id){
			//create settings on position 25 (right after Field Label)
			if($position == 0){
				?>
				<li class="multi_entry_setting field_setting">
					<input type="checkbox" id="field_multi_entry_value" onclick="SetFieldProperty('multientryField', this.checked);" />
					<label class="inline" for="field_admin_label">
						<?php _e("Multi Entry", "gravityforms"); ?>
						<?php gform_tooltip("form_field_multi_entry_value") ?>
					</label>
					
				</li>
				<?php
			}
		}
		
		public function editor_script(){
			?>
			<script type='text/javascript'>
				//adding setting to fields of type "text"
				//fieldSettings["text"] += ", .multi_entry_setting";
				fieldSettings["section"] += ", .multi_entry_setting";
				//fieldSettings["checkboxes"] += ", .multi_entry_setting";
				//binding to the load field settings event to initialize the checkbox
				jQuery(document).bind("gform_load_field_settings", function(event, field, form){
					jQuery("#field_multi_entry_value").attr("checked", field["multientryField"] == true);
				});
			</script>
			<?php
		}
		
		public function add_encryption_tooltips($tooltips){
		   $tooltips["form_field_multi_entry_value"] = "<h6>Multi Entry</h6>Check this box to create entry starting at this point";
		   return $tooltips;
		}
		
		/**
		 * Function that hooks into gform_prerender and calls our create table function
		 *    -- only when on multientry form settings page
		 */
		function pre_render_function($form){
			// see what page we are on
			$me_view    = rgget( 'view' );
			$me_subview = rgget( 'subview' );
			if ($me_view == 'settings' && $me_subview == 'multientry') {
				$this->create_multi_table($form);
			}
			
			return $form;
		}
		
		/**
		 * Creates table that all Multi Entry data goes into
		 */
		public function create_multi_table($form) {
			global $wpdb;
			$multi_entry_title = str_replace(' ', '_', $form['title']);
			$table_name = $wpdb->prefix . 'me_' . strtolower($multi_entry_title);
			
			if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
			
				$charset_collate = $wpdb->get_charset_collate();
				
				$sql = "CREATE TABLE $table_name ( 
					id INT NOT NULL AUTO_INCREMENT,
					entry_id VARCHAR(50) NOT NULL,\n";
				
				$fields = $this->multi_get_fields($form);
				
				/**
				 * Future feature
				 *   - updated 06.17.15
				 *   - On plugin page (not yet created) end user can specify text used in front of all multi entry fields
				 *   - 
				 *   - for now just set our settings (identifier, multi_label)
				 */
				$settings = $this->get_multi_entry_settings();
				if(!isset($settings['identifier'])) $settings['identifier'] = 'player';
				if(!isset($settings['table_label'])) $settings['table_label'] = 'multi_label';
				
				//get our array for creating main table
				$fields_merge = $this->reorder_array($fields, $settings);
				
				foreach($fields_merge as $fm) {
					//var_dump($fm);
					$t_label = isset($fm[$settings['table_label']]) ?  $fm[$settings['table_label']] : false;
					
					if($t_label == 1 || !$t_label){
						$fml = $fm['field_label'];
						$sql .= " $fml VARCHAR(50) NOT NULL,\n";
					}
				}
				
				//Add payment entries and other fluff
				//$entry_fluff   = array('date_created', 'payment_status', 'payment_date', 'transaction_id', 'payment_amount', 'payment_method', 'is_fulfulled', 'transaction_type', 'status');
				$entry_fluff = $this->get_payment_addon_array();
				foreach($entry_fluff as $fluff){
					if($fluff == 'id'){ $fluff = 'entry_id'; }
					
					$sql .= "$fluff VARCHAR(50), \n";
				}
				
				//finish off the table definition
				$sql .= " UNIQUE KEY id(id)) $charset_collate;";
				
				// -------- DEBUG ------------------
				foreach($fields as $field) {
					$fields_input[] = $field['inputs'];
				}
				//echo 'Field Merge: ';
				//var_dump($fields_merge);
				//echo 'SQL: ';
				//var_dump($sql);
				// -------- END DEBUG ------------------
				
				require_once(ABSPATH . '/wp-admin/includes/upgrade.php');
				
				dbDelta($sql);
				
				//Create our meta table
				$fields_meta = $this->reorder_array($fields, $settings, true);
				$meta_table = $this->create_multi_meta_table($fields_meta, $form['id']);
				
				//Add table name to form object to use later
				$form['multiTableName'] = $table_name;
				$result = GFAPI::update_form($form);
			}
			
			return $form;
		}
		
		/**
		 * Store the field id / column name reference in a meta table
		 *    uses serialize to store array
		 *    creates if not present
		 *
		 *  @param array $field_merge structure - $field['id'] => $field['label']
		 */
		
		/**
		 * Create meta table
		 */
		private function create_multi_meta_table($fields_merge, $form_id) {
			global $wpdb;
			$table_name = $wpdb->prefix . "me_meta";
			$charset_collate = $wpdb->get_charset_collate();
			
			if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
				$sql = "CREATE TABLE $table_name ( 
					form_id mediumint(8) NOT NULL,
					me_column_data longtext,
					PRIMARY KEY (form_id)) $charset_collate;";
				
				dbDelta($sql);
				
				//insert existing data
				$ins = $wpdb->insert($table_name, array( 'form_id' => $form_id, 'me_column_data' => maybe_serialize( $fields_merge )));
			}
		}
		
		/* ------- Update the table ----------
		 *
		 *   - using dbDelta here to easily add columns
		 *   - must account for removed columns which is why we
		 *     have 2 arrays, one for form objects fields and one from the table
		 *   - custom db query to remove columns as well as reorder columns
		 *
		 *   @param array $my_settings   Array of field labels
		 *   @param array $form          Form object
		 */
		public function update_multi_table($form) {
			global $wpdb;
			$multi_entry_title = str_replace(' ', '_', $form['title']);
			$table_name = $wpdb->prefix . 'me_' . strtolower($multi_entry_title);
			
			// -------- DEBUG ------------------
			//$multi_meta = $this->get_meta_fields_array($form['id']);
			//echo 'Meta Array: ';
			//var_dump($multi_meta);
			// -------- END DEBUG ------------------
			
			if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
				$table_col = $wpdb->get_col( "DESCRIBE " . $table_name);
				unset($table_col[0]);
				unset($table_col[1]);
				$table_col = array_values($table_col);
				
				// -------- DEBUG ------------------
				/*
				echo 'Table Column: ';
				var_dump($table_col);
				*/
				// -------- END DEBUG ------------------
				
				$fields = $this->multi_get_fields($form);
				
				/**
				 * Future feature
				 *   - updated 06.17.15
				 *   - On plugin page (not yet created) end user can specify text used in front of all multi entry fields
				 *   _ for now just set our settings (identifier, multi_label)
				 */

				$settings = $this->get_multi_entry_settings();
				if(!isset($settings['identifier'])) $settings['identifier'] = 'player';
				if(!isset($settings['table_label'])) $settings['table_label'] = 'multi_label';
				
				// -------- DEBUG ------------------
				//echo 'Function Ident Settings: ';
				//var_dump($settings);
				// -------- End DEBUG --------------
				
				$reorder_fields = $this->reorder_array($fields, $settings);
						
				//add unique identifier to duplicate fields so we can add to database
				// move to reorder function ??????
				//$reorder_fields = $this->handle_duplicates($reorder_fields);
				
				//convert to simple array
				foreach($reorder_fields as $re_field) {
					$my_fields[] = $re_field['field_label'];
				}
				
				// -------- DEBUG ------------------
				
				//echo 'From form object: <br />';
				//var_dump($fields);
				/*echo 'Reorder Fields Array: ';
				var_dump($reorder_fields);
				echo 'My Fields: ';
				var_dump($my_fields);
				echo 'Inputs: ';
				foreach($fields as $field){
					$input_field[] = $field['inputs'];
					$choice_field[] = $field['choices'];
				}
				var_dump($input_field);
				var_dump($fields);
				*/
				// -------- END DEBUG ------------------
				//$exclude = array('id', 'entry_id');
				
				$exclude = array_merge($this->get_payment_addon_array(), array('id', 'entry_id'));
				$whats_missing = array_diff($table_col, $my_fields, $exclude);
				$whats_added   = array_diff($my_fields, $table_col);
				$whats_changed = array_diff($table_col, $my_fields, $exclude);
				
				// -------- DEBUG ------------------
				
				echo 'Whats missing:';
				var_dump($whats_missing);
				echo  'Whats added:';
				var_dump($whats_added);
				if($table_col == $my_fields){
					echo  'Whats changed = something has changed <br />';
				}else{
					echo 'Whats changed = nothing <br />';
				}
				/**/
				// -------- END DEBUG ------------------
				
				/* ---------------------------------------------------
				 *	Now remove columns not in use
				 *
				 */
				if($whats_missing) {
					$missing_query = "ALTER TABLE $table_name ";
					foreach ($whats_missing as $missing) {
						if($missing === end($whats_missing)){
							$missing_query .= 'DROP COLUMN ' . $missing . ';';
						}else{
							$missing_query .= 'DROP COLUMN ' . $missing . ', ';
						}
					}
					
					//$this->mf_query($missing_query);
				}
				/* --------------------------------------------------
				 *	Send to dbDelta and let it figure out what needs to be added
				 *
				 */
				if($whats_added) {
					$charset_collate = $wpdb->get_charset_collate();
					//
					$sql = "CREATE TABLE $table_name ( 
						id INT NOT NULL AUTO_INCREMENT, 
						entry_id VARCHAR(50) NOT NULL,\n";
					
					foreach($my_fields as $field) {
						if($field === end($my_fields)) {
							//updating so we don't need UNIQUE KEY
							$sql .= " $field VARCHAR(50) NOT NULL)";
						}else {
							$sql .= " $field VARCHAR(50) NOT NULL,\n";
						}
					}
					
					require_once(ABSPATH . '/wp-admin/includes/upgrade.php');
					
					dbDelta($sql);
				}
				
				/* -------------------------------------------
				 *	now re-order based on form object
				 * 
				 */
				if(!empty($whats_added) || !empty($whats_missing) || $table_col !== $my_fields ) {
					$count = 0;
					$change = false;
					$reorder_query_array = array();
					// Get rid of cached version of $wpdb so we can test for any changes
					$wpdb->flush();
					$my_reorder_col = $wpdb->get_col( "DESCRIBE " . $table_name);
					unset($my_reorder_col[0]);
					unset($my_reorder_col[1]);
					$my_reorder_col = array_values($my_reorder_col);
					
					foreach ($my_fields as $index => $order) {
						if($index == 0) {
							$reorder_query_array[] = 'MODIFY ' . $order . ' VARCHAR(50) AFTER entry_id';
						}else {
							$reorder_query_array[] = 'MODIFY ' . $order . ' VARCHAR(50) AFTER ' . $my_fields[$index-1];//$my_reorder_col[$position];
						}
						
					} // end foreach
					//build the query
					if(!empty($reorder_query_array)){
						$reorder_query = "ALTER TABLE $table_name ";
						foreach($reorder_query_array as $array) {
							if(end($reorder_query_array) === $array) {
								$reorder_query .= $array . ';';
							}else {
								$reorder_query .= $array . ',';
							}
						}
					}
					
					if($reorder_query){
						// -------- DEBUG ------------------
						//echo 'Reorder Query:  ' . $reorder_query;
						// -------- END DEBUG ------------------
						
						$this->mf_query($reorder_query);
					}
					
					//update meta table
					//$reorder_fields has proper indexed items plus multi designation
					$meta_table = $this->update_multi_meta_table($reorder_fields, $form['id']);
					
				}
			}//end if $table
			return $form;
		}
		
		/**
		 * Function that places form data from front end into our custom table
		 *     $player_arrays structure - $field['id'] => $field['label']
		 *     $common_array structure  - $field['id'] => $field['label']
		 */
		public function multi_after_submit($entrys, $form) {
			global $wpdb;
			$meta_table = $this->get_meta_table_name();
			
			//get meta info so we can compare to fields submitted
			$multi_meta = $this->get_meta_fields_array($form['id']);
			
			/**
			 *  Match index of $entry array to index of $multi_meta array
			 *    - $multi_meta array holds an array
			 *         > field_label => string
			 *         > multi_label => int
			 *
			 *    - foreach on $multi_meta & see if there is a match in $entry
			 */
			$player_array  = array();
			$common_array  = array();
			$address_array = array();
			$entry_fluff   = array('id', 'date_created', 'payment_status', 'payment_date', 'transaction_id', 'payment_amount', 'payment_method', 'is_fulfulled', 'transaction_type', 'status');
			$counter = 0;
			$skip = 0;
			$keep_track = 1;
			foreach($multi_meta as $k => $multi) {
						
				//now place into proper arrays
				if(!empty($multi['multi_label'])){
					
					// Do this check only on the first pass thru
					
					// reset counter when we get to new "player"
					if($keep_track < $multi['multi_label']){
						$counter = 1;
					}
					//first run thru grab the multi_label #
					if($counter <= 1){
						$keep_track = $multi['multi_label'];
					}
					//skip if we have blank player entry
					//Check occurs only on first run thru "player" array
					if($counter <= 1 && $multi['multi_label'] > 1 && $skip !== $multi['multi_label'] && ($multi['field_section'] == 'text' || $multi['field_section'] == 'name') && $entrys[$k] == '') {
						$skip = $multi['multi_label'];
						echo $skip;
						echo ' '. $multi['field_label'];
					}
					if($skip == $multi['multi_label']){
						continue;
					}
					
					$player_array[$multi['multi_label']][$multi['field_label']] = (isset($entrys[$k])) ? $entrys[$k] : '';
					
					$counter++;
				}else {
					// Don't add 'player' address here
					// Assume same as 'player'
					// Up to admin/developer to make sure fields are filled in if needed (javascript?)
					$common_array[$multi['field_label']] = (isset($entrys[$k])) ? $entrys[$k] : '';
				}
				
			}
			
			//Add Payment fields if available
			//Add entry id and other meta_fields
			/**
			 *  TO DO:
			 *    - automatically add 'sibling' names to end of entry
			 *
			 */
			foreach($entry_fluff as $k => $fluff){
				if($fluff == 'id'){ $fluff = 'entry_id'; }
				
				if(isset($entrys[$entry_fluff[$k]])){
					$common_array[$fluff] = $entrys[$entry_fluff[$k]];
				}
			}
			
			//Now send to database
			foreach($player_array as $player){
				$db_entry = array_merge($player, $common_array);
				
				$this->multi_insert_db($db_entry, $form['multiTableName']);
			}
			
			// -------- DEBUG ------------------
			write_log('-------------- CUSTOM DEBUG STARTS NOW --------------');
			write_log( $db_entry );
			// -------- END DEBUG ------------------
			
		}
		
		/**
		 * Update meta table
		 */
		private function update_multi_meta_table($fields_merge, $form_id) {
			global $wpdb;
			$table_name = $wpdb->prefix . $this->get_meta_table_name();
			
			$table_data = array(
				'me_column_data' => maybe_serialize( $fields_merge )
			);
			
			$upd = $wpdb->update($table_name, $table_data, array( 'form_id' => $form_id));
		}
		
		/* 
		 * ==================
		 * Helper functions
		 * ==================
		 */
		 
		/**
		 * return array of fields from form object
		 */
		function multi_get_fields($form) {
			if(!$form) {
				return false;
			}
			$fields = $form['fields'];
			return $fields;
		}
		
		/**
		 * Re-order fields so that multi entry section is first and remove duplicates
		 *   -> use 2 arrays
		 *   -> one to hold multi entry section
		 *   -> one to hold the rest of the form
		 *   -> array_merge is bliss!
		 *
		 *   Returns numeric array of labels
		 */
		function reorder_array($reorder_fields, $settings, $indexed=false) {
			$multi_label         = $settings['table_label'];
			$multi_entry_section = 0;
			$multi_entry_count   = 0;
			$section_array       = array();
			$form_array          = array();
			$field_excludes      = array('section', 'html', 'captcha', 'page');
			foreach($reorder_fields as $field) {
				if($field['type'] == 'section') {
					if($field['multientryField']){
						$multi_entry_section = 1; //we have our multi entry
						$multi_entry_count++;
					}else{
						$multi_entry_section = 0; //multi entry stops
					}
				}
				
				if($multi_entry_section == 1 && !in_array($field['type'], $field_excludes)) {
					// Only take the first section
					if($multi_entry_count == 1 || $indexed){
						//what about address and multiple choice fields
						if($field['inputs']){
							foreach($field['inputs'] as $input){
								if(!isset($input['isHidden']) || ( isset($input['isHidden']) && !$input['isHidden'])){
									$input_label = $this->blank_field_label($input);
									$input_label = strtolower(str_replace(array(' ', '-'), '_', $input_label));
									$input_label = preg_replace('/[^A-Za-z0-9_\-]/', '', $input_label );
									$input_label = str_replace('__', '_', $input_label);
									$input_label = $settings['identifier'] . '_' . $input_label;
									
									$section_array[$input['id']] = array('field_label' => $input_label, $multi_label => $multi_entry_count, 'field_section' => $field['type']);
									
								}
								
							}
						}else{
							
							$field_label = $this->blank_field_label($field);
							$field_label = strtolower(str_replace(array(' ', '-'), '_', $field_label));
							$field_label = preg_replace('/[^A-Za-z0-9_\-]/', '', $field_label);
							$field_label = str_replace('__', '_', $field_label);
							$field_label = $settings['identifier'] . '_' . $field_label;
							
								$section_array[$field['id']] = array('field_label' => $field_label, $multi_label => $multi_entry_count, 'field_section' => $field['type']);
							
						}
					}
				}elseif(!in_array($field['type'], $field_excludes)){
					//what about address and multiple choice fields
					if($field['inputs']){
						foreach($field['inputs'] as $input){
							if(!isset($input['isHidden']) || ( isset($input['isHidden']) && !$input['isHidden'])){
								if(isset($input['customLabel'])) {
									$input_label = $input['customLabel'];
								}else{
									$input_label = $this->blank_field_label($input);
								}
								$input_label = strtolower(str_replace(array(' ', '-'), '_', $input_label));
								$input_label = preg_replace('/[^A-Za-z0-9_\-]/', '', $input_label);
								$input_label = str_replace('__', '_', $input_label);
								
									$form_array[$input['id']] = array('field_label' => $input_label, 'field_section' => $field['type']);
								
							}
						}
					}else{
						$field_label = $this->blank_field_label($field);
						$field_label = strtolower(str_replace(array(' ', '-'), '_', $field_label));
						$field_label = preg_replace('/[^A-Za-z0-9_\-]/', '', $field_label);
						$field_label = str_replace('__', '_', $field_label);
						
							$form_array[$field['id']] = array('field_label' => $field_label, 'field_section' => $field['type']);
						
					}
				}
				
			}// end foreach
			
			// -------- DEBUG ---------------------
			/*
			echo 'Section Array: ';
			var_dump($section_array);
			echo 'Form array: ';
			var_dump($form_array);
			echo 'f_merge: ';
			var_dump($f_merge);
			*/
			//-------- END DEBUG ------------------ */
			//handle duplicates
			$section_array = $this->handle_duplicates($section_array);
			$form_array    = $this->handle_duplicates($form_array);
			$f_merge = $section_array + $form_array;
			
			return $f_merge;
		}
		
		/**
		 * Place $fields into array to be placed in meta table
		 *   -> **  Must use this function as we need to return all "player" fields  **
		 *   -> use 2 arrays
		 *   -> one to hold multi entry section
		 *   -> one to hold the rest of the form
		 *   -> array_merge is bliss!
		 *
		 *  Returns multidimensional array with $field id as index
		 */
		function reorder_meta_array($reorder_fields, $settings) {
			$multi_label         = 'multi';
			$multi_entry_section = 0;
			$multi_entry_count   = 1;
			$section_array       = array();
			$form_array          = array();
			$field_excludes      = array('section', 'html', 'captcha', 'page');
			foreach($reorder_fields as $field) {
				if($field['type'] == 'section') {
					if($field['multientryField']){
						$multi_entry_section = 1; //we have our multi entry
						$multi_entry_count++;
					}else{
						$multi_entry_section = 0; //multi entry stops
					}
				}
				
				if($multi_entry_section == 1 && !in_array($field['type'], $field_excludes)) {
					// Only take the first section
					if($multi_entry_count == 2){
						//what about address and multiple choice fields
						if($field['inputs']){
							foreach($field['inputs'] as $input){
								if(!isset($input['isHidden']) || ( isset($input['isHidden']) && !$input['isHidden'])){
									$input_label = $this->blank_field_label($input);
									$input_label = strtolower(str_replace(array(' ', '-'), '_', $input_label));
									$input_label = preg_replace('/[^A-Za-z0-9_\-]/', '', $input_label );
									$input_label = str_replace('__', '_', $input_label);
									$input_label = $settings['identifier'] . '_' . $input_label;
									
									$section_array[$input['id']] = array($input_label, $multi_label);
								}
								
							}
						}else{
							$field_label = $this->blank_field_label($field);
							$field_label = strtolower(str_replace(array(' ', '-'), '_', $field_label));
							$field_label = preg_replace('/[^A-Za-z0-9_\-]/', '', $field_label);
							$field_label = str_replace('__', '_', $field_label);
							$field_label = $settings['identifier'] . '_' . $field_label;
	
							$section_array[$field['id']] = array($field_label, $multi_label);
						}
					}
				}elseif(!in_array($field['type'], $field_excludes)){
					//what about address and multiple choice fields
					if($field['inputs']){
						//var_dump($field['inputs']);
						foreach($field['inputs'] as $input){
							if(!isset($input['isHidden']) || ( isset($input['isHidden']) && !$input['isHidden'])){
								if(isset($input['customLabel'])) {
									$input_label = $input['customLabel'];
								}else{
									$input_label = $this->blank_field_label($input);
								}
								$input_label = strtolower(str_replace(array(' ', '-'), '_', $input_label));
								$input_label = preg_replace('/[^A-Za-z0-9_\-]/', '', $input_label);
								$input_label = str_replace('__', '_', $input_label);
								
								$form_array[$input['id']] = $input_label;
							}
						}
					}else{
						$field_label = $this->blank_field_label($field);
						$field_label = strtolower(str_replace(array(' ', '-'), '_', $field_label));
						$field_label = preg_replace('/[^A-Za-z0-9_\-]/', '', $field_label);
						$field_label = str_replace('__', '_', $field_label);
						
						
						$form_array[$field['id']] = $field_label;
					}
				}
				
			}// end foreach
			$f_merge = $section_array +	$form_array;
			
			// -------- DEBUG ---------------------
			/*
			echo 'Section Array: ';
			var_dump($section_array);
			echo 'Form array: ';
			var_dump($form_array);
			echo 'f_merge: ';
			var_dump($f_merge);
			*/
			//-------- END DEBUG ------------------ */
			
			return $f_merge;
		}
		
		/**
		 * Find out if there are duplicate labels
		 * make them unique and return the $array
		 */
		private function handle_duplicates($arrays) {
			// Collect all field labels that aren't marked as multi entry
			foreach($arrays as $ar){
				if(!isset($ar['multi_label'])){
					$dup_arrays[] = $ar['field_label'];
				}
			}
			// -------- DEBUG ------------------
			/*
			echo 'Dup Arrays: <br />';
			var_dump(empty($dup_arrays));
			var_dump($dup_arrays);
			*/
			// -------- END DEBUG ------------------
			
			if(!empty($dup_arrays)){
				$field_count = array_count_values($dup_arrays);
				$fields_so_far = array();
			
				foreach($field_count as $label => $count) {
					if($count > 1){
						$fields_so_far[] = $label;
					}
				}
				
				//now add a numerical value to end of field to make it unique
				$x=1;
				foreach($arrays as $k => $array) {
					if(in_array($array['field_label'], $fields_so_far)){
						if(isset($prev_label) && $prev_label !== $array['field_label']){
							$x=1;
						}
						$prev_label = $array['field_label'];
						$arrays[$k]['field_label'] = $array['field_label'] . '_' . $x;
						$x++;
					}
					
				}
			}
			// -------- DEBUG ------------------
			/*
			echo 'End Handle Duplicats: <br />';
			var_dump($arrays);
			*/
			// -------- END DEBUG ------------------
			return $arrays;
		}
		
		/**
		 * Send custom query
		 */
		function mf_query($my_query) {
			global $wpdb;
			//using wpdb->query here, is this the right thing to do?
			$the_query = $wpdb->query(
								"
								$my_query
								"
							);
			return $the_query;
		}
		
		/*
		 * Gets addon settings
		 */
		function get_multi_entry_settings(){
			$settings['enabled'] = array();
			if(isset($_GET["id"])){
				$form_id = (int)$_GET["id"];
				$form = GFAPI::get_form( $form_id );
				$addon_settings = $this->get_form_settings($form);
				
				return $addon_settings;
			}
			
		}
		
		/*
		 * Get an array of form id's that are multi-entry enabled
		 */
		function get_multi_entry_enabled() {
			$enabled_ids = array();
			$forms = GFAPI::get_forms();
			foreach($forms as $form) {
				$forms_settings = $this->get_form_settings($form);
				if(!empty($forms_settings) && $forms_settings['enabled'] == 1) {
					$enabled_ids[] = $form['id'];
				}
			}
			return $enabled_ids;
		}
		
		/**
		 * Checks to see if field label is blank
		 * Looks for Admin Label and if that is blank inserts generic label
		 */
		function blank_field_label($field) {
			if($field['label'] == '') {
				if($field['adminLabel'] == ''){
					$field['adminLabel'] = 'Label Left Blank';
				}
				$field_label = $field['adminLabel'];
			}else{
				$field_label = $field['label'];
			}
			
			return $field_label;
		}
		
		/**
		 * Insert form data from front end into our custom table
		 */
		function multi_insert_db($form_data, $table_name){
			global $wpdb;
			
			$wpdb->insert($table_name, $form_data);
		}
		
		/**
		 * Insert data into Multi Entry table
		 */
		 
		 /***  Need to use prepare here??  ***/
		function multi_insert_table($table_data, $form){
			global $wpdb;
			$table_name = $form['multiTableName'];
			
			$wpdb->insert($table_name, $table_data);
		}
		
		/**
		 * Returns unserialized array from meta table
		 *   - needs form id
		 */
		 protected function get_meta_fields_array($form_id) {
			 global $wpdb;
			 $table_name = $wpdb->prefix . $this->get_meta_table_name();
			 $meta_fields = $wpdb->get_row("SELECT * FROM $table_name WHERE form_id = $form_id", ARRAY_A);
			 return maybe_unserialize($meta_fields['me_column_data']);
		 }
		
		/**
		 * Returns this plugins meta table name.  Used to create meta table.
		 */
		protected function get_meta_table_name() {
			return $this->_meta_table_name;
		}
		/**
		 * Returns an array of payment addon fields.
		 */
		protected function get_payment_addon_array() {
			return $this->payment_addon_array;
		}
		
    } //end class

}//endif