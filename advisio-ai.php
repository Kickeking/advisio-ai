<?php
/**
 * Plugin Name: Advisio AI
 * Description: Advisio AI chat service — Guided Commerce & Intelligent Support by Area 404.
 * Version: 4.0.0
 * Author: Area 404
 * Text Domain: advisio-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ADVISIO_AI_VERSION', '4.0.0' );
define( 'ADVISIO_AI_PLUGIN_FILE', __FILE__ );
define( 'ADVISIO_AI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'ADVISIO_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADVISIO_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ADVISIO_AI_GITHUB_REPO', 'Kickeking/advisio-ai' );

/**
 * Native GitHub Release Auto-Updater Class.
 * Hardcoded to the official repository and strictly cached for 12 hours via WordPress transients.
 */
if ( ! class_exists( 'Advisio_AI_GitHub_Updater' ) ) {
class Advisio_AI_GitHub_Updater {

    private $plugin_file;
    private $plugin_slug;
    private $repo;
    private $token;
    private $github_response;

    public function __construct( $plugin_file ) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename( $plugin_file );
        $this->repo        = ADVISIO_AI_GITHUB_REPO;
        $this->token       = trim( (string) get_option( 'advisio_github_token', '' ) );

        if ( empty( $this->repo ) || false === strpos( $this->repo, '/' ) ) {
            return;
        }

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_popup_info' ), 10, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
    }

    private function fetch_latest_release() {
        if ( empty( $this->repo ) || false === strpos( $this->repo, '/' ) ) {
            return false;
        }

        if ( ! is_null( $this->github_response ) ) {
            return $this->github_response;
        }

        $transient_key = 'advisio_gh_rel_' . md5( $this->repo );
        $cached        = get_transient( $transient_key );

        if ( false !== $cached ) {
            $this->github_response = $cached;
            return $this->github_response;
        }

        $url     = "https://api.github.com/repos/{$this->repo}/releases/latest";
        $headers = array(
            'Accept'     => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
        );

        if ( ! empty( $this->token ) ) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        $response = wp_remote_get( $url, array(
            'headers' => $headers,
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            set_transient( $transient_key, false, 2 * HOUR_IN_SECONDS );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( empty( $data ) || ! isset( $data['tag_name'] ) ) {
            set_transient( $transient_key, false, 2 * HOUR_IN_SECONDS );
            return false;
        }

        $asset_url = '';
        if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
            foreach ( $data['assets'] as $asset ) {
                if ( isset( $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['browser_download_url'] ) ) {
                    $asset_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        if ( empty( $asset_url ) && ! empty( $data['zipball_url'] ) ) {
            $asset_url = $data['zipball_url'];
        }

        $release = array(
            'version'     => ltrim( $data['tag_name'], 'v' ),
            'package'     => $asset_url,
            'body'        => isset( $data['body'] ) ? $data['body'] : '',
            'published'   => isset( $data['published_at'] ) ? $data['published_at'] : '',
            'html_url'    => isset( $data['html_url'] ) ? $data['html_url'] : '',
        );

        set_transient( $transient_key, $release, 12 * HOUR_IN_SECONDS );
        $this->github_response = $release;

        return $this->github_response;
    }

    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->fetch_latest_release();
        if ( ! $release || empty( $release['package'] ) ) {
            return $transient;
        }

        if ( version_compare( $release['version'], ADVISIO_AI_VERSION, '>' ) ) {
            $plugin_data = array(
                'id'            => $this->plugin_slug,
                'slug'          => dirname( $this->plugin_slug ),
                'plugin'        => $this->plugin_slug,
                'new_version'   => $release['version'],
                'url'           => $release['html_url'],
                'package'       => $release['package'],
                'icons'         => array(),
                'banners'       => array(),
                'banners_rtl'   => array(),
                'tested'        => '',
                'requires_php'  => '7.4',
                'compatibility' => new stdClass(),
            );

            $transient->response[ $this->plugin_slug ] = (object) $plugin_data;
        }

        return $transient;
    }

    public function plugin_popup_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! isset( $args->slug ) ) {
            return $result;
        }

        if ( $args->slug !== dirname( $this->plugin_slug ) && $args->slug !== $this->plugin_slug ) {
            return $result;
        }

        $release = $this->fetch_latest_release();
        if ( ! $release ) {
            return $result;
        }

        $info = (object) array(
            'name'          => 'Advisio AI',
            'slug'          => $this->plugin_slug,
            'version'       => $release['version'],
            'author'        => '<a href="#">Area 404</a>',
            'homepage'      => 'https://area404.se',
            'download_link' => $release['package'],
            'sections'      => array(
                'description' => 'Advisio AI chat service — Guided Commerce & Intelligent Support for WooCommerce.',
                'changelog'   => nl2br( esc_html( $release['body'] ) ),
            ),
        );

        return $info;
    }

    public function after_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;
        $install_directory = plugin_dir_path( $this->plugin_file );
        $wp_filesystem->move( $result['destination'], $install_directory );
        $result['destination'] = $install_directory;

        if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $this->plugin_slug ) ) {
            if ( function_exists( 'activate_plugin' ) ) {
                activate_plugin( $this->plugin_slug );
            }
        }
        return $result;
    }
}
} // end if ! class_exists( 'Advisio_AI_GitHub_Updater' )

