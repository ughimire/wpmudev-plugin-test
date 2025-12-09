import { createRoot, render, StrictMode, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, CheckboxControl, Notice, Spinner, TextControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import './scss/style.scss';

const domElement = document.getElementById( window.wpmudevPostsMaint.dom_element_id );

// Configure apiFetch to use plugin nonce and REST root.
if ( window.wpmudevPostsMaint?.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( window.wpmudevPostsMaint.nonce ) );
}

if ( window.wpmudevPostsMaint?.restUrl ) {
	apiFetch.use( apiFetch.createRootURLMiddleware( window.wpmudevPostsMaint.restUrl ) );
}

const formatDate = (value) => {
	if ( ! value ) {
		return __( 'Never', 'wpmudev-plugin-test' );
	}
	const date = new Date( value.replace( ' ', 'T' ) );
	if ( Number.isNaN( date.getTime() ) ) {
		return value;
	}
	return date.toLocaleString();
};

const WPMUDEV_PostsMaintenance = () => {
	const [availableTypes, setAvailableTypes] = useState( window.wpmudevPostsMaint.availableTypes || [] );
	const [selectedTypes, setSelectedTypes] = useState( window.wpmudevPostsMaint.savedPostTypes || [] );
	const [lastScan, setLastScan] = useState( window.wpmudevPostsMaint.lastScan || '' );
	const [batchSize, setBatchSize] = useState( window.wpmudevPostsMaint.defaultBatch || 50 );
	const [isLoading, setIsLoading] = useState( false );
	const [isScanning, setIsScanning] = useState( false );
	const [progress, setProgress] = useState( { processed: 0, total: 0, percentage: 0 } );
	const [notice, setNotice] = useState( { message: '', type: 'info' } );

	const showNotice = ( message, type = 'info' ) => {
		setNotice( { message, type } );
	};

	const clearNotice = () => setNotice( { message: '', type: 'info' } );

	const isTypeSelected = ( slug ) => selectedTypes.includes( slug );

	const toggleType = ( slug ) => {
		if ( isScanning ) return;
		if ( isTypeSelected( slug ) ) {
			setSelectedTypes( selectedTypes.filter( ( item ) => item !== slug ) );
		} else {
			setSelectedTypes( [ ...selectedTypes, slug ] );
		}
	};

	const loadStatus = async () => {
		setIsLoading( true );
		clearNotice();
		try {
			const response = await apiFetch( {
				path: `/${ window.wpmudevPostsMaint.restEndpointStatus }`,
				method: 'GET',
			} );
			if ( response.success ) {
				setAvailableTypes( response.available_types || [] );
				setSelectedTypes( response.saved_posttypes || [] );
				setLastScan( response.last_scan || '' );
				if ( response.default_batch ) {
					setBatchSize( response.default_batch );
				}
			} else {
				showNotice( response.message || __( 'Failed to load status.', 'wpmudev-plugin-test' ), 'error' );
			}
		} catch ( error ) {
			showNotice( error.message || __( 'Failed to load status.', 'wpmudev-plugin-test' ), 'error' );
		} finally {
			setIsLoading( false );
		}
	};

	useEffect( () => {
		loadStatus();
	}, [] );

	const runScanBatch = async ( offset = 0, processedTotal = 0 ) => {
		try {
			const response = await apiFetch( {
				path: `/${ window.wpmudevPostsMaint.restEndpointScan }`,
				method: 'POST',
				data: {
					post_types: selectedTypes,
					offset,
					batch_size: batchSize,
				},
			} );

			if ( ! response.success ) {
				throw new Error( response.message || __( 'Scan failed.', 'wpmudev-plugin-test' ) );
			}

			const processed = processedTotal + ( response.processed || 0 );
			const total = response.total || 0;
			const percentage = total > 0 ? Math.round( ( processed / total ) * 100 ) : 0;

			setProgress( { processed, total, percentage } );

			if ( response.completed ) {
				setIsScanning( false );
				setLastScan( response.last_scan || '' );
				showNotice( __( 'Scan completed successfully.', 'wpmudev-plugin-test' ), 'success' );
				return;
			}

			// Continue with next batch.
			await runScanBatch( response.next_offset || offset + batchSize, processed );
		} catch ( error ) {
			setIsScanning( false );
			showNotice( error.message || __( 'An error occurred during scanning.', 'wpmudev-plugin-test' ), 'error' );
		}
	};

	const startScan = async () => {
		clearNotice();
		if ( selectedTypes.length === 0 ) {
			showNotice( __( 'Please select at least one post type.', 'wpmudev-plugin-test' ), 'error' );
			return;
		}
		setIsScanning( true );
		setProgress( { processed: 0, total: 0, percentage: 0 } );
		await runScanBatch( 0, 0 );
	};

	const selectedLabels = useMemo( () => {
		const map = availableTypes.reduce( ( acc, item ) => {
			acc[ item.slug ] = item.label;
			return acc;
		}, {} );
		return selectedTypes.map( ( slug ) => map[ slug ] || slug ).join( ', ' );
	}, [ availableTypes, selectedTypes ] );

	return (
		<>
			<div className="sui-header">
				<h1 className="sui-header-title">
					{ __( 'Posts Maintenance', 'wpmudev-plugin-test' ) }
				</h1>
				<p className="sui-description">
					{ __( 'Scan public posts/pages and update the last scan timestamp.', 'wpmudev-plugin-test' ) }
				</p>
			</div>

			{ notice.message && (
				<Notice
					status={ notice.type }
					isDismissible
					onRemove={ () => clearNotice() }
				>
					{ notice.message }
				</Notice>
			) }

			<div className="sui-box">
				<div className="sui-box-header">
					<h2 className="sui-box-title">{ __( 'Configure Scan', 'wpmudev-plugin-test' ) }</h2>
					<div className="sui-actions-right">
						<Button
							variant="secondary"
							onClick={ loadStatus }
							disabled={ isScanning || isLoading }
						>
							{ isLoading ? <Spinner /> : __( 'Refresh', 'wpmudev-plugin-test' ) }
						</Button>
					</div>
				</div>
				<div className="sui-box-body">
					<div className="sui-box-settings-row">
						<p><strong>{ __( 'Post Types to Scan', 'wpmudev-plugin-test' ) }</strong></p>
						<div className="post-types-grid">
							{ availableTypes.map( ( type ) => (
								<CheckboxControl
									key={ type.slug }
									label={ `${ type.label } (${ type.slug })` }
									checked={ isTypeSelected( type.slug ) }
									onChange={ () => toggleType( type.slug ) }
									disabled={ isScanning }
								/>
							) ) }
						</div>
					</div>

					<div className="sui-box-settings-row">
						<TextControl
							label={ __( 'Batch size', 'wpmudev-plugin-test' ) }
							help={ __( 'Number of posts to process per batch (max 200).', 'wpmudev-plugin-test' ) }
							type="number"
							value={ batchSize }
							onChange={ ( val ) => setBatchSize( Math.min( 200, Math.max( 1, parseInt( val || 50, 10 ) ) ) ) }
							disabled={ isScanning }
						/>
					</div>

					<div className="sui-box-settings-row">
						<p>
							<strong>{ __( 'Last Scan:', 'wpmudev-plugin-test' ) }</strong>
							{ ' ' }
							{ formatDate( lastScan ) }
						</p>
						<p>
							<strong>{ __( 'Selected:', 'wpmudev-plugin-test' ) }</strong>
							{ ' ' }
							{ selectedLabels || __( 'None', 'wpmudev-plugin-test' ) }
						</p>
					</div>
				</div>
				<div className="sui-box-footer">
					<div className="sui-actions-right">
						<Button
							variant="primary"
							onClick={ startScan }
							disabled={ isScanning || isLoading }
						>
							{ isScanning ? <Spinner /> : __( 'Scan Posts', 'wpmudev-plugin-test' ) }
						</Button>
					</div>
				</div>
			</div>

			<div className="sui-box">
				<div className="sui-box-header">
					<h2 className="sui-box-title">{ __( 'Progress', 'wpmudev-plugin-test' ) }</h2>
				</div>
				<div className="sui-box-body">
					<div className="sui-progress">
						<div className="sui-progress-bar">
							<span className="sui-progress-bar-value" style={ { width: `${ progress.percentage }%` } }></span>
						</div>
						<span className="sui-progress-text">{ progress.percentage }%</span>
					</div>
					<p className="sui-description">
						{ sprintf(
							__( 'Processed %1$s of %2$s posts.', 'wpmudev-plugin-test' ),
							progress.processed,
							progress.total || __( 'unknown', 'wpmudev-plugin-test' )
						) }
					</p>
				</div>
			</div>
		</>
	);
};

if ( createRoot ) {
	createRoot( domElement ).render(
		<StrictMode>
			<WPMUDEV_PostsMaintenance />
		</StrictMode>
	);
} else {
	render(
		<StrictMode>
			<WPMUDEV_PostsMaintenance />
		</StrictMode>,
		domElement
	);
}


