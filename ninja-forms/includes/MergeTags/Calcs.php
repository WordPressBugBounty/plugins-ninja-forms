<?php if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class NF_MergeTags_Calcs
 */
class NF_MergeTags_Calcs extends NF_Abstracts_MergeTags
{
    protected $id = 'calcs';

    protected $_default_group = FALSE;

    /** @var array Separators for each calculation, separate from numeric values. */
    protected $calc_formats = array();

    /** @var bool Whether the current replacement is for a display action. */
    protected $format_action_values = false;

    public function __construct()
    {
        parent::__construct();
        $this->title = esc_html__( 'Calculations', 'ninja-forms' );
        add_filter( 'ninja_forms_calc_setting',  array( $this, 'replace' ) );
    }

    public function __call($name, $arguments)
    {
        $value = $this->merge_tags[ $name ][ 'calc_value' ];

        // Action context is scoped to email/success messages.
        // Arithmetic and integration consumers continue to receive canonical numbers.
        if ( $this->format_action_values && isset( $this->calc_formats[ $name ] ) ) {
            $format = $this->calc_formats[ $name ];
            $point = strpos( $value, '.' );
            $precision = ( false === $point ) ? 0 : strlen( $value ) - $point - 1;
            return number_format( (float) $value, $precision, $format['decimal'], $format['thousands'] );
        }

        return $value;
    }

    /**
     * Format display actions even when their settings omit builder-only objectType.
     *
     * @param mixed $subject Action settings.
     * @return string|array
     */
    public function action_replace( $subject )
    {
        $previous = $this->format_action_values;
        $this->format_action_values = is_array( $subject ) && isset( $subject['type'] )
            && in_array( $subject['type'], array( 'email', 'successmessage' ), true );
        try {
            return parent::action_replace( $subject );
        } finally {
            $this->format_action_values = $previous;
        }
    }

    public function set_merge_tags( $key, $value, $round = 2 , $dec = '.', $sep = ',')
    {
        $callback = ( is_numeric( $key ) ) ? 'calc_' . $key : $key;

        // Retain display separators without changing stored numeric values.
        $format = array( 'decimal' => $dec, 'thousands' => $sep );
        $this->calc_formats[ $callback ] = $format;
        $this->calc_formats[ $callback . '2' ] = $format;

        try {
            $locale = new stdClass();
            $locale->number_format = array(
                'thousands_sep' => $sep,
                'decimal_point' => $dec
            );
            $handler = new NF_Handlers_LocaleNumberFormatting($locale);
            $eq = $handler->locale_decode_equation($value);
            $calculated_value = Ninja_Forms()->eos()->solve( $eq );
        } catch( Exception $e ){
            $calculated_value = FALSE;
        }

        $this->merge_tags[ $callback ] = array(
            'id' => $key,
            'tag' => "{calc:$key}",
            'callback' => $callback,
            'calc_value' => number_format( $calculated_value, $round, '.', '' )
        );

        $callback .= '2';

        $this->merge_tags[ $callback ] = array(
            'id' => $key,
            'tag' => "{calc:$key:2}",
            'callback' => $callback,
            'calc_value' => number_format( $calculated_value, 2, '.', '' )
        );
    }
    
    /**
     * Order calculations so each one is evaluated after the calculations it uses.
     *
     * Calculations reference each other with {calc:name} merge tags, which only
     * resolve once the referenced calculation has been set. Evaluating in the
     * builder's list order therefore counted any later-listed reference as 0.
     * References inside a loop (A uses B, B uses A, or a calc using itself) are
     * left for the caller's scrub, so they still count as 0.
     *
     * @param array $calculations Calculation settings in builder order.
     * @return array The same calculations in evaluation order.
     */
    public static function get_evaluation_order( $calculations )
    {
        $calculations = array_values( (array) $calculations );

        // Name => position of the first calculation with that name.
        $by_name = array();
        foreach ( $calculations as $index => $calc ) {
            if ( isset( $calc['name'] ) && ! isset( $by_name[ $calc['name'] ] ) ) {
                $by_name[ $calc['name'] ] = $index;
            }
        }

        // Positions of the calculations each calculation references.
        $references = array();
        foreach ( $calculations as $index => $calc ) {
            $references[ $index ] = array();
            $eq = isset( $calc['eq'] ) && is_string( $calc['eq'] ) ? $calc['eq'] : '';
            preg_match_all( '/{calc:([^}]*)}/', $eq, $matches );
            foreach ( $matches[1] as $reference ) {
                if ( ! isset( $by_name[ $reference ] ) && ':2' === substr( $reference, -2 ) ) {
                    $reference = substr( $reference, 0, -2 );
                }
                if ( isset( $by_name[ $reference ] ) ) {
                    $references[ $index ][] = $by_name[ $reference ];
                }
            }
        }

        // Depth-first walk with an explicit stack, so long chains never nest
        // calls. Each calculation is entered once; a reference back into an
        // entered calculation (a loop) is skipped.
        $ordered = array();
        $entered = array();

        foreach ( array_keys( $calculations ) as $start ) {
            if ( isset( $entered[ $start ] ) ) {
                continue;
            }
            $entered[ $start ] = true;
            $stack = array( array( $start, 0 ) );

            while ( $stack ) {
                $top = count( $stack ) - 1;
                list( $index, $next ) = $stack[ $top ];

                if ( $next < count( $references[ $index ] ) ) {
                    $stack[ $top ][1]++;
                    $reference = $references[ $index ][ $next ];
                    if ( ! isset( $entered[ $reference ] ) ) {
                        $entered[ $reference ] = true;
                        $stack[] = array( $reference, 0 );
                    }
                    continue;
                }

                array_pop( $stack );
                $ordered[] = $calculations[ $index ];
            }
        }

        return $ordered;
    }

    /**
     * Re-key calculation results into the builder's list order.
     *
     * @param array $results Calculation results keyed by name, in evaluation order.
     * @param array $calculations Calculation settings in builder order.
     * @return array
     */
    public static function restore_builder_order( $results, $calculations )
    {
        $ordered = array();
        foreach ( (array) $calculations as $calc ) {
            if ( isset( $calc['name'] ) && array_key_exists( $calc['name'], $results ) ) {
                $ordered[ $calc['name'] ] = $results[ $calc['name'] ];
            }
        }

        // Anything without a matching setting keeps its place at the end.
        return $ordered + $results;
    }

    public function get_calc_value( $key )
    {
        $return = null;

        if(isset($this->merge_tags[ $key ][ 'calc_value' ])){
            $return = $this->merge_tags[ $key ][ 'calc_value' ];
        }
        
        return $return; 
    }
    
    // @TODO: $round is no longer necessary in this context.
    public function get_formatted_calc_value( $key, $round = 2, $dec = '.', $sep = ',')
    {
        $locale = new stdClass();
        $locale->number_format = array(
            'thousands_sep' => $sep,
            'decimal_point' => $dec
        );
        $handler = new NF_Handlers_LocaleNumberFormatting($locale);
        return $handler->locale_encode_number( $this->merge_tags[ $key ][ 'calc_value' ] );
    }

} // END CLASS NF_MergeTags_Calcs
