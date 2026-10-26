<?php
/**
 * Plugin Name: Advisio AI
 * Description: Advisio AI chat service — Guided Commerce & Intelligent Support by JC-ITC.
 * Version: 4.2.0
 * Author: JC-ITC
 * Text Domain: advisio-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ADVISIO_AI_VERSION', '4.2.0' );
define( 'ADVISIO_AI_PLUGIN_FILE', __FILE__ );
define( 'ADVISIO_AI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'ADVISIO_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADVISIO_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Native GitHub Release Auto-Updater Class.
 * Strictly cached for 12 hours via WordPress transients and safely idle when no repository is configured.
 */
class Advisio_AI_GitHub_Updater {

    private $plugin_file;
    private $plugin_slug;
    private $repo;
    private $token;
    private $github_response;

    public function __construct( $plugin_file ) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename( $plugin_file );
        $this->repo        = trim( (string) get_option( 'advisio_github_repo', '' ) );
        $this->token       = trim( (string) get_option( 'advisio_github_token', '' ) );

        // Safe Idle State: Immediately bail out if repository is empty or invalid
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
            if ( 'error_backoff' === $cached ) {
                $this->github_response = false;
                return false;
            }
            $this->github_response = $cached;
            return $this->github_response;
        }

        $api_url = sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->repo );
        $headers = array(
            'Accept'     => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/Advisio-AI-Updater',
        );

        if ( ! empty( $this->token ) ) {
            $headers['Authorization'] = 'token ' . $this->token;
        }

        $response = wp_remote_get( $api_url, array(
            'headers' => $headers,
            'timeout' => 12,
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            set_transient( $transient_key, 'error_backoff', HOUR_IN_SECONDS );
            $this->github_response = false;
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $body ) || empty( $body->tag_name ) ) {
            set_transient( $transient_key, 'error_backoff', HOUR_IN_SECONDS );
            $this->github_response = false;
            return false;
        }

        // Cache successful release check for 12 hours
        set_transient( $transient_key, $body, 12 * HOUR_IN_SECONDS );
        $this->github_response = $body;
        return $body;
    }

    public function check_for_update( $transient ) {
        if ( empty( $this->repo ) || empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->fetch_latest_release();
        if ( ! $release || empty( $release->tag_name ) ) {
            return $transient;
        }

        $latest_version = ltrim( $release->tag_name, 'vV' );
        if ( version_compare( $latest_version, ADVISIO_AI_VERSION, '>' ) ) {
            $package_url = isset( $release->zipball_url ) ? $release->zipball_url : '';
            if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
                foreach ( $release->assets as $asset ) {
                    if ( ! empty( $asset->browser_download_url ) && false !== strpos( $asset->name, '.zip' ) ) {
                        $package_url = $asset->browser_download_url;
                        break;
                    }
                }
            }

            if ( ! empty( $package_url ) ) {
                $plugin_obj              = new stdClass();
                $plugin_obj->slug        = dirname( $this->plugin_slug );
                $plugin_obj->plugin      = $this->plugin_slug;
                $plugin_obj->new_version = $latest_version;
                $plugin_obj->url         = 'https://github.com/' . $this->repo;
                $plugin_obj->package     = $package_url;

                $transient->response[ $this->plugin_slug ] = $plugin_obj;
            }
        }

        return $transient;
    }

    public function plugin_popup_info( $result, $action, $args ) {
        if ( empty( $this->repo ) || 'plugin_information' !== $action || empty( $args->slug ) || dirname( $this->plugin_slug ) !== $args->slug ) {
            return $result;
        }

        $release = $this->fetch_latest_release();
        if ( ! $release || empty( $release->tag_name ) ) {
            return $result;
        }

        $info                = new stdClass();
        $info->name          = 'Advisio AI';
        $info->slug          = dirname( $this->plugin_slug );
        $info->version       = ltrim( $release->tag_name, 'vV' );
        $info->author        = '<a href="https://github.com/' . esc_attr( $this->repo ) . '">JC-ITC</a>';
        $info->homepage      = 'https://github.com/' . $this->repo;
        $info->download_link = isset( $release->zipball_url ) ? $release->zipball_url : '';
        $info->sections      = array(
            'description' => 'Advisio AI chat service — Guided Commerce & Intelligent Support by JC-ITC.',
            'changelog'   => ! empty( $release->body ) ? nl2br( esc_html( $release->body ) ) : 'Latest release from GitHub.',
        );

        return $info;
    }

    public function after_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;
        $install_directory = plugin_dir_path( $this->plugin_file );
        $wp_filesystem->move( $result['destination'], $install_directory );
        $result['destination'] = $install_directory;

        if ( is_plugin_active( $this->plugin_slug ) ) {
            activate_plugin( $this->plugin_slug );
        }
        return $result;
    }
}

class Advisio_AI {

