<?php if ( ! defined( 'ABSPATH' ) ) exit;

class NF_AJAX_Controllers_Submission extends NF_Abstracts_Controller
{
    protected $_form_data = array();

    protected $_form_cache = array();

    protected $_preview_data = array();

    protected $_form_id = '';

    public function __construct()
    {
        if( isset( $_POST[ 'nf_resume' ] ) && isset( $_COOKIE[ 'nf_wp_session' ] ) ){
            add_action( 'init', array( $this, 'resume' ) );
            return;
        }

        if( isset( $_POST['formData'] ) ) {
            $this->_form_data = json_decode( $_POST['formData'], TRUE  );

            // php5.2 fallback
            if( ! $this->_form_data ) $this->_form_data = json_decode( stripslashes( $_POST['formData'] ), TRUE  );
        }


        // Ajax calls here are both handled by 'submit' in this file
        add_action( 'wp_ajax_nf_ajax_submit',   array( $this, 'submit' )  );
        add_action( 'wp_ajax_nopriv_nf_ajax_submit',   array( $this, 'submit' )  );

        /**
         * Ajax calls here are handled by 'resume' in this file. These calls
         * are normally made by the application when returning from 'PayPal' or
         * 'Stripe'.
         */
        add_action( 'wp_ajax_nf_ajax_resume',   array( $this, 'resume' )  );
        add_action( 'wp_ajax_nopriv_nf_ajax_resume',   array( $this, 'resume' )  );
    }

    public function submit()
    {
    	$nonce_name = 'ninja_forms_display_nonce';
    	/**
	     * We've got to get the 'nonce_ts' to append to the nonce name to get
	     * the unique nonce we created
	     * */
    	if( isset( $_REQUEST[ 'nonce_ts' ] ) && 0 < strlen( $_REQUEST[ 'nonce_ts' ] ) ) {
    		$nonce_name = $nonce_name . "_" . $_REQUEST[ 'nonce_ts' ];
	    }
        $check_ajax_referer = check_ajax_referer( $nonce_name, 'security', $die = false );
        if(!$check_ajax_referer){
            /**
             * "Just in Time Nonce".
             * If the nonce fails, then send back a new nonce for the form to resubmit.
             * This supports the edge-case of 11:59:59 form submissions, while avoiding the form load nonce request.
             */

            $current_time_stamp = time();
            $new_nonce_name = 'ninja_forms_display_nonce_' . $current_time_stamp;
            $this->_errors['nonce'] = array(
                'new_nonce' => wp_create_nonce( $new_nonce_name ),
                'nonce_ts' => $current_time_stamp
            );
            $this->_respond();
        }

        register_shutdown_function( array( $this, 'shutdown' ) );

        $this->form_data_check();

        $this->_form_id = $this->_form_data['id'];

        /* Render Instance Fix */
        if(strpos($this->_form_id, '_')){
            $this->_form_instance_id = $this->_form_id;
            list($this->_form_id, $this->_instance_id) = explode('_', $this->_form_id);
            $updated_fields = array();
            foreach($this->_form_data['fields'] as $field_id => $field ){
                list($field_id) = explode('_', $field_id);
                list($field['id']) = explode('_', $field['id']);
                $updated_fields[$field_id] = $field;
            }
            $this->_form_data['fields'] = $updated_fields;
        }
        /* END Render Instance Fix */

        // If we don't have a numeric form ID...
        if ( ! is_numeric( $this->_form_id ) ) {
            // Kick the request out without processing.
            $this->_errors[] = esc_html__( 'Form does not exist.', 'ninja-forms' );
            $this->_respond();
        }

        // Check to see if our form is maintenance mode.
        $is_maintenance = WPN_Helper::form_in_maintenance( $this->_form_id );

        /*
         * If our form is in maintenance mode then, stop processing and throw an error with a link
         * back to the form.
         */
        if ( $is_maintenance ) {
            $message = sprintf(
                esc_html__( 'This form is currently undergoing maintenance. Please %sclick here%s to reload the form and try again.', 'ninja-forms' )
                ,'<a href="' . esc_html($_SERVER[ 'HTTP_REFERER' ]) . '">', '</a>'
            );
            $this->_errors[ 'form' ][] = apply_filters( 'nf_maintenance_message', $message  ) ;
            $this->_respond();
        }

        if( $this->is_preview() ) {

            $this->_form_cache = get_user_option( 'nf_form_preview_' . $this->_form_id );

            if( ! $this->_form_cache ){
                $this->_errors[ 'preview' ] = esc_html__( 'Preview does not exist.', 'ninja-forms' );
                $this->_respond();
            }
        } else {
            if( WPN_Helper::use_cache() ) {
                $this->_form_cache = WPN_Helper::get_nf_cache( $this->_form_id );
            }

        }

        // Add Field Keys to _form_data
        if(! $this->is_preview()){

            // Make sure we don't have any field ID mismatches.
            foreach( $this->_form_data[ 'fields' ] as $id => $settings ){
                if( $id != $settings[ 'id' ] ){
                    $this->_errors[ 'fields' ][ $id ] = esc_html__( 'The submitted data is invalid.', 'ninja-forms' );
                    $this->_respond();
                }
            }

            $form_fields = Ninja_Forms()->form($this->_form_id)->get_fields();
            foreach ($form_fields as $id => $field) {
                $this->_form_data['fields'][$id]['key'] = $field->get_setting('key');
            }
        }

        // TODO: Update Conditional Logic to preserve field ID => [ Settings, ID ] structure.
        $this->_form_data = apply_filters( 'ninja_forms_submit_data', $this->_form_data );

        $this->process();
    }

