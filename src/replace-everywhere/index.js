/**
 * "Replace everywhere" toolbar button on core/image.
 *
 * Surfaces the existing /mle/v1/usage and /mle/v1/replace endpoints in
 * the editor. Click → modal showing how many posts use the image →
 * file picker → POST /replace/{id} → block updates with the new URL.
 *
 * Uses a hidden <input type="file"> rather than MediaUpload because
 * MediaUpload opens the legacy wp.media Backbone modal — exactly the
 * surface that won't survive Phase 3. We only need a one-shot file
 * picker, not a media browser.
 */
import { addFilter } from '@wordpress/hooks';
import { BlockControls } from '@wordpress/block-editor';
import {
	ToolbarGroup,
	ToolbarButton,
	Modal,
	Button,
	Spinner,
	Notice,
} from '@wordpress/components';
import { useState, useRef, Fragment, useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf, _n } from '@wordpress/i18n';
import { createHigherOrderComponent } from '@wordpress/compose';
import apiFetch from '@wordpress/api-fetch';

const TARGET_BLOCK = 'core/image';

function ReplaceEverywhereModal( { attachmentId, onClose, onReplaced } ) {
	const [ usage, setUsage ] = useState( null );
	const [ usageError, setUsageError ] = useState( null );
	const [ uploading, setUploading ] = useState( false );
	const [ uploadError, setUploadError ] = useState( null );
	const fileInputRef = useRef( null );

	useEffect( () => {
		let mounted = true;
		apiFetch( { path: `/mle/v1/usage/${ attachmentId }` } )
			.then( ( data ) => {
				if ( mounted ) {
					setUsage( data );
				}
			} )
			.catch( ( err ) => {
				if ( mounted ) {
					setUsageError( err );
				}
			} );

		return () => {
			mounted = false;
		};
	}, [ attachmentId ] );

	const onPickFile = () => {
		fileInputRef.current?.click();
	};

	const onFileChange = async ( event ) => {
		const file = event.target.files?.[ 0 ];
		if ( ! file ) {
			return;
		}

		setUploading( true );
		setUploadError( null );

		try {
			const formData = new FormData();
			formData.append( 'file', file );

			// apiFetch doesn't handle multipart cleanly — use fetch with the
			// REST nonce. wpApiSettings is enqueued whenever wp-api is loaded,
			// which the block editor always does.
			const nonce = window.wpApiSettings?.nonce ?? '';
			const root = window.wpApiSettings?.root ?? '/wp-json/';

			const response = await fetch(
				`${ root }mle/v1/replace/${ attachmentId }`,
				{
					method: 'POST',
					headers: { 'X-WP-Nonce': nonce },
					credentials: 'same-origin',
					body: formData,
				}
			);

			if ( ! response.ok ) {
				const body = await response.json().catch( () => ( {} ) );
				throw new Error(
					body.message || __( 'Replacement failed.', 'media-library-enhance' )
				);
			}

			const result = await response.json();
			onReplaced( result.attachment.url );
		} catch ( err ) {
			setUploadError( err.message );
			setUploading( false );
		}
	};

	if ( usageError ) {
		return (
			<Modal
				title={ __( 'Replace everywhere', 'media-library-enhance' ) }
				onRequestClose={ onClose }
			>
				<Notice status="error" isDismissible={ false }>
					{ __(
						'Could not load usage information.',
						'media-library-enhance'
					) }
				</Notice>
			</Modal>
		);
	}

	if ( usage === null ) {
		return (
			<Modal
				title={ __( 'Replace everywhere', 'media-library-enhance' ) }
				onRequestClose={ onClose }
			>
				<Spinner />
			</Modal>
		);
	}

	const count = usage.post_count;

	return (
		<Modal
			title={ __( 'Replace everywhere', 'media-library-enhance' ) }
			onRequestClose={ uploading ? undefined : onClose }
			style={ { maxWidth: 520 } }
		>
			<p>
				{ sprintf(
					/* translators: %d: number of posts */
					_n(
						'This image is used in %d post. Replacing it will update every reference.',
						'This image is used in %d posts. Replacing them all will update every reference.',
						count,
						'media-library-enhance'
					),
					count
				) }
			</p>
			{ usage.posts?.length > 0 && (
				<ul style={ { maxHeight: 200, overflow: 'auto' } }>
					{ usage.posts.map( ( post ) => (
						<li key={ post.id }>
							<a
								href={ post.edit_url }
								target="_blank"
								rel="noreferrer"
							>
								{ post.title || `#${ post.id }` }
							</a>
						</li>
					) ) }
				</ul>
			) }
			{ uploadError && (
				<Notice status="error" onRemove={ () => setUploadError( null ) }>
					{ uploadError }
				</Notice>
			) }
			<input
				type="file"
				ref={ fileInputRef }
				accept="image/*"
				style={ { display: 'none' } }
				onChange={ onFileChange }
			/>
			<div
				style={ {
					marginTop: 16,
					display: 'flex',
					justifyContent: 'flex-end',
					gap: 8,
				} }
			>
				<Button
					variant="tertiary"
					onClick={ onClose }
					disabled={ uploading }
				>
					{ __( 'Cancel', 'media-library-enhance' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ onPickFile }
					disabled={ uploading }
					isBusy={ uploading }
				>
					{ uploading
						? __( 'Replacing…', 'media-library-enhance' )
						: __(
								'Choose replacement file',
								'media-library-enhance'
							) }
				</Button>
			</div>
		</Modal>
	);
}

function ReplaceButton( { clientId, attachmentId } ) {
	const [ open, setOpen ] = useState( false );
	const { updateBlockAttributes } = useDispatch( 'core/block-editor' );

	const canReplace = useSelect(
		( s ) =>
			Boolean(
				s( 'core' ).canUser?.( 'update', 'media', attachmentId )
			),
		[ attachmentId ]
	);

	if ( ! canReplace ) {
		return null;
	}

	return (
		<Fragment>
			<ToolbarButton
				icon="image-rotate"
				label={ __(
					'Replace everywhere',
					'media-library-enhance'
				) }
				onClick={ () => setOpen( true ) }
			/>
			{ open && (
				<ReplaceEverywhereModal
					attachmentId={ attachmentId }
					onClose={ () => setOpen( false ) }
					onReplaced={ ( newUrl ) => {
						// Cache-bust by appending the timestamp — the file
						// path stays the same so the URL otherwise wouldn't
						// trigger a re-render.
						const bustedUrl =
							newUrl + ( newUrl.includes( '?' ) ? '&' : '?' ) +
							'mle_t=' +
							Date.now();
						updateBlockAttributes( clientId, { url: bustedUrl } );
						setOpen( false );
					} }
				/>
			) }
		</Fragment>
	);
}

const withReplaceButton = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		if ( props.name !== TARGET_BLOCK ) {
			return <BlockEdit { ...props } />;
		}
		const attachmentId = props.attributes?.id;
		if ( ! attachmentId ) {
			return <BlockEdit { ...props } />;
		}

		return (
			<Fragment>
				<BlockControls>
					<ToolbarGroup>
						<ReplaceButton
							clientId={ props.clientId }
							attachmentId={ attachmentId }
						/>
					</ToolbarGroup>
				</BlockControls>
				<BlockEdit { ...props } />
			</Fragment>
		);
	};
}, 'withReplaceButton' );

addFilter(
	'editor.BlockEdit',
	'mle/replace-everywhere',
	withReplaceButton
);
