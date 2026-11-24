<?php if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class NF_MergeTags_WordPress
 */
class NF_MergeTags_WP extends NF_Abstracts_MergeTags
{
    protected $id = 'wp';

    /**
     * The post the submitted form was displayed on.
     *
     * NULL means no context was carried with the submission. 0 means the form
     * was displayed somewhere that has no post of its own, such as an archive
     * or a search results page. See issue #4028.
     *
     * @var int|null
     */
    protected $post_context_id = null;

    /**
     * Whether the view the submitted form was displayed on was the site's home
     * page. A home page showing the latest posts has no post of its own but
     * still has an address worth reporting. See issue #4028.
     *
     * @var bool
     */
    protected $post_context_is_home = false;

    public function __construct()
    {
        parent::__construct();
        $this->title = esc_html__( 'WordPress', 'ninja-forms' );
        $this->merge_tags = Ninja_Forms()->config( 'MergeTagsWP' );
    }

    /**
     * Custom replace() method for custom post meta or user meta.
     * @param string|array $subject
     * @return string
     */
    public function replace( $subject )
    {
        if(is_null($subject)){
            return '';
        }
        
        // Recursively replace merge tags.

        if( is_array( $subject ) ){
            foreach( $subject as $i => $s ){
                $subject[ $i ] = $this->replace( $s );
            }
            return $subject;
        }

        /**
         * Replace Custom Post Meta
         * {post_meta:foo} --> meta key is 'foo'
         */
        preg_match_all( "/{post_meta:(.*?)}/", $subject, $post_meta_matches );
        if( ! empty( $post_meta_matches[0] ) ) {
            /**
             * $matches[0][$i]  merge tag match     {post_meta:foo}
             * $matches[1][$i]  captured meta key   foo
             */
            foreach( $post_meta_matches[0] as $i => $search ) {
                $meta_key   = $post_meta_matches[1][$i];
                $meta_value = get_post_meta( $this->post_id(), $meta_key, true  );

                if ( '' != $meta_value ) {
                    $subject = str_replace( $search, $this->prepareSubstitutedValue( $meta_value ), $subject );
                } else {
                    $subject = str_replace( $search, '', $subject );
                }
            }
        }
        /**
         * Replace Custom User Meta
         * {user_meta:foo} --> meta key is 'foo'
         */
        $user_id = $this->getCurrentUserId();

        preg_match_all( "/{user_meta:(.*?)}/", $subject, $user_meta_matches );
        // if user is logged in and we have user_meta merge tags
        if( ! empty( $user_meta_matches[0] ) && $user_id != 0  ) {
            /**
             * $matches[0][$i]  merge tag match     {user_meta:foo}
             * $matches[1][$i]  captured meta key   foo
             */
            foreach ($user_meta_matches[0] as $i => $search) {
                $meta_key = $user_meta_matches[1][$i];

                $meta_value = $this->getUserMeta($user_id, $meta_key);

                // Only attempt replacement if meta value is string
                if (is_string($meta_value)) {

                    $subject = str_replace($search, $this->prepareSubstitutedValue($meta_value), $subject);
                }
            }
            
        // if a user is not logged in, but there are user_meta merge tags
        } elseif ( ! empty( $user_meta_matches[0] ) && $user_id == 0 ) {
        	$subject = '';
        }
        return parent::replace( $subject );
    }

    /**
     * Wrap WP get_user_meta to enable unit testing
     *
     * @param int $user_id
     * @param string $meta_key
     * @return mixed
     */
    protected function getUserMeta($user_id, $meta_key)
    {
        $return = get_user_meta( $user_id, $meta_key, true );

        return $return;
    }

    /**
     * Return the current user Id
     *
     * @return void
     */
    protected function getCurrentUserId( ): int
    {
        $return = get_current_user_id();
        
        return $return;
    }

    /**
     * Record the post the submitted form was displayed on.
     *
     * The settings posted back with a form are client data, so the post is only
     * accepted when it exists and this request is allowed to read it. An absent
     * key leaves the context unset, so forms rendered before this release keep
     * their previous behaviour. See issue #4028.
     *
     * @param array $submitted_settings Form settings posted back with the submission.
     * @return void
     */
    public function set_post_context( $submitted_settings )
    {
        $this->clear_post_context();

        if ( ! is_array( $submitted_settings ) || ! isset( $submitted_settings[ 'nf_post_context_id' ] ) ) {
            return;
        }

        // From here on the form has told us where it was displayed, even when
        // the answer is "nowhere with a post of its own".
        $this->post_context_id      = 0;
        $this->post_context_is_home = ! empty( $submitted_settings[ 'nf_post_context_is_home' ] );

        $post_id = absint( $submitted_settings[ 'nf_post_context_id' ] );

        if ( ! $post_id ) {
            return;
        }

        $post = get_post( $post_id );

        if ( ! $post ) {
            return;
        }

        $publicly_viewable = function_exists( 'is_post_publicly_viewable' )
            ? is_post_publicly_viewable( $post )
            : ( 'publish' === $post->post_status );

        if ( ! $publicly_viewable && ! current_user_can( 'read_post', $post->ID ) ) {
            return;
        }

        $this->post_context_id = (int) $post->ID;
    }

