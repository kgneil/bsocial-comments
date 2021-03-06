<?php

class bSocial_Comments_Feedback_Admin extends bSocial_Comments_Feedback
{
	public function __construct()
	{
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 10, 2 );

		add_filter( 'manage_edit-comments_columns', array( $this, 'comments_columns' ) );
		add_filter( 'manage_comments_custom_column', array( $this, 'manage_comments_custom_column' ), 10, 2 );
		add_filter( 'manage_edit-comments_sortable_columns', array( $this, 'manage_edit_comments_sortable_columns' ) );
		add_filter( 'comments_clauses', array( $this, 'comments_clauses' ) );
	} // end __construct

	/**
	 * Hook to admin_enqueue_scripts
	 */
	public function admin_enqueue_scripts( $current_page )
	{
		$script_config = apply_filters( 'go_config', array( 'version' => bsocial_comments()->version ), 'go-script-version' );

		wp_enqueue_style( $this->id_base . '-admin', plugins_url( '/css/bsocial-comments-feedback-admin.css', __FILE__ ), array(), $script_config['version'] );
		wp_register_script( $this->id_base . '-admin', plugins_url( '/js/bsocial-comments-feedback-admin.js', __FILE__ ), array( 'jquery' ), $script_config['version'], TRUE );

		// Only enqueue script when on a comment edit page where it's needed
		if ( 'comment.php' == $current_page || 'edit-comments.php' == $current_page )
		{
			wp_enqueue_script( $this->id_base . '-admin' );
		} // END if
	} // END admin_enqueue_scripts

	/**
	 * Add metaboxes
	 */
	public function add_meta_boxes( $post_type, $post )
	{
		if ( 'comment' != $post_type )
		{
			return;
		} // END if

		add_meta_box( $this->id_base . '-faves', 'Comment Faves', array( $this, 'faves_meta_box' ), 'comment', 'normal', 'high' );
		add_meta_box( $this->id_base . '-flags', 'Comment Flags', array( $this, 'flags_meta_box' ), 'comment', 'normal', 'high' );
	} // END add_meta_boxes

	/**
	 * Render the comment faves metabox
	 */
	public function faves_meta_box( $comment )
	{
		require_once __DIR__ . '/class-bsocial-comments-feedback-table.php';

		$go_list_table = new bSocial_Comments_Feedback_Table();

		$go_list_table->current_comment = $comment;
		$go_list_table->type = 'fave';

		$go_list_table->prepare_items();
		$go_list_table->custom_display();
	} // END faves_meta_box

	/**
	 * Render the comment flags metabox
	 */
	public function flags_meta_box( $comment )
	{
		require_once __DIR__ . '/class-bsocial-comments-feedback-table.php';

		$go_list_table = new bSocial_Comments_Feedback_Table();

		$go_list_table->current_comment = $comment;
		$go_list_table->type = 'flag';

		$go_list_table->prepare_items();
		$go_list_table->custom_display();
	} // END flags_meta_box

	/**
	 * Hook to manage_edit-comments_columns filter and add columns for flags and faves
	 *
	 * @param $columns (array) array of column slugs and names
	 */
	public function comments_columns( $columns )
	{
		// Rebuild the array so we can make sure Faves/Flags columns show right after the comment content
		$new_columns = array();

		foreach ( $columns as $column => $column_name )
		{
			$new_columns[ $column ] = $column_name;

			if ( 'comment' == $column )
			{
				$new_columns['faves'] = 'Faves';
				$new_columns['flags'] = 'Flags';
			} // END if
		} // END foreach

		return $new_columns;
	} // END comments_columns

	/**
	 * Hook to the manage_comments_custom_column filter and echo appropriate content for the column
	 *
	 * @param $column (string) slug of the column being rendered
	 * @param $comment_id (int) WP comment_id value for the comment being viewed
	 */
	public function manage_comments_custom_column( $column, $comment_id )
	{
		if ( 'faves' != $column && 'flags' != $column )
		{
			return;
		} // END if

		switch ( $column )
		{
			case 'faves':
				$this->faves_column( $comment_id );
				break;

			case 'flags':
				$this->flags_column( $comment_id );
				break;
		} // END switch
	} // END manage_comments_custom_column

	/**
	 * Render and echo fave column value for a given comment
	 *
	 * @param $comment_id (int) WP comment_id value for the comment being viewed
	 */
	public function faves_column( $comment_id )
	{
		if ( ! $comment = get_comment( $comment_id ) )
		{
			return;
		} // END if

		if ( 'fave' == $comment->comment_type )
		{
			echo $this->get_parent_link( $comment->comment_parent );
		} // END if
		elseif ( '' == $comment->comment_type || 'comment' == $comment->comment_type )
		{
			$count = $this->get_comment_fave_count( $comment_id );
			echo 0 == $count ? '<span class="zero">' . absint( $count ) . '</span>' : '<span class="faves">+ ' . absint( $count ) . '</span>';
		} // END elseif
	} // END faves_column

	/**
	 * Render and echo flag column value for a given comment
	 *
	 * @param $comment_id (int) WP comment_id value for the comment being viewed
	 */
	public function flags_column( $comment_id )
	{
		if ( ! $comment = get_comment( $comment_id ) )
		{
			return;
		} // END if

		if ( 'flag' == $comment->comment_type )
		{
			echo $this->get_parent_link( $comment->comment_parent );
		} // END if
		elseif ( '' == $comment->comment_type || 'comment' == $comment->comment_type )
		{
			$count = $this->get_comment_flag_count( $comment_id );
			echo 0 == $count ? '<span class="zero">' . absint( $count ) . '</span>' : '<span class="flags">- ' . absint( $count ) . '</span>';
		} // END elseif
	} // END flags_column

	/**
	 * Echos a comment edit link for a flag/fave parent
	 *
	 * @param $parent_id (int) WP parent_id value the link should be created for
	 */
	public function get_parent_link( $parent_id )
	{
		$url = add_query_arg( array( 'action' => 'editcomment', 'c' => absint( $parent_id ) ), admin_url( 'comment.php' ) );
		echo '<a href="' . esc_url( $url ) . '" title="Edit parent comment" class="post-com-count"><span class="comment-count">&nbsp;</span></a>';
	} // END get_parent_link

	/**
	 * Hook to the manage_edit-comments_sortable_columns filter and add faves/flags to the list of sortable columns
	 *
	 * @param $parent_id (int) WP parent_id value the link should be created for
	 */
	public function manage_edit_comments_sortable_columns( $sortable_columns )
	{
		$sortable_columns['faves'] = 'faves';
		$sortable_columns['flags'] = 'flags';

		return $sortable_columns;
	} // END manage_edit_comments_sortable_columns

	/**
	 * Hook into the comments_clauses filter hook and return adjusted clauses to support fave/flag column sorting
	 *
	 * @param $clauses (array) Array of SQL clauses for the comments query
	 */
	public function comments_clauses( $clauses )
	{
		global $wpdb;

		if ( ! is_admin() )
		{
			return $clauses;
		} // END if

		$current_screen = get_current_screen();

		// Make sure the query is for all statuses
		if (
			! isset( $_GET['orderby'] )
			|| ( isset( $_GET['orderby'] ) && 'faves' != $_GET['orderby'] && 'flags' != $_GET['orderby'] )
			|| 'edit-comments' != $current_screen->base
		)
		{
			return $clauses;
		} // END if

		// Make sure we can work with the commentmeta table
		$clauses['join'] = trim( $clauses['join'] ) . ' JOIN ' . $wpdb->commentmeta .' ON ' . $wpdb->commentmeta . '.comment_id = ' . $wpdb->comments . '.comment_ID';

		$type = 'faves' == $_GET['orderby'] ? 'faves' : 'flags';

		// Get fave/flag meta value so we can sort on it
		$clauses['where'] = trim( $clauses['where'] ) . $wpdb->prepare( ' AND meta_key = %s', $this->id_base . '-' . $type );

		// Order by the meta_value first then the date second this is a little hacky looking but it works
		$clauses['orderby'] = 'meta_value ' . $clauses['order'] . ',';
		$clauses['order']   = 'comment_date_gmt DESC';

		return $clauses;
	} // END comments_clauses
}// END bSocial_Comments_Feedback_Admin