if ( ! class_exists( 'Advisio_AI' ) ) {
class Advisio_AI {

    /**
     * Knowledge scopes that website/shop/content crawler runs must NEVER delete or overwrite.
     * ('custom' is the legacy pre-4.3.0 scope for manual notes & uploaded files.)
     */
    const PROTECTED_SCOPES = array( 'manual', 'file', 'custom' );

    /**
     * Option key holding the durable backup copy of all Manual Knowledge entries.
     */
    const MANUAL_OPTION = 'advisio_manual_knowledge_list';
    const MANUAL_BACKUP_OPTION = 'advisio_manual_knowledge_backup';
    const CUSTOM_KNOWLEDGE_BACKUP = 'advisio_custom_knowledge_backup';
    const DNA_LOCKED_OPTION = 'advisio_dna_locked_items';
    const DNA_STAGED_OPTION = 'advisio_dna_staged_items';
    const MASTER_DNA_OPTION = 'advisio_compiled_master_dna';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'admin_init_tasks' ) );
        add_action( 'in_admin_header', array( $this, 'suppress_foreign_admin_notices' ), 9999 );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

        // Lightweight frontend hooks (Zero custom-table SQL queries and zero remote HTTP requests on page load)
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_head', array( $this, 'inject_dynamic_css' ) );
        add_action( 'wp_footer', array( $this, 'render_chat_widget' ) );

        // Admin AJAX endpoints (5-Stage Sequential Crawler + Knowledge + Proposals)
        add_action( 'wp_ajax_advisio_test_api_key', array( $this, 'ajax_test_api_key' ) );
        add_action( 'wp_ajax_advisio_get_index_queue', array( $this, 'ajax_get_index_queue' ) );
        add_action( 'wp_ajax_advisio_process_index_batch', array( $this, 'ajax_process_index_batch' ) );
        add_action( 'wp_ajax_advisio_upload_knowledge', array( $this, 'ajax_upload_knowledge' ) );
        add_action( 'wp_ajax_advisio_delete_custom_doc', array( $this, 'ajax_delete_custom_doc' ) );
        add_action( 'wp_ajax_advisio_handle_proposal', array( $this, 'ajax_handle_proposal' ) );
        add_action( 'wp_ajax_advisio_run_learning_engine', array( $this, 'ajax_run_learning_engine' ) );

        // Stage 1: Tab 2 Unified Custom Knowledge Base endpoints
        add_action( 'wp_ajax_advisio_save_custom_knowledge', array( $this, 'ajax_save_custom_knowledge' ) );
        add_action( 'wp_ajax_advisio_upload_custom_file', array( $this, 'ajax_upload_custom_file' ) );
        add_action( 'wp_ajax_advisio_delete_custom_knowledge', array( $this, 'ajax_delete_custom_knowledge' ) );
        add_action( 'wp_ajax_advisio_get_custom_knowledge', array( $this, 'ajax_get_custom_knowledge' ) );

        // Backwards compatibility aliases
        add_action( 'wp_ajax_advisio_save_manual_knowledge', array( $this, 'ajax_save_custom_knowledge' ) );
        add_action( 'wp_ajax_advisio_delete_manual_knowledge', array( $this, 'ajax_delete_custom_knowledge' ) );
        add_action( 'wp_ajax_advisio_get_manual_knowledge', array( $this, 'ajax_get_custom_knowledge' ) );
        add_action( 'wp_ajax_advisio_upload_knowledge', array( $this, 'ajax_upload_custom_file' ) );

        // Stage 1: Tab 3 The Brain (Self-Building Master Prompt) endpoints
        add_action( 'wp_ajax_advisio_save_setup_box', array( $this, 'ajax_save_setup_box' ) );
        add_action( 'wp_ajax_advisio_generate_master_brain', array( $this, 'ajax_generate_master_brain' ) );
        add_action( 'wp_ajax_advisio_add_pending_addition', array( $this, 'ajax_add_pending_addition' ) );
        add_action( 'wp_ajax_advisio_edit_pending_addition', array( $this, 'ajax_edit_pending_addition' ) );
        add_action( 'wp_ajax_advisio_delete_pending_addition', array( $this, 'ajax_delete_pending_addition' ) );
        add_action( 'wp_ajax_advisio_update_master_brain', array( $this, 'ajax_update_master_brain' ) );
        add_action( 'wp_ajax_advisio_reset_brain', array( $this, 'ajax_reset_brain' ) );

        // Task 5: Captured Lead Deletion endpoint
        add_action( 'wp_ajax_advisio_delete_lead', array( $this, 'ajax_delete_lead' ) );

        // Manual Knowledge (dual-storage) endpoints
        add_action( 'wp_ajax_advisio_save_manual_knowledge', array( $this, 'ajax_save_manual_knowledge' ) );
        add_action( 'wp_ajax_advisio_delete_manual_knowledge', array( $this, 'ajax_delete_manual_knowledge' ) );
        add_action( 'wp_ajax_advisio_get_manual_knowledge', array( $this, 'ajax_get_manual_knowledge' ) );

        // AI Memory Inspector & Diagnostics endpoints
        add_action( 'wp_ajax_advisio_memory_stats', array( $this, 'ajax_memory_stats' ) );
        add_action( 'wp_ajax_advisio_memory_list', array( $this, 'ajax_memory_list' ) );
        add_action( 'wp_ajax_advisio_memory_get', array( $this, 'ajax_memory_get' ) );
        add_action( 'wp_ajax_advisio_memory_update', array( $this, 'ajax_memory_update' ) );
        add_action( 'wp_ajax_advisio_memory_delete', array( $this, 'ajax_memory_delete' ) );
        add_action( 'wp_ajax_advisio_memory_test', array( $this, 'ajax_memory_test' ) );

        // Frontend & Public AJAX endpoints
        add_action( 'wp_ajax_advisio_send_chat_message', array( $this, 'fg_handle_chat_message' ) );
        add_action( 'wp_ajax_nopriv_advisio_send_chat_message', array( $this, 'fg_handle_chat_message' ) );
        add_action( 'wp_ajax_advisio_submit_lead', array( $this, 'handle_submit_lead' ) );
        add_action( 'wp_ajax_nopriv_advisio_submit_lead', array( $this, 'handle_submit_lead' ) );

        // Background 50-chat learning engine (runs via WP-Cron so customers never wait for it)
        add_action( 'advisio_run_learning_engine_cron', array( $this, 'cron_run_learning_engine' ) );
    }

    /**
     * Admin-only initialization: Settings registration, DB version check, and GitHub updater.
     * Never runs on frontend page views.
     */
    public function admin_init_tasks() {
        if ( isset( $_GET['page'] ) && strpos( (string) $_GET['page'], 'advisio' ) !== false ) {
            advisio_ensure_tables_ready();
        }

        $this->register_settings();
        if ( class_exists( 'Advisio_AI_GitHub_Updater' ) ) {
            new Advisio_AI_GitHub_Updater( ADVISIO_AI_PLUGIN_FILE );
        }
    }

    /**
     * 100% Passive Activation: Zero execution on activation.
     * Custom tables are created safely and on-demand only when opening Advisio AI in WP-Admin.
     */
    public static function activate_plugin() {
        return true;
    }

    /**
     * Deactivation cleanup: remove scheduled background events (data is preserved).
     */
    public static function deactivate_plugin() {
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( 'advisio_run_learning_engine_cron' );
        }
    }


    public static function get_default_lead_fields() {
        return array(
            array(
                'id'          => 'field_name',
                'label'       => 'Namn',
                'type'        => 'text',
                'placeholder' => 'Ditt för- och efternamn',
                'required'    => true,
                'options'     => '',
            ),
            array(
                'id'          => 'field_email',
                'label'       => 'E-postadress',
                'type'        => 'email',
                'placeholder' => 'din@epost.se',
                'required'    => true,
                'options'     => '',
            ),
            array(
                'id'          => 'field_phone',
                'label'       => 'Telefonnummer',
                'type'        => 'tel',
                'placeholder' => '070-123 45 67',
                'required'    => false,
                'options'     => '',
            ),
            array(
                'id'          => 'field_notes',
                'label'       => 'Övriga önskemål / Projektinfo',
                'type'        => 'textarea',
                'placeholder' => 'Beskriv kort vad du söker eller eventuella mått...',
                'required'    => false,
                'options'     => '',
            ),
        );
    }

    public function add_admin_menu() {
        add_menu_page(
            'Advisio AI',
            'Advisio AI',
            'manage_options',
            'advisio-ai',
            array( $this, 'admin_page_html' ),
            'dashicons-superhero',
            30
        );
    }

    /**
     * Prevent third-party plugin notice spam from covering or injecting into the Advisio AI workspace.
     */
    public function suppress_foreign_admin_notices() {
        if ( isset( $_GET['page'] ) && 'advisio-ai' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
            remove_all_actions( 'admin_notices' );
            remove_all_actions( 'all_admin_notices' );
            remove_all_actions( 'user_admin_notices' );
            remove_all_actions( 'network_admin_notices' );
        }
    }

    public function register_settings() {
        $settings = array(
            'advisio_language',
            'advisio_api_key',
            'advisio_system_instruction',
            'advisio_negative_constraints',
            'advisio_target_goals',
            'advisio_approved_strategies',
            'advisio_proactive',
            'advisio_proactive_text',
            'advisio_chat_title',
            'advisio_welcome_msg',
            'advisio_launcher_icon',
            'advisio_custom_icon',
            'advisio_color_primary',
            'advisio_color_header',
            'advisio_notify_email',
            'advisio_label_callback',
            'advisio_label_lead_header',
            'advisio_label_lead_success',
            'advisio_lead_form_fields',
            'advisio_github_token',
        );
        foreach ( $settings as $s ) {
            register_setting( 'advisio_ai_settings', $s );
        }

        // Main "Save All Settings" fallback: any text left in the Manual Knowledge editor is captured as a manual entry.
        register_setting(
            'advisio_ai_settings',
            'advisio_manual_pending_content',
            array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => array( $this, 'sanitize_manual_pending' ),
            )
        );
    }

    /**
     * Sanitize callback for the Manual Knowledge editor when the main settings form is submitted.
     * Saves the typed entry into BOTH the knowledge table and the wp_options backup, then stores nothing in the option itself.
     */
    public function sanitize_manual_pending( $value ) {
        static $processed = false;
        if ( $processed || ! current_user_can( 'manage_options' ) ) {
            return '';
        }

        $content = sanitize_textarea_field( (string) $value );
        if ( '' === trim( $content ) ) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification -- options.php already verified the settings nonce.
        $title = isset( $_POST['advisio_manual_pending_title'] ) ? sanitize_text_field( wp_unslash( $_POST['advisio_manual_pending_title'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification
        $entry_id = isset( $_POST['advisio_manual_pending_id'] ) ? sanitize_key( wp_unslash( $_POST['advisio_manual_pending_id'] ) ) : '';

        $this->save_custom_knowledge_entry( $entry_id, $title, $content, 'text' );
        $processed = true;

        return '';
    }

    public function admin_enqueue_scripts( $hook ) {
        // Robust hook check: matches standard slug, screen translations, submenus, or query param
        if ( strpos( (string) $hook, 'advisio' ) === false && ( ! isset( $_GET['page'] ) || strpos( (string) $_GET['page'], 'advisio' ) === false ) ) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true );

        wp_enqueue_script(
            'advisio-admin-js',
            ADVISIO_AI_PLUGIN_URL . 'assets/js/admin-script.js',
            array( 'jquery', 'wp-color-picker' ),
            ADVISIO_AI_VERSION,
            true
        );

        wp_enqueue_style(
            'advisio-admin-css',
            ADVISIO_AI_PLUGIN_URL . 'assets/css/admin-style.css',
            array(),
            ADVISIO_AI_VERSION
        );

        $perf_data = $this->get_performance_chart_data();

        wp_localize_script( 'advisio-admin-js', 'AdvisioAdmin', array(
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'advisio_admin_nonce' ),
            'homeUrl'      => home_url( '/' ),
            'monthlyStats' => $perf_data['monthly'],
            'yearlyStats'  => $perf_data['yearly'],
            'manualList'   => $this->get_live_custom_knowledge(),
            'memoryStats'  => $this->get_memory_stats(),
        ) );
    }

    /**
     * Compute both Monthly View (30 days, day-by-day) and Yearly View (12 months, month-by-month) for Chart.js.
     * Optimized: 2 grouped SQL queries instead of 84 individual COUNT queries.
     */
    private function get_performance_chart_data() {
        global $wpdb;
        $table_logs = $wpdb->prefix . 'fg_ai_logs';
        $now_local  = function_exists( 'current_time' ) ? current_time( 'timestamp' ) : time();
        $day_sec    = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;

        // Defensive check: If table does not exist yet (fresh install), return empty defaults safely
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_logs ) ) !== $table_logs ) {
            return array(
                'monthly' => array( 'labels' => array(), 'messages' => array(), 'leads' => array() ),
                'yearly'  => array( 'labels' => array(), 'messages' => array(), 'leads' => array() ),
            );
        }

        // 1. Monthly View: Last 30 days (local site time, matching created_at which is stored via current_time('mysql'))
        $start_day = gmdate( 'Y-m-d 00:00:00', $now_local - ( 29 * $day_sec ) );
        $day_rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) AS d, SUM(CASE WHEN is_lead = 0 THEN 1 ELSE 0 END) AS msgs, SUM(CASE WHEN is_lead = 1 THEN 1 ELSE 0 END) AS leads
             FROM $table_logs
             WHERE created_at >= %s
             GROUP BY DATE(created_at)",
            $start_day
        ), ARRAY_A );

        $day_map = array();
        foreach ( (array) $day_rows as $row ) {
            $day_map[ $row['d'] ] = $row;
        }

        $m_labels   = array();
        $m_messages = array();
        $m_leads    = array();
        for ( $i = 29; $i >= 0; $i-- ) {
            $ts           = $now_local - ( $i * $day_sec );
            $ymd          = gmdate( 'Y-m-d', $ts );
            $m_labels[]   = gmdate( 'd M', $ts );
            $m_messages[] = isset( $day_map[ $ymd ] ) ? (int) $day_map[ $ymd ]['msgs'] : 0;
            $m_leads[]    = isset( $day_map[ $ymd ] ) ? (int) $day_map[ $ymd ]['leads'] : 0;
        }

        // 2. Yearly View: Last 12 months (month-by-month)
        $month_base  = strtotime( gmdate( 'Y-m-01', $now_local ) . ' 00:00:00 UTC' );
        $start_month = gmdate( 'Y-m-01 00:00:00', strtotime( '-11 months', $month_base ) );
        $month_rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS ym, SUM(CASE WHEN is_lead = 0 THEN 1 ELSE 0 END) AS msgs, SUM(CASE WHEN is_lead = 1 THEN 1 ELSE 0 END) AS leads
             FROM $table_logs
             WHERE created_at >= %s
             GROUP BY DATE_FORMAT(created_at, '%%Y-%%m')",
            $start_month
        ), ARRAY_A );

        $month_map = array();
        foreach ( (array) $month_rows as $row ) {
            $month_map[ $row['ym'] ] = $row;
        }

        $y_labels   = array();
        $y_messages = array();
        $y_leads    = array();
        for ( $i = 11; $i >= 0; $i-- ) {
            $ts           = strtotime( "-$i months", $month_base );
            $ym           = gmdate( 'Y-m', $ts );
            $y_labels[]   = gmdate( 'M Y', $ts );
            $y_messages[] = isset( $month_map[ $ym ] ) ? (int) $month_map[ $ym ]['msgs'] : 0;
            $y_leads[]    = isset( $month_map[ $ym ] ) ? (int) $month_map[ $ym ]['leads'] : 0;
        }

        return array(
            'monthly' => array(
                'labels'   => $m_labels,
                'messages' => $m_messages,
                'leads'    => $m_leads,
            ),
            'yearly'  => array(
                'labels'   => $y_labels,
                'messages' => $y_messages,
                'leads'    => $y_leads,
            ),
        );
    }

    public function get_localized_inquiry_taxonomy( $lang ) {
        $taxonomies = array(
            'Swedish' => array(
                'support' => array(
                    'Mätning & Storlekshjälp'  => array( 'hur mäter', 'mått', 'mäta', 'storlek', 'bredd', 'höjd', 'karmyttermått', 'modulmått', 'väggöppning', 'mätguide' ),
                    'Montering & Installation' => array( 'montering', 'montera', 'installera', 'karmhylsa', 'karmskruv', 'karmnyckel', 'drevning', 'justera', 'sätta in' ),
                    'Reklamation & Ångerrätt'  => array( 'reklamation', 'reklamera', 'ångerrätt', 'retur', 'skada', 'trasig', 'fel på', 'byta' ),
                    'Köpvillkor & Garanti'     => array( 'villkor', 'köpvillkor', 'garanti', 'garantitid', 'försäkring', 'trygghet' ),
                    'Leverans & Frakt'         => array( 'leverans', 'frakt', 'leveranstid', 'hemleverans', 'spåra', 'transport', 'fraktkostnad', 'när kommer' ),
                    'Betalning & Finansiering' => array( 'betalning', 'faktura', 'delbetalning', 'klarna', 'kort', 'ränta', 'betala', 'rot', 'rotavdrag' ),
                    'Underhåll & Skötsel'      => array( 'underhåll', 'måla', 'rengöra', 'putsa', 'kondens', 'imma', 'smörja', 'skötsel' ),
                    'Orderstatus & Kundservice'=> array( 'order', 'beställning', 'kundtjänst', 'kontakt', 'offert', 'ordernummer', 'partner', 'samarbete' ),
                ),
                'products' => array(
                    '3-glasfönster & Energi'   => array( '3-glas', 'treglas', 'energifönster', 'isolerglas', 'lågt u-värde' ),
                    'Billigaste / Budgetval'   => array( 'billig', 'billigast', 'budget', 'prisvärd', 'lägst pris', 'kampanj', 'rea', '2-glas', 'tvåglas' ),
                    'Bäst U-värde & Isolering' => array( 'u-värde', 'u värde', 'isolering', 'energiklass', 'värme', 'passivhus' ),
                    'Altandörrar & Ytterdörrar'=> array( 'altandörr', 'balkongdörr', 'ytterdörr', 'terrassdörr', 'pardörr', 'dörr' ),
                    'Skjutdörrar & Vikpartier' => array( 'skjutdörr', 'vikdörr', 'skjutparti', 'vikparti', 'uterum' ),
                    'Fasta vs. Öppningsbara'   => array( 'fast fönster', 'fasta', 'öppningsbar', 'vridfönster', 'sidohängt', 'överhängt', 'källarfönster' ),
                    'Material: Trä / Alu / PVC'=> array( 'trä/alu', 'trä-alu', 'aluminium', 'träfönster', 'pvc', 'komposit', 'underhållsfri' ),
                    'Tillval: Spröjs & Glas'   => array( 'spröjs', 'spaltventil', 'ventil', 'handtag', 'frostat', 'härdat', 'laminerat', 'solskydd', 'persienn' ),
                ),
            ),
            'English' => array(
                'support' => array(
                    'Measurements & Sizing Help' => array( 'measure', 'size', 'sizing', 'dimension', 'width', 'height', 'opening', 'frame size', 'how to measure' ),
                    'Installation & Fitting'     => array( 'install', 'installation', 'fitting', 'mount', 'frame screw', 'adjust', 'hinge' ),
                    'Claims & Returns'           => array( 'return', 'claim', 'refund', 'damaged', 'broken', 'defect', 'exchange', 'complaint' ),
                    'Terms & Warranty'           => array( 'warranty', 'guarantee', 'terms', 'conditions', 'policy', 'coverage' ),
                    'Delivery & Shipping'        => array( 'delivery', 'shipping', 'freight', 'lead time', 'track', 'dispatch', 'when will' ),
                    'Payment & Financing'        => array( 'payment', 'invoice', 'finance', 'installment', 'klarna', 'card', 'pay' ),
                    'Maintenance & Care'         => array( 'maintenance', 'clean', 'condensation', 'paint', 'care', 'lubricate' ),
                    'Order Status & Partnerships'=> array( 'order', 'status', 'quote', 'support', 'contact', 'partner', 'partnership' ),
                ),
                'products' => array(
                    'Triple Glazed Windows'      => array( 'triple', '3-glass', '3-pane', 'triple glazed' ),
                    'Budget & Cheapest Options'  => array( 'cheap', 'cheapest', 'budget', 'affordable', 'lowest price', 'sale', '2-glass', 'double glazed' ),
                    'Best U-Value & Efficiency'  => array( 'u-value', 'u value', 'energy', 'insulation', 'thermal', 'passive' ),
                    'Patio & Exterior Doors'     => array( 'patio door', 'balcony door', 'exterior door', 'front door', 'french door', 'door' ),
                    'Sliding & Folding Doors'    => array( 'sliding', 'bifold', 'folding', 'conservatory' ),
                    'Fixed vs. Opening Windows'  => array( 'fixed', 'casement', 'tilt', 'turn', 'top swing', 'awning', 'basement' ),
                    'Materials: Wood / Alu / PVC'=> array( 'wood', 'timber', 'aluminum', 'aluminium', 'clad', 'pvc', 'upvc', 'composite' ),
                    'Add-ons: Grilles & Glass'   => array( 'grille', 'muntin', 'trickle vent', 'handle', 'frosted', 'tempered', 'laminated', 'solar' ),
                ),
            ),
            'Norwegian' => array(
                'support' => array(
                    'Måling & Størrelseshjelp'   => array( 'mål', 'måle', 'størrelse', 'bredde', 'høyde', 'karmmål', 'modulmål' ),
                    'Montering & Installasjon'   => array( 'montering', 'montere', 'installere', 'karmskrue', 'justere' ),
                    'Reklamasjon & Angrerett'    => array( 'reklamasjon', 'angrerett', 'retur', 'skade', 'feil', 'bytte' ),
                    'Kjøpsvilkår & Garanti'      => array( 'vilkår', 'kjøpsvilkår', 'garanti' ),
                    'Levering & Frakt'           => array( 'levering', 'frakt', 'leveringstid', 'transport' ),
                    'Betaling & Finansiering'    => array( 'betaling', 'faktura', 'delbetaling', 'klarna' ),
                    'Vedlikehold & Pleie'        => array( 'vedlikehold', 'kondens', 'male', 'vaske' ),
                    'Ordrestatus & Kundeservice' => array( 'ordre', 'bestilling', 'tilbud', 'kontakt', 'partner' ),
                ),
                'products' => array(
                    '3-lags Vinduer & Energi'    => array( '3-lags', 'trelags', 'energiglass', 'isolasjon' ),
                    'Billigste / Budsjettvalg'   => array( 'billig', 'billigst', 'budsjett', 'tilbud', '2-lags' ),
                    'Best U-verdi & Isolering'   => array( 'u-verdi', 'u verdi', 'energi', 'passivhus' ),
                    'balkong- & Ytterdører'      => array( 'balkongdør', 'terrassedør', 'ytterdør', 'dør' ),
                    'Skyvedører & Foldedører'    => array( 'skyvedør', 'foldedør' ),
                    'Faste vs. Åpningsbare'      => array( 'fastkarm', 'toppsving', 'sidehengslet', 'kjellervindu' ),
                    'Materiale: Tre / Alu / PVC' => array( 'tre/alu', 'aluminium', 'trevindu', 'pvc' ),
                    'Tilvalg: Sprosse & Ventil'  => array( 'sprosse', 'spalteventil', 'ventil', 'vrider', 'frostet', 'sikkerhetsglass' ),
                ),
            ),
            'Danish' => array(
                'support' => array(
                    'Opmåling & Størrelseshjælp' => array( 'mål', 'opmåling', 'størrelse', 'bredde', 'højde', 'karmmål' ),
                    'Montering & Installation'   => array( 'montering', 'montere', 'karmskrue', 'justering' ),
                    'Reklamation & Fortrydelse'  => array( 'reklamation', 'fortrydelsesret', 'retur', 'skade' ),
                    'Handelsbetingelser & Garanti'=> array( 'betingelser', 'vilkår', 'garanti' ),
                    'Levering & Fragt'           => array( 'levering', 'fragt', 'leveringstid' ),
                    'Betaling & Finansiering'    => array( 'betaling', 'faktura', 'afbetaling', 'klarna' ),
                    'Vedligeholdelse & Pleje'    => array( 'vedligeholdelse', 'kondens', 'maling' ),
                    'Ordrestatus & Kundeservice' => array( 'ordre', 'bestilling', 'tilbud', 'kontakt', 'partner' ),
                ),
                'products' => array(
                    '3-lags Vinduer & Energi'    => array( '3-lags', 'trelags', 'energirude' ),
                    'Billigste / Budgetvalg'     => array( 'billig', 'billigst', 'budget', '2-lags' ),
                    'Bedste U-værdi & Energi'    => array( 'u-værdi', 'energi', 'isolering' ),
                    'Terrasse- & Yderdøre'       => array( 'terrassedør', 'facadedør', 'yderdør', 'dør' ),
                    'Skydedøre & Foldedøre'      => array( 'skydedør', 'foldedør' ),
                    'Faste vs. Oplukkelige'      => array( 'fastkarm', 'topstyret', 'sidehængt', 'bondehusvindue' ),
                    'Materiale: Træ / Alu / PVC' => array( 'træ/alu', 'aluminium', 'træ', 'pvc' ),
                    'Tilvalg: Sprosser & Glas'   => array( 'sprosse', 'ventil', 'greb', 'matteret', 'sikkerhedsglas' ),
                ),
            ),
            'German' => array(
                'support' => array(
                    'Aufmaß & Größenhilfe'       => array( 'aufmaß', 'messen', 'maße', 'größe', 'breite', 'höhe' ),
                    'Montage & Einbau'           => array( 'montage', 'einbau', 'montieren', 'rahmenschraube', 'einstellen' ),
                    'Reklamation & Widerruf'     => array( 'reklamation', 'widerruf', 'rückgabe', 'schaden', 'defekt' ),
                    'AGB & Garantie'             => array( 'agb', 'garantie', 'bedingungen', 'gewährleistung' ),
                    'Lieferung & Versand'        => array( 'lieferung', 'versand', 'lieferzeit', 'spedition' ),
                    'Zahlung & Finanzierung'     => array( 'zahlung', 'rechnung', 'ratenzahlung', 'klarna' ),
                    'Pflege & Wartung'           => array( 'pflege', 'wartung', 'kondenswasser', 'reinigen' ),
                    'Bestellstatus & Kontakt'    => array( 'bestellung', 'status', 'angebot', 'kontakt', 'partner' ),
                ),
                'products' => array(
                    '3-fach Verglasung & Energie'=> array( '3-fach', 'dreifach', 'isolierglas' ),
                    'Günstigste / Budget-Option' => array( 'günstig', 'billig', 'budget', 'preiswert', '2-fach' ),
                    'Bester U-Wert & Dämmung'    => array( 'u-wert', 'uw-wert', 'dämmung', 'passivhaus' ),
                    'Terrassen- & Haustüren'     => array( 'terrassentür', 'balkontür', 'haustür', 'eingangstür', 'tür' ),
                    'Schiebe- & Falttüren'       => array( 'schiebetür', 'falttür', 'hebeschiebetür' ),
                    'Festverglast vs. Dreh-Kipp' => array( 'festverglast', 'dreh-kipp', 'kellerfenster' ),
                    'Material: Holz / Alu / PVC' => array( 'holz', 'alu', 'aluminium', 'kunststoff', 'pvc' ),
                    'Zubehör: Sprossen & Glas'   => array( 'sprossen', 'lüfter', 'griff', 'ornamentglas', 'sicherheitsglas' ),
                ),
            ),
            'Finnish' => array(
                'support' => array(
                    'Mittaus & Kokoneuvonta'     => array( 'mittaus', 'mitata', 'mitat', 'leveys', 'korkeus', 'karmimitta' ),
                    'Asennus & Kiinnitys'        => array( 'asennus', 'asentaa', 'karmiruuvi', 'säätö' ),
                    'Reklamaatio & Palautus'     => array( 'reklamaatio', 'palautus', 'peruutusoikeus', 'vaurio', 'viallinen' ),
                    'Toimitusehdot & Takuu'      => array( 'ehdot', 'toimitusehdot', 'takuu' ),
                    'Toimitus & Rahti'           => array( 'toimitus', 'rahti', 'toimitusaika', 'kuljetus' ),
                    'Maksu & Rahoitus'           => array( 'maksu', 'lasku', 'osamaksu', 'klarna' ),
                    'Huolto & Ylläpito'          => array( 'huolto', 'kondenssi', 'puhdistus', 'maalaus' ),
                    'Tilausseuranta & Asiakaspalvelu' => array( 'tilaus', 'tarjous', 'asiakaspalvelu', 'yhteystiedot', 'kumppani' ),
                ),
                'products' => array(
                    '3-lasiset Ikkunat'          => array( '3-lasinen', 'kolmilasinen', '3-lasi' ),
                    'Edullisimmat / Budjetti'    => array( 'halpa', 'edullinen', 'budjetti', '2-lasinen' ),
                    'Paras U-arvo & Energia'     => array( 'u-arvo', 'energia', 'eristys' ),
                    'Terassi- & Ulko-ovet'       => array( 'terassiovi', 'parvekeovi', 'ulko-ovi', 'ovi' ),
                    'Liuku- & Taitto-ovet'       => array( 'liukuovi', 'taitto-ovi' ),
                    'Kiinteät vs. Avattavat'     => array( 'kiinteä', 'avattava', 'kellarinikkuna' ),
                    'Materiaalit: Puu / Alu / PVC'=> array( 'puu', 'alumiini', 'puu-alumiini', 'pvc' ),
                    'Lisävarusteet: Ristikot'    => array( 'ristikko', 'turvalasi', 'venttiili', 'painike' ),
                ),
            ),
        );

        return isset( $taxonomies[ $lang ] ) ? $taxonomies[ $lang ] : $taxonomies['Swedish'];
    }

    /**
     * Compute Normalized Dual Top Inquiries (Support vs Products) strictly in selected language.
     * Returns only categories with count > 0 so there is zero pre-filled dummy data.
     */
    private function get_dual_top_inquiries() {
        global $wpdb;
        $table_logs = $wpdb->prefix . 'fg_ai_logs';
        $lang       = get_option( 'advisio_language', 'Swedish' );
        $taxonomy   = $this->get_localized_inquiry_taxonomy( $lang );

        $raw_messages = $wpdb->get_col( "SELECT user_message FROM $table_logs WHERE is_lead = 0 ORDER BY id DESC LIMIT 500" );

        $support_counts = array();
        foreach ( $taxonomy['support'] as $label => $kw_list ) {
            $support_counts[ $label ] = array( 'keywords' => $kw_list, 'count' => 0 );
        }

        $product_counts = array();
        foreach ( $taxonomy['products'] as $label => $kw_list ) {
            $product_counts[ $label ] = array( 'keywords' => $kw_list, 'count' => 0 );
        }

        if ( ! empty( $raw_messages ) ) {
            foreach ( $raw_messages as $msg ) {
                $lower = mb_strtolower( $msg, 'UTF-8' );
                foreach ( $support_counts as $cat => &$data ) {
                    foreach ( $data['keywords'] as $kw ) {
                        if ( false !== mb_strpos( $lower, $kw, 0, 'UTF-8' ) ) {
                            $data['count']++;
                            break;
                        }
                    }
                }
                unset( $data );

                foreach ( $product_counts as $cat => &$data ) {
                    foreach ( $data['keywords'] as $kw ) {
                        if ( false !== mb_strpos( $lower, $kw, 0, 'UTF-8' ) ) {
                            $data['count']++;
                            break;
                        }
                    }
                }
                unset( $data );
            }
        }

        $active_support = array_filter( $support_counts, function( $item ) {
            return $item['count'] > 0;
        } );
        $active_products = array_filter( $product_counts, function( $item ) {
            return $item['count'] > 0;
        } );

        uasort( $active_support, function( $a, $b ) {
            return $b['count'] <=> $a['count'];
        } );
        uasort( $active_products, function( $a, $b ) {
            return $b['count'] <=> $a['count'];
        } );

        return array(
            'support'  => $active_support,
            'products' => $active_products,
        );
    }

    /**
     * Format detailed delta status badge string for Shop or Content.
     */
    private function format_delta_status_text( $scope_label, $stat_option, $current_db_count ) {
        if ( empty( $stat_option ) || ! is_array( $stat_option ) || empty( $stat_option['time'] ) ) {
            return $scope_label . ' Status: Not indexed yet';
        }
        $delta     = isset( $stat_option['delta'] ) ? intval( $stat_option['delta'] ) : 0;
        $total     = isset( $stat_option['total'] ) ? intval( $stat_option['total'] ) : $current_db_count;
        $date_part = date( 'Y-m-d', strtotime( $stat_option['time'] ) );
        $time_part = date( 'H:i', strtotime( $stat_option['time'] ) );

        return sprintf(
            '%s Status: Completed — +%d new items synced (Total: %d items) on %s at %s',
            $scope_label,
            $delta,
            $total,
            $date_part,
            $time_part
        );
    }

    /**
     * Render the 6-Tab Admin UI.
     */
    public function admin_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        advisio_ensure_tables_ready();

        global $wpdb;
        $table_logs      = $wpdb->prefix . 'fg_ai_logs';
        $table_knowledge = $wpdb->prefix . 'fg_ai_knowledge';
        $table_proposals = $wpdb->prefix . 'fg_ai_proposals';

        // Guarantee manual knowledge from the wp_options backup is always mirrored into the AI memory table
        self::maybe_resync_manual_knowledge();

        // KPI Metrics
        $msg_today         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_logs WHERE DATE(created_at) = CURDATE() AND is_lead = 0" );
        $msg_week          = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_logs WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1) AND is_lead = 0" );
        $leads_total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_logs WHERE is_lead = 1" );
        $pending_proposals = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_proposals WHERE status = 'pending'" );
        $total_sessions    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT session_id) FROM $table_logs WHERE is_lead = 0" );
        $last_analyzed_cnt = (int) get_option( 'advisio_last_analyzed_session_count', 0 );
        $sessions_until_50 = max( 0, 50 - ( $total_sessions - $last_analyzed_cnt ) );

        // AI Memory breakdown (single grouped query)
        $mem_stats = $this->get_memory_stats();

        // Index Timestamps, Delta Counts & Totals
        $shop_stat    = get_option( 'advisio_last_shop_index', false );
        $content_stat = get_option( 'advisio_last_content_index', false );
        $shop_count   = (int) $mem_stats['shop'];
        $cont_count   = (int) $mem_stats['content'] + (int) $mem_stats['global_site'];
        $cust_count   = (int) $mem_stats['manual'] + (int) $mem_stats['file'];

        $shop_detailed_status    = $this->format_delta_status_text( 'Shop', $shop_stat, $shop_count );
        $content_detailed_status = $this->format_delta_status_text( 'Content', $content_stat, $cont_count );

        $shop_short_txt = $shop_stat
            ? sprintf( '+%d new (Total: %d) on %s', isset( $shop_stat['delta'] ) ? $shop_stat['delta'] : 0, $shop_count, date( 'Y-m-d H:i', strtotime( $shop_stat['time'] ) ) )
            : 'Not indexed yet';
        $content_short_txt = $content_stat
            ? sprintf( '+%d new (Total: %d) on %s', isset( $content_stat['delta'] ) ? $content_stat['delta'] : 0, $cont_count, date( 'Y-m-d H:i', strtotime( $content_stat['time'] ) ) )
            : 'Not indexed yet';

        // Dual Top Inquiries (Single Language, No Dummy Data)
        $dual_inquiries = $this->get_dual_top_inquiries();

        // Proposals
        $proposals = $wpdb->get_results( "SELECT * FROM $table_proposals WHERE status = 'pending' ORDER BY id DESC" );

        // Uploaded reference files (scope = 'file', plus legacy 'custom' uploads)
        $custom_files = $wpdb->get_results( "SELECT id, title, source_type, last_indexed FROM $table_knowledge WHERE scope IN ('file', 'custom') AND source_type = 'manual_file' ORDER BY id DESC" );

        // Last Gemini API error (diagnostics)
        $last_api_error = get_option( 'advisio_last_api_error', array() );

        // Lead Form Fields
        $lead_fields_raw = get_option( 'advisio_lead_form_fields', '' );
        if ( is_array( $lead_fields_raw ) ) {
            $lead_fields     = $lead_fields_raw;
            $lead_fields_raw = wp_json_encode( $lead_fields );
        } else {
            $lead_fields = ( is_string( $lead_fields_raw ) && '' !== trim( $lead_fields_raw ) ) ? json_decode( $lead_fields_raw, true ) : null;
        }
        if ( ! is_array( $lead_fields ) || empty( $lead_fields ) ) {
            $lead_fields     = self::get_default_lead_fields();
            $lead_fields_raw = wp_json_encode( $lead_fields );
        }
        ?>

        <!-- Isolate any residual WP admin notices above our workspace so they never cover or break the UI -->
        <div class="wrap advisio-wp-notice-catcher">
            <h1 class="wp-heading-inline" style="display:none;">Advisio AI</h1>
            <hr class="wp-header-end">
        </div>

        <div class="advisio-admin-wrap" id="advisio-admin-wrap">
            <!-- GLOBAL HEADER -->
            <div class="advisio-header">
                <div class="advisio-header-content">
                    <div class="advisio-brand-row">
                        <div class="advisio-brand-title" role="heading" aria-level="1">Advisio AI</div>
                        <span class="advisio-demo-badge">(DEMO)</span>
                    </div>
                    <p class="subtitle">Advisio AI chat service — by Area 404</p>
                </div>
                <div class="advisio-theme-toggle">
                    <label class="theme-switch" title="Switch Dark / Light Mode">
                        <input type="checkbox" id="advisio-theme-checkbox">
                        <span class="slider round"></span>
                    </label>
                    <span class="theme-label" id="theme-label-text">🌙 Dark Mode / ☀️ Light Mode</span>
                </div>
            </div>

            <?php if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) : ?>
                <div class="advisio-save-banner">
                    <span>✓ Advisio AI configuration saved successfully.</span>
                </div>
            <?php endif; ?>

            <form action="options.php" method="post" id="advisio-unified-form" enctype="multipart/form-data">
                <?php settings_fields( 'advisio_ai_settings' ); ?>

                <!-- 6 NAVIGATION TABS -->
                <div class="advisio-nav-tabs" id="advisio-tabs">
                    <button type="button" class="advisio-tab-btn active" data-tab="tab-dashboard">
                        📊 1. Dashboard &amp; AI Insights
                        <?php if ( $pending_proposals > 0 ) : ?>
                            <span class="advisio-tab-pill"><?php echo intval( $pending_proposals ); ?></span>
                        <?php endif; ?>
                    </button>
                    <button type="button" class="advisio-tab-btn" data-tab="tab-setup">⚙️ 2. Setup &amp; Modular Knowledge Base</button>
                    <button type="button" class="advisio-tab-btn" data-tab="tab-persona">🧠 3. AI Behavior, Hierarchy &amp; Constraints</button>
                    <button type="button" class="advisio-tab-btn" data-tab="tab-widget">🎨 4. Widget Design &amp; Live Preview</button>
                    <button type="button" class="advisio-tab-btn" data-tab="tab-lead">📋 5. Leads &amp; Dynamic Form Builder</button>
                    <button type="button" class="advisio-tab-btn" data-tab="tab-docs">📖 6. Documentation</button>
                </div>

                <div class="advisio-tab-content-container">

                    <!-- ==========================================
                         TAB 1: DASHBOARD & AI INSIGHTS
                         ========================================== -->
                    <div id="tab-dashboard" class="advisio-tab-content active">
                        <!-- 1. KPI Metric Cards -->
                        <div class="advisio-kpi-grid">
                            <div class="advisio-kpi-card">
                                <h4>Messages Today / This Week</h4>
                                <div class="kpi-value">
                                    <?php echo intval( $msg_today ); ?>
                                    <span class="kpi-sub-divider">/</span>
                                    <span class="kpi-week-val"><?php echo intval( $msg_week ); ?></span>
                                </div>
                                <div class="kpi-caption">Today vs. Current Week Volume</div>
                            </div>

                            <div class="advisio-kpi-card">
                                <h4>Total Leads Captured</h4>
                                <div class="kpi-value text-emerald"><?php echo intval( $leads_total ); ?></div>
                                <div class="kpi-caption">Qualified Callback &amp; Quote Submissions</div>
                            </div>

                            <div class="advisio-kpi-card kpi-glow-card">
                                <h4>Pending AI Strategy Proposals</h4>
                                <div class="kpi-value text-cyan" id="kpi-pending-proposals"><?php echo intval( $pending_proposals ); ?></div>
                                <div class="kpi-caption">Next Auto-Learning Batch in <?php echo intval( $sessions_until_50 ); ?> Sessions</div>
                            </div>

                            <div class="advisio-kpi-card">
                                <h4>Index Status Overview</h4>
                                <div class="kpi-index-lines">
                                    <div><strong>🛒 Shop:</strong> <span id="dash-shop-idx"><?php echo esc_html( $shop_short_txt ); ?></span></div>
                                    <div><strong>📄 Content:</strong> <span id="dash-content-idx"><?php echo esc_html( $content_short_txt ); ?></span></div>
                                    <div><strong>📁 Custom Docs:</strong> <span><?php echo intval( $cust_count ); ?> active sources</span></div>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Dual Top Inquiries Analytics (2-Column Grid Inside Each Block, Active Language Only) -->
                        <div class="advisio-layout-split">
                            <div class="advisio-card">
                                <h2>🛠️ Top Inquiries: Support</h2>
                                <p class="desc">Normalized customer support topics in your selected language (Measurements, Installation, Claims/Returns, Warranty, Shipping, Payment).</p>
                                <?php if ( empty( $dual_inquiries['support'] ) ) : ?>
                                    <div class="advisio-empty-inquiries">
                                        <p>No support inquiries recorded yet. Categories will populate organically in a 2-column grid as customers start chatting.</p>
                                    </div>
                                <?php else : ?>
                                    <div class="advisio-inquiries-2col-grid">
                                        <?php
                                        $max_sup = 1;
                                        foreach ( $dual_inquiries['support'] as $c_data ) {
                                            if ( $c_data['count'] > $max_sup ) {
                                                $max_sup = $c_data['count'];
                                            }
                                        }
                                        foreach ( $dual_inquiries['support'] as $cat_label => $c_data ) :
                                            $pct = round( ( $c_data['count'] / $max_sup ) * 100 );
                                            ?>
                                            <div class="advisio-inquiry-grid-item">
                                                <div class="advisio-metric-head">
                                                    <span><?php echo esc_html( $cat_label ); ?></span>
                                                    <span class="advisio-metric-count"><?php echo intval( $c_data['count'] ); ?></span>
                                                </div>
                                                <div class="advisio-metric-track">
                                                    <div class="advisio-metric-fill fill-cyan" style="width: <?php echo intval( $pct ); ?>%;"></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="advisio-card">
                                <h2>🪟 Top Inquiries: Products</h2>
                                <p class="desc">Commercial and buyer-intent inquiries regarding specific models, prices, U-values, glazing, materials, and comparisons.</p>
                                <?php if ( empty( $dual_inquiries['products'] ) ) : ?>
                                    <div class="advisio-empty-inquiries">
                                        <p>No product inquiries recorded yet. Categories will populate organically in a 2-column grid as customers ask about windows and doors.</p>
                                    </div>
                                <?php else : ?>
                                    <div class="advisio-inquiries-2col-grid">
                                        <?php
                                        $max_prod = 1;
                                        foreach ( $dual_inquiries['products'] as $p_data ) {
                                            if ( $p_data['count'] > $max_prod ) {
                                                $max_prod = $p_data['count'];
                                            }
                                        }
                                        foreach ( $dual_inquiries['products'] as $cat_label => $p_data ) :
                                            $pct = round( ( $p_data['count'] / $max_prod ) * 100 );
                                            ?>
                                            <div class="advisio-inquiry-grid-item">
                                                <div class="advisio-metric-head">
                                                    <span><?php echo esc_html( $cat_label ); ?></span>
                                                    <span class="advisio-metric-count"><?php echo intval( $p_data['count'] ); ?></span>
                                                </div>
                                                <div class="advisio-metric-track">
                                                    <div class="advisio-metric-fill fill-indigo" style="width: <?php echo intval( $pct ); ?>%;"></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 3. Automated 50-Chat AI Learning Engine ("AI Strategy Proposals") -->
                        <div class="advisio-card advisio-proposals-section">
                            <div class="advisio-card-header-flex">
                                <div>
                                    <h2>🤖 Automated 50-Chat AI Learning Engine — Strategy Proposals</h2>
                                    <p class="desc">Every 50 completed chat sessions, Gemini 3.5 Flash Lite analyzes conversation logs and inquiry trends to generate 2–5 actionable strategy improvements. Approve a proposal to inject it directly into your active AI prompt block.</p>
                                </div>
                                <div>
                                    <button type="button" id="btn-run-learning-now" class="advisio-btn advisio-btn-primary">⚡ Analyze &amp; Generate Proposals Now</button>
                                </div>
                            </div>
                            <div id="learning-engine-status"></div>

                            <div id="advisio-proposals-grid" class="advisio-proposals-grid">
                                <?php if ( empty( $proposals ) ) : ?>
                                    <div class="advisio-empty-proposals" id="no-proposals-placeholder">
                                        <p>No pending AI Strategy Proposals waiting for review. Proposals are generated automatically every 50 chat sessions, or you can click <strong>"Analyze &amp; Generate Proposals Now"</strong> above to inspect recent conversations immediately.</p>
                                    </div>
                                <?php else : ?>
                                    <?php foreach ( $proposals as $prop ) : ?>
                                        <div class="advisio-proposal-card" id="proposal-card-<?php echo intval( $prop->id ); ?>">
                                            <div class="proposal-meta">
                                                <span class="proposal-badge"><?php echo esc_html( $prop->category ); ?></span>
                                                <span class="proposal-date"><?php echo esc_html( date( 'Y-m-d H:i', strtotime( $prop->created_at ) ) ); ?></span>
                                            </div>
                                            <p class="proposal-rationale"><em>"<?php echo esc_html( $prop->rationale ); ?>"</em></p>
                                            <div class="proposal-rule-box">
                                                <strong>Proposed Strategy Rule:</strong>
                                                <p><?php echo esc_html( $prop->proposal_text ); ?></p>
                                            </div>
                                            <div class="proposal-actions">
                                                <button type="button" class="advisio-btn advisio-btn-approve" data-id="<?php echo intval( $prop->id ); ?>" data-action="approve">✓ Approve</button>
                                                <button type="button" class="advisio-btn advisio-btn-reject" data-id="<?php echo intval( $prop->id ); ?>" data-action="reject">✕ Reject</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Concise Quick-Start Overview -->
                        <div class="advisio-card">
                            <div class="advisio-card-header-flex">
                                <div>
                                    <h2>🚀 Concise Quick-Start Overview</h2>
                                    <p class="desc">Get Advisio AI running in 4 streamlined steps. For deep technical walkthroughs, visit the Documentation tab.</p>
                                </div>
                                <button type="button" class="advisio-btn advisio-btn-secondary advisio-jump-tab" data-target-tab="tab-docs">📖 Open Full Documentation (Tab 6) &rarr;</button>
                            </div>
                            <div class="advisio-quickstart-grid">
                                <div class="qs-card">
                                    <span class="qs-step">Step 1</span>
                                    <h4>Gemini API &amp; Language</h4>
                                    <p>In <strong>Tab 2 (Setup)</strong>, enter your Google AI Studio API Key (supports <code>AIza</code> and <code>AQ.</code> keys) powered by <strong>Gemini Flash Lite</strong>, and select your primary language.</p>
                                </div>
                                <div class="qs-card">
                                    <span class="qs-step">Step 2</span>
                                    <h4>5-Stage Deep Crawler</h4>
                                    <p>Run <strong>Full Index</strong> or <strong>Quick Sync</strong> in Tab 2 to index Startsida, Footer, Menus, Subpages, and Products with crash-proof micro-batches and live stats.</p>
                                </div>
                                <div class="qs-card">
                                    <span class="qs-step">Step 3</span>
                                    <h4>Unified Live Memory Bank</h4>
                                    <p>Add custom knowledge texts or upload reference files (<code>.txt</code>, <code>.csv</code>, <code>.md</code>, <code>.json</code>) in Tab 2 with instant live activation—no pending staging required.</p>
                                </div>
                                <div class="qs-card">
                                    <span class="qs-step">Step 4</span>
                                    <h4>The Brain &amp; AI Learning</h4>
                                    <p>Configure Advisio's 4-section Master Brain in <strong>Tab 3</strong> via natural language, review qualified leads in <strong>Tab 5</strong>, and approve 50-chat strategy proposals in Tab 1.</p>
                                </div>
                            </div>
                        </div>

                        <!-- 4. Performance Graph with Monthly / Yearly Switcher (Placed right above Save Settings) -->
                        <div class="advisio-card">
                            <div class="advisio-card-header-flex">
                                <div>
                                    <h2 id="advisio-chart-heading">📈 Performance Graph — Monthly View (Last 30 Days)</h2>
                                    <p class="desc">Dual-metric tracking of Total Chat Messages processed vs. Qualified Leads Captured (Monthly = 1 Month daily view | Yearly = 12 Months monthly view).</p>
                                </div>
                                <div class="advisio-chart-switcher">
                                    <button type="button" class="advisio-btn advisio-chart-mode-btn active" data-chart-mode="monthly">[ Monthly View (1 Month) ]</button>
                                    <button type="button" class="advisio-btn advisio-chart-mode-btn" data-chart-mode="yearly">[ Yearly View (12 Months) ]</button>
                                </div>
                            </div>
                            <div class="advisio-chart-container">
                                <canvas id="advisioMonthlyChart" height="92"></canvas>
                            </div>
                        </div>
                    </div>
                                        <!-- ==========================================
                         TAB 2: SETUP & MODULAR KNOWLEDGE BASE
                         ========================================== -->
                    <div id="tab-setup" class="advisio-tab-content">
                        <?php
                        $mem_stats       = $this->get_memory_stats();
                        $live_custom     = $this->get_live_custom_knowledge();
                        $manual_count    = count( $live_custom );
                        $content_footer  = intval( $mem_stats['content'] ) + intval( $mem_stats['global_site'] );
                        $cur_lang        = get_option( 'advisio_language', 'Swedish' );
                        $api_key         = get_option( 'advisio_api_key', '' );
                        $active_model    = get_option( 'advisio_active_model', 'gemini-3.5-flash-lite' );
                        $last_sync_stats = get_option( 'advisio_last_index_stats', array() );
                        ?>

                        <!-- ROW 1: MEMORY QUICK-FACT COUNTER CARDS (5-CARD SYMMETRICAL ICON GRID) -->
                        <div class="advisio-memory-summary-grid" id="advisio-memory-summary-card">
                            <div class="mem-counter-card mem-card-total">
                                <div class="mem-card-icon">🧠</div>
                                <div class="mem-card-data">
                                    <span class="mem-card-val" id="mem-stat-total"><?php echo intval( $mem_stats['total'] ); ?></span>
                                    <span class="mem-card-lbl">Totalt i Minnet</span>
                                </div>
                            </div>
                            <div class="mem-counter-card mem-card-live">
                                <div class="mem-card-icon">🟢</div>
                                <div class="mem-card-data">
                                    <span class="mem-card-val" id="mem-stat-live"><?php echo intval( $mem_stats['total'] ); ?></span>
                                    <span class="mem-card-lbl">Live i Minnesbanken</span>
                                </div>
                            </div>
                            <div class="mem-counter-card mem-card-pending">
                                <div class="mem-card-icon">📚</div>
                                <div class="mem-card-data">
                                    <span class="mem-card-val" id="mem-stat-pending" data-manual-val="<?php echo intval( $manual_count ); ?>"><?php echo intval( $manual_count ); ?></span>
                                    <span class="mem-card-lbl">Manuell Kunskap &amp; Filer</span>
                                </div>
                            </div>
                            <div class="mem-counter-card mem-card-content">
                                <div class="mem-card-icon">📄</div>
                                <div class="mem-card-data">
                                    <span class="mem-card-val" id="mem-stat-content"><?php echo intval( $content_footer ); ?></span>
                                    <span class="mem-card-lbl">Sidor, Menyer &amp; Footer</span>
                                </div>
                            </div>
                            <div class="mem-counter-card mem-card-shop">
                                <div class="mem-card-icon">🛒</div>
                                <div class="mem-card-data">
                                    <span class="mem-card-val" id="mem-stat-shop"><?php echo intval( $mem_stats['shop'] ); ?></span>
                                    <span class="mem-card-lbl">Produkter &amp; Kategorier</span>
                                </div>
                            </div>
                        </div>

                        <!-- ROW 2: 2-COLUMN SYMMETRICAL SPLIT (API & LANGUAGE LEFT, 5-STAGE CRAWLER RIGHT) -->
                        <div class="advisio-layout-split advisio-row-mt">
                            <!-- Left Card: Gemini API Configuration & Customer Language -->
                            <div class="advisio-card advisio-card-stretch">
                                <div class="advisio-card-header-clean">
                                    <div class="header-icon-title">
                                        <span class="header-icon">🔑</span>
                                        <div>
                                            <h2>Gemini API &amp; Språkinställningar</h2>
                                            <p class="desc">Koppla upp Google Gemini Flash Lite och ange AI:ns primära kundspråk.</p>
                                        </div>
                                    </div>
                                    <span class="fg-model-badge" id="active-model-badge">Engine: <?php echo esc_html( $active_model ); ?></span>
                                </div>

                                <div class="advisio-form-group">
                                    <label class="advisio-field-label" for="advisio_language">🌍 Kundens svarspråk &amp; Analysspråk</label>
                                    <select name="advisio_language" id="advisio_language" class="advisio-input advisio-select-full">
                                        <option value="Swedish" <?php selected( $cur_lang, 'Swedish' ); ?>>🇸🇪 Svenska (Swedish — Standard)</option>
                                        <option value="English" <?php selected( $cur_lang, 'English' ); ?>>🇬🇧 English</option>
                                        <option value="Norwegian" <?php selected( $cur_lang, 'Norwegian' ); ?>>🇳🇴 Norsk (Norwegian)</option>
                                        <option value="Danish" <?php selected( $cur_lang, 'Danish' ); ?>>🇩🇰 Dansk (Danish)</option>
                                        <option value="German" <?php selected( $cur_lang, 'German' ); ?>>🇩🇪 Deutsch (German)</option>
                                        <option value="Finnish" <?php selected( $cur_lang, 'Finnish' ); ?>>🇫🇮 Suomi (Finnish)</option>
                                    </select>
                                    <p class="desc helper-text">Styr AI-svarens språk och kategorisering av kundfrågor i Flik 1.</p>
                                </div>

                                <div class="advisio-form-group advisio-group-mt">
                                    <label class="advisio-field-label" for="advisio_api_key">🔑 Google Gemini API-nyckel (AI Studio)</label>
                                    <div class="api-key-row">
                                        <input type="password" name="advisio_api_key" id="advisio_api_key" value="<?php echo esc_attr( $api_key ); ?>" placeholder="Klistra in din AI Studio API-nyckel här..." class="advisio-input">
                                        <button type="button" id="toggle-api-key" class="advisio-btn advisio-btn-secondary" title="Visa / Dölj API-nyckel">👁️</button>
                                        <button type="button" id="btn-test-api" class="advisio-btn advisio-btn-primary">Save &amp; Test API Key</button>
                                    </div>
                                    <div id="api-test-result" style="margin-top:10px;"></div>
                                </div>

                                <div class="advisio-callout-box advisio-group-mt">
                                    <strong>🛡️ Deterministisk &amp; Hallucinationssäker</strong>
                                    <p>Anropen körs låsta med <code>temperature: 0.2</code> och <code>topP: 0.8</code> för att garantera 100% faktaenlighet mot din butiks data.</p>
                                </div>
                            </div>

                            <!-- Right Card: 5-Stage Sequential Website Deep Crawler -->
                            <div class="advisio-card advisio-card-stretch" id="advisio-crawler-card">
                                <div class="advisio-card-header-clean">
                                    <div class="header-icon-title">
                                        <span class="header-icon">🕷️</span>
                                        <div>
                                            <h2>5-Stegs Sekventiell Webbplatscrawler</h2>
                                            <p class="desc">Crash-proof indexering i 5 sekventiella steg. 1–3 objekt/batch.</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Scope Action Buttons -->
                                <div class="crawler-actions-grid">
                                    <div class="crawler-action-tile primary-tile">
                                        <div class="tile-info">
                                            <strong>🌐 Hela Webbplatsen (Steg 1–5)</strong>
                                            <span>Startsida &rarr; Footer &rarr; Menyer &rarr; Undersidor &rarr; Produkter</span>
                                        </div>
                                        <div class="tile-buttons">
                                            <button type="button" id="btn-crawl-website" class="advisio-btn advisio-btn-primary" data-scope="all">🌐 Full Indexering</button>
                                            <button type="button" id="btn-quick-sync" class="advisio-btn advisio-btn-secondary" data-scope="all" title="Endast nya eller uppdaterade sidor och produkter">⚡ Snappsynk</button>
                                        </div>
                                    </div>

                                    <div class="crawler-subtiles-row">
                                        <div class="crawler-subtile">
                                            <span class="subtile-name">🛒 Endast Butik (Steg 5)</span>
                                            <div class="subtile-btns">
                                                <button type="button" class="advisio-btn advisio-btn-sm" id="btn-crawl-shop" data-scope="shop">Indexera Shop</button>
                                                <button type="button" class="advisio-btn advisio-btn-sm advisio-btn-secondary" id="btn-sync-shop" data-scope="shop">Synka Shop</button>
                                            </div>
                                        </div>
                                        <div class="crawler-subtile">
                                            <span class="subtile-name">📄 Endast Innehåll (Steg 1–4)</span>
                                            <div class="subtile-btns">
                                                <button type="button" class="advisio-btn advisio-btn-sm" id="btn-crawl-content" data-scope="content">Indexera Sidor</button>
                                                <button type="button" class="advisio-btn advisio-btn-sm advisio-btn-secondary" id="btn-sync-content" data-scope="content">Synka Sidor</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Visual 5-Stage Step Tracker -->
                                <div class="advisio-crawl-tracker" id="advisio-crawl-tracker">
                                    <div class="crawl-stage-row">
                                        <div class="crawl-stage" id="stage-pill-1" data-stage="1">
                                            <span class="crawl-stage-num">1</span>
                                            <span class="crawl-stage-name">Startsida</span>
                                        </div>
                                        <div class="crawl-stage" id="stage-pill-2" data-stage="2">
                                            <span class="crawl-stage-num">2</span>
                                            <span class="crawl-stage-name">Footer</span>
                                        </div>
                                        <div class="crawl-stage" id="stage-pill-3" data-stage="3">
                                            <span class="crawl-stage-num">3</span>
                                            <span class="crawl-stage-name">Menyer</span>
                                        </div>
                                        <div class="crawl-stage" id="stage-pill-4" data-stage="4">
                                            <span class="crawl-stage-num">4</span>
                                            <span class="crawl-stage-name">Undersidor</span>
                                        </div>
                                        <div class="crawl-stage" id="stage-pill-5" data-stage="5">
                                            <span class="crawl-stage-num">5</span>
                                            <span class="crawl-stage-name">Produkter</span>
                                        </div>
                                    </div>

                                    <div class="crawl-progress-line">
                                        <div class="advisio-progress-container">
                                            <div class="advisio-progress-fill" id="crawl-progress-fill" style="width:0%;"></div>
                                        </div>
                                        <span class="crawl-progress-percent" id="crawl-progress-text">0%</span>
                                    </div>
                                    <div class="crawl-current" id="crawl-current-item">Väntar på start...</div>
                                    <button type="button" id="btn-stop-crawl" class="advisio-btn advisio-btn-reject advisio-btn-sm" style="display:none; margin-top:8px;">Avbryt körning</button>
                                    <div id="crawl-summary" style="display:none; margin-top:10px;"></div>
                                    <ul id="crawl-live-feed" style="display:none; margin-top:10px; padding:0; list-style:none;"></ul>

                                    <!-- Senaste Indexering & Resultat Panel -->
                                    <div class="advisio-last-sync-card" id="advisio-last-sync-card" style="<?php echo empty( $last_sync_stats ) ? 'display:none;' : ''; ?>">
                                        <div class="sync-card-header">
                                            <span class="sync-badge-icon">📊</span>
                                            <h4>Senaste Indexering &amp; Resultat</h4>
                                            <span class="sync-timestamp" id="sync-stat-timestamp"><?php echo esc_html( ! empty( $last_sync_stats['timestamp'] ) ? $last_sync_stats['timestamp'] : '' ); ?></span>
                                        </div>
                                        <div class="sync-summary-pills">
                                            <span class="sync-pill pill-type" id="sync-stat-type"><?php echo esc_html( ! empty( $last_sync_stats['sync_type'] ) ? $last_sync_stats['sync_type'] : 'Full Indexering' ); ?></span>
                                            <span class="sync-pill pill-new" id="sync-stat-new">+<strong id="sync-val-new"><?php echo intval( ! empty( $last_sync_stats['new_added'] ) ? $last_sync_stats['new_added'] : 0 ); ?></strong> nya</span>
                                            <span class="sync-pill pill-updated" id="sync-stat-updated">🔄 <strong id="sync-val-updated"><?php echo intval( ! empty( $last_sync_stats['updated'] ) ? $last_sync_stats['updated'] : 0 ); ?></strong> uppdaterade</span>
                                        </div>
                                        <div class="sync-breakdown-grid">
                                            <div class="sync-breakdown-item">
                                                <span class="breakdown-label">🏠 Startsida &amp; Footer</span>
                                                <strong class="breakdown-val" id="sync-breakdown-gf"><?php echo intval( ! empty( $last_sync_stats['breakdown']['startsida_footer'] ) ? $last_sync_stats['breakdown']['startsida_footer'] : 0 ); ?></strong>
                                            </div>
                                            <div class="sync-breakdown-item">
                                                <span class="breakdown-label">📄 Menyer &amp; Sidor</span>
                                                <strong class="breakdown-val" id="sync-breakdown-ms"><?php echo intval( ! empty( $last_sync_stats['breakdown']['menyer_sidor'] ) ? $last_sync_stats['breakdown']['menyer_sidor'] : 0 ); ?></strong>
                                            </div>
                                            <div class="sync-breakdown-item">
                                                <span class="breakdown-label">🛒 Produkter &amp; Kat.</span>
                                                <strong class="breakdown-val" id="sync-breakdown-pk"><?php echo intval( ! empty( $last_sync_stats['breakdown']['produkter'] ) ? $last_sync_stats['breakdown']['produkter'] : 0 ); ?></strong>
                                            </div>
                                            <div class="sync-breakdown-item">
                                                <span class="breakdown-label">📚 Manuell &amp; Filer</span>
                                                <strong class="breakdown-val" id="sync-breakdown-mf"><?php echo intval( ! empty( $last_sync_stats['breakdown']['manuell_filer'] ) ? $last_sync_stats['breakdown']['manuell_filer'] : 0 ); ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ROW 3: UNIFIED CUSTOM KNOWLEDGE BASE (MANUAL TEXT & FILE UPLOADER) -->
                        <div class="advisio-card advisio-row-mt" id="advisio-custom-knowledge-card">
                            <div class="advisio-card-header-clean">
                                <div class="header-icon-title">
                                    <span class="header-icon">📚</span>
                                    <div>
                                        <h2>Anpassad Kunskapsbas (Manuell Text &amp; Referensfiler)</h2>
                                        <p class="desc">Lägg till specifik butiksinformation som showroom-öppettider, monteringsvillkor, garantier eller ladda upp prislistor och specifikationsfiler.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="advisio-knowledge-grid-split">
                                <!-- LEFT COLUMN: ADD KNOWLEDGE INPUT -->
                                <div class="knowledge-input-panel">
                                    <div class="panel-section-title">
                                        <h3>✏️ Lägg till Kunskapstext eller Referensfil</h3>
                                        <span class="section-subtitle">Välj att skriva text eller ladda upp en fil</span>
                                    </div>

                                    <!-- Option A: Text Input -->
                                    <div class="knowledge-sub-section">
                                        <input type="hidden" id="advisio_custom_text_id" value="">
                                        <label class="advisio-field-label" for="advisio_custom_text_title">Rubrik / Ämne <span class="advisio-optional">(t.ex. Showroom &amp; Öppettider, Returpolicy)</span></label>
                                        <input type="text" id="advisio_custom_text_title" class="advisio-input" placeholder="t.ex. Showroom &amp; Öppettider">

                                        <label class="advisio-field-label advisio-group-mt" for="advisio_custom_text_content">Kunskapsinnehåll</label>
                                        <textarea id="advisio_custom_text_content" class="advisio-textarea" rows="4" placeholder="t.ex. Vårt showroom finns på Kungsgatan 12 i Stockholm. Öppet vardagar 10-18, lördagar 11-15. Här kan du känna på träfönster och aluminiumprofiler..."></textarea>
                                    </div>

                                    <!-- Option B: File Dropzone -->
                                    <div class="knowledge-sub-section advisio-group-mt">
                                        <label class="advisio-field-label">📁 Eller ladda upp referensfil <span class="advisio-optional">(.txt, .md, .csv, .json — max 5 MB)</span></label>
                                        <div class="advisio-file-dropzone" id="advisio-file-dropzone">
                                            <input type="file" id="custom_knowledge_file_input" accept=".txt,.md,.csv,.json" style="display:none;">
                                            <div class="dropzone-inner" id="dropzone-trigger">
                                                <span class="dropzone-icon">📄</span>
                                                <span class="dropzone-text" id="dropzone-filename">Dra &amp; släpp en referensfil här, eller <u>klicka för att välja fil</u></span>
                                                <span class="dropzone-hint">CSV-rader konverteras automatiskt till semantiska nyckel:värde-rader</span>
                                            </div>
                                        </div>
                                        <div id="file-selected-status" class="file-selected-box" style="display:none;">
                                            <span class="badge-file-selected">Vald fil: <strong id="selected-file-name"></strong></span>
                                            <button type="button" class="btn-clear-file" id="btn-clear-file" title="Ta bort vald fil">✕ Ta bort</button>
                                        </div>
                                    </div>

                                    <!-- Action Button -->
                                    <div class="knowledge-submit-bar advisio-group-mt">
                                        <button type="button" id="btn-add-custom-knowledge" class="advisio-btn advisio-btn-primary advisio-btn-full">
                                            <span>➕ Lägg till i Kunskapsbasen</span>
                                        </button>
                                        <div id="custom-knowledge-status" style="margin-top:8px;"></div>
                                    </div>
                                </div>

                                <!-- RIGHT COLUMN: UNIFIED SAVED MANUAL KNOWLEDGE & FILES LIST -->
                                <div class="knowledge-lists-panel">
                                    <div class="knowledge-status-card live-card">
                                        <div class="status-card-header">
                                            <div class="status-badge-title">
                                                <span class="status-dot dot-emerald"></span>
                                                <h4>📚 Sparad Manuell Kunskap &amp; Filer i Minnesbanken</h4>
                                            </div>
                                            <span class="advisio-count-pill badge-success" id="live-knowledge-count-pill"><?php echo intval( $manual_count ); ?> sparade</span>
                                        </div>
                                        <p class="desc helper-text">All manuell information och uppladdade referensfiler sparas direkt i Minnesbanken och är omedelbart tillgängliga för Advisio AI.</p>

                                        <div id="advisio-live-knowledge-list" class="advisio-custom-knowledge-list">
                                            <?php if ( empty( $live_custom ) ) : ?>
                                                <p class="desc no-items-placeholder">Ingen sparad manuell kunskap än. Skriv eller ladda upp information till vänster för att lägga till i minnesbanken.</p>
                                            <?php else : foreach ( $live_custom as $l_item ) :
                                                $l_id   = esc_attr( isset( $l_item['id'] ) ? $l_item['id'] : '' );
                                                $l_type = esc_html( ucfirst( isset( $l_item['type'] ) ? $l_item['type'] : 'Text' ) );
                                                $l_tit  = esc_html( isset( $l_item['title'] ) ? $l_item['title'] : 'Kunskap' );
                                                $l_cnt  = esc_html( isset( $l_item['content'] ) ? $l_item['content'] : '' );
                                                $l_prev = esc_html( function_exists( 'mb_substr' ) ? mb_substr( $l_cnt, 0, 180, 'UTF-8' ) : substr( $l_cnt, 0, 180 ) );
                                            ?>
                                                <div class="advisio-custom-item item-live" id="live-item-<?php echo $l_id; ?>">
                                                    <div class="advisio-custom-item-head">
                                                        <div class="head-left">
                                                            <span class="custom-type-pill badge-success"><?php echo $l_type; ?></span>
                                                            <strong class="custom-item-title"><?php echo $l_tit; ?></strong>
                                                        </div>
                                                        <div class="head-actions">
                                                            <button type="button" class="advisio-btn advisio-btn-reject advisio-btn-sm btn-delete-custom-item" data-id="<?php echo $l_id; ?>">🗑️ Ta bort</button>
                                                        </div>
                                                    </div>
                                                    <div class="advisio-custom-item-body">
                                                        <span class="preview-text"><?php echo nl2br( $l_prev ); ?><?php echo ( function_exists( 'mb_strlen' ) ? mb_strlen( $l_cnt, 'UTF-8' ) : strlen( $l_cnt ) ) > 180 ? '...' : ''; ?></span>
                                                    </div>
                                                </div>
                                            <?php endforeach; endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div><!-- #tab-setup -->

                    <!-- ==========================================
                         TAB 3: THE BRAIN ("AI BEHAVIOR, HIERARCHY & CONSTRAINTS")
                         ========================================== -->
                    <div id="tab-persona" class="advisio-tab-content">
                        <?php
                        $total_records     = $this->get_total_knowledge_count();
                        $brain_initialized = (bool) get_option( 'advisio_brain_initialized', 0 );
                        ?>

                        <?php if ( $total_records === 0 ) : ?>
                            <!-- PREREQUISITE LOCK BANNER (EMPTY STORE MEMORY) -->
                            <div class="advisio-card advisio-prereq-lock-card">
                                <div class="prereq-lock-inner">
                                    <div class="prereq-lock-icon">🔒</div>
                                    <div class="prereq-lock-text">
                                        <h3>⚠️ Steg 1 krävs: Skapa butikens kunskapsbas först</h3>
                                        <p>Du måste indexera webbplatsen eller lägga till manuell information under <strong>Flik 2 (Setup &amp; Kunskapsbas)</strong> innan du kan skapa Advisios hjärna. Hjärnan sammanställer butikens faktiska minne med dina beteenderegler vid generering.</p>
                                        <button type="button" class="advisio-btn advisio-btn-primary advisio-jump-tab" data-target-tab="tab-setup" style="margin-top:14px;">
                                            👉 Gå till Flik 2 och indexera webbplatsen
                                        </button>
                                    </div>
                                </div>
                            </div>

                        <?php elseif ( ! $brain_initialized ) : ?>
                            <!-- STATE 1: INITIAL SETUP MODE (3 Symmetrical Boxes -> 3/3 Ready -> Create Brain via AI) -->
                            <?php
                            $b1_ready    = (bool) get_option( 'advisio_setup_box_1_ready', 0 );
                            $b2_ready    = (bool) get_option( 'advisio_setup_box_2_ready', 0 );
                            $b3_ready    = (bool) get_option( 'advisio_setup_box_3_ready', 0 );
                            $ready_count = ( $b1_ready ? 1 : 0 ) + ( $b2_ready ? 1 : 0 ) + ( $b3_ready ? 1 : 0 );
                            $b1_text     = get_option( 'advisio_box_1_persona', $this->get_default_box_1_text() );
                            $b2_text     = get_option( 'advisio_box_2_donts', $this->get_default_box_2_text() );
                            $b3_text     = get_option( 'advisio_box_3_goals', $this->get_default_box_3_text() );
                            ?>
                            <div class="advisio-card setup-progress-card">
                                <div class="setup-progress-head">
                                    <div class="head-info">
                                        <h2>🧠 Skapa Advisios Hjärna (Steg 2 av 2)</h2>
                                        <p class="desc">Fyll i och spara de 3 grundpelarna nedan. När alla 3 delar är klara (3 / 3 Redo) låses knappen upp för att generera Advisios hjärna med AI.</p>
                                    </div>
                                    <div class="setup-status-pill <?php echo ( 3 === $ready_count ) ? 'all-ready' : ''; ?>" id="setup-status-badge">
                                        <span class="status-indicator"></span>
                                        Setup-status: <strong id="setup-ready-count"><?php echo intval( $ready_count ); ?></strong> / 3 Redo
                                    </div>
                                </div>

                                <!-- Main Create Brain Button with Double Confirmation -->
                                <div class="create-brain-action-row" style="margin-top:18px;">
                                    <button type="button" id="btn-create-master-brain" class="advisio-btn advisio-btn-save advisio-btn-lg" <?php disabled( $ready_count < 3 ); ?>>
                                        🧠 Skapa Advisio (Generera Hjärnan)
                                    </button>
                                </div>

                                <!-- Inline Animated Confirmation Box -->
                                <div id="create-brain-confirm-box" class="advisio-confirm-box" style="display:none; margin-top:14px;">
                                    <div class="confirm-box-content">
                                        <strong>⚠️ Är du helt säker på att du vill skapa Advisios hjärna nu?</strong>
                                        <p>Advisios AI analyserar dina 3 delar tillsammans med butikens samlade minne och genererar Master System Instructions via Gemini Flash Lite. All väntande kunskap aktiveras samtidigt.</p>
                                        <div class="confirm-btn-row">
                                            <button type="button" id="btn-confirm-create-brain" class="advisio-btn advisio-btn-save">Ja, Skapa Advisio nu</button>
                                            <button type="button" id="btn-cancel-create-brain" class="advisio-btn advisio-btn-secondary">Avbryt</button>
                                        </div>
                                    </div>
                                </div>
                                <div id="create-brain-status" style="margin-top:10px;"></div>
                            </div>

                            <!-- 3 SETUP BOXES (3-COLUMN SYMMETRICAL GRID) -->
                            <div class="advisio-setup-boxes-grid advisio-row-mt">
                                <!-- Setup Box 1: Roll, Ton & Beteende -->
                                <div class="advisio-card setup-box-card" data-box="1">
                                    <div class="setup-box-head">
                                        <div class="head-title">
                                            <span class="step-num-badge">Steg 1 av 3</span>
                                            <h3>🎭 1. Roll, Ton &amp; Beteende</h3>
                                        </div>
                                        <span class="setup-badge <?php echo $b1_ready ? 'badge-ready' : 'badge-pending'; ?>" id="badge-box-1"><?php echo $b1_ready ? '✅ Redo' : '⚪ Ej sparad'; ?></span>
                                    </div>
                                    <p class="desc helper-text">Hur AI:n presenterar sig och tonläget i dialogen.</p>
                                    <div class="setup-box-body">
                                        <textarea id="setup_box_1_content" class="advisio-textarea" rows="8"><?php echo esc_textarea( $b1_text ); ?></textarea>
                                    </div>
                                    <div class="setup-box-footer">
                                        <button type="button" class="advisio-btn advisio-btn-primary advisio-btn-full btn-save-setup-box" data-box="1">💾 Spara del 1/3</button>
                                    </div>
                                </div>

                                <!-- Setup Box 2: Strikta Gränser & Förbud (DONTs) -->
                                <div class="advisio-card setup-box-card" data-box="2">
                                    <div class="setup-box-head">
                                        <div class="head-title">
                                            <span class="step-num-badge">Steg 2 av 3</span>
                                            <h3>⛔ 2. Strikta Gränser &amp; Förbud</h3>
                                        </div>
                                        <span class="setup-badge <?php echo $b2_ready ? 'badge-ready' : 'badge-pending'; ?>" id="badge-box-2"><?php echo $b2_ready ? '✅ Redo' : '⚪ Ej sparad'; ?></span>
                                    </div>
                                    <p class="desc helper-text">Vad AI:n ALDRIG får göra (t.ex. hitta på priser eller mått).</p>
                                    <div class="setup-box-body">
                                        <textarea id="setup_box_2_content" class="advisio-textarea" rows="8"><?php echo esc_textarea( $b2_text ); ?></textarea>
                                    </div>
                                    <div class="setup-box-footer">
                                        <button type="button" class="advisio-btn advisio-btn-primary advisio-btn-full btn-save-setup-box" data-box="2">💾 Spara del 2/3</button>
                                    </div>
                                </div>

                                <!-- Setup Box 3: Mål & Kvalificering -->
                                <div class="advisio-card setup-box-card" data-box="3">
                                    <div class="setup-box-head">
                                        <div class="head-title">
                                            <span class="step-num-badge">Steg 3 av 3</span>
                                            <h3>🎯 3. Mål &amp; Kvalificering</h3>
                                        </div>
                                        <span class="setup-badge <?php echo $b3_ready ? 'badge-ready' : 'badge-pending'; ?>" id="badge-box-3"><?php echo $b3_ready ? '✅ Redo' : '⚪ Ej sparad'; ?></span>
                                    </div>
                                    <p class="desc helper-text">Vilken information AI:n ska samla in innan offert/lead.</p>
                                    <div class="setup-box-body">
                                        <textarea id="setup_box_3_content" class="advisio-textarea" rows="8"><?php echo esc_textarea( $b3_text ); ?></textarea>
                                    </div>
                                    <div class="setup-box-footer">
                                        <button type="button" class="advisio-btn advisio-btn-primary advisio-btn-full btn-save-setup-box" data-box="3">💾 Spara del 3/3</button>
                                    </div>
                                </div>
                            </div>

                        <?php else : ?>
                            <!-- STATE 2: ACTIVE BRAIN MODE (Locked Boxes + Pending Additions with '-' removal) -->
                            <?php
                            $master_brain_prompt = get_option( 'advisio_master_brain_prompt', '' );
                            $total_pending       = $this->get_total_pending_brain_and_memory_count();
                            $box_1_locked        = get_option( 'advisio_box_1_persona', '' );
                            $box_2_locked        = get_option( 'advisio_box_2_donts', '' );
                            $box_3_locked        = get_option( 'advisio_box_3_goals', '' );
                            $box_4_locked        = (array) get_option( 'advisio_box_4_strategies', array() );
                            $box_1_pending       = $this->get_pending_box_additions( 1 );
                            $box_2_pending       = $this->get_pending_box_additions( 2 );
                            $box_3_pending       = $this->get_pending_box_additions( 3 );
                            $box_4_pending       = (array) get_option( 'advisio_pending_box_4', array() );
                            ?>

                            <!-- TOP MASTER BRAIN CARD -->
                            <div class="advisio-card" id="advisio-active-brain-card">
                                <div class="advisio-card-header-clean">
                                    <div class="header-icon-title">
                                        <span class="header-icon">🧬</span>
                                        <div>
                                            <h2>Advisios Aktiva Hjärna (Master System Instructions)</h2>
                                            <p class="desc">Genererad av AI utifrån dina val och butikens minne. Den styr livechatten. Du kan inte skriva direkt här — lägg till ändringar i delarna nedan och klicka 'Uppdatera Advisio'.</p>
                                        </div>
                                    </div>
                                    <div class="active-brain-actions">
                                        <button type="button" id="btn-update-master-brain" class="advisio-btn advisio-btn-primary <?php echo $total_pending > 0 ? 'advisio-btn-pulse' : ''; ?>">
                                            🔄 Uppdatera Advisio (<span id="brain-pending-total-badge"><?php echo intval( $total_pending ); ?></span> väntande)
                                        </button>
                                        <button type="button" id="btn-reset-master-brain" class="advisio-btn advisio-btn-reject">
                                            ⚠️ Reset
                                        </button>
                                    </div>
                                </div>

                                <!-- Inline Double Confirmation Boxes -->
                                <div id="update-brain-confirm-box" class="advisio-confirm-box" style="display:none; margin:14px 0;">
                                    <div class="confirm-box-content">
                                        <strong>🔄 Vill du uppdatera Advisio AI med alla väntande ändringar nu?</strong>
                                        <p>Gemini Flash Lite analyserar dina nya instruktioner, tar bort eventuella regler markerade med minus (-), och uppdaterar systemprompterna utan att störa chatten.</p>
                                        <div class="confirm-btn-row">
                                            <button type="button" id="btn-confirm-update-brain" class="advisio-btn advisio-btn-save">Ja, Uppdatera Advisio nu</button>
                                            <button type="button" id="btn-cancel-update-brain" class="advisio-btn advisio-btn-secondary">Avbryt</button>
                                        </div>
                                    </div>
                                </div>

                                <div id="reset-brain-confirm-box" class="advisio-confirm-box" style="display:none; margin:14px 0;">
                                    <div class="confirm-box-content">
                                        <strong>⚠️ VARNING: Vill du återställa Advisios hjärna till fabriksläge?</strong>
                                        <p>Detta nollställer den aktiva hjärnan och återgår till setup-läget. Alla 3 grundpelare sparas men den genererade hjärnan rensas.</p>
                                        <div class="confirm-btn-row">
                                            <button type="button" id="btn-confirm-reset-brain" class="advisio-btn advisio-btn-reject">Ja, Nollställ och börja om</button>
                                            <button type="button" id="btn-cancel-reset-brain" class="advisio-btn advisio-btn-secondary">Avbryt</button>
                                        </div>
                                    </div>
                                </div>

                                <div id="brain-action-status" style="margin-top:8px;"></div>

                                <!-- Readonly Master Brain Display Container -->
                                <div class="master-brain-container" style="position:relative; margin-top:14px;">
                                    <div class="locked-brain-badge">🔒 Låst kärninstruktion</div>
                                    <textarea id="advisio_master_brain_prompt" class="advisio-textarea master-brain-textarea" rows="10" readonly><?php echo esc_textarea( $master_brain_prompt ); ?></textarea>
                                </div>
                            </div>

                            <!-- 3 CORE BEHAVIOR PILLARS (3-COLUMN SYMMETRICAL GRID) -->
                            <div class="advisio-brain-pillars-grid advisio-row-mt">
                                <!-- Pillar 1: Roll, Ton & Beteende -->
                                <div class="advisio-card brain-pillar-card" data-box="1">
                                    <div class="pillar-header">
                                        <div class="pillar-title">
                                            <span class="pillar-icon">🎭</span>
                                            <h3>1. Roll, Ton &amp; Beteende</h3>
                                        </div>
                                        <span class="pillar-badge badge-active">Aktiv</span>
                                    </div>

                                    <!-- Zone 1: Locked Active Rules -->
                                    <div class="locked-box-display">
                                        <span class="sub-label">🔒 Aktiv i Hjärnan (Låst)</span>
                                        <div class="locked-text-wrap"><?php echo nl2br( esc_html( $box_1_locked ) ); ?></div>
                                    </div>

                                    <!-- Zone 2: Pending Additions & Removal Input -->
                                    <div class="pending-additions-wrap">
                                        <span class="sub-label">✏️ Lägg till / Ta bort inför nästa uppdatering</span>
                                        <div class="rule-tip-pill">💡 Tips: Börja med minus (-) för att ta bort en befintlig regel</div>

                                        <div class="pending-list" id="pending-list-box-1">
                                            <?php if ( empty( $box_1_pending ) ) : ?>
                                                <p class="desc no-items-placeholder">Inga väntande ändringar.</p>
                                            <?php else : foreach ( $box_1_pending as $item ) : ?>
                                                <div class="dna-item-row pending-item <?php echo ! empty( $item['is_removal'] ) ? 'item-removal' : ''; ?>" data-id="<?php echo esc_attr( $item['id'] ); ?>">
                                                    <span class="item-tag"><?php echo ! empty( $item['is_removal'] ) ? '🔴 - Tas bort' : '🟢 + Läggs till'; ?></span>
                                                    <span class="dna-item-text"><?php echo esc_html( $item['text'] ); ?></span>
                                                    <div class="item-actions">
                                                        <button type="button" class="btn-delete-pending-item" data-box="1" data-id="<?php echo esc_attr( $item['id'] ); ?>" title="Ta bort">🗑️</button>
                                                    </div>
                                                </div>
                                            <?php endforeach; endif; ?>
                                        </div>

                                        <div class="add-pending-row">
                                            <input type="text" class="advisio-input input-pending-addition" data-box="1" placeholder="Ny instruktion eller regel...">
                                            <button type="button" class="advisio-btn advisio-btn-secondary btn-add-pending-addition" data-box="1">➕ Spara tillägg</button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Pillar 2: Strikta Förbud (DONTs) -->
                                <div class="advisio-card brain-pillar-card" data-box="2">
                                    <div class="pillar-header">
                                        <div class="pillar-title">
                                            <span class="pillar-icon">⛔</span>
                                            <h3>2. Strikta Gränser &amp; Förbud</h3>
                                        </div>
                                        <span class="pillar-badge badge-active">Aktiv</span>
                                    </div>

                                    <!-- Zone 1: Locked Active Rules -->
                                    <div class="locked-box-display">
                                        <span class="sub-label">🔒 Aktiv i Hjärnan (Låst)</span>
                                        <div class="locked-text-wrap"><?php echo nl2br( esc_html( $box_2_locked ) ); ?></div>
                                    </div>

                                    <!-- Zone 2: Pending Additions & Removal Input -->
                                    <div class="pending-additions-wrap">
                                        <span class="sub-label">✏️ Lägg till / Ta bort inför nästa uppdatering</span>
                                        <div class="rule-tip-pill">💡 Tips: Börja med minus (-) för att ta bort en befintlig regel</div>

                                        <div class="pending-list" id="pending-list-box-2">
                                            <?php if ( empty( $box_2_pending ) ) : ?>
                                                <p class="desc no-items-placeholder">Inga väntande ändringar.</p>
                                            <?php else : foreach ( $box_2_pending as $item ) : ?>
                                                <div class="dna-item-row pending-item <?php echo ! empty( $item['is_removal'] ) ? 'item-removal' : ''; ?>" data-id="<?php echo esc_attr( $item['id'] ); ?>">
                                                    <span class="item-tag"><?php echo ! empty( $item['is_removal'] ) ? '🔴 - Tas bort' : '🟢 + Läggs till'; ?></span>
                                                    <span class="dna-item-text"><?php echo esc_html( $item['text'] ); ?></span>
                                                    <div class="item-actions">
                                                        <button type="button" class="btn-delete-pending-item" data-box="2" data-id="<?php echo esc_attr( $item['id'] ); ?>" title="Ta bort">🗑️</button>
                                                    </div>
                                                </div>
                                            <?php endforeach; endif; ?>
                                        </div>

                                        <div class="add-pending-row">
                                            <input type="text" class="advisio-input input-pending-addition" data-box="2" placeholder="Nytt förbud eller begränsning...">
                                            <button type="button" class="advisio-btn advisio-btn-secondary btn-add-pending-addition" data-box="2">➕ Spara tillägg</button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Pillar 3: Mål & Kvalificering -->
                                <div class="advisio-card brain-pillar-card" data-box="3">
                                    <div class="pillar-header">
                                        <div class="pillar-title">
                                            <span class="pillar-icon">🎯</span>
                                            <h3>3. Mål &amp; Kvalificering</h3>
                                        </div>
                                        <span class="pillar-badge badge-active">Aktiv</span>
                                    </div>

                                    <!-- Zone 1: Locked Active Rules -->
                                    <div class="locked-box-display">
                                        <span class="sub-label">🔒 Aktiv i Hjärnan (Låst)</span>
                                        <div class="locked-text-wrap"><?php echo nl2br( esc_html( $box_3_locked ) ); ?></div>
                                    </div>

                                    <!-- Zone 2: Pending Additions & Removal Input -->
                                    <div class="pending-additions-wrap">
                                        <span class="sub-label">✏️ Lägg till / Ta bort inför nästa uppdatering</span>
                                        <div class="rule-tip-pill">💡 Tips: Börja med minus (-) för att ta bort en befintlig regel</div>

                                        <div class="pending-list" id="pending-list-box-3">
                                            <?php if ( empty( $box_3_pending ) ) : ?>
                                                <p class="desc no-items-placeholder">Inga väntande ändringar.</p>
                                            <?php else : foreach ( $box_3_pending as $item ) : ?>
                                                <div class="dna-item-row pending-item <?php echo ! empty( $item['is_removal'] ) ? 'item-removal' : ''; ?>" data-id="<?php echo esc_attr( $item['id'] ); ?>">
                                                    <span class="item-tag"><?php echo ! empty( $item['is_removal'] ) ? '🔴 - Tas bort' : '🟢 + Läggs till'; ?></span>
                                                    <span class="dna-item-text"><?php echo esc_html( $item['text'] ); ?></span>
                                                    <div class="item-actions">
                                                        <button type="button" class="btn-delete-pending-item" data-box="3" data-id="<?php echo esc_attr( $item['id'] ); ?>" title="Ta bort">🗑️</button>
                                                    </div>
                                                </div>
                                            <?php endforeach; endif; ?>
                                        </div>

                                        <div class="add-pending-row">
                                            <input type="text" class="advisio-input input-pending-addition" data-box="3" placeholder="Nytt kvalificeringssteg...">
                                            <button type="button" class="advisio-btn advisio-btn-secondary btn-add-pending-addition" data-box="3">➕ Spara tillägg</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- BOX 4: APPROVED AI LEARNING STRATEGIES (SYMMETRICAL DEDICATED CARD) -->
                            <div class="advisio-card advisio-row-mt brain-strategies-card" data-box="4">
                                <div class="pillar-header">
                                    <div class="pillar-title">
                                        <span class="pillar-icon">🤖</span>
                                        <h3>4. Godkända AI-Lärandestrategier (Från 50-Chattars Självlärning)</h3>
                                    </div>
                                    <span class="pillar-badge badge-info">Självlärande</span>
                                </div>

                                <div class="strategies-split-grid">
                                    <div class="locked-box-display">
                                        <span class="sub-label">🔒 Aktiva strategier i Hjärnan (Kompilerade)</span>
                                        <div class="locked-strategies-list">
                                            <?php if ( empty( $box_4_locked ) ) : ?>
                                                <p class="desc no-items-placeholder">Inga godkända strategier låsta i hjärnan än.</p>
                                            <?php else : foreach ( $box_4_locked as $strat ) : ?>
                                                <div class="dna-item-row locked">
                                                    <span class="dna-item-text">💡 <?php echo esc_html( is_array( $strat ) ? $strat['text'] : $strat ); ?></span>
                                                </div>
                                            <?php endforeach; endif; ?>
                                        </div>
                                    </div>

                                    <div class="pending-additions-wrap">
                                        <span class="sub-label">🟡 Väntande godkända strategier (Från Dashboard)</span>
                                        <p class="desc helper-text">När du godkänner förslag i Flik 1 (Dashboard) hamnar de här och bakas in vid nästa uppdatering.</p>
                                        <div class="pending-list" id="pending-list-box-4">
                                            <?php if ( empty( $box_4_pending ) ) : ?>
                                                <p class="desc no-items-placeholder">Inga väntande förslag från Dashboard.</p>
                                            <?php else : foreach ( $box_4_pending as $p_strat ) : ?>
                                                <div class="dna-item-row pending-strat">
                                                    <span class="dna-item-text">💡 <?php echo esc_html( isset( $p_strat['text'] ) ? $p_strat['text'] : $p_strat ); ?></span>
                                                    <span class="badge-pending-strat">🟡 Väntar på uppdatering</span>
                                                </div>
                                            <?php endforeach; endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div><!-- #tab-persona -->

                    <!-- ==========================================
                         TAB 4: WIDGET DESIGN & LIVE PREVIEW
                         ========================================== -->
                    <div id="tab-widget" class="advisio-tab-content">
                        <div class="advisio-split-design">
                            <!-- Left Column: Controls (45%) -->
                            <div class="advisio-card advisio-design-controls">
                                <div class="advisio-card-header-clean">
                                    <div class="header-icon-title">
                                        <span class="header-icon">🎨</span>
                                        <div>
                                            <h2>Widget-design &amp; Varumärkesanpassning</h2>
                                            <p class="desc">Anpassa färger, rubriker, hälsningsfraser och ikoner för din chattwidget.</p>
                                        </div>
                                    </div>
                                </div>

                                <table class="form-table advisio-design-table">
                                    <tr>
                                        <th>Chattrubrik (Titel)</th>
                                        <td>
                                            <input type="text" name="advisio_chat_title" id="preview_chat_title" value="<?php echo esc_attr( get_option( 'advisio_chat_title', 'Advisio AI Kundtjänst' ) ); ?>" class="advisio-input">
                                            <p class="desc helper-text">Visas i chattfönstrets header.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Välkomsthälsning (Första bubblan)</th>
                                        <td>
                                            <input type="text" name="advisio_welcome_msg" id="preview_welcome_msg" value="<?php echo esc_attr( get_option( 'advisio_welcome_msg', 'Hej! Välkommen till vår kundtjänst. Hur kan jag hjälpa dig idag?' ) ); ?>" class="advisio-input">
                                            <p class="desc helper-text">Visas rent och tydligt för kunden när chatten öppnas.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Launcher-ikon (Chattknappen)</th>
                                        <td>
                                            <select name="advisio_launcher_icon" id="advisio_launcher_icon" class="advisio-input">
                                                <option value="preset-1" <?php selected( get_option( 'advisio_launcher_icon' ), 'preset-1' ); ?>>1. Modern Chattbubbla (SVG)</option>
                                                <option value="preset-2" <?php selected( get_option( 'advisio_launcher_icon' ), 'preset-2' ); ?>>2. AI-Gnistor / Smart Bot (SVG)</option>
                                                <option value="preset-3" <?php selected( get_option( 'advisio_launcher_icon' ), 'preset-3' ); ?>>3. Supportheadset / Rådgivare (SVG)</option>
                                                <option value="preset-4" <?php selected( get_option( 'advisio_launcher_icon' ), 'preset-4' ); ?>>4. Snabb respons / Blixt (SVG)</option>
                                                <option value="custom" <?php selected( get_option( 'advisio_launcher_icon' ), 'custom' ); ?>>5. Egen uppladdad ikon (WP Media)</option>
                                            </select>
                                            <div id="custom-icon-wrapper" style="<?php echo 'custom' === get_option( 'advisio_launcher_icon' ) ? 'display:block;' : 'display:none;'; ?> margin-top:12px;">
                                                <input type="hidden" name="advisio_custom_icon" id="advisio_custom_icon" value="<?php echo esc_attr( get_option( 'advisio_custom_icon' ) ); ?>">
                                                <img id="custom-icon-preview" src="<?php echo esc_attr( get_option( 'advisio_custom_icon' ) ); ?>" style="max-height:52px; display: <?php echo get_option( 'advisio_custom_icon' ) ? 'block' : 'none'; ?>; margin-bottom:10px; border-radius:8px;">
                                                <button type="button" class="advisio-btn advisio-btn-secondary" id="btn-upload-icon">Välj / Ladda upp bild</button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Primär Accent- &amp; Knappfärg</th>
                                        <td>
                                            <input type="text" name="advisio_color_primary" id="preview_color_primary" value="<?php echo esc_attr( get_option( 'advisio_color_primary', '#6366F1' ) ); ?>" class="advisio-color-picker">
                                            <p class="desc helper-text">Färg för launcher-cirkelknappen, kundens pratbubblor och skicka-knapp.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Header-bakgrundsfärg</th>
                                        <td>
                                            <input type="text" name="advisio_color_header" id="preview_color_header" value="<?php echo esc_attr( get_option( 'advisio_color_header', '#1E293B' ) ); ?>" class="advisio-color-picker">
                                            <p class="desc helper-text">Bakgrundsfärg för chattfönstrets översta sektion.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Proaktiv Hälsningsbubbla</th>
                                        <td>
                                            <input type="text" name="advisio_proactive_text" id="preview_proactive_text" value="<?php echo esc_attr( get_option( 'advisio_proactive_text', 'Behöver du hjälp med mått eller fönsterval? 👋' ) ); ?>" class="advisio-input" placeholder="Hälsningstext...">
                                            <p class="desc helper-text">Liten pratbubbla som svävar intill launcher-knappen på webbplatsen.</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Right Column: Self-Contained Sticky Preview Stage Canvas (55%) -->
                            <div class="advisio-card advisio-design-preview-container">
                                <div class="advisio-preview-stage-header">
                                    <div class="header-icon-title">
                                        <span class="header-icon">⚡</span>
                                        <div>
                                            <h2>Interaktiv Förhandsgranskning (Desktop Storlek)</h2>
                                            <p class="desc">Uppdaterar färger, pratbubblor, snabbvalsknappar och launcher-knappen i realtid.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="advisio-preview-stage" id="advisio-preview-stage">
                                    <!-- Upper Area: Realistic Desktop Chat Window (430px x 540px) -->
                                    <div class="lp-stage-window" id="lp-window">
                                        <!-- Header Bar -->
                                        <div class="lp-header" id="lp-header-bg">
                                            <div class="lp-header-brand">
                                                <span class="lp-header-icon-badge" id="lp-header-icon"></span>
                                                <div class="lp-header-meta">
                                                    <h4 id="lp-title"><?php echo esc_html( get_option( 'advisio_chat_title', 'Advisio AI Kundtjänst' ) ); ?></h4>
                                                    <span class="lp-online-status"><span class="lp-online-dot"></span> Online</span>
                                                </div>
                                            </div>
                                            <span class="lp-close-x" title="Stäng förhandsgranskning">&times;</span>
                                        </div>

                                        <!-- Messages Conversation Area -->
                                        <div class="lp-body">
                                            <!-- Initial welcome message -->
                                            <div class="lp-msg bot">
                                                <div class="lp-bubble" id="lp-welcome-bubble"><?php echo esc_html( get_option( 'advisio_welcome_msg', 'Hej! Välkommen till vår kundtjänst. Hur kan jag hjälpa dig idag?' ) ); ?></div>
                                            </div>
                                            <!-- Customer sample message -->
                                            <div class="lp-msg user">
                                                <div class="lp-bubble" id="lp-user-bubble">Jag letar efter ett 3-glas fönster.</div>
                                            </div>
                                            <!-- AI response with context-locked chips + Callback -->
                                            <div class="lp-msg bot">
                                                <div class="lp-bubble">Absolut! Vilka mått (bredd &times; höjd) behöver du på fönstret? Vi har både klassiska träfönster och aluminiumprofiler.</div>
                                            </div>
                                            <div class="lp-chips">
                                                <span class="lp-chip">3-glas fönster</span>
                                                <span class="lp-chip">Showroom</span>
                                                <span class="lp-chip">Inte säker</span>
                                                <span class="lp-chip lp-chip-primary" id="lp-cb-chip"><?php echo esc_html( get_option( 'advisio_label_callback', '📞 Bli kontaktad av en säljare' ) ); ?></span>
                                            </div>
                                        </div>

                                        <!-- Footer Input Bar -->
                                        <div class="lp-footer">
                                            <input type="text" disabled placeholder="Skriv ditt meddelande...">
                                            <button type="button" id="lp-send-btn" aria-label="Send">&#10148;</button>
                                        </div>
                                    </div>

                                    <!-- Dedicated Bottom Row: Floating Launcher Button Preview (Zero overlap!) -->
                                    <div class="lp-stage-launcher-row">
                                        <div class="lp-proactive-pill" id="lp-proactive-pill"><?php echo esc_html( get_option( 'advisio_proactive_text', 'Behöver du hjälp med mått eller fönsterval? 👋' ) ); ?></div>
                                        <div class="lp-launcher-btn" id="lp-launcher" title="Flytande Launcher-knapp förhandsvisning">
                                            <span id="lp-icon-render"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div><!-- .advisio-split-design -->
                    </div><!-- #tab-widget -->

                    <!-- ==========================================
                         TAB 5: LEADS & DYNAMIC FORM BUILDER
                         ========================================== -->
                    <div id="tab-lead" class="advisio-tab-content">
                        <?php
                        $captured_leads = $wpdb->get_results( "SELECT * FROM $table_logs WHERE is_lead = 1 ORDER BY id DESC LIMIT 50" );
                        if ( ! is_array( $captured_leads ) ) {
                            $captured_leads = array();
                        }
                        ?>
                        <!-- TOP ROW: 2-Column Symmetrical Grid (Notification Settings Left, Form Texts & Labels Right) -->
                        <div class="advisio-layout-split">
                            <!-- Left Card: Notification & Email Settings -->
                            <div class="advisio-card advisio-card-stretch">
                                <div class="advisio-card-header-clean">
                                    <div class="header-icon-title">
                                        <span class="header-icon">✉️</span>
                                        <div>
                                            <h2>E-post &amp; Aviseringsinställningar</h2>
                                            <p class="desc">Ange mottagare för inkomna kundförfrågningar och AI-sammanfattningar.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="advisio-form-group">
                                    <label class="advisio-field-label" for="advisio_notify_email">Mottagande E-postadress</label>
                                    <input type="email" name="advisio_notify_email" id="advisio_notify_email" value="<?php echo esc_attr( get_option( 'advisio_notify_email', get_option( 'admin_email' ) ) ); ?>" class="advisio-input" placeholder="sales@yourdomain.se">
                                </div>

                                <div class="advisio-callout-box advisio-group-mt">
                                    <strong>🤖 Automatisk AI-Leadssammanfattning</strong>
                                    <p>När kunden skickar in formuläret genererar Advisio AI en 3–4 punkters sammanfattning (Behov, Mått, Produktspecifikationer, Nyckelfrågor) som inkluderas i mailet och tabellen nedan.</p>
                                </div>
                            </div>

                            <!-- Right Card: Form Texts & Labels -->
                            <div class="advisio-card advisio-card-stretch">
                                <div class="advisio-card-header-clean">
                                    <div class="header-icon-title">
                                        <span class="header-icon">🏷️</span>
                                        <div>
                                            <h2>Formulärtexter &amp; Etiketter</h2>
                                            <p class="desc">Anpassa texterna för kontaktknapp och bekräftelsemeddelande.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="advisio-form-group">
                                    <label class="advisio-field-label" for="advisio_label_callback">Fast kontaktknapp i chatten</label>
                                    <input type="text" name="advisio_label_callback" id="advisio_label_callback" value="<?php echo esc_attr( get_option( 'advisio_label_callback', '📞 Bli kontaktad av en säljare' ) ); ?>" class="advisio-input">
                                </div>

                                <div class="advisio-form-group advisio-group-mt">
                                    <label class="advisio-field-label" for="advisio_label_lead_header">Rubrik inuti kontaktformuläret</label>
                                    <input type="text" name="advisio_label_lead_header" id="advisio_label_lead_header" value="<?php echo esc_attr( get_option( 'advisio_label_lead_header', 'Fyll i dina uppgifter så återkommer en rådgivare:' ) ); ?>" class="advisio-input">
                                </div>

                                <div class="advisio-form-group advisio-group-mt">
                                    <label class="advisio-field-label" for="advisio_label_lead_success">Tackmeddelande efter inskickat formulär</label>
                                    <input type="text" name="advisio_label_lead_success" id="advisio_label_lead_success" value="<?php echo esc_attr( get_option( 'advisio_label_lead_success', 'Tack! En rådgivare kommer att kontakta dig inom kort.' ) ); ?>" class="advisio-input">
                                </div>
                            </div>
                        </div>

                        <!-- MIDDLE ROW: 2-Column Split Layout (Dynamic Field Builder Left, Live Form Preview Right) -->
                        <div class="advisio-lead-builder-split advisio-row-mt">
                            <!-- Left Card: Dynamic Field Builder -->
                            <div class="advisio-card advisio-card-stretch">
                                <div class="advisio-card-header-clean">
                                    <div class="header-icon-title">
                                        <span class="header-icon">🛠️</span>
                                        <div>
                                            <h2>Dynamisk Formulärbyggare</h2>
                                            <p class="desc">Lägg till, sortera eller ta bort fält i kundens kontaktformulär.</p>
                                        </div>
                                    </div>
                                    <button type="button" id="btn-add-lead-field" class="advisio-btn advisio-btn-primary">+ Lägg till fält</button>
                                </div>

                                <input type="hidden" name="advisio_lead_form_fields" id="advisio_lead_form_fields_input" value="<?php echo esc_attr( $lead_fields_raw ); ?>">

                                <div id="advisio-field-builder-list" class="advisio-builder-list">
                                    <!-- Rendered dynamically via admin-script.js -->
                                </div>
                            </div>

                            <!-- Right Card: Live Lead Form Preview -->
                            <div class="advisio-card advisio-card-stretch advisio-lead-preview-wrapper-card">
                                <div class="advisio-card-header-clean">
                                    <div class="header-icon-title">
                                        <span class="header-icon">👁️</span>
                                        <div>
                                            <h2>Live Förhandsvisning av Formuläret</h2>
                                            <p class="desc">Så här ser kontaktformuläret ut för kunden inuti chatten.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="advisio-lead-preview-card">
                                    <h4 id="lp-lead-header-text"><?php echo esc_html( get_option( 'advisio_label_lead_header', 'Fyll i dina uppgifter så återkommer en rådgivare:' ) ); ?></h4>

                                    <div class="lead-preview-contact-pref">
                                        <span class="pref-label">Önskat kontaktsätt:</span>
                                        <div class="pref-pills-row">
                                            <span class="pref-pill active">📞 Telefon</span>
                                            <span class="pref-pill">✉️ E-post</span>
                                        </div>
                                    </div>

                                    <div id="lp-lead-fields-preview" class="lp-lead-fields-preview">
                                        <!-- Synchronized dynamically via admin-script.js -->
                                    </div>

                                    <div class="lp-lead-actions-preview">
                                        <button type="button" class="advisio-btn advisio-btn-primary advisio-btn-full" disabled>Skicka förfrågan</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- BOTTOM ROW: CAPTURED LEADS & AI CONVERSATION SUMMARIES (STRUCTURED TABLE) -->
                        <div class="advisio-card advisio-row-mt advisio-leads-card">
                            <div class="advisio-card-header-clean">
                                <div class="header-icon-title">
                                    <span class="header-icon">📋</span>
                                    <div>
                                        <h2>Inkomna Leads &amp; AI-Sammanfattningar</h2>
                                        <p class="desc">Kvalificerade kontaktförfrågningar med automatisk 3–4 punkters AI-sammanfattning av kunddialogen.</p>
                                    </div>
                                </div>
                                <div class="advisio-card-header-actions">
                                    <span class="advisio-count-pill badge-info"><?php echo count( $captured_leads ); ?> förfrågningar</span>
                                </div>
                            </div>

                            <?php if ( empty( $captured_leads ) ) : ?>
                                <div class="advisio-leads-table-wrap">
                                    <p class="desc no-items-placeholder" style="padding:32px 0; text-align:center;">
                                        Inga kontaktförfrågningar eller leads har fångats in än. När en kund fyller i formuläret i chatten dyker dialogen och AI-sammanfattningen upp här.
                                    </p>
                                </div>
                            <?php else : ?>
                                <div class="advisio-leads-table-wrap">
                                    <table class="advisio-leads-table widefat striped">
                                        <thead>
                                            <tr>
                                                <th style="width:150px;">Datum &amp; Tid</th>
                                                <th style="width:230px;">Kund &amp; Kontaktväg</th>
                                                <th style="width:220px;">Formulärdata</th>
                                                <th>AI-Sammanfattning av Dialogen</th>
                                                <th style="width:90px; text-align:right;">Åtgärd</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $captured_leads as $lead ) :
                                                $decoded_lead  = json_decode( $lead->user_message, true );
                                                $pref_contact  = isset( $decoded_lead['preferred_contact'] ) ? $decoded_lead['preferred_contact'] : 'Email';
                                                $fields_map    = ( isset( $decoded_lead['fields'] ) && is_array( $decoded_lead['fields'] ) ) ? $decoded_lead['fields'] : array();
                                                $ai_summary    = isset( $decoded_lead['ai_summary'] ) ? $decoded_lead['ai_summary'] : $lead->bot_reply;
                                                $customer_name = isset( $fields_map['name'] ) ? $fields_map['name'] : ( isset( $fields_map['namn'] ) ? $fields_map['namn'] : 'Kund' );
                                                $contact_icon  = ( 'phone' === strtolower( $pref_contact ) || 'telefon' === strtolower( $pref_contact ) ) ? '📞' : '✉️';
                                                ?>
                                                <tr id="lead-row-<?php echo intval( $lead->id ); ?>">
                                                    <td class="lead-col-date">
                                                        <span class="lead-date-badge"><?php echo esc_html( date( 'Y-m-d H:i', strtotime( $lead->created_at ) ) ); ?></span>
                                                    </td>
                                                    <td class="lead-col-customer">
                                                        <div class="lead-customer-cell">
                                                            <strong class="lead-name"><?php echo esc_html( $customer_name ); ?></strong>
                                                            <span class="lead-pref-pill"><?php echo $contact_icon . ' ' . esc_html( ucfirst( $pref_contact ) ); ?></span>
                                                            <?php if ( ! empty( $fields_map['email'] ) ) : ?>
                                                                <a href="mailto:<?php echo esc_attr( $fields_map['email'] ); ?>" class="lead-contact-link">✉️ <?php echo esc_html( $fields_map['email'] ); ?></a>
                                                            <?php endif; ?>
                                                            <?php if ( ! empty( $fields_map['phone'] ) ) : ?>
                                                                <a href="tel:<?php echo esc_attr( $fields_map['phone'] ); ?>" class="lead-contact-link">📞 <?php echo esc_html( $fields_map['phone'] ); ?></a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="lead-col-fields">
                                                        <div class="lead-fields-tags">
                                                            <?php foreach ( $fields_map as $f_key => $f_val ) :
                                                                if ( in_array( strtolower( $f_key ), array( 'name', 'namn', 'email', 'phone', 'preferred_contact' ) ) ) continue;
                                                                ?>
                                                                <span class="lead-field-tag"><strong><?php echo esc_html( ucfirst( $f_key ) ); ?>:</strong> <?php echo esc_html( $f_val ); ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </td>
                                                    <td class="lead-col-summary">
                                                        <div class="lead-ai-summary-box">
                                                            <?php echo nl2br( esc_html( $ai_summary ) ); ?>
                                                        </div>
                                                    </td>
                                                    <td class="lead-col-actions" style="text-align:right;">
                                                        <button type="button" class="advisio-btn advisio-btn-reject advisio-btn-sm btn-delete-lead" data-id="<?php echo intval( $lead->id ); ?>" title="Ta bort detta lead">🗑️ Radera</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div><!-- #tab-lead -->
                    <!-- ==========================================
                         TAB 6: DOCUMENTATION (FULL SYSTEM MANUAL)
                         ========================================== -->
                    <div id="tab-docs" class="advisio-tab-content">
                        <div class="advisio-card">
                            <h2>📖 Advisio AI (DEMO) — Full System Documentation &amp; Operations Manual</h2>
                            <p class="desc">Comprehensive reference guide for administrators managing the Advisio AI chat service — by Area 404.</p>
                        </div>

                        <div class="advisio-docs-grid">
                            <!-- Section 1 -->
                            <div class="advisio-card doc-card">
                                <div class="doc-num">01</div>
                                <h3>System Architecture &amp; Gemini Flash Lite Engine</h3>
                                <p>Advisio AI is engineered around a <strong>Hybrid Local RAG (Retrieval-Augmented Generation)</strong> architecture powered by Google's <strong>Gemini 3.5 Flash Lite</strong> engine.</p>
                                <ul>
                                    <li><strong>Google AI Studio Key Support:</strong> Native compatibility with both standard Google AI Studio keys (starting with <code>AIza...</code>) and workspace keys (starting with <code>AQ....</code>).</li>
                                    <li><strong>Dynamic Flash-Lite Model Resolution:</strong> Automatically detects and resolves the latest active Flash Lite model endpoint via <code>/v1beta/models</code>, caching the verified model and eliminating deprecated legacy 1.5 versions.</li>
                                    <li><strong>0ms Local Retrieval:</strong> WordPress queries indexed MySQL records (<code>wp_fg_ai_knowledge</code>) using multi-keyword tokenized relevance scoring in milliseconds, injecting exact source titles and deep links into every response.</li>
                                    <li><strong>Hallucination Guard:</strong> Inference parameters are locked at <code>temperature: 0.2</code> and <code>topP: 0.8</code> with a 25-second HTTP timeout and graceful multi-stage fallback.</li>
                                </ul>
                            </div>

                            <!-- Section 2 -->
                            <div class="advisio-card doc-card">
                                <div class="doc-num">02</div>
                                <h3>5-Stage Crash-Proof Deep Crawler &amp; Live Statistics</h3>
                                <p>In <strong>Tab 2 (Setup &amp; Modular Knowledge Base)</strong>, website indexing runs across 5 sequential, deterministic stages to guarantee 100% catalog and page coverage:</p>
                                <ul>
                                    <li><strong>Stage 1 (Startsida):</strong> Indexes the homepage hero, core value propositions, unique selling points (USPs), and site metadata.</li>
                                    <li><strong>Stage 2 (Footer):</strong> Captures company details, showroom addresses, opening hours, customer service contact numbers, warranty notices, and footer navigation links.</li>
                                    <li><strong>Stage 3 (Menyer):</strong> Maps complete WordPress navigation menus and header hierarchy taxonomies.</li>
                                    <li><strong>Stage 4 (Undersidor):</strong> Crawls all published pages, subpages, FAQs, buying guides, Elementor/WPBakery/Salient layouts, and detects embedded forms (WPForms, CF7, Gravity, Elementor).</li>
                                    <li><strong>Stage 5 (Produkter):</strong> Indexes the WooCommerce catalog including product titles, SKUs, pricing, categories, attributes, U-values, glass specs, dimensions, and stock status.</li>
                                    <li><strong>Micro-Batch Chaining &amp; Database Fallback:</strong> Processes 1–3 items per request via asynchronous AJAX chaining, eliminating HTTP 500/504 gateway timeouts. If HTTP scraping fails, it falls back directly to raw WordPress database records.</li>
                                    <li><strong>Senaste Indexering &amp; Resultat Card:</strong> Automatically prunes deleted records and tracks last index timestamp, items indexed per stage, and delta counts (+X new/updated).</li>
                                </ul>
                            </div>

                            <!-- Section 3 -->
                            <div class="advisio-card doc-card">
                                <div class="doc-num">03</div>
                                <h3>Unified Single Live Memory Bank &amp; Document Management</h3>
                                <p>All knowledge is consolidated into a single, high-performance database table (<code>wp_fg_ai_knowledge</code>), streamlining administration and RAG accuracy:</p>
                                <ul>
                                    <li><strong>Instant Live Activation:</strong> Custom manual knowledge entries and uploaded reference files are immediately saved with <code>status = 'live'</code>—eliminating confusing "pending review" staging delays.</li>
                                    <li><strong>Reference Document Processing:</strong> Upload custom product manuals, price matrices, or FAQ documents in <code>.txt</code>, <code>.csv</code>, <code>.md</code>, or <code>.json</code> formats. Files are automatically sanitized, tokenized, and indexed into live memory.</li>
                                    <li><strong>Bulletproof Schema Auto-Migration:</strong> Dynamic <code>advisio_ensure_tables_ready()</code> checks column integrity on-demand before any write, ensuring all required columns exist without causing activation fatal errors.</li>
                                    <li><strong>Granular Record Management:</strong> Administrators can view, filter, edit, or delete any indexed record at any time with immediate real-time synchronization.</li>
                                </ul>
                            </div>

                            <!-- Section 4 -->
                            <div class="advisio-card doc-card">
                                <div class="doc-num">04</div>
                                <h3>The Brain — 4-Section Architecture &amp; Staged Updates</h3>
                                <p>In <strong>Tab 3 (AI Behavior, Hierarchy &amp; Constraints)</strong>, Advisio AI's cognitive architecture is structured into 4 specialized functional sections:</p>
                                <ul>
                                    <li><strong>Box 1 (Core Identity, Tone &amp; Company Profile):</strong> Defines brand voice, professional sales demeanor, language style, and opening engagement etiquette.</li>
                                    <li><strong>Box 2 (Qualifying Goals, Conversion Strategy &amp; Lead Triggers):</strong> Directs customer discovery, qualification criteria, when to suggest formal quotes, and booking callbacks.</li>
                                    <li><strong>Box 3 (Knowledge, Catalog &amp; Routing Rules):</strong> Governs memory bank routing, product model matching, technical explanations (U-values, glass types, maintenance), and subpage deep-linking.</li>
                                    <li><strong>Box 4 (Strict Guardrails &amp; Negative Constraints):</strong> Absolute negative boundaries—prevents guessing missing prices, guarantees disclaimers on custom dimensions, blocks competitor mentions, and deflects off-topic queries.</li>
                                    <li><strong>Natural Language "Uppdatera Advisio":</strong> Administrators can input natural language instructions (e.g. <em>"Always check frame depth for sliding doors"</em> or <em>"- Remove rule about showroom visits"</em>). Gemini surgically updates the 4 boxes and stages changes for admin review before saving.</li>
                                </ul>
                            </div>

                            <!-- Section 5 -->
                            <div class="advisio-card doc-card">
                                <div class="doc-num">05</div>
                                <h3>Conversational Psychology, Quick Replies &amp; Measurement Guides</h3>
                                <p>Advisio AI operates with an intentional, consultative sales psychology designed to maximize user trust and lead conversion:</p>
                                <ul>
                                    <li><strong>Clean Initial Welcome:</strong> When the chat widget opens, only the welcoming greeting is displayed—free from overwhelming buttons or premature chips.</li>
                                    <li><strong>Context-Locked Question Chips:</strong> The AI asks one clear guiding question at a time and generates 2–3 concrete reply chips directly answering that question, followed by an <code>Inte säker</code> ("Not sure") escape option.</li>
                                    <li><strong>Smart Measurement Guidance:</strong> When asking for dimensions (Width &times; Height), the AI displays context-sensitive chips <code>[ Hur mäter jag? | Inte säker på måtten ]</code> that provide step-by-step frame measurement guidance or offer a sales callback.</li>
                                    <li><strong>Subpage &amp; Quote Form Deep-Linking:</strong> Automatically generates clickable markdown links (<code>[Title](URL)</code>) to verified subpages and quote request forms so customers can inspect details and convert immediately.</li>
                                </ul>
                            </div>

                            <!-- Section 6 -->
                            <div class="advisio-card doc-card">
                                <div class="doc-num">06</div>
                                <h3>Leads Management, Dynamic Form Builder &amp; Autonomous AI Summaries</h3>
                                <p><strong>Tab 5 (Leads &amp; Dynamic Form Builder)</strong> turns customer conversations into qualified sales opportunities with zero manual friction:</p>
                                <ul>
                                    <li><strong>Visual Dynamic Form Builder:</strong> Fully customizable quote and callback forms. Add, remove, or reorder <code>text</code>, <code>email</code>, <code>tel</code>, <code>textarea</code>, and <code>select</code> dropdown fields with per-field required/optional toggles.</li>
                                    <li><strong>Smart Progressive Profiling:</strong> If a visitor already provided contact information or specifications during the chat, the form automatically pre-fills and streamlines submission.</li>
                                    <li><strong>Autonomous 3–4 Bullet AI Lead Summary:</strong> Upon form submission, Gemini generates an instant 3–4 bullet executive summary (customer requirements, product specifications, dimensions, urgency level) attached to the lead in Tab 5 and emailed to your sales team.</li>
                                    <li><strong>50-Chat Autonomous Learning Engine:</strong> Every 50 completed chat sessions, Advisio AI analyzes conversation trends and generates 2–5 strategic rule recommendations in Tab 1 for one-click admin approval.</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- GLOBAL SAVE BUTTON -->
                <div class="advisio-save-wrapper">
                    <?php submit_button( 'Save All Settings', 'advisio-btn advisio-btn-save', 'submit', false ); ?>
                </div>
            </form>

            </div><!-- .advisio-admin-wrap -->
        <?php
    }

    /**
     * Smart Flash-Lite Model Resolution: Automatically detects the exact working Flash Lite model
     * supported by the key via /v1beta/models. Prioritizes gemini-3.5-flash-lite and latest flash-lite.
     * Zero references to legacy 1.5 models.
     */
    public function resolve_gemini_model( $api_key, $force_refresh = false ) {
        $api_key = trim( (string) $api_key );
        if ( empty( $api_key ) ) {
            return 'gemini-3.5-flash-lite';
        }

        $transient_key = 'advisio_models_' . md5( $api_key );
        if ( ! $force_refresh ) {
            $cached = get_transient( $transient_key );
            if ( ! empty( $cached ) && false === strpos( $cached, '1.5' ) ) {
                return $cached;
            }
            $saved = get_option( 'advisio_active_model', '' );
            if ( ! empty( $saved ) && false === strpos( $saved, '1.5' ) ) {
                return $saved;
            }
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $api_key );
        $response = wp_remote_get( $url, array(
            'timeout'   => 12,
            'sslverify' => false,
            'headers'   => array( 'User-Agent' => 'AdvisioAI/' . ADVISIO_AI_VERSION ),
        ) );

        $chosen_model = 'gemini-3.5-flash-lite'; // deterministic default
        if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $data['models'] ) && is_array( $data['models'] ) ) {
                $lite_models  = array();
                $flash_models = array();
                $other_models = array();

                foreach ( $data['models'] as $m ) {
                    $methods = isset( $m['supportedGenerationMethods'] ) ? $m['supportedGenerationMethods'] : array();
                    if ( ! in_array( 'generateContent', $methods, true ) ) {
                        continue;
                    }
                    $name = str_replace( 'models/', '', $m['name'] );
                    // Never use legacy 1.5 models
                    if ( false !== strpos( $name, '1.5' ) ) {
                        continue;
                    }
                    if ( false !== strpos( $name, 'flash-lite' ) ) {
                        $lite_models[] = $name;
                    } elseif ( false !== strpos( $name, 'flash' ) ) {
                        $flash_models[] = $name;
                    } else {
                        $other_models[] = $name;
                    }
                }

                if ( in_array( 'gemini-3.5-flash-lite', $lite_models, true ) ) {
                    $chosen_model = 'gemini-3.5-flash-lite';
                } elseif ( in_array( 'gemini-flash-lite-latest', $lite_models, true ) ) {
                    $chosen_model = 'gemini-flash-lite-latest';
                } elseif ( ! empty( $lite_models ) ) {
                    $chosen_model = $lite_models[0];
                } elseif ( in_array( 'gemini-3.5-flash', $flash_models, true ) ) {
                    $chosen_model = 'gemini-3.5-flash';
                } elseif ( ! empty( $flash_models ) ) {
                    $chosen_model = $flash_models[0];
                } elseif ( ! empty( $other_models ) ) {
                    $chosen_model = $other_models[0];
                }
            }
        }

        set_transient( $transient_key, $chosen_model, 6 * HOUR_IN_SECONDS );
        update_option( 'advisio_active_model', $chosen_model );
        return $chosen_model;
    }

    /**
     * Internal execute helper: formats payload and invokes call_gemini_api.
     */
    public function execute_gemini_api( $system_instruction, $user_prompt, $temperature = 0.35, $max_tokens = 1200 ) {
        $api_key = trim( (string) get_option( 'advisio_api_key', '' ) );
        if ( empty( $api_key ) ) {
            return array( 'success' => false, 'error' => 'API Key is not configured.' );
        }

        $payload = array(
            'contents' => array(
                array(
                    'role'  => 'user',
                    'parts' => array( array( 'text' => (string) $user_prompt ) ),
                ),
            ),
            'generationConfig' => array(
                'temperature'     => (float) $temperature,
                'maxOutputTokens' => (int) $max_tokens,
                'topP'            => 0.8,
            ),
        );

        if ( ! empty( $system_instruction ) ) {
            $payload['system_instruction'] = array(
                'parts' => array( array( 'text' => (string) $system_instruction ) ),
            );
        }

        return $this->call_gemini_api( $api_key, $payload, 30 );
    }

    /**
     * Robust Gemini API caller with automatic Flash Lite model resolution and 404 auto-recovery.
     */
    private function call_gemini_api( $api_key, $payload, $timeout = 25 ) {
        $api_key = trim( (string) $api_key );
        if ( empty( $api_key ) ) {
            return array( 'success' => false, 'error' => 'API Key is empty.' );
        }

        $active_model = $this->resolve_gemini_model( $api_key );
        $models_to_try = array( $active_model );
        $fallbacks = array( 'gemini-3.5-flash-lite', 'gemini-flash-lite-latest', 'gemini-2.5-flash-lite', 'gemini-3.5-flash', 'gemini-2.5-flash' );
        foreach ( $fallbacks as $fb ) {
            if ( ! in_array( $fb, $models_to_try, true ) ) {
                $models_to_try[] = $fb;
            }
        }

        $last_error  = 'Unknown API error';
        $req_timeout = min( 30, max( 5, intval( $timeout ) ) );
        $budget      = $req_timeout + 20;
        $started     = microtime( true );

        foreach ( $models_to_try as $model ) {
            $elapsed   = microtime( true ) - $started;
            $remaining = (int) floor( $budget - $elapsed );
            if ( $remaining < 5 ) {
                break;
            }

            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . rawurlencode( $api_key );
            $response = wp_remote_post( $url, array(
                'body'    => wp_json_encode( $payload ),
                'headers' => array( 'Content-Type' => 'application/json' ),
                'timeout' => min( $req_timeout, $remaining ),
            ) );

            if ( is_wp_error( $response ) ) {
                $last_error = $response->get_error_message();
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $data = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 404 === $code ) {
                // HTTP 404: Re-query models list from Google API to pick alternative
                $this->resolve_gemini_model( $api_key, true );
                $last_error = 'Model ' . $model . ' returned 404. Retrying fallback...';
                continue;
            }

            if ( isset( $data['error'] ) ) {
                $last_error = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Gemini API Error';
                continue;
            }

            if ( $code < 200 || $code >= 300 ) {
                $last_error = 'Gemini API HTTP ' . $code;
                continue;
            }

            if ( ! empty( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ) {
                $text = '';
                foreach ( $data['candidates'][0]['content']['parts'] as $p ) {
                    if ( isset( $p['text'] ) ) {
                        $text .= $p['text'];
                    }
                }
                if ( '' !== trim( $text ) ) {
                    update_option( 'advisio_active_model', $model );
                    return array(
                        'success' => true,
                        'text'    => $text,
                        'model'   => $model,
                    );
                }
            }
        }

        update_option(
            'advisio_last_api_error',
            array(
                'time'    => current_time( 'mysql' ),
                'message' => substr( wp_strip_all_tags( (string) $last_error ), 0, 400 ),
            ),
            false
        );

        return array( 'success' => false, 'error' => $last_error );
    }

    /**
     * AJAX: Save and Test Gemini API Key (supports AQ. and AIza keys untouched).
     */
    public function ajax_test_api_key() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $api_key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
        if ( empty( $api_key ) ) {
            wp_send_json_error( 'API-nyckeln är tom.' );
        }

        update_option( 'advisio_api_key', $api_key );

        // Force model refresh for the newly provided key
        $model = $this->resolve_gemini_model( $api_key, true );
        $res   = $this->execute_gemini_api( '', 'Respond with OK', 0.2, 50 );

        if ( ! $res['success'] ) {
            wp_send_json_error( $res['error'] );
        }

        wp_send_json_success( 'API-nyckel sparad och verifierad med ' . $res['model'] . '!' );
    }


    /* =====================================================================
     * 5-STAGE SEQUENTIAL DEEP CRAWLER
     * Stage 1: Homepage & global elements      (scope: global_site)
     * Stage 2: Footer, widgets & footer links  (scope: global_site + content)
     * Stage 3: All navigation menus            (scope: global_site navmap + content)
     * Stage 4: All remaining pages & posts     (scope: content)
     * Stage 5: Product categories & products   (scope: shop)
     * Manual Knowledge ('manual') and uploaded files ('file') are NEVER deleted or overwritten.
     * ===================================================================== */

    /**
     * PHASE 1 AJAX (`advisio_get_index_queue`): Build the explicit, ordered crawl queue for the selected scope.
     */
    public function ajax_get_index_queue() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $this->extend_time_limit( 120 );

        global $wpdb;
        $table = $wpdb->prefix . 'fg_ai_knowledge';

        $scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all';
        if ( ! in_array( $scope, array( 'shop', 'content', 'all' ), true ) ) {
            $scope = 'all';
        }
        $incremental = ! empty( $_POST['incremental'] ) && '1' === strval( $_POST['incremental'] );

        if ( 'shop' === $scope ) {
            $stages = array( 5 );
        } elseif ( 'content' === $scope ) {
            $stages = array( 1, 2, 3, 4 );
        } else {
            $stages = array( 1, 2, 3, 4, 5 );
        }

        $shop_stat    = get_option( 'advisio_last_shop_index', false );
        $content_stat = get_option( 'advisio_last_content_index', false );

        $ctx = array(
            'queue'            => array(),
            'seen_posts'       => array(),
            'seen_terms'       => array(),
            'seen_urls'        => array(),
            'want_content'     => in_array( 1, $stages, true ),
            'want_shop'        => in_array( 5, $stages, true ) && post_type_exists( 'product' ),
            'incremental'      => $incremental,
            'since_content'    => ( $incremental && is_array( $content_stat ) && ! empty( $content_stat['time'] ) ) ? $content_stat['time'] : '',
            'since_shop'       => ( $incremental && is_array( $shop_stat ) && ! empty( $shop_stat['time'] ) ) ? $shop_stat['time'] : '',
            'indexed_post_ids' => array(),
            'indexed_urls'     => array(),
        );

        if ( $incremental ) {
            $ids = $wpdb->get_col( "SELECT DISTINCT post_id FROM $table WHERE post_id > 0 AND scope IN ('content', 'shop', 'global_site')" );
            $ctx['indexed_post_ids'] = array_flip( array_map( 'intval', (array) $ids ) );
            $urls = $wpdb->get_col( "SELECT url FROM $table WHERE scope IN ('content', 'shop', 'global_site')" );
            $ctx['indexed_urls'] = array_flip( (array) $urls );
        }

        $home_key = $this->normalize_url_key( home_url( '/' ) );
        $ctx['seen_urls'][ $home_key ] = true;

        $home_html   = '';
        $loopback_ok = true;

        // ---------- STAGE 1: Homepage & Global Elements ----------
        if ( $ctx['want_content'] ) {
            $home_html   = $this->fetch_rendered_html( home_url( '/' ), 15 );
            $loopback_ok = ( '' !== $home_html );
            if ( $loopback_ok ) {
                set_transient( 'advisio_crawl_home_html', $home_html, 30 * MINUTE_IN_SECONDS );
            } else {
                delete_transient( 'advisio_crawl_home_html' );
            }

            $front_id = $this->get_front_page_id();
            if ( $front_id > 0 ) {
                $ctx['seen_posts'][ $front_id ] = true;
            }
            $ctx['queue'][] = array(
                's'     => 1,
                't'     => 'homepage',
                'id'    => $front_id,
                'title' => 'Homepage / Startsida',
            );

            // ---------- STAGE 2: Footer, Widgets & every footer link ----------
            $ctx['queue'][] = array(
                's'     => 2,
                't'     => 'footer',
                'id'    => 0,
                'title' => 'Global Footer & Widgets',
            );
            if ( '' !== $home_html ) {
                $footer = $this->extract_footer_from_html( $home_html );
                foreach ( $footer['links'] as $link ) {
                    $this->queue_discovered_link( $ctx, 2, $link['url'], $link['label'] );
                }
            }

            // ---------- STAGE 3: All navigation menus + header <nav> links ----------
            $ctx['queue'][] = array(
                's'     => 3,
                't'     => 'navmap',
                'id'    => 0,
                'title' => 'Site Navigation Directory (all menus)',
            );
            $menus = wp_get_nav_menus();
            if ( ! empty( $menus ) && ! is_wp_error( $menus ) ) {
                foreach ( $menus as $menu ) {
                    $items = wp_get_nav_menu_items( $menu->term_id );
                    if ( empty( $items ) || ! is_array( $items ) ) {
                        continue;
                    }
                    foreach ( $items as $item ) {
                        $this->queue_menu_item( $ctx, $item );
                    }
                }
            }
            if ( '' !== $home_html ) {
                foreach ( $this->extract_nav_links_from_html( $home_html ) as $link ) {
                    $this->queue_discovered_link( $ctx, 3, $link['url'], $link['label'] );
                }
            }
        }

        // ---------- STAGE 4: All remaining published pages, posts & public CPTs ----------
        if ( in_array( 4, $stages, true ) ) {
            $types        = $this->get_content_post_types();
            $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
            $rows         = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_title, post_modified FROM {$wpdb->posts}
                 WHERE post_type IN ($placeholders) AND post_status = 'publish'
                 ORDER BY (post_type = 'page') DESC, ID ASC",
                $types
            ) );
            foreach ( (array) $rows as $row ) {
                $this->queue_bulk_post_row( $ctx, 4, $row, false );
            }
        }

        // ---------- STAGE 5: Product categories, then every product one by one ----------
        if ( $ctx['want_shop'] ) {
            if ( taxonomy_exists( 'product_cat' ) ) {
                $terms = get_terms( array(
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => false,
                    'fields'     => 'id=>name',
                ) );
                if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
                    foreach ( $terms as $term_id => $term_name ) {
                        $this->queue_term( $ctx, 5, 'product_cat', (int) $term_id, (string) $term_name );
                    }
                }
            }

            $rows = $wpdb->get_results(
                "SELECT ID, post_title, post_modified FROM {$wpdb->posts}
                 WHERE post_type = 'product' AND post_status = 'publish'
                 ORDER BY ID ASC"
            );
            foreach ( (array) $rows as $row ) {
                $this->queue_bulk_post_row( $ctx, 5, $row, true );
            }
        }

        $queue = array_slice( $ctx['queue'], 0, 25000 );

        $stage_counts = array( 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0 );
        foreach ( $queue as $q ) {
            $stage_counts[ (int) $q['s'] ]++;
        }

        update_option( 'advisio_crawl_session', array(
            'started'           => current_time( 'mysql' ),
            'scope'             => $scope,
            'incremental'       => $incremental,
            'loopback_ok'       => $loopback_ok,
            'loopback_failures' => 0,
        ), false );

        $response = array(
            'queue'        => $queue,
            'total'        => count( $queue ),
            'scope'        => $scope,
            'stages'       => $stages,
            'stage_counts' => $stage_counts,
            'loopback_ok'  => $loopback_ok,
            'finalized'    => false,
        );

        // Nothing to do (e.g. Quick Sync Shop with no changes): finalize immediately
        if ( empty( $queue ) ) {
            $response              = array_merge( $response, $this->finalize_crawl_session( false ) );
            $response['finalized'] = true;
        }

        wp_send_json_success( $response );
    }

    /**
     * PHASE 2 AJAX (`advisio_process_index_batch`): Process 1–3 queue items per request (zero timeouts / zero 500 errors).
     */
    public function ajax_process_index_batch() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $this->extend_time_limit( 120 );

        $raw_items = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '[]';
        $items     = is_string( $raw_items ) ? json_decode( $raw_items, true ) : array();
        if ( ! is_array( $items ) ) {
            $items = array();
        }
        $items      = array_slice( $items, 0, 3 );
        $is_final   = ! empty( $_POST['is_final'] ) && '1' === strval( $_POST['is_final'] );
        $had_errors = ! empty( $_POST['had_errors'] ) && '1' === strval( $_POST['had_errors'] );

        $session = get_option( 'advisio_crawl_session', array() );
        if ( ! is_array( $session ) ) {
            $session = array();
        }

        $results = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $type  = isset( $item['t'] ) ? sanitize_key( $item['t'] ) : '';
            $stage = isset( $item['s'] ) ? (int) $item['s'] : 0;
            $res   = array(
                'title'  => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
                'stage'  => $stage,
                'status' => 'skipped',
                'chars'  => 0,
                'note'   => '',
            );

            try {
                switch ( $type ) {
                    case 'homepage':
                        $out = $this->crawl_homepage_item( isset( $item['id'] ) ? (int) $item['id'] : 0, $session );
                        break;
                    case 'footer':
                        $out = $this->crawl_footer_item( $session );
                        break;
                    case 'navmap':
                        $out = $this->crawl_navmap_item( $session );
                        break;
                    case 'post':
                        $out = $this->crawl_post_item( isset( $item['id'] ) ? (int) $item['id'] : 0, $stage, $session );
                        break;
                    case 'term':
                        $out = $this->crawl_term_item( isset( $item['id'] ) ? (int) $item['id'] : 0, isset( $item['tax'] ) ? sanitize_key( $item['tax'] ) : 'product_cat' );
                        break;
                    case 'url':
                        $out = $this->crawl_url_item( isset( $item['url'] ) ? esc_url_raw( $item['url'] ) : '', $res['title'], $stage, $session );
                        break;
                    default:
                        $out = array(
                            'status' => 'skipped',
                            'note'   => 'Unknown item type',
                        );
                }
                $res = array_merge( $res, $out );
            } catch ( \Throwable $e ) {
                // Isolate any defective page/product so the batch never triggers a 500 error
                $res['status'] = 'error';
                $res['note']   = substr( $e->getMessage(), 0, 160 );
            }

            $results[] = $res;
        }

        update_option( 'advisio_crawl_session', $session, false );
        $this->flush_runtime_cache();

        $response = array(
            'results'  => $results,
            'is_final' => $is_final,
        );

        if ( $is_final ) {
            $response = array_merge( $response, $this->finalize_crawl_session( $had_errors ) );
        }

        wp_send_json_success( $response );
    }

    /**
     * Finalize a crawl run: prune stale records (Full Index only, never protected scopes), update status options & summary.
     */
    private function finalize_crawl_session( $had_errors ) {
        global $wpdb;
        $table = $wpdb->prefix . 'fg_ai_knowledge';

        $session     = get_option( 'advisio_crawl_session', array() );
        $started     = ! empty( $session['started'] ) ? $session['started'] : current_time( 'mysql' );
        $scope       = ! empty( $session['scope'] ) ? $session['scope'] : 'all';
        $incremental = ! empty( $session['incremental'] );
        $now         = current_time( 'mysql' );
        $did_content = in_array( $scope, array( 'content', 'all' ), true );
        $did_shop    = in_array( $scope, array( 'shop', 'all' ), true );

        $pruned = 0;
        if ( ! $incremental && ! $had_errors ) {
            $prune_scopes = array();
            if ( $did_content ) {
                $prune_scopes[] = 'content';
                $prune_scopes[] = 'global_site';
            }
            if ( $did_shop ) {
                $prune_scopes[] = 'shop';
            }
            $prune_scopes = array_values( array_diff( $prune_scopes, self::PROTECTED_SCOPES ) );

            if ( ! empty( $prune_scopes ) ) {
                $ph     = implode( ',', array_fill( 0, count( $prune_scopes ), '%s' ) );
                $pruned = (int) $wpdb->query( $wpdb->prepare(
                    "DELETE FROM $table WHERE scope IN ($ph) AND source_type = 'internal' AND last_indexed < %s",
                    array_merge( $prune_scopes, array( $started ) )
                ) );
            }
        }

        $written_content = $did_content ? (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE scope IN ('content', 'global_site') AND last_indexed >= %s",
            $started
        ) ) : 0;
        $written_shop = $did_shop ? (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE scope = 'shop' AND last_indexed >= %s",
            $started
        ) ) : 0;

        $stats         = $this->get_memory_stats();
        $content_total = (int) $stats['content'] + (int) $stats['global_site'];

        // Determine human-readable sync type
        $sync_type = 'Full Indexering';
        if ( 'all' === $scope ) {
            $sync_type = $incremental ? 'Snappsynk (Hela webbplatsen)' : 'Full Indexering (Hela webbplatsen)';
        } elseif ( 'shop' === $scope ) {
            $sync_type = $incremental ? 'Synka Shop' : 'Indexera Shop';
        } elseif ( 'content' === $scope ) {
            $sync_type = $incremental ? 'Synka Sidor' : 'Indexera Sidor';
        }

        $new_added = isset( $session['new_count'] ) ? (int) $session['new_count'] : ( $written_content + $written_shop );
        $updated   = isset( $session['updated_count'] ) ? (int) $session['updated_count'] : 0;

        $last_stats = array(
            'timestamp'       => date_i18n( 'Y-m-d H:i' ),
            'sync_type'       => $sync_type,
            'total_in_memory' => (int) $stats['total'],
            'new_added'       => $new_added,
            'updated'         => $updated,
            'breakdown'       => array(
                'startsida_footer' => (int) $stats['global_site'],
                'menyer_sidor'     => (int) $stats['content'],
                'produkter'        => (int) $stats['shop'],
                'manuell_filer'    => (int) $stats['manual'],
            ),
        );
        update_option( 'advisio_last_index_stats', $last_stats );

        $result = array(
            'written_total'    => $written_content + $written_shop,
            'written_content'  => $written_content,
            'written_shop'     => $written_shop,
            'pruned'           => $pruned,
            'memory_stats'     => $stats,
            'combined_total'   => $content_total + (int) $stats['shop'],
            'last_index_stats' => $last_stats,
        );

        if ( $did_shop ) {
            $stat_arr = array(
                'total' => (int) $stats['shop'],
                'delta' => $written_shop,
                'time'  => $now,
            );
            update_option( 'advisio_last_shop_index', $stat_arr );
            $result['shop_badge'] = $this->format_delta_status_text( 'Shop', $stat_arr, (int) $stats['shop'] );
            $result['shop_short'] = sprintf( '+%d new (Total: %d) on %s', $written_shop, (int) $stats['shop'], date( 'Y-m-d H:i', strtotime( $now ) ) );
        }
        if ( $did_content ) {
            $stat_arr = array(
                'total' => $content_total,
                'delta' => $written_content,
                'time'  => $now,
            );
            update_option( 'advisio_last_content_index', $stat_arr );
            $result['content_badge'] = $this->format_delta_status_text( 'Content', $stat_arr, $content_total );
            $result['content_short'] = sprintf( '+%d new (Total: %d) on %s', $written_content, $content_total, date( 'Y-m-d H:i', strtotime( $now ) ) );
        }

        delete_transient( 'advisio_crawl_home_html' );
        delete_option( 'advisio_crawl_session' );

        return $result;
    }

    /* ---------------- Queue builder helpers ---------------- */

    private function get_front_page_id() {
        return ( 'page' === get_option( 'show_on_front' ) ) ? (int) get_option( 'page_on_front' ) : 0;
    }

    private function queue_menu_item( &$ctx, $item ) {
        if ( ! is_object( $item ) ) {
            return;
        }
        $label = isset( $item->title ) ? wp_strip_all_tags( $item->title ) : '';
        if ( 'post_type' === $item->type ) {
            $this->queue_post( $ctx, 3, (int) $item->object_id );
        } elseif ( 'taxonomy' === $item->type ) {
            $this->queue_term( $ctx, 3, (string) $item->object, (int) $item->object_id, $label );
        } elseif ( ! empty( $item->url ) ) {
            $this->queue_discovered_link( $ctx, 3, $item->url, $label );
        }
    }

    private function post_needs_sync( $ctx, $post_id, $post_modified, $is_product ) {
        if ( ! $ctx['incremental'] ) {
            return true;
        }
        $since = $is_product ? $ctx['since_shop'] : $ctx['since_content'];
        if ( '' === $since || ! isset( $ctx['indexed_post_ids'][ $post_id ] ) ) {
            return true;
        }
        return ( (string) $post_modified > (string) $since );
    }

    private function queue_post( &$ctx, $stage, $post_id ) {
        if ( $post_id <= 0 || isset( $ctx['seen_posts'][ $post_id ] ) ) {
            return;
        }
        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return;
        }
        if ( in_array( $post->post_type, array( 'attachment', 'revision', 'nav_menu_item', 'wp_block', 'elementor_library', 'product_variation' ), true ) ) {
            return;
        }
        $is_product = ( 'product' === $post->post_type );
        if ( ( $is_product && ! $ctx['want_shop'] ) || ( ! $is_product && ! $ctx['want_content'] ) ) {
            return;
        }

        $ctx['seen_posts'][ $post_id ] = true;
        $permalink = get_permalink( $post );
        if ( $permalink ) {
            $ctx['seen_urls'][ $this->normalize_url_key( $permalink ) ] = true;
        }

        if ( ! $this->post_needs_sync( $ctx, $post_id, $post->post_modified, $is_product ) ) {
            return;
        }

        $title          = wp_strip_all_tags( get_the_title( $post ) );
        $ctx['queue'][] = array(
            's'     => (int) $stage,
            't'     => 'post',
            'id'    => (int) $post_id,
            'title' => '' !== $title ? $title : '(untitled #' . $post_id . ')',
        );
    }

    private function queue_bulk_post_row( &$ctx, $stage, $row, $is_product ) {
        $post_id = (int) $row->ID;
        if ( isset( $ctx['seen_posts'][ $post_id ] ) ) {
            return;
        }
        $ctx['seen_posts'][ $post_id ] = true;

        if ( ! $this->post_needs_sync( $ctx, $post_id, $row->post_modified, $is_product ) ) {
            return;
        }

        $title          = wp_strip_all_tags( (string) $row->post_title );
        $ctx['queue'][] = array(
            's'     => (int) $stage,
            't'     => 'post',
            'id'    => $post_id,
            'title' => '' !== $title ? $title : '(untitled #' . $post_id . ')',
        );
    }

    private function queue_term( &$ctx, $stage, $taxonomy, $term_id, $label ) {
        if ( $term_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
            return;
        }
        $key = $taxonomy . ':' . $term_id;
        if ( isset( $ctx['seen_terms'][ $key ] ) ) {
            return;
        }
        $is_shop_tax = in_array( $taxonomy, array( 'product_cat', 'product_tag' ), true ) || 0 === strpos( $taxonomy, 'pa_' );
        if ( ( $is_shop_tax && ! $ctx['want_shop'] ) || ( ! $is_shop_tax && ! $ctx['want_content'] ) ) {
            return;
        }
        $ctx['seen_terms'][ $key ] = true;

        $link = get_term_link( (int) $term_id, $taxonomy );
        if ( is_wp_error( $link ) || empty( $link ) ) {
            return;
        }
        $ctx['seen_urls'][ $this->normalize_url_key( $link ) ] = true;

        if ( $ctx['incremental'] && isset( $ctx['indexed_urls'][ $link ] ) ) {
            return;
        }

        $ctx['queue'][] = array(
            's'     => (int) $stage,
            't'     => 'term',
            'id'    => (int) $term_id,
            'tax'   => $taxonomy,
            'title' => '' !== $label ? $label . ' (Category)' : 'Category #' . $term_id,
        );
    }

    private function queue_discovered_link( &$ctx, $stage, $url, $label ) {
        $url = $this->normalize_internal_url( $url );
        if ( '' === $url ) {
            return;
        }
        $key = $this->normalize_url_key( $url );
        if ( isset( $ctx['seen_urls'][ $key ] ) ) {
            return;
        }
        $ctx['seen_urls'][ $key ] = true;

        $post_id = url_to_postid( $url );
        if ( $post_id > 0 ) {
            $this->queue_post( $ctx, $stage, $post_id );
            return;
        }

        if ( ! $ctx['want_content'] ) {
            return;
        }
        if ( $ctx['incremental'] && isset( $ctx['indexed_urls'][ $url ] ) ) {
            return;
        }

        $label          = trim( wp_strip_all_tags( (string) $label ) );
        $ctx['queue'][] = array(
            's'     => (int) $stage,
            't'     => 'url',
            'id'    => 0,
            'url'   => $url,
            'title' => '' !== $label ? $label : $url,
        );
    }

    /**
     * Normalize a discovered link into an absolute, same-host, crawlable page URL (or '' when not crawlable).
     */
    private function normalize_internal_url( $url ) {
        $url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
        if ( '' === $url || preg_match( '#^(mailto:|tel:|javascript:|\#|data:|sms:|whatsapp:|skype:)#i', $url ) ) {
            return '';
        }

        $url = $this->absolutize_url( $url );
        if ( '' === $url ) {
            return '';
        }

        $parts = wp_parse_url( $url );
        $home  = wp_parse_url( home_url( '/' ) );
        if ( empty( $parts['host'] ) || empty( $home['host'] ) ) {
            return '';
        }
        $strip_www = function( $host ) {
            return strtolower( preg_replace( '/^www\./i', '', $host ) );
        };
        if ( $strip_www( $parts['host'] ) !== $strip_www( $home['host'] ) ) {
            return '';
        }

        $path = isset( $parts['path'] ) ? $parts['path'] : '/';
        if ( preg_match( '#/(wp-admin|wp-login\.php|wp-json|feed|xmlrpc\.php|wp-content|wp-includes|cart|kassan|varukorg|checkout|my-account|mitt-konto)(/|$)#i', $path ) ) {
            return '';
        }
        if ( preg_match( '#\.(pdf|jpe?g|png|gif|webp|svg|zip|docx?|xlsx?|pptx?|mp4|mp3|mov|avi)$#i', $path ) ) {
            return '';
        }
        $query = isset( $parts['query'] ) ? $parts['query'] : '';
        if ( '' !== $query && preg_match( '/(add-to-cart|action=|replytocom|orderby|filter_|min_price|max_price|s=)/i', $query ) ) {
            return '';
        }

        $scheme = ! empty( $home['scheme'] ) ? $home['scheme'] : 'https';
        $port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $parts['host'] . $port . $path . ( '' !== $query ? '?' . $query : '' );
    }

    private function absolutize_url( $href ) {
        $href = trim( (string) $href );
        if ( '' === $href ) {
            return '';
        }
        $home   = wp_parse_url( home_url( '/' ) );
        $scheme = ! empty( $home['scheme'] ) ? $home['scheme'] : 'https';
        if ( 0 === strpos( $href, '//' ) ) {
            return $scheme . ':' . $href;
        }
        if ( 0 === strpos( $href, '/' ) ) {
            return untrailingslashit( home_url() ) . $href;
        }
        if ( preg_match( '#^https?://#i', $href ) ) {
            return $href;
        }
        return '';
    }

    private function normalize_url_key( $url ) {
        $key = preg_replace( '#^https?://(www\.)?#i', '', (string) $url );
        $key = preg_replace( '/#.*$/', '', $key );
        return strtolower( untrailingslashit( $key ) );
    }

    /* ---------------- Crawl item processors ---------------- */

    private function crawl_homepage_item( $front_id, &$session ) {
        $site_name = wp_strip_all_tags( get_bloginfo( 'name' ) );
        $tagline   = wp_strip_all_tags( get_bloginfo( 'description' ) );
        $home_url  = home_url( '/' );

        $html = $this->get_cached_home_html( $session );

        $rendered = '' !== $html ? $this->html_to_visible_text( $html, 'homepage' ) : '';
        $db_text  = '';
        if ( $front_id > 0 ) {
            $post = get_post( $front_id );
            if ( $post && 'publish' === $post->post_status ) {
                $db_text = $this->extract_page_or_policy_data( $post );
            }
        }

        $parts   = array();
        $parts[] = '[GLOBAL SITE — STARTSIDA / HOMEPAGE] Företag / webbplats: ' . $site_name . ( '' !== $tagline ? ' — ' . $tagline : '' ) . "\nURL: " . $home_url;
        if ( '' !== $rendered ) {
            $parts[] = "=== RENDERED HOMEPAGE TEXT (header top-bar, announcement bars & all sections) ===\n" . $rendered;
        }
        if ( '' !== $db_text && ( '' === $rendered || mb_strlen( $rendered, 'UTF-8' ) < 0.5 * mb_strlen( $db_text, 'UTF-8' ) ) ) {
            $parts[] = "=== HOMEPAGE DATABASE CONTENT (page builder, Elementor & custom fields) ===\n" . $db_text;
        }
        if ( '' !== $html && $this->detect_rendered_form( $html ) ) {
            $parts[] = $this->form_notice( $home_url );
        }

        $content = $this->normalize_extracted_text( implode( "\n\n", $parts ), 40000 );
        $ok      = $this->store_knowledge_record( array(
            'post_id' => $front_id,
            'title'   => 'Startsida — ' . $site_name,
            'content' => $content,
            'type'    => 'homepage',
            'scope'   => 'global_site',
            'url'     => $home_url,
        ) );

        return array(
            'status' => $ok ? 'indexed' : 'skipped',
            'chars'  => mb_strlen( $content, 'UTF-8' ),
            'note'   => '' !== $rendered ? 'Rendered HTML + database' : 'Database only (loopback unavailable)',
        );
    }

    private function crawl_footer_item( &$session ) {
        $html = $this->get_cached_home_html( $session );

        $footer_text = '';
        $links       = array();
        if ( '' !== $html ) {
            $footer      = $this->extract_footer_from_html( $html );
            $footer_text = $footer['text'];
            $links       = $footer['links'];
        }

        $widgets_text  = $this->extract_sidebar_widgets_text();
        $theme_text    = $this->extract_theme_option_facts();
        $home_rendered = '' !== $html ? $this->html_to_visible_text( $html, 'homepage' ) : '';
        $key_facts     = $this->extract_key_fact_lines( $footer_text . "\n" . $widgets_text . "\n" . $theme_text . "\n" . $home_rendered );

        if ( '' === $footer_text && '' === $widgets_text && '' === $theme_text && empty( $key_facts ) ) {
            return array(
                'status' => 'skipped',
                'note'   => 'No footer content found',
            );
        }

        $content = "[GLOBAL FOOTER — FÖRETAGSUPPGIFTER, ADRESSER, ÖPPETTIDER & KONTAKT]\n";
        if ( ! empty( $key_facts ) ) {
            $content .= "=== NYCKELFAKTA (Butik / Adress / Öppettider / Kontakt) ===\n" . implode( "\n", $key_facts ) . "\n\n";
        }
        if ( '' !== $footer_text ) {
            $content .= "=== FOOTER TEXT (visas på alla sidor) ===\n" . $footer_text . "\n\n";
        }
        if ( ! empty( $links ) ) {
            $content .= "=== FOOTER LINKS (exakta URL:er) ===\n";
            foreach ( $links as $link ) {
                $content .= $link['label'] . ': ' . $link['url'] . "\n";
            }
            $content .= "\n";
        }
        if ( '' !== $widgets_text ) {
            $content .= "=== WIDGETS ===\n" . $widgets_text . "\n\n";
        }
        if ( '' !== $theme_text ) {
            $content .= "=== THEME SETTINGS ===\n" . $theme_text . "\n";
        }

        $content = $this->normalize_extracted_text( $content, 25000 );
        $ok      = $this->store_knowledge_record( array(
            'post_id' => 0,
            'title'   => 'Footer & Kontaktuppgifter',
            'content' => $content,
            'type'    => 'footer',
            'scope'   => 'global_site',
            'url'     => home_url( '/#footer' ),
        ) );

        return array(
            'status' => $ok ? 'indexed' : 'skipped',
            'chars'  => mb_strlen( $content, 'UTF-8' ),
            'note'   => count( $links ) . ' footer links, ' . count( $key_facts ) . ' key facts',
        );
    }

    private function crawl_navmap_item( &$session ) {
        $lines = array();
        $seen  = array();

        $menus = wp_get_nav_menus();
        if ( ! empty( $menus ) && ! is_wp_error( $menus ) ) {
            foreach ( $menus as $menu ) {
                $items = wp_get_nav_menu_items( $menu->term_id );
                if ( empty( $items ) || ! is_array( $items ) ) {
                    continue;
                }
                $by_id = array();
                foreach ( $items as $it ) {
                    $by_id[ $it->ID ] = $it;
                }
                $lines[] = '--- Meny: ' . $menu->name . ' ---';
                foreach ( $items as $it ) {
                    if ( empty( $it->url ) || empty( $it->title ) ) {
                        continue;
                    }
                    $label = wp_strip_all_tags( $it->title );
                    if ( ! empty( $it->menu_item_parent ) && isset( $by_id[ $it->menu_item_parent ] ) ) {
                        $label = wp_strip_all_tags( $by_id[ $it->menu_item_parent ]->title ) . ' › ' . $label;
                    }
                    $lines[]                                     = $label . ': ' . $it->url;
                    $seen[ $this->normalize_url_key( $it->url ) ] = true;
                }
            }
        }

        $html = $this->get_cached_home_html( $session );
        if ( '' !== $html ) {
            $extra = array();
            foreach ( $this->extract_nav_links_from_html( $html ) as $link ) {
                $k = $this->normalize_url_key( $link['url'] );
                if ( isset( $seen[ $k ] ) ) {
                    continue;
                }
                $seen[ $k ] = true;
                $extra[]    = $link['label'] . ': ' . $link['url'];
            }
            if ( ! empty( $extra ) ) {
                $lines[] = '--- Header-navigering ---';
                $lines   = array_merge( $lines, $extra );
            }
        }

        if ( empty( $lines ) ) {
            return array(
                'status' => 'skipped',
                'note'   => 'No menus found',
            );
        }

        $content = $this->normalize_extracted_text( "[SITE NAVIGATION DIRECTORY — alla menysidor med exakta URL:er]\n" . implode( "\n", $lines ), 12000 );
        $ok      = $this->store_knowledge_record( array(
            'post_id' => 0,
            'title'   => 'Webbplatsens navigering & undersidor',
            'content' => $content,
            'type'    => 'navmap',
            'scope'   => 'global_site',
            'url'     => home_url( '/#navigation' ),
        ) );

        return array(
            'status' => $ok ? 'indexed' : 'skipped',
            'chars'  => mb_strlen( $content, 'UTF-8' ),
            'note'   => count( $lines ) . ' navigation lines',
        );
    }

    private function crawl_post_item( $post_id, $stage, &$session ) {
        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return array(
                'status' => 'skipped',
                'note'   => 'Not published',
            );
        }

        $permalink = get_permalink( $post );
        if ( empty( $permalink ) || is_wp_error( $permalink ) ) {
            $permalink = home_url( '/?p=' . $post->ID );
        }

        if ( 'product' === $post->post_type ) {
            $content = $this->extract_woocommerce_product_data( $post );
            $scope   = 'shop';
            $note    = 'Product data & attributes';
        } else {
            $rendered_html = '';
            if ( $this->loopback_allowed( $session ) ) {
                $rendered_html = $this->fetch_rendered_html_tracked( $permalink, 12, $session );
            }
            $content = $this->extract_page_or_policy_data( $post, $rendered_html, $stage );
            $scope   = 'content';
            $note    = '' !== $rendered_html ? 'Database + rendered HTML' : 'Database extraction';
        }

        $content = mb_substr( (string) $content, 0, 60000, 'UTF-8' );
        $ok      = $this->store_knowledge_record( array(
            'post_id' => $post->ID,
            'title'   => wp_strip_all_tags( $post->post_title ),
            'content' => $content,
            'type'    => $post->post_type,
            'scope'   => $scope,
            'url'     => $permalink,
        ) );

        return array(
            'status' => $ok ? 'indexed' : 'skipped',
            'chars'  => mb_strlen( $content, 'UTF-8' ),
            'note'   => $note,
        );
    }

    private function crawl_term_item( $term_id, $taxonomy ) {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return array(
                'status' => 'skipped',
                'note'   => 'Taxonomy missing',
            );
        }
        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return array(
                'status' => 'skipped',
                'note'   => 'Term not found',
            );
        }
        $link = get_term_link( $term );
        if ( is_wp_error( $link ) ) {
            return array(
                'status' => 'skipped',
                'note'   => 'No term link',
            );
        }

        $is_shop = in_array( $taxonomy, array( 'product_cat', 'product_tag' ), true ) || 0 === strpos( $taxonomy, 'pa_' );
        $lines   = array();
        $lines[] = ( 'product_cat' === $taxonomy ? '[PRODUKTKATEGORI] ' : '[KATEGORI] ' ) . $term->name;
        $lines[] = 'Kategori-URL: ' . $link;
        if ( ! empty( $term->parent ) ) {
            $parent = get_term( $term->parent, $taxonomy );
            if ( $parent && ! is_wp_error( $parent ) ) {
                $lines[] = 'Överordnad kategori: ' . $parent->name;
            }
        }
        if ( ! empty( $term->description ) ) {
            $lines[] = 'Beskrivning: ' . $this->clean_page_builder_content( $term->description );
        }
        $lines[] = 'Antal produkter/inlägg: ' . (int) $term->count;

        $children = get_terms( array(
            'taxonomy'   => $taxonomy,
            'parent'     => (int) $term->term_id,
            'hide_empty' => false,
            'fields'     => 'names',
        ) );
        if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
            $lines[] = 'Underkategorier: ' . implode( ', ', $children );
        }

        if ( 'product_cat' === $taxonomy ) {
            $pids = get_posts( array(
                'post_type'        => 'product',
                'post_status'      => 'publish',
                'numberposts'      => 40,
                'fields'           => 'ids',
                'no_found_rows'    => true,
                'suppress_filters' => true,
                'tax_query'        => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field'    => 'term_id',
                        'terms'    => (int) $term->term_id,
                    ),
                ),
            ) );
            if ( ! empty( $pids ) ) {
                $lines[] = 'Produkter i kategorin:';
                foreach ( $pids as $pid ) {
                    $lines[] = '- ' . wp_strip_all_tags( get_the_title( $pid ) ) . ': ' . get_permalink( $pid );
                }
            }
        }

        $content = $this->normalize_extracted_text( implode( "\n", $lines ), 15000 );
        $ok      = $this->store_knowledge_record( array(
            'post_id' => 0,
            'title'   => $term->name . ( 'product_cat' === $taxonomy ? ' (Produktkategori)' : ' (Kategori)' ),
            'content' => $content,
            'type'    => $taxonomy,
            'scope'   => $is_shop ? 'shop' : 'content',
            'url'     => $link,
        ) );

        return array(
            'status' => $ok ? 'indexed' : 'skipped',
            'chars'  => mb_strlen( $content, 'UTF-8' ),
            'note'   => 'Category',
        );
    }

    private function crawl_url_item( $url, $label, $stage, &$session ) {
        if ( '' === $url ) {
            return array(
                'status' => 'skipped',
                'note'   => 'Empty URL',
            );
        }
        if ( ! $this->loopback_allowed( $session ) ) {
            return array(
                'status' => 'skipped',
                'note'   => 'Loopback HTTP unavailable on this server',
            );
        }

        $html = $this->fetch_rendered_html_tracked( $url, 12, $session );
        if ( '' === $html ) {
            return array(
                'status' => 'error',
                'note'   => 'Could not fetch page HTML',
            );
        }

        $title = $this->extract_html_title( $html );
        if ( '' === $title ) {
            $title = $label;
        }
        $text = $this->html_to_visible_text( $html, 'page' );
        if ( '' === $text ) {
            return array(
                'status' => 'skipped',
                'note'   => 'No visible text',
            );
        }

        $content = $this->stage_prefix( $stage ) . 'URL: ' . $url . ' | Title: ' . $title . "\n" . $text;
        if ( $this->detect_rendered_form( $html ) ) {
            $content .= "\n" . $this->form_notice( $url );
        }

        $content = mb_substr( $content, 0, 60000, 'UTF-8' );
        $ok      = $this->store_knowledge_record( array(
            'post_id' => 0,
            'title'   => $title,
            'content' => $content,
            'type'    => 'url',
            'scope'   => 'content',
            'url'     => $url,
        ) );

        return array(
            'status' => $ok ? 'indexed' : 'skipped',
            'chars'  => mb_strlen( $content, 'UTF-8' ),
            'note'   => 'Rendered HTML',
        );
    }

    private function stage_prefix( $stage ) {
        if ( 2 === (int) $stage ) {
            return '[FOOTER-LÄNKAD SIDA] ';
        }
        if ( 3 === (int) $stage ) {
            return '[MENYSIDA] ';
        }
        return '';
    }

    private function form_notice( $url ) {
        return '[OBS: Denna sida innehåller ett formulär som kunden kan fylla i direkt på: ' . $url . ']';
    }

    /**
     * Write a crawled record. The crawler can NEVER write into (or replace) protected scopes.
     */
    private function store_knowledge_record( $args ) {
        global $wpdb;
        $table = $wpdb->prefix . 'fg_ai_knowledge';

        $r = wp_parse_args( $args, array(
            'post_id'     => 0,
            'source_id'   => 0,
            'title'       => '',
            'content'     => '',
            'type'        => 'page',
            'scope'       => 'content',
            'url'         => '',
            'source_type' => 'internal',
            'status'      => 'live',
        ) );

        if ( in_array( $r['scope'], self::PROTECTED_SCOPES, true ) ) {
            return false;
        }
        $r['url'] = substr( (string) $r['url'], 0, 255 );
        if ( '' === trim( (string) $r['content'] ) || '' === $r['url'] ) {
            return false;
        }

        $existing_rec = $wpdb->get_row( $wpdb->prepare( "SELECT id, scope FROM $table WHERE url = %s LIMIT 1", $r['url'] ) );
        if ( $existing_rec && in_array( $existing_rec->scope, self::PROTECTED_SCOPES, true ) ) {
            return false;
        }

        $is_new = empty( $existing_rec );

        // Track new vs updated records for session reporting
        $session = get_option( 'advisio_crawl_session', false );
        if ( is_array( $session ) ) {
            if ( $is_new ) {
                $session['new_count'] = ( isset( $session['new_count'] ) ? (int) $session['new_count'] : 0 ) + 1;
            } else {
                $session['updated_count'] = ( isset( $session['updated_count'] ) ? (int) $session['updated_count'] : 0 ) + 1;
            }
            update_option( 'advisio_crawl_session', $session, false );
        }

        $post_id   = ! empty( $r['post_id'] ) ? (int) $r['post_id'] : ( ! empty( $r['source_id'] ) ? (int) $r['source_id'] : 0 );
        $source_id = ! empty( $r['source_id'] ) ? (int) $r['source_id'] : $post_id;
        $now       = current_time( 'mysql' );

        $data = array(
            'post_id'      => $post_id,
            'source_id'    => $source_id,
            'title'        => mb_substr( (string) $r['title'], 0, 500, 'UTF-8' ),
            'content'      => (string) $r['content'],
            'type'         => substr( (string) $r['type'], 0, 255 ),
            'scope'        => $r['scope'],
            'url'          => $r['url'],
            'source_type'  => $r['source_type'],
            'status'       => 'live',
            'last_indexed' => $now,
            'updated_at'   => $now,
        );

        $result = $wpdb->replace( $table, $data );

        if ( false === $result && ! empty( $wpdb->last_error ) ) {
            error_log( 'Advisio AI crawler store_knowledge_record DB error: ' . $wpdb->last_error );
        }

        return false !== $result;
    }

    /* ---------------- HTTP loopback helpers ---------------- */

    private function extend_time_limit( $seconds ) {
        if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
            @set_time_limit( (int) $seconds ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
    }

    private function flush_runtime_cache() {
        // Free per-request object cache memory without wiping a shared persistent cache (Redis/Memcached) on production.
        if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
            wp_cache_flush_runtime();
        } elseif ( ! wp_using_ext_object_cache() ) {
            wp_cache_flush();
        }
    }

    /**
     * Internal loopback request to capture the real rendered frontend HTML of a page.
     */
    private function fetch_rendered_html( $url, $timeout = 12 ) {
        $response = wp_remote_get( $url, array(
            'timeout'             => (int) $timeout,
            'sslverify'           => false,
            'redirection'         => 3,
            'limit_response_size' => 4 * MB_IN_BYTES,
            'user-agent'          => 'Mozilla/5.0 (compatible; AdvisioAI-Crawler/' . ADVISIO_AI_VERSION . '; +' . home_url( '/' ) . ')',
            'headers'             => array(
                'Accept'        => 'text/html,application/xhtml+xml',
                'Cache-Control' => 'no-cache',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return '';
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) {
            return '';
        }
        $ctype = (string) wp_remote_retrieve_header( $response, 'content-type' );
        if ( '' !== $ctype && false === stripos( $ctype, 'html' ) ) {
            return '';
        }
        return (string) wp_remote_retrieve_body( $response );
    }

    /**
     * Loopback fetch that tracks consecutive failures so a server without loopback support degrades to DB-only crawling fast.
     */
    private function fetch_rendered_html_tracked( $url, $timeout, &$session ) {
        $html = $this->fetch_rendered_html( $url, $timeout );
        if ( '' === $html ) {
            $session['loopback_failures'] = isset( $session['loopback_failures'] ) ? (int) $session['loopback_failures'] + 1 : 1;
            if ( $session['loopback_failures'] >= 3 ) {
                $session['loopback_ok'] = false;
            }
        } else {
            $session['loopback_failures'] = 0;
            $session['loopback_ok']       = true;
        }
        return $html;
    }

    private function loopback_allowed( $session ) {
        return ! ( is_array( $session ) && isset( $session['loopback_ok'] ) && false === $session['loopback_ok'] );
    }

    private function get_cached_home_html( &$session ) {
        $html = get_transient( 'advisio_crawl_home_html' );
        if ( false === $html || '' === $html ) {
            $html = $this->loopback_allowed( $session ) ? $this->fetch_rendered_html_tracked( home_url( '/' ), 15, $session ) : '';
            if ( '' !== $html ) {
                set_transient( 'advisio_crawl_home_html', $html, 30 * MINUTE_IN_SECONDS );
            }
        }
        return (string) $html;
    }

    /* ---------------- HTML parsing helpers (DOMDocument with regex fallback) ---------------- */

    private function xp_noise() {
        return "//script|//style|//noscript|//svg|//template|//iframe|//link|//meta|//*[@id='CybotCookiebotDialog']|//*[contains(@class,'cookie-notice')]|//*[contains(@id,'cookie-law')]|//*[contains(@class,'cmplz-cookiebanner')]|//*[contains(@class,'screen-reader-text')]";
    }

    private function xp_footer() {
        return "//footer|//*[@id='footer-outer']|//*[@id='colophon']|//*[@id='footer']|//*[@data-elementor-type='footer']|//*[contains(concat(' ',normalize-space(@class),' '),' site-footer ')]|//*[contains(concat(' ',normalize-space(@class),' '),' footer-widgets ')]";
    }

    private function xp_header() {
        return "//header|//*[@id='header-outer']|//*[@id='header-space']|//*[@id='masthead']|//*[@data-elementor-type='header']";
    }

    private function xp_nav() {
        return "//nav|//*[@role='navigation']|//*[@id='slide-out-widget-area']|//*[@id='slide-out-widget-area-bg']|//*[@id='search-outer']|//*[contains(@class,'skip-link')]";
    }

    private function load_html_dom( $html ) {
        if ( '' === trim( (string) $html ) || ! class_exists( 'DOMDocument' ) ) {
            return null;
        }
        $prev = libxml_use_internal_errors( true );
        $dom  = new DOMDocument();
        $ok   = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        return $ok ? $dom : null;
    }

    private function dom_remove_xpath( $xpath, $query ) {
        $nodes = $xpath->query( $query );
        if ( ! $nodes ) {
            return;
        }
        $list = array();
        foreach ( $nodes as $n ) {
            $list[] = $n;
        }
        foreach ( $list as $n ) {
            if ( $n->parentNode ) {
                $n->parentNode->removeChild( $n );
            }
        }
    }

    private function dom_contains( $ancestor, $node ) {
        $p = $node->parentNode;
        while ( $p ) {
            if ( $p === $ancestor ) {
                return true;
            }
            $p = $p->parentNode;
        }
        return false;
    }

    private function dom_has_ancestor_tag( $node, $tags ) {
        $p = $node->parentNode;
        while ( $p && XML_ELEMENT_NODE === $p->nodeType ) {
            if ( in_array( strtolower( $p->nodeName ), $tags, true ) ) {
                return true;
            }
            $p = $p->parentNode;
        }
        return false;
    }

    /**
     * Recursively collect visible text with line breaks at block boundaries (tel:/mailto: targets appended).
     */
    private function dom_node_text( $node, $contact_links = true ) {
        static $blocks = null;
        if ( null === $blocks ) {
            $blocks = array_flip( array( 'p', 'div', 'section', 'article', 'li', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'tr', 'table', 'thead', 'tbody', 'header', 'footer', 'main', 'aside', 'blockquote', 'address', 'dd', 'dt', 'dl', 'figcaption', 'figure', 'form', 'fieldset', 'label', 'button', 'hr', 'pre', 'td', 'th' ) );
        }
        if ( ! $node || ! $node->hasChildNodes() ) {
            return '';
        }

        $out = '';
        foreach ( $node->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
                $out .= $child->nodeValue . ' ';
                continue;
            }
            if ( XML_ELEMENT_NODE !== $child->nodeType ) {
                continue;
            }
            $tag      = strtolower( $child->nodeName );
            $is_block = isset( $blocks[ $tag ] );
            if ( $is_block ) {
                $out .= "\n";
            }
            $out .= $this->dom_node_text( $child, $contact_links );
            if ( 'a' === $tag && $contact_links ) {
                $href = trim( $child->getAttribute( 'href' ) );
                if ( preg_match( '#^(tel:|mailto:)#i', $href ) ) {
                    $out .= ' (' . rawurldecode( $href ) . ') ';
                }
            }
            if ( $is_block ) {
                $out .= "\n";
            }
        }
        return $out;
    }

    /**
     * Collapse whitespace per line, drop empty and consecutive duplicate lines, and cap length.
     */
    private function normalize_extracted_text( $text, $max = 20000 ) {
        $text  = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $lines = preg_split( '/\r\n|\r|\n/u', $text );
        $out   = array();
        $prev  = '';
        foreach ( (array) $lines as $line ) {
            $line = trim( preg_replace( '/[ \t\x{00A0}\x{200B}]+/u', ' ', (string) $line ) );
            if ( '' === $line || $line === $prev ) {
                continue;
            }
            $out[] = $line;
            $prev  = $line;
        }
        $text = implode( "\n", $out );
        if ( mb_strlen( $text, 'UTF-8' ) > $max ) {
            $text = mb_substr( $text, 0, $max, 'UTF-8' );
        }
        return $text;
    }

    /**
     * Regex fallback when DOMDocument is unavailable.
     */
    private function regex_html_to_text( $html, $strip_chrome ) {
        $html = preg_replace( '#<(script|style|noscript|svg|template|iframe)\b[^>]*>.*?</\1>#is', ' ', (string) $html );
        $html = preg_replace( '#<!--.*?-->#s', ' ', $html );
        if ( $strip_chrome ) {
            $html = preg_replace( '#<(header|nav|footer|aside)\b[^>]*>.*?</\1>#is', ' ', $html );
        }
        $html = preg_replace( '#<(br|/p|/div|/li|/h[1-6]|/tr|/section|/article)\b[^>]*>#i', "\n", $html );
        return $this->normalize_extracted_text( wp_strip_all_tags( $html, false ), 40000 );
    }

    /**
     * Extract visible text from rendered HTML.
     * Mode 'homepage': keeps header top-bars/announcement bars & all sections, removes footer + nav menus (footer is stored separately).
     * Mode 'page': removes header, nav, footer & sidebars and prefers the main content container.
     */
    private function html_to_visible_text( $html, $mode = 'page' ) {
        $dom = $this->load_html_dom( $html );
        if ( ! $dom ) {
            return $this->regex_html_to_text( $html, 'page' === $mode );
        }

        $xpath = new DOMXPath( $dom );
        $this->dom_remove_xpath( $xpath, $this->xp_noise() );
        $this->dom_remove_xpath( $xpath, $this->xp_footer() );
        $this->dom_remove_xpath( $xpath, $this->xp_nav() );

        $body = $dom->getElementsByTagName( 'body' )->item( 0 );
        $root = $body;

        if ( 'page' === $mode ) {
            $this->dom_remove_xpath( $xpath, $this->xp_header() );
            $this->dom_remove_xpath( $xpath, '//aside' );
            $candidates = array( '//main', "//*[@role='main']", "//*[@id='main-content']", "//*[@id='content']", "//*[contains(concat(' ',normalize-space(@class),' '),' main-content ')]", '//article' );
            foreach ( $candidates as $q ) {
                $nodes = $xpath->query( $q );
                if ( $nodes && $nodes->length > 0 ) {
                    $text = $this->normalize_extracted_text( $this->dom_node_text( $nodes->item( 0 ), true ), 40000 );
                    if ( mb_strlen( $text, 'UTF-8' ) >= 150 ) {
                        return $text;
                    }
                }
            }
        }

        if ( ! $root ) {
            return '';
        }
        return $this->normalize_extracted_text( $this->dom_node_text( $root, true ), 40000 );
    }

    /**
     * Extract the rendered site footer text + every link inside it.
     */
    private function extract_footer_from_html( $html ) {
        $result = array(
            'text'  => '',
            'links' => array(),
        );

        $dom = $this->load_html_dom( $html );
        if ( ! $dom ) {
            if ( preg_match_all( '#<footer\b[^>]*>(.*?)</footer>#is', (string) $html, $m ) ) {
                $raw            = implode( "\n", $m[1] );
                $result['text'] = $this->regex_html_to_text( $raw, false );
                if ( preg_match_all( '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $raw, $am, PREG_SET_ORDER ) ) {
                    foreach ( $am as $a ) {
                        $abs = $this->absolutize_url( html_entity_decode( $a[1], ENT_QUOTES, 'UTF-8' ) );
                        if ( '' === $abs ) {
                            continue;
                        }
                        $label                    = trim( wp_strip_all_tags( $a[2] ) );
                        $result['links'][ $abs ] = array(
                            'label' => '' !== $label ? $label : $abs,
                            'url'   => $abs,
                        );
                    }
                }
            }
            $result['links'] = array_values( array_slice( $result['links'], 0, 150 ) );
            return $result;
        }

        $xpath = new DOMXPath( $dom );
        $this->dom_remove_xpath( $xpath, $this->xp_noise() );

        $nodes   = $xpath->query( $this->xp_footer() );
        $matched = array();
        if ( $nodes ) {
            foreach ( $nodes as $n ) {
                // Skip small entry footers inside articles/main content
                if ( $this->dom_has_ancestor_tag( $n, array( 'article', 'main' ) ) ) {
                    continue;
                }
                $matched[] = $n;
            }
        }

        // Keep only outermost footer containers
        $outer = array();
        foreach ( $matched as $n ) {
            $inside = false;
            foreach ( $matched as $m ) {
                if ( $m !== $n && $this->dom_contains( $m, $n ) ) {
                    $inside = true;
                    break;
                }
            }
            if ( ! $inside ) {
                $outer[] = $n;
            }
        }

        $texts = array();
        $links = array();
        foreach ( $outer as $n ) {
            $texts[] = $this->dom_node_text( $n, true );
            foreach ( $n->getElementsByTagName( 'a' ) as $a ) {
                $abs = $this->absolutize_url( $a->getAttribute( 'href' ) );
                if ( '' === $abs ) {
                    continue;
                }
                $label = trim( preg_replace( '/\s+/u', ' ', $a->textContent ) );
                if ( '' === $label ) {
                    $label = trim( $a->getAttribute( 'title' ) );
                }
                if ( '' === $label ) {
                    $label = trim( $a->getAttribute( 'aria-label' ) );
                }
                $links[ $abs ] = array(
                    'label' => '' !== $label ? $label : $abs,
                    'url'   => $abs,
                );
            }
        }

        $result['text']  = $this->normalize_extracted_text( implode( "\n", $texts ), 15000 );
        $result['links'] = array_values( array_slice( $links, 0, 150 ) );
        return $result;
    }

    /**
     * Extract header <nav> / header links from rendered HTML.
     */
    private function extract_nav_links_from_html( $html ) {
        $dom = $this->load_html_dom( $html );
        if ( ! $dom ) {
            return array();
        }
        $xpath = new DOMXPath( $dom );
        $nodes = $xpath->query( "//nav//a|//header//a|//*[@id='header-outer']//a|//*[@role='navigation']//a" );
        $links = array();
        if ( $nodes ) {
            foreach ( $nodes as $a ) {
                $abs = $this->absolutize_url( $a->getAttribute( 'href' ) );
                if ( '' === $abs || isset( $links[ $abs ] ) ) {
                    continue;
                }
                $label = trim( preg_replace( '/\s+/u', ' ', $a->textContent ) );
                if ( '' === $label ) {
                    $label = trim( $a->getAttribute( 'title' ) );
                }
                if ( '' === $label ) {
                    continue;
                }
                $links[ $abs ] = array(
                    'label' => $label,
                    'url'   => $abs,
                );
                if ( count( $links ) >= 250 ) {
                    break;
                }
            }
        }
        return array_values( $links );
    }

    private function extract_html_title( $html ) {
        if ( ! preg_match( '#<title[^>]*>(.*?)</title>#is', (string) $html, $m ) ) {
            return '';
        }
        $title     = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( '' !== $site_name ) {
            $title = trim( preg_replace( '/\s*[\-–—|•·]\s*' . preg_quote( $site_name, '/' ) . '\s*$/u', '', $title ) );
        }
        return $title;
    }

    /**
     * Detect a real interactive form inside the main content of rendered HTML (ignores search, cart & login forms).
     */
    private function detect_rendered_form( $html ) {
        $plugin_pattern = '/(wpforms-form|wpcf7-form|gform_wrapper|elementor-form|nf-form-cont|frm_forms|fluentform|forminator-custom-form|caldera-grid|jetpack-contact-form)/i';

        $dom = $this->load_html_dom( $html );
        if ( ! $dom ) {
            return (bool) preg_match( $plugin_pattern, (string) $html );
        }

        $xpath = new DOMXPath( $dom );
        $this->dom_remove_xpath( $xpath, $this->xp_header() );
        $this->dom_remove_xpath( $xpath, $this->xp_nav() );
        $this->dom_remove_xpath( $xpath, $this->xp_footer() );
        $this->dom_remove_xpath( $xpath, '//aside' );

        foreach ( $dom->getElementsByTagName( 'form' ) as $form ) {
            $attrs = strtolower( $form->getAttribute( 'class' ) . ' ' . $form->getAttribute( 'id' ) . ' ' . $form->getAttribute( 'role' ) . ' ' . $form->getAttribute( 'action' ) );
            if ( preg_match( '/(search|cart|woocommerce-ordering|variations_form|login|lost_reset|comment|newsletter|mc4wp)/', $attrs ) ) {
                continue;
            }
            return true;
        }

        $body = $dom->getElementsByTagName( 'body' )->item( 0 );
        return $body ? (bool) preg_match( $plugin_pattern, $dom->saveHTML( $body ) ) : false;
    }

    /**
     * Collect text from all ACTIVE WordPress sidebars/widget areas (footer columns, contact widgets, menus).
     */
    private function extract_sidebar_widgets_text() {
        $sidebars = get_option( 'sidebars_widgets', array() );
        if ( ! is_array( $sidebars ) ) {
            return '';
        }

        $texts = array();
        $cache = array();
        foreach ( $sidebars as $sidebar_id => $widget_ids ) {
            if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widget_ids ) ) {
                continue;
            }
            foreach ( $widget_ids as $wid ) {
                if ( ! is_string( $wid ) || ! preg_match( '/^(.+)-(\d+)$/', $wid, $m ) ) {
                    continue;
                }
                $base = $m[1];
                $num  = (int) $m[2];
                if ( ! isset( $cache[ $base ] ) ) {
                    $cache[ $base ] = get_option( 'widget_' . $base, array() );
                }
                $inst = ( is_array( $cache[ $base ] ) && isset( $cache[ $base ][ $num ] ) ) ? $cache[ $base ][ $num ] : null;
                if ( ! is_array( $inst ) ) {
                    continue;
                }

                $chunk = array();
                foreach ( array( 'title', 'text', 'content', 'html', 'description', 'address', 'phone', 'email' ) as $k ) {
                    if ( ! empty( $inst[ $k ] ) && is_string( $inst[ $k ] ) ) {
                        $clean = $this->clean_page_builder_content( $inst[ $k ] );
                        if ( '' !== $clean ) {
                            $chunk[] = $clean;
                        }
                    }
                }
                if ( 'nav_menu' === $base && ! empty( $inst['nav_menu'] ) ) {
                    $items = wp_get_nav_menu_items( (int) $inst['nav_menu'] );
                    if ( ! empty( $items ) && is_array( $items ) ) {
                        foreach ( $items as $it ) {
                            $chunk[] = wp_strip_all_tags( $it->title ) . ': ' . $it->url;
                        }
                    }
                }
                if ( ! empty( $chunk ) ) {
                    $texts[] = implode( "\n", $chunk );
                }
            }
        }

        return $this->normalize_extracted_text( implode( "\n\n", array_unique( $texts ) ), 12000 );
    }

    /**
     * Extract readable company/contact/footer facts from theme options (theme_mods, Salient Redux, Avada, Astra, etc.).
     */
    private function extract_theme_option_facts() {
        $sources = array();
        $mods    = get_theme_mods();
        if ( is_array( $mods ) ) {
            $sources[] = $mods;
        }
        foreach ( array( 'salient_redux', 'fusion_options', 'avada_options', 'astra-settings', 'generate_settings', 'flatsome_options' ) as $opt ) {
            $val = get_option( $opt );
            if ( is_array( $val ) ) {
                $sources[] = $val;
            }
        }

        $key_pattern = '/(footer|copyright|phone|telefon|email|e-post|mail|address|adress|contact|kontakt|showroom|butik|opening|hours|öppet|oppet|org_?nr|company|foretag|företag|header_secondary|header-secondary|top_?bar|announcement)/i';
        $skip_values = array( 'on', 'off', 'yes', 'no', 'true', 'false', 'default', 'none', 'inherit', 'auto' );
        $facts       = array();

        $walker = function( $arr, $depth ) use ( &$walker, &$facts, $key_pattern, $skip_values ) {
            if ( $depth > 3 || ! is_array( $arr ) ) {
                return;
            }
            foreach ( $arr as $key => $val ) {
                if ( is_array( $val ) ) {
                    $walker( $val, $depth + 1 );
                    continue;
                }
                if ( ! is_string( $val ) || ! preg_match( $key_pattern, (string) $key ) ) {
                    continue;
                }
                $len = strlen( $val );
                if ( $len < 3 || $len > 600 ) {
                    continue;
                }
                $clean = $this->clean_page_builder_content( $val );
                if ( mb_strlen( $clean, 'UTF-8' ) < 3 || in_array( strtolower( $clean ), $skip_values, true ) ) {
                    continue;
                }
                if ( preg_match( '#^(https?://\S+\.(png|jpe?g|svg|gif|webp)|\#[0-9a-f]{3,8}|rgba?\(.*\)|\d+(\.\d+)?(px|em|rem|%)?)$#i', $clean ) ) {
                    continue;
                }
                $facts[] = $key . ': ' . $clean;
            }
        };

        foreach ( $sources as $src ) {
            $walker( $src, 0 );
        }

        return $this->normalize_extracted_text( implode( "\n", array_unique( $facts ) ), 6000 );
    }

    /**
     * Pull out lines that contain showroom / store / address / opening hours / contact facts.
     */
    private function extract_key_fact_lines( $text ) {
        $pattern = '/(showroom|butik|utställning|besök|öppet|öppettider|adress|address|telefon|tel\.|tel:|ring oss|mailto:|e-post|email|org\.?\s?nr|organisationsnummer|lager|upphämtning|hämta|vardagar|måndag|lördag|söndag)/iu';
        $out     = array();
        foreach ( (array) preg_split( '/\n+/u', (string) $text ) as $line ) {
            $line = trim( $line );
            $len  = mb_strlen( $line, 'UTF-8' );
            if ( $len < 4 || $len > 300 ) {
                continue;
            }
            if ( preg_match( $pattern, $line ) ) {
                $out[ $line ] = true;
                if ( count( $out ) >= 40 ) {
                    break;
                }
            }
        }
        return array_keys( $out );
    }

    /**
     * =========================================================================
     * SECTION G: EXTRACTION HELPERS, DUAL-STORAGE MANUAL KNOWLEDGE & MEMORY AJAX
     * =========================================================================
     */

    /**
     * Get all eligible public post types for Site Content indexing.
     */
    private function get_content_post_types() {
        $public_types = get_post_types( array( 'public' => true ), 'names' );
        $excluded     = array(
            'product',
            'product_variation',
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'wp_navigation',
            'e-landing-page',
            'elementor_library',
        );

        $types = array();
        foreach ( $public_types as $pt ) {
            if ( ! in_array( $pt, $excluded, true ) ) {
                $types[] = sanitize_key( $pt );
            }
        }

        if ( ! in_array( 'page', $types, true ) ) {
            $types[] = 'page';
        }
        if ( ! in_array( 'post', $types, true ) ) {
            $types[] = 'post';
        }

        return array_values( array_unique( $types ) );
    }

    /**
     * Detect interactive forms in content before shortcodes or markup are stripped.
     */
    private function detect_interactive_form( $raw_content, $elementor_raw = '' ) {
        $haystack = (string) $raw_content . ' ' . (string) $elementor_raw;
        if ( empty( trim( $haystack ) ) ) {
            return false;
        }

        $pattern = '/(\[wpforms|\[contact-form|\[gravityform|\[formidable|\[ninja_form|\[fluentform|\[caldera_form|\[quform|elementor-form|wp-block-jetpack-contact-form|<form\b)/i';
        return (bool) preg_match( $pattern, $haystack );
    }

    /**
     * Clean Page Builder & shortcode content WITHOUT using strip_shortcodes()
     * (preserves enclosed text inside [vc_column_text]...[/vc_column_text] etc.).
     */
    private function clean_page_builder_content( $raw_text ) {
        if ( empty( $raw_text ) ) {
            return '';
        }

        // Remove script and style blocks first
        $text = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', (string) $raw_text );

        // Strip only the shortcode brackets [shortcode ...] and [/shortcode] while keeping all inner text intact
        $text = preg_replace( '/\[\/?[^\]]+\]/', ' ', $text );

        // Strip HTML tags and decode entities
        $text = wp_strip_all_tags( $text );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        return trim( preg_replace( '/\s+/u', ' ', $text ) );
    }

    /**
     * Recursively parse Elementor _elementor_data JSON and extract all visible widget text.
     */
    private function extract_elementor_text( $elementor_raw ) {
        if ( empty( $elementor_raw ) ) {
            return '';
        }

        $data = is_string( $elementor_raw ) ? json_decode( $elementor_raw, true ) : $elementor_raw;
        if ( ! is_array( $data ) ) {
            return '';
        }

        $collected   = array();
        $target_keys = array(
            'editor',
            'title',
            'text',
            'html',
            'heading',
            'description',
            'tab_title',
            'tab_content',
            'accordion_title',
            'alert_title',
            'alert_description',
            'button_text',
            'field_label',
            'placeholder',
            'blockquote_content',
            'testimonial_content',
            'item_description',
            'title_text',
            'description_text',
        );

        $walker = function( $nodes ) use ( &$walker, &$collected, $target_keys ) {
            if ( ! is_array( $nodes ) ) {
                return;
            }
            foreach ( $nodes as $key => $val ) {
                if ( is_array( $val ) ) {
                    $walker( $val );
                } elseif ( is_string( $val ) && in_array( (string) $key, $target_keys, true ) ) {
                    $clean = $this->clean_page_builder_content( $val );
                    if ( mb_strlen( $clean, 'UTF-8' ) > 1 ) {
                        $collected[] = $clean;
                    }
                }
            }
        };

        $walker( $data );

        if ( empty( $collected ) ) {
            return '';
        }

        return implode( ' | ', array_unique( $collected ) );
    }

    /**
     * Extract readable custom postmeta fields (ACF, custom tabs, SEO descriptions) for pages & posts.
     */
    private function extract_custom_meta_text( $post_id ) {
        $all_meta = get_post_meta( $post_id );
        if ( ! is_array( $all_meta ) ) {
            return '';
        }

        $parts = array();
        foreach ( $all_meta as $meta_key => $meta_vals ) {
            if ( '_elementor_data' === $meta_key ) {
                continue;
            }
            // Skip internal WP/WooCommerce hidden meta keys except SEO description
            if ( 0 === strpos( $meta_key, '_' ) && ! in_array( $meta_key, array( '_yoast_wpseo_metadesc', '_rank_math_description' ), true ) ) {
                continue;
            }

            $raw_val = isset( $meta_vals[0] ) ? (string) $meta_vals[0] : '';
            if ( empty( $raw_val ) || is_serialized( $raw_val ) || strlen( $raw_val ) > 4000 ) {
                continue;
            }

            $clean = $this->clean_page_builder_content( $raw_val );
            if ( mb_strlen( $clean, 'UTF-8' ) > 3 && ! is_numeric( $clean ) ) {
                $parts[] = $clean;
            }
        }

        return implode( ' | ', array_unique( $parts ) );
    }

    /**
     * Deep extraction for WooCommerce products (Title, Permalink, SKU, Regular/Sale Price, Short/Full Desc, Categories, Tags, Attributes, Lookup Table).
     */
    private function extract_woocommerce_product_data( $post ) {
        $meta_lines = array();
        $title_low  = mb_strtolower( $post->post_title, 'UTF-8' );
        $permalink  = get_permalink( $post->ID );

        $addon_keywords = array( 'handtag', 'spaltventil', 'ventil', 'spröjs', 'karmhylsa', 'karmskruv', 'nyckel', 'beslag', 'tillbehör', 'myggnät', 'persienn', 'fönsterbleck', 'handle', 'grille', 'accessory' );
        $is_addon       = false;
        foreach ( $addon_keywords as $akw ) {
            if ( false !== mb_strpos( $title_low, $akw, 0, 'UTF-8' ) ) {
                $is_addon = true;
                break;
            }
        }

        $meta_lines[] = $is_addon
            ? 'PRODUCT HIERARCHY: Secondary Accessory / Add-on (Mention only when relevant or requested alongside a main product).'
            : 'PRODUCT HIERARCHY: Main Product (Primary Window / Door Focus).';

        $meta_lines[] = 'Product Title: ' . $post->post_title;
        if ( $permalink && ! is_wp_error( $permalink ) ) {
            $meta_lines[] = 'Product URL: ' . $permalink;
        }

        if ( function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $post->ID );
            if ( $product ) {
                if ( $product->get_sku() ) {
                    $meta_lines[] = 'SKU: ' . $product->get_sku();
                }

                $reg_price  = $product->get_regular_price();
                $sale_price = $product->get_sale_price();
                $disp_price = html_entity_decode( wp_strip_all_tags( wc_price( wc_get_price_to_display( $product ) ) ) );

                $price_parts = array( 'Active Price: ' . $disp_price );
                if ( '' !== (string) $reg_price ) {
                    $price_parts[] = 'Regular: ' . $reg_price;
                }
                if ( '' !== (string) $sale_price ) {
                    $price_parts[] = 'Sale: ' . $sale_price;
                }
                $meta_lines[] = implode( ' ', $price_parts );

                $cats = wc_get_product_category_list( $post->ID );
                if ( $cats && ! is_wp_error( $cats ) ) {
                    $meta_lines[] = 'Categories: ' . wp_strip_all_tags( $cats );
                }

                $tags = wc_get_product_tag_list( $post->ID );
                if ( $tags && ! is_wp_error( $tags ) ) {
                    $meta_lines[] = 'Tags: ' . wp_strip_all_tags( $tags );
                }

                $dims = $product->get_dimensions( false );
                if ( ! empty( $dims['width'] ) || ! empty( $dims['height'] ) || ! empty( $dims['length'] ) ) {
                    $meta_lines[] = sprintf(
                        'Dimensions: Width %s x Height %s%s',
                        $dims['width'],
                        $dims['height'],
                        ! empty( $dims['length'] ) ? ' x Depth ' . $dims['length'] : ''
                    );
                }

                $attributes = $product->get_attributes();
                if ( ! empty( $attributes ) && is_array( $attributes ) ) {
                    foreach ( $attributes as $attr ) {
                        if ( ! is_object( $attr ) || ! method_exists( $attr, 'get_name' ) ) {
                            continue;
                        }
                        $name = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $attr->get_name() ) : $attr->get_name();
                        if ( method_exists( $attr, 'is_taxonomy' ) && $attr->is_taxonomy() ) {
                            $terms = wc_get_product_terms( $post->ID, $attr->get_name(), array( 'fields' => 'names' ) );
                            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                                $meta_lines[] = $name . ': ' . implode( ', ', $terms );
                            }
                        } elseif ( method_exists( $attr, 'get_options' ) ) {
                            $opts = $attr->get_options();
                            if ( ! empty( $opts ) && is_array( $opts ) ) {
                                $meta_lines[] = $name . ': ' . implode( ', ', array_map( 'strval', $opts ) );
                            }
                        }
                    }
                }

                if ( $product->is_type( 'variable' ) ) {
                    $children   = array_slice( (array) $product->get_children(), 0, 20 );
                    $var_output = array();
                    foreach ( $children as $child_id ) {
                        $var_prod = wc_get_product( $child_id );
                        if ( $var_prod ) {
                            $v_price      = html_entity_decode( wp_strip_all_tags( wc_price( wc_get_price_to_display( $var_prod ) ) ) );
                            $v_attrs      = function_exists( 'wc_get_formatted_variation' ) ? wc_get_formatted_variation( $var_prod, true, false, false ) : '';
                            $var_output[] = trim( $v_attrs . ' (' . $v_price . ')' );
                        }
                    }
                    if ( ! empty( $var_output ) ) {
                        $meta_lines[] = 'Variations: ' . implode( ' ; ', $var_output );
                    }
                }
            }
        }

        // Check WooCommerce Lookup Table & Custom Specification Meta
        global $wpdb;
        $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup_table ) ) === $lookup_table ) {
            $lookup_row = $wpdb->get_row( $wpdb->prepare( "SELECT sku, min_price, max_price, stock_status FROM $lookup_table WHERE product_id = %d", $post->ID ), ARRAY_A );
            if ( ! empty( $lookup_row ) ) {
                $meta_lines[] = sprintf(
                    'Lookup Price Range: %s - %s (%s)',
                    $lookup_row['min_price'],
                    $lookup_row['max_price'],
                    $lookup_row['stock_status']
                );
            }
        }

        $all_meta = get_post_meta( $post->ID );
        if ( is_array( $all_meta ) ) {
            foreach ( $all_meta as $m_key => $m_vals ) {
                if ( preg_match( '/(lookup|u_value|u-varde|uvarde|wombat|glas|mått|matt|bredd|hojd|energi|garanti)/i', $m_key ) ) {
                    $val_str = isset( $m_vals[0] ) ? $this->clean_page_builder_content( (string) $m_vals[0] ) : '';
                    if ( ! empty( $val_str ) && strlen( $val_str ) < 1500 ) {
                        $meta_lines[] = 'Spec (' . $m_key . '): ' . $val_str;
                    }
                }
            }
        }

        $elementor_raw  = get_post_meta( $post->ID, '_elementor_data', true );
        $has_form       = $this->detect_interactive_form( $post->post_content . ' ' . $post->post_excerpt, $elementor_raw );
        $body_text      = $this->clean_page_builder_content( $post->post_excerpt . ' ' . $post->post_content );
        $elementor_text = $this->extract_elementor_text( $elementor_raw );

        $combined_body = trim( $body_text . ( ! empty( $elementor_text ) ? ' ' . $elementor_text : '' ) );
        if ( $has_form ) {
            $combined_body .= ' [IMPORTANT: This page contains an interactive form the customer can fill out directly on this URL]';
        }

        return implode( ' | ', $meta_lines ) . "\n" . $combined_body;
    }

    /**
     * Deep extraction for Pages, Subpages, Custom Post Types, and Footer/Policy documents.
     * Preserves Page Builder shortcodes, Elementor widgets, custom meta, and detects interactive forms.
     * Optionally merges rendered HTML when available from the crawler loopback.
     */
    private function extract_page_or_policy_data( $post, $rendered_html = '', $stage = '4' ) {
        $title_low   = mb_strtolower( $post->post_title, 'UTF-8' );
        $policy_tags = array(
            'villkor', 'köpvillkor', 'reklamation', 'ångerrätt', 'retur', 'garanti',
            'integritet', 'gdpr', 'mätguide', 'mäta', 'montering', 'leverans', 'frakt',
            'partner', 'partnerskap', 'samarbete', 'återförsäljare', 'kontakt', 'formulär',
            'terms', 'policy', 'return', 'claim', 'warranty', 'measure', 'guide', 'faq', 'contact',
            'showroom', 'utställning', 'butik', 'öppettider', 'besök',
        );
        $is_policy   = false;
        foreach ( $policy_tags as $pt ) {
            if ( false !== mb_strpos( $title_low, $pt, 0, 'UTF-8' ) ) {
                $is_policy = true;
                break;
            }
        }

        $prefix = $is_policy ? '[OFFICIAL SUBPAGE / POLICY / GUIDE] ' : '[SITE CONTENT PAGE] ';
        if ( $post->post_parent > 0 ) {
            $parent_title = get_the_title( $post->post_parent );
            if ( $parent_title ) {
                $prefix .= '(Subpage of: ' . $parent_title . ') ';
            }
        }

        $permalink = get_permalink( $post->ID );
        if ( $permalink && ! is_wp_error( $permalink ) ) {
            $prefix .= 'URL: ' . $permalink . ' | ';
        }

        $elementor_raw = get_post_meta( $post->ID, '_elementor_data', true );

        // 1. Detect interactive forms BEFORE stripping shortcodes or HTML tags
        $has_form = $this->detect_interactive_form( $post->post_content . ' ' . $post->post_excerpt, $elementor_raw );
        if ( ! $has_form && '' !== $rendered_html ) {
            $has_form = $this->detect_rendered_form( $rendered_html );
        }

        // 2. Strip shortcode brackets via regex (preserving WPBakery/Salient/Divi inner text)
        $body_text = $this->clean_page_builder_content( $post->post_excerpt . ' ' . $post->post_content );

        // 3. Extract Elementor JSON widget strings if present
        $elementor_text = $this->extract_elementor_text( $elementor_raw );

        // 4. Extract readable custom meta fields
        $custom_meta_text = $this->extract_custom_meta_text( $post->ID );

        // 5. If rendered HTML was fetched via loopback, extract clean visible text
        $rendered_text = '';
        if ( '' !== $rendered_html ) {
            $rendered_text = $this->html_to_visible_text( $rendered_html, 'page' );
        }

        $parts = array();
        if ( ! empty( $body_text ) ) {
            $parts[] = $body_text;
        }
        if ( ! empty( $elementor_text ) ) {
            $parts[] = $elementor_text;
        }
        if ( ! empty( $custom_meta_text ) ) {
            $parts[] = $custom_meta_text;
        }
        if ( ! empty( $rendered_text ) ) {
            // Append unique lines from rendered text that might be missed in DB content
            $parts[] = $rendered_text;
        }

        $full_text = trim( implode( "\n\n", array_unique( array_filter( $parts ) ) ) );
        if ( $has_form ) {
            $full_text .= ' [IMPORTANT: This page contains an interactive form the customer can fill out directly on this URL]';
        }

        return $prefix . 'Title: ' . $post->post_title . "\n" . $this->normalize_extracted_text( $full_text, 16000 );
    }

    /**
     * Return current live memory stats (with live vs pending counts).
     */
    public function get_memory_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'fg_ai_knowledge';

        $stats = array(
            'total'       => 0,
            'live'        => 0,
            'manual'      => 0,
            'pending'     => 0,
            'global_site' => 0,
            'content'     => 0,
            'shop'        => 0,
            'file'        => 0,
        );

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return $stats;
        }

        $rows = $wpdb->get_results( "SELECT scope, COUNT(*) as cnt FROM $table WHERE status = 'live' GROUP BY scope", ARRAY_A );
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $scope = is_array( $r ) ? ( isset( $r['scope'] ) ? $r['scope'] : 'content' ) : ( isset( $r->scope ) ? $r->scope : 'content' );
                $cnt   = is_array( $r ) ? ( isset( $r['cnt'] ) ? (int) $r['cnt'] : 1 ) : ( isset( $r->cnt ) ? (int) $r->cnt : 1 );
                $stats['total'] += $cnt;

                if ( 'custom' === $scope ) {
                    $stats['file'] += $cnt;
                } elseif ( isset( $stats[ $scope ] ) ) {
                    $stats[ $scope ] += $cnt;
                } else {
                    $stats['content'] += $cnt;
                }
            }
        }

        $live_custom = $this->get_live_custom_knowledge();
        $manual_cnt  = count( $live_custom );
        $stats['manual']  = $manual_cnt;
        $stats['pending'] = $manual_cnt; // backward-compatibility alias for UI pill
        $stats['live']    = $stats['total'];

        return $stats;
    }

    public function ajax_memory_stats() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        wp_send_json_success( $this->get_memory_stats() );
    }

    public function get_pending_custom_knowledge() {
        $list = get_option( 'advisio_pending_knowledge', array() );
        return is_array( $list ) ? $list : array();
    }

    /**
     * Re-sync custom/manual knowledge to wp_fg_ai_knowledge if needed.
     */
    public static function maybe_resync_manual_knowledge() {
        global $wpdb;
        $table = $wpdb->prefix . 'fg_ai_knowledge';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }
        $db_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE scope IN ('manual', 'file', 'custom')" );
        $live = get_option( 'advisio_live_knowledge', array() );
        if ( 0 === $db_count && ! empty( $live ) && is_array( $live ) ) {
            foreach ( $live as $item ) {
                if ( empty( $item['content'] ) ) continue;
                $clean_id = ! empty( $item['id'] ) ? sanitize_key( $item['id'] ) : 'cust_' . uniqid();
                $now      = current_time( 'mysql' );
                $wpdb->insert(
                    $table,
                    array(
                        'post_id'      => 0,
                        'source_id'    => 0,
                        'title'        => ! empty( $item['title'] ) ? sanitize_text_field( $item['title'] ) : 'Kunskap',
                        'content'      => trim( $item['content'] ),
                        'type'         => ( ! empty( $item['type'] ) && 'file' === $item['type'] ) ? 'file' : 'text',
                        'scope'        => 'manual',
                        'url'          => 'custom://manual/' . $clean_id,
                        'source_type'  => ! empty( $item['filename'] ) ? 'file:' . sanitize_file_name( $item['filename'] ) : 'manual',
                        'status'       => 'live',
                        'last_indexed' => $now,
                        'updated_at'   => $now,
                    ),
                    array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
                );
            }
        }
    }

    public function get_live_custom_knowledge() {
        $list = get_option( 'advisio_live_knowledge', null );
        if ( null === $list || ! is_array( $list ) ) {
            $legacy = get_option( 'advisio_manual_knowledge', array() );
            if ( empty( $legacy ) || ! is_array( $legacy ) ) {
                $legacy = get_option( 'advisio_manual_knowledge_backup', array() );
            }
            if ( is_array( $legacy ) && ! empty( $legacy ) ) {
                $list = $legacy;
                update_option( 'advisio_live_knowledge', $list );
            } else {
                $list = array();
            }
        }

        // Migrate any remaining pending items to unified live knowledge
        $pending = get_option( 'advisio_pending_knowledge', array() );
        if ( ! empty( $pending ) && is_array( $pending ) ) {
            foreach ( $pending as $p ) {
                $p['status'] = 'live';
                $list[]      = $p;
            }
            update_option( 'advisio_live_knowledge', $list );
            delete_option( 'advisio_pending_knowledge' );
        }

        return $list;
    }

    public function save_custom_knowledge_entry( $id, $title, $content, $type = 'text', $filename = '' ) {
        global $wpdb;
        $table_k = $wpdb->prefix . 'fg_ai_knowledge';

        $clean_id    = ! empty( $id ) ? sanitize_key( $id ) : 'cust_' . substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 10 );
        $clean_title = ! empty( trim( (string) $title ) ) ? sanitize_text_field( $title ) : ( 'file' === $type ? sanitize_file_name( $filename ) : 'Anpassad kunskap' );
        $clean_cont  = trim( (string) $content );
        $status      = 'live';
        $now         = current_time( 'mysql' );

        $item = array(
            'id'         => $clean_id,
            'title'      => $clean_title,
            'content'    => $clean_cont,
            'type'       => $type,
            'filename'   => $filename,
            'status'     => 'live',
            'created_at' => $now,
        );

        $live  = $this->get_live_custom_knowledge();
        $found = false;
        foreach ( $live as $idx => $l_item ) {
            if ( isset( $l_item['id'] ) && $l_item['id'] === $clean_id ) {
                $live[ $idx ] = $item;
                $found        = true;
                break;
            }
        }
        if ( ! $found ) {
            $live[] = $item;
        }
        update_option( 'advisio_live_knowledge', $live );

        // Remove from pending if previously present
        $pending = get_option( 'advisio_pending_knowledge', array() );
        if ( is_array( $pending ) && ! empty( $pending ) ) {
            $pending = array_values( array_filter( $pending, function( $p ) use ( $clean_id ) {
                return ( ! isset( $p['id'] ) || $p['id'] !== $clean_id );
            } ) );
            update_option( 'advisio_pending_knowledge', $pending );
        }

        // Store into table
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_k ) ) === $table_k ) {
            $url = 'custom://manual/' . $clean_id;
            $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_k WHERE url = %s LIMIT 1", $url ) );
            if ( $existing_id ) {
                $wpdb->update(
                    $table_k,
                    array(
                        'title'        => $clean_title,
                        'content'      => $clean_cont,
                        'status'       => 'live',
                        'last_indexed' => $now,
                        'updated_at'   => $now,
                    ),
                    array( 'id' => (int) $existing_id ),
                    array( '%s', '%s', '%s', '%s', '%s' ),
                    array( '%d' )
                );
            } else {
                $wpdb->insert(
                    $table_k,
                    array(
                        'post_id'      => 0,
                        'source_id'    => 0,
                        'title'        => $clean_title,
                        'content'      => $clean_cont,
                        'type'         => $type,
                        'scope'        => 'manual',
                        'url'          => $url,
                        'source_type'  => ! empty( $filename ) ? 'file:' . sanitize_file_name( $filename ) : 'manual_entry',
                        'status'       => 'live',
                        'last_indexed' => $now,
                        'updated_at'   => $now,
                    ),
                    array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
                );
            }
        }

        return array(
            'status' => 'live',
            'item'   => $item,
        );
    }

    public function delete_custom_knowledge_entry( $id ) {
        global $wpdb;
        $clean_id = sanitize_key( $id );
        $table_k  = $wpdb->prefix . 'fg_ai_knowledge';

        $pending = $this->get_pending_custom_knowledge();
        $new_p   = array();
        foreach ( $pending as $p ) {
            if ( isset( $p['id'] ) && $p['id'] === $clean_id ) {
                continue;
            }
            $new_p[] = $p;
        }
        update_option( 'advisio_pending_knowledge', $new_p );

        $live  = $this->get_live_custom_knowledge();
        $new_l = array();
        foreach ( $live as $l ) {
            if ( isset( $l['id'] ) && $l['id'] === $clean_id ) {
                continue;
            }
            $new_l[] = $l;
        }
        update_option( 'advisio_live_knowledge', $new_l );

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_k ) ) === $table_k ) {
            $wpdb->delete( $table_k, array( 'url' => 'custom://manual/' . $clean_id ), array( '%s' ) );
        }

        return true;
    }

    public function ajax_save_custom_knowledge() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $id      = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
        $title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $content = isset( $_POST['content'] ) ? trim( wp_unslash( $_POST['content'] ) ) : '';

        if ( empty( $content ) ) {
            wp_send_json_error( 'Innehållet kan inte vara tomt.' );
        }

        $res = $this->save_custom_knowledge_entry( $id, $title, $content, 'text' );
        wp_send_json_success( array(
            'item'    => $res['item'],
            'result'  => $res,
            'pending' => $this->get_pending_custom_knowledge(),
            'live'    => $this->get_live_custom_knowledge(),
            'stats'   => $this->get_memory_stats(),
        ) );
    }

    public function ajax_upload_custom_file() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! isset( $_FILES['file'] ) || empty( $_FILES['file']['name'] ) ) {
            wp_send_json_error( 'Ingen fil valdes.' );
        }

        $file     = $_FILES['file'];
        $ext      = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $filename = sanitize_file_name( $file['name'] );

        if ( ! in_array( $ext, array( 'txt', 'csv', 'md', 'json' ), true ) ) {
            wp_send_json_error( 'Ogiltigt filformat. Tillåtna format: .txt, .md, .csv, .json.' );
        }

        if ( $file['size'] > 5 * 1024 * 1024 ) {
            wp_send_json_error( 'Filen är för stor (max 5MB).' );
        }

        $file_content = file_get_contents( $file['tmp_name'] );
        if ( false === $file_content || empty( trim( $file_content ) ) ) {
            wp_send_json_error( 'Filen är tom eller kunde inte läsas.' );
        }

        if ( 'csv' === $ext ) {
            $parsed_text = $this->parse_csv_to_semantic_text( $file_content, $filename );
        } elseif ( 'json' === $ext ) {
            $j = json_decode( $file_content, true );
            $parsed_text = is_array( $j ) ? wp_json_encode( $j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : $file_content;
        } else {
            $parsed_text = $this->clean_page_builder_content( $file_content );
        }

        if ( empty( trim( $parsed_text ) ) ) {
            wp_send_json_error( 'Inget läsbart innehåll hittades i filen.' );
        }

        $res = $this->save_custom_knowledge_entry( '', $filename, $parsed_text, 'file', $filename );
        wp_send_json_success( array(
            'item'    => $res['item'],
            'result'  => $res,
            'pending' => $this->get_pending_custom_knowledge(),
            'live'    => $this->get_live_custom_knowledge(),
            'stats'   => $this->get_memory_stats(),
        ) );
    }

    /**
     * Parse CSV file contents into semantic key:value rows for the knowledge base.
     */
    public function parse_csv_to_semantic_text( $content, $filename = '' ) {
        $lines = preg_split( '/\r\n|\r|\n/', trim( (string) $content ) );
        if ( empty( $lines ) ) {
            return '';
        }
        $header_line = array_shift( $lines );
        $delimiter   = ( false !== strpos( $header_line, ';' ) ) ? ';' : ',';
        $headers     = str_getcsv( $header_line, $delimiter );
        $headers     = array_map( 'trim', (array) $headers );

        $output = array();
        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( empty( $line ) ) {
                continue;
            }
            $row = str_getcsv( $line, $delimiter );
            $row_parts = array();
            foreach ( $headers as $idx => $header ) {
                $val = isset( $row[ $idx ] ) ? trim( (string) $row[ $idx ] ) : '';
                if ( '' !== $val ) {
                    $row_parts[] = ( ! empty( $header ) ? $header . ': ' : '' ) . $val;
                }
            }
            if ( ! empty( $row_parts ) ) {
                $output[] = implode( ' | ', $row_parts );
            }
        }
        return implode( "\n", $output );
    }

    public function get_dna_staged_items() {
        $staged = get_option( 'advisio_pending_box_4', array() );
        return array( 'strategies' => is_array( $staged ) ? $staged : array() );
    }

    public function save_dna_staged_items( $data ) {
        $strategies = isset( $data['strategies'] ) && is_array( $data['strategies'] ) ? $data['strategies'] : array();
        return update_option( 'advisio_pending_box_4', $strategies );
    }

    public static function get_manual_list() {
        return advisio_ai()->get_live_custom_knowledge();
    }

    public static function upsert_manual_entry( $id, $title, $content ) {
        return advisio_ai()->save_custom_knowledge_entry( $id, $title, $content, 'text' );
    }

    public static function build_manual_entry( $id, $title, $content ) {
        return array( 'id' => $id, 'title' => $title, 'content' => $content );
    }

    public static function save_manual_list( $list ) {
        return true;
    }

    public static function sync_manual_knowledge_to_table() {
        return self::maybe_resync_manual_knowledge();
    }

    public static function ensure_tables_ready() {
        advisio_ensure_tables_ready();
    }

    public function ajax_delete_custom_knowledge() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
        if ( empty( $id ) ) {
            wp_send_json_error( 'Saknar ID.' );
        }

        $this->delete_custom_knowledge_entry( $id );
        wp_send_json_success( array(
            'id'      => $id,
            'pending' => $this->get_pending_custom_knowledge(),
            'live'    => $this->get_live_custom_knowledge(),
            'stats'   => $this->get_memory_stats(),
        ) );
    }

    public function ajax_get_custom_knowledge() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        wp_send_json_success( array(
            'pending' => $this->get_pending_custom_knowledge(),
            'live'    => $this->get_live_custom_knowledge(),
        ) );
    }


    /**
     * AJAX: Approve or Reject an AI Strategy Proposal from Tab 1.
     */
    public function ajax_handle_proposal() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table_proposals = $wpdb->prefix . 'fg_ai_proposals';

        $prop_id  = isset( $_POST['proposal_id'] ) ? intval( $_POST['proposal_id'] ) : 0;
        $decision = isset( $_POST['decision'] ) ? sanitize_text_field( wp_unslash( $_POST['decision'] ) ) : '';

        $proposal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_proposals WHERE id = %d", $prop_id ) );
        if ( ! $proposal ) {
            wp_send_json_error( 'Proposal not found.' );
        }

        $current_strategies = get_option( 'advisio_approved_strategies', '' );
        $rule_line          = '- ' . trim( $proposal->proposal_text );

        if ( 'approve' === $decision ) {
            $wpdb->update(
                $table_proposals,
                array( 'status' => 'approved' ),
                array( 'id' => $prop_id ),
                array( '%s' ),
                array( '%d' )
            );

            if ( false === strpos( $current_strategies, $proposal->proposal_text ) ) {
                $updated_strategies = trim( $current_strategies . "\n" . $rule_line );
                update_option( 'advisio_approved_strategies', $updated_strategies );

                // Route automatically into Pillar 4 (Learning Strategies) staging queue
                $staged_dna = $this->get_dna_staged_items();
                $already_staged = false;
                foreach ( $staged_dna['strategies'] as $stg ) {
                    if ( isset( $stg['text'] ) && trim( $stg['text'] ) === trim( $proposal->proposal_text ) ) {
                        $already_staged = true;
                        break;
                    }
                }
                if ( ! $already_staged ) {
                    $staged_dna['strategies'][] = array(
                        'id'   => 'strat_' . substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 8 ),
                        'text' => trim( $proposal->proposal_text ),
                    );
                    $this->save_dna_staged_items( $staged_dna );
                }
                $updated_strategies = trim( $current_strategies . "\n" . $rule_line );
                update_option( 'advisio_approved_strategies', $updated_strategies );
            } else {
                $updated_strategies = $current_strategies;
            }

            $pending_left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_proposals WHERE status = 'pending'" );
            wp_send_json_success( array(
                'status'     => 'approved',
                'strategies' => $updated_strategies,
                'pending'    => $pending_left,
            ) );
        } else {
            $wpdb->update(
                $table_proposals,
                array( 'status' => 'rejected' ),
                array( 'id' => $prop_id ),
                array( '%s' ),
                array( '%d' )
            );

            $updated_strategies = trim( str_replace( $rule_line, '', $current_strategies ) );
            update_option( 'advisio_approved_strategies', $updated_strategies );

            $pending_left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_proposals WHERE status = 'pending'" );
            wp_send_json_success( array(
                'status'     => 'rejected',
                'strategies' => $updated_strategies,
                'pending'    => $pending_left,
            ) );
        }
    }

    /**
     * AJAX: Manual trigger for the 50-Chat AI Learning Engine.
     */
    public function ajax_run_learning_engine() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $generated = $this->generate_ai_strategy_proposals( true );
        if ( is_wp_error( $generated ) ) {
            wp_send_json_error( $generated->get_error_message() );
        }

        wp_send_json_success( array(
            'proposals' => $generated,
        ) );
    }

    /**
     * WP-Cron background runner for the 50-Chat Learning Engine.
     */
    public function cron_run_learning_engine() {
        $this->generate_ai_strategy_proposals( false );
    }

    /**
     * Core 50-Chat Autonomous Learning Engine logic (with strict 12s HTTP timeout).
     */
    private function generate_ai_strategy_proposals( $force = false ) {
        global $wpdb;
        $table_logs      = $wpdb->prefix . 'fg_ai_logs';
        $table_proposals = $wpdb->prefix . 'fg_ai_proposals';

        $total_sessions    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT session_id) FROM $table_logs WHERE is_lead = 0" );
        $last_analyzed_cnt = (int) get_option( 'advisio_last_analyzed_session_count', 0 );

        if ( ! $force && ( $total_sessions - $last_analyzed_cnt ) < 50 ) {
            return array();
        }

        $api_key = get_option( 'advisio_api_key', '' );
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'Gemini API Key is not configured yet.' );
        }

        $recent_logs = $wpdb->get_results( "SELECT session_id, user_message, bot_reply FROM $table_logs WHERE is_lead = 0 ORDER BY id DESC LIMIT 120" );
        $transcript_sample = '';
        if ( ! empty( $recent_logs ) ) {
            foreach ( array_slice( $recent_logs, 0, 60 ) as $row ) {
                $transcript_sample .= "Customer: " . $row->user_message . "\nAI: " . wp_strip_all_tags( $row->bot_reply ) . "\n---\n";
            }
        } else {
            $transcript_sample = "Sample trends: Customers frequently ask about cheapest 2-glass/3-glass window options, how to measure wall openings (karmyttermått), U-values, claims/reklamation, showroom opening hours, and delivery times.";
        }

        $inquiries      = $this->get_dual_top_inquiries();
        $trends_summary = "Support Trends:\n";
        foreach ( $inquiries['support'] as $k => $v ) {
            $trends_summary .= "- $k: {$v['count']}\n";
        }
        $trends_summary .= "\nProduct Trends:\n";
        foreach ( $inquiries['products'] as $k => $v ) {
            $trends_summary .= "- $k: {$v['count']}\n";
        }

        $analysis_prompt = "You are an AI Conversion & Customer Support Strategist for a Scandinavian Window & Door e-commerce store.\n" .
            "Analyze the following customer inquiry trends and recent chat logs:\n\n" .
            $trends_summary . "\n" .
            "Recent Chat Logs:\n" . substr( $transcript_sample, 0, 6000 ) . "\n\n" .
            "Generate between 3 and 4 specific, actionable AI strategy proposals to improve customer support and guided selling.\n" .
            "Return ONLY a valid JSON array of objects with these exact keys:\n" .
            "[\n" .
            "  {\n" .
            "    \"category\": \"Product Strategy\" or \"Support Optimization\" or \"Guided Selling\",\n" .
            "    \"rationale\": \"I noticed many customers ask for [X]; I propose [Y]\",\n" .
            "    \"proposal_text\": \"Clear instruction rule to append to the AI system prompt.\"\n" .
            "  }\n" .
            "]";

        $payload = array(
            'contents'         => array( array( 'parts' => array( array( 'text' => $analysis_prompt ) ) ) ),
            'generationConfig' => array(
                'temperature'     => 0.35,
                'maxOutputTokens' => 700,
                'topP'            => 0.8,
            ),
        );

        $res = $this->call_gemini_api( $api_key, $payload, 12 );
        if ( ! $res['success'] ) {
            return new WP_Error( 'api_error', $res['error'] );
        }

        $raw_text = trim( $res['text'] );
        $raw_text = preg_replace( '/^```(?:json)?\s*/i', '', $raw_text );
        $raw_text = preg_replace( '/\s*```$/', '', $raw_text );

        $decoded = json_decode( $raw_text, true );
        if ( ! is_array( $decoded ) || empty( $decoded ) ) {
            return new WP_Error( 'parse_error', 'Could not parse AI strategy proposals JSON.' );
        }

        update_option( 'advisio_last_analyzed_session_count', $total_sessions );
        $batch_num = (int) get_option( 'advisio_proposal_batch_num', 0 ) + 1;
        update_option( 'advisio_proposal_batch_num', $batch_num );

        $inserted_cards = array();
        foreach ( array_slice( $decoded, 0, 5 ) as $item ) {
            if ( empty( $item['proposal_text'] ) ) {
                continue;
            }
            $cat  = isset( $item['category'] ) ? sanitize_text_field( $item['category'] ) : 'Guided Selling';
            $rat  = isset( $item['rationale'] ) ? sanitize_textarea_field( $item['rationale'] ) : 'Observed pattern in customer inquiries.';
            $text = sanitize_textarea_field( $item['proposal_text'] );

            $wpdb->insert(
                $table_proposals,
                array(
                    'proposal_text' => $text,
                    'rationale'     => $rat,
                    'category'      => $cat,
                    'status'        => 'pending',
                    'batch_number'  => $batch_num,
                    'created_at'    => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s', '%s', '%d', '%s' )
            );

            $inserted_cards[] = array(
                'id'            => $wpdb->insert_id,
                'category'      => $cat,
                'rationale'     => $rat,
                'proposal_text' => $text,
                'created_at'    => date( 'Y-m-d H:i' ),
            );
        }

        return $inserted_cards;
    }

    /**
     * Extract and expand keywords with Swedish & English synonym mapping.
     */
    private function get_expanded_keywords( $user_message ) {
        $lower_msg = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $user_message, 'UTF-8' ) : strtolower( (string) $user_message );
        $clean_msg = preg_replace( '/[^\p{L}\p{N}\s\-]/u', ' ', $lower_msg );
        $raw_words = preg_split( '/\s+/', $clean_msg, -1, PREG_SPLIT_NO_EMPTY );
        $stopwords = array(
            'och', 'att', 'det', 'som', 'en', 'ett', 'på', 'är', 'för', 'med', 'till',
            'den', 'har', 'de', 'inte', 'om', 'jag', 'vill', 'kan', 'hur', 'vad', 'finns',
            'skulle', 'vilja', 'behöver', 'the', 'and', 'for', 'with', 'what', 'how', 'have',
            'you', 'are', 'there', 'any', 'där', 'här', 'var', 'vart', 'när', 'vilken', 'vilka',
        );

        $keywords = array();
        foreach ( (array) $raw_words as $w ) {
            if ( mb_strlen( $w, 'UTF-8' ) >= 3 && ! in_array( $w, $stopwords, true ) ) {
                $keywords[] = $w;
            }
        }

        // Deep synonym mapping across key business areas
        $synonym_map = array(
            // Store / Physical presence / Showroom
            'butik'       => array( 'showroom', 'utställning', 'besöksadress', 'öppettider', 'adress', 'butik', 'fysisk', 'besök' ),
            'fysisk'      => array( 'showroom', 'butik', 'utställning', 'besöksadress', 'öppettider', 'adress' ),
            'showroom'    => array( 'butik', 'utställning', 'besöksadress', 'öppettider', 'adress' ),
            'besök'       => array( 'showroom', 'öppettider', 'adress', 'utställning' ),
            'öppettider'  => array( 'showroom', 'öppettider', 'butik', 'vardagar', 'öppet' ),
            // Claims / Reklamation
            'reklamation' => array( 'reklamation', 'reklamera', 'skada', 'trasig', 'retur', 'garanti', 'formulär', 'fel' ),
            'reklamera'   => array( 'reklamation', 'reklamera', 'skada', 'trasig', 'retur', 'garanti' ),
            'trasig'      => array( 'reklamation', 'skada', 'garanti', 'reklamera' ),
            'skada'       => array( 'reklamation', 'skada', 'reklamera', 'transportskada' ),
            'klagomål'    => array( 'reklamation', 'kundtjänst', 'villkor' ),
            'ångerrätt'   => array( 'köpvillkor', 'retur', 'ångerrätt', 'villkor' ),
            'retur'       => array( 'reklamation', 'ångerrätt', 'köpvillkor', 'retur' ),
            'garanti'     => array( 'garanti', 'köpvillkor', 'reklamation' ),
            // Partnership / B2B
            'partner'     => array( 'partnerskap', 'partner', 'samarbete', 'återförsäljare', 'b2b', 'företag' ),
            'samarbete'   => array( 'partnerskap', 'partner', 'samarbete', 'kontakt' ),
            'återförsälj' => array( 'partnerskap', 'partner', 'återförsäljare', 'företagskund' ),
            // Measurement / Sizes
            'mått'        => array( 'mätguide', 'mäta', 'karmyttermått', 'modulmått', 'storlek', 'mått' ),
            'mäta'        => array( 'mätguide', 'mäta fönster', 'karmyttermått', 'modulmått' ),
            'storlek'     => array( 'mått', 'dimension', 'mätguide' ),
            // Mounting / Installation
            'montering'   => array( 'monteringsanvisning', 'montera', 'installation', 'karmskruv' ),
            'montera'     => array( 'monteringsanvisning', 'installation', 'montering' ),
            'installera'  => array( 'monteringsanvisning', 'installation', 'montering' ),
            // Delivery / Shipping
            'leverans'    => array( 'leveransvillkor', 'frakt', 'leveranstid', 'transport' ),
            'frakt'       => array( 'leveransvillkor', 'fraktkostnad', 'leverans' ),
            // Price / Quote
            'offert'      => array( 'offert', 'kontakt', 'pris', 'kundtjänst' ),
            'pris'        => array( 'pris', 'erbjudande', 'kampanj' ),
        );

        foreach ( $synonym_map as $trigger => $expansions ) {
            if ( false !== mb_strpos( $clean_msg, $trigger, 0, 'UTF-8' ) ) {
                foreach ( $expansions as $exp ) {
                    $keywords[] = $exp;
                }
            }
        }

        if ( empty( $keywords ) ) {
            $keywords = array( trim( (string) $user_message ) );
        }

        return array_slice( array_unique( array_filter( $keywords ) ), 0, 10 );
    }

    /**
     * Gather guaranteed Core Company Facts & Showroom details from scope = 'manual' and scope = 'global_site'.
     * Injected at the ABSOLUTE TOP of the Gemini system prompt as highest-priority truth.
     */
    private function get_core_company_facts_prompt() {
        global $wpdb;
        $table = $wpdb->prefix . 'fg_ai_knowledge';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return '';
        }

        $records = $wpdb->get_results(
            "SELECT title, content, url, scope
             FROM $table
             WHERE scope IN ('manual', 'global_site') AND status = 'live'
             ORDER BY CASE WHEN scope = 'manual' THEN 1 ELSE 2 END, id ASC
             LIMIT 100",
            ARRAY_A
        );

        if ( empty( $records ) ) {
            return '';
        }

        $lines = array();
        foreach ( $records as $rec ) {
            $tag = ( 'manual' === $rec['scope'] ) ? '[MANUAL FACT / OVERRIDE]' : '[VERIFIED SITE FACT]';
            $url_part = ( ! empty( $rec['url'] ) && 0 !== strpos( $rec['url'], 'custom://' ) ) ? ' (URL: ' . $rec['url'] . ')' : '';
            $lines[] = $tag . ' ' . $rec['title'] . $url_part . ":\n" . mb_substr( $rec['content'], 0, 1400, 'UTF-8' );
        }

        return "=== CRITICAL STORE FACTS, SHOWROOM & MANUAL OVERRIDES (HIGHEST PRIORITY TRUTH) ===\n" .
            "The following facts represent verified, ground-truth company information (including Showroom locations, visiting addresses, opening hours, customer service contact, and manual business rules):\n\n" .
            implode( "\n\n", $lines ) . "\n\n" .
            "MANDATORY TRUTH RULES:\n" .
            "1. If the customer asks if you have a physical store, shop, showroom, or visiting address, you MUST answer YES using the exact address, opening hours, and showroom details above. NEVER claim you do not have a physical location or showroom.\n" .
            "2. If any manual override fact conflicts with general website text, the manual fact ALWAYS wins.\n\n";
    }

    /**
     * Diagnostic retrieval runner for the AI Memory Inspector tester.
     */
    public function perform_diagnostic_retrieval( $query ) {
        global $wpdb;
        $table = $wpdb->prefix . 'fg_ai_knowledge';

        $keywords    = $this->get_expanded_keywords( $query );
        $where_parts = array();
        $score_parts = array();
        $where_args  = array();
        $score_args  = array();

        foreach ( $keywords as $kw ) {
            $like          = '%' . $wpdb->esc_like( $kw ) . '%';
            $where_parts[] = '(title LIKE %s OR url LIKE %s OR content LIKE %s)';
            $where_args[]  = $like;
            $where_args[]  = $like;
            $where_args[]  = $like;

            $score_parts[] = '(CASE WHEN title LIKE %s THEN 5 WHEN url LIKE %s THEN 4 WHEN content LIKE %s THEN 1 ELSE 0 END)';
            $score_args[]  = $like;
            $score_args[]  = $like;
            $score_args[]  = $like;
        }

        $where_sql = implode( ' OR ', $where_parts );
        $score_sql = implode( ' + ', $score_parts );
        $all_args  = array_merge( $score_args, $where_args );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, content, url, scope, source_type, ($score_sql) AS relevance_score, LEFT(content, 260) as preview
             FROM $table
             WHERE ($where_sql)
             ORDER BY relevance_score DESC, id ASC
             LIMIT 10",
            $all_args
        ), ARRAY_A );

        return array(
            'keywords' => $keywords,
            'records'  => $results ? $results : array(),
        );
    }

    /**
     * Multi-Keyword Tokenized Hybrid RAG Search across `wp_fg_ai_knowledge`.
     * Formats every record with exact SOURCE TITLE, PAGE URL, and CONTENT for accurate subpage & form linking.
     */
    private function retrieve_relevant_knowledge( $user_message, $product_id = 0 ) {
        global $wpdb;
        $table_k = $wpdb->prefix . 'fg_ai_knowledge';

        $context = '';

        // If viewing a single product page, inject its live WooCommerce details first
        if ( $product_id > 0 && function_exists( 'wc_get_product' ) ) {
            $prod = wc_get_product( $product_id );
            if ( $prod ) {
                $price_txt = html_entity_decode( wp_strip_all_tags( wc_price( wc_get_price_to_display( $prod ) ) ) );
                $context  .= sprintf(
                    "SOURCE TITLE: %s (Current Product Viewed) | PAGE URL: %s | CONTENT: Base Price: %s\n\n",
                    $prod->get_name(),
                    get_permalink( $product_id ),
                    $price_txt
                );
            }
        }

        $keywords = $this->get_expanded_keywords( $user_message );

        $where_parts = array();
        $score_parts = array();
        $where_args  = array();
        $score_args  = array();

        foreach ( $keywords as $kw ) {
            $like          = '%' . $wpdb->esc_like( $kw ) . '%';
            $where_parts[] = '(title LIKE %s OR url LIKE %s OR content LIKE %s)';
            $where_args[]  = $like;
            $where_args[]  = $like;
            $where_args[]  = $like;

            // Prioritize exact titles and policy/subpage URLs higher than deep body text
            $score_parts[] = '(CASE WHEN title LIKE %s THEN 5 WHEN url LIKE %s THEN 4 WHEN content LIKE %s THEN 1 ELSE 0 END)';
            $score_args[]  = $like;
            $score_args[]  = $like;
            $score_args[]  = $like;
        }

        $where_sql = implode( ' OR ', $where_parts );
        $score_sql = implode( ' + ', $score_parts );
        $all_args  = array_merge( $score_args, $where_args );

        // We exclude manual here because manual entries are already injected guaranteed at the top of the prompt
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT title, content, url, scope, ($score_sql) AS relevance_score
             FROM $table_k
             WHERE scope != 'manual' AND status = 'live' AND ($where_sql)
             ORDER BY relevance_score DESC, id ASC
             LIMIT 6",
            $all_args
        ) );

        if ( ! empty( $results ) ) {
            foreach ( $results as $r ) {
                $clean_url = ( 0 === strpos( $r->url, 'custom://' ) ) ? home_url( '/' ) : $r->url;
                $snippet   = mb_substr( $r->content, 0, 1400, 'UTF-8' );
                $context  .= sprintf(
                    "SOURCE TITLE: %s | PAGE URL: %s | CONTENT: %s\n\n",
                    $r->title,
                    $clean_url,
                    $snippet
                );
            }
        }

        $words = preg_split( '/\s+/', $context );
        if ( count( $words ) > 1400 ) {
            $context = implode( ' ', array_slice( $words, 0, 1400 ) );
        }

        return $context;
    }

    /**
     * AJAX: Handle Frontend Chat Message & Enforce All Behavioral, Subpage Linking, and Hierarchy Rules.
     */
    public function fg_handle_chat_message() {
        check_ajax_referer( 'advisio_chat_nonce', 'nonce' );

        $session_id   = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : wp_generate_password( 12, false );
        $user_message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';
        $history_json = isset( $_POST['history'] ) ? wp_unslash( $_POST['history'] ) : '[]';
        $product_id   = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;

        if ( empty( $user_message ) ) {
            wp_send_json_error( 'Empty message.' );
        }

        $api_key = get_option( 'advisio_api_key', '' );
        if ( empty( $api_key ) ) {
            wp_send_json_error( 'API Key is not configured.' );
        }

        // 1. Core Company Facts & Showroom details (Highest priority ground-truth at absolute top)
        $core_facts = $this->get_core_company_facts_prompt();

        // 2. Guaranteed Custom Knowledge & Uploaded Files (scope = 'manual') - 100% injected
        global $wpdb;
        $table_k = $wpdb->prefix . 'fg_ai_knowledge';
        $manual_records = $wpdb->get_results(
            "SELECT title, content, url FROM $table_k WHERE scope IN ('manual', 'file', 'custom') AND status = 'live' ORDER BY id DESC LIMIT 100",
            ARRAY_A
        );
        $live_custom = $this->get_live_custom_knowledge();
        if ( ! empty( $live_custom ) && is_array( $live_custom ) ) {
            $existing_titles = ! empty( $manual_records ) ? array_column( (array) $manual_records, 'title' ) : array();
            if ( ! is_array( $manual_records ) ) {
                $manual_records = array();
            }
            foreach ( $live_custom as $l_item ) {
                $t = isset( $l_item['title'] ) ? $l_item['title'] : 'Kunskap';
                if ( ! in_array( $t, $existing_titles, true ) ) {
                    $manual_records[] = array(
                        'title'   => $t,
                        'content' => isset( $l_item['content'] ) ? $l_item['content'] : '',
                        'url'     => '',
                    );
                }
            }
        }
        $custom_knowledge_prompt = "";
        if ( ! empty( $manual_records ) ) {
            $custom_knowledge_prompt .= "=== VERIFIED CUSTOM KNOWLEDGE & UPLOADED FILES (ALWAYS TRUE) ===\n";
            foreach ( $manual_records as $m_rec ) {
                $custom_knowledge_prompt .= sprintf(
                    "TOPIC: %s | CONTENT: %s\n",
                    $m_rec['title'],
                    $m_rec['content']
                );
            }
            $custom_knowledge_prompt .= "\n";
        }

        // 3. Dynamic RAG Retrieval (Top 5 trimmed results)
        $context = $this->retrieve_relevant_knowledge( $user_message, $product_id );

        // 4. Advisio Master DNA (Compiled single production instruction)
        $master_dna = $this->get_or_bootstrap_master_dna();
        $lang       = get_option( 'advisio_language', 'Swedish' );
        $chip_labels = self::get_frontend_chip_labels( $lang );

        $full_system_instruction =
            $core_facts . "\n" .
            $custom_knowledge_prompt .
            "=== ADVISIO MASTER DNA (BEHAVIOR, PERSONA & GOALS) ===\n" .
            $master_dna . "\n\n" .
            "=== CONTEXT-LOCKED QUICK-REPLY CHIPS (MANDATORY FORMAT) ===\n" .
            "- At the end of your response, ask ONE clear guiding question. The `[SUGGESTIONS: Option 1 | Option 2 | Option 3 | " . $chip_labels['not_sure_raw'] . "]` tag you generate at the very end MUST contain 2–3 direct, concrete answers to that exact question, followed by '" . $chip_labels['not_sure_raw'] . "' (or equivalent in the active language).\n" .
            "- Example 1: If you ask \"Vilken typ av fönster letar du efter?\", the chips MUST be `[SUGGESTIONS: Fast fönster | Öppningsbart (Vridfönster) | Altandörr | " . $chip_labels['not_sure_raw'] . "]`.\n" .
            "- Example 2: If you ask \"Önskar du 2-glas eller 3-glas isolerglas?\", the chips MUST be `[SUGGESTIONS: 2-glas | 3-glas (Bäst isolering) | " . $chip_labels['not_sure_raw'] . "]`.\n" .
            "- Example 3: If you ask for exact measurements (Width x Height), NEVER output fake number chips; output `[SUGGESTIONS: " . $chip_labels['measure_help'] . " | " . $chip_labels['not_sure_measurements'] . "]` so the customer can either type their numbers or click for help.\n\n" .
            "=== KNOWLEDGE BASE CONTEXT (SEARCHED STORE PAGES & PRODUCTS) ===\n" .
            $context;
$history = json_decode( $history_json, true );
        if ( ! is_array( $history ) ) {
            $history = array();
        }

        $contents = array();
        foreach ( $history as $msg ) {
            if ( empty( $msg['content'] ) ) {
                continue;
            }
            $contents[] = array(
                'role'  => ( isset( $msg['role'] ) && 'user' === $msg['role'] ) ? 'user' : 'model',
                'parts' => array( array( 'text' => (string) $msg['content'] ) ),
            );
        }
        $contents[] = array(
            'role'  => 'user',
            'parts' => array( array( 'text' => $user_message ) ),
        );

        $payload = array(
            'systemInstruction' => array(
                'parts' => array( array( 'text' => $full_system_instruction ) ),
            ),
            'contents'          => $contents,
            'generationConfig'  => array(
                'temperature'     => 0.35,
                'maxOutputTokens' => 700,
                'topP'            => 0.8,
            ),
        );

        $res = $this->call_gemini_api( $api_key, $payload, 25 );
        if ( ! $res['success'] ) {
            wp_send_json_error( $res['error'] );
        }

        $reply = $res['text'];

        global $wpdb;
        $table_logs = $wpdb->prefix . 'fg_ai_logs';
        $wpdb->insert(
            $table_logs,
            array(
                'session_id'   => $session_id,
                'user_message' => $user_message,
                'bot_reply'    => $reply,
                'is_lead'      => 0,
                'created_at'   => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%d', '%s' )
        );

        // Automatic 50-session learning check (runs via cron so chat user never waits)
        $total_sessions    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT session_id) FROM $table_logs WHERE is_lead = 0" );
        $last_analyzed_cnt = (int) get_option( 'advisio_last_analyzed_session_count', 0 );
        if ( ( $total_sessions - $last_analyzed_cnt ) >= 50 ) {
            if ( ! wp_next_scheduled( 'advisio_run_learning_engine_cron' ) ) {
                wp_schedule_single_event( time() + 30, 'advisio_run_learning_engine_cron' );
            }
        }

        wp_send_json_success( array(
            'reply' => $reply,
            'model' => $res['model'],
        ) );
    }

    /**
     * Generate a concise 3-4 bullet point AI Summary of the conversation upon Lead Handover (12s timeout).
     */
    private function generate_lead_conversation_summary( $history_arr, $clean_fields ) {
        $api_key = get_option( 'advisio_api_key', '' );
        $lang    = get_option( 'advisio_language', 'Swedish' );

        $transcript_lines = array();
        if ( is_array( $history_arr ) ) {
            foreach ( $history_arr as $turn ) {
                $role = ( isset( $turn['role'] ) && 'user' === $turn['role'] ) ? 'Customer' : 'Advisio AI';
                $text = isset( $turn['content'] ) ? trim( (string) $turn['content'] ) : '';
                if ( '' !== $text ) {
                    $transcript_lines[] = "$role: $text";
                }
            }
        }

        if ( empty( $transcript_lines ) || empty( $api_key ) ) {
            $fallback = array(
                '• Customer requested direct advisor contact via the lead form.',
            );
            foreach ( $clean_fields as $k => $v ) {
                $fallback[] = '• ' . $k . ': ' . $v;
            }
            return implode( "\n", array_slice( $fallback, 0, 4 ) );
        }

        $prompt = "Summarize the following customer chat conversation into 3 to 4 concise bullet points (using '• ') in $lang for a sales representative:\n" .
            "1. Customer need / intent\n" .
            "2. Main product & specifications discussed\n" .
            "3. Measurements (Width x Height) if mentioned\n" .
            "4. Key questions or special requests\n\n" .
            "Conversation:\n" . implode( "\n", $transcript_lines );

        $payload = array(
            'contents'         => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ),
            'generationConfig' => array(
                'temperature'     => 0.35,
                'maxOutputTokens' => 700,
                'topP'            => 0.8,
            ),
        );

        $res = $this->call_gemini_api( $api_key, $payload, 12 );
        if ( $res['success'] && ! empty( $res['text'] ) ) {
            return trim( wp_strip_all_tags( $res['text'] ) );
        }

        return "• Customer chatted with Advisio AI (" . count( $transcript_lines ) . " messages) and requested sales follow-up.";
    }

    /**
     * AJAX: Handle Dynamic Lead Form Submission with Automatic AI Conversation Summary.
     */
    public function handle_submit_lead() {
        check_ajax_referer( 'advisio_chat_nonce', 'nonce' );

        $session_id        = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : wp_generate_password( 12, false );
        $preferred_contact = isset( $_POST['preferred_contact'] ) ? sanitize_text_field( wp_unslash( $_POST['preferred_contact'] ) ) : 'Email';
        $fields_json       = isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : '{}';
        $history_json      = isset( $_POST['history'] ) ? wp_unslash( $_POST['history'] ) : '[]';

        $submitted_fields = json_decode( $fields_json, true );
        if ( ! is_array( $submitted_fields ) ) {
            $submitted_fields = array();
        }

        $clean_fields = array();
        foreach ( $submitted_fields as $label => $value ) {
            $clean_fields[ sanitize_text_field( $label ) ] = sanitize_textarea_field( $value );
        }

        $history_arr = json_decode( $history_json, true );
        if ( ! is_array( $history_arr ) ) {
            $history_arr = array();
        }

        // Generate 3-4 bullet point AI Summary of the conversation
        $ai_summary = $this->generate_lead_conversation_summary( $history_arr, $clean_fields );

        $admin_email = get_option( 'advisio_notify_email', get_option( 'admin_email' ) );
        $subject     = 'New Lead & AI Summary — Advisio AI Chat Service';

        // Build HTML Email with highlighted AI Summary box at the top
        $html_body  = '<div style="font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; color: #0F172A; max-width: 640px; margin: 0 auto;">';
        $html_body .= '<h2 style="color: #4F46E5; margin-bottom: 12px;">New Lead Captured via Advisio AI</h2>';
        $html_body .= '<div style="background: #EEF2FF; border-left: 4px solid #6366F1; padding: 16px; border-radius: 8px; margin-bottom: 20px;">';
        $html_body .= '<strong style="color: #3730A3; display: block; margin-bottom: 8px; font-size: 14px; text-transform: uppercase;">🤖 AI Quick Summary of Conversation</strong>';
        $html_body .= '<div style="font-size: 14px; line-height: 1.6; color: #1E293B;">' . nl2br( esc_html( $ai_summary ) ) . '</div>';
        $html_body .= '</div>';

        $html_body .= '<h3 style="font-size: 16px; margin-bottom: 8px;">Customer Details</h3>';
        $html_body .= '<p style="margin: 4px 0;"><strong>Preferred Contact Method:</strong> ' . esc_html( $preferred_contact ) . '</p>';
        foreach ( $clean_fields as $lbl => $val ) {
            $html_body .= '<p style="margin: 4px 0;"><strong>' . esc_html( $lbl ) . ':</strong> ' . esc_html( $val ) . '</p>';
        }

        $html_body .= '<hr style="border: none; border-top: 1px solid #CBD5E1; margin: 20px 0;">';
        $html_body .= '<h3 style="font-size: 15px; color: #475569;">Full Chat Transcript</h3>';
        foreach ( $history_arr as $turn ) {
            $role = ( isset( $turn['role'] ) && 'user' === $turn['role'] ) ? 'Customer' : 'Advisio AI';
            $text = isset( $turn['content'] ) ? $turn['content'] : '';
            $html_body .= '<p style="font-size: 13px; margin: 6px 0;"><strong>[' . esc_html( $role ) . ']:</strong> ' . nl2br( esc_html( $text ) ) . '</p>';
        }
        $html_body .= '</div>';

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        wp_mail( $admin_email, $subject, $html_body, $headers );

        global $wpdb;
        $table_logs   = $wpdb->prefix . 'fg_ai_logs';
        $lead_payload = wp_json_encode( array(
            'preferred_contact' => $preferred_contact,
            'fields'            => $clean_fields,
            'ai_summary'        => $ai_summary,
        ) );

        $wpdb->insert(
            $table_logs,
            array(
                'session_id'   => $session_id,
                'user_message' => $lead_payload,
                'bot_reply'    => $ai_summary,
                'is_lead'      => 1,
                'created_at'   => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%d', '%s' )
        );

        wp_send_json_success( array(
            'message'    => 'Lead captured successfully.',
            'ai_summary' => $ai_summary,
        ) );
    }

    /**
     * Inject dynamic CSS variables into frontend head (Zero DB queries beyond cached WP options).
     */
    public function inject_dynamic_css() {
        $primary = esc_attr( get_option( 'advisio_color_primary', '#6366F1' ) );
        $header  = esc_attr( get_option( 'advisio_color_header', '#1E293B' ) );
        ?>
        <style id="advisio-dynamic-colors">
            :root {
                --advisio-primary: <?php echo $primary; ?>;
                --advisio-header: <?php echo $header; ?>;
            }
        </style>
        <?php
    }

    /**
     * Localized helper labels for frontend quick-reply chips (How to measure & Not sure).
     */
    public static function get_frontend_chip_labels( $lang ) {
        $map = array(
            'Swedish'   => array(
                'measure_help'          => 'Hur mäter jag?',
                'not_sure_measurements' => 'Inte säker på måtten',
                'not_sure'              => 'Inte säker',
                'not_sure_raw'          => 'Inte säker',
            ),
            'English'   => array(
                'measure_help'          => 'How do I measure?',
                'not_sure_measurements' => 'Not sure about dimensions',
                'not_sure'              => 'Not sure',
                'not_sure_raw'          => 'Not sure',
            ),
            'Norwegian' => array(
                'measure_help'          => 'Hvordan måler jeg?',
                'not_sure_measurements' => 'Usikker på målene',
                'not_sure'              => 'Usikker',
                'not_sure_raw'          => 'Usikker',
            ),
            'Danish'    => array(
                'measure_help'          => 'Hvordan måler jeg?',
                'not_sure_measurements' => 'Ikke sikker på målene',
                'not_sure'              => 'Ikke sikker',
                'not_sure_raw'          => 'Ikke sikker',
            ),
            'German'    => array(
                'measure_help'          => 'Wie messe ich richtig?',
                'not_sure_measurements' => 'Bei den Maßen unsicher',
                'not_sure'              => 'Nicht sicher',
                'not_sure_raw'          => 'Nicht sicher',
            ),
            'Finnish'   => array(
                'measure_help'          => 'Miten mittaan?',
                'not_sure_measurements' => 'Epävarma mitoista',
                'not_sure'              => 'En ole varma',
                'not_sure_raw'          => 'En ole varma',
            ),
        );
        return isset( $map[ $lang ] ) ? $map[ $lang ] : $map['Swedish'];
    }

    /**
     * Enqueue frontend chat widget scripts and styles (Lightweight options only — zero custom table SQL queries).
     */
    public function enqueue_scripts() {
        wp_enqueue_style(
            'advisio-chat-style',
            ADVISIO_AI_PLUGIN_URL . 'assets/css/chat-style.css',
            array(),
            ADVISIO_AI_VERSION
        );
        wp_enqueue_script(
            'advisio-chat-script',
            ADVISIO_AI_PLUGIN_URL . 'assets/js/chat-script.js',
            array(),
            ADVISIO_AI_VERSION,
            true
        );

        $lead_fields_raw = get_option( 'advisio_lead_form_fields', '' );
        if ( is_array( $lead_fields_raw ) ) {
            $lead_fields = $lead_fields_raw;
        } else {
            $lead_fields = ( is_string( $lead_fields_raw ) && '' !== trim( $lead_fields_raw ) ) ? json_decode( $lead_fields_raw, true ) : null;
        }
        if ( ! is_array( $lead_fields ) || empty( $lead_fields ) ) {
            $lead_fields = self::get_default_lead_fields();
        }

        $lang        = get_option( 'advisio_language', 'Swedish' );
        $chip_labels = self::get_frontend_chip_labels( $lang );

        wp_localize_script( 'advisio-chat-script', 'AdvisioChat', array(
            'ajaxUrl'                 => admin_url( 'admin-ajax.php' ),
            'nonce'                   => wp_create_nonce( 'advisio_chat_nonce' ),
            'productId'               => ( function_exists( 'is_product' ) && is_product() ) ? get_the_ID() : 0,
            'proactive'               => (int) get_option( 'advisio_proactive', 1 ),
            'welcomeMsg'              => get_option( 'advisio_welcome_msg', 'Hej! Välkommen till vår kundtjänst. Hur kan jag hjälpa dig idag?' ),
            'cbLabel'                 => get_option( 'advisio_label_callback', '📞 Bli kontaktad av en säljare' ),
            'leadHeader'              => get_option( 'advisio_label_lead_header', 'Fyll i dina uppgifter så återkommer en rådgivare:' ),
            'leadSuccess'             => get_option( 'advisio_label_lead_success', 'Tack! En rådgivare kommer att kontakta dig inom kort.' ),
            'leadFields'              => $lead_fields,
            'measureHelpChip'         => $chip_labels['measure_help'],
            'notSureMeasurementsChip' => $chip_labels['not_sure_measurements'],
            'notSureChip'             => $chip_labels['not_sure'],
            'notSureRaw'              => $chip_labels['not_sure_raw'],
            'language'                => $lang,
        ) );
    }

    /**
     * Render frontend chat widget HTML in wp_footer.
     */
    public function render_chat_widget() {
        $title          = get_option( 'advisio_chat_title', 'Advisio AI Kundtjänst' );
        $proactive_text = get_option( 'advisio_proactive_text', 'Behöver du hjälp med mått eller fönsterval? 👋' );
        $icon_type      = get_option( 'advisio_launcher_icon', 'preset-1' );
        $icon_url       = get_option( 'advisio_custom_icon' );

        if ( 'custom' === $icon_type && $icon_url ) {
            $icon_html = '<img src="' . esc_url( $icon_url ) . '" alt="Chat">';
        } elseif ( 'preset-2' === $icon_type ) {
            $icon_html = '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 2L9.5 9.5 2 12l7.5 2.5L12 22l2.5-7.5L22 12l-7.5-2.5L12 2z"/></svg>';
        } elseif ( 'preset-3' === $icon_type ) {
            $icon_html = '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 3a9 9 0 00-9 9v4a3 3 0 003 3h1v-6H5v-1a7 7 0 0114 0v1h-2v6h1a3 3 0 003-3v-4a9 9 0 00-9-9z"/></svg>';
        } elseif ( 'preset-4' === $icon_type ) {
            $icon_html = '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>';
        } else {
            $icon_html = '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>';
        }
        ?>
        <div id="advisio-chat-container">
            <div id="advisio-proactive-bubble" class="advisio-hidden">
                <?php echo esc_html( $proactive_text ); ?>
            </div>
            <button id="advisio-launcher" aria-label="Open Advisio AI Chat">
                <span class="advisio-icon"><?php echo $icon_html; ?></span>
            </button>
            <div id="advisio-chat-window" class="advisio-hidden">
                <div class="advisio-chat-header">
                    <div class="advisio-header-title-wrap">
                        <span class="advisio-online-dot"></span>
                        <h3><?php echo esc_html( $title ); ?></h3>
                    </div>
                    <button id="advisio-close-chat" aria-label="Close Chat">&times;</button>
                </div>

                <div class="advisio-chat-body" id="advisio-chat-body"></div>

                <!-- Dynamic Lead Form Drawer -->
                <div id="advisio-lead-form-container" class="advisio-hidden">
                    <div class="advisio-lead-form" id="advisio-lead-form-inner">
                        <p id="advisio-lead-title-text"><?php echo esc_html( get_option( 'advisio_label_lead_header', 'Fyll i dina uppgifter så återkommer en rådgivare:' ) ); ?></p>

                        <div class="advisio-contact-method-group">
                            <span class="advisio-contact-method-label">Preferred contact method:</span>
                            <div class="advisio-contact-pills">
                                <label class="advisio-contact-pill active">
                                    <input type="radio" name="advisio_preferred_contact" value="Email" checked>
                                    <span>✉️ Email</span>
                                </label>
                                <label class="advisio-contact-pill">
                                    <input type="radio" name="advisio_preferred_contact" value="Phone">
                                    <span>📞 Phone</span>
                                </label>
                            </div>
                        </div>

                        <div id="advisio-dynamic-fields-mount"></div>

                        <div class="advisio-lead-actions">
                            <button type="button" id="advisio-submit-lead-btn" class="advisio-btn">Skicka</button>
                            <button type="button" id="advisio-cancel-lead-btn" class="advisio-btn-cancel">Avbryt</button>
                        </div>
                    </div>
                </div>

                <div class="advisio-chat-footer">
                    <input type="text" id="advisio-chat-input" placeholder="Skriv ditt meddelande...">
                    <button id="advisio-chat-send" aria-label="Send Message">
                        <svg fill="currentColor" viewBox="0 0 24 24"><path d="M2 21l21-9L2 3v7l15 2-15 2v7z"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }



    /* =========================================================================
     * STAGE 1: TAB 3 THE BRAIN (SELF-BUILDING MASTER PROMPT ARCHITECTURE)
     * ========================================================================= */

    public function get_default_box_1_text() {
        return "Du är Advisio AI, en professionell, varm och rådgivande AI-assistent för vår e-handelsbutik.\n" .
               "Du talar alltid flytande, artig och naturlig svenska.\n" .
               "Ditt bemötande är alltid förtroendeingivande, trevligt och rådgivande utan att vara påträngande eller tjatigt.";
    }

    public function get_default_box_2_text() {
        return "- Hitta ALDRIG på priser, mått, lagerstatus eller villkor som inte finns i kunskapsbasen.\n" .
               "- Fråga ALDRIG efter personnummer, bankuppgifter eller känsliga personuppgifter.\n" .
               "- Ställ ALDRIG mer än en kvalificerande fråga i taget.\n" .
               "- Påstå ALDRIG att butik saknas om det finns ett showroom eller besöksadress i kunskapsbasen.\n" .
               "- Länka ALDRIG till externa webbplatser utanför butikens domän.";
    }

    public function get_default_box_3_text() {
        return "1. Ta reda på vilket rum eller projekt kunden behöver hjälp med (t.ex. renovering, nybygge eller uterum).\n" .
               "2. Fråga efter ungefärliga mått (Bredd x Höjd), önskat material eller stil.\n" .
               "3. Föreslå matchande produkter ur butikens sortiment.\n" .
               "4. Erbjud offert eller hjälp att beställa direkt i butiken om kunden önskar.";
    }

    public function get_total_knowledge_count() {
        global $wpdb;
        $table_k = $wpdb->prefix . 'fg_ai_knowledge';
        $db_count = 0;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_k ) ) === $table_k ) {
            $db_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_k WHERE status = 'live'" );
        }
        $live_custom = count( $this->get_live_custom_knowledge() );
        return max( $db_count, $live_custom );
    }

    public function get_pending_box_additions( $box ) {
        $key = 'advisio_pending_box_' . intval( $box );
        $list = get_option( $key, array() );
        return is_array( $list ) ? $list : array();
    }

    public function get_total_pending_brain_and_memory_count() {
        $p1 = count( $this->get_pending_box_additions( 1 ) );
        $p2 = count( $this->get_pending_box_additions( 2 ) );
        $p3 = count( $this->get_pending_box_additions( 3 ) );
        $p4 = count( (array) get_option( 'advisio_pending_box_4', array() ) );
        $pm = count( $this->get_pending_custom_knowledge() );

        global $wpdb;
        $table_k = $wpdb->prefix . 'fg_ai_knowledge';
        $pending_crawler = 0;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_k ) ) === $table_k ) {
            $pending_crawler = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_k WHERE status = 'pending' AND scope NOT IN ('manual', 'file', 'custom')" );
        }

        return $p1 + $p2 + $p3 + $p4 + $pm + $pending_crawler;
    }

    public function build_store_memory_map() {
        global $wpdb;
        $table_k   = $wpdb->prefix . 'fg_ai_knowledge';
        $has_table = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_k ) ) === $table_k );

        $map = "=== BUTIKENS STRUKTURERADE MINNESKARTA (MEMORY BLUEPRINT) ===\n\n";

        // SECTION A: MANUAL KNOWLEDGE & REFERENCE FILES
        $map .= "=== SEKTION A: MANUELL KUNSKAP & REFERENSFILER (HÖGSTA PRIORITET) ===\n";
        $live_custom  = $this->get_live_custom_knowledge();
        $manual_items = array();
        if ( ! empty( $live_custom ) ) {
            foreach ( $live_custom as $c ) {
                $type_tag = ( isset( $c['type'] ) && 'file' === $c['type'] ) ? 'FIL' : 'TEXT';
                $c_title  = isset( $c['title'] ) ? $c['title'] : 'Kunskap';
                $c_body   = isset( $c['content'] ) ? ( function_exists( 'mb_substr' ) ? mb_substr( $c['content'], 0, 500, 'UTF-8' ) : substr( $c['content'], 0, 500 ) ) : '';
                $manual_items[ $c_title ] = sprintf( "[%s] %s: %s\n", $type_tag, $c_title, $c_body );
            }
        }
        if ( $has_table ) {
            $db_manual = $wpdb->get_results( "SELECT title, content, type FROM $table_k WHERE scope IN ('manual', 'file', 'custom') ORDER BY id ASC", ARRAY_A );
            if ( ! empty( $db_manual ) ) {
                foreach ( $db_manual as $dm ) {
                    $type_tag = ( isset( $dm['type'] ) && 'file' === $dm['type'] ) ? 'FIL' : 'TEXT';
                    $t        = isset( $dm['title'] ) ? $dm['title'] : 'Kunskap';
                    if ( ! isset( $manual_items[ $t ] ) ) {
                        $b = isset( $dm['content'] ) ? ( function_exists( 'mb_substr' ) ? mb_substr( $dm['content'], 0, 500, 'UTF-8' ) : substr( $dm['content'], 0, 500 ) ) : '';
                        $manual_items[ $t ] = sprintf( "[%s] %s: %s\n", $type_tag, $t, $b );
                    }
                }
            }
        }
        if ( ! empty( $manual_items ) ) {
            $map .= implode( "", $manual_items ) . "\n";
        } else {
            $map .= "Inga manuella kunskapsposter tillagda än.\n\n";
        }

        // SECTION B: GLOBAL SITE & FOOTER
        $map .= "=== SEKTION B: GLOBAL WEBBPLATS & FOOTER ===\n";
        $global_site = array();
        if ( $has_table ) {
            $global_site = $wpdb->get_results( "SELECT title, content, url FROM $table_k WHERE scope = 'global_site' ORDER BY id ASC", ARRAY_A );
        }
        if ( ! empty( $global_site ) ) {
            foreach ( $global_site as $gs ) {
                $snippet = function_exists( 'mb_substr' ) ? mb_substr( $gs['content'], 0, 600, 'UTF-8' ) : substr( $gs['content'], 0, 600 );
                $map .= sprintf( "[GLOBAL] %s (%s):\n%s\n\n", $gs['title'], $gs['url'], $snippet );
            }
        } else {
            $core_facts = $this->get_core_company_facts_prompt();
            if ( ! empty( $core_facts ) ) {
                $map .= trim( $core_facts ) . "\n\n";
            } else {
                $map .= "Global webbplats & footer ej indexerad än.\n\n";
            }
        }

        // SECTION C: PAGES & NAVIGATION MAP
        $map .= "=== SEKTION C: WEBBPLATSENS SIDOR & MENYSTRUKTUR ===\n";
        if ( $has_table ) {
            $pages = $wpdb->get_results( "SELECT title, url FROM $table_k WHERE scope = 'content' ORDER BY id ASC LIMIT 50", ARRAY_A );
            if ( ! empty( $pages ) ) {
                foreach ( $pages as $p ) {
                    $map .= sprintf( "- %s (%s)\n", $p['title'], $p['url'] );
                }
                $map .= "\n";
            } else {
                $map .= "Inga sidor indexerade än.\n\n";
            }
        }

        // SECTION D: WOOCOMMERCE PRODUCT CATALOG MAP
        $map .= "=== SEKTION D: WOOCOMMERCE PRODUKTKATALOG (PRODUKTER & KATEGORIER) ===\n";
        if ( $has_table ) {
            $products = $wpdb->get_results( "SELECT title, url, LEFT(content, 260) as snippet FROM $table_k WHERE scope = 'shop' ORDER BY id ASC LIMIT 35", ARRAY_A );
            if ( ! empty( $products ) ) {
                foreach ( $products as $prod ) {
                    $prod_title   = isset( $prod['title'] ) ? $prod['title'] : '';
                    $prod_snippet = isset( $prod['snippet'] ) ? $prod['snippet'] : '';
                    $map .= sprintf( "- %s (%s): %s\n", $prod_title, isset( $prod['url'] ) ? $prod['url'] : '', $prod_snippet );
                }
                $map .= "\n";
            } else {
                $map .= "Inga produkter indexerade än.\n\n";
            }
        }

        return $map;
    }

    public function ajax_save_setup_box() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $box     = isset( $_POST['box'] ) ? intval( $_POST['box'] ) : 0;
        $content = isset( $_POST['content'] ) ? trim( wp_unslash( $_POST['content'] ) ) : '';

        if ( ! in_array( $box, array( 1, 2, 3 ), true ) ) {
            wp_send_json_error( 'Ogiltig del.' );
        }
        if ( empty( $content ) ) {
            wp_send_json_error( 'Innehållet kan inte vara tomt.' );
        }

        if ( 1 === $box ) {
            update_option( 'advisio_box_1_persona', $content );
            update_option( 'advisio_setup_box_1_ready', 1 );
        } elseif ( 2 === $box ) {
            update_option( 'advisio_box_2_donts', $content );
            update_option( 'advisio_setup_box_2_ready', 1 );
        } elseif ( 3 === $box ) {
            update_option( 'advisio_box_3_goals', $content );
            update_option( 'advisio_setup_box_3_ready', 1 );
        }

        $b1_ready = (bool) get_option( 'advisio_setup_box_1_ready', 0 );
        $b2_ready = (bool) get_option( 'advisio_setup_box_2_ready', 0 );
        $b3_ready = (bool) get_option( 'advisio_setup_box_3_ready', 0 );
        $ready_count = ( $b1_ready ? 1 : 0 ) + ( $b2_ready ? 1 : 0 ) + ( $b3_ready ? 1 : 0 );

        wp_send_json_success( array(
            'box'         => $box,
            'ready_count' => $ready_count,
            'all_ready'   => ( 3 === $ready_count ),
        ) );
    }

    public function ajax_generate_master_brain() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $b1 = trim( (string) get_option( 'advisio_box_1_persona', '' ) );
        $b2 = trim( (string) get_option( 'advisio_box_2_donts', '' ) );
        $b3 = trim( (string) get_option( 'advisio_box_3_goals', '' ) );

        if ( empty( $b1 ) || empty( $b2 ) || empty( $b3 ) ) {
            wp_send_json_error( 'Alla 3 delar måste fyllas i och sparas innan du kan skapa Advisios hjärna.' );
        }

        $memory_map = $this->build_store_memory_map();

        $system_instruction = "You are an Elite AI System Architect and Prompt Engineer.
