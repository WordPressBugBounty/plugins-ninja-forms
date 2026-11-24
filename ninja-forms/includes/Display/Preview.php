<?php if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class NF_Display_Preview
 */
class NF_Display_Preview
{
    protected $_form_id = '';

  public function __construct()
  {
    $this->_form_id = $this->constructFormId();

    if(is_null($this->_form_id)){
      return;
    }
    
    add_action('pre_get_posts', array($this, 'pre_get_posts'));

    add_filter('the_title', array($this, 'the_title'));
    remove_filter('the_content', 'wpautop');
    remove_filter('the_excerpt', 'wpautop');
    add_filter('the_content', array($this, 'the_content'), 9001);
    add_filter('get_the_excerpt', array($this, 'the_content'));
    add_filter('template_include', array($this, 'template_include'));

    add_filter('post_thumbnail_html', array($this, 'post_thumbnail_html'));
  }

    public function pre_get_posts( $query )
    {
		$query->set( 'posts_per_page', 1 );
    }

    /**
     * @return string
     */
    function the_title( $title )
    {
        if( ! in_the_loop() ) return $title;

        $form_title = Ninja_Forms()->form( $this->_form_id )->get()->get_setting( 'title' );

        /* translators: Browser page title. %s is the title of the form. */
        return sprintf( esc_html__( '%s Preview', 'ninja-forms' ), esc_html( $form_title ) );
    }

    /**
     * @return string
     */
    function the_content()
    {
        if ( !$this->userCanViewPreview() ) return esc_html__( 'You must be logged in and have form privileges to preview a form.', 'ninja-forms' );

        // takes into account if we are trying to preview a non-published form
        $tmp_id_test = explode( '-', $this->_form_id );

        // if only 1 element, then is it numeric
	    if( 1 === count( $tmp_id_test) && ! is_numeric( $tmp_id_test[ 0 ] ) ) {
		    return esc_html__( 'You must provide a valid form ID.', 'ninja-forms' );
	    }
	    // if 2 array elements, is the first equal to 'tmp' and the second numeric
	    elseif ( ( 2 === count( $tmp_id_test )
	                 && ('tmp' != $tmp_id_test[ 0 ]
                     || ! is_numeric( $tmp_id_test[ 1 ] ) ) ) ) {
		    return esc_html__( 'You must provide a valid form ID.', 'ninja-forms' );
    }

    return do_shortcode("[nf_preview id='" . esc_attr($this->_form_id) . "']");
  }

  /**
   * Construct the form id
   * 
   * Check for GET parameter, then sanitize.  Failures return null
   *
   * @return string|null
   */
  protected function constructFormId()
  {
    $return = null;

    $previewParameter = $this->extractPreviewGetParameter();

    if (is_null($previewParameter)) {
      return $return;
    }

    $sanitizedFormId = $this->sanitizeFormId($previewParameter);

    if (is_null($sanitizedFormId)) {
      return $return;
    }

    return $sanitizedFormId;
  }

      /**
     * Return the GET parameter for form preview id
     *
     * @return string|null
     */
    protected function extractPreviewGetParameter()
    {
      $return = null;

      if ( isset( $_GET['nf_preview_form'] ) ){
        $return = $_GET['nf_preview_form'];
      }

      return $return;
    }

  /**
   * Ensure form Id is only integer or tmp-*
   *
   * If disallowed structure is found, return null
   * 
   * @param int|string $unsanitizedFormId
   * @return int|string|null
   */
  protected function sanitizeFormId($unsanitizedFormId)
  {
    $return = null;

    $wpSanitized = WPN_Helper::sanitize_text_field($unsanitizedFormId);

    if(is_int($wpSanitized) ||
    is_string($wpSanitized) && ctype_digit($wpSanitized) ){

      $return = $wpSanitized;
      return $return;
    }

    if(!is_string($wpSanitized)){
      return $return;
    }
    
    $return = $this->sanitizeForUnpublishedFormId($wpSanitized);
    
    return $return;
  }

  /**
   * Allow non-integer-like values form unpublished form
   * 
   * Uses format tmp-***
   *
   * @param string $incoming
   * @return void
   */
  protected function sanitizeForUnpublishedFormId(string $incoming)
  {
    $return = null;

    if (strpos($incoming, 'tmp-') === 0) {
      $prefixRemoved = str_replace('tmp-', '', $incoming);
      if (ctype_digit($prefixRemoved)) {
        $return = $incoming;
      }
    }

    return $return;
  }

  /**
   * Does user have permission to preview forms?
   *
   * @return boolean
   */
  protected function userCanViewPreview(): bool
  {
    $return = true;
    if (! is_user_logged_in() || !current_user_can(apply_filters('ninja_forms_admin_all_forms_capabilities', 'manage_options'))) {
      $return = false;
    }
    return $return;
  }

  /**
   * Supply the template the form preview is rendered with.
   *
   * Hooked to template_include, so WordPress performs the include itself and
   * the rest of the render still runs. Ending the request here instead - which
   * is what this did between 3.6.10 and the fix for issue #7878 - skips every
   * later stage, and a theme that builds part of its layout in that stage, such
   * as Divi, is left with a header and an empty body.
   *
   * get_query_template() resolves block templates as well as classic ones, so a
   * block (FSE) theme receives the same template WordPress would have chosen for
   * it. That is what the 3.6.10 change needed and what locate_template(), used
   * here before it, could not give.
   *
   * @param string $template The template WordPress resolved.
   * @return string
   */
    function template_include( $template )
    {
      $templates = array( 'page.php', 'single.php', 'index.php');

      $preview_template = get_query_template('ninja-forms', $templates);

      return $preview_template ? $preview_template : $template;
    }

    function post_thumbnail_html() {
    	return '';
    }

} // END CLASS NF_Display_Preview
