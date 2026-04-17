/**
 * Duplicate-on-upload editor notice.
 *
 * Watches the block editor for newly inserted image-bearing blocks and
 * checks each new attachment ID against the duplicate index. When a
 * match is found, dispatches a core/notices warning Notice with three
 * actions: use the existing image, keep both, or compare side-by-side.
 *
 * Watching the canonical block list (subscribe + diff) handles uploads,
 * paste-from-URL, and drag-drop uniformly. Gutenberg has no documented
 * "block inserted" event that's stable across versions.
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select, subscribe } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { createRoot } from '@wordpress/element';
import { Modal, Button } from '@wordpress/components';

const TARGET_BLOCKS = new Set( [
	'core/image',
	'core/cover',
	'core/media-text',
	'core/gallery',
] );

const NOTICE_PREFIX = 'mle-duplicate-';

// IDs we've already checked this session — no point re-querying every render.
const checked = new Set();
// IDs queued for the next batch tick.
let pending = new Set();
let flushScheduled = false;

/**
 * Walk the block tree and yield { clientId, blockName, attachmentId }
 * for every image-bearing block with an attachment ID.
 */
function* walkImageBlocks( blocks ) {
	for ( const block of blocks ) {
		if ( TARGET_BLOCKS.has( block.name ) ) {
			const ids =
				block.name === 'core/gallery'
					? block.attributes?.ids ?? []
					: block.attributes?.id
						? [ block.attributes.id ]
						: [];
			for ( const id of ids ) {
				yield {
					clientId: block.clientId,
					blockName: block.name,
					attachmentId: id,
				};
			}
		}
		if ( block.innerBlocks?.length ) {
			yield* walkImageBlocks( block.innerBlocks );
		}
	}
}

function scheduleFlush() {
	if ( flushScheduled ) {
		return;
	}
	flushScheduled = true;
	// 100ms debounce — collapses bulk drag-drop uploads into one batch.
	setTimeout( flushPending, 100 );
}

async function flushPending() {
	flushScheduled = false;
	const ids = Array.from( pending );
	pending = new Set();
	if ( ! ids.length ) {
		return;
	}

	// Look up the editor's current view of each block so the Notice
	// actions can target the right clientId.
	const blocks = select( 'core/block-editor' ).getBlocks();
	const blockByAttachmentId = new Map();
	for ( const entry of walkImageBlocks( blocks ) ) {
		if ( ! blockByAttachmentId.has( entry.attachmentId ) ) {
			blockByAttachmentId.set( entry.attachmentId, entry );
		}
	}

	// Fire requests in parallel; one notice per duplicate found.
	const responses = await Promise.all(
		ids.map( ( id ) =>
			apiFetch( {
				path: `/mle/v1/duplicates/check-on-upload?attachment_id=${ id }`,
			} ).catch( () => null )
		)
	);

	for ( let i = 0; i < responses.length; i++ ) {
		const result = responses[ i ];
		const id = ids[ i ];
		if ( ! result ) {
			continue;
		}
		const block = blockByAttachmentId.get( id );
		if ( ! block ) {
			continue;
		}
		if ( result.exact?.length ) {
			showNotice( {
				attachmentId: id,
				clientId: block.clientId,
				blockName: block.name,
				match: result.exact[ 0 ],
				kind: 'exact',
			} );
		} else if ( result.similar?.length ) {
			showNotice( {
				attachmentId: id,
				clientId: block.clientId,
				blockName: block.name,
				match: result.similar[ 0 ],
				kind: 'similar',
			} );
		}
	}
}

