<?php if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class NF_Fields_Hidden
 */
class NF_Fields_Hidden extends NF_Abstracts_Input
{
    protected $_name = 'hidden';

    protected $_nicename = 'Hidden';

    protected $_section = 'misc';

    protected $_icon = 'eye-slash';

    protected $_type = 'hidden';

    protected $_templates = 'hidden';

    protected $_wrap_template = 'wrap-no-label';

    protected $_settings_only = array(
        'key', 'label', 'default', 'admin_label', 'classes'
    );

    protected $_use_merge_tags_include = array( 'calculations' );

    public function __construct()
    {
        parent::__construct();

        $use_merge_tags = array( 'include' => array( 'calculations' ) );

        $this->_settings[ 'label' ][ 'width' ] = 'full';
        $this->_settings[ 'default' ][ 'group' ] = 'primary';
        $this->_settings[ 'default' ][ 'user_merge_tags' ] = $use_merge_tags;

        $this->_nicename = esc_html_x( 'Hidden', 'field type name', 'ninja-forms' );

        add_filter( 'ninja_forms_merge_tag_calc_value_' . $this->_type, array( $this, 'get_calc_value' ), 10, 2 );
    }

    /**
     * Get calculation value for merge tag.
     *
     * A fixed saved default can only be replaced by a value that a Conditional
     * Logic "change value" rule on the form can set. Any other submitted value
     * falls back to the saved default. Empty or merge-tag defaults, and rules
     * whose value contains a merge tag, cannot be verified and are left as-is.
     *
     * @see https://github.com/Saturday-Drive/ninja-forms/issues/8257
     *
     * @param mixed $value Submitted field value.
     * @param array $field Field settings, including server-supplied form_conditions.
     * @return mixed Calculation value.
     */
    public function get_calc_value( $value, $field )
    {
        $default = isset( $field[ 'default' ] ) && is_scalar( $field[ 'default' ] ) ? trim( (string) $field[ 'default' ] ) : '';
        if ( '' === $default || false !== strpos( $default, '{' ) ) {
            return $value;
        }

        $allowed = array( $default );
        $key = isset( $field[ 'key' ] ) ? (string) $field[ 'key' ] : '';
        $conditions = isset( $field[ 'form_conditions' ] ) && is_array( $field[ 'form_conditions' ] ) ? $field[ 'form_conditions' ] : array();
        foreach ( $conditions as $condition ) {
            foreach ( array( 'then', 'else' ) as $branch ) {
                if ( empty( $condition[ $branch ] ) || ! is_array( $condition[ $branch ] ) ) {
                    continue;
                }
                foreach ( $condition[ $branch ] as $trigger ) {
                    if ( ! isset( $trigger[ 'key' ], $trigger[ 'trigger' ] ) || $key !== (string) $trigger[ 'key' ] || 'change_value' !== $trigger[ 'trigger' ] ) {
                        continue;
                    }
                    $rule_value = isset( $trigger[ 'value' ] ) && is_scalar( $trigger[ 'value' ] ) ? trim( (string) $trigger[ 'value' ] ) : '';
                    if ( false !== strpos( $rule_value, '{' ) ) {
                        return $value;
                    }
                    $allowed[] = $rule_value;
                }
            }
        }

        if ( is_scalar( $value ) && in_array( trim( (string) $value ), $allowed, true ) ) {
            return $value;
        }

        return $field[ 'default' ];
    }
}