    /**
     * Forget any post context carried from a previous submission.
     *
     * @return void
     */
    public function clear_post_context()
    {
        $this->post_context_id      = null;
        $this->post_context_is_home = false;
    }

    /**
     * Whether the submission told us which post the form was displayed on.
     *
     * @return bool
     */
    protected function has_post_context()
    {
        return null !== $this->post_context_id;
    }

    protected function post_id()
    {
        global $post;

        if ( $this->has_post_context() ) {
            // The form told us where it was displayed, and that answer is
            // final. A view with no post of its own must not borrow another
            // post's data. See issue #4028.
            return $this->post_context_id ? $this->post_context_id : '';
        }

        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // No context was carried, so fall back to the referer.
            $post_id = $this->viewable_referer_post_id( url_to_postid( wp_get_referer() ) );
        } elseif( $post ) {
            $post_id = $post->ID;
        } else {
            return ''; // No Post ID found.
        }

        return $post_id ? $post_id : '';
    }

    protected function post_title()
    {
        $post_id = $this->post_id();
        if( ! $post_id ) return;
        $post = get_post( $post_id );
        return ( $post ) ? $post->post_title : '';
    }

    protected function post_url()
    {
        // Outside the loop, the front page is not the last displayed blog post.
        if ( ! is_admin() && is_front_page() && ! in_the_loop() ) {
            return home_url( '/' );
        }

        $post_id = $this->post_id();

        if ( ! $post_id && $this->has_post_context() ) {
            // The form was displayed on a view with no post of its own. Only
            // the site's home page has an address worth reporting; an archive
            // or a search result has none. See issue #4028.
            return $this->post_context_is_home ? home_url( '/' ) : '';
        }

        if ( ! $post_id && is_admin() && wp_doing_ajax() ) {
            // A latest-posts homepage has no post ID to recover from the referer.
            $referer = wp_get_referer();
            // Query parameters and fragments do not change the containing homepage.
            $referer = $referer ? preg_split( '/[?#]/', $referer, 2 )[0] : '';
            if ( $referer && untrailingslashit( $referer ) === untrailingslashit( home_url( '/' ) ) ) {
                return home_url( '/' );
            }
        }
        if( ! $post_id ) return;
        $post = get_post( $post_id );
        return ( $post ) ? get_permalink( $post->ID ) : '';
    }

    protected function post_author()
    {
        $post_id = $this->post_id();
        if( ! $post_id ) return;
        $post = get_post( $post_id );
        if( ! $post ) return '';
        $author = get_user_by( 'id', $post->post_author);
        return $author->display_name;
    }

    protected function post_author_email()
    {
        $post_id = $this->post_id();
        if( ! $post_id ) return;
        $post = get_post( $post_id );
        if( ! $post ) return '';
        $author = get_user_by( 'id', $post->post_author );
        return $author->user_email;
    }

    protected function user_id()
    {
        $current_user = wp_get_current_user();

        return ( $current_user ) ? $current_user->ID : '';
    }

    protected function user_first_name()
    {
        $current_user = wp_get_current_user();

        return ( $current_user ) ? $current_user->user_firstname : '';
    }

    protected function user_last_name()
    {
        $current_user = wp_get_current_user();

        return ( $current_user ) ? $current_user->user_lastname : '';
    }

    protected function user_display_name()
    {
        $current_user = wp_get_current_user();

        return ( $current_user ) ? $current_user->display_name : '';
    }

	protected function user_username()
	{
		$current_user = wp_get_current_user();

		return ( $current_user ) ? $current_user->user_nicename : '';
	}

    protected function user_email()
    {
        $current_user = wp_get_current_user();

        return ( $current_user ) ? $current_user->user_email : '';
    }

    protected function user_url()
    {
        $current_user = wp_get_current_user();

        return ( $current_user ) ? $current_user->user_url : '';
    }

    protected function admin_email()
    {
        return get_option( 'admin_email' );
    }

    protected function site_title()
    {
        return get_bloginfo( 'name' );
    }

    protected function site_url()
    {
        return get_bloginfo( 'url' );
    }

} // END CLASS NF_MergeTags_System