Your task is to generate a comprehensive, production-grade Master System Instruction Prompt for Advisio AI.
The generated prompt will serve as the AI's Brain and MUST be in Swedish (the language of the store).
You must structure the generated prompt strictly into these 4 distinct internal sections:

=== 1. CORE IDENTITY, TONE & CONVERSATION STRATEGY ===
Define the AI persona, expert, warm and advisory sales advisor tone (based on Box 1), and conversation guidelines. Specify that at the end of each response, the AI asks ONE single guiding question followed by quick reply suggestions using the exact format: [SUGGESTIONS: Option 1 | Option 2 | Inte säker].

=== 2. MANDATORY QUALIFYING CHECKLIST (GOALS) ===
Define the step-by-step qualifying stages and sales goals (based on Box 3). Emphasize asking only ONE qualifying question at a time so the customer is never overwhelmed.

=== 3. KNOWLEDGE ROUTING & MEMORY DIRECTORY (HOW TO USE YOUR MEMORY BANK) ===
Explain how to route and use the store's Memory Bank:
- Section A (Manual Knowledge & Files) and Section B (Global Site & Footer) represent highest-priority ground truth (visiting addresses, opening hours, warranties, policies). If physical store/showroom exists in memory, always confirm it.
- Section C (Pages) and Section D (Product Catalog) provide linking URLs and product details. Always use exact URLs from the memory bank when referring to pages or products.

