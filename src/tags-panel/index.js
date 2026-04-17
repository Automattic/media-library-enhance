/**
 * Tags InspectorControls panel.
 *
 * Adds a "Tags" panel to the block sidebar for image-bearing blocks so
 * editors can apply media_tag terms to the underlying attachment without
 * leaving the post editor. Tags persist on the attachment, so the same
 * image inserted in another post inherits them.
 *
 * Wired via the editor.BlockEdit filter — the documented stable
 * extension point. Avoids forking core block definitions.
 */
import { addFilter } from '@wordpress/hooks';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, FormTokenField, Spinner } from '@wordpress/components';
import { useEntityRecord, useEntityRecords } from '@wordpress/core-data';
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { createHigherOrderComponent } from '@wordpress/compose';
import { Fragment, useMemo } from '@wordpress/element';

const TARGET_BLOCKS = new Set( [
	'core/image',
	'core/cover',
	'core/media-text',
	'core/gallery',
] );

/**
 * Resolve the single attachment ID for a block, or null if the panel
 * shouldn't render. Galleries with multiple images are intentionally
 * skipped — multi-image tagging is a future enhancement.
 *
 * @param {string} name       The block name.
 * @param {Object} attributes The block attributes.
 * @return {number|null} Attachment ID or null.
 */
function getAttachmentId( name, attributes ) {
	if ( name === 'core/gallery' ) {
		const ids = attributes?.ids ?? [];
		return ids.length === 1 ? ids[ 0 ] : null;
	}
	return attributes?.id ?? null;
}

function TagsPanel( { attachmentId } ) {
	const { record, hasResolved, isResolving } = useEntityRecord(
		'postType',
		'attachment',
		attachmentId
	);
	const { records: terms, hasResolved: termsResolved } = useEntityRecords(
		'taxonomy',
		'media_tag',
		{ per_page: -1 }
	);
	const { editEntityRecord, saveEditedEntityRecord } =
		useDispatch( 'core' );

	const termsBySlug = useMemo( () => {
		const map = new Map();
		( terms ?? [] ).forEach( ( term ) => map.set( term.slug, term ) );
		return map;
	}, [ terms ] );

	const termsById = useMemo( () => {
		const map = new Map();
		( terms ?? [] ).forEach( ( term ) => map.set( term.id, term ) );
		return map;
	}, [ terms ] );

	if ( ! hasResolved || ! termsResolved ) {
		return <Spinner />;
	}
	if ( ! record ) {
		return null;
	}

	const currentTermIds = record.media_tag ?? [];
	const currentTokens = currentTermIds
		.map( ( id ) => termsById.get( id )?.name )
		.filter( Boolean );
	const suggestions = ( terms ?? [] ).map( ( t ) => t.name );

	const onChange = async ( tokens ) => {
		// Resolve token strings to term IDs, creating missing terms as needed.
		const resolvedIds = [];
		for ( const token of tokens ) {
			const slug = token.toLowerCase().trim();
			let term = termsBySlug.get( slug );
			if ( ! term ) {
				// Lookup by name (the user typed a name, not the slug).
				term = ( terms ?? [] ).find(
					( t ) => t.name.toLowerCase() === slug
				);
			}
			if ( term ) {
				resolvedIds.push( term.id );
			} else {
				// Create the term inline via core-data.
				try {
					const created = await wp.apiFetch( {
						path: '/wp/v2/media_tag',
						method: 'POST',
						data: { name: token },
					} );
					resolvedIds.push( created.id );
				} catch ( err ) {
					// eslint-disable-next-line no-console
					console.warn( 'Failed to create media_tag', token, err );
				}
			}
		}

		editEntityRecord( 'postType', 'attachment', attachmentId, {
			media_tag: resolvedIds,
		} );
		await saveEditedEntityRecord(
			'postType',
			'attachment',
			attachmentId
		);
	};

	return (
		<FormTokenField
			label={ __( 'Tags', 'media-library-enhance' ) }
			value={ currentTokens }
			suggestions={ suggestions }
			onChange={ onChange }
			__experimentalExpandOnFocus
		/>
	);
}

const withTagsPanel = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		if ( ! TARGET_BLOCKS.has( props.name ) ) {
			return <BlockEdit { ...props } />;
		}

		const attachmentId = getAttachmentId( props.name, props.attributes );

		return (
			<Fragment>
				<BlockEdit { ...props } />
				<InspectorControls>
					<PanelBody
						title={ __( 'Tags', 'media-library-enhance' ) }
						initialOpen={ false }
					>
						{ attachmentId ? (
							<TagsPanel attachmentId={ attachmentId } />
						) : (
							<p>
								{ __(
									'Upload an image to add tags.',
									'media-library-enhance'
								) }
							</p>
						) }
					</PanelBody>
				</InspectorControls>
			</Fragment>
		);
	};
}, 'withTagsPanel' );

addFilter( 'editor.BlockEdit', 'mle/tags-panel', withTagsPanel );
