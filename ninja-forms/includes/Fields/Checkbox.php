<?php if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class NF_Fields_Checkbox
 */
class NF_Fields_Checkbox extends NF_Abstracts_Input
{
    protected $_name = 'checkbox';

    protected $_nicename = 'Checkbox';

    protected $_section = 'common';

    protected $_icon = 'check-square-o';

    protected $_type = 'checkbox';

    protected $_templates = 'checkbox';

    protected $_test_value = 0;

    protected $_settings =  array( 'checkbox_default_value', 'checkbox_values', 'checked_value', 'unchecked_value', 'checked_calc_value', 'unchecked_calc_value' );

    protected $_settings_exclude = array( 'default', 'placeholder', 'input_limit_set' );

    /**
     * NF_Fields_Checkbox constructor.
     * @since 3.0
     */
    public function __construct()
    {
        parent::__construct();

        $this->_nicename = esc_html__( 'Single Checkbox', 'ninja-forms' );

        $this->_settings[ 'label_pos' ][ 'value' ] = 'right';

        add_filter( 'ninja_forms_custom_columns', array( $this, 'custom_columns' ), 10, 2 );

        add_filter( 'ninja_forms_merge_tag_value_' . $this->_name, array( $this, 'filter_merge_tag_value' ), 10, 2 );
        add_filter( 'ninja_forms_merge_tag_calc_value_' . $this->_name, array( $this, 'filter_merge_tag_value_calc' ), 10, 2 );
        add_filter( 'ninja_forms_subs_export_field_value_' . $this->_type, array( $this, 'export_value' ), 10, 2 );
        add_filter( 'ninja_forms_localize_field_' . $this->_name, array( $this, 'localize_field' ) );
        add_filter( 'ninja_forms_localize_field_' . $this->_name . '_preview', array( $this, 'localize_field' ) );
    }

    /**
     * Has Display Value
     * Determines whether a checked/unchecked display value setting was given a
     * value by the form author. A value of "0" is a real value and must not be
     * replaced with the built-in Checked/Unchecked labels.
     * @since 3.16.2
     *
     * @param mixed $setting checked_value or unchecked_value setting
     * @return bool
     */
    protected function has_display_value( $setting )
    {
        return null !== $setting && '' !== $setting;
    }

    /**
     * Admin Form Element
     * Display the checkbox on the edit submissions area.
     * @since 3.0
     *
     * @param $id Field ID.
     * @param $value Field value.
     * @return string HTML used for display of checkbox.
     */
    public function admin_form_element( $id, $value )
    {
        // If the checkboxes value is 1 or on...
        if( 'on' == $value || 1 == $value ) {
            // ...this variable to checked.
            $checked = 'checked';
        } else {
            // ...else leave the variable empty.
            $checked = '';
        }

        // Return HTML to be output to the submission edit page.
        return "<input type='hidden' name='fields[$id]' value='0' >
                <input type='checkbox' name='fields[$id]' id='' $checked>";
    }

    /**
     * Custom Columns
     * Creates what is displayed in the columns on the submissions page.
     * @since 3.0
     *nf_subs_export_pre_value
     * @param $value checkbox value
     * @param $field field model.
     * @return $value string|void
     */
    public function custom_columns( $value, $field )
    {
        // If the field type is equal to checkbox...
        if( 'checkbox' == $field->get_setting( 'type' ) ) {
            // Backwards compatibility check for the new checked value setting.
            if( null == $field->get_setting( 'checked_value' ) && 1 == $value || 'on' == $value ) {
                return esc_html__( 'Checked', 'ninja-forms' );
            } elseif( null == $field->get_setting( 'unchecked_value' ) && 0 == $value ) {
                return esc_html__( 'Unchecked', 'ninja-forms');
            }

            // If the field value is set to 1....
            if( 1 == $value || 'on' == $value ) {
                // Set the value to the checked value setting.
                $value = $field->get_setting( 'checked_value' );
            }
            // Unless we've somehow gotten the display value in here...
            elseif ( $field->get_setting( 'checked_value' ) != $value ) {
                // Set the value to the unchecked value setting.
                $value = $field->get_setting( 'unchecked_value' );
            }
        }
        return $value;
    }

    /**
     * Filter Merge Tag Value
     * This is what provides the merge tag with the fields value.
     * @since 3.0
     *
     * @param $value Field value
     * @param $field field model
     * @return string|void
     */
    public function filter_merge_tag_value( $value, $field )
    {
        $settings = ( isset( $field[ 'settings' ] ) && is_array( $field[ 'settings' ] ) )
            ? $field[ 'settings' ]
            : array();

        return $this->resolve_display_wording( $settings, (bool) $value );
    }