=== 4. STRICT NEGATIVE CONSTRAINTS (NEVER VIOLATE) ===
Incorporate all negative constraints (based on Box 2). Place these at the absolute bottom as inviolable rules that override everything else (never invent facts, never give price guarantees unless verified, never suggest competing stores, never ask multiple questions at once).

Output ONLY the complete, production-ready Master System Instruction Prompt in Swedish, without any conversational preamble or meta-commentary.";

        $user_prompt = "BOX 1 (PERSONA & TONE):\n$b1\n\nBOX 2 (NEGATIVE CONSTRAINTS / DONTS):\n$b2\n\nBOX 3 (QUALIFYING GOALS / CHECKLIST):\n$b3\n\n$memory_map\n\nGenerate the complete Master System Instruction Prompt in Swedish now.";

        $res = $this->execute_gemini_api( $system_instruction, $user_prompt, 0.35, 2000 );
        if ( ! $res['success'] || empty( $res['text'] ) ) {
            wp_send_json_error( 'Kunde inte generera hjärnan: ' . ( isset( $res['error'] ) ? $res['error'] : 'Okänt fel' ) );
        }

        $master_prompt = trim( $res['text'] );
        if ( preg_match( '/^```(?:markdown)?\s*([\s\S]*?)\s*```$/i', $master_prompt, $m_match ) ) {
            $master_prompt = trim( $m_match[1] );
        }
        update_option( 'advisio_master_brain_prompt', $master_prompt );
        update_option( 'advisio_compiled_master_dna', $master_prompt ); // compatibility alias
        update_option( 'advisio_brain_initialized', 1 );

        // Promote all pending memory in Tab 2 to live
        global $wpdb;
        $table_k = $wpdb->prefix . 'fg_ai_knowledge';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_k ) ) === $table_k ) {
            $wpdb->query( "UPDATE $table_k SET status = 'live' WHERE status != 'live'" );
        }

        $pending_custom = $this->get_pending_custom_knowledge();
        if ( ! empty( $pending_custom ) ) {
            $live_custom = $this->get_live_custom_knowledge();
            foreach ( $pending_custom as $p_item ) {
                $p_item['status'] = 'live';
                $live_custom[]    = $p_item;
            }
            update_option( 'advisio_live_knowledge', $live_custom );
            update_option( 'advisio_pending_knowledge', array() );
        }

        wp_send_json_success( array(
            'master_prompt' => $master_prompt,
            'message'       => 'Advisios hjärna skapad och aktiverad framgångsrikt!',
        ) );
    }

    public function ajax_add_pending_addition() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $box  = isset( $_POST['box'] ) ? intval( $_POST['box'] ) : 0;
        $text = isset( $_POST['text'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['text'] ) ) ) : '';

        if ( ! in_array( $box, array( 1, 2, 3 ), true ) || empty( $text ) ) {
            wp_send_json_error( 'Ogiltig del eller tomt tillägg.' );
        }

        $is_removal = ( 0 === strpos( $text, '-' ) );
        $list       = $this->get_pending_box_additions( $box );
        $new_item   = array(
            'id'         => 'add_' . substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 8 ),
            'text'       => $text,
            'is_removal' => $is_removal,
            'created_at' => current_time( 'mysql' ),
        );
        $list[] = $new_item;
        update_option( 'advisio_pending_box_' . $box, $list );

        wp_send_json_success( array(
            'item'          => $new_item,
            'box'           => $box,
            'total_pending' => $this->get_total_pending_brain_and_memory_count(),
        ) );
    }

    public function ajax_edit_pending_addition() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $box  = isset( $_POST['box'] ) ? intval( $_POST['box'] ) : 0;
        $id   = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
        $text = isset( $_POST['text'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['text'] ) ) ) : '';

        if ( ! in_array( $box, array( 1, 2, 3 ), true ) || empty( $id ) || empty( $text ) ) {
            wp_send_json_error( 'Ogiltiga parametrar.' );
        }

        $list = $this->get_pending_box_additions( $box );
        foreach ( $list as $idx => $item ) {
            if ( isset( $item['id'] ) && $item['id'] === $id ) {
                $list[ $idx ]['text']       = $text;
                $list[ $idx ]['is_removal'] = ( 0 === strpos( $text, '-' ) );
                break;
            }
        }
        update_option( 'advisio_pending_box_' . $box, $list );

        wp_send_json_success( array(
            'box'           => $box,
            'id'            => $id,
            'total_pending' => $this->get_total_pending_brain_and_memory_count(),
        ) );
    }

    public function ajax_delete_pending_addition() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $box = isset( $_POST['box'] ) ? intval( $_POST['box'] ) : 0;
        $id  = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';

        if ( ! in_array( $box, array( 1, 2, 3 ), true ) || empty( $id ) ) {
            wp_send_json_error( 'Saknar parametrar.' );
        }

        $list  = $this->get_pending_box_additions( $box );
        $new_l = array();
        foreach ( $list as $item ) {
            if ( isset( $item['id'] ) && $item['id'] === $id ) {
                continue;
            }
            $new_l[] = $item;
        }
        update_option( 'advisio_pending_box_' . $box, $new_l );

        wp_send_json_success( array(
            'box'           => $box,
            'id'            => $id,
            'total_pending' => $this->get_total_pending_brain_and_memory_count(),
        ) );
    }

    public function ajax_update_master_brain() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $b1_locked = get_option( 'advisio_box_1_persona', '' );
        $b2_locked = get_option( 'advisio_box_2_donts', '' );
        $b3_locked = get_option( 'advisio_box_3_goals', '' );
        $b4_locked = (array) get_option( 'advisio_box_4_strategies', array() );

        $b1_pending = $this->get_pending_box_additions( 1 );
        $b2_pending = $this->get_pending_box_additions( 2 );
        $b3_pending = $this->get_pending_box_additions( 3 );
        $b4_pending = (array) get_option( 'advisio_pending_box_4', array() );

        $memory_map = $this->build_store_memory_map();

        $system_instruction = "You are an Elite AI System Architect and Prompt Engineer.
