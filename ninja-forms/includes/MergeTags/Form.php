<?php if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class NF_MergeTags_Form
 */
final class NF_MergeTags_Form extends NF_Abstracts_MergeTags
{
    protected $id = 'form';

    protected $form_id;

    protected $form_title;

    protected $sub_seq;

    /**
     * Submission count frozen for the submission currently being processed.
     *
     * Calculations are evaluated before the submission is saved, while actions
     * such as email and success message run after it. Counting the database on
     * demand would therefore report one number to a calculation and a different
     * number to the email describing the same submission. The count is taken
     * once when submission processing starts, includes the submission being
     * made, and is reused for the rest of the request.
     *
     * NULL means no submission is being processed and the count is read live.
     *
     * @since 3.16.2
     * @var int|null
     */
    protected $in_progress_sub_count = null;

    /**
     * Whether a submission is currently being processed for this form.
     *
     * @var bool
     */
    protected $processing_submission = false;

    public function __construct()
    {
        parent::__construct();
        $this->title = esc_html__( 'Form', 'ninja-forms' );

        $this->merge_tags = Ninja_Forms()->config( 'MergeTagsForm' );

		add_action( 'ninja_forms_save_sub', array( $this, 'setSubSeq' ) );

        // Gets the form ID.
        add_action( 'nf_get_form_id', array( $this, 'set_form_id' ), 15, 1 );
    }

    /**
     * @return mixed
     */
    public function getSubSeq()
    {
        /*
         * A custom upload file name is built while the submission's fields are
         * processed, which happens before the submission is saved. Claim the
         * number the stored entry will keep so the file name and the Submissions
         * page agree. See issue #6859.
         */
        if ( '' === (string) $this->sub_seq && $this->processing_submission && $this->form_id ) {
            $this->sub_seq = NF_Database_Models_Form::reserve_sub_seq( $this->form_id );
        }

        return $this->sub_seq;
    }

    /**
     * Tell the merge tags that a submission is being processed for this form.
     *
     * Only a real submission claims a sequence number; rendering a form on a
     * page, or previewing one in the builder, must not consume one.
     *
     * @param string|int $form_id    Form being submitted.
     * @param bool       $is_preview Whether this is a builder preview.
     * @return void
     */
    public function prepareSubmissionProcessing( $form_id, $is_preview = false )
    {
        $this->set_form_id( $form_id );
        // Each submission claims its own number, never the previous one's.
        $this->sub_seq = '';
        $this->processing_submission = ! $is_preview;
    }

    /**
     * @param mixed $sub_seq
     */
    public function setSubSeq( $sub_id )
    {
        $submission = Ninja_Forms()->form()->sub( $sub_id )->get();
        $this->sub_seq = $submission->get_seq_num();
    }

	/**
	 * Getter method for the form_id.
	 *
	 * @return void
	 */
	public function get_form_id()
	{
		return $this->form_id;
	}

	/**
	 * Getter method for the form title.
	 *
	 * @return void
	 */
	public function get_form_title()
	{
		return $this->form_title;
	}

    /**
     * Setter method for the form_id and callback for the nf_get_form_id action.
     * @since 3.2.2
     *
     * @param string $form_id The ID of the current form.
     * @return void
     */
    public function set_form_id( $form_id )
    {
        if( $form_id != $this->form_id ){
            $this->in_progress_sub_count = null;
        }

        $this->form_id = $form_id;
    }

    /**
     * Freeze the submission count for the submission now being processed.
     *
     * Called by the submission controller once the form ID is known and before
     * calculations are evaluated. The frozen count includes the submission
     * being made, so {submission:count} answers "how many submissions have been
     * made, including this one" everywhere in the request: in a calculation
     * evaluated before the save, and in an email or success message rendered
     * after it.
     *
     * @since 3.16.2
     *
     * @return void
     */
    public function start_submission()
    {
        $this->in_progress_sub_count = $this->query_sub_count() + 1;
    }

	/**
	 * Setter method for the form_title
	 *
	 * @param string $form_title The title of the current form.
	 * @return void
	 */
	public function set_form_title( $form_title )
	{
		$this->form_title = $form_title;
	}

    /**
     * Gets a count of the form submissions and callback for the sub_count merge tag setting.
     * @since 3.2.2
     *
     * @return array|int Count of the form submissions.
     */
    public function get_sub_count()
    {
        if( ! is_null( $this->in_progress_sub_count ) ){
            return $this->in_progress_sub_count;
        }

        return $this->query_sub_count();
    }

    /**
     * Counts the submissions a form currently has.
     *
     * Only published submissions count. A submission the site owner deleted is
     * in the trash, and counting it would report capacity that is not in use.
     * This matches the submission limit check in the submission controller.
     *
     * @since 3.16.2
     *
     * @return int Count of the form's submissions.
     */
    protected function query_sub_count()
    {
		global $wpdb;

		// Query the database for the total amount of submissions for a form.
    	$query = "SELECT DISTINCT( COUNT( wpp.id ) ) AS sub_count FROM `" .
			$wpdb->prefix . "posts` wpp JOIN 
`" . $wpdb->prefix . "postmeta` 
			wpm ON	wpp.id = wpm.post_id
			WHERE wpm.meta_key = '_form_id' AND wpm.meta_value = %s
			AND wpp.post_status = 'publish'";

    	$count = $wpdb->get_results( $wpdb->prepare( $query, $this->form_id ) );

        return (int) $count[ 0 ]->sub_count;
    }
} // END CLASS NF_MergeTags_Form
