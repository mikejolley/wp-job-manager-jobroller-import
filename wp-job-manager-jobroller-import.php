<?php
/*
Plugin Name: WP Job Manager - JobRoller Import
Plugin URI: http://mikejolley.com
Description: Convert Jobroller meta data to WP Job Manager format. Ensure you BACKUP before running this converter.
Author: Mike Jolley
Author URI: http://mikejolley.com
Version: 1.0.0
Text Domain: wp-job-manager-jobroller-import
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if ( ! defined( 'WP_LOAD_IMPORTERS' ) )
	return;

/** Display verbose errors */
if ( ! defined( 'IMPORT_DEBUG' ) )
	define( 'IMPORT_DEBUG', false );

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) ) {
		require $class_wp_importer;
	}
}

/**
 * WP_Job_Manager_Jobroller_Import Importer class
 *
 * @package WordPress
 * @subpackage Importer
 */
class WP_Job_Manager_Jobroller_Import extends WP_Importer {

	private $converted = 0;
	private $skipped   = 0;

	/**
	 * Registered callback function
	 */
	function dispatch() {
		$this->header();
		$this->convert();
		$this->footer();
	}

	/**
	 * Page header
	 */
	function header() {
		echo '<div class="wrap">';
		echo '<h2>' . __( 'JobRoller to WP Job Manager Importer', 'wp-job-manager-jobroller-import' ) . '</h2>';
	}

	/**
	 * Page footer
	 */
	function footer() {
		echo '</div>';
	}

	/**
	 * Suspend caching and run the converter
	 */
	function convert() {
		wp_suspend_cache_invalidation( true );

		$this->process_jobs();
		$this->process_taxonomies();

		wp_suspend_cache_invalidation( false );
	}

	/**
	 * Convert all the jobs
	 */
	public function process_jobs() {
		$jobs = get_posts( array(
			'posts_per_page' => -1,
			'post_type'      => 'job_listing',
			'post_status'    => 'publish',
			'fields'         => 'ids'
		) );

		foreach ( $jobs as $job_id ) {
			// Already converted
			if ( get_post_meta( $job_id, '_job_location', true ) || ! get_post_meta( $job_id, '_Company', true ) ) {
				$this->skipped ++;
				continue;
			}

			// Convert it
			$company_name    = get_post_meta( $job_id, '_Company', true );
			$company_website = get_post_meta( $job_id, '_CompanyURL', true );
			$expiry_date     = get_post_meta( $job_id, '_expires', true );
			$company_logo    = '';
			$address         = get_post_meta( $job_id, 'geo_address', true );

			if ( $address ) {
				$location = $address;
			} else {
				$location = '';
			}

			if ( has_post_thumbnail( $job_id ) ) {
			    $thumb = wp_get_attachment_image_src( get_post_thumbnail_id( $job_id ), 'full' );
			    $company_logo = $thumb[0];
			}

			$how_to_apply = get_post_meta( $job_id, '_how_to_apply', true );

			preg_match_all( '/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(\/\S*)?/', $how_to_apply, $url_matches );
			preg_match_all( '/[a-z0-9_\-\+]+@[a-z0-9\-]+\.([a-z]{2,3})(?:\.[a-z]{2})?/i', $how_to_apply, $email_matches );

			if ( sizeof( $email_matches[0] ) > 0 ) {
				$apply = current( $email_matches[0] );
			} elseif ( sizeof( $url_matches[0] ) > 0 ) {
				$apply = current( $url_matches[0] );
			} else {
				$apply = '';
			}

			add_post_meta( $job_id, '_company_name', $company_name );
			update_post_meta( $job_id, '_company_website', $company_website );
			add_post_meta( $job_id, '_company_logo', $company_logo );
			add_post_meta( $job_id, '_featured', 0 );
			add_post_meta( $job_id, '_filled', 0 );
			add_post_meta( $job_id, '_company_tagline', '' );
			add_post_meta( $job_id, '_company_twitter', '' );
			add_post_meta( $job_id, '_job_location', $location );
			update_post_meta( $job_id, '_application', $apply );

			if ( $expiry_date ) {
				add_post_meta( $job_id, '_job_expires', date( 'Y-m-d', $expiry_date ) );
			}

			if ( $location ) {
				WP_Job_Manager_Geocode::generate_location_data( $job_id, $location );
			}

			printf( '<p>' . __('<strong>%s</strong> product was converted', 'wp-job-manager-jobroller-import') . '</p>', get_the_title( $job_id ) );

			$this->converted ++;
		}

		printf( '<p>' . __('<strong>Done</strong>. Converted %d jobs and skipped %d.', 'wp-job-manager-jobroller-import') . '</p>', $this->converted, $this->skipped );
	}

	public function process_taxonomies() {
		global $wpdb;

		echo '<p>' . __( 'Converting taxonomies', 'wp-job-manager-jobroller-import') . '</p>';

		$wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => 'job_listing_category' ), array( 'taxonomy' => 'job_cat' ) );
		$wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => 'job_listing_tag' ), array( 'taxonomy' => 'job_tag' ) );
		$wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => 'job_listing_type' ), array( 'taxonomy' => 'job_type' ) );

		echo '<p>' . __( 'Finished converting taxonomies. Have fun!', 'wp-job-manager-jobroller-import') . '</p>';
	}
}

/**
 * Init the importer
 */
function wp_job_manager_jobroller_import_init() {
	load_plugin_textdomain( 'wp-job-manager-jobroller-import', false, dirname( plugin_basename( __FILE__ ) ) );

	$GLOBALS['wp-job-manager-jobroller-import'] = new WP_Job_Manager_Jobroller_Import();

	register_importer( 'wp-job-manager-jobroller-import', 'JobRoller to WP Job Manager Importer', __( 'Convert Jobroller meta data to WP Job Manager format. BACKUP your data first!', 'wp-job-manager-jobroller-import'), array( $GLOBALS['wp-job-manager-jobroller-import'], 'dispatch' ) );

}
add_action( 'admin_init', 'wp_job_manager_jobroller_import_init' );
