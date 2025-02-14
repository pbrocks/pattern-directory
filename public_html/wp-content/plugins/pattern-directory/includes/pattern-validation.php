<?php

namespace WordPressdotorg\Pattern_Directory\Pattern_Validation;
use const WordPressdotorg\Pattern_Directory\Pattern_Post_Type\POST_TYPE;

add_filter( 'rest_pre_insert_' . POST_TYPE, __NAMESPACE__ . '\validate_content', 10, 2 );
add_filter( 'rest_pre_insert_' . POST_TYPE, __NAMESPACE__ . '\validate_title', 11, 2 );

/**
 * Strip out basic HTML to get at the manually-entered content in block content.
 *
 * First, remove class attributes, since custom class names will be caught by attribute checks.
 * Next, remove empty alt tags, which are present on default image blocks.
 * Lastly, remove any HTML tags without attributes- this regex catches opening, closing, and self-closing tags.
 * After all this, any block_content left should be there intentionally by the author.
 *
 * @param string $html The block content, from `innerHTML` of a parsed block.
 * @return string Any content that doesn't match the cases described above.
 */
function strip_basic_html( $html ) {
	$to_replace = array( '/class="[^"]*"/', '/alt=""/', '/<\/?[a-zA-Z]+\s*\/?>/' );
	return trim( preg_replace( $to_replace, '', $html ) );
}

/**
 * Check if a block has been edited by the user, as opposed to an empty/placeholder block.
 *
 * @param array $block A parsed block object.
 * @return bool Whether the block has been edited.
 */
function is_not_empty_block( $block ) {
	$registry = \WP_Block_Type_Registry::get_instance();
	$block_type = $registry->get_registered( $block['blockName'] );

	// Paragraphs are a special case, these should never be empty.
	if ( 'core/paragraph' === $block['blockName'] ) {
		$block_content = strip_basic_html( $block['innerHTML'] );
		if ( empty( $block_content ) ) {
			return false;
		}
	}

	// Allow dynamic blocks, which contain no content and maybe no attributes.
	$allowed_empty = [ 'core/archives', 'core/calendar', 'core/latest-posts', 'core/separator', 'core/spacer', 'core/tag-cloud' ];
	if ( in_array( $block['blockName'], $allowed_empty ) ) {
		return true;
	}

	// Check if the attributes are different from the default attributes.
	$block_attrs = $block_type->prepare_attributes_for_render( $block['attrs'] );
	$default_attrs = $block_type->prepare_attributes_for_render( array() );
	if ( $block_attrs != $default_attrs ) {
		return true;
	}

	// If there are any child blocks, check those. Only return if there are real child blocks,
	// otherwise continue on to check for any other content.
	if ( count( $block['innerBlocks'] ) >= 1 ) {
		$child_blocks = array_filter( $block['innerBlocks'], __NAMESPACE__ . '\is_not_empty_block' );
		if ( count( $child_blocks ) ) {
			return true;
		}
	}

	$block_content = strip_basic_html( $block['innerHTML'] );
	if ( ! empty( $block_content ) ) {
		return true;
	}
	return false;
}

/**
 * Validate the pattern content.
 */
function validate_content( $prepared_post, $request ) {
	if ( is_wp_error( $prepared_post ) ) {
		return $prepared_post;
	}

	// If post_content does not exist, this is just an update to an existing pattern.
	if ( ! isset( $prepared_post->post_content ) ) {
		return $prepared_post;
	}

	$content = $prepared_post->post_content;
	if ( ! $content ) {
		return new \WP_Error(
			'rest_pattern_empty',
			__( 'Pattern content cannot be empty.', 'wporg-patterns' ),
			array( 'status' => 400 )
		);
	}

	// The editor adds in linebreaks between blocks, but parse_blocks thinks those are invalid blocks.
	$content = str_replace( "\n\n", '', $content );
	$blocks = parse_blocks( $content );
	$registry = \WP_Block_Type_Registry::get_instance();

	// $blocks contains a list of the blocks in the content. By default it will always have one item, even if it's
	// not valid block content. Instead, we should check that each block in the list has a blockName.
	$invalid_blocks = array_filter( $blocks, function( $block ) use ( $registry ) {
		$block_type = $registry->get_registered( $block['blockName'] );
		return is_null( $block['blockName'] ) || is_null( $block_type );
	} );
	if ( count( $invalid_blocks ) ) {
		return new \WP_Error(
			'rest_pattern_invalid_blocks',
			__( 'Pattern content contains invalid blocks.', 'wporg-patterns' ),
			array( 'status' => 400 )
		);
	}

	// Next, we should check that we have at least one non-empty block.
	$real_blocks = array_filter( $blocks, __NAMESPACE__ . '\is_not_empty_block' );

	if ( ! count( $real_blocks ) ) {
		return new \WP_Error(
			'rest_pattern_empty_blocks',
			__( 'Pattern content contains only empty blocks.', 'wporg-patterns' ),
			array( 'status' => 400 )
		);
	}

	return $prepared_post;
}

/**
 * Validate the pattern title.
 */
function validate_title( $prepared_post, $request ) {
	if ( is_wp_error( $prepared_post ) ) {
		return $prepared_post;
	}

	$status = isset( $request['status'] ) ? $request['status'] : get_post_status( $prepared_post->ID );
	// Bypass this validation for drafts.
	if ( 'draft' === $status || 'auto-draft' === $status ) {
		return $prepared_post;
	}

	// A title exists, but is empty -- invalid.
	if ( isset( $request['title'] ) && empty( trim( $request['title'] ) ) ) {
		return new \WP_Error(
			'rest_pattern_empty_title',
			__( 'A pattern title is required.', 'wporg-patterns' ),
			array( 'status' => 400 )
		);
	}

	// The existing pattern doesn't have a title, and none is set -- invalid.
	$post_title = get_the_title( $prepared_post->ID );
	if ( empty( $post_title ) && ! isset( $request['title'] ) ) {
		return new \WP_Error(
			'rest_pattern_empty_title',
			__( 'A pattern title is required.', 'wporg-patterns' ),
			array( 'status' => 400 )
		);
	}

	return $prepared_post;
}