The user wants to update Advisio AI's Brain and Behavior Boxes.
You are given:
- Current Locked Box 1 (Persona & Strategy)
- Current Locked Box 2 (DONTs / Negative Constraints)
- Current Locked Box 3 (Qualifying Goals)
- Current Locked Box 4 (Approved Learning Strategies)
- Pending Additions for Box 1, 2, 3 (note: items starting with '-' indicate things to remove or stop doing)
- Pending Approved Strategies for Box 4
- Structured Memory Blueprint (from Knowledge Base)

Your task:
1. Seamlessly merge the pending additions into their respective boxes:
   - For any pending item starting with '-', remove that rule, topic, or behavior from the locked text.
   - For all other pending items, integrate them cleanly and coherently into the text.
2. Merge the pending approved strategies into Box 4.
3. Regenerate the complete, updated Master System Instruction Prompt ('master_prompt') in Swedish strictly structured into these 4 distinct internal sections:
   === 1. CORE IDENTITY, TONE & CONVERSATION STRATEGY ===
   === 2. MANDATORY QUALIFYING CHECKLIST (GOALS) ===
   === 3. KNOWLEDGE ROUTING & MEMORY DIRECTORY (HOW TO USE YOUR MEMORY BANK) ===
   === 4. STRICT NEGATIVE CONSTRAINTS (NEVER VIOLATE) ===
