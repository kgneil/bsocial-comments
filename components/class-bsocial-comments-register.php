<?php

class bSocial_Comments_Register
{
	public $id_base = 'bsocial-comments-register';
	public $label_defaults = array(
		'name'          => 'Comments',
		'singular_name' => 'Comment',
		'edit_item'     => 'Edit Comment',
		'update_item'   => 'Update Comment',
		'view_item'     => 'View Comment',
	);
	public $comment_types = array();
	public $comment_statuses = array();

	/**
	 * Start things up by plugging us into all of filter and actions hooks we'll need to support custom comment type registration
	 */
	public function __construct()
	{
		// 9 so that we run before most plugin code that way other plugins can add actions without interference
		add_filter( 'comment_row_actions', array( $this, 'comment_row_actions' ), 9, 2 );
		add_filter( 'bulk_actions-edit-comments', array( $this, 'bulk_actions' ), 9, 2 );
		add_filter( 'comment_status_links', array( $this, 'comment_status_links_remove' ), 9, 2 );
		add_filter( 'comment_status_links', array( $this, 'comment_status_links_add' ), 11, 2 );
		add_filter( 'wp_count_comments', array( $this, 'wp_count_comments' ), 10, 2 );
		add_filter( 'comments_per_page', array( $this, 'comments_per_page' ) );
		add_filter( 'admin_comment_types_dropdown', array( $this, 'admin_comment_types_dropdown' ) );

		add_action( 'transition_comment_status', array( $this, 'transition_comment_status' ), 10, 3 );
		add_action( 'wp_insert_comment', array( $this, 'wp_insert_comment' ) );

		// Doing this involves a performance hit, so it'll be off by default until a better method for this comes along
		if ( bsocial_comments()->options()->register->filter_text )
		{
			// 11 so that we run after any translation code has done so
			add_filter( 'gettext', array( $this, 'gettext' ), 11, 3 );
			add_filter( 'gettext_with_context', array( $this, 'gettext_with_context' ), 11, 4 );
		} // END if
	} // end __construct

	/**
	 * Registers a comment type inserting it into all of the correct places in WP to make it usable
	 *
	 * @param $comment_type (string) The name of the comment type
	 * @param $args (array) args you want to use to register this comment type
	 */
	public function comment_type( $comment_type, $args )
	{
		$comment_type = sanitize_key( $comment_type );

		$defaults = array(
			'labels'        => array(),
			'description'   => '',
			'public'        => FALSE,
			'show_ui'       => NULL,
			'admin_actions' => array(
				'approve',
				'reply',
				'quickedit',
				'edit',
				'spam',
				'trash',
			),
			'statuses' => array(
				'approved',
				'spam',
				'trash',
			),
		);

		$args = wp_parse_args( $args, $defaults );
		$args = (object) $args;

		$args->name = $comment_type;

		if ( strlen( $comment_type ) > 20 )
		{
			return new WP_Error( 'comment_type_too_long', 'Comment types cannot exceed 20 characters in length' );
		} // END if

		// If not set, default to the setting for public.
		if ( NULL === $args->show_ui )
		{
			$args->show_ui = $args->public;
		} // END if

		$args->labels = $this->get_comment_labels( $args );

		$this->comment_types[ $args->name ] = $args;

		return $args;
	} // END comment_type

	/**
	 * Parses through labels and makes sure that we've got values set that we need
	 *
	 * @param $comment_type_object (object) The comment type object you want to parse the labels of
	 */
	public function get_comment_labels( $comment_type_object )
	{
		$comment_type_object->labels = (object) wp_parse_args( (array) $comment_type_object->labels, $this->label_defaults );

		$comment_type = $comment_type_object->name;

		return apply_filters( 'go_comment_comment_type_labels_' . $comment_type, $comment_type_object->labels );
	} // END get_comment_labels

	/**
	 * Registers a comment status inserting it into all of the correct places in WP to make it usable
	 *
	 * @param $comment_status (string) The name of the comment type
	 * @param $args (array) args you want to use to register this comment status
	 */
	public function comment_status( $status, $args = array() )
	{
		$defaults = array(
			'label'                  => FALSE,
			'label_count'            => FALSE,
			'public'                 => FALSE,
			'show_in_admin_all_list' => NULL,
		);

		$args = wp_parse_args( $args, $defaults );
		$args = (object) $args;

		$post_status = sanitize_key( $status );
		$args->name = $post_status;

		if ( strlen( $comment_type ) > 20 )
		{
			return new WP_Error( 'comment_status_too_long', 'Comment statuses cannot exceed 20 characters in length' );
		} // END if

		if ( NULL === $args->show_in_admin_all_list  )
		{
			$args->show_in_admin_all_list = $args->public;
		}

		$this->comment_statuses[ $args->name ] = $args;

		return $args;
	} // END comment_status

