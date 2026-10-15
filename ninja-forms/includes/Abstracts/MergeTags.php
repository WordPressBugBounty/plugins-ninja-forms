<?php if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class NF_Abstracts_MergeTags
 */
abstract class NF_Abstracts_MergeTags
{
    /**
     * Merge Tag object id
     *
     * @var string
     */
    protected $id = '';

    /**
     * Merge tag object title
     *
     * @var string
     */
    protected $title = '';

    /**
     * Merge tag configuration array
     *
     * @var array
     */
    protected $merge_tags = array();

    /**
     * Is Merge Tag object in default group?
     *
     * @var boolean
     */
    protected $_default_group = TRUE;

    /**
     * Should Merge Tag follow use_safe rules?
     *
     * @var boolean
     */
    protected $use_safe = FALSE;

    /**
     * Is the current replacement going into content that will be run
     * through do_shortcode()?
     *
     * @var boolean
     */
    protected $neutralize_substituted_shortcodes = FALSE;

    public function __construct()
    {
        add_filter( 'kbj_test', array( $this, 'replace' ) );

        add_filter( 'ninja_forms_render_default_value', array( $this, 'replace_default_value' ) );

        add_filter( 'ninja_forms_run_action_settings',  array( $this, 'action_replace' ) );
        add_filter( 'ninja_forms_run_action_settings_preview',  array( $this, 'action_replace' ) );

        add_filter( 'ninja_forms_calc_setting',  array( $this, 'replace' ) );

        /* Manually trigger Merge Tag replacement */
        add_filter( 'ninja_forms_merge_tags', array( $this, 'replace' ) );
    }

    /**
     * Merge Tag replacement on an action
     *
     * @param mixed $subject
     * @return string|array
     */
    public function action_replace( $subject ) {
        if( is_array($subject) && isset($subject['objectType']) && 'Action' == $subject['objectType'] ) {
            // Make sure that payment totals use calc values.
            if( isset($subject['payment_total'])
                && isset($subject['payment_total_type'])
                && $subject['payment_total_type'] == 'field'
                && ! is_numeric($subject['payment_total'])
                && ! strpos($subject['payment_total'], ':calc}') )
            {
                $subject['payment_total'] = substr_replace($subject['payment_total'], ':calc', -1, 0);
            }
        }

        // Email and Success Message use the escaped safe_value for field merge
        // tags. Keyed off the action type alone: objectType is a builder-only
        // key absent from stored/published action settings (#8232), the same
        // shape as the shortcode-neutralization gate below and #8228.
        $previous_use_safe = $this->use_safe;
        $this->use_safe = is_array( $subject )
            && isset( $subject['type'] )
            && ( 'email' === $subject['type'] || 'successmessage' === $subject['type'] );

        // The success message is run through do_shortcode(). Keyed off the
        // action type alone: stored action settings can omit objectType.
        $previous_neutralize = $this->neutralize_substituted_shortcodes;
        $this->neutralize_substituted_shortcodes = is_array( $subject )
            && isset( $subject['type'] )
            && 'successmessage' === $subject['type'];

        try {
            $subject = $this->replace( $subject );
        } finally {
            // Restore the previous flags after we finish replacing.
            $this->use_safe = $previous_use_safe;
            $this->neutralize_substituted_shortcodes = $previous_neutralize;
        }
        return $subject;
    }

    /**
     * Merge Tag replacement on a field default value
     *
     * Default values are run through do_shortcode() when the form renders, so
     * values substituted into them are made unable to form a shortcode. The
     * default value's own text is left untouched.
     *
     * @param mixed $subject
     * @return string|array
     */
    public function replace_default_value( $subject )
    {
        $previous_neutralize = $this->neutralize_substituted_shortcodes;
        $this->neutralize_substituted_shortcodes = true;

        try {
            return $this->replace( $subject );
        } finally {
            $this->neutralize_substituted_shortcodes = $previous_neutralize;
        }
    }

    /**
     * Replace incoming value with matched MergeTag value
     * 
     * @param string|array $subject
     * @return string
     */
    public function replace( $subject )
    {
        if(is_null($subject)){
            return '';
        }

        if( is_array( $subject ) ){
            foreach( $subject as $i => $s ){
                $subject[ $i ] = $this->replace( $s );
            }
            return $subject;
        }

        $matches = $this->getMatches($subject);

        if( empty( $matches ) ) return $subject;

        foreach( $this->merge_tags as $merge_tag ){
            if( ! isset( $merge_tag[ 'tag' ] ) || ! in_array( $merge_tag[ 'tag' ], $matches ) ) continue;

            if( ! isset($merge_tag[ 'callback' ])) continue;

            $callback = $this->extractCallback($merge_tag,$matches);

            $replace = $this->getReplacement($callback);
            
            $subject = str_replace( $merge_tag[ 'tag' ], $replace, $subject );
        }

        return $subject;
    }

    /**
     * Return matches for merge tag
     *
     * @param string $subject Expects string value, but not guaranteed
     * @return array
     */
    protected function getMatches( $subject): array
    {
        preg_match_all("/{([^}]*)}/", $subject, $matches );

        return $matches[0];
    }

    /**
     * Extract callable callback from merge tag configuration
     *
     * @param array $mergeTag
     * @param array $matches
     * @return string
     */
    protected function extractCallback(array $mergeTag, array $matches)
    {
        $return = '';

        if (! isset($mergeTag['tag']) || ! in_array($mergeTag['tag'], $matches)) {
            return $return;
        };

        if (! isset($mergeTag['callback'])) {
            return $return;
        };

        // Remove static callback potential
        if (
            is_string($mergeTag['callback']) &&
            false !== strpos($mergeTag['callback'], '::')
        ) {

            return $return;
        } 
        
        // Remove class initializtion potential
        if (
            is_array($mergeTag['callback'])
            && is_string($mergeTag['callback'][0])
            && 0 === strpos(trim($mergeTag['callback'][0]), 'new')
        ) {

            return $return;
        }

        // All undesired potential callbacks removed
        $return = $mergeTag['callback'];

        return $return;
    }

    /**
     * Call callable function for replacement value
     *
     * @param string $callback
     * @return string
     */
    protected function getReplacement($callback)
    {
        $return = '';

        if ( is_callable( array( $this, $callback ) ) ) {
            $return = $this->{$callback}();
        } elseif ( is_callable( $callback ) ) {
            $return = $callback();
        }

        return $this->maybeNeutralizeSubstitutedShortcodes( $return );
    }

    /**
     * Neutralize shortcode brackets in a substituted value when the content
     * being replaced will be run through do_shortcode()
     *
     * @param mixed $value
     * @return mixed
     */
    protected function maybeNeutralizeSubstitutedShortcodes( $value )
    {
        if ( ! $this->neutralize_substituted_shortcodes || ! is_string( $value ) ) {
            return $value;
        }

        return str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $value );
    }

    /**
     * Get Merge Tag id
     *
     * @return string
     */
    public function get_id()
    {
        return $this->id;
    }

    /**
     * Get Merge Tag title
     *
     * @return string
     */
    public function get_title()
    {
        return $this->title;
    }

    /**
     * Get Merge Tag configuration array
     *
     * @return array
     */
    public function get_merge_tags()
    {
        return $this->merge_tags;
    }

    /**
     * Is MergeTag in default group?
     *
     * @return boolean
     */
    public function is_default_group()
    {
        return $this->_default_group;
    }


} // END CLASS NF_Abstracts_MergeTags