4. Respond ONLY with a valid JSON object in this exact schema (no markdown fences, no extra commentary):
{
  \"updated_box_1\": \"...\",
  \"updated_box_2\": \"...\",
  \"updated_box_3\": \"...\",
  \"updated_box_4\": [\"...\"],
  \"master_prompt\": \"...\"
}";

        $b1_p_texts = array_column( $b1_pending, 'text' );
        $b2_p_texts = array_column( $b2_pending, 'text' );
        $b3_p_texts = array_column( $b3_pending, 'text' );
        $b4_p_texts = array_map( function( $it ) { return is_array( $it ) ? $it['text'] : (string)$it; }, $b4_pending );

        $user_prompt = "CURRENT BOX 1 (PERSONA):\n$b1_locked\nPENDING BOX 1 ADDITIONS:\n" . implode( "\n", $b1_p_texts ) . "\n\n" .
                       "CURRENT BOX 2 (DONTS):\n$b2_locked\nPENDING BOX 2 ADDITIONS:\n" . implode( "\n", $b2_p_texts ) . "\n\n" .
                       "CURRENT BOX 3 (GOALS):\n$b3_locked\nPENDING BOX 3 ADDITIONS:\n" . implode( "\n", $b3_p_texts ) . "\n\n" .
                       "CURRENT BOX 4 (STRATEGIES):\n" . wp_json_encode( $b4_locked ) . "\nPENDING BOX 4 STRATEGIES:\n" . wp_json_encode( $b4_p_texts ) . "\n\n" .
                       "$memory_map\n\nGenerate the updated structured JSON response now.";

        $res = $this->execute_gemini_api( $system_instruction, $user_prompt, 0.2, 2800 );
        if ( ! $res['success'] || empty( $res['text'] ) ) {
            wp_send_json_error( 'Kunde inte uppdatera Advisio: ' . ( isset( $res['error'] ) ? $res['error'] : 'Okänt fel' ) );
        }

        $raw_text = trim( $res['text'] );

        // Strip markdown code fences if present (e.g. ```json ... ``` or ``` ... ```)
        if ( preg_match( '/```(?:json)?\s*([\s\S]*?)\s*```/i', $raw_text, $cb_match ) ) {
            $json_str = trim( $cb_match[1] );
        } else {
            $json_str = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $raw_text );
        }

        $decoded = json_decode( $json_str, true );

        // Fallback: extract the outermost {...} JSON block if surrounded by conversational text
        if ( ! is_array( $decoded ) ) {
            $first_brace = strpos( $raw_text, '{' );
            $last_brace  = strrpos( $raw_text, '}' );
            if ( false !== $first_brace && false !== $last_brace && $last_brace > $first_brace ) {
                $sub_json = substr( $raw_text, $first_brace, ( $last_brace - $first_brace + 1 ) );
                $decoded  = json_decode( $sub_json, true );
            }
        }

        // Safe Fallback: if json_decode still fails or master_prompt is empty, NEVER overwrite locked boxes!
        if ( ! is_array( $decoded ) || empty( $decoded['master_prompt'] ) || ! is_string( $decoded['master_prompt'] ) ) {
            wp_send_json_error( 'Kunde inte tolka AI-svaret för uppdateringen. Inga inställningar har ändrats.' );
        }

        // Defensive updates: only update boxes if non-empty valid content was returned
        if ( ! empty( $decoded['updated_box_1'] ) && is_string( $decoded['updated_box_1'] ) ) {
            update_option( 'advisio_box_1_persona', trim( $decoded['updated_box_1'] ) );
        }
        if ( ! empty( $decoded['updated_box_2'] ) && is_string( $decoded['updated_box_2'] ) ) {
            update_option( 'advisio_box_2_donts', trim( $decoded['updated_box_2'] ) );
        }
        if ( ! empty( $decoded['updated_box_3'] ) && is_string( $decoded['updated_box_3'] ) ) {
            update_option( 'advisio_box_3_goals', trim( $decoded['updated_box_3'] ) );
        }
        if ( isset( $decoded['updated_box_4'] ) && is_array( $decoded['updated_box_4'] ) && ! empty( $decoded['updated_box_4'] ) ) {
            update_option( 'advisio_box_4_strategies', $decoded['updated_box_4'] );
        }
        update_option( 'advisio_master_brain_prompt', trim( $decoded['master_prompt'] ) );
        update_option( 'advisio_compiled_master_dna', trim( $decoded['master_prompt'] ) );

        // Clear all pending staging queues
        update_option( 'advisio_pending_box_1', array() );
        update_option( 'advisio_pending_box_2', array() );
        update_option( 'advisio_pending_box_3', array() );
        update_option( 'advisio_pending_box_4', array() );

        // Promote all pending memory in Tab 2 to live
        global $wpdb;
        $table_k = $wpdb->prefix . 'fg_ai_knowledge';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_k ) ) === $table_k ) {
            $wpdb->query( "UPDATE $table_k SET status = 'live' WHERE status != 'live'" );
        }

        $pending_custom = $this->get_pending_custom_knowledge();
        if ( ! empty( $pending_custom ) ) {
            $live_custom = $this->get_live_custom_knowledge();
            foreach ( $pending_custom as $p_item ) {
                $p_item['status'] = 'live';
                $live_custom[]    = $p_item;
            }
            update_option( 'advisio_live_knowledge', $live_custom );
            update_option( 'advisio_pending_knowledge', array() );
        }

        wp_send_json_success( array(
            'message'       => 'Advisios hjärna och minne har uppdaterats framgångsrikt!',
            'master_prompt' => $decoded['master_prompt'],
        ) );
    }

    public function ajax_reset_brain() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        update_option( 'advisio_brain_initialized', 0 );
        update_option( 'advisio_master_brain_prompt', '' );
        update_option( 'advisio_setup_box_1_ready', 0 );
        update_option( 'advisio_setup_box_2_ready', 0 );
        update_option( 'advisio_setup_box_3_ready', 0 );
        update_option( 'advisio_pending_box_1', array() );
        update_option( 'advisio_pending_box_2', array() );
        update_option( 'advisio_pending_box_3', array() );
        update_option( 'advisio_pending_box_4', array() );

        wp_send_json_success( array(
            'message' => 'Advisio har nollställts till setup-läget.',
        ) );
    }

    public function get_or_bootstrap_master_dna() {
        $prompt = get_option( 'advisio_master_brain_prompt', '' );
        if ( empty( $prompt ) ) {
            $prompt = get_option( self::MASTER_DNA_OPTION, '' );
        }
        if ( empty( $prompt ) ) {
            $prompt = $this->get_default_box_1_text() . "\n\n" . $this->get_default_box_2_text() . "\n\n" . $this->get_default_box_3_text();
        }
        return $prompt;
    }


    /* =========================================================================
     * TASK 5: CAPTURED LEADS MANAGEMENT
     * ========================================================================= */

    /**
     * AJAX: Delete a captured lead log row.
     */
    public function ajax_delete_lead() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $lead_id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( $lead_id <= 0 ) {
            wp_send_json_error( 'Ogiltigt lead-ID.' );
        }

        global $wpdb;
        $table_logs = $wpdb->prefix . 'fg_ai_logs';
        $deleted = $wpdb->delete( $table_logs, array( 'id' => $lead_id, 'is_lead' => 1 ), array( '%d', '%d' ) );

        if ( false === $deleted ) {
            wp_send_json_error( 'Kunde inte radera lead ur databasen.' );
        }

        wp_send_json_success( array( 'id' => $lead_id ) );
    }

}
} // end if ! class_exists( 'Advisio_AI' )

