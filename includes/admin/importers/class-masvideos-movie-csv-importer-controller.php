<?php
/**
 * Class MasVideos_Movie_CSV_Importer_Controller file.
 *
 * @package MasVideos\Admin\Importers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_Importer' ) ) {
	return;
}

/**
 * Movie importer controller - handles file upload and forms in admin.
 *
 * @package     MasVideos/Admin/Importers
 * @version     3.1.0
 */
class MasVideos_Movie_CSV_Importer_Controller {

	/**
	 * The path to the current file.
	 *
	 * @var string
	 */
	protected $file = '';

	/**
	 * The current import step.
	 *
	 * @var string
	 */
	protected $step = '';

	/**
	 * Progress steps.
	 *
	 * @var array
	 */
	protected $steps = array();

	/**
	 * Errors.
	 *
	 * @var array
	 */
	protected $errors = array();

	/**
	 * The current delimiter for the file being read.
	 *
	 * @var string
	 */
	protected $delimiter = ',';

	/**
	 * Whether to use previous mapping selections.
	 *
	 * @var bool
	 */
	protected $map_preferences = false;

	/**
	 * Whether to skip existing movies.
	 *
	 * @var bool
	 */
	protected $update_existing = false;

	/**
	 * Get importer instance.
	 *
	 * @param  string $file File to import.
	 * @param  array  $args Importer arguments.
	 * @return MasVideos_Movie_CSV_Importer
	 */
	public static function get_importer( $file, $args = array() ) {
		$importer_class = apply_filters( 'masvideos_movie_csv_importer_class', 'MasVideos_Movie_CSV_Importer' );
		$args           = apply_filters( 'masvideos_movie_csv_importer_args', $args, $importer_class );
		return new $importer_class( $file, $args );
	}