function showNotice( { attachmentId, clientId, blockName, match, kind } ) {
	const noticeId = `${ NOTICE_PREFIX }${ attachmentId }`;

	const message =
		kind === 'exact'
			? sprintf(
					/* translators: %s: existing attachment title */
					__(
						'Possible duplicate upload — matches "%s" already in the library.',
						'media-library-enhance'
					),
					match.title || `#${ match.id }`
				)
			: sprintf(
					/* translators: %s: existing attachment title */
					__(
						'Similar image found — review "%s" before publishing.',
						'media-library-enhance'
					),
					match.title || `#${ match.id }`
				);

	const actions = [
		{
			label: __( 'Compare', 'media-library-enhance' ),
			onClick: () =>
				openCompareModal( {
					newId: attachmentId,
					existing: match,
				} ),
		},
		{
			label: __( 'Keep both', 'media-library-enhance' ),
			onClick: () =>
				dispatch( 'core/notices' ).removeNotice( noticeId ),
		},
	];

	if ( kind === 'exact' ) {
		actions.unshift( {
			label: __( 'Use the existing one', 'media-library-enhance' ),
			onClick: () =>
				useExistingInstead( {
					newId: attachmentId,
					clientId,
					blockName,
					existing: match,
					noticeId,
				} ),
		} );
	}

	dispatch( 'core/notices' ).createNotice( 'warning', message, {
		id: noticeId,
		isDismissible: true,
		actions,
	} );
}

async function useExistingInstead( {
	newId,
	clientId,
	blockName,
	existing,
	noticeId,
} ) {
	// Swap the block's attachment reference to the existing image.
	const newAttrs =
		blockName === 'core/gallery'
			? { ids: [ existing.id ] }
			: { id: existing.id, url: existing.url };

	dispatch( 'core/block-editor' ).updateBlockAttributes(
		clientId,
		newAttrs
	);

	// Remove the just-uploaded duplicate.
	try {
		await apiFetch( {
			path: `/wp/v2/media/${ newId }?force=true`,
			method: 'DELETE',
		} );
	} catch ( err ) {
		// eslint-disable-next-line no-console
		console.warn( 'Failed to delete duplicate upload', newId, err );
	}

	dispatch( 'core/notices' ).removeNotice( noticeId );
	checked.delete( newId );
}

/**
 * Mount a side-by-side compare modal as a small React island.
 */
function openCompareModal( { newId, existing } ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );

	const close = () => {
		root.unmount();
		container.remove();
	};

	const newUrl = wp.media
		? wp.media.attachment( newId ).get( 'url' )
		: '';

	root.render(
		<Modal
			title={ __( 'Compare images', 'media-library-enhance' ) }
			onRequestClose={ close }
			style={ { maxWidth: 720 } }
		>
			<div
				style={ {
					display: 'grid',
					gridTemplateColumns: '1fr 1fr',
					gap: 16,
				} }
			>
				<figure>
					<figcaption>
						<strong>
							{ __(
								'Just uploaded',
								'media-library-enhance'
							) }
						</strong>
						<br />#{ newId }
					</figcaption>
					{ newUrl && (
						<img
							src={ newUrl }
							alt=""
							style={ { maxWidth: '100%' } }
						/>
					) }
				</figure>
				<figure>
					<figcaption>
						<strong>
							{ __( 'Existing', 'media-library-enhance' ) }
						</strong>
						<br />
						{ existing.title } (#{ existing.id })
					</figcaption>
					<img
						src={ existing.url }
						alt={ existing.title }
						style={ { maxWidth: '100%' } }
					/>
				</figure>
			</div>
			<div style={ { marginTop: 16, textAlign: 'right' } }>
				<Button variant="primary" onClick={ close }>
					{ __( 'Close', 'media-library-enhance' ) }
				</Button>
			</div>
		</Modal>
	);
}

// Subscribe once — the callback runs on every store change. Cheap because
// we only diff a Set membership.
subscribe( () => {
	const editor = select( 'core/block-editor' );
	if ( ! editor ) {
		return;
	}
	const blocks = editor.getBlocks();
	let foundNew = false;
	for ( const { attachmentId } of walkImageBlocks( blocks ) ) {
		if ( ! checked.has( attachmentId ) ) {
			checked.add( attachmentId );
			pending.add( attachmentId );
			foundNew = true;
		}
	}
	if ( foundNew ) {
		scheduleFlush();
	}
}, 'core/block-editor' );