/**
 * Lightweight, fault-tolerant table creator.
 * Called ONLY when opening the Advisio AI admin page or running an Advisio AJAX action.
 * Never called on activation or general frontend page loads.
 */
function advisio_ensure_tables_ready() {
    static $ready = false;
    if ( $ready ) {
        return;
    }

    try {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return;
        }

        $charset_collate = '';
        if ( method_exists( $wpdb, 'get_charset_collate' ) ) {
            $charset_collate = $wpdb->get_charset_collate();
        }

        $table_knowledge = $wpdb->prefix . 'fg_ai_knowledge';
        $wpdb->query( "CREATE TABLE IF NOT EXISTS $table_knowledge (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) DEFAULT 0 NOT NULL,
            source_id bigint(20) DEFAULT 0 NOT NULL,
            title text NOT NULL,
            content longtext NOT NULL,
            type varchar(255) NOT NULL,
            scope varchar(50) DEFAULT 'content' NOT NULL,
            url varchar(255) NOT NULL,
            source_type varchar(50) DEFAULT 'internal' NOT NULL,
            status varchar(20) DEFAULT 'live' NOT NULL,
            last_indexed datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY url (url(191)),
            KEY scope (scope),
            KEY post_id (post_id)
        ) $charset_collate;" );

        // Bulletproof schema auto-migration: inspect exact existing columns via DESC
        $existing_cols = $wpdb->get_col( "DESC $table_knowledge", 0 );
        if ( ! is_array( $existing_cols ) ) {
            $existing_cols = array();
        }
        $existing_cols = array_map( 'strtolower', $existing_cols );

        $needed_cols = array(
            'post_id'      => "bigint(20) DEFAULT 0 NOT NULL",
            'source_id'    => "bigint(20) DEFAULT 0 NOT NULL",
            'title'        => "text NOT NULL",
            'content'      => "longtext NOT NULL",
            'type'         => "varchar(255) NOT NULL",
            'scope'        => "varchar(50) DEFAULT 'content' NOT NULL",
            'url'          => "varchar(255) NOT NULL",
            'source_type'  => "varchar(50) DEFAULT 'internal' NOT NULL",
            'status'       => "varchar(20) DEFAULT 'live' NOT NULL",
            'last_indexed' => "datetime DEFAULT '0000-00-00 00:00:00' NOT NULL",
            'updated_at'   => "datetime DEFAULT CURRENT_TIMESTAMP NOT NULL",
        );

        foreach ( $needed_cols as $col_name => $col_def ) {
            if ( ! in_array( strtolower( $col_name ), $existing_cols, true ) ) {
                $wpdb->query( "ALTER TABLE $table_knowledge ADD COLUMN $col_name $col_def" );
                if ( in_array( $col_name, array( 'status', 'scope', 'source_type', 'post_id', 'source_id' ), true ) ) {
                    $wpdb->query( "ALTER TABLE $table_knowledge ADD INDEX $col_name ($col_name)" );
                }
            }
        }

        // Single Live Memory Bank: Ensure all records are active live
        $wpdb->query( "UPDATE $table_knowledge SET status = 'live' WHERE status != 'live'" );

        $table_logs = $wpdb->prefix . 'fg_ai_logs';
        $wpdb->query( "CREATE TABLE IF NOT EXISTS $table_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            user_message text NOT NULL,
            bot_reply longtext NOT NULL,
            is_lead tinyint(1) DEFAULT 0 NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id),
            KEY session_id (session_id(191)),
            KEY is_lead (is_lead),
            KEY created_at (created_at)
        ) $charset_collate;" );

        $table_proposals = $wpdb->prefix . 'fg_ai_proposals';
        $wpdb->query( "CREATE TABLE IF NOT EXISTS $table_proposals (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            proposal_text text NOT NULL,
            rationale text NOT NULL,
            category varchar(100) DEFAULT 'Strategy' NOT NULL,
            status varchar(50) DEFAULT 'pending' NOT NULL,
            batch_number int(11) DEFAULT 1 NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id),
            KEY status (status)
        ) $charset_collate;" );

        if ( false === get_option( 'advisio_lead_form_fields', false ) && class_exists( 'Advisio_AI' ) ) {
            update_option( 'advisio_lead_form_fields', wp_json_encode( Advisio_AI::get_default_lead_fields() ) );
        }

        $ready = true;
    } catch ( \Throwable $e ) {
        // Fault-tolerant: never break the site on table checks
    }
}

