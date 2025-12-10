import { createRoot, render, StrictMode, useState, useEffect, createInterpolateElement } from '@wordpress/element';
import { Button, TextControl, Spinner, Notice } from '@wordpress/components';
import { __, _x, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import "./scss/style.scss"

const domElement = document.getElementById( window.wpmudevDriveTest.dom_element_id );

const WPMUDEV_DriveTest = () => {
    const [isAuthenticated, setIsAuthenticated] = useState(window.wpmudevDriveTest.authStatus || false);
    const [hasCredentials, setHasCredentials] = useState(window.wpmudevDriveTest.hasCredentials || false);
    const [showCredentials, setShowCredentials] = useState(!window.wpmudevDriveTest.hasCredentials);
    const [isLoading, setIsLoading] = useState(false);
    const [files, setFiles] = useState([]);
    const [uploadFile, setUploadFile] = useState(null);
    const [folderName, setFolderName] = useState('');
    const [notice, setNotice] = useState({ message: '', type: '' });
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

        setIsLoading(true);
        
        // Create FormData for file upload.
        const formData = new FormData();
        formData.append('file', uploadFile);

        try {
            const response = await fetch(
                `${window.wpmudevDriveTest.restUrl || window.location.origin + '/wp-json'}/${window.wpmudevDriveTest.restEndpointUpload}`,
                {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-WP-Nonce': window.wpmudevDriveTest.nonce,
                    },
                }
            );

            const data = await response.json();

            if (data.success) {
                showNotice(
                    data.message || __('File uploaded successfully!', 'wpmudev-plugin-test'),
                    'success'
                );
                setUploadFile(null);
                // Reset file input.
                const fileInput = document.querySelector('.drive-file-input');
                if (fileInput) {
                    fileInput.value = '';
                }
                // Refresh file list.
                await loadFiles();
            } else {
                showNotice(
                    data.message || __('Failed to upload file.', 'wpmudev-plugin-test'),
                    'error'
                );
            }
        } catch (error) {
            showNotice(
                error.message || __('An error occurred while uploading the file.', 'wpmudev-plugin-test'),
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
                        </div>
                        <div className="sui-box-footer">
                            <div className="sui-actions-right">
                                <Button
                                    variant="primary"
                                    onClick={handleUpload}
                                    disabled={isLoading || !uploadFile}
                                >
                                    {isLoading ? <Spinner /> : __('Upload to Drive', 'wpmudev-plugin-test')}
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
                                >
                                    {isLoading ? <Spinner /> : __('Refresh Files', 'wpmudev-plugin-test')}
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