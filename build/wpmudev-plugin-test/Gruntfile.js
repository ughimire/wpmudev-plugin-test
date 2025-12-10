module.exports = function (grunt) {
	// Polyfill for Node.js v24+ compatibility
	// Some grunt plugins use deprecated nodeUtil.isError which was removed in Node.js v20+
	if (typeof require('util').types === 'object' && !require('util').isError) {
		const util = require('util');
		if (!util.isError) {
			util.isError = function(err) {
				return err instanceof Error;
			};
		}
	}
	
	// Manually load grunt tasks to avoid compatibility issues with newer Node.js
	// require('load-grunt-tasks')(grunt) // Commented out due to Node.js v24+ compatibility
	
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
		'!vendor/**',
		'!node_modules/**',
		'!**/*.map',
		'!**/node_modules/**',
		'!**/vendor/**',
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
		'copy:pro',
		'compress:pro',
	])

	grunt.registerTask('preBuildClean', [
		'clean:temp',
		'clean:assets',
		'clean:folder_v2',
	])
}
