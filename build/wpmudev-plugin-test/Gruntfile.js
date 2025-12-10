module.exports = function (grunt) {
	// Fix for Node.js v24+ compatibility issues
	// I ran into this when upgrading Node - some older Grunt plugins expect util.isError
	// which was removed in newer Node versions. This polyfill restores it.
	if (typeof require('util').types === 'object' && !require('util').isError) {
		const util = require('util');
		if (!util.isError) {
			util.isError = function(err) {
				return err instanceof Error;
			};
		}
	}
	
	// Load tasks manually instead of using load-grunt-tasks
	// Had to do this because of Node.js compatibility issues
	
	// Load grunt tasks manually
	grunt.loadNpmTasks('grunt-contrib-clean');
	grunt.loadNpmTasks('grunt-contrib-copy');
	grunt.loadNpmTasks('grunt-contrib-compress');
	grunt.loadNpmTasks('grunt-checktextdomain');

	const copyFiles = [
		'app/**',
		'core/**',
		'languages/**',
		'uninstall.php',
		'wpmudev-plugin-test.php',
		'vendor/**',
		'!node_modules/**',
		'!**/*.map',
		'!**/node_modules/**',
		'!**/vendor/**/tests/**',
		'!**/vendor/**/test/**',
		'QUESTIONS.md',
		'README.md',
		'composer.json',
		'package.json',
		'Gruntfile.js',
		'gulpfile.js',
		'webpack.config.js',
		'phpcs.ruleset.xml',
		'phpunit.xml.dist',
		'src/**',
		'tests/**',
		'assets/**',
	]

    const excludeCopyFilesPro = copyFiles
		.slice(0)
		.concat([
			'changelog.txt',
		])

	// Read changelog if it exists, otherwise use empty string
	const changelog = grunt.file.exists('.changelog') ? grunt.file.read('.changelog') : ''

	grunt.initConfig({
		pkg: grunt.file.readJSON('package.json'),

		// Clean temp folders and release copies.
		clean: {
			temp: {
				src: ['**/*.tmp', '**/.afpDeleted*', '**/.DS_Store'],
				dot: true,
				filter: 'isFile',
			},
			assets: ['assets/css/**', 'assets/js/**'],
			folder_v2: ['build/**'],
		},

		checktextdomain: {
			options: {
				text_domain: 'wpmudev-plugin-test',
				keywords: [
					'__:1,2d',
					'_e:1,2d',
					'_x:1,2c,3d',
					'esc_html__:1,2d',
					'esc_html_e:1,2d',
					'esc_html_x:1,2c,3d',
					'esc_attr__:1,2d',
					'esc_attr_e:1,2d',
					'esc_attr_x:1,2c,3d',
					'_ex:1,2c,3d',
					'_n:1,2,4d',
					'_nx:1,2,4c,5d',
					'_n_noop:1,2,3d',
					'_nx_noop:1,2,3c,4d',
				],
			},
			files: {
				src: [
					'app/templates/**/*.php',
					'core/**/*.php',
					'!core/external/**', // Exclude external libs.
					'google-analytics-async.php',
				],
				expand: true,
			},
		},

		copy: {
			pro: {
				src: excludeCopyFilesPro,
				dest: 'build/<%= pkg.name %>/',
			},
		},

		compress: {
			pro: {
				options: {
					mode: 'zip',
					archive: './build/<%= pkg.name %>-<%= pkg.version %>.zip',
				},
				expand: true,
				cwd: 'build/<%= pkg.name %>/',
				src: ['**/*'],
				dest: '<%= pkg.name %>/',
			},
		},

	})

	grunt.loadNpmTasks('grunt-search')

	grunt.registerTask('version-compare', ['search'])
	grunt.registerTask('finish', function () {
		const json = grunt.file.readJSON('package.json')
		const file = './build/' + json.name + '-' + json.version + '.zip'
		grunt.log.writeln('Process finished.')

		grunt.log.writeln('----------')
	})

	grunt.registerTask('build', [
		'checktextdomain',
		'cleanupVendor',
		'copy:pro',
		'compress:pro',
	])

	grunt.registerTask('cleanupVendor', function() {
		const done = this.async();
		const { exec } = require('child_process');
		const fs = require('fs');
		const path = require('path');
		
		// This is the tricky part - we need to identify which vendor packages are dev-only
		// and can be safely removed from the production build. I'm parsing both composer.json
		// and composer.lock to get the complete dependency tree.
		let devPackages = new Set();
		
		try {
			// Get direct dev dependencies from composer.json
			const composerJson = JSON.parse(fs.readFileSync('composer.json', 'utf8'));
			const devDeps = composerJson['require-dev'] || {};
			Object.keys(devDeps).forEach(pkg => devPackages.add(pkg));
			
			// Get all dev-only packages from composer.lock (including transitive dependencies)
			if (fs.existsSync('composer.lock')) {
				const composerLock = JSON.parse(fs.readFileSync('composer.lock', 'utf8'));
				
				// Get production packages from require (not require-dev)
				const prodPackages = new Set();
				if (composerJson.require) {
					Object.keys(composerJson.require).forEach(pkg => prodPackages.add(pkg));
				}
				
				// Get all production packages from lock file
				if (composerLock.packages) {
					composerLock.packages.forEach(pkg => {
						if (pkg.name) prodPackages.add(pkg.name);
					});
				}
				
				// Get dev-only packages from packages-dev array
				if (composerLock['packages-dev']) {
					composerLock['packages-dev'].forEach(pkg => {
						if (pkg.name && !prodPackages.has(pkg.name)) {
							devPackages.add(pkg.name);
						}
					});
				}
				
				// Find transitive dependencies: packages in "packages" that are only required by dev packages
				if (composerLock.packages) {
					const allPackages = [
						...(composerLock.packages || []),
						...(composerLock['packages-dev'] || [])
					];
					
					composerLock.packages.forEach(pkg => {
						if (pkg.name && !prodPackages.has(pkg.name)) {
							// Check if this package is required by any dev package
							const isDevOnly = allPackages.some(otherPkg => {
								if (!otherPkg.require || !otherPkg.name) return false;
								return Object.keys(otherPkg.require).includes(pkg.name) &&
									devPackages.has(otherPkg.name);
							});
							
							if (isDevOnly) {
								devPackages.add(pkg.name);
							}
						}
					});
				}
			}
		} catch (error) {
			grunt.log.warn('Error reading composer files: ' + error.message);
		}
		
		// Extract vendor names from package names (e.g., "phpunit/phpunit" -> "phpunit")
		const devVendors = new Set();
		devPackages.forEach(pkg => {
			const parts = pkg.split('/');
			if (parts.length === 2) {
				devVendors.add(parts[0]);
			}
		});
		
		const vendorArray = Array.from(devVendors);
		
		if (vendorArray.length === 0) {
			grunt.log.ok('No dev dependencies to remove');
			done();
			return;
		}
		
		let completed = 0;
		let removed = 0;
		
		// ONLY remove dev dependency vendor directories, nothing else
		vendorArray.forEach((vendor) => {
			const vendorPath = path.join('vendor', vendor);
			if (fs.existsSync(vendorPath)) {
				const command = process.platform === 'win32' 
					? `rmdir /s /q "${vendorPath}"`
					: `rm -rf "${vendorPath}"`;
				
				exec(command, (error) => {
					completed++;
					if (!error) {
						removed++;
						grunt.log.ok(`Removed vendor/${vendor}`);
					}
					
					if (completed === vendorArray.length) {
						grunt.log.ok(`Vendor cleanup completed: removed ${removed} of ${vendorArray.length} dev dependency directories`);
						done();
					}
				});
			} else {
				completed++;
				if (completed === vendorArray.length) {
					grunt.log.ok(`Vendor cleanup completed: removed ${removed} of ${vendorArray.length} dev dependency directories`);
					done();
				}
			}
		});
	});

	grunt.registerTask('preBuildClean', [
		'clean:temp',
		'clean:assets',
		'clean:folder_v2',
		'cleanupVendor',
	])
}
