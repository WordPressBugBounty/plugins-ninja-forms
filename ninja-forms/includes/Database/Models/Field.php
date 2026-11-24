<?php if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class NF_Database_Models_Field
 */
final class NF_Database_Models_Field extends NF_Abstracts_Model
{
    private $form_id = '';

    protected $_type = 'field';

    protected $_table_name = 'nf3_fields';

    protected $_meta_table_name = 'nf3_field_meta';

    protected $_columns = array(
        'label',
        'key',
        'parent_id',
        'type',
        'created_at'
    );
    public function __construct( $db, $id, $parent_id = '' )
    {
        parent::__construct( $db, $id, $parent_id );
    }

	/**
	 * Delete
	 *
	 * Delete the object, its children, and its relationships.
	 *
	 * Also deletes data associated with field
	 *
	 * @return bool
	 */
    public function delete() {
        // Captured first: the parent deletes the row this id came from.
        $field_id = $this->get_id();

        $result = parent::delete();

        $this->delete_submission_values( $field_id );

        return $result;
    }

    /**
     * Remove every value this field ever collected.
     *
     * The builder warns, before deleting a saved field that holds data, that
     * "This will also DELETE all submission data associated with this field."
     * Nothing used to act on that: the field row, its settings and its
     * relationships went, and the answers stayed in postmeta forever, hidden
     * from every admin screen. See issue #8175.
     *
     * Submission values live as `_field_{fieldId}` postmeta on the `nf_sub`
     * post. Field ids are unique across the install, so the key identifies one
     * field's answers exactly.
     *
     * Deleted through delete_metadata() with $delete_all, rather than a hand
     * written DELETE, so the object cache is invalidated for every affected
     * submission. A site with many submissions makes this one publish slower;
     * that is deliberate, so the product never reports "deleted" about data
     * that is still there.
     *
     * @param int|string $field_id Field whose submission values should go.
     * @return void
     */
    private function delete_submission_values( $field_id )
    {
        $field_id = (string) $field_id;

        /*
         * Whole numbers only, and deliberately not absint(). A Repeatable
         * Fieldset child is referenced as "5.2", whose parent is field 5.
         * Coercing that to an integer here would delete every answer the
         * fieldset ever collected instead of one field's. Children are not
         * rows in this table and are never deleted through this model.
         */
        if ( '' === $field_id || ! ctype_digit( $field_id ) ) {
            return;
        }

        delete_metadata( 'post', 0, '_field_' . $field_id, '', true );
    }

    public static function import( array $settings, $field_id = '', $is_conversion = FALSE )
    {
        $settings = apply_filters( 'ninja_forms_before_import_fields', $settings );
        $settings[ 'saved' ] = 1;

        if( $field_id && $is_conversion ) {
            $field = Ninja_Forms()->form()->field( $field_id )->get();
        } else {
            $field = Ninja_Forms()->form()->field()->get();
        }
        $field->update_settings( $settings );
        $field->save();
    }

} // End NF_Database_Models_Field