    public function resume()
    {
        $this->_form_data = Ninja_Forms()->session()->get( 'nf_processing_form_data' );
        $this->_form_cache = Ninja_Forms()->session()->get( 'nf_processing_form_cache' );
        $this->_data = Ninja_Forms()->session()->get( 'nf_processing_data' );
        $this->_data[ 'resume' ] = WPN_Helper::sanitize_text_field($_POST[ 'nf_resume' ]);

        $this->_form_id = $this->_data[ 'form_id' ];

        unset( $this->_data[ 'halt' ] );
        unset( $this->_data[ 'actions' ][ 'redirect' ] );

        $this->process();
    }

    protected function process()
    {
        // Init Field Merge Tags.
        $field_merge_tags = Ninja_Forms()->merge_tags[ 'fields' ];
        $field_merge_tags->set_form_id( $this->_form_id );

        // Init Calc Merge Tags.
        $calcs_merge_tags = Ninja_Forms()->merge_tags[ 'calcs' ];

        if(isset($this->_form_cache[ 'settings' ] ) ) {
            $form_settings = $this->_form_cache[ 'settings' ];
        } else {
            $form_settings = false;
        }

        if( ! $form_settings ){
            $form = Ninja_Forms()->form( $this->_form_id )->get();
            $form_settings = $form->get_settings();
        }

	    // Init Form Merge Tags.
	    $form_merge_tags = Ninja_Forms()->merge_tags[ 'form' ];
	    $form_merge_tags->set_form_id( $this->_form_id );
	    $form_merge_tags->set_form_title( $form_settings['title'] );

        $this->_data[ 'form_id' ] = $this->_form_data[ 'form_id' ] = $this->_form_id;
        $this->_data[ 'settings' ] = $form_settings;
        $this->_data[ 'settings' ][ 'is_preview' ] = $this->is_preview();

        $this->maybePreserveExtraData();
        $this->_data[ 'extra' ] = $this->_form_data[ 'extra' ];

        /*
        |--------------------------------------------------------------------------
        | Fields
        |--------------------------------------------------------------------------
        */

        if( $this->is_preview() ){
            $form_fields = $this->_form_cache[ 'fields' ];
        } else {
            $form_fields = Ninja_Forms()->form($this->_form_id)->get_fields();
        }

        /**
         * The Field Processing Loop.
         *
         * There can only be one!
         * For performance reasons, this should be the only time that the fields array is traversed.
         * Anything needing to loop through fields should integrate here.
         */
        $validate_fields = apply_filters( 'ninja_forms_validate_fields', true, $this->_data );
        foreach( $form_fields as $key => $field ){

            if( is_object( $field ) ) {

                //Process Merge tags on Repeater fields values
                if( $field->get_setting('type' )=== "repeater" ){
                    $this->process_repeater_fields_merge_tags( $field );
                }

                $field = array(
                    'id' => $field->get_id(),
                    'settings' => $field->get_settings()
                );
            }

            /** Get the field ID */
            /*
             * TODO: Refactor data structures to match.
             * Preview: Field IDs are stored as the associated array key.
             * Publish: Field IDs are stored as an array key=>value pair.
             */
            if( $this->is_preview() ){
                $field[ 'id' ] = $key;
            }

            // Duplicate field ID as single variable for more readable array access.
            $field_id = $field[ 'id' ];

            // Check that the field ID exists in the submitted for data and has a submitted value.
            if( isset( $this->_form_data[ 'fields' ][ $field_id ] ) && isset( $this->_form_data[ 'fields' ][ $field_id ][ 'value' ] ) ){
                $field[ 'value' ] = $this->_form_data[ 'fields' ][ $field_id ][ 'value' ];
            } else {
                $field[ 'value' ] = '';
            }

            // Duplicate field value to settings and top level array item for backwards compatible access (ie Save Action).
            $field[ 'settings' ][ 'value' ] = $field[ 'value' ];

            // Duplicate field value to form cache for passing to the action filter.
            $this->_form_cache[ 'fields' ][ $key ][ 'settings' ][ 'value' ] = $this->_form_data[ 'fields' ][ $field_id ][ 'value' ];

            // Duplicate the Field ID for access as a setting.
            $field[ 'settings' ][ 'id' ] = $field[ 'id' ];

            // Combine with submitted data.
            $field = array_merge( $field, $this->_form_data[ 'fields' ][ $field_id ] );

            // Flatten the field array.
            $field = array_merge( $field, $field[ 'settings' ] );

            /** Prepare Fields in repeater for Validation and Process */
            if( $field["type"] === "repeater" ){
                foreach( $field["value"] as $index => $child_field_value ){
                    foreach( $field['fields'] as $i => $child_field ) {
                        if(strpos($index, $child_field['id']) !== false){
                            $field['value'][$index] = array_merge($child_field, $child_field_value);
                        }
                    }
                }
            }
            /** Validate the Field */
            if( $validate_fields && ! isset( $this->_data[ 'resume' ] ) ){
                if( $field["type"] === "repeater" ){
                    foreach( $field["value"] as  $index => $child_field ){
                        $this->validate_field( $field["value"][$index] );
                    }
                } else {
                    $this->validate_field( $field );
                }
                 
            }

            /** Process the Field */
            if( ! isset( $this->_data[ 'resume' ] ) ) {
                if( $field["type"] === "repeater" ){
                    foreach( $field["value"] as $index => $child_field ){
                        $this->process_field( $field["value"][$index] );
                    }
                } else {
                    $this->process_field($field);
                }
            }
            $field = array_merge( $field, $this->_form_data[ 'fields' ][ $field_id ] );

	        // Check for field errors after processing.
	        if ( isset( $this->_form_data['errors']['fields'][ $field_id ] ) ) {
		        $this->_errors['fields'][ $field_id ] = $this->_form_data['errors']['fields'][ $field_id ];
		        $this->_respond();
	        }

            /** Populate Field Merge Tag */
            $field_merge_tags->add_field( $field );

            $this->_data[ 'fields' ][ $field_id ] = $field;
            $this->_data[ 'fields_by_key' ][ $field[ 'key' ] ] = $field;
        }

        /*
        |--------------------------------------------------------------------------
        | Check for unique field settings.
        |--------------------------------------------------------------------------
        */
        if ( isset( $this->_data[ 'resume' ] ) && $this->_data[ 'resume' ] ){
            // On Resume Submission, we don't need to run this check again.
            // This section intentionally left blank.
        } elseif ( isset ( $this->_data[ 'settings' ][ 'unique_field' ] ) && ! empty( $this->_data[ 'settings' ][ 'unique_field' ] ) ) {
            /*
             * Get our unique field
             */
            $unique_field_key = $this->_data[ 'settings' ][ 'unique_field' ];
            $unique_field_error = $this->_data[ 'settings' ][ 'unique_field_error' ];
            $unique_field_id = $this->_data[ 'fields_by_key' ][ $unique_field_key ][ 'id' ];
            $unique_field_value = $this->_data[ 'fields_by_key' ][ $unique_field_key ][ 'value' ];
            if ( is_array( $unique_field_value ) ) {
                $unique_field_value = serialize( $unique_field_value );
            }

            if ( ! empty($unique_field_value) ) {
                /*
                 * Check our db for the value submitted.
                 */
                
                global $wpdb;
                // @TODO: Rewrite this to use our submissions API.
                $sql = $wpdb->prepare( "SELECT COUNT(m.meta_id) FROM `" . $wpdb->prefix . "postmeta` AS m LEFT JOIN `" . $wpdb->prefix . "posts` AS p ON p.id = m.post_id WHERE m.meta_key = '_field_%d' AND m.meta_value = '%s' AND p.post_status = 'publish'", $unique_field_id, $unique_field_value );
                $result = $wpdb->get_results( $sql, 'ARRAY_N' );
                if ( intval( $result[ 0 ][ 0 ] ) > 0 ) {
                    $this->_errors['fields'][ $unique_field_id ] = array( 'slug' => 'unique_field', 'message' => $unique_field_error );
                    $this->_respond();
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Verify the submission limit.
        |--------------------------------------------------------------------------
        */
        if ( isset( $this->_data[ 'settings' ][ 'sub_limit_number' ] ) && ! empty( $this->_data[ 'settings' ][ 'sub_limit_number' ] ) ) {
            global $wpdb;
            $result = $wpdb->get_row( "SELECT COUNT(DISTINCT(p.ID)) AS count FROM `$wpdb->posts` AS p
            LEFT JOIN `$wpdb->postmeta` AS m
            ON p.ID = m.post_id
            WHERE m.meta_key = '_form_id'
            AND m.meta_value = $this->_form_id
            AND p.post_status = 'publish'");
            if ( intval( $result->count ) >= intval( $this->_data[ 'settings' ][ 'sub_limit_number' ] ) ) {
                $this->_errors[ 'form' ][] = $this->_data[ 'settings' ][ 'sub_limit_msg' ];
                $this->_respond();
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Calculations
        |--------------------------------------------------------------------------
        */

        /**
         * We shouldn't have anything in here before processing.
         * So, clear out the trash.
         */
        if( isset( $this->_data['extra']['calculations'] ) ) {
            unset( $this->_data['extra']['calculations']);
            unset( $this->_form_data['extra']['calculations']);
        }

        if( isset( $this->_form_cache[ 'settings' ][ 'calculations' ] ) ) {

            /**
             * The Calculation Processing Loop
             */
            foreach( $this->_form_cache[ 'settings' ][ 'calculations' ] as $calc ){
                $eq = apply_filters( 'ninja_forms_calc_setting', $calc[ 'eq' ] );

                // Scrub unmerged tags (ie deleted/non-existent fields/calcs, etc).
                $eq = preg_replace( '/{([a-zA-Z0-9]|:|_|-)*}/', 0, $eq);

				/**
				 * PHP doesn't evaluate empty strings to numbers. So check
	             * for any string for the decimal place
				**/
                $dec = ( isset( $calc[ 'dec' ] ) && '' != $calc[ 'dec' ] ) ?
	                $calc[ 'dec' ] : 2;
                
                $calcs_merge_tags->set_merge_tags( $calc[ 'name' ], $eq, $dec, $this->_form_data['settings']['decimal_point'], $this->_form_data['settings']['thousands_sep'] );
                $this->_data[ 'extra' ][ 'calculations' ][ $calc[ 'name' ] ] = array(
                    'raw' => $calc[ 'eq' ],
                    'parsed' => $eq,
                    'value' => $calcs_merge_tags->get_formatted_calc_value( $calc[ 'name' ], $dec, $this->_form_data['settings']['decimal_point'], $this->_form_data['settings']['thousands_sep'] ),
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Actions
        |--------------------------------------------------------------------------
        */

        /*
         * TODO: This section has become convoluted, but will be refactored along with the submission controller.
         */

        if( isset( $this->_data[ 'resume' ] ) && $this->_data[ 'resume' ] ){
            // On Resume Submission, the action data is loaded form the session.
            // This section intentionally left blank.
        } elseif( ! $this->is_preview() ) {
            // Published forms rely on the Database for the "truth" about Actions.
            $actions = Ninja_Forms()->form($this->_form_id)->get_actions();
            $this->_form_cache[ 'actions' ] = array();
            foreach( $actions as $action ){
                $action_id = $action->get_id();
                $this->_form_cache[ 'actions' ][ $action_id ] = array(
                    'id' => $action_id,
                    'settings' => $action->get_settings()
                );
            }
        } else {
            // Previews uses user option for stored data.
            $preview_data = get_user_option( 'nf_form_preview_' . $this->_form_id );
            $this->_form_cache[ 'actions' ] = $preview_data[ 'actions' ];
        }
        /* END form cache bypass. */

        // Sort Actions by Timing Order, then by Priority Order.
        usort( $this->_form_cache[ 'actions' ], array( $this, 'sort_form_actions' ) );

        /*
         * Filter Actions so that they can be pragmatically disabled by add-ons.
         *
         * ninja_forms_submission_actions
         * ninja_forms_submission_actions_preview
         */
        $this->_form_cache[ 'actions' ] = apply_filters( 'ninja_forms_submission_actions', $this->_form_cache[ 'actions' ], $this->_form_cache, $this->_form_data );
        if( $this->is_preview() ) {
            $this->_form_cache['actions'] = apply_filters('ninja_forms_submission_actions_preview', $this->_form_cache['actions'], $this->_form_cache);
        }

        // Initialize the process actions log.
        if( ! isset( $this->_data[ 'processed_actions' ] ) ) $this->_data[ 'processed_actions' ] = array();

        /*
         * Merging extra data that may have been added by fields during processing so that the values aren't lost when we enter the action loop.
         */
        $this->_data[ 'extra' ] = array_merge( $this->_data[ 'extra' ], $this->_form_data[ 'extra' ] );

        /**
         * The Action Processing Loop
         */
        foreach( $this->_form_cache[ 'actions' ] as $key => $action ){

            /** Get the action ID */
            /*
             * TODO: Refactor data structures to match.
             * Preview: Action IDs are stored as the associated array key.
             * Publish: Action IDs are stored as an array key=>value pair.
             */
            if( $this->is_preview() ){
                $action[ 'id' ] = $key;
            }

            // Duplicate the Action ID for access as a setting.
            $action[ 'settings' ][ 'id' ] = $action[ 'id' ];

            // Duplicate action ID as single variable for more readable array access.
            $action_id = $action[ 'id' ];

            // If an action has already run (ie resume submission), do not re-process.
            if( in_array( $action[ 'id' ], $this->_data[ 'processed_actions' ] ) ) continue;

            $action[ 'settings' ] = apply_filters( 'ninja_forms_run_action_settings', $action[ 'settings' ], $this->_form_id, $action[ 'id' ], $this->_form_data['settings'] );
            if( $this->is_preview() ){
                $action[ 'settings' ] = apply_filters( 'ninja_forms_run_action_settings_preview', $action[ 'settings' ], $this->_form_id, $action[ 'id' ], $this->_form_data['settings'] );
            }

            if( ! $action[ 'settings' ][ 'active' ] ) continue;

            if( ! apply_filters( 'ninja_forms_run_action_type_' . $action[ 'settings' ][ 'type' ], TRUE ) ) continue;

            $type = $action[ 'settings' ][ 'type' ];

            if( ! is_string( $type ) ) continue;

            /*
             *  test if Ninja_Forms()->actions[ $type ] is not empty
             */
            if(isset(Ninja_Forms()->actions[ $type ])) 
            { 
                $action_class = Ninja_Forms()->actions[ $type ];

                if( ! method_exists( $action_class, 'process' ) ) continue;
    
                if( $data = $action_class->process($action[ 'settings' ], $this->_form_id, $this->_data ) )
                {
                    $this->_data = apply_filters( 'ninja_forms_post_run_action_type_' . $action[ 'settings' ][ 'type' ], $data );
                }
            }

            // $this->_data[ 'actions' ][ $type ][] = $action;

            $this->maybe_halt( $action[ 'id' ] );
        }

        do_action( 'ninja_forms_after_submission', $this->_data );

        $this->_respond();
    }

    /**
     * Upon request, preserve a resumed action's  extra data from before halt
     *
     * @return void
     */
    private function maybePreserveExtraData(): void
    {
        if (
            isset($this->_data['resume']) &&
            isset($this->_data['resume']['preserve_extra_data'])
        ) {
            $preservedKey = $this->_data['resume']['preserve_extra_data'];

            if (isset($this->_data['extra'][$preservedKey])) {
                $this->_form_data['extra'][$preservedKey] = $this->_data['extra'][$preservedKey];
            }
        }
    }

    protected function validate_field( $field_settings )
    {
        $field_settings = apply_filters( 'ninja_forms_pre_validate_field_settings', $field_settings );

        if( ! is_string( $field_settings['type'] ) ) return;

        $field_class = Ninja_Forms()->fields[ $field_settings['type'] ];

        // if field_class is neither object nor string return before method_exists call
        if(!is_object($field_class) && !is_string($field_class)){
            return;
        }

        if( ! method_exists( $field_class, 'validate' ) ) return;

        if( $errors = $field_class->validate( $field_settings, $this->_form_data ) ){
            $field_id = $field_settings[ 'id' ];
            $this->_errors[ 'fields' ][ $field_id ] = $errors;
            $this->_respond();
        }
    }

    protected function process_field( $field_settings )
    {
        if( ! is_string( $field_settings['type'] ) ) return;

        $field_class = Ninja_Forms()->fields[ $field_settings['type'] ];

        // If $field_class is not object or string, return w/o checking for method_exists
        if(!is_object($field_class) && !is_string($field_class)){
            return;
        }

        if( ! method_exists( $field_class, 'process' ) ) return;

        if( $data = $field_class->process( $field_settings, $this->_form_data )  ){
            $this->_form_data = $data;
        }
    }

    protected function maybe_halt( $action_id )
    {
        if( isset( $this->_data[ 'errors' ] ) && $this->_data[ 'errors' ] ){
            $this->_respond();
        }

        if( isset( $this->_data[ 'halt' ] ) && $this->_data[ 'halt' ] ){

            Ninja_Forms()->session()->set( 'nf_processing_data', $this->_data );
            Ninja_Forms()->session()->set( 'nf_processing_form_data', $this->_form_data );
            Ninja_Forms()->session()->set( 'nf_processing_form_cache', $this->_form_cache );

            $this->_respond();
        }

        array_push( $this->_data[ 'processed_actions' ], $action_id );
    }

    protected function sort_form_actions( $a, $b )
    {
        if( is_object( $a ) ) {
            if( ! isset( Ninja_Forms()->actions[ $a->get_setting( 'type' ) ] ) ) return -1;
            $a = Ninja_Forms()->actions[ $a->get_setting( 'type' ) ];
        } else {
            if( ! isset( Ninja_Forms()->actions[ $a[ 'settings' ][ 'type' ] ] ) ) return -1;
            $a = Ninja_Forms()->actions[ $a[ 'settings' ][ 'type' ] ];
        }

        if( is_object( $b ) ) {
            if( ! isset( Ninja_Forms()->actions[ $b->get_setting( 'type' ) ] ) ) return 1;
            $b = Ninja_Forms()->actions[ $b->get_setting( 'type' ) ];
        } else {
            if( ! isset( Ninja_Forms()->actions[ $b[ 'settings' ][ 'type' ] ] ) ) return 1;
            $b = Ninja_Forms()->actions[ $b[ 'settings' ][ 'type' ] ];
        }

        if ( $a->get_timing() == $b->get_timing() ) {
            if ( $a->get_priority() == $b->get_priority() ) {
                return 0;
            }
            return ( $a->get_priority() < $b->get_priority() ) ? -1 : 1;
        }

        return ( $a->get_timing() < $b->get_timing() ) ? -1 : 1;
    }

    public function shutdown()
    {
        $error = error_get_last();
        if( $error !== NULL && in_array( $error[ 'type' ], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ) ) ) {

            $this->_errors[ 'form' ][ 'last' ] = esc_html__( 'The server encountered an error during processing.', 'ninja-forms' );

            if( current_user_can( 'manage_options' ) && isset( $error[ 'message' ] ) ){
                $this->_errors[ 'form' ][ 'last_admin' ] = '<pre>' . $error[ 'message' ] . '</pre>';
            }

            $this->_errors[ 'last' ] = $error;
            Ninja_Forms()->logger()->emergency( $error[ 'message' ] );
            $this->_respond();
        }
    }

    protected function form_data_check()
    {
        if( $this->_form_data ) return;

        if( function_exists( 'json_last_error' ) // Function not supported in php5.2
            && function_exists( 'json_last_error_msg' )// Function not supported in php5.4
            && json_last_error() ){
            $this->_errors[] = json_last_error_msg();
        } else {
            $this->_errors[] = esc_html__( 'An unexpected error occurred.', 'ninja-forms' );
        }

        $this->_respond();
    }

    protected function is_preview()
    {
        if( ! isset( $this->_form_data[ 'settings' ][ 'is_preview' ] ) ) return false;
        return $this->_form_data[ 'settings' ][ 'is_preview' ];
    }

    /*
     * Overwrite method for parent class.
     */
    protected function _respond( $data = array() )
    {
        // Restore form instance ID.
        if(property_exists($this, '_form_instance_id') 
            && $this->_form_instance_id){
            $this->_data[ 'form_id' ] = $this->_form_instance_id;

            // Maybe update IDs for field errors, if there are field errors.
            if(isset($this->_errors['fields']) && $this->_errors['fields']){
                $field_errors = array();
                foreach($this->_errors['fields'] as $field_id => $error){
                    $field_errors[$field_id . '_' . $this->_instance_id] = $error;
                }
                $this->_errors['fields'] = $field_errors;
            }
        }

        // Set a content type of JSON for the purpose of previnting XSS attacks.
        header( 'Content-Type: application/json' );
        // Call the parent method.
        parent::_respond();
    }

     /**
     * Process fields merge tags for fields inside a repeater fieldset
     * 
     * @param object $field The Repeater Fieldset
     * 
     */
    protected function process_repeater_fields_merge_tags( $field ){
        //Compare the Repeater field passed calling the function with the array of fields values from the submission object
        foreach( $this->_form_data['fields'][$field->get_id()]['value'] as $id => $data ){
            //Check if field is a Repeater Field
            if( Ninja_Forms()->fieldsetRepeater->isRepeaterFieldByFieldReference($id) && !empty($data['value']) && is_string($data['value']) ) {
                //Merge tags in the Repeater Field Sub Fields values
                $this->_form_data['fields'][$field->get_id()]['value'][$id]['value'] = apply_filters( 'ninja_forms_merge_tags', $data['value'] );
            } 
        }
    }
}
