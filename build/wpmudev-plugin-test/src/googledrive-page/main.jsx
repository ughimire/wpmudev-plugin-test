/**
 * Google Drive Test Page - React Component
 * 
 * This component handles the entire Google Drive integration interface.
 * I've structured it to be maintainable and follow WordPress React patterns.
 * 
 * @author Umesh Ghimire
 * @since 1.0.0
 */

import { createRoot, render, StrictMode, useState, useEffect, createInterpolateElement } from '@wordpress/element';
import { Button, TextControl, Spinner, Notice } from '@wordpress/components';
import { __, _x, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import "./scss/style.scss"

// Get the DOM element where we'll mount our React app
const domElement = document.getElementById( window.wpmudevDriveTest.dom_element_id );

const WPMUDEV_DriveTest = () => {
    // Authentication and credential state management
    // I'm using the initial values from the localized script to maintain state across page loads
    const [isAuthenticated, setIsAuthenticated] = useState(window.wpmudevDriveTest.authStatus || false);
    const [hasCredentials, setHasCredentials] = useState(window.wpmudevDriveTest.hasCredentials || false);
    const [showCredentials, setShowCredentials] = useState(!window.wpmudevDriveTest.hasCredentials);
    
    // UI state management
    const [isLoading, setIsLoading] = useState(false);
    const [files, setFiles] = useState([]);
    const [uploadFile, setUploadFile] = useState(null);
    const [folderName, setFolderName] = useState('');
    
    // Upload progress tracking - this gives users real-time feedback
    const [uploadProgress, setUploadProgress] = useState(0);
    const [isUploading, setIsUploading] = useState(false);
    const [uploadXhr, setUploadXhr] = useState(null); // Store XHR reference for cancellation
    
    // User feedback system - I prefer this pattern over multiple state variables
    const [notice, setNotice] = useState({ message: '', type: '' });
    
    // Form data for credentials - keeping it simple with a single object
    const [credentials, setCredentials] = useState({
        clientId: '',
        clientSecret: ''
    });

    useEffect(() => {
        // Load files when authenticated.
        if (isAuthenticated) {
            loadFiles();
        }
        
        // Check authentication status from URL params.
        const urlParams = new URLSearchParams(window.location.search);
        const authStatus = urlParams.get('auth');
        if (authStatus === 'success') {
            setIsAuthenticated(true);
            showNotice(__('Successfully authenticated with Google Drive!', 'wpmudev-plugin-test'), 'success');
            // Clean URL.
            window.history.replaceState({}, document.title, window.location.pathname);
        } else if (authStatus === 'error') {
            const errorMessage = urlParams.get('message') || __('Authentication failed.', 'wpmudev-plugin-test');
            showNotice(errorMessage, 'error');
            // Clean URL.
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }, [isAuthenticated]);

    const showNotice = (message, type = 'success') => {
        setNotice({ message, type });
        setTimeout(() => setNotice({ message: '', type: '' }), 5000);
    };

    const handleSaveCredentials = async () => {
        if (!credentials.clientId || !credentials.clientSecret) {
            showNotice(__('Please enter both Client ID and Client Secret.', 'wpmudev-plugin-test'), 'error');
            return;
        }

        setIsLoading(true);
        try {
            const response = await apiFetch({
                path: `/${window.wpmudevDriveTest.restEndpointSave}`,
                method: 'POST',
                data: {
                    client_id: credentials.clientId,
                    client_secret: credentials.clientSecret,
                },
            });

            if (response.success) {
                setHasCredentials(true);
                setShowCredentials(false);
                showNotice(response.message || __('Credentials saved successfully!', 'wpmudev-plugin-test'), 'success');
            } else {
                showNotice(response.message || __('Failed to save credentials.', 'wpmudev-plugin-test'), 'error');
            }
        } catch (error) {
            showNotice(
                error.message || __('An error occurred while saving credentials.', 'wpmudev-plugin-test'),
                'error'
            );
        } finally {
            setIsLoading(false);
        }
    };

    const handleAuth = async () => {
        setIsLoading(true);
        try {
            const response = await apiFetch({
                path: `/${window.wpmudevDriveTest.restEndpointAuth}`,
                method: 'POST',
            });

            if (response.success && response.auth_url) {
                // Redirect to Google OAuth page.
                window.location.href = response.auth_url;
            } else {
                showNotice(
                    response.message || __('Failed to start authentication.', 'wpmudev-plugin-test'),
                    'error'
                );
                setIsLoading(false);
            }
        } catch (error) {
            showNotice(
                error.message || __('An error occurred during authentication.', 'wpmudev-plugin-test'),
                'error'
            );
            setIsLoading(false);
        }
    };

    const loadFiles = async () => {
        setIsLoading(true);
        try {
            const response = await apiFetch({
                path: `/${window.wpmudevDriveTest.restEndpointFiles}`,
                method: 'GET',
            });

            if (response.success && Array.isArray(response.files)) {
                setFiles(response.files);
            } else {
                showNotice(
                    response.message || __('Failed to load files.', 'wpmudev-plugin-test'),
                    'error'
                );
            }
        } catch (error) {
            showNotice(
                error.message || __('An error occurred while loading files.', 'wpmudev-plugin-test'),
                'error'
            );
        } finally {
            setIsLoading(false);
        }
    };

    const handleUpload = async () => {
        if (!uploadFile) {
            showNotice(__('Please select a file to upload.', 'wpmudev-plugin-test'), 'error');
            return;
        }

        // Reset progress and start upload
        setUploadProgress(0);
        setIsUploading(true);
        
        // Using FormData for multipart upload - this is the standard way to handle file uploads
        // in modern browsers. The REST API expects this format.
        const formData = new FormData();
        formData.append('file', uploadFile);

        // Construct URL properly to avoid double slashes
        const restBase = (window.wpmudevDriveTest.restUrl || window.location.origin + '/wp-json').replace(/\/$/, '');
        const endpoint = window.wpmudevDriveTest.restEndpointUpload.replace(/^\//, '');
        const uploadUrl = `${restBase}/${endpoint}`;

        // Using XMLHttpRequest instead of fetch to get upload progress
        // This is the only way to track upload progress in browsers currently
        const xhr = new XMLHttpRequest();

        // Store XHR reference for potential cancellation
        setUploadXhr(xhr);

        return new Promise((resolve, reject) => {
            // Track upload progress
            xhr.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) {
                    const percentComplete = Math.round((event.loaded / event.total) * 100);
                    setUploadProgress(percentComplete);
                }
            });

            // Handle completion
            xhr.addEventListener('load', async () => {
                setUploadXhr(null); // Clear XHR reference
                setIsUploading(false);
                
                try {
                    const data = JSON.parse(xhr.responseText);

                    if (xhr.status === 200 && data.success) {
                        setUploadProgress(100);
                        showNotice(
                            data.message || __('File uploaded successfully!', 'wpmudev-plugin-test'),
                            'success'
                        );
                        setUploadFile(null);
                        
                        // Reset file input
                        const fileInput = document.querySelector('.drive-file-input');
                        if (fileInput) {
                            fileInput.value = '';
                        }
                        
                        // Reset progress after a short delay to show completion
                        setTimeout(() => setUploadProgress(0), 2000);
                        
                        // Refresh file list
                        await loadFiles();
                        resolve(data);
                    } else {
                        showNotice(
                            data.message || __('Failed to upload file.', 'wpmudev-plugin-test'),
                            'error'
                        );
                        setUploadProgress(0);
                        reject(new Error(data.message || 'Upload failed'));
                    }
                } catch (error) {
                    showNotice(
                        __('Invalid response from server.', 'wpmudev-plugin-test'),
                        'error'
                    );
                    setUploadProgress(0);
                    reject(error);
                }
            });

            // Handle errors
            xhr.addEventListener('error', () => {
                setUploadXhr(null);
                setIsUploading(false);
                setUploadProgress(0);
                showNotice(
                    __('Network error occurred during upload.', 'wpmudev-plugin-test'),
                    'error'
                );
                reject(new Error('Network error'));
            });

            // Handle abort
            xhr.addEventListener('abort', () => {
                setUploadXhr(null);
                setIsUploading(false);
                setUploadProgress(0);
                showNotice(
                    __('Upload was cancelled.', 'wpmudev-plugin-test'),
                    'info'
                );
                reject(new Error('Upload cancelled'));
            });

            // Start the upload
            xhr.open('POST', uploadUrl);
            xhr.setRequestHeader('X-WP-Nonce', window.wpmudevDriveTest.nonce);
            xhr.send(formData);
        });
    };

    // Cancel upload function - gives users control over long uploads
    const handleCancelUpload = () => {
        if (uploadXhr && isUploading) {
            uploadXhr.abort(); // This will trigger the 'abort' event listener
        }
    };

    const handleDisconnect = async () => {
        // Show confirmation before disconnecting - this is a destructive action
        if (!window.confirm(__('Are you sure you want to disconnect from Google Drive? You will need to re-authenticate to access your files again.', 'wpmudev-plugin-test'))) {
            return;
        }

        setIsLoading(true);
        try {
            const response = await apiFetch({
                path: `/${window.wpmudevDriveTest.restEndpointDisconnect}`,
                method: 'POST',
            });

            if (response.success) {
                // Reset all authentication-related state
                setIsAuthenticated(false);
                setFiles([]);
                setShowCredentials(false); // Keep credentials form hidden since we still have them saved
                showNotice(response.message || __('Successfully disconnected from Google Drive.', 'wpmudev-plugin-test'), 'success');
            } else {
                showNotice(
                    response.message || __('Failed to disconnect from Google Drive.', 'wpmudev-plugin-test'),
                    'error'
                );
            }
        } catch (error) {
            showNotice(
                error.message || __('An error occurred while disconnecting from Google Drive.', 'wpmudev-plugin-test'),
                'error'
            );
        } finally {
            setIsLoading(false);
        }
    };

    const handleDownload = async (fileId, fileName) => {
        setIsLoading(true);
        try {
            const response = await apiFetch({
                path: `/${window.wpmudevDriveTest.restEndpointDownload}?file_id=${encodeURIComponent(fileId)}`,
                method: 'GET',
            });

            if (response.success && response.content) {
                // Decode base64 content.
                const binaryString = atob(response.content);
                const bytes = new Uint8Array(binaryString.length);
                for (let i = 0; i < binaryString.length; i++) {
                    bytes[i] = binaryString.charCodeAt(i);
                }
                
                // Create blob and download.
                const blob = new Blob([bytes], { type: response.mimeType || 'application/octet-stream' });
                const url = window.URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = fileName || response.filename || 'download';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.URL.revokeObjectURL(url);
                
                showNotice(__('File downloaded successfully!', 'wpmudev-plugin-test'), 'success');
            } else {
                showNotice(
                    response.message || __('Failed to download file.', 'wpmudev-plugin-test'),
                    'error'
                );
            }
        } catch (error) {
            showNotice(
                error.message || __('An error occurred while downloading the file.', 'wpmudev-plugin-test'),
                'error'
            );
        } finally {
            setIsLoading(false);
        }
    };

    const handleCreateFolder = async () => {
        if (!folderName.trim()) {
            showNotice(__('Please enter a folder name.', 'wpmudev-plugin-test'), 'error');
            return;
        }

        setIsLoading(true);
        try {
            const response = await apiFetch({
                path: `/${window.wpmudevDriveTest.restEndpointCreate}`,
                method: 'POST',
                data: {
                    name: folderName.trim(),
                },
            });

            if (response.success) {
                showNotice(
                    response.message || __('Folder created successfully!', 'wpmudev-plugin-test'),
                    'success'
                );
                setFolderName('');
                // Refresh file list.
                await loadFiles();
            } else {
                showNotice(
                    response.message || __('Failed to create folder.', 'wpmudev-plugin-test'),
                    'error'
                );
            }
        } catch (error) {
            showNotice(
                error.message || __('An error occurred while creating the folder.', 'wpmudev-plugin-test'),
                'error'
            );
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <>
            <div className="sui-header">
                <h1 className="sui-header-title">
                    { __('Google Drive Test', 'wpmudev-plugin-test') }
                </h1>
                <p className="sui-description">
                    {__('Test Google Drive API integration for applicant assessment', 'wpmudev-plugin-test')}
                </p>
                {/* Add disconnect button in header when authenticated for better visibility */}
                {isAuthenticated && (
                    <div className="sui-actions-right" style={{ marginTop: '10px' }}>
                        <Button
                            variant="ghost"
                            onClick={handleDisconnect}
                            disabled={isLoading}
                            style={{ color: '#d63638' }}
                        >
                            {isLoading ? <Spinner /> : __('Disconnect from Google Drive', 'wpmudev-plugin-test')}
                        </Button>
                    </div>
                )}
            </div>

            {notice.message && (
                <Notice status={notice.type} isDismissible onRemove={() => setNotice({ message: '', type: '' })}>
                    {notice.message}
                </Notice>
            )}

            {showCredentials ? (
                <div className="sui-box">
                    <div className="sui-box-header">
                        <h2 className="sui-box-title">{ __('Set Google Drive Credentials', 'wpmudev-plugin-test')}</h2>
                    </div>
                    <div className="sui-box-body">
                        <div className="sui-box-settings-row">
                            <TextControl
                                help={ createInterpolateElement(
                                    __(
                                        'You can get Client ID from <a>Google Cloud Console</a>. Make sure to enable Google Drive API.',
                                        'wpmudev-plugin-test'
                                    ),
                                    {
                                        a: (
                                            <a
                                                href="https://console.cloud.google.com/apis/credentials"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            />
                                        )
                                    }
                                ) }
                                label={ __( 'Client ID', 'wpmudev-plugin-test' ) }
                                value={ credentials.clientId }
                                onChange={(value) => setCredentials({ ...credentials, clientId: value })}
                            />
                        </div>

                        <div className="sui-box-settings-row">
                            <TextControl
                                help={createInterpolateElement(
                                    __('You can get Client Secret from <a>Google Cloud Console</a>.', 'wpmudev-plugin-test'),
                                    {
                                        a: <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer" />,
                                    }
                                )}
                                label={ __('Client Secret', 'wpmudev-plugin-test') }
                                value={credentials.clientSecret}
                                onChange={(value) => setCredentials({...credentials, clientSecret: value})}
                                type="password"
                            />
                        </div>

                        <div className="sui-box-settings-row">
                            <span>
                                {
                                createInterpolateElement(
                                    __(
                                        'Please use this URL <em><url/></em> in your Google API’s <strong>Authorized redirect URIs</strong> field.',
                                        'wpmudev-plugin-test'
                                    ),
                                    {
                                        em: <em />,
                                        strong: <strong />,
                                        url: <span>{window.wpmudevDriveTest.redirectUri}</span>
                                    }
                                )
                            }
                            </span>
                        </div>

                        <div className="sui-box-settings-row">
                            <p><strong>{ __("Required scopes for Google Drive API:", 'wpmudev-plugin-test') }</strong></p>
                            <ul>
                                <li>https://www.googleapis.com/auth/drive.file</li>
                                <li>https://www.googleapis.com/auth/drive.readonly</li>
                            </ul>
                        </div>
                    </div>
                    <div className="sui-box-footer">
                        <div className="sui-actions-right">
                            <Button
                                variant="primary"
                                onClick={handleSaveCredentials}
                                disabled={isLoading}
                            >
                                {isLoading ? <Spinner /> : __('Save Credentials', 'wpmudev-plugin-test')}
                            </Button>
                        </div>
                    </div>
                </div>
            ) : !isAuthenticated ? (
                <div className="sui-box">
                    <div className="sui-box-header">
                        <h2 className="sui-box-title">{__("Authenticate with Google Drive", 'wpmudev-plugin-test') }</h2>
                    </div>
                    <div className="sui-box-body">
                        <div className="sui-box-settings-row">
                            <p>{__("Please authenticate with Google Drive to proceed with the test.", 'wpmudev-plugin-test') }</p>
                            <p><strong>{__("This test will require the following permissions:", 'wpmudev-plugin-test') }</strong></p>
                            <ul>
                                <li>{ __("View and manage Google Drive files", 'wpmudev-plugin-test') }</li>
                                <li>{ __("Upload new files to Drive", 'wpmudev-plugin-test') }</li>
                                <li>{ __("Create folders in Drive", 'wpmudev-plugin-test') }</li>
                            </ul>
                        </div>
                    </div>
                    <div className="sui-box-footer">
                        <div className="sui-actions-left">
                            <Button
                                variant="secondary"
                                onClick={() => setShowCredentials(true)}
                            >
                                {__('Change Credentials', 'wpmudev-plugin-test')}
                            </Button>
                        </div>
                        <div className="sui-actions-right">
                            <Button
                                variant="primary"
                                onClick={handleAuth}
                                disabled={isLoading}
                            >
                                {isLoading ? <Spinner /> : __('Authenticate with Google Drive', 'wpmudev-plugin-test')}
                            </Button>
                        </div>
                    </div>
                </div>
            ) : (
                <>
                    {/* File Upload Section */}
                    <div className="sui-box">
                        <div className="sui-box-header">
                            <h2 className="sui-box-title">{ __("Upload File to Drive", 'wpmudev-plugin-test') }</h2>
                        </div>
                        <div className="sui-box-body">
                            <div className="sui-box-settings-row">
                                <input
                                    type="file"
                                    onChange={(e) => setUploadFile(e.target.files[0])}
                                    className="drive-file-input"
                                    disabled={isUploading}
                                />
                                {uploadFile && (
                                    <p>
                                        {
                                            createInterpolateElement(
                                                sprintf(
                                                    __('<strong>Selected file:</strong> %s (%s KB)', 'wpmudev-plugin-test'),
                                                    uploadFile.name,
                                                    Math.round(uploadFile.size / 1024)
                                                ),
                                                {
                                                    strong: <strong/>,
                                                }
                                            )
                                        }
                                    </p>
                                )}
                            </div>
                            
                            {/* Upload Progress Bar - shows real-time upload progress */}
                            {(isUploading || uploadProgress > 0) && (
                                <div className="sui-box-settings-row">
                                    <div className="upload-progress-container">
                                        <div className="upload-progress-label">
                                            <strong>
                                                {isUploading 
                                                    ? __('Uploading...', 'wpmudev-plugin-test')
                                                    : uploadProgress === 100 
                                                        ? __('Upload Complete!', 'wpmudev-plugin-test')
                                                        : __('Upload Progress', 'wpmudev-plugin-test')
                                                }
                                            </strong>
                                            <span className="upload-progress-percent">{uploadProgress}%</span>
                                        </div>
                                        <div className="upload-progress-bar">
                                            <div 
                                                className={`upload-progress-fill ${uploadProgress === 100 ? 'complete' : ''}`}
                                                style={{ 
                                                    width: `${uploadProgress}%`,
                                                    backgroundColor: uploadProgress === 100 ? '#28a745' : '#007cba',
                                                    height: '8px',
                                                    borderRadius: '4px',
                                                    transition: 'width 0.3s ease, background-color 0.3s ease'
                                                }}
                                            />
                                        </div>
                                        {isUploading && (
                                            <div className="upload-progress-actions">
                                                <p className="upload-progress-text">
                                                    {sprintf(
                                                        __('Uploading %s... Please don\'t close this page.', 'wpmudev-plugin-test'),
                                                        uploadFile?.name || __('file', 'wpmudev-plugin-test')
                                                    )}
                                                </p>
                                                <Button
                                                    variant="link"
                                                    onClick={handleCancelUpload}
                                                    style={{ 
                                                        color: '#d63638', 
                                                        fontSize: '12px',
                                                        textDecoration: 'none',
                                                        padding: '0',
                                                        height: 'auto'
                                                    }}
                                                >
                                                    {__('Cancel Upload', 'wpmudev-plugin-test')}
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>
                        <div className="sui-box-footer">
                            <div className="sui-actions-right">
                                <Button
                                    variant="primary"
                                    onClick={handleUpload}
                                    disabled={isUploading || !uploadFile}
                                >
                                    {isUploading ? <Spinner /> : __('Upload to Drive', 'wpmudev-plugin-test')}
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* Create Folder Section */}
                    <div className="sui-box">
                        <div className="sui-box-header">
                            <h2 className="sui-box-title">{ __('Create New Folder', 'wpmudev-plugin-test') }</h2>
                        </div>
                        <div className="sui-box-body">
                            <div className="sui-box-settings-row">
                                <TextControl
                                    label={__("Folder Name", 'wpmudev-plugin-test')}
                                    value={folderName}
                                    onChange={setFolderName}
                                    placeholder={ __("Enter folder name", 'wpmudev-plugin-test') }
                                />
                            </div>
                        </div>
                        <div className="sui-box-footer">
                            <div className="sui-actions-right">
                                <Button
                                    variant="secondary"
                                    onClick={handleCreateFolder}
                                    disabled={isLoading || !folderName.trim()}
                                >
                                    {isLoading ? <Spinner /> : __('Create Folder', 'wpmudev-plugin-test')}
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* Files List Section */}
                    <div className="sui-box">
                        <div className="sui-box-header">
                            <h2 className="sui-box-title">{ __("Your Drive Files", 'wpmudev-plugin-test')}</h2>
                            <div className="sui-actions-right">
                                <Button
                                    variant="secondary"
                                    onClick={loadFiles}
                                    disabled={isLoading}
                                    style={{ marginRight: '8px' }}
                                >
                                    {isLoading ? <Spinner /> : __('Refresh Files', 'wpmudev-plugin-test')}
                                </Button>
                                <Button
                                    variant="ghost"
                                    onClick={handleDisconnect}
                                    disabled={isLoading}
                                    style={{ color: '#d63638', border: '1px solid #d63638' }}
                                >
                                    {isLoading ? <Spinner /> : __('Disconnect', 'wpmudev-plugin-test')}
                                </Button>
                            </div>
                        </div>
                        <div className="sui-box-body">
                            {isLoading ? (
                                <div className="drive-loading">
                                    <Spinner />
                                    <p>{ __('Loading files...', 'wpmudev-plugin-test') }</p>
                                </div>
                            ) : files.length > 0 ? (
                                <div className="drive-files-grid">
                                    {files.map((file) => {
                                        const isFolder = file.isFolder || file.mimeType === 'application/vnd.google-apps.folder';
                                        const fileSize = file.size ? (file.size < 1024 ? file.size + ' B' : file.size < 1024 * 1024 ? Math.round(file.size / 1024) + ' KB' : Math.round(file.size / (1024 * 1024) * 100) / 100 + ' MB') : '';
                                        const fileType = isFolder ? __('Folder', 'wpmudev-plugin-test') : __('File', 'wpmudev-plugin-test');
                                        
                                        return (
                                            <div key={file.id} className="drive-file-item">
                                                <div className="file-info">
                                                    <strong>{file.name}</strong>
                                                    <small>
                                                        {fileType}
                                                        {fileSize && ` • ${fileSize}`}
                                                        {file.modifiedTime && ` • ${new Date(file.modifiedTime).toLocaleDateString()}`}
                                                    </small>
                                                </div>
                                                <div className="file-actions">
                                                    {!isFolder && (
                                                        <Button
                                                            variant="secondary"
                                                            size="small"
                                                            onClick={() => handleDownload(file.id, file.name)}
                                                            disabled={isLoading}
                                                        >
                                                            {__("Download", 'wpmudev-plugin-test')}
                                                        </Button>
                                                    )}
                                                    {file.webViewLink && (
                                                        <Button
                                                            variant="link"
                                                            size="small"
                                                            href={file.webViewLink}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                        >
                                                            { __("View in Drive", 'wpmudev-plugin-test') }
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <div className="sui-box-settings-row">
                                    <p>{ __("No files found in your Drive. Upload a file or create a folder to get started.", 'wpmudev-plugin-test') }</p>
                                </div>
                            )}
                        </div>
                    </div>
                </>
            )}
        </>
    );
}

if ( createRoot ) {
    createRoot( domElement ).render(<StrictMode><WPMUDEV_DriveTest/></StrictMode>);
} else {
    render( <StrictMode><WPMUDEV_DriveTest/></StrictMode>, domElement );
}