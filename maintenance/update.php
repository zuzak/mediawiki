#!/usr/bin/env php
<?php
/**
 * Run all updaters.
 *
 * This is used when the database schema is modified and we need to apply patches.
 * It is kept compatible with php 4 parsing so that it can give out a meaningful error.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @todo document
 * @ingroup Maintenance
 */

if ( !function_exists( 'version_compare' ) || ( version_compare( PHP_VERSION, '5.3.3' ) < 0 ) ) {
	require dirname( __FILE__ ) . '/../includes/PHPVersionError.php';
	wfPHPVersionError( 'cli' );
}

$wgUseMasterForMaintenance = true;
require_once __DIR__ . '/Maintenance.php';

/**
 * Maintenance script to run database schema updates.
 *
 * @ingroup Maintenance
 */
class UpdateMediaWiki extends Maintenance {
	function __construct() {
		parent::__construct();
		$this->mDescription = "MediaWiki database updater";
		$this->addOption( 'skip-compat-checks', 'Skips compatibility checks, mostly for developers' );
		$this->addOption( 'quick', 'Skip 5 second countdown before starting' );
		$this->addOption( 'doshared', 'Also update shared tables' );
		$this->addOption( 'nopurge', 'Do not purge the objectcache table after updates' );
		$this->addOption( 'noschema', 'Only do the updates that are not done during schema updates' );
		$this->addOption(
			'schema',
			'Output SQL to do the schema updates instead of doing them. Works '
				. 'even when $wgAllowSchemaUpdates is false',
			false,
			true
		);
		$this->addOption( 'force', 'Override when $wgAllowSchemaUpdates disables this script' );
	}

	function getDbType() {
		/* If we used the class constant PHP4 would give a parser error here */
		return 2; /* Maintenance::DB_ADMIN */
	}

	function compatChecks() {
		// Avoid syntax error in PHP4
		$minimumPcreVersion = constant( 'Installer::MINIMUM_PCRE_VERSION' );

		list( $pcreVersion ) = explode( ' ', PCRE_VERSION, 2 );
		if ( version_compare( $pcreVersion, $minimumPcreVersion, '<' ) ) {
			$this->error(
				"PCRE $minimumPcreVersion or later is required.\n" .
				"Your PHP binary is linked with PCRE $pcreVersion.\n\n" .
				"More information:\n" .
				"https://www.mediawiki.org/wiki/Manual:Errors_and_symptoms/PCRE\n\n" .
				"ABORTING.\n",
				true );
		}

		$test = new PhpXmlBugTester();
		if ( !$test->ok ) {
			$this->error(
				"Your system has a combination of PHP and libxml2 versions that is buggy\n" .
				"and can cause hidden data corruption in MediaWiki and other web apps.\n" .
				"Upgrade to libxml2 2.7.3 or later.\n" .
				"ABORTING (see https://bugs.php.net/bug.php?id=45996).\n",
				true );
		}
	}

	function execute() {
		global $wgVersion, $wgLang, $wgAllowSchemaUpdates;

		if ( !$wgAllowSchemaUpdates
			&& !( $this->hasOption( 'force' )
				|| $this->hasOption( 'schema' )
				|| $this->hasOption( 'noschema' ) )
		) {
			$this->error( "Do not run update.php on this wiki. If you're seeing this you should\n"
				. "probably ask for some help in performing your schema updates or use\n"
				. "the --noschema and --schema options to get an SQL file for someone\n"
				. "else to inspect and run.\n\n"
				. "If you know what you are doing, you can continue with --force\n", true );
		}

		$this->fileHandle = null;
		if ( substr( $this->getOption( 'schema' ), 0, 2 ) === "--" ) {
			$this->error( "The --schema option requires a file as an argument.\n", true );
		} elseif ( $this->hasOption( 'schema' ) ) {
			$file = $this->getOption( 'schema' );
			$this->fileHandle = fopen( $file, "w" );
			if ( $this->fileHandle === false ) {
				$err = error_get_last();
				$this->error( "Problem opening the schema file for writing: $file\n\t{$err['message']}", true );
			}
		}

		$wgLang = Language::factory( 'en' );

		define( 'MW_UPDATER', true );

		$this->output( "MediaWiki {$wgVersion} Updater\n\n" );

		wfWaitForSlaves( 5 ); // let's not kill databases, shall we? ;) --tor

		if ( !$this->hasOption( 'skip-compat-checks' ) ) {
			$this->compatChecks();
		} else {
			$this->output( "Skipping compatibility checks, proceed at your own risk (Ctrl+C to abort)\n" );
			wfCountdown( 5 );
		}

		# Attempt to connect to the database as a privileged user
		# This will vomit up an error if there are permissions problems
		$db = wfGetDB( DB_MASTER );

		$this->output( "Going to run database updates for " . wfWikiID() . "\n" );
		if ( $db->getType() === 'sqlite' ) {
			$this->output( "Using SQLite file: '{$db->mDatabaseFile}'\n" );
		}
		$this->output( "Depending on the size of your database this may take a while!\n" );

		if ( !$this->hasOption( 'quick' ) ) {
			$this->output( "Abort with control-c in the next five seconds "
				. "(skip this countdown with --quick) ... " );
			wfCountDown( 5 );
		}

		$time1 = new MWTimestamp();

		$shared = $this->hasOption( 'doshared' );

		$updates = array( 'core', 'extensions' );
		if ( !$this->hasOption( 'schema' ) ) {
			if ( $this->hasOption( 'noschema' ) ) {
				$updates[] = 'noschema';
			}
			$updates[] = 'stats';
		}

		$updater = DatabaseUpdater::newForDb( $db, $shared, $this );
		$updater->doUpdates( $updates );

		foreach ( $updater->getPostDatabaseUpdateMaintenance() as $maint ) {
			$child = $this->runChild( $maint );

			// LoggedUpdateMaintenance is checking the updatelog itself
			$isLoggedUpdate = is_a( $child, 'LoggedUpdateMaintenance' );

			if ( !$isLoggedUpdate && $updater->updateRowExists( $maint ) ) {
				continue;
			}

			$child->execute();
			if ( !$isLoggedUpdate ) {
				$updater->insertUpdateRow( $maint );
			}
		}

		if ( !$this->hasOption( 'nopurge' ) ) {
			$updater->purgeCache();
		}
		$time2 = new MWTimestamp();

		$timeDiff = $time2->diff( $time1 );
		$this->output( "\nDone in " . $timeDiff->format( "%i:%S" ) . ".\n" );
	}

	function afterFinalSetup() {
		global $wgLocalisationCacheConf;

		# Don't try to access the database
		# This needs to be disabled early since extensions will try to use the l10n
		# cache from $wgExtensionFunctions (bug 20471)
		$wgLocalisationCacheConf = array(
			'class' => 'LocalisationCache',
			'storeClass' => 'LCStoreNull',
			'storeDirectory' => false,
			'manualRecache' => false,
		);
	}
}

$maintClass = 'UpdateMediaWiki';
require_once RUN_MAINTENANCE_IF_MAIN;