/**
 * Central helper: Call Gemini API with automatic model resolution and 404 recovery.
 *
 * @param string $system_instruction System instruction prompt.
 * @param string $user_prompt       User prompt.
 * @param float  $temperature       Generation temperature (default 0.35).
 * @param int    $max_tokens        Max output tokens (default 1200).
 * @return array Array with success, text, model, and error.
 */
function advisio_call_gemini_api( $system_instruction, $user_prompt, $temperature = 0.35, $max_tokens = 1200 ) {
    return advisio_ai()->execute_gemini_api( $system_instruction, $user_prompt, $temperature, $max_tokens );
}

function advisio_ai() {
    static $instance = null;
    if ( null === $instance ) {
        $instance = new Advisio_AI();
    }
    return $instance;
}

// Unconditionally instantiate on file load so all hooks and menus are active
advisio_ai();

// Failsafe hook for standard WordPress plugin lifecycle
add_action( 'plugins_loaded', 'advisio_ai', 5 );

// Unconditional activation and deactivation hooks at file level (100% passive)
register_activation_hook( ADVISIO_AI_PLUGIN_FILE, array( 'Advisio_AI', 'activate_plugin' ) );
register_deactivation_hook( ADVISIO_AI_PLUGIN_FILE, array( 'Advisio_AI', 'deactivate_plugin' ) );