	/**
	 * Check whether a file is a valid CSV file.
	 *
	 * @param string $file File path.
	 * @param bool   $check_path Whether to also check the file is located in a valid location (Default: true).
	 * @return bool
	 */
	public static function is_file_valid_csv( $file, $check_path = true ) {
		if ( $check_path && apply_filters( 'masvideos_movie_csv_importer_check_import_file_path', true ) && false !== stripos( $file, '://' ) ) {
			return false;
		}

		$valid_filetypes = self::get_valid_csv_filetypes();
		$filetype = wp_check_filetype( $file, $valid_filetypes );
		if ( in_array( $filetype['type'], $valid_filetypes, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get all the valid filetypes for a CSV file.
	 *
	 * @return array
	 */
	protected static function get_valid_csv_filetypes() {
		return apply_filters(
			'masvideos_csv_movie_import_valid_filetypes', array(
				'csv' => 'text/csv',
				'txt' => 'text/plain',
			)
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$default_steps = array(
			'upload'  => array(
				'name'    => __( 'Upload CSV file', 'masvideos' ),
				'view'    => array( $this, 'upload_form' ),
				'handler' => array( $this, 'upload_form_handler' ),
			),
			'mapping' => array(
				'name'    => __( 'Column mapping', 'masvideos' ),
				'view'    => array( $this, 'mapping_form' ),
				'handler' => '',
			),
			'import'  => array(
				'name'    => __( 'Import', 'masvideos' ),
				'view'    => array( $this, 'import' ),
				'handler' => '',
			),
			'done'    => array(
				'name'    => __( 'Done!', 'masvideos' ),
				'view'    => array( $this, 'done' ),
				'handler' => '',
			),
		);

		$this->steps = apply_filters( 'masvideos_movie_csv_importer_steps', $default_steps );

		// phpcs:disable WordPress.CSRF.NonceVerification.NoNonceVerification
		$this->step            = isset( $_REQUEST['step'] ) ? sanitize_key( $_REQUEST['step'] ) : current( array_keys( $this->steps ) );
		$this->file            = isset( $_REQUEST['file'] ) ? masvideos_clean( wp_unslash( $_REQUEST['file'] ) ) : '';
		$this->update_existing = isset( $_REQUEST['update_existing'] ) ? (bool) $_REQUEST['update_existing'] : false;
		$this->delimiter       = ! empty( $_REQUEST['delimiter'] ) ? masvideos_clean( wp_unslash( $_REQUEST['delimiter'] ) ) : ',';
		$this->map_preferences = isset( $_REQUEST['map_preferences'] ) ? (bool) $_REQUEST['map_preferences'] : false;
		// phpcs:enable

		if ( $this->map_preferences ) {
			add_filter( 'masvideos_csv_movie_import_mapped_columns', array( $this, 'auto_map_user_preferences' ), 9999 );
		}
	}

	/**
	 * Get the URL for the next step's screen.
	 *
	 * @param string $step  slug (default: current step).
	 * @return string       URL for next step if a next step exists.
	 *                      Admin URL if it's the last step.
	 *                      Empty string on failure.
	 */
	public function get_next_step_link( $step = '' ) {
		if ( ! $step ) {
			$step = $this->step;
		}

		$keys = array_keys( $this->steps );

		if ( end( $keys ) === $step ) {
			return admin_url();
		}

		$step_index = array_search( $step, $keys, true );

		if ( false === $step_index ) {
			return '';
		}

		$params = array(
			'step'            => $keys[ $step_index + 1 ],
			'file'            => str_replace( DIRECTORY_SEPARATOR, '/', $this->file ),
			'delimiter'       => $this->delimiter,
			'update_existing' => $this->update_existing,
			'map_preferences' => $this->map_preferences,
			'_wpnonce'        => wp_create_nonce( 'masvideos-csv-importer' ), // wp_nonce_url() escapes & to &amp; breaking redirects.
		);

		return add_query_arg( $params );
	}

	/**
	 * Output header view.
	 */
	protected function output_header() {
		include dirname( __FILE__ ) . '/views/html-csv-import-header.php';
	}

	/**
	 * Output steps view.
	 */
	protected function output_steps() {
		include dirname( __FILE__ ) . '/views/html-csv-import-steps.php';
	}

	/**
	 * Output footer view.
	 */
	protected function output_footer() {
		include dirname( __FILE__ ) . '/views/html-csv-import-footer.php';
	}

	/**
	 * Add error message.
	 *
	 * @param string $message Error message.
	 * @param array  $actions List of actions with 'url' and 'label'.
	 */
	protected function add_error( $message, $actions = array() ) {
		$this->errors[] = array(
			'message' => $message,
			'actions' => $actions,
		);
	}

	/**
	 * Add error message.
	 */
	protected function output_errors() {
		if ( ! $this->errors ) {
			return;
		}

		foreach ( $this->errors as $error ) {
			echo '<div class="error inline">';
			echo '<p>' . esc_html( $error['message'] ) . '</p>';

			if ( ! empty( $error['actions'] ) ) {
				echo '<p>';
				foreach ( $error['actions'] as $action ) {
					echo '<a class="button button-primary" href="' . esc_url( $action['url'] ) . '">' . esc_html( $action['label'] ) . '</a> ';
				}
				echo '</p>';
			}
			echo '</div>';
		}
	}

	/**
	 * Dispatch current step and show correct view.
	 */
	public function dispatch() {
		// phpcs:ignore WordPress.CSRF.NonceVerification.NoNonceVerification
		if ( ! empty( $_POST['save_step'] ) && ! empty( $this->steps[ $this->step ]['handler'] ) ) {
			call_user_func( $this->steps[ $this->step ]['handler'], $this );
		}
		$this->output_header();
		$this->output_steps();
		$this->output_errors();
		call_user_func( $this->steps[ $this->step ]['view'], $this );
		$this->output_footer();
	}

	/**
	 * Output information about the uploading process.
	 */
	protected function upload_form() {
		$bytes      = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
		$size       = size_format( $bytes );
		$upload_dir = wp_upload_dir();

		include dirname( __FILE__ ) . '/views/html-csv-import-form.php';
	}

	/**
	 * Handle the upload form and store options.
	 */
	public function upload_form_handler() {
		check_admin_referer( 'masvideos-csv-importer' );

		$file = $this->handle_upload();

		if ( is_wp_error( $file ) ) {
			$this->add_error( $file->get_error_message() );
			return;
		} else {
			$this->file = $file;
		}

		wp_redirect( esc_url_raw( $this->get_next_step_link() ) );
		exit;
	}

	/**
	 * Handles the CSV upload and initial parsing of the file to prepare for
	 * displaying author import options.
	 *
	 * @return string|WP_Error
	 */
	public function handle_upload() {
		// phpcs:disable WordPress.CSRF.NonceVerification.NoNonceVerification -- Nonce already verified in MasVideos_Movie_CSV_Importer_Controller::upload_form_handler()
		$file_url = isset( $_POST['file_url'] ) ? masvideos_clean( wp_unslash( $_POST['file_url'] ) ) : '';

		if ( empty( $file_url ) ) {
			if ( ! isset( $_FILES['import'] ) ) {
				return new WP_Error( 'masvideos_movie_csv_importer_upload_file_empty', __( 'File is empty. Please upload something more substantial. This error could also be caused by uploads being disabled in your php.ini or by post_max_size being defined as smaller than upload_max_filesize in php.ini.', 'masvideos' ) );
			}

			if ( ! self::is_file_valid_csv( masvideos_clean( wp_unslash( $_FILES['import']['name'] ) ), false ) ) {
				return new WP_Error( 'masvideos_movie_csv_importer_upload_file_invalid', __( 'Invalid file type. The importer supports CSV and TXT file formats.', 'masvideos' ) );
			}

			$overrides = array(
				'test_form' => false,
				'mimes'     => self::get_valid_csv_filetypes(),
			);
			$import    = $_FILES['import']; // WPCS: sanitization ok, input var ok.
			$upload    = wp_handle_upload( $import, $overrides );

			if ( isset( $upload['error'] ) ) {
				return new WP_Error( 'masvideos_movie_csv_importer_upload_error', $upload['error'] );
			}

			// Construct the object array.
			$object = array(
				'post_title'     => basename( $upload['file'] ),
				'post_content'   => $upload['url'],
				'post_mime_type' => $upload['type'],
				'guid'           => $upload['url'],
				'context'        => 'import',
				'post_status'    => 'private',
			);

			// Save the data.
			$id = wp_insert_attachment( $object, $upload['file'] );

			/*
			 * Schedule a cleanup for one day from now in case of failed
			 * import or missing wp_import_cleanup() call.
			 */
			wp_schedule_single_event( time() + DAY_IN_SECONDS, 'importer_scheduled_cleanup', array( $id ) );

			return $upload['file'];
		} elseif ( file_exists( ABSPATH . $file_url ) ) {
			if ( ! self::is_file_valid_csv( ABSPATH . $file_url ) ) {
				return new WP_Error( 'masvideos_movie_csv_importer_upload_file_invalid', __( 'Invalid file type. The importer supports CSV and TXT file formats.', 'masvideos' ) );
			}

			return ABSPATH . $file_url;
		}
		// phpcs:enable

		return new WP_Error( 'masvideos_movie_csv_importer_upload_invalid_file', __( 'Please upload or provide the link to a valid CSV file.', 'masvideos' ) );
	}

	/**
	 * Mapping step.
	 */
	protected function mapping_form() {
		$args = array(
			'lines'     => 1,
			'delimiter' => $this->delimiter,
		);

		$importer     = self::get_importer( $this->file, $args );
		$headers      = $importer->get_raw_keys();
		$mapped_items = $this->auto_map_columns( $headers );
		$sample       = current( $importer->get_raw_data() );

		if ( empty( $sample ) ) {
			$this->add_error(
				__( 'The file is empty or using a different encoding than UTF-8, please try again with a new file.', 'masvideos' ),
				array(
					array(
						'url'   => admin_url( 'edit.php?post_type=movie&page=movie_importer' ),
						'label' => __( 'Upload a new file', 'masvideos' ),
					),
				)
			);

			// Force output the errors in the same page.
			$this->output_errors();
			return;
		}

		include_once dirname( __FILE__ ) . '/views/html-csv-import-mapping.php';
	}

	/**
	 * Import the file if it exists and is valid.
	 */
	public function import() {
		if ( ! self::is_file_valid_csv( $this->file ) ) {
			$this->add_error( __( 'Invalid file type. The importer supports CSV and TXT file formats.', 'masvideos' ) );
			$this->output_errors();
			return;
		}

		if ( ! is_file( $this->file ) ) {
			$this->add_error( __( 'The file does not exist, please try again.', 'masvideos' ) );
			$this->output_errors();
			return;
		}

		// phpcs:disable WordPress.CSRF.NonceVerification.NoNonceVerification -- Nonce already verified in MasVideos_Admin_Importers::do_ajax_movie_import()
		if ( ! empty( $_POST['map_from'] ) && ! empty( $_POST['map_to'] ) ) {
			$mapping_from = masvideos_clean( wp_unslash( $_POST['map_from'] ) );
			$mapping_to   = masvideos_clean( wp_unslash( $_POST['map_to'] ) );

			// Save mapping preferences for future imports.
			update_user_option( get_current_user_id(), 'masvideos_movie_import_mapping', $mapping_to );
		} else {
			wp_redirect( esc_url_raw( $this->get_next_step_link( 'upload' ) ) );
			exit;
		}
		// phpcs:enable

		wp_localize_script(
			'masvideos-movie-import', 'masvideos_movie_import_params', array(
				'import_nonce'    => wp_create_nonce( 'masvideos-movie-import' ),
				'mapping'         => array(
					'from' => $mapping_from,
					'to'   => $mapping_to,
				),
				'file'            => $this->file,
				'update_existing' => $this->update_existing,
				'delimiter'       => $this->delimiter,
			)
		);
		wp_enqueue_script( 'masvideos-movie-import' );

		include_once dirname( __FILE__ ) . '/views/html-csv-import-progress.php';
	}

	/**
	 * Done step.
	 */
	protected function done() {
		// phpcs:disable WordPress.CSRF.NonceVerification.NoNonceVerification
		$post_type  = isset( $_GET['post_type'] ) ? masvideos_clean( $_GET['post_type'] ) : 'post';
		$imported 	= isset( $_GET['movies-imported'] ) ? absint( $_GET['movies-imported'] ) : 0;
		$updated  	= isset( $_GET['movies-updated'] ) ? absint( $_GET['movies-updated'] ) : 0;
		$failed   	= isset( $_GET['movies-failed'] ) ? absint( $_GET['movies-failed'] ) : 0;
		$skipped  	= isset( $_GET['movies-skipped'] ) ? absint( $_GET['movies-skipped'] ) : 0;
		$errors   	= array_filter( (array) get_user_option( 'movie_import_error_log' ) );
		// phpcs:enable

		include_once dirname( __FILE__ ) . '/views/html-csv-import-done.php';
	}

	/**
	 * Columns to normalize.
	 *
	 * @param  array $columns List of columns names and keys.
	 * @return array
	 */
	protected function normalize_columns_names( $columns ) {
		$normalized = array();

		foreach ( $columns as $key => $value ) {
			$normalized[ strtolower( $key ) ] = $value;
		}

		return $normalized;
	}

	/**
	 * Auto map column names.
	 *
	 * @param  array $raw_headers Raw header columns.
	 * @param  bool  $num_indexes If should use numbers or raw header columns as indexes.
	 * @return array
	 */
	protected function auto_map_columns( $raw_headers, $num_indexes = true ) {
		$weight_unit    = get_option( 'masvideos_weight_unit' );
		$dimension_unit = get_option( 'masvideos_dimension_unit' );

		include dirname( __FILE__ ) . '/mappings/mappings.php';

		/*
		 * @hooked masvideos_importer_generic_mappings - 10
		 * @hooked masvideos_importer_wordpress_mappings - 10
		 * @hooked masvideos_importer_default_english_mappings - 100
		 */
		$default_columns = $this->normalize_columns_names(
			apply_filters(
				'masvideos_csv_movie_import_mapping_default_columns', array(
					__( 'ID', 'masvideos' )                        => 'id',
					__( 'Name', 'masvideos' )                      => 'name',
					__( 'Published', 'masvideos' )                 => 'published',
					__( 'Is featured?', 'masvideos' )              => 'featured',
					__( 'Visibility in catalog', 'masvideos' )     => 'catalog_visibility',
					__( 'Short description', 'masvideos' )         => 'short_description',
					__( 'Description', 'masvideos' )               => 'description',
					__( 'Allow customer reviews?', 'masvideos' )   => 'reviews_allowed',
					__( 'Genres', 'masvideos' )                    => 'genre_ids',
					__( 'Tags', 'masvideos' )                      => 'tag_ids',
					__( 'Images', 'masvideos' )                    => 'images',
					__( 'Position', 'masvideos' )                  => 'menu_order',
					__( 'Movie Choice', 'masvideos' )              => 'movie_choice',
					__( 'Movie Attachment', 'masvideos' )          => 'movie_attachment_id',
					__( 'Movie Embed Content', 'masvideos' )       => 'movie_embed_content',
					__( 'Movie Link', 'masvideos' )                => 'movie_url_link',
					__( 'Movie Release Date', 'masvideos' )        => 'movie_release_date',
					__( 'Movie Run Time', 'masvideos' )            => 'movie_run_time',
					__( 'Movie Censor Rating', 'masvideos' )       => 'movie_censor_rating',
					__( 'Recommended Movie', 'masvideos' )         => 'recommended_movie_ids',
				)
			)
		);

		$special_columns = $this->get_special_columns(
			$this->normalize_columns_names(
				apply_filters(
					'masvideos_csv_movie_import_mapping_special_columns',
					array(
						/* translators: %d: Attribute number */
						__( 'Attribute %d name', 'masvideos' ) => 'attributes:name',
						/* translators: %d: Attribute number */
						__( 'Attribute %d value(s)', 'masvideos' ) => 'attributes:value',
						/* translators: %d: Attribute number */
						__( 'Attribute %d visible', 'masvideos' ) => 'attributes:visible',
						/* translators: %d: Attribute number */
						__( 'Attribute %d global', 'masvideos' ) => 'attributes:taxonomy',
						/* translators: %d: Attribute number */
						__( 'Attribute %d default', 'masvideos' ) => 'attributes:default',
						/* translators: %d: Meta number */
						__( 'Meta: %s', 'masvideos' ) => 'meta:',
					)
				)
			)
		);

		$headers = array();
		foreach ( $raw_headers as $key => $field ) {
			$field             = strtolower( $field );
			$index             = $num_indexes ? $key : $field;
			$headers[ $index ] = $field;

			if ( isset( $default_columns[ $field ] ) ) {
				$headers[ $index ] = $default_columns[ $field ];
			} else {
				foreach ( $special_columns as $regex => $special_key ) {
					if ( preg_match( $regex, $field, $matches ) ) {
						$headers[ $index ] = $special_key . $matches[1];
						break;
					}
				}
			}
		}

		return apply_filters( 'masvideos_csv_movie_import_mapped_columns', $headers, $raw_headers );
	}

	/**
	 * Map columns using the user's lastest import mappings.
	 *
	 * @param  array $headers Header columns.
	 * @return array
	 */
	public function auto_map_user_preferences( $headers ) {
		$mapping_preferences = get_user_option( 'masvideos_movie_import_mapping' );

		if ( ! empty( $mapping_preferences ) && is_array( $mapping_preferences ) ) {
			return $mapping_preferences;
		}

		return $headers;
	}

	/**
	 * Sanitize special column name regex.
	 *
	 * @param  string $value Raw special column name.
	 * @return string
	 */
	protected function sanitize_special_column_name_regex( $value ) {
		return '/' . str_replace( array( '%d', '%s' ), '(.*)', trim( quotemeta( $value ) ) ) . '/';
	}

	/**
	 * Get special columns.
	 *
	 * @param  array $columns Raw special columns.
	 * @return array
	 */
	protected function get_special_columns( $columns ) {
		$formatted = array();

		foreach ( $columns as $key => $value ) {
			$regex = $this->sanitize_special_column_name_regex( $key );

			$formatted[ $regex ] = $value;
		}

		return $formatted;
	}

	/**
	 * Get mapping options.
	 *
	 * @param  string $item Item name.
	 * @return array
	 */
	protected function get_mapping_options( $item = '' ) {
		// Get index for special column names.
		$index = $item;

		if ( preg_match( '/\d+$/', $item, $matches ) ) {
			$index = $matches[0];
		}

		// Properly format for meta field.
		$meta = str_replace( 'meta:', '', $item );

		// Available options.
		$weight_unit    = get_option( 'masvideos_weight_unit' );
		$dimension_unit = get_option( 'masvideos_dimension_unit' );
		$options        = array(
			'id'                     => __( 'ID', 'masvideos' ),
			'name'                   => __( 'Name', 'masvideos' ),
			'published'              => __( 'Published', 'masvideos' ),
			'featured'               => __( 'Is featured?', 'masvideos' ),
			'catalog_visibility'     => __( 'Visibility in catalog', 'masvideos' ),
			'short_description'      => __( 'Short description', 'masvideos' ),
			'description'            => __( 'Description', 'masvideos' ),
			'genre_ids'              => __( 'Genres', 'masvideos' ),
			'tag_ids'                => __( 'Tags', 'masvideos' ),
			'images'                 => __( 'Images', 'masvideos' ),
			'movie_choice'           => __( 'Movie Choice', 'masvideos' ),
			'movie_attachment_id'    => __( 'Movie Attachment', 'masvideos' ),
			'movie_embed_content'    => __( 'Movie Embed Content', 'masvideos' ),
			'movie_url_link'         => __( 'Movie Link', 'masvideos' ),
			'movie_release_date'     => __( 'Movie Release Date', 'masvideos' ),
			'movie_run_time'         => __( 'Movie Run Time', 'masvideos' ),
			'movie_censor_rating'    => __( 'Movie Censor Rating', 'masvideos' ),
			'recommended_movie_ids'  => __( 'Recommended Movies', 'masvideos' ),
			'attributes'             => array(
				'name'    => __( 'Attributes', 'masvideos' ),
				'options' => array(
					'attributes:name' . $index     => __( 'Attribute name', 'masvideos' ),
					'attributes:value' . $index    => __( 'Attribute value(s)', 'masvideos' ),
					'attributes:taxonomy' . $index => __( 'Is a global attribute?', 'masvideos' ),
					'attributes:visible' . $index  => __( 'Attribute visibility', 'masvideos' ),
					'attributes:default' . $index  => __( 'Default attribute', 'masvideos' ),
				),
			),
			'reviews_allowed'        => __( 'Allow customer reviews?', 'masvideos' ),
			'meta:' . $meta          => __( 'Import as meta', 'masvideos' ),
			'menu_order'             => __( 'Position', 'masvideos' ),
		);

		return apply_filters( 'masvideos_csv_movie_import_mapping_options', $options, $item );
	}
}