    public function __construct() {
        // Database table safety: Run dbDelta ONLY on activation or admin version upgrade
        register_activation_hook( __FILE__, array( 'Advisio_AI', 'activate_plugin' ) );

        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'admin_init_tasks' ) );
        add_action( 'in_admin_header', array( $this, 'suppress_foreign_admin_notices' ), 9999 );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

        // Lightweight frontend hooks (Zero heavy SQL queries on page load)
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_head', array( $this, 'inject_dynamic_css' ) );
        add_action( 'wp_footer', array( $this, 'render_chat_widget' ) );

        // Admin AJAX endpoints (Two-Phase Queue Indexer + Knowledge + Proposals)
        add_action( 'wp_ajax_advisio_test_api_key', array( $this, 'ajax_test_api_key' ) );
        add_action( 'wp_ajax_advisio_get_index_queue', array( $this, 'ajax_get_index_queue' ) );
        add_action( 'wp_ajax_advisio_process_index_batch', array( $this, 'ajax_process_index_batch' ) );
        add_action( 'wp_ajax_advisio_upload_knowledge', array( $this, 'ajax_upload_knowledge' ) );
        add_action( 'wp_ajax_advisio_delete_custom_doc', array( $this, 'ajax_delete_custom_doc' ) );
        add_action( 'wp_ajax_advisio_handle_proposal', array( $this, 'ajax_handle_proposal' ) );
        add_action( 'wp_ajax_advisio_run_learning_engine', array( $this, 'ajax_run_learning_engine' ) );

        // Frontend & Public AJAX endpoints
        add_action( 'wp_ajax_advisio_send_chat_message', array( $this, 'fg_handle_chat_message' ) );
        add_action( 'wp_ajax_nopriv_advisio_send_chat_message', array( $this, 'fg_handle_chat_message' ) );
        add_action( 'wp_ajax_advisio_submit_lead', array( $this, 'handle_submit_lead' ) );
        add_action( 'wp_ajax_nopriv_advisio_submit_lead', array( $this, 'handle_submit_lead' ) );
    }

    /**
     * Admin-only initialization: Settings registration, DB version check, and GitHub updater.
     * Never runs on frontend visitors.
     */
    public function admin_init_tasks() {
        $installed_ver = get_option( 'advisio_db_version', '0' );
        if ( version_compare( $installed_ver, ADVISIO_AI_VERSION, '<' ) ) {
            self::activate_plugin();
        }

        $this->register_settings();
        new Advisio_AI_GitHub_Updater( ADVISIO_AI_PLUGIN_FILE );
    }

    /**
     * Create and migrate custom tables via dbDelta (called ONLY on activation or version bump).
     */
    public static function activate_plugin() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_knowledge = $wpdb->prefix . 'fg_ai_knowledge';
        $sql_knowledge = "CREATE TABLE $table_knowledge (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) DEFAULT 0 NOT NULL,
            title text NOT NULL,
            content longtext NOT NULL,
            type varchar(255) NOT NULL,
            scope varchar(50) DEFAULT 'content' NOT NULL,
            url varchar(255) NOT NULL,
            source_type varchar(50) DEFAULT 'internal' NOT NULL,
            last_indexed datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY url (url(191)),
            KEY scope (scope),
            KEY post_id (post_id)
        ) $charset_collate;";

        $table_logs = $wpdb->prefix . 'fg_ai_logs';
        $sql_logs = "CREATE TABLE $table_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            user_message text NOT NULL,
            bot_reply longtext NOT NULL,
            is_lead tinyint(1) DEFAULT 0 NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY session_id (session_id(191)),
            KEY is_lead (is_lead),
            KEY created_at (created_at)
        ) $charset_collate;";

        $table_proposals = $wpdb->prefix . 'fg_ai_proposals';
        $sql_proposals = "CREATE TABLE $table_proposals (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            proposal_text text NOT NULL,
            rationale text NOT NULL,
            category varchar(100) DEFAULT 'Strategy' NOT NULL,
            status varchar(50) DEFAULT 'pending' NOT NULL,
            batch_number int(11) DEFAULT 1 NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_knowledge );
        dbDelta( $sql_logs );
        dbDelta( $sql_proposals );

        if ( false === get_option( 'advisio_lead_form_fields', false ) ) {
            update_option( 'advisio_lead_form_fields', wp_json_encode( self::get_default_lead_fields() ) );
        }

        update_option( 'advisio_db_version', ADVISIO_AI_VERSION );
    }

    /**
     * Default dynamic lead form structure.
     */
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
            'advisio_github_repo',
            'advisio_github_token',
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
        );
        foreach ( $settings as $s ) {
            register_setting( 'advisio_ai_settings', $s );
        }
    }

    public function admin_enqueue_scripts( $hook ) {
        if ( 'toplevel_page_advisio-ai' !== $hook ) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true );

        wp_enqueue_script(
            'advisio-admin-js',
            ADVISIO_AI_PLUGIN_URL . 'assets/js/admin-script.js',
            array( 'jquery', 'wp-color-picker', 'chart-js' ),
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
            'monthlyStats' => $perf_data['monthly'],
            'yearlyStats'  => $perf_data['yearly'],
        ) );
    }

    /**
     * Compute both Monthly View (1 month back, day-by-day) and Yearly View (12 months back, month-by-month) for Chart.js.
     */
    private function get_performance_chart_data() {
        global $wpdb;
        $table_logs = $wpdb->prefix . 'fg_ai_logs';

        // 1. Monthly View: 1 Month (Last 30 Days day-by-day)
        $m_labels   = array();
        $m_messages = array();
        $m_leads    = array();

        for ( $i = 29; $i >= 0; $i-- ) {
            $ymd        = date( 'Y-m-d', strtotime( "-$i days" ) );
            $m_labels[] = date( 'd M', strtotime( "-$i days" ) );

            $m_messages[] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_logs WHERE DATE(created_at) = %s AND is_lead = 0",
                $ymd
            ) );
            $m_leads[] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_logs WHERE DATE(created_at) = %s AND is_lead = 1",
                $ymd
            ) );
        }

        // 2. Yearly View: 1 Year Back (Last 12 Months month-by-month)
        $y_labels   = array();
        $y_messages = array();
        $y_leads    = array();

        for ( $i = 11; $i >= 0; $i-- ) {
            $ym         = date( 'Y-m', strtotime( "-$i months" ) );
            $y_labels[] = date( 'M Y', strtotime( "-$i months" ) );

            $y_messages[] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_logs WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s AND is_lead = 0",
                $ym
            ) );
            $y_leads[] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_logs WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s AND is_lead = 1",
                $ym
            ) );
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

    /**
     * Localized category definitions strictly in the selected plugin language (no mixed languages, no pre-filled dummy data).
     */
    private function get_localized_inquiry_taxonomy( $lang ) {
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

        global $wpdb;
        $table_logs      = $wpdb->prefix . 'fg_ai_logs';
        $table_knowledge = $wpdb->prefix . 'fg_ai_knowledge';
        $table_proposals = $wpdb->prefix . 'fg_ai_proposals';

        // KPI Metrics
        $msg_today         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_logs WHERE DATE(created_at) = CURDATE() AND is_lead = 0" );
        $msg_week          = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_logs WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1) AND is_lead = 0" );
        $leads_total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_logs WHERE is_lead = 1" );
        $pending_proposals = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_proposals WHERE status = 'pending'" );
        $total_sessions    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT session_id) FROM $table_logs WHERE is_lead = 0" );
        $last_analyzed_cnt = (int) get_option( 'advisio_last_analyzed_session_count', 0 );
        $sessions_until_50 = max( 0, 50 - ( $total_sessions - $last_analyzed_cnt ) );

        // Index Timestamps, Delta Counts & Totals
        $shop_stat    = get_option( 'advisio_last_shop_index', false );
        $content_stat = get_option( 'advisio_last_content_index', false );
        $shop_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_knowledge WHERE scope = 'shop'" );
        $cont_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_knowledge WHERE scope = 'content'" );
        $cust_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_knowledge WHERE scope = 'custom'" );

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

        // Lead Form Fields
        $lead_fields_raw = get_option( 'advisio_lead_form_fields', '' );
        $lead_fields     = json_decode( $lead_fields_raw, true );
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
                    <p class="subtitle">Advisio AI chat service — by JC-ITC</p>
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
                                    <h4>API &amp; Language</h4>
                                    <p>Open <strong>Tab 2 (Setup)</strong>, select your primary customer language, and verify your Gemini API Key.</p>
                                </div>
                                <div class="qs-card">
                                    <span class="qs-step">Step 2</span>
                                    <h4>Queue-Based Deep Sync</h4>
                                    <p>Use the crash-proof <strong>Full Index</strong> or <strong>Quick Sync (New/Updated Only)</strong> buttons for Shop, Site Content, or upload local FAQ files.</p>
                                </div>
                                <div class="qs-card">
                                    <span class="qs-step">Step 3</span>
                                    <h4>Support First &amp; Subpage Links</h4>
                                    <p>In <strong>Tab 3 (AI Behavior)</strong>, configure your persona. Advisio AI answers questions and links directly to relevant subpages and forms.</p>
                                </div>
                                <div class="qs-card">
                                    <span class="qs-step">Step 4</span>
                                    <h4>AI Lead Summary &amp; Learning</h4>
                                    <p>Review leads with automatic <strong>3–4 bullet AI Summaries</strong> in <strong>Tab 5</strong> and approve 50-chat learning proposals right here.</p>
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
                        <div class="advisio-layout-split">
                            <div class="advisio-card">
                                <h2>🌍 Customer Response Language &amp; Analytics Locale</h2>
                                <p class="desc">Defines both the customer-facing AI response language and the single language used for Dynamic Top Inquiries categories.</p>
                                <table class="form-table">
                                    <tr>
                                        <th>Response Language</th>
                                        <td>
                                            <select name="advisio_language" id="advisio_language" class="advisio-input" style="max-width:320px;">
                                                <?php $cur_lang = get_option( 'advisio_language', 'Swedish' ); ?>
                                                <option value="Swedish" <?php selected( $cur_lang, 'Swedish' ); ?>>🇸🇪 Swedish (Svenska — Default)</option>
                                                <option value="English" <?php selected( $cur_lang, 'English' ); ?>>🇬🇧 English</option>
                                                <option value="Norwegian" <?php selected( $cur_lang, 'Norwegian' ); ?>>🇳🇴 Norwegian (Norsk)</option>
                                                <option value="Danish" <?php selected( $cur_lang, 'Danish' ); ?>>🇩🇰 Danish (Dansk)</option>
                                                <option value="German" <?php selected( $cur_lang, 'German' ); ?>>🇩🇪 German (Deutsch)</option>
                                                <option value="Finnish" <?php selected( $cur_lang, 'Finnish' ); ?>>🇫🇮 Finnish (Suomi)</option>
                                            </select>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <div class="advisio-card">
                                <h2>🔑 Gemini API Configuration</h2>
                                <p class="desc"><span class="fg-model-badge">Active Engine: Gemini 3.5 Flash Lite</span></p>
                                <p class="desc">Locked with Hallucination Guard (<code>temperature: 0.2</code>, <code>topP: 0.8</code>) for deterministic product and policy accuracy.</p>
                                <?php $api_key = get_option( 'advisio_api_key', '' ); ?>
                                <div class="api-key-row">
                                    <input type="password" name="advisio_api_key" id="advisio_api_key" value="<?php echo esc_attr( $api_key ); ?>" placeholder="AIzaSy..." class="advisio-input">
                                    <button type="button" id="toggle-api-key" class="advisio-btn advisio-btn-secondary" title="Show/Hide API Key">👁️</button>
                                    <button type="button" id="btn-test-api" class="advisio-btn advisio-btn-primary">Save &amp; Test API Key</button>
                                </div>
                                <div id="api-test-result"></div>
                            </div>
                        </div>

                        <!-- Explicit Full vs. Quick Sync Action Buttons & Detailed Delta Badges -->
                        <div class="advisio-card">
                            <h2>⚡ Two-Phase Queue-Based Deep Indexer — Full vs. Quick Sync</h2>
                            <p class="desc">Crash-proof Two-Phase ID Queue architecture processes <strong>5 items per micro-batch</strong> with automatic memory flushing, Elementor/Page-Builder preservation, and interactive form detection.</p>

                            <div class="advisio-indexer-scopes-grid">
                                <!-- Scope 1: Shop -->
                                <div class="indexer-scope-box">
                                    <div>
                                        <h4>🛒 Scope 1: Shop (WooCommerce)</h4>
                                        <p>Extracts products, exact URLs, SKUs, prices, categories, tags, and all taxonomy &amp; custom attributes (dimensions/mått, U-value, glass type, material) + lookup tables.</p>
                                    </div>
                                    <div>
                                        <div class="advisio-delta-badge-box" id="shop-idx-badge">
                                            <?php echo esc_html( $shop_detailed_status ); ?>
                                        </div>
                                        <div class="scope-btn-group">
                                            <button type="button" class="advisio-btn advisio-btn-primary btn-trigger-index" data-scope="shop" data-incremental="0">🔄 Full Shop Index</button>
                                            <button type="button" class="advisio-btn advisio-btn-secondary btn-trigger-index" data-scope="shop" data-incremental="1">⚡ Quick Sync Shop (New/Updated Only)</button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Scope 2: Site Content & Footers -->
                                <div class="indexer-scope-box">
                                    <div>
                                        <h4>📄 Scope 2: Site Content &amp; Footers</h4>
                                        <p>Extracts all published pages, posts, custom post types, Page-Builder/Elementor blocks, interactive forms (Claims, Partnership, Contact), and footer widgets.</p>
                                    </div>
                                    <div>
                                        <div class="advisio-delta-badge-box" id="content-idx-badge">
                                            <?php echo esc_html( $content_detailed_status ); ?>
                                        </div>
                                        <div class="scope-btn-group">
                                            <button type="button" class="advisio-btn advisio-btn-primary btn-trigger-index" data-scope="content" data-incremental="0">🔄 Full Content Index</button>
                                            <button type="button" class="advisio-btn advisio-btn-secondary btn-trigger-index" data-scope="content" data-incremental="1">⚡ Quick Sync Content (New/Updated Only)</button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Scope 3: Universal Master Actions -->
                                <div class="indexer-scope-box scope-universal">
                                    <div>
                                        <h4>🌐 Universal Master Actions</h4>
                                        <p>Sequentially queues and indexes both Shop and Site/Footer Content in 5-item micro-batches and updates both independent delta status badges.</p>
                                    </div>
                                    <div>
                                        <div class="advisio-delta-badge-box">
                                            Combined Active Knowledge Base: <strong id="combined-idx-count"><?php echo intval( $shop_count + $cont_count ); ?> indexed items</strong>
                                        </div>
                                        <div class="scope-btn-group">
                                            <button type="button" class="advisio-btn advisio-btn-save btn-trigger-index" data-scope="all" data-incremental="0">🌐 Index Entire Website (Full)</button>
                                            <button type="button" class="advisio-btn advisio-btn-secondary btn-trigger-index" data-scope="all" data-incremental="1">⚡ Quick Sync Entire Website (New Only)</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="advisio-progress-container" style="display:none;" id="index-progress-container">
                                <div class="advisio-progress-bar" id="index-progress-bar"></div>
                            </div>
                            <div id="index-status">
                                <span class="advisio-badge" id="persistent-idx-status">Ready. Click any Full Index or Quick Sync button above.</span>
                            </div>
                        </div>

                        <!-- Manual Knowledge / FAQ Textarea & Direct File Uploader -->
                        <div class="advisio-card">
                            <h2>📁 Custom Knowledge Base &amp; Direct File Uploader (<code>scope = 'custom'</code>)</h2>
                            <p class="desc">Maintain internal FAQs, support rules, or upload local reference files (<code>.txt</code>, <code>.md</code>, <code>.csv</code>) directly into the AI knowledge base.</p>
                            <?php
                            $manual_notes = $wpdb->get_var( "SELECT content FROM $table_knowledge WHERE source_type = 'manual_notes' LIMIT 1" );
                            $custom_files = $wpdb->get_results( "SELECT id, title, source_type, last_indexed FROM $table_knowledge WHERE scope = 'custom' AND source_type = 'manual_file' ORDER BY id DESC" );
                            ?>

                            <label class="advisio-field-label">Manual Knowledge / FAQ Textarea</label>
                            <textarea name="advisio_manual_text" id="advisio_manual_text" class="advisio-textarea" rows="6" placeholder="Enter custom support knowledge, warranty rules, measurement instructions, or shipping policies..."><?php echo esc_textarea( $manual_notes ); ?></textarea>

                            <div class="advisio-upload-row">
                                <div>
                                    <label class="advisio-field-label">Direct File Uploader (<code>.txt</code>, <code>.md</code>, <code>.csv</code> — Max 5MB)</label>
                                    <input type="file" id="advisio_manual_file" accept=".txt,.md,.csv" class="advisio-file-input">
                                </div>
                                <button type="button" id="btn-save-knowledge" class="advisio-btn advisio-btn-primary">💾 Save Manual Notes &amp; Upload File</button>
                            </div>
                            <div id="knowledge-save-status"></div>

                            <?php if ( ! empty( $custom_files ) ) : ?>
                                <div class="advisio-custom-files-list" id="advisio-custom-files-list">
                                    <h4>Uploaded Custom Documents:</h4>
                                    <table class="advisio-table">
                                        <thead>
                                            <tr>
                                                <th>Document Title</th>
                                                <th>Source</th>
                                                <th>Indexed Timestamp</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $custom_files as $cf ) : ?>
                                                <tr id="custom-doc-row-<?php echo intval( $cf->id ); ?>">
                                                    <td><?php echo esc_html( $cf->title ); ?></td>
                                                    <td><span class="advisio-tag">📁 Local File</span></td>
                                                    <td><?php echo esc_html( $cf->last_indexed ); ?></td>
                                                    <td><button type="button" class="advisio-btn advisio-btn-reject btn-delete-doc" data-id="<?php echo intval( $cf->id ); ?>">Delete</button></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Built-In GitHub Release Auto-Updater (Password-Protected Developer Panel) -->
                        <div class="advisio-card advisio-dev-lock-card">
                            <div id="advisio-dev-gate" class="advisio-dev-gate">
                                <div class="advisio-card-header-flex" style="align-items:center;">
                                    <div>
                                        <h2 style="margin:0;">🔒 Developer Update Channel (JC-ITC Only)</h2>
                                        <p class="desc" style="margin:4px 0 0 0;">Protected developer configuration for GitHub Release Auto-Updates (12h transient cached).</p>
                                    </div>
                                    <div class="api-key-row" style="margin:0;">
                                        <input type="password" id="advisio-dev-password-input" class="advisio-input" placeholder="Developer password..." style="width:190px;">
                                        <button type="button" id="btn-unlock-dev-github" class="advisio-btn advisio-btn-secondary">🔓 Unlock</button>
                                    </div>
                                </div>
                                <div id="advisio-dev-lock-msg"></div>
                            </div>

                            <div id="advisio-dev-github-panel" style="display:none; margin-top:16px; padding-top:16px; border-top:1px solid var(--border-color);">
                                <h2>🔄 Built-In GitHub Release Auto-Updater (Developer Settings)</h2>
                                <p class="desc">Connect Advisio AI to your GitHub repository to receive one-click release updates directly in <strong>WordPress Admin &rarr; Plugins</strong>. When left empty, this updater stays in a safe idle state with zero overhead.</p>
                                <table class="form-table">
                                    <tr>
                                        <th>GitHub Repository</th>
                                        <td>
                                            <input type="text" name="advisio_github_repo" value="<?php echo esc_attr( get_option( 'advisio_github_repo', '' ) ); ?>" placeholder="e.g., username/advisio-ai" class="advisio-input" style="max-width:420px;">
                                            <p class="desc" style="margin:4px 0 0 0;">Format: <code>owner/repository</code>. Leave blank to keep updater idle.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>GitHub Personal Access Token</th>
                                        <td>
                                            <input type="password" name="advisio_github_token" value="<?php echo esc_attr( get_option( 'advisio_github_token', '' ) ); ?>" placeholder="ghp_... (Optional — only required for private repositories)" class="advisio-input" style="max-width:420px;">
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- ==========================================
                         TAB 3: AI BEHAVIOR, HIERARCHY & CONSTRAINTS
                         ========================================== -->
                    <div id="tab-persona" class="advisio-tab-content">
                        <!-- Built-in Hardened Architecture Summary -->
                        <div class="advisio-card advisio-hierarchy-banner">
                            <h2>🛡️ Active Core Guardrails: Support-First Psychology, Subpage Links &amp; Smart Chips</h2>
                            <div class="advisio-guardrails-grid">
                                <div class="guardrail-item">
                                    <strong>1. Initial Welcome Cleanliness</strong>
                                    <p>When the chat opens initially, ONLY the warm welcome greeting is shown—no premature quick-reply chips or callback buttons until the customer writes their first message.</p>
                                </div>
                                <div class="guardrail-item">
                                    <strong>2. Support First &amp; Direct Subpage Links</strong>
                                    <p>Answers customer questions helpfully and explicitly refers them to dedicated subpages/forms (Claims/Reklamation, Partnership, Terms, Guides, Products) with clickable links.</p>
                                </div>
                                <div class="guardrail-item">
                                    <strong>3. One Question + Mandatory "Not Sure" Chip</strong>
                                    <p>Asks strictly ONE guiding question per reply and always provides 2–4 context chips including <code>Inte säker</code> (or language equivalent) so the AI can assist if the user is unsure.</p>
                                </div>
                                <div class="guardrail-item">
                                    <strong>4. Smart Measurement Chips</strong>
                                    <p>When asking for exact dimensions (Width &times; Height), never outputs fake number chips. Outputs <code>[ 📐 Hur mäter jag? | 🤔 Inte säker ]</code> alongside the persistent callback button.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Textarea 1: System Instructions & Persona -->
                        <div class="advisio-card">
                            <h2>1. System Instructions &amp; Persona</h2>
                            <p class="desc">Define the AI's role, helpful tone, and support guidelines. <em>Note: For optimal AI reasoning and multilingual response quality, System Instructions should ideally be written in English.</em></p>
                            <textarea name="advisio_system_instruction" class="advisio-textarea" rows="6"><?php echo esc_textarea( get_option( 'advisio_system_instruction', 'You are Advisio AI, a warm, knowledgeable customer support specialist and consultative window and door advisor. Prioritize helpful customer support first. When guiding customers toward products, establish the right main product (window or door) and dimensions (width x height) first, treating accessories/add-ons as secondary extras.' ) ); ?></textarea>
                        </div>

                        <!-- Textarea 2: Strict Negative Constraints / DONTs -->
                        <div class="advisio-card advisio-dont-card">
                            <h2>2. Strict Negative Constraints / DONTs</h2>
                            <p class="desc" style="color: #F43F5E;">Explicitly forbidden topics or behaviors (e.g., never mention solar panels, never push quotes prematurely, never sell loose accessories without a main window/door, never invent prices or URLs).</p>
                            <textarea name="advisio_negative_constraints" class="advisio-textarea" rows="5"><?php echo esc_textarea( get_option( 'advisio_negative_constraints', "- Never push quotes prematurely before helping the customer or answering their support question.\n- Never sell add-ons (handles, grilles/spröjs, vents, frame screws) as standalone products.\n- Never ask more than ONE question per message.\n- Never guess prices, policies, or URLs not present in the Knowledge Base." ) ); ?></textarea>
                        </div>

                        <!-- Textarea 3: Target Information Goals -->
                        <div class="advisio-card">
                            <h2>3. Target Information Goals (Qualifying Checklist)</h2>
                            <p class="desc">Written in your selected site language. Advisio AI will naturally gather these parameters one step at a time when guiding a buyer:</p>
                            <textarea name="advisio_target_goals" class="advisio-textarea" rows="4"><?php echo esc_textarea( get_option( 'advisio_target_goals', "1. Typ av huvudprodukt (Fönster, Altandörr, Skjutdörr eller Ytterdörr)\n2. Exakta mått: Bredd x Höjd (i mm eller cm)\n3. Öppningsfunktion (Fast eller öppningsbart)\n4. Glastyp / U-värde (2-glas eller 3-glas isolerglas)\n5. Material (Trä, Trä/Aluminium eller PVC)" ) ); ?></textarea>
                        </div>

                        <!-- 4. Approved Learning Strategies List -->
                        <div class="advisio-card">
                            <h2>4. Approved AI Learning Strategies (<code>### APPROVED AI LEARNING STRATEGIES ###</code>)</h2>
                            <p class="desc">Rules approved from the 50-Chat Autonomous Learning Engine are automatically appended here and injected into the active prompt block. You may also edit, refine, or add rules manually below (one strategy per line).</p>
                            <textarea name="advisio_approved_strategies" id="advisio_approved_strategies" class="advisio-textarea" rows="6" placeholder="Approved AI learning strategies will appear here automatically when you click [✓ Approve] on Dashboard proposals..."><?php echo esc_textarea( get_option( 'advisio_approved_strategies', '' ) ); ?></textarea>
                        </div>
                    </div>

                    <!-- ==========================================
                         TAB 4: WIDGET DESIGN & LIVE PREVIEW
                         ========================================== -->
                    <div id="tab-widget" class="advisio-tab-content">
                        <div class="advisio-split-design">
                            <!-- Left Column: Controls -->
                            <div class="advisio-card advisio-design-controls">
                                <h2>🎨 Widget Design &amp; Branding Controls</h2>
                                <table class="form-table">
                                    <tr>
                                        <th>Chat Header Title</th>
                                        <td>
                                            <input type="text" name="advisio_chat_title" id="preview_chat_title" value="<?php echo esc_attr( get_option( 'advisio_chat_title', 'Advisio AI Kundtjänst' ) ); ?>" class="advisio-input">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Initial Welcome Greeting</th>
                                        <td>
                                            <input type="text" name="advisio_welcome_msg" id="preview_welcome_msg" value="<?php echo esc_attr( get_option( 'advisio_welcome_msg', 'Hej! Välkommen till vår kundtjänst. Hur kan jag hjälpa dig idag?' ) ); ?>" class="advisio-input">
                                            <p class="desc" style="margin:4px 0 0 0;">Displayed cleanly without any quick-reply chips or callback buttons until the customer writes.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Launcher Icon Selector</th>
                                        <td>
                                            <select name="advisio_launcher_icon" id="advisio_launcher_icon" class="advisio-input">
                                                <option value="preset-1" <?php selected( get_option( 'advisio_launcher_icon' ), 'preset-1' ); ?>>1. Modern Chat Bubble (SVG)</option>
                                                <option value="preset-2" <?php selected( get_option( 'advisio_launcher_icon' ), 'preset-2' ); ?>>2. AI Sparkles / Guided Bot (SVG)</option>
                                                <option value="preset-3" <?php selected( get_option( 'advisio_launcher_icon' ), 'preset-3' ); ?>>3. Support Specialist Headset (SVG)</option>
                                                <option value="preset-4" <?php selected( get_option( 'advisio_launcher_icon' ), 'preset-4' ); ?>>4. Instant Lightning (SVG)</option>
                                                <option value="custom" <?php selected( get_option( 'advisio_launcher_icon' ), 'custom' ); ?>>5. Upload Custom Brand Icon (WP Media)</option>
                                            </select>
                                            <div id="custom-icon-wrapper" style="<?php echo 'custom' === get_option( 'advisio_launcher_icon' ) ? 'display:block;' : 'display:none;'; ?> margin-top:12px;">
                                                <input type="hidden" name="advisio_custom_icon" id="advisio_custom_icon" value="<?php echo esc_attr( get_option( 'advisio_custom_icon' ) ); ?>">
                                                <img id="custom-icon-preview" src="<?php echo esc_attr( get_option( 'advisio_custom_icon' ) ); ?>" style="max-height:52px; display: <?php echo get_option( 'advisio_custom_icon' ) ? 'block' : 'none'; ?>; margin-bottom:10px; border-radius:8px;">
                                                <button type="button" class="advisio-btn advisio-btn-secondary" id="btn-upload-icon">Upload / Select Image</button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Launcher &amp; Accent Color</th>
                                        <td>
                                            <input type="text" name="advisio_color_primary" id="preview_color_primary" value="<?php echo esc_attr( get_option( 'advisio_color_primary', '#6366F1' ) ); ?>" class="advisio-color-picker">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Header Background Color</th>
                                        <td>
                                            <input type="text" name="advisio_color_header" id="preview_color_header" value="<?php echo esc_attr( get_option( 'advisio_color_header', '#1E293B' ) ); ?>" class="advisio-color-picker">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Proactive Greeting Bubble</th>
                                        <td>
                                            <label class="advisio-switch-label" style="margin-bottom:10px;">
                                                <input type="checkbox" name="advisio_proactive" id="preview_proactive_toggle" value="1" <?php checked( get_option( 'advisio_proactive', 1 ), 1 ); ?>>
                                                <span>Show floating greeting bubble above launcher after 5 seconds</span>
                                            </label>
                                            <input type="text" name="advisio_proactive_text" id="preview_proactive_text" value="<?php echo esc_attr( get_option( 'advisio_proactive_text', 'Behöver du hjälp med mått eller fönsterval? 👋' ) ); ?>" class="advisio-input" placeholder="Proactive greeting text...">
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Right Column: Real-Time Live Preview -->
                            <div class="advisio-design-preview-container">
                                <h3>⚡ Real-Time Live Preview</h3>
                                <div class="advisio-live-preview-box">
                                    <div class="live-preview-chat-window" id="lp-window">
                                        <div class="lp-header" id="lp-header-bg">
                                            <h4 id="lp-title"><?php echo esc_html( get_option( 'advisio_chat_title', 'Advisio AI Kundtjänst' ) ); ?></h4>
                                            <span>&times;</span>
                                        </div>
                                        <div class="lp-body">
                                            <!-- Initial welcome message without chips -->
                                            <div class="lp-msg bot">
                                                <div class="lp-bubble" id="lp-welcome-bubble"><?php echo esc_html( get_option( 'advisio_welcome_msg', 'Hej! Välkommen till vår kundtjänst. Hur kan jag hjälpa dig idag?' ) ); ?></div>
                                            </div>
                                            <!-- Customer first message -->
                                            <div class="lp-msg user">
                                                <div class="lp-bubble" id="lp-user-bubble">Jag letar efter ett 3-glas fönster.</div>
                                            </div>
                                            <!-- AI response with measurement chips + Not sure + Callback -->
                                            <div class="lp-msg bot">
                                                <div class="lp-bubble">Absolut! Vilka mått (bredd &times; höjd) behöver du på fönstret?</div>
                                            </div>
                                            <div class="lp-chips">
                                                <span class="lp-chip">📐 Hur mäter jag?</span>
                                                <span class="lp-chip">🤔 Inte säker</span>
                                                <span class="lp-chip lp-chip-primary" id="lp-cb-chip"><?php echo esc_html( get_option( 'advisio_label_callback', '📞 Bli kontaktad av en säljare' ) ); ?></span>
                                            </div>
                                        </div>
                                        <div class="lp-footer">
                                            <input type="text" disabled placeholder="Skriv ditt meddelande...">
                                            <button type="button" id="lp-send-btn">&#10148;</button>
                                        </div>
                                    </div>
                                    <div class="lp-launcher-row">
                                        <div class="lp-proactive-pill" id="lp-proactive-pill"><?php echo esc_html( get_option( 'advisio_proactive_text', 'Behöver du hjälp med mått eller fönsterval? 👋' ) ); ?></div>
                                        <div class="lp-launcher-btn" id="lp-launcher">
                                            <span id="lp-icon-render"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ==========================================
                         TAB 5: LEADS & DYNAMIC FORM BUILDER
                         ========================================== -->
                    <div id="tab-lead" class="advisio-tab-content">
                        <div class="advisio-layout-split">
                            <!-- Left Column: Notification Settings & Labels -->
                            <div class="advisio-card">
                                <h2>✉️ Lead Notification &amp; Handover Settings</h2>
                                <p class="desc">When a customer submits the lead form, Advisio AI automatically generates a <strong>3–4 bullet AI Conversation Summary</strong> and places it at the top of your notification email and inside the Admin Leads table below.</p>
                                <table class="form-table">
                                    <tr>
                                        <th>Notification Recipient Email</th>
                                        <td>
                                            <input type="email" name="advisio_notify_email" value="<?php echo esc_attr( get_option( 'advisio_notify_email', get_option( 'admin_email' ) ) ); ?>" class="advisio-input">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Persistent Callback Button Label</th>
                                        <td>
                                            <input type="text" name="advisio_label_callback" id="advisio_label_callback" value="<?php echo esc_attr( get_option( 'advisio_label_callback', '📞 Bli kontaktad av en säljare' ) ); ?>" class="advisio-input">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Lead Form Drawer Header</th>
                                        <td>
                                            <input type="text" name="advisio_label_lead_header" id="advisio_label_lead_header" value="<?php echo esc_attr( get_option( 'advisio_label_lead_header', 'Fyll i dina uppgifter så återkommer en rådgivare:' ) ); ?>" class="advisio-input">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Submission Confirmation Text</th>
                                        <td>
                                            <input type="text" name="advisio_label_lead_success" value="<?php echo esc_attr( get_option( 'advisio_label_lead_success', 'Tack! En rådgivare kommer att kontakta dig inom kort.' ) ); ?>" class="advisio-input">
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Right Column: Interactive Form Builder -->
                            <div class="advisio-card">
                                <div class="advisio-card-header-flex">
                                    <div>
                                        <h2>🛠️ Dynamic Lead Form Field Builder</h2>
                                        <p class="desc">Add, remove, or reorder fields. Includes built-in <strong>Preferred Contact Method (✉️ Email / 📞 Phone)</strong> and Smart Progressive Profiling.</p>
                                    </div>
                                    <button type="button" id="btn-add-lead-field" class="advisio-btn advisio-btn-primary">+ Add Form Field</button>
                                </div>

                                <input type="hidden" name="advisio_lead_form_fields" id="advisio_lead_form_fields_input" value="<?php echo esc_attr( $lead_fields_raw ); ?>">

                                <div id="advisio-field-builder-list" class="advisio-field-builder-list"></div>

                                <div class="advisio-lead-preview-panel">
                                    <h4>Live Lead Drawer Preview</h4>
                                    <div class="advisio-lead-preview-inner">
                                        <p id="lp-lead-header-text" style="font-weight:600; margin:0 0 8px 0;"><?php echo esc_html( get_option( 'advisio_label_lead_header', 'Fyll i dina uppgifter så återkommer en rådgivare:' ) ); ?></p>
                                        <div class="lp-contact-method-preview">
                                            <span>Preferred Contact:</span>
                                            <span class="lp-pill active">✉️ Email</span>
                                            <span class="lp-pill">📞 Phone</span>
                                        </div>
                                        <div id="advisio-lead-form-live-preview"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Captured Leads Log Table with Highlighted AI Conversation Summary -->
                        <div class="advisio-card">
                            <h2>📋 Captured Leads &amp; AI Conversation Summaries (Latest 50)</h2>
                            <p class="desc">Every lead includes the customer's preferred contact channel, submitted form fields, and an automatic 3–4 bullet AI Summary of their chat conversation.</p>
                            <?php
                            $captured_leads = $wpdb->get_results( "SELECT * FROM $table_logs WHERE is_lead = 1 ORDER BY id DESC LIMIT 50" );
                            if ( empty( $captured_leads ) ) :
                                ?>
                                <p class="desc">No leads captured yet.</p>
                            <?php else : ?>
                                <table class="advisio-table">
                                    <thead>
                                        <tr>
                                            <th style="width:140px;">Date</th>
                                            <th style="width:130px;">Preferred Contact</th>
                                            <th style="width:260px;">Customer Details</th>
                                            <th>🤖 AI Quick Summary of Conversation</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        foreach ( $captured_leads as $lead ) :
                                            $decoded_lead = json_decode( $lead->user_message, true );
                                            $pref_contact = isset( $decoded_lead['preferred_contact'] ) ? $decoded_lead['preferred_contact'] : 'Email';
                                            $fields_map   = isset( $decoded_lead['fields'] ) && is_array( $decoded_lead['fields'] ) ? $decoded_lead['fields'] : array();
                                            $ai_summary   = isset( $decoded_lead['ai_summary'] ) ? $decoded_lead['ai_summary'] : $lead->bot_reply;
                                            ?>
                                            <tr>
                                                <td><?php echo esc_html( date( 'Y-m-d H:i', strtotime( $lead->created_at ) ) ); ?></td>
                                                <td>
                                                    <span class="advisio-tag"><?php echo 'Phone' === $pref_contact ? '📞 Phone' : '✉️ Email'; ?></span>
                                                </td>
                                                <td>
                                                    <?php if ( ! empty( $fields_map ) ) : ?>
                                                        <?php foreach ( $fields_map as $f_lbl => $f_val ) : ?>
                                                            <div><strong><?php echo esc_html( $f_lbl ); ?>:</strong> <?php echo esc_html( $f_val ); ?></div>
                                                        <?php endforeach; ?>
                                                    <?php else : ?>
                                                        <div><?php echo esc_html( $lead->user_message ); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="advisio-lead-summary-box">
                                                        <?php if ( ! empty( $ai_summary ) ) : ?>
                                                            <?php echo nl2br( esc_html( $ai_summary ) ); ?>
                                                        <?php else : ?>
                                                            <em>Direct lead submission (no prior chat messages).</em>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ==========================================
                         TAB 6: DOCUMENTATION (FULL SYSTEM MANUAL)
                         ========================================== -->
                    <div id="tab-docs" class="advisio-tab-content">
                        <div class="advisio-card">
                            <h2>📖 Advisio AI (DEMO) — Full System Documentation &amp; Operations Manual</h2>
                            <p class="desc">Comprehensive reference guide for administrators managing the Advisio AI chat service — by JC-ITC.</p>
                        </div>

                        <div class="advisio-docs-grid">
                            <!-- Section 1 -->
                            <div class="advisio-card doc-card">
                                <div class="doc-num">01</div>
                                <h3>System Architecture &amp; Hybrid RAG Memory</h3>
                                <p>Advisio AI is engineered around a <strong>Hybrid Local RAG (Retrieval-Augmented Generation)</strong> architecture powered by Google's <strong>Gemini 3.5 Flash Lite</strong> engine (with automatic failover to <code>gemini-3.5-flash</code>).</p>
                                <ul>
                                    <li><strong>0ms Local Retrieval:</strong> WordPress searches its indexed MySQL table (<code>wp_fg_ai_knowledge</code>) using multi-keyword tokenized relevance scoring in milliseconds.</li>
                                    <li><strong>Subpage &amp; Form Referencing:</strong> Each retrieved record injects <code>SOURCE TITLE | PAGE URL | CONTENT</code> so the AI answers questions and links visitors directly to subpages and forms.</li>
                                    <li><strong>Hallucination Guard:</strong> API calls are locked at <code>temperature: 0.2</code> and <code>topP: 0.8</code> with a strict 12-second HTTP timeout.</li>
                                </ul>
                            </div>

                            <!-- Section 2 -->
                            <div class="advisio-card doc-card">
                                <div class="doc-num">02</div>
                                <h3>Crash-Proof Two-Phase Queue Indexer</h3>
                                <p>In <strong>Tab 2</strong>, indexing uses a Two-Phase ID Queue architecture to guarantee 100% site coverage with zero 500 timeout errors:</p>
                                <ul>
                                    <li><strong>Phase 1 (ID Discovery):</strong> Queries only published post IDs matching the scope (or modified/missing IDs during Quick Sync).</li>
                                    <li><strong>Phase 2 (5-Item Micro-Batches):</strong> Processes strictly 5 IDs per request, preserves WPBakery/Salient/Elementor page-builder content, detects embedded forms (WPForms, CF7, Gravity, Elementor), and flushes memory after each batch.</li>
                                    <li><strong>Direct File Uploader:</strong> Upload <code>.txt</code>, <code>.md</code>, or <code>.csv</code> files or enter manual FAQs directly into <code>scope = 'custom'</code>.</li>
                                </ul>
                            </div>

                            <!-- Section 3 -->
                            <div class="advisio-card doc-card">
                                <div class="doc-num">03</div>
                                <h3>Conversational Flow, "Inte säker" &amp; Measurement Chips</h3>
                                <p>Advisio AI follows a strict, calm conversational psychology:</p>
                                <ul>
                                    <li><strong>Clean Initial Welcome:</strong> When the chat window first opens, only the warm welcome greeting is shown—no premature buttons or chips.</li>
                                    <li><strong>One Question + Mandatory "Not Sure" Chip:</strong> After the customer writes, the AI asks one guiding question at a time and always includes <code>Inte säker</code> (or language equivalent) among the 2–4 quick-reply chips.</li>
                                    <li><strong>Measurement Chips:</strong> When asking for Width &times; Height, it never renders fake number chips; instead it shows <code>[ 📐 Hur mäter jag? | 🤔 Inte säker ]</code> alongside the persistent callback button.</li>
                                </ul>
                            </div>

                            <!-- Section 4 -->
                            <div class="advisio-card doc-card">
                                <div class="doc-num">04</div>
                                <h3>2-Column Single-Language Analytics &amp; Monthly/Yearly Graph</h3>
                                <p><strong>Tab 1 (Dashboard)</strong> provides real-time business intelligence:</p>
                                <ul>
                                    <li><strong>2-Column Support vs. Products Blocks:</strong> Starts completely clean without dummy data and populates organically in the selected plugin language as customers chat.</li>
                                    <li><strong>Monthly / Yearly Performance Switcher:</strong> Toggle between <strong>Monthly View (1 Month / Last 30 Days day-by-day)</strong> and <strong>Yearly View (12 Months back month-by-month)</strong> for Messages and Leads.</li>
                                </ul>
                            </div>

                            <!-- Section 5 -->
                            <div class="advisio-card doc-card">
                                <div class="doc-num">05</div>
                                <h3>50-Chat Autonomous Learning &amp; AI Lead Summaries</h3>
                                <p>Advisio AI automates both sales handovers and prompt optimization:</p>
                                <ul>
                                    <li><strong>AI Conversation Summary on Lead Handover:</strong> When a visitor submits the lead form, Gemini generates a 3–4 bullet summary of their needs, product specs, and measurements—highlighted at the top of your notification email and in Tab 5.</li>
                                    <li><strong>50-Chat Strategy Proposals:</strong> Every 50 completed chat sessions, Advisio AI proposes 2–5 new strategy rules that you can approve or reject with one click.</li>
                                </ul>
                            </div>

                            <!-- Section 6 -->
                            <div class="advisio-card doc-card">
                                <div class="doc-num">06</div>
                                <h3>Customizing the Dynamic Lead Form &amp; Progressive Profiling</h3>
                                <p><strong>Tab 5</strong> provides a visual form builder for capturing high-intent quote requests and callbacks:</p>
                                <ul>
                                    <li><strong>Dynamic Field Builder:</strong> Add, remove, or configure <code>text</code>, <code>email</code>, <code>tel</code>, <code>textarea</code>, and <code>select</code> dropdown fields, and toggle mandatory validation per field.</li>
                                    <li><strong>Smart Progressive Profiling:</strong> If a customer already typed their email during the chat conversation, the form automatically pre-fills and hides the email field when opened.</li>
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
        </div>
        <?php
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
                'measure_help' => '📐 Hur mäter jag?',
                'not_sure'     => '🤔 Inte säker',
                'not_sure_raw' => 'Inte säker',
            ),
            'English'   => array(
                'measure_help' => '📐 How do I measure?',
                'not_sure'     => '🤔 Not sure',
                'not_sure_raw' => 'Not sure',
            ),
            'Norwegian' => array(
                'measure_help' => '📐 Hvordan måler jeg?',
                'not_sure'     => '🤔 Usikker',
                'not_sure_raw' => 'Usikker',
            ),
            'Danish'    => array(
                'measure_help' => '📐 Hvordan måler jeg?',
                'not_sure'     => '🤔 Ikke sikker',
                'not_sure_raw' => 'Ikke sikker',
            ),
            'German'    => array(
                'measure_help' => '📐 Wie messe ich richtig?',
                'not_sure'     => '🤔 Nicht sicher',
                'not_sure_raw' => 'Nicht sicher',
            ),
            'Finnish'   => array(
                'measure_help' => '📐 Miten mittaan?',
                'not_sure'     => '🤔 En ole varma',
                'not_sure_raw' => 'En ole varma',
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
        $lead_fields     = json_decode( $lead_fields_raw, true );
        if ( ! is_array( $lead_fields ) || empty( $lead_fields ) ) {
            $lead_fields = self::get_default_lead_fields();
        }

        $lang        = get_option( 'advisio_language', 'Swedish' );
        $chip_labels = self::get_frontend_chip_labels( $lang );

        wp_localize_script( 'advisio-chat-script', 'AdvisioChat', array(
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'advisio_chat_nonce' ),
            'productId'       => ( function_exists( 'is_product' ) && is_product() ) ? get_the_ID() : 0,
            'proactive'       => (int) get_option( 'advisio_proactive', 1 ),
            'welcomeMsg'      => get_option( 'advisio_welcome_msg', 'Hej! Välkommen till vår kundtjänst. Hur kan jag hjälpa dig idag?' ),
            'cbLabel'         => get_option( 'advisio_label_callback', '📞 Bli kontaktad av en säljare' ),
            'leadHeader'      => get_option( 'advisio_label_lead_header', 'Fyll i dina uppgifter så återkommer en rådgivare:' ),
            'leadSuccess'     => get_option( 'advisio_label_lead_success', 'Tack! En rådgivare kommer att kontakta dig inom kort.' ),
            'leadFields'      => $lead_fields,
            'measureHelpChip' => $chip_labels['measure_help'],
            'notSureChip'     => $chip_labels['not_sure'],
            'notSureRaw'      => $chip_labels['not_sure_raw'],
            'language'        => $lang,
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

    /**
     * Helper: Call Gemini API with strict gemini-3.5-flash-lite endpoint, fallback to gemini-3.5-flash, and strict 12s HTTP timeout.
     */
    private function call_gemini_api( $api_key, $payload, $timeout = 12 ) {
        $models      = array( 'gemini-3.5-flash-lite', 'gemini-3.5-flash' );
        $last_error  = 'Unknown API error';
        $req_timeout = min( 12, max( 5, intval( $timeout ) ) );

        foreach ( $models as $model ) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . rawurlencode( $api_key );
            $response = wp_remote_post( $url, array(
                'body'    => wp_json_encode( $payload ),
                'headers' => array( 'Content-Type' => 'application/json' ),
                'timeout' => $req_timeout,
            ) );

            if ( is_wp_error( $response ) ) {
                $last_error = $response->get_error_message();
                continue;
            }

            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $data['error'] ) ) {
                $last_error = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Gemini API Error';
                continue;
            }

            if ( ! empty( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
                return array(
                    'success' => true,
                    'model'   => $model,
                    'text'    => $data['candidates'][0]['content']['parts'][0]['text'],
                );
            }
        }

        return array(
            'success' => false,
            'error'   => $last_error,
        );
    }

    /**
     * AJAX: Save and Test Gemini API Key (12s timeout).
     */
    public function ajax_test_api_key() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        if ( empty( $api_key ) ) {
            wp_send_json_error( 'API Key is empty.' );
        }
        update_option( 'advisio_api_key', $api_key );

        $payload = array(
            'contents'         => array( array( 'parts' => array( array( 'text' => 'Respond with OK' ) ) ) ),
            'generationConfig' => array(
                'temperature' => 0.2,
                'topP'        => 0.8,
            ),
        );

        $res = $this->call_gemini_api( $api_key, $payload, 12 );
        if ( ! $res['success'] ) {
            wp_send_json_error( $res['error'] );
        }

        wp_send_json_success( 'API Key saved & verified with ' . $res['model'] . '!' );
    }

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
     * PHASE 1 AJAX: Lightweight ID Discovery Queue (`advisio_get_index_queue`).
     * Fetches ONLY the IDs of published items matching the selected scope so it never times out.
     */
    public function ajax_get_index_queue() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fg_ai_knowledge';

        $scope       = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : 'shop';
        $incremental = ! empty( $_POST['incremental'] ) && '1' === strval( $_POST['incremental'] );

        if ( 'shop' === $scope ) {
            $post_types  = array( 'product' );
            $last_stat   = get_option( 'advisio_last_shop_index', false );
            $scope_label = 'Shop';
        } else {
            $scope       = 'content';
            $post_types  = $this->get_content_post_types();
            $last_stat   = get_option( 'advisio_last_content_index', false );
            $scope_label = 'Content';
        }

        $last_time    = ( $incremental && is_array( $last_stat ) && ! empty( $last_stat['time'] ) ) ? $last_stat['time'] : '';
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

        if ( $incremental && ! empty( $last_time ) ) {
            // Quick Sync: Fetch IDs where post_modified > last_sync_date OR post_id does not yet exist in wp_fg_ai_knowledge
            $sql_params = array_merge( array( $scope ), $post_types, array( $last_time ) );
            $ids        = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT p.ID
                 FROM {$wpdb->posts} p
                 LEFT JOIN $table k ON (k.post_id = p.ID AND k.scope = %s)
                 WHERE p.post_type IN ($placeholders)
                   AND p.post_status = 'publish'
                   AND (k.id IS NULL OR p.post_modified > %s)
                 ORDER BY p.ID ASC",
                $sql_params
            ) );
        } else {
            // Full Index: Fetch all published IDs in this scope
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 WHERE p.post_type IN ($placeholders)
                   AND p.post_status = 'publish'
                 ORDER BY p.ID ASC",
                $post_types
            ) );
        }

        $queue_ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );

        // For Site Content Full Index (or first sync), append synthetic ID 0 for Footer/Widgets/Menus
        if ( 'content' === $scope && ( ! $incremental || empty( $last_time ) ) ) {
            $queue_ids[] = 0;
        }

        // If 0 items need syncing (e.g., Quick Sync with no modified items), finalize immediately
        if ( empty( $queue_ids ) ) {
            $now         = current_time( 'mysql' );
            $total_in_db = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE scope = %s", $scope ) );
            $stat_arr    = array(
                'total' => $total_in_db,
                'delta' => 0,
                'time'  => $now,
            );

            if ( 'shop' === $scope ) {
                update_option( 'advisio_last_shop_index', $stat_arr );
            } else {
                update_option( 'advisio_last_content_index', $stat_arr );
            }

            $badge_text     = $this->format_delta_status_text( $scope_label, $stat_arr, $total_in_db );
            $short_text     = sprintf( '+0 new (Total: %d) on %s', $total_in_db, date( 'Y-m-d H:i', strtotime( $now ) ) );
            $combined_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE scope IN ('shop', 'content')" );

            wp_send_json_success( array(
                'ids'            => array(),
                'total'          => 0,
                'scope'          => $scope,
                'badge_text'     => $badge_text,
                'short_text'     => $short_text,
                'db_total'       => $total_in_db,
                'combined_total' => $combined_total,
            ) );
        }

        wp_send_json_success( array(
            'ids'   => $queue_ids,
            'total' => count( $queue_ids ),
            'scope' => $scope,
        ) );
    }

    /**
     * PHASE 2 AJAX: Micro-Batch Deep Extraction (`advisio_process_index_batch`).
     * Processes strictly up to 5 IDs per request, preserves Page Builder content, detects forms, and flushes cache.
     */
    public function ajax_process_index_batch() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fg_ai_knowledge';

        $scope        = isset( $_POST['scope'] ) && 'shop' === $_POST['scope'] ? 'shop' : 'content';
        $scope_label  = 'shop' === $scope ? 'Shop' : 'Content';
        $is_final     = ! empty( $_POST['is_final'] ) && '1' === strval( $_POST['is_final'] );
        $total_queued = isset( $_POST['total_queued'] ) ? intval( $_POST['total_queued'] ) : 0;

        $raw_ids = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();
        if ( is_string( $raw_ids ) ) {
            $decoded = json_decode( $raw_ids, true );
            $raw_ids = is_array( $decoded ) ? $decoded : explode( ',', $raw_ids );
        }

        $batch_ids = array_slice( array_map( 'intval', (array) $raw_ids ), 0, 5 );
        $indexed_in_batch = 0;

        foreach ( $batch_ids as $post_id ) {
            try {
                // Synthetic ID 0: Index Footer, Widgets, and Navigation Menus
                if ( 0 === $post_id && 'content' === $scope ) {
                    $this->index_footer_and_policy_extras();
                    $indexed_in_batch++;
                    continue;
                }

                $post = get_post( $post_id );
                if ( ! $post || 'publish' !== $post->post_status ) {
                    continue;
                }

                if ( 'product' === $post->post_type ) {
                    $full_content = $this->extract_woocommerce_product_data( $post );
                    $item_scope   = 'shop';
                } else {
                    $full_content = $this->extract_page_or_policy_data( $post );
                    $item_scope   = 'content';
                }

                if ( empty( trim( $full_content ) ) ) {
                    continue;
                }

                $permalink = get_permalink( $post->ID );
                if ( empty( $permalink ) || is_wp_error( $permalink ) ) {
                    $permalink = home_url( '/?p=' . $post->ID );
                }

                $wpdb->replace(
                    $table,
                    array(
                        'post_id'      => $post->ID,
                        'title'        => $post->post_title,
                        'content'      => $full_content,
                        'type'         => $post->post_type,
                        'scope'        => $item_scope,
                        'url'          => $permalink,
                        'source_type'  => 'internal',
                        'last_indexed' => current_time( 'mysql' ),
                    ),
                    array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
                );

                $indexed_in_batch++;
            } catch ( \Throwable $e ) {
                // Isolate any defective product/page so the batch never triggers a 500 error
                continue;
            }
        }

        // Clean up memory at the end of every 5-item batch
        wp_cache_flush();

        $badge_text     = '';
        $short_text     = '';
        $db_total       = 0;
        $combined_total = 0;

        if ( $is_final ) {
            $now            = current_time( 'mysql' );
            $db_total       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE scope = %s", $scope ) );
            $combined_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE scope IN ('shop', 'content')" );

            $stat_arr = array(
                'total' => $db_total,
                'delta' => $total_queued,
                'time'  => $now,
            );

            if ( 'shop' === $scope ) {
                update_option( 'advisio_last_shop_index', $stat_arr );
            } else {
                update_option( 'advisio_last_content_index', $stat_arr );
            }

            $badge_text = $this->format_delta_status_text( $scope_label, $stat_arr, $db_total );
            $short_text = sprintf( '+%d new (Total: %d) on %s', $total_queued, $db_total, date( 'Y-m-d H:i', strtotime( $now ) ) );
        }

        wp_send_json_success( array(
            'batch_count'    => count( $batch_ids ),
            'indexed_count'  => $indexed_in_batch,
            'is_final'       => $is_final,
            'scope'          => $scope,
            'badge_text'     => $badge_text,
            'short_text'     => $short_text,
            'db_total'       => $db_total,
            'combined_total' => $combined_total,
        ) );
    }

    /**
     * Detect interactive forms in raw post content or Elementor JSON BEFORE stripping tags/shortcodes.
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
     * Clean Page Builder & shortcode content WITHOUT using strip_shortcodes() (preserves enclosed text inside [vc_column_text]...[/vc_column_text] etc.).
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

        $collected = array();
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
     */
    private function extract_page_or_policy_data( $post ) {
        $title_low   = mb_strtolower( $post->post_title, 'UTF-8' );
        $policy_tags = array(
            'villkor', 'köpvillkor', 'reklamation', 'ångerrätt', 'retur', 'garanti',
            'integritet', 'gdpr', 'mätguide', 'mäta', 'montering', 'leverans', 'frakt',
            'partner', 'partnerskap', 'samarbete', 'återförsäljare', 'kontakt', 'formulär',
            'terms', 'policy', 'return', 'claim', 'warranty', 'measure', 'guide', 'faq', 'contact',
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

        // 1. Detect interactive forms BEFORE stripping shortcode brackets or HTML tags
        $has_form = $this->detect_interactive_form( $post->post_content . ' ' . $post->post_excerpt, $elementor_raw );

        // 2. Strip shortcode brackets via regex (preserving WPBakery/Salient/Divi inner text)
        $body_text = $this->clean_page_builder_content( $post->post_excerpt . ' ' . $post->post_content );

        // 3. Extract Elementor JSON widget strings if present
        $elementor_text = $this->extract_elementor_text( $elementor_raw );

        // 4. Extract readable custom meta fields
        $custom_meta_text = $this->extract_custom_meta_text( $post->ID );

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

        $full_text = trim( implode( ' ', $parts ) );
        if ( $has_form ) {
            $full_text .= ' [IMPORTANT: This page contains an interactive form the customer can fill out directly on this URL]';
        }

        return $prefix . 'Title: ' . $post->post_title . "\n" . $full_text;
    }

    /**
     * Index footer widgets, block widgets & navigation menu links into `scope = 'content'`.
     */
    private function index_footer_and_policy_extras() {
        global $wpdb;
        $table = $wpdb->prefix . 'fg_ai_knowledge';

        $widget_texts = array();
        $text_widgets = get_option( 'widget_text', array() );
        if ( is_array( $text_widgets ) ) {
            foreach ( $text_widgets as $tw ) {
                if ( is_array( $tw ) && ! empty( $tw['text'] ) ) {
                    $widget_texts[] = $this->clean_page_builder_content( ( ! empty( $tw['title'] ) ? $tw['title'] . ': ' : '' ) . $tw['text'] );
                }
            }
        }

        $block_widgets = get_option( 'widget_block', array() );
        if ( is_array( $block_widgets ) ) {
            foreach ( $block_widgets as $bw ) {
                if ( is_array( $bw ) && ! empty( $bw['content'] ) ) {
                    $stripped = $this->clean_page_builder_content( $bw['content'] );
                    if ( strlen( $stripped ) > 15 ) {
                        $widget_texts[] = $stripped;
                    }
                }
            }
        }

        // Also index navigation menu items & URLs so subpages linked in headers/footers are always known
        $menus = wp_get_nav_menus();
        if ( ! empty( $menus ) && is_array( $menus ) ) {
            $menu_links = array();
            foreach ( $menus as $menu ) {
                $items = wp_get_nav_menu_items( $menu->term_id );
                if ( ! empty( $items ) && is_array( $items ) ) {
                    foreach ( $items as $item ) {
                        if ( ! empty( $item->title ) && ! empty( $item->url ) ) {
                            $menu_links[] = $item->title . ' (' . $item->url . ')';
                        }
                    }
                }
            }
            if ( ! empty( $menu_links ) ) {
                $widget_texts[] = 'Site Navigation & Subpage Links: ' . implode( ' | ', array_unique( $menu_links ) );
            }
        }

        if ( ! empty( $widget_texts ) ) {
            $combined_footer = "[FOOTER, NAVIGATION & WIDGET INFORMATION]\n" . implode( "\n", $widget_texts );
            $wpdb->replace(
                $table,
                array(
                    'post_id'      => 0,
                    'title'        => 'Site Footer, Navigation & Widget Information',
                    'content'      => $combined_footer,
                    'type'         => 'footer',
                    'scope'        => 'content',
                    'url'          => home_url( '/' ),
                    'source_type'  => 'internal',
                    'last_indexed' => current_time( 'mysql' ),
                ),
                array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
            );
        }
    }

    /**
     * AJAX: Upload External Documents (.txt, .md, .csv) & Save Manual Knowledge (scope = 'custom').
     */
    public function ajax_upload_knowledge() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fg_ai_knowledge';

        $manual_text = isset( $_POST['manual_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['manual_text'] ) ) : '';

        $wpdb->delete( $table, array( 'source_type' => 'manual_notes' ) );
        if ( ! empty( $manual_text ) ) {
            $wpdb->insert(
                $table,
                array(
                    'post_id'      => 0,
                    'title'        => 'Manual FAQ & Custom Policy Knowledge',
                    'content'      => $manual_text,
                    'type'         => 'manual',
                    'scope'        => 'custom',
                    'url'          => home_url( '/' ),
                    'source_type'  => 'manual_notes',
                    'last_indexed' => current_time( 'mysql' ),
                ),
                array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
            );
        }

        if ( isset( $_FILES['file'] ) && UPLOAD_ERR_OK === $_FILES['file']['error'] ) {
            $file_tmp  = $_FILES['file']['tmp_name'];
            $file_name = sanitize_file_name( $_FILES['file']['name'] );
            $file_size = filesize( $file_tmp );
            $ext       = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

            if ( ! in_array( $ext, array( 'txt', 'md', 'csv' ), true ) ) {
                wp_send_json_error( 'Invalid file format. Only .txt, .md, and .csv are allowed.' );
            }

            if ( $file_size > 5 * 1024 * 1024 ) {
                wp_send_json_error( 'File too large. Maximum allowed size is 5MB.' );
            }

            $raw_content = file_get_contents( $file_tmp );
            if ( 'csv' === $ext ) {
                $parsed_content = $this->parse_csv_to_semantic_text( $raw_content, $file_name );
            } else {
                $parsed_content = wp_strip_all_tags( $raw_content );
            }

            $custom_url = 'custom://file/' . $file_name;
            $wpdb->replace(
                $table,
                array(
                    'post_id'      => 0,
                    'title'        => 'Custom Document: ' . $file_name,
                    'content'      => $parsed_content,
                    'type'         => 'file_' . $ext,
                    'scope'        => 'custom',
                    'url'          => $custom_url,
                    'source_type'  => 'manual_file',
                    'last_indexed' => current_time( 'mysql' ),
                ),
                array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
            );

            wp_send_json_success( 'Manual knowledge and uploaded file (' . esc_html( $file_name ) . ') indexed into scope=custom!' );
        }

        wp_send_json_success( 'Manual knowledge saved and indexed into scope=custom!' );
    }

    /**
     * Parse CSV into clear header:value lines for high RAG accuracy.
     */
    private function parse_csv_to_semantic_text( $csv_string, $file_name ) {
        $lines   = preg_split( '/\r\n|\r|\n/', trim( $csv_string ) );
        $headers = array();
        $out     = array( 'Document: ' . $file_name );

        foreach ( $lines as $idx => $line ) {
            if ( empty( trim( $line ) ) ) {
                continue;
            }
            $delimiter = ( false !== strpos( $line, ';' ) && substr_count( $line, ';' ) > substr_count( $line, ',' ) ) ? ';' : ',';
            $cols      = str_getcsv( $line, $delimiter );
            if ( 0 === $idx ) {
                $headers = array_map( 'trim', $cols );
            } else {
                $row_parts = array();
                foreach ( $cols as $c_idx => $val ) {
                    $h           = isset( $headers[ $c_idx ] ) && '' !== $headers[ $c_idx ] ? $headers[ $c_idx ] : 'Col_' . ( $c_idx + 1 );
                    $row_parts[] = $h . ': ' . trim( $val );
                }
                $out[] = implode( ' | ', $row_parts );
            }
        }
        return wp_strip_all_tags( implode( "\n", $out ) );
    }

    /**
     * AJAX: Delete an uploaded custom document.
     */
    public function ajax_delete_custom_doc() {
        check_ajax_referer( 'advisio_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $doc_id = isset( $_POST['doc_id'] ) ? intval( $_POST['doc_id'] ) : 0;
        if ( $doc_id <= 0 ) {
            wp_send_json_error( 'Invalid ID' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fg_ai_knowledge';
        $wpdb->delete( $table, array( 'id' => $doc_id, 'scope' => 'custom' ), array( '%d', '%s' ) );

        wp_send_json_success( 'Deleted' );
    }

    /**
     * AJAX: Approve or Reject a 50-Chat AI Strategy Proposal.
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
     * AJAX: Manual or Automatic trigger for the 50-Chat AI Learning Engine.
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
            $transcript_sample = "Sample trends: Customers frequently ask about cheapest 2-glass/3-glass window options, how to measure wall openings (karmyttermått), U-values, claims/reklamation, and delivery times.";
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
                'temperature' => 0.2,
                'topP'        => 0.8,
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
     * Multi-Keyword Tokenized Hybrid RAG Search across `wp_fg_ai_knowledge`.
     * Formats every record with exact SOURCE TITLE, PAGE URL, and CONTENT for accurate subpage & form linking.
     */
    private function retrieve_relevant_knowledge( $user_message, $product_id = 0 ) {
        global $wpdb;
        $table_k = $wpdb->prefix . 'fg_ai_knowledge';

        $context = '';

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

        $manual_notes = $wpdb->get_var( "SELECT content FROM $table_k WHERE source_type = 'manual_notes' LIMIT 1" );
        if ( ! empty( $manual_notes ) ) {
            $context .= sprintf(
                "SOURCE TITLE: Custom Internal Knowledge & FAQs | PAGE URL: %s | CONTENT: %s\n\n",
                home_url( '/' ),
                $manual_notes
            );
        }

        $clean_msg = preg_replace( '/[^\p{L}\p{N}\s\-]/u', ' ', mb_strtolower( $user_message, 'UTF-8' ) );
        $raw_words = preg_split( '/\s+/', $clean_msg, -1, PREG_SPLIT_NO_EMPTY );
        $stopwords = array(
            'och', 'att', 'det', 'som', 'en', 'ett', 'på', 'är', 'för', 'med', 'till',
            'den', 'har', 'de', 'inte', 'om', 'jag', 'vill', 'kan', 'hur', 'vad', 'finns',
            'skulle', 'vilja', 'behöver', 'the', 'and', 'for', 'with', 'what', 'how',
        );

        $keywords = array();
        foreach ( $raw_words as $w ) {
            if ( mb_strlen( $w, 'UTF-8' ) >= 3 && ! in_array( $w, $stopwords, true ) ) {
                $keywords[] = $w;
            }
        }

        // Expand common subpage/form intent stems so subpages like Reklamation, Partnerskap, Villkor, Mätguide are always matched
        $synonym_stems = array(
            'reklam'   => array( 'reklamation', 'skada', 'trasig', 'fel', 'claim', 'complaint' ),
            'partner'  => array( 'partnerskap', 'samarbete', 'återförsäljare', 'företag', 'byggare' ),
            'villkor'  => array( 'köpvillkor', 'ångerrätt', 'garanti', 'retur', 'terms' ),
            'mät'      => array( 'mätguide', 'mäta', 'mått', 'karmyttermått', 'modulmått' ),
            'monter'   => array( 'montering', 'installera', 'karmskruv' ),
            'kontakt'  => array( 'kundtjänst', 'formulär', 'offert' ),
        );
        foreach ( $synonym_stems as $stem => $triggers ) {
            foreach ( $triggers as $trig ) {
                if ( false !== mb_strpos( $clean_msg, $trig, 0, 'UTF-8' ) ) {
                    $keywords[] = $stem;
                    break;
                }
            }
        }

        if ( empty( $keywords ) ) {
            $keywords = array( trim( $user_message ) );
        }

        $keywords    = array_slice( array_unique( $keywords ), 0, 6 );
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

            $score_parts[] = '(CASE WHEN title LIKE %s THEN 4 WHEN url LIKE %s THEN 3 WHEN content LIKE %s THEN 1 ELSE 0 END)';
            $score_args[]  = $like;
            $score_args[]  = $like;
            $score_args[]  = $like;
        }

        $where_sql = implode( ' OR ', $where_parts );
        $score_sql = implode( ' + ', $score_parts );
        $all_args  = array_merge( $score_args, $where_args );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT title, content, url, scope, ($score_sql) AS relevance_score
             FROM $table_k
             WHERE source_type != 'manual_notes' AND ($where_sql)
             ORDER BY relevance_score DESC, id ASC
             LIMIT 6",
            $all_args
        ) );

        if ( ! empty( $results ) ) {
            foreach ( $results as $r ) {
                $clean_url = ( 0 === strpos( $r->url, 'custom://' ) ) ? home_url( '/' ) : $r->url;
                $snippet   = mb_substr( $r->content, 0, 1200, 'UTF-8' );
                $context  .= sprintf(
                    "SOURCE TITLE: %s | PAGE URL: %s | CONTENT: %s\n\n",
                    $r->title,
                    $clean_url,
                    $snippet
                );
            }
        }

        $words = preg_split( '/\s+/', $context );
        if ( count( $words ) > 1200 ) {
            $context = implode( ' ', array_slice( $words, 0, 1200 ) );
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

        $context              = $this->retrieve_relevant_knowledge( $user_message, $product_id );
        $system_instruction   = get_option( 'advisio_system_instruction', 'You are Advisio AI, a helpful customer support specialist and consultative window and door advisor.' );
        $negative_constraints = get_option( 'advisio_negative_constraints', '' );
        $target_goals         = get_option( 'advisio_target_goals', 'Typ av fönster/dörr, Exakta mått bredd x höjd, Glastyp, Material' );
        $approved_strategies  = get_option( 'advisio_approved_strategies', '' );
        $lang                 = get_option( 'advisio_language', 'Swedish' );

        $chip_labels = self::get_frontend_chip_labels( $lang );

        $full_system_instruction =
            "=== SYSTEM INSTRUCTIONS & PERSONA ===\n" .
            $system_instruction . "\n\n" .
            "=== STRICT KNOWLEDGE GROUNDING & SUBPAGE / FORM URL REFERENCING ===\n" .
            "- All answers MUST be strictly based on the indexed website content, footer policy pages, and uploaded custom documents provided in the KNOWLEDGE BASE CONTEXT below.\n" .
            "- When a customer asks about topics that have a dedicated page or form on the website (such as Reklamation/Claims, Partnerskap/Partnership, Köpvillkor/Terms, Mätguide/Measurement guides, or specific products), you MUST first answer their question helpfully and then explicitly refer them to that page with a clickable Markdown link, e.g., [Läs mer och fyll i formuläret för reklamation här](URL). Never invent URLs—only use exact URLs provided in the knowledge base.\n" .
            "- Be tolerant of customer typos, misspellings, and colloquial phrasing.\n\n" .
            "=== BALANCED CORE PRIORITY (SUPPORT FIRST, CONSULTATIVE SELLING SECOND) ===\n" .
            "- Focus primarily on helpful customer support based on the customer's question.\n" .
            "- When guiding toward products, establish the right MAIN PRODUCT first (Windows or Doors, where size/dimensions Width x Height are naturally an important early factor).\n" .
            "- Treat Add-ons/Accessories (handles, vents/spaltventil, grilles/spröjs, frame screws) as secondary extras mentioned ONLY when relevant or requested.\n\n" .
            "=== CONVERSATIONAL PACING & GUIDING QUESTIONS ===\n" .
            "- Primary Response Language: " . $lang . ".\n" .
            "- ONE QUESTION AT A TIME: Respond helpfully and ask strictly ONE guiding question at a time to lead the conversation forward.\n" .
            "- ASSIST WHEN UNSURE: If the customer clicks or says '" . $chip_labels['not_sure_raw'] . "' or asks how to measure, explain clearly and helpfully using the indexed guides before moving to the next question.\n" .
            "- MULTI-REQUIREMENT SYNTHESIS: If a customer writes a detailed paragraph with their wishes, recommend the best matching main product right away (including its clickable Markdown link) and ask if they need anything else or specific add-ons.\n\n" .
            "=== TARGET INFORMATION GOALS ===\n" .
            $target_goals . "\n\n" .
            "=== STRICT NEGATIVE CONSTRAINTS (DONTs) ===\n" .
            $negative_constraints . "\n\n";

        if ( ! empty( trim( $approved_strategies ) ) ) {
            $full_system_instruction .=
                "### APPROVED AI LEARNING STRATEGIES ###\n" .
                $approved_strategies . "\n\n";
        }

        $full_system_instruction .=
            "=== CONTEXT-AWARE QUICK-REPLY CHIPS (MANDATORY FORMAT) ===\n" .
            "- At the end of EVERY response, you MUST include 2 to 4 contextually relevant quick-reply chips formatted EXACTLY like: [SUGGESTIONS: Option 1 | Option 2 | " . $chip_labels['not_sure_raw'] . "]\n" .
            "- MANDATORY 'NOT SURE' OPTION: Whenever you ask a guiding question, ALWAYS include '" . $chip_labels['not_sure_raw'] . "' as the last suggestion chip.\n" .
            "- WHEN ASKING FOR MEASUREMENTS: If you ask the customer for exact dimensions (Width x Height), NEVER output fake number chips or 'Fill in measurements'. Output ONLY these support chips: [SUGGESTIONS: " . $chip_labels['measure_help'] . " | " . $chip_labels['not_sure'] . "]\n\n" .
            "=== KNOWLEDGE BASE CONTEXT ===\n" .
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
                'temperature' => 0.2,
                'topP'        => 0.8,
            ),
        );

        $res = $this->call_gemini_api( $api_key, $payload, 12 );
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

        // Automatic 50-session learning check
        $total_sessions    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT session_id) FROM $table_logs WHERE is_lead = 0" );
        $last_analyzed_cnt = (int) get_option( 'advisio_last_analyzed_session_count', 0 );
        if ( ( $total_sessions - $last_analyzed_cnt ) >= 50 ) {
            $this->generate_ai_strategy_proposals( false );
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
                'temperature' => 0.2,
                'topP'        => 0.8,
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
}

new Advisio_AI();