	/**
	 * Filters the admin_comment_types_dropdown hook and adds any comment types that have show_ui set
	 *
	 * @param $comment_types (array) Array of comment types available in the admin drop down
	 */
	public function admin_comment_types_dropdown( $comment_types )
	{
		foreach ( $this->comment_types as $comment_type )
		{
			if ( $comment_type->show_ui && ! isset( $comment_types[ $comment_type->name ] ) )
			{
				$comment_types[ $comment_type->name ] = $comment_type->labels->name;
			} // END if
		} // END foreach

		return $comment_types;
	} // END admin_comment_types_dropdown

	/**
	 * Filters the gettext_with_context hook by passing the parameters to the gettext method below
	 *
	 * @param $translated_text (string) The translated text
	 * @param $text (string) The original untrnaslated text
	 * @param $context (string) The context of the text (i.e. noun, verb, etc...)
	 * @param $domain (string) A value allowing developers to differentiate a possibly matching string of text from another
	 */
	public function gettext_with_context( $translated_text, $text, $context, $domain )
	{
		return $this->gettext( $translated_text, $text, $domain, $context );
	} // END gettext_with_context

	/**
	 * Filters the gettext hook and replaces comment related text with comment type labels when appropriate
	 * Includes an optional $context param to deal with gettext_with_context stuff
	 *
	 * @param $translated_text (string) The translated text
	 * @param $text (string) The original untrnaslated text
	 * @param $domain (string) A value allowing developers to differentiate a possibly matching string of text from another
	 * @param $context (string) The context of the text (i.e. noun, verb, etc...)
	 */
	public function gettext( $translated_text, $text, $domain, $context = FALSE )
	{
		// Check context
		if ( $context && 'noun' != $context )
		{
			return $translated_text;
		} // END if

		// Does this text eve apply?
		if ( ! in_array( $translated_text, $this->label_defaults ) )
		{
			return $translated_text;
		} // END if

		// Try to get a comment object, if we can't there's no point in conituing
		// Passing a 0 value to get_comment forces it to find the comment on it's own
		// You have to pass 0 as a variable because of the way get_comment is written in WordPress core
		$comment = 0;

		if ( ! $comment = get_comment( $comment ) )
		{
			return $translated_text;
		} // END if

		if ( isset( $this->comment_types[ $comment->comment_type ] ) )
		{
			$label = array_search( $translated_text, $this->label_defaults );
			$translated_text = $this->comment_types[ $comment->comment_type ]->labels->$label;
		} // END if

		return $translated_text;
	} // END gettext

	/**
	 * Filters comment_row_actions hook and returns only the actions for the comment type
	 *
	 * @param $actions (array) Array of action links for the given comment
	 * @param $comment (object) WordPress comment object
	 */
	public function comment_row_actions( $actions, $comment )
	{
		$this->filter_actions( $actions, $comment->comment_type );

		return $this->filter_actions( $actions, $comment->comment_type );
	} // END comment_row_actions

	/**
	 * Filters bulk_actions hook and returns a only the actions for this comment type
	 *
	 * @param $actions (array) Array of action links for the given comment
	 */
	public function bulk_actions( $actions )
	{
		if ( ! isset( $_GET['comment_type'] ) || empty( $_GET['comment_type'] ) )
		{
			return $actions;
		} // END if

		return $this->filter_actions( $actions, $_GET['comment_type'] );
	} // END bulk_actions

	/**
	 * Filters an array of actions removing any that don't match the comment type
	 * Only acts if the comment type is a custom one registered with this plugin
	 *
	 * @param $actions (array) Array of actions
	 * @param $comment_type (string) The comment type you want to filter for
	 */
	public function filter_actions( $actions, $comment_type )
	{
		if ( ! isset( $this->comment_types[ $comment_type ] ) )
		{
			return $actions;
		} // END if

		$new_actions = array();

		foreach ( $this->comment_types[ $comment_type ]->admin_actions as $action )
		{
			if ( isset( $actions[ $action ] ) )
			{
				$new_actions[ $action ] = $actions[ $action ];
			} // END if
		} // END foreach

		return $new_actions;
	} // END filter_actions