    /**
     * Resolve Display Wording
     * Gives the words a merge tag prints for a checkbox: the wording the form
     * author typed into Checked Value or Unchecked Value, and otherwise the
     * built-in label.
     *
     * Calculation values are deliberately not consulted. They are numbers for
     * calculations, and standing in for the wording here turned a customer's
     * "Checked" line into "100" in their email notification.
     *
     * @since 3.16.2
     * @see https://github.com/Saturday-Drive/ninja-forms/issues/7633
     *
     * @param array $settings field settings
     * @param bool $is_checked whether the checkbox was checked
     * @return string
     */
    protected function resolve_display_wording( $settings, $is_checked )
    {
        $setting_name = $is_checked ? 'checked_value' : 'unchecked_value';

        if ( isset( $settings[ $setting_name ] ) && $this->has_display_value( $settings[ $setting_name ] ) ) {
            return $settings[ $setting_name ];
        }

        return $is_checked
            ? esc_html__( 'Checked', 'ninja-forms' )
            : esc_html__( 'Unchecked', 'ninja-forms' );
    }

    /**
     * Filter Merge Tag Value Calc
     * Provides the calculation value when the merge tag is used.
     * @since 3.0
     *
     * @param $value checkbox value
     * @param $field field model
     * @return $field
     */
    public function filter_merge_tag_value_calc( $value, $field )
    {
        // If checkbox is checked (using same logic as filter_merge_tag_value)...
        if ( ! empty( $value ) ) {
            // Return checked calc value with defensive fallback for different field structures
            if ( isset( $field['checked_calc_value'] ) ) {
                return $field['checked_calc_value'];
            } elseif ( isset( $field['settings']['checked_calc_value'] ) ) {
                return $field['settings']['checked_calc_value'];
            }
            return 1; // Default calc value for checked
        } else {
            // Return unchecked calc value with defensive fallback
            if ( isset( $field['unchecked_calc_value'] ) ) {
                return $field['unchecked_calc_value'];
            } elseif ( isset( $field['settings']['unchecked_calc_value'] ) ) {
                return $field['settings']['unchecked_calc_value'];
            }
            return 0; // Default calc value for unchecked
        }
    }

    /**
     * Export Value
     * Determines the value to send to submission export.
     * @since 3.0
     *
     * @param $value checkbox field value
     * @param $field checkbox field model
     * @return string
     */
    public function export_value( $value, $field )
    {
        // If value is redacted, return it unchanged.
        if ( '(redacted)' === $value ) {
            return $value;
        }

        // @TODO: Why were these values translated in the first place?
        // If value is equal to checked or unchecked return the value
        if ( 'checked' === $value ||
            'unchecked' === $value ) return $value;

        // Creating settings variables for our check.
        if( is_array( $field ) ) {
            // The email action sends teh field variable as an array
            $checked_setting    = $field[ 'settings' ][ 'checked_value' ] ?? '';
            $unchecked_setting  = $field[ 'settings' ][ 'unchecked_value' ] ?? '';
        } else {
            $checked_setting    = $field->get_setting( 'checked_value' );
            $unchecked_setting  = $field->get_setting( 'unchecked_value' );
        }

        /*
         * The stored value is a flag, not words. Numbers, null and booleans
         * read as they always have, but a string that is not numeric never
         * matches a flag: before PHP 8 a loose 0 == $value was true for any
         * non-numeric string, which swallowed a value already stored as a
         * display word and reported it as unchecked.
         */
        $is_numeric   = is_numeric( $value );
        $is_checked   = 'on' === $value || true === $value || ( $is_numeric && 1 == $value );
        $is_unchecked = null === $value || false === $value || ( $is_numeric && 0 == $value );

        // If the the value and check to see if we have checked and unchecked settings...
        if ( $is_checked && $this->has_display_value( $checked_setting ) ) {
            // ...if we do return checked setting
            return $checked_setting;
        } elseif ( $is_unchecked && $this->has_display_value( $unchecked_setting ) ) {
            // ...else return unchecked setting.
            return $unchecked_setting;
        /*
         * These checks are for checkbox fields that were created before version 3.2.7.
         */
        } elseif ( $is_checked ) {
            return esc_html__( 'Checked', 'ninja-forms' );
        } elseif ( $is_unchecked ) {
            return esc_html__( 'Unchecked', 'ninja-forms' );
        }

        // Nothing above matched, so the stored value is already a display word:
        // one saved while the site ran in another language, for example. Show it
        // as it is, escaped the way the display values above are.
        return esc_html( $value );
    }

    /**
     * Localize Field
     * Pass checkbox display values to JavaScript for front-end merge tag replacement.
     * Exposes resolved checked/unchecked values at the top level so
     * fieldModel.get('checked_value') works in JavaScript.
     *
     * @since 3.14.2
     *
     * @param array $field
     * @return array
     */
    public function localize_field( $field )
    {
        $settings = ( isset( $field['settings'] ) && is_array( $field['settings'] ) )
            ? $field['settings']
            : array();

        // The front end shows the same wording an Action prints, so the page
        // and the email notification cannot disagree.
        $field['checked_value']   = $this->resolve_display_wording( $settings, true );
        $field['unchecked_value'] = $this->resolve_display_wording( $settings, false );

        return $field;
    }
}
