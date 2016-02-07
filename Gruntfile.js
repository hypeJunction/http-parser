module.exports = function (grunt) {

	var package = grunt.file.readJSON('package.json');

	// Project configuration.
	grunt.initConfig({
		pkg: package,
		// Bump version numbers
		version: {
			pkg: {
				src: ['package.json', 'composer.json'],
			},
			manifest: {
				options: {
					pkg: grunt.file.readJSON('package.json')
				}
			}
		},
		clean: {
			release: {
				src: ['build/', 'releases/', 'vendor/', 'coverage/', 'composer.lock']
			}
		},
		copy: {
			release: {
				src: [
					'**',
					'!**/.git*',
					'!releases/**',
					'!build/**',
					'!node_modules/**',
					'!package.json',
					'!tests/**',
					'!composer.json',
					'!composer.lock',
					'!package.json',
					'!phpunit.xml',
					'!Gruntfile.js'
				],
				dest: 'build/',
				expand: true
			},
		},
		compress: {
			release: {
				options: {
					archive: 'releases/<%= pkg.name %>-<%= pkg.version %>.zip'
				},
				cwd: 'build/',
				src: ['**/*'],
				dest: '<%= pkg.name %>/',
				expand: true
			}
		},
		gitcommit: {
			release: {
				options: {
					message: 'chore(build): release <%= pkg.version %>',
				},
				files: {
					src: ["composer.json", "package.json", "CHANGELOG.md"],
				}
			},
		},
		gitfetch: {
			release: {
				all: true
			}
		},
		gittag: {
			release: {
				options: {
					tag: '<%= pkg.version %>',
					message: 'Release <%= pkg.version %>'
				}
			}
		},
		gitpush: {
			release: {
			},
			release_tags: {
				options: {
					tags: true
				}
			}
		},
		gh_release: {
			options: {
				token: process.env.GITHUB_TOKEN,
				repo: package.repository.repo,
				owner: package.repository.owner
			},
			release: {
				tag_name: '<%= pkg.version %>',
				name: 'Release <%= pkg.version %>',
				draft: false,
				prerelease: false,
				asset: {
					name: '<%= pkg.name %>-<%= pkg.version %>.zip',
					file: 'releases/<%= pkg.name %>-<%= pkg.version %>.zip',
					'Content-Type': 'application/zip'
				}
			}
		},
		conventionalChangelog: {
			options: {
				changelogOpts: {
					// conventional-changelog options go here
					preset: 'angular'
				}
			},
			release: {
				src: 'CHANGELOG.md'
			}

		}
	});
	// Load all grunt plugins here
	grunt.loadNpmTasks('grunt-version');
	grunt.loadNpmTasks('grunt-contrib-copy');
	grunt.loadNpmTasks('grunt-contrib-clean');
	grunt.loadNpmTasks('grunt-contrib-compress');
	grunt.loadNpmTasks('grunt-composer');
	grunt.loadNpmTasks('grunt-conventional-changelog');
	grunt.loadNpmTasks('grunt-git');
	grunt.loadNpmTasks('grunt-gh-release');

	grunt.registerTask('readpkg', 'Read in the package.json file', function () {
		grunt.config.set('pkg', grunt.file.readJSON('package.json'));
	});

	// Release task
	grunt.registerTask('release', function (n) {
		var n = n || 'patch';
		grunt.task.run([
			'version::' + n,
			'readpkg',
			'conventionalChangelog:release',
			'gitfetch:release',
			'gitcommit:release',
			'gittag:release',
			'gitpush:release',
			'gitpush:release_tags',
			'clean:release',
			'composer:install:no-dev:prefer-dist',
			'copy:release',
			'compress:release',
			'gh_release',
		]);
	});
};