	/**
	 * Filters comment_status_links to only include comment statuses that were registered with this plugin for the currently active comment type
	 *
	 * @param $status_links (array) Array of status links for use in the edit-comments admin panel
	 */
	public function comment_status_links_remove( $status_links )
	{
		if ( ! isset( $_GET['comment_type'] ) || empty( $_GET['comment_type'] ) )
		{
			return $status_links;
		} // END if

		$new_status_links = array();
		$new_status_links['all'] = $status_links['all'];

		foreach ( $this->comment_types[ $_GET['comment_type'] ]->statuses as $status )
		{
			if ( isset( $status_links[ $status ] ) )
			{
				$new_status_links[ $status ] = $status_links[ $status ];
			} // END if
		} // END foreach

		return $new_status_links;
	} // END comment_status_links_remove

	/**
	 * Filters comment_status_links to include additional comment statuses that were registered with this plugin
	 *
	 * @param $status_links (array) Array of status links for use in the edit-comments admin panel
	 */
	public function comment_status_links_add( $status_links )
	{
		foreach ( $this->get_all_statuses() AS $status )
		{
			if ( ! $status = $this->get_status( $status ) )
			{
				continue;
			} // END if

			if ( ! isset( $status_links[ $status->name ] ) && $status->show_in_admin_all_list )
			{
				$status_links[ $status->name ] = $this->get_status_link( $status->name, $status->label_count );
			} // END if
		} // END foreach

		return $status_links;
	} // END comment_status_links_add

	/**
	 * Builds a status link for use in the admin panelf or a given comment status status
	 *
	 * @param $status (string) The comment status you want a link for
	 * @param $status (array) An array of labels appropriate for use in translate_nooped_plural
	 */
	public function get_status_link( $status, $label )
	{
		$link = 'edit-comments.php';

		if ( isset( $_GET['comment_type'] ) && ! empty( $_GET['comment_type'] ) && 'all' != $_GET['comment_type'] )
		{
			$comment_type = $_GET['comment_type'];
			$link = add_query_arg( 'comment_type', $comment_type, $link );
		} // END if

		$class = ( $status == $comment_status ) ? ' class="current"' : '';

		$link = add_query_arg( 'comment_status', $status, $link );

		if ( $post_id )
		{
			$link = add_query_arg( 'p', absint( $post_id ), $link );
		} // END if

		$stats = wp_count_comments();

		return $status_links[ $status ] = '<a href=' . $link . $class . '>' . sprintf( translate_nooped_plural( $label, $stats->$status ), number_format_i18n( $stats->$status ) ) . '</a>';
	} // END get_status_link

	/**
	 * Filters the wp_count_comments hook and returns stats for all comment statuses including ones registered with this plugin
	 *
	 * @param $stats (object) Stats of comment counts for each comment status
	 * @param $post_id (int) A WordPress post id value
	 *
	 * @TODO Need to make sure that this cache gets refreshed at the right times? (i.e. whenever a comment is deleted or it's post_status changes in some way)
	 */
	public function wp_count_comments( $stats, $post_id )
	{
		// See if we've cached the results already
		$stats = wp_cache_get( 'stats-' . $post_id, $this->id_base );

		if ( FALSE != $stats )
		{
			return $stats;
		} // END if

		// Temporarily remove this filter so we can get the existing stats
		remove_filter( 'wp_count_comments', array( $this, 'wp_count_comments' ), 10, 2 );

		// Get the existing stats
		$stats = wp_count_comments( $post_id );

		// Get the statuses from our comment types
		$statuses = $this->get_all_statuses();

		// Make sure we're only caring about statuses we don't already have stats for
		foreach ( $statuses as $status )
		{
			if ( isset( $stats->$status ) )
			{
				$statuses = array_diff( $statuses, array( $status ) );
			} // END if
		} // END foreach

		$status_counts = $this->get_comment_status_counts( $post_id, $statuses );

		foreach ( $status_counts as $count )
		{
			do_action( 'debug_robot', print_r( $count, TRUE ) );
			$status = $count->comment_approved;
			$stats->status = $count->num_comments;
		} // END foreach

		// Set cache for this set of stats
		wp_cache_set( 'stats-' . $post_id, $stats, $this->id_base );

		add_filter( 'wp_count_comments', array( $this, 'wp_count_comments' ), 10, 2 );

		return $stats;
	} // END wp_count_comments

	/**
	 * Returns an array of all statuses associated with our custom comment types
	 */
	public function get_all_statuses()
	{
		$all_statuses = array();

		foreach ( $this->comment_types as $comment_type )
		{
			foreach ( $comment_type->statuses as $status )
			{
				if ( ! in_array( $status, $all_statuses ) )
				{
					$all_statuses[] = $status;
				} // END if
			} // END foreach
		} // END foreach

		return $all_statuses;
	} // END get_all_statuses

	/**
	 * Gets comment status counts for a given $post_id for the given statuses
	 *
	 * @param $post_id (int) A WordPress post id value
	 * @param $statuses (array) An array of comment statuses
	 */
	public function get_comment_status_counts( $post_id, $statuses )
	{
		global $wpdb;

		$where = '';

		if ( 0 < $post_id )
		{
			$where = $wpdb->prepare( 'WHERE comment_post_ID = %d', $post_id );
		} // END if

		if ( 0 < count( $statuses ) )
		{
			$where = '' != $where ? $where . ' AND' : 'WHERE';
			$where .= ' comment_approved IN ( ' . substr( str_repeat( '%s, ', count( $statuses ) ), 0, -2 ) . ' )';
			$where = $wpdb->prepare( $where, $statuses );
		} // END if

		return $wpdb->get_results( 'SELECT comment_approved, COUNT( * ) AS num_comments FROM ' . $wpdb->comments . ' ' . $where . ' GROUP BY comment_approved' );
	} // END get_comment_status_counts

	/**
	 * Gets the status object for the given status if it has been registered with this plugin
	 *
	 * @param $status (string) A custom status registered with this plugin
	 */
	public function get_status( $status )
	{
		if ( ! isset( $this->comment_statuses[ $status ] )  )
		{
			return FALSE;
		} // END if

		return $this->comment_statuses[ $status ];
	} // END get_status

	/**
	 * WP_Comments_List_Table::prepare_items ignores any statuses that arent' built into WordPress
	 * The comments_per_page hook conveniently fires before $comment_status ever gets used so we can fix it here
	 *
	 * @param $comments_per_page (int) The number of comments to display per page in the admin interface
	 */
	public function comments_per_page( $comments_per_page )
	{
		global $comment_status;

		if ( isset( $_GET['comment_status'] ) && in_array( $_GET['comment_status'], $this->get_all_statuses() ) )
		{
			$comment_status = $_GET['comment_status'];
		} // END if

		return $comments_per_page;
	} // END comments_per_page

	/**
	 * Hook to transition_comment_status action to watch for status changes and delete relating stats caches in response
	 *
	 * @param $new_status (string) The new status of the comment
	 * @param $old_status (string) The old status of the comment
	 * @param $comment (object) WordPress comment object
	 */
	public function transition_comment_status( $new_status, $old_status, $comment )
	{
		// Make sure a change actually occured
		// From what I can tell WP already does this but relying on that to always be the case makes me nervous
		if ( $new_status == $old_status )
		{
			return;
		} // END if

		$this->delete_caches( $comment );
	} // END transition_comment_status

	/**
	 * Hook to wp_insert_comment action to watch for new comments and delete relating stats caches in response
	 *
	 * @param $comment_id (int) The id of the new comment
	 * @param $comment (object) WordPress comment object for the new comment
	 */
	public function wp_insert_comment( $comment_id, $comment )
	{
		$this->delete_caches( $comment );
	} // END wp_insert_comment

	/**
	 * Deletes caches related to our custom comment types and statuses
	 *
	 * @param $comment (object) WordPress comment object
	 */
	public function delete_caches( $comment )
	{
		if ( ! isset( $comment->comment_post_ID ) )
		{
			return FALSE;
		} // END if

		// Delete the comment status stats for the given post AND the generic 'All' statuses stats cache
		wp_cache_delete( 'stats-' . $comment->comment_post_ID, $this->id_base );
		wp_cache_delete( 'stats-0', $this->id_base );
	} // END delete_caches
}// END bSocial_Comments_Register