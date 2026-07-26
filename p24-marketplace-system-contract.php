<?php
/**
 * Plugin Name: Шахты24 Маркет — System Contract & Category Semantics
 * Plugin URI:  https://маркет.шахты24.рф/
 * Description: Системный контракт Маркета: реестр сущностей, маршрутов, ролей, действий, схем рубрик и безопасная смена семантики категорий. Полевой мост для P24 Marketplace OS 1.0.0-alpha.3.5.1.
 * Version:     1.0.0-alpha.3.5.2
 * Author:      Верещагин и Партнёры / NOVA
 * Text Domain: p24-marketplace-system-contract
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('P24MSC_VERSION', '1.0.0-alpha.3.5.2');
define('P24MSC_SCHEMA_VERSION', '1.0');
define('P24MSC_FILE', __FILE__);
define('P24MSC_DIR', plugin_dir_path(__FILE__));
define('P24MSC_URL', plugin_dir_url(__FILE__));

final class P24MSC_System_Contract {
    private static $instance = null;
    private $listing_post_type = null;
    private $primary_taxonomy = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'boot'), 30);
    }

    public function boot() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_menu', array($this, 'register_admin_page'), 99);
        add_action('admin_notices', array($this, 'dependency_notice'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_front_assets'));
        add_filter('body_class', array($this, 'front_body_classes'));
        add_filter('admin_body_class', array($this, 'admin_body_classes'));
        add_action('wp_head', array($this, 'print_route_marker'), 2);
        add_action('admin_head', array($this, 'print_route_marker'), 2);
        add_filter('the_content', array($this, 'clean_public_description'), 25);
        add_action('save_post', array($this, 'capture_listing_semantics'), 40, 3);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));

        add_filter('p24m_system_registry', array($this, 'filter_system_registry'));
        add_filter('p24m_entity_registry', array($this, 'filter_entity_registry'));
        add_filter('p24m_route_registry', array($this, 'filter_route_registry'));
        add_filter('p24m_action_registry', array($this, 'filter_action_registry'));
        add_filter('p24m_category_schemas', array($this, 'filter_category_schemas'));
        add_filter('p24m_category_schema', array($this, 'filter_category_schema'), 10, 2);
    }

    public function dependency_notice() {
        if (!current_user_can('activate_plugins') || $this->is_marketplace_core_available()) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>Шахты24 Маркет — System Contract:</strong> основной P24 Marketplace OS не обнаружен. Мост остаётся безопасно активным, но реестр объявлений и схемы рубрик будут неполными.</p></div>';
    }

    private function is_marketplace_core_available() {
        return defined('P24M_VERSION') || post_type_exists('p24_listing') || class_exists('P24M_Listing_Contract');
    }

    public function plugin_action_links($links) {
        $url = admin_url('admin.php?page=p24-marketplace-system-contract');
        array_unshift($links, '<a href="' . esc_url($url) . '">Карта системы</a>');
        return $links;
    }

    public function enqueue_admin_assets($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $post_type = $screen && !empty($screen->post_type) ? (string) $screen->post_type : '';
        $is_contract = isset($_GET['page']) && 'p24-marketplace-system-contract' === sanitize_key(wp_unslash($_GET['page']));
        $is_listing = $post_type && $post_type === $this->listing_post_type();
        if (!$is_contract && !$is_listing) {
            return;
        }
        wp_enqueue_style('p24msc-system-contract', P24MSC_URL . 'assets/system-contract.css', array(), P24MSC_VERSION);
        wp_enqueue_script('p24msc-category-semantics', P24MSC_URL . 'assets/category-semantics.js', array(), P24MSC_VERSION, true);
        wp_localize_script('p24msc-category-semantics', 'P24MSC', $this->script_config());
    }

    public function enqueue_front_assets() {
        $is_cabinet = false;
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if (false !== strpos($path, '/kabinet') || is_singular($this->listing_post_type())) {
            $is_cabinet = true;
        }
        if (!$is_cabinet) {
            return;
        }
        wp_enqueue_style('p24msc-system-contract', P24MSC_URL . 'assets/system-contract.css', array(), P24MSC_VERSION);
        wp_enqueue_script('p24msc-category-semantics', P24MSC_URL . 'assets/category-semantics.js', array(), P24MSC_VERSION, true);
        wp_localize_script('p24msc-category-semantics', 'P24MSC', $this->script_config());
    }

    private function script_config() {
        return array(
            'version' => P24MSC_VERSION,
            'schemas' => $this->category_schemas(),
            'messages' => array(
                'reclassifyTitle' => 'Вы меняете рубрику объявления',
                'reclassifyBody' => 'Общие данные, фотографии, цена и контакты сохранятся. Несовместимые характеристики прежней рубрики будут очищены после сохранения.',
                'confirm' => 'Сменить рубрику',
                'cancel' => 'Оставить прежнюю',
                'conditionReset' => 'Состояние сброшено, потому что у новой рубрики другой смысл этого поля.',
            ),
        );
    }

    public function front_body_classes($classes) {
        $route = $this->current_route_key();
        if ($route) {
            $classes[] = 'p24m-contract-route-' . sanitize_html_class(str_replace('.', '-', $route));
        }
        return $classes;
    }

    public function admin_body_classes($classes) {
        $route = $this->current_route_key();
        if ($route) {
            $classes .= ' p24m-contract-route-' . sanitize_html_class(str_replace('.', '-', $route));
        }
        return trim($classes);
    }

    public function print_route_marker() {
        $route = $this->current_route_key();
        if (!$route) {
            return;
        }
        echo '<meta name="p24m-route-key" content="' . esc_attr($route) . '">' . "\n";
        echo '<meta name="p24m-system-contract" content="' . esc_attr(P24MSC_SCHEMA_VERSION) . '">' . "\n";
    }

    private function current_route_key() {
        if (is_admin()) {
            $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
            $map = array(
                'p24-marketplace-manager' => 'manager.dashboard',
                'p24-marketplace-moderation' => 'manager.moderation',
                'p24-marketplace-inbox' => 'manager.inbox',
                'p24-marketplace-developer' => 'developer.dashboard',
                'p24-marketplace-listing-integrity' => 'developer.listing_integrity',
                'p24-marketplace-system-contract' => 'developer.system_map',
            );
            if (isset($map[$page])) {
                return $map[$page];
            }
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && $screen->post_type === $this->listing_post_type()) {
                return 'listing.wp_admin';
            }
            return '';
        }

        if (is_singular($this->listing_post_type())) {
            return 'listing.view';
        }
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if (false !== strpos($path, '/kabinet')) {
            $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
            $map = array(
                'listings' => 'cabinet.listings',
                'edit' => 'listing.edit',
                'messages' => 'cabinet.messages',
                'promotion' => 'cabinet.promotion',
                'statistics' => 'cabinet.statistics',
            );
            return isset($map[$tab]) ? $map[$tab] : 'cabinet.dashboard';
        }
        if (is_front_page() || is_home()) {
            return 'market.home';
        }
        return '';
    }

    public function register_rest_routes() {
        register_rest_route('p24-market/v1', '/system-map', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'rest_public_system_map'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('p24-market/v1', '/system-map/private', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'rest_private_system_map'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));
        register_rest_route('p24-market/v1', '/category-schema/(?P<term_id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'rest_category_schema'),
            'permission_callback' => '__return_true',
            'args' => array(
                'term_id' => array('sanitize_callback' => 'absint'),
            ),
        ));
    }

    public function rest_public_system_map(WP_REST_Request $request) {
        $map = $this->system_map(false);
        return rest_ensure_response($map);
    }

    public function rest_private_system_map(WP_REST_Request $request) {
        $map = $this->system_map(true);
        return rest_ensure_response($map);
    }

    public function rest_category_schema(WP_REST_Request $request) {
        $term_id = absint($request['term_id']);
        $term = $this->find_term_by_id($term_id);
        if (!$term) {
            return new WP_Error('p24msc_term_not_found', 'Рубрика не найдена.', array('status' => 404));
        }
        $family = $this->detect_category_family($term);
        return rest_ensure_response(array(
            'term' => array('id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug),
            'family' => $family,
            'schema' => $this->category_schema($family),
        ));
    }

    private function system_map($private) {
        $map = array(
            'contract' => array(
                'name' => 'P24 Marketplace System Contract',
                'version' => P24MSC_VERSION,
                'schema_version' => P24MSC_SCHEMA_VERSION,
                'generated_at' => current_time('c'),
            ),
            'product' => array(
                'name' => get_bloginfo('name'),
                'url' => home_url('/'),
                'wordpress' => get_bloginfo('version'),
                'marketplace_core_version' => defined('P24M_VERSION') ? P24M_VERSION : null,
                'listing_post_type' => $this->listing_post_type(),
                'primary_category_taxonomy' => $this->primary_category_taxonomy(),
            ),
            'entities' => $this->entity_registry($private),
            'routes' => array_values($this->route_registry()),
            'actions' => array_values($this->action_registry()),
            'category_schemas' => $this->category_schemas(),
            'test_objects' => $this->test_objects(),
            'roles' => $this->role_registry(),
            'health' => $this->health_summary(),
        );
        return apply_filters('p24msc_system_map', $map, $private);
    }

    public function filter_system_registry($registry) {
        return array_merge((array) $registry, $this->system_map(false));
    }

    public function filter_entity_registry($registry) {
        return array_merge((array) $registry, $this->entity_registry(false));
    }

    public function filter_route_registry($registry) {
        return array_merge((array) $registry, $this->route_registry());
    }

    public function filter_action_registry($registry) {
        return array_merge((array) $registry, $this->action_registry());
    }

    public function filter_category_schemas($schemas) {
        return array_merge((array) $schemas, $this->category_schemas());
    }

    public function filter_category_schema($schema, $context) {
        $family = is_string($context) ? sanitize_key($context) : '';
        if (is_object($context) && isset($context->term_id)) {
            $family = $this->detect_category_family($context);
        }
        if (!$family) {
            return $schema;
        }
        return array_merge((array) $schema, $this->category_schema($family));
    }

    private function entity_registry($private) {
        $post_type = $this->listing_post_type();
        $taxonomy = $this->primary_category_taxonomy();
        $entities = array(
            'listing' => array(
                'label' => 'Объявление',
                'storage' => $private ? array('post_type' => $post_type, 'taxonomy' => $taxonomy) : array('type' => 'wordpress_post'),
                'owner' => 'seller',
                'states' => array('draft', 'moderation', 'published', 'withdrawn', 'archived'),
                'relations' => array('seller', 'category', 'media', 'dialog', 'promotion', 'publication', 'price_event'),
            ),
            'seller' => array(
                'label' => 'Продавец',
                'storage' => array('type' => 'wordpress_user'),
                'owner' => 'user',
                'relations' => array('listing', 'dialog', 'publication', 'promotion'),
            ),
            'category' => array(
                'label' => 'Рубрика',
                'storage' => $private ? array('taxonomy' => $taxonomy) : array('type' => 'wordpress_term'),
                'owner' => 'system',
                'relations' => array('listing', 'category_schema'),
            ),
            'dialog' => array(
                'label' => 'Диалог',
                'storage' => array('type' => 'detected_runtime'),
                'owner' => 'buyer_and_seller',
                'states' => array('new', 'contacted', 'negotiation', 'meeting', 'sold', 'no_result', 'spam'),
            ),
            'promotion' => array(
                'label' => 'Продвижение',
                'storage' => array('type' => 'detected_runtime'),
                'owner' => 'seller',
                'states' => array('draft', 'pending_payment', 'active', 'completed', 'cancelled'),
            ),
            'publication' => array(
                'label' => 'Публикация',
                'storage' => array('type' => 'detected_runtime'),
                'owner' => 'seller_or_manager',
                'states' => array('draft', 'prepared', 'published', 'failed', 'expired'),
            ),
            'price_event' => array(
                'label' => 'Событие цены',
                'storage' => array('type' => 'future_event_log'),
                'owner' => 'listing',
                'states' => array('created', 'decreased', 'increased', 'corrected'),
            ),
        );
        return apply_filters('p24msc_entity_registry', $entities, $private);
    }

    private function action_registry() {
        $actions = array(
            'listing.create' => array('label' => 'Подать объявление', 'entity' => 'listing', 'role' => 'seller', 'capability' => 'read', 'route' => 'listing.create'),
            'listing.edit' => array('label' => 'Редактировать объявление', 'entity' => 'listing', 'role' => 'seller', 'capability' => 'edit_post', 'route' => 'listing.edit'),
            'listing.preview' => array('label' => 'Посмотреть глазами покупателя', 'entity' => 'listing', 'role' => 'seller', 'capability' => 'read', 'route' => 'listing.view'),
            'listing.submit_moderation' => array('label' => 'Отправить на проверку', 'entity' => 'listing', 'role' => 'seller', 'capability' => 'edit_post', 'route' => 'cabinet.listings'),
            'listing.approve' => array('label' => 'Одобрить', 'entity' => 'listing', 'role' => 'manager', 'capability' => 'edit_others_posts', 'route' => 'manager.moderation'),
            'listing.return_revision' => array('label' => 'Вернуть на доработку', 'entity' => 'listing', 'role' => 'manager', 'capability' => 'edit_others_posts', 'route' => 'manager.moderation'),
            'dialog.open' => array('label' => 'Открыть диалог', 'entity' => 'dialog', 'role' => 'seller', 'capability' => 'read', 'route' => 'cabinet.messages'),
            'promotion.open' => array('label' => 'Продвижение и статистика', 'entity' => 'promotion', 'role' => 'seller', 'capability' => 'read', 'route' => 'cabinet.promotion'),
            'system.inspect' => array('label' => 'Открыть карту системы', 'entity' => 'system', 'role' => 'developer', 'capability' => 'manage_options', 'route' => 'developer.system_map'),
        );
        return apply_filters('p24msc_action_registry', $actions);
    }

    private function route_registry() {
        $sample = $this->sample_listing('publish');
        $sample_id = $sample ? (int) $sample->ID : 0;
        $term = $this->sample_category();
        $routes = array(
            'market.home' => $this->route('market.home', 'Главная витрина', home_url('/'), 'guest', 'read', 'market', 'body'),
            'market.catalog' => $this->route('market.catalog', 'Каталог и поиск', home_url('/'), 'guest', 'read', 'catalog', '[data-p24m-market], .p24m-v1-app'),
            'category.view' => $this->route('category.view', 'Страница рубрики', $term ? get_term_link($term) : home_url('/'), 'guest', 'read', 'category', '[data-p24m-page="category"], .p24m-v1-listing-grid'),
            'listing.view' => $this->route('listing.view', 'Карточка объявления', $sample ? get_permalink($sample) : home_url('/'), 'guest', 'read', 'listing', '[data-p24m-page="listing"], .p24m-v1-listing'),
            'listing.create' => $this->route('listing.create', 'Подача объявления', $this->cabinet_url('add'), 'seller', 'read', 'listing_editor', 'form, [data-p24m-listing-form]'),
            'cabinet.listings' => $this->route('cabinet.listings', 'Мои объявления', $this->cabinet_url('listings'), 'seller', 'read', 'cabinet', '[data-p24m-cabinet], .p24m-cabinet'),
            'listing.edit' => $this->route('listing.edit', 'Редактор объявления', $this->cabinet_url('edit', $sample_id), 'seller', 'edit_post', 'listing_editor', 'form, [data-p24m-listing-form]'),
            'cabinet.messages' => $this->route('cabinet.messages', 'Сообщения', $this->cabinet_url('messages'), 'seller', 'read', 'dialog', '[data-p24m-messages], .p24m-messages'),
            'cabinet.promotion' => $this->route('cabinet.promotion', 'Продвижение и статистика', $this->cabinet_url('promotion'), 'seller', 'read', 'promotion', '[data-p24m-promotion], .p24m-promotion'),
            'manager.dashboard' => $this->route('manager.dashboard', 'Рабочий стол менеджера', admin_url('admin.php?page=p24-marketplace-manager'), 'manager', 'edit_others_posts', 'manager', '.p24m-manager, [data-p24m-manager]'),
            'manager.moderation' => $this->route('manager.moderation', 'Модерация', admin_url('admin.php?page=p24-marketplace-moderation'), 'manager', 'edit_others_posts', 'moderation', '.p24m-moderation, [data-p24m-moderation]'),
            'manager.inbox' => $this->route('manager.inbox', 'Обращения менеджера', admin_url('admin.php?page=p24-marketplace-inbox'), 'manager', 'edit_others_posts', 'dialog', '.p24m-inbox, [data-p24m-inbox]'),
            'developer.dashboard' => $this->route('developer.dashboard', 'Контур разработчика', admin_url('admin.php?page=p24-marketplace-developer'), 'developer', 'manage_options', 'developer', '.p24m-developer, [data-p24m-developer]'),
            'developer.listing_integrity' => $this->route('developer.listing_integrity', 'Архитектура листинга', admin_url('admin.php?page=p24-marketplace-listing-integrity'), 'developer', 'manage_options', 'diagnostic', '.p24m-listing-integrity'),
            'developer.system_map' => $this->route('developer.system_map', 'Карта системы', admin_url('admin.php?page=p24-marketplace-system-contract'), 'developer', 'manage_options', 'diagnostic', '.p24msc-wrap'),
        );
        return apply_filters('p24msc_route_registry', $routes);
    }

    private function route($key, $label, $url, $role, $capability, $entity, $marker) {
        if (is_wp_error($url)) {
            $url = '';
        }
        return array(
            'key' => $key,
            'label' => $label,
            'url' => (string) $url,
            'expected_role' => $role,
            'capability' => $capability,
            'entity' => $entity,
            'control_marker' => $marker,
            'source' => 'p24_system_contract',
        );
    }

    private function cabinet_url($tab, $listing_id = 0) {
        $args = array('tab' => sanitize_key($tab));
        if ($listing_id) {
            $args['listing_id'] = absint($listing_id);
        }
        return add_query_arg($args, home_url('/kabinet/'));
    }

    private function role_registry() {
        return array(
            'guest' => array('label' => 'Покупатель', 'capabilities' => array('read_public')),
            'seller' => array('label' => 'Продавец', 'capabilities' => array('read', 'edit_own_listing')),
            'manager' => array('label' => 'Менеджер', 'capabilities' => array('read', 'edit_others_posts', 'moderate_listing')),
            'developer' => array('label' => 'Разработчик', 'capabilities' => array('manage_options', 'inspect_system')),
        );
    }

    private function category_schemas() {
        $schemas = array(
            'automobile' => array(
                'label' => 'Автомобиль',
                'condition_label' => 'Состояние автомобиля',
                'condition_options' => array(
                    'new' => 'Новое',
                    'excellent' => 'Отличное',
                    'good' => 'Хорошее',
                    'fair' => 'Удовлетворительное',
                    'repair' => 'Требует ремонта',
                    'not_running' => 'Не на ходу',
                ),
                'required' => array('make', 'model', 'year'),
                'fields' => array('make', 'model', 'year', 'mileage', 'body', 'gearbox', 'fuel', 'engine', 'drive', 'steering'),
            ),
            'clothing' => array(
                'label' => 'Одежда и личные вещи',
                'condition_label' => 'Состояние вещи',
                'condition_options' => array(
                    'new' => 'Новое с биркой',
                    'excellent' => 'Новое без бирки',
                    'good' => 'Отличное',
                    'fair' => 'Хорошее',
                    'used' => 'Удовлетворительное',
                    'repair' => 'Есть дефекты',
                ),
                'required' => array('item_type', 'size'),
                'fields' => array('item_group', 'item_type', 'audience', 'brand', 'size', 'season', 'color', 'material', 'purchase_place', 'defect_note'),
            ),
            'real_estate' => array(
                'label' => 'Недвижимость',
                'condition_label' => 'Состояние объекта',
                'condition_options' => array(
                    'new' => 'Новостройка',
                    'excellent' => 'После ремонта',
                    'good' => 'Хорошее',
                    'fair' => 'Требует косметического ремонта',
                    'repair' => 'Требует ремонта',
                ),
                'required' => array('property_type', 'deal_type', 'area'),
                'fields' => array('property_type', 'deal_type', 'rooms', 'area', 'floor', 'floors_total', 'building_type', 'repair_type'),
            ),
            'service' => array(
                'label' => 'Услуга',
                'condition_label' => '',
                'condition_options' => array(),
                'required' => array('service_type'),
                'fields' => array('service_type', 'service_area', 'schedule', 'experience', 'price_unit'),
            ),
            'generic' => array(
                'label' => 'Товар',
                'condition_label' => 'Состояние товара',
                'condition_options' => array(
                    'new' => 'Новое',
                    'excellent' => 'Как новое',
                    'good' => 'Хорошее',
                    'fair' => 'Удовлетворительное',
                    'repair' => 'Есть дефекты',
                ),
                'required' => array(),
                'fields' => array(),
            ),
        );
        return apply_filters('p24msc_category_schemas', $schemas);
    }

    private function category_schema($family) {
        $schemas = $this->category_schemas();
        return isset($schemas[$family]) ? $schemas[$family] : $schemas['generic'];
    }

    private function detect_category_family($term) {
        if (!$term || is_wp_error($term)) {
            return 'generic';
        }
        $parts = array($term->name, $term->slug);
        $taxonomy = $term->taxonomy;
        $parent = (int) $term->parent;
        $guard = 0;
        while ($parent && $guard < 8) {
            $ancestor = get_term($parent, $taxonomy);
            if (!$ancestor || is_wp_error($ancestor)) {
                break;
            }
            $parts[] = $ancestor->name;
            $parts[] = $ancestor->slug;
            $parent = (int) $ancestor->parent;
            $guard++;
        }
        $haystack = function_exists('mb_strtolower') ? mb_strtolower(implode(' ', $parts), 'UTF-8') : strtolower(implode(' ', $parts));
        $families = array(
            'automobile' => array('автомоб', 'машин', 'транспорт', 'грузовик', 'мото', 'auto', 'car'),
            'clothing' => array('личные вещи', 'одежд', 'обув', 'джинс', 'брюк', 'куртк', 'рубаш', 'плать', 'сумк', 'аксессуар', 'clothing'),
            'real_estate' => array('недвиж', 'квартир', 'дом', 'участ', 'комнат', 'гараж', 'property', 'real-estate'),
            'service' => array('услуг', 'работ', 'мастер', 'ремонт услуг', 'ваканс', 'service'),
        );
        foreach ($families as $family => $needles) {
            foreach ($needles as $needle) {
                if (false !== strpos($haystack, $needle)) {
                    return $family;
                }
            }
        }
        return 'generic';
    }

    public function capture_listing_semantics($post_id, $post, $update) {
        if (!$post || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if ($post->post_type !== $this->listing_post_type()) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $term = $this->listing_primary_term($post_id);
        $family = $term ? $this->detect_category_family($term) : 'generic';
        $old_family = (string) get_post_meta($post_id, '_p24msc_category_family', true);
        update_post_meta($post_id, '_p24msc_category_family', $family);
        update_post_meta($post_id, '_p24msc_schema_version', P24MSC_SCHEMA_VERSION);

        $confirmed = isset($_POST['p24msc_confirm_reclassify']) && '1' === sanitize_text_field(wp_unslash($_POST['p24msc_confirm_reclassify']));
        if ($update && $old_family && $old_family !== $family) {
            update_post_meta($post_id, '_p24msc_reclassified_at', current_time('mysql'));
            update_post_meta($post_id, '_p24msc_previous_family', $old_family);
            if ($confirmed) {
                $this->clear_incompatible_meta($post_id, $old_family, $family);
            }
        }
    }

    private function clear_incompatible_meta($post_id, $old_family, $new_family) {
        $groups = array(
            'automobile' => array('make', 'model', 'year', 'mileage', 'body', 'gearbox', 'fuel', 'engine', 'drive', 'steering'),
            'clothing' => array('item_group', 'item_type', 'audience', 'brand', 'size', 'season', 'color', 'material', 'purchase_place', 'defect_note'),
            'real_estate' => array('property_type', 'deal_type', 'rooms', 'area', 'floor', 'floors_total', 'building_type', 'repair_type'),
            'service' => array('service_type', 'service_area', 'schedule', 'experience', 'price_unit'),
        );
        if (!isset($groups[$old_family]) || $old_family === $new_family) {
            return;
        }
        foreach ($groups[$old_family] as $field) {
            foreach ($this->meta_key_variants($field) as $meta_key) {
                delete_post_meta($post_id, $meta_key);
            }
        }
        foreach ($this->condition_meta_keys() as $meta_key) {
            delete_post_meta($post_id, $meta_key);
        }
        do_action('p24msc_listing_reclassified', $post_id, $old_family, $new_family);
    }

    private function meta_key_variants($field) {
        return array_unique(array(
            '_p24m_' . $field,
            'p24m_' . $field,
            '_p24_' . $field,
            'p24_' . $field,
            '_' . $field,
            $field,
        ));
    }

    private function condition_meta_keys() {
        return array('_p24m_condition', 'p24m_condition', '_p24m_item_condition', 'p24m_item_condition', '_p24_condition', 'condition', 'item_condition');
    }

    public function clean_public_description($content) {
        if (is_admin() || !is_singular($this->listing_post_type()) || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        $post_id = get_the_ID();
        $family = (string) get_post_meta($post_id, '_p24msc_category_family', true);
        if (!$family) {
            $term = $this->listing_primary_term($post_id);
            $family = $term ? $this->detect_category_family($term) : 'generic';
        }
        $content = preg_replace('/(?:^|[\s•·|])Главное\s*(?:[:•·|—-]+\s*)?/iu', ' ', $content);
        if ('clothing' === $family) {
            $content = preg_replace('/Состояние\s*:\s*Требует ремонта/iu', 'Состояние: Есть дефекты', $content);
        }
        $content = preg_replace('/[ \t]{2,}/u', ' ', $content);
        return trim($content);
    }

    private function health_summary() {
        $post_type = $this->listing_post_type();
        $counts = wp_count_posts($post_type);
        return array(
            'marketplace_core_detected' => $this->is_marketplace_core_available(),
            'listing_post_type_exists' => post_type_exists($post_type),
            'category_taxonomy_exists' => taxonomy_exists($this->primary_category_taxonomy()),
            'published_listings' => isset($counts->publish) ? (int) $counts->publish : 0,
            'contract_endpoint' => rest_url('p24-market/v1/system-map'),
        );
    }

    private function test_objects() {
        $objects = array();
        $statuses = array('publish', 'pending', 'draft');
        foreach ($statuses as $status) {
            $post = $this->sample_listing($status);
            if ($post) {
                $objects['listing_' . $status] = array(
                    'id' => (int) $post->ID,
                    'title' => get_the_title($post),
                    'status' => $status,
                    'url' => 'publish' === $status ? get_permalink($post) : '',
                    'edit_url' => $this->cabinet_url('edit', $post->ID),
                );
            }
        }
        $term = $this->sample_category();
        if ($term) {
            $objects['category'] = array(
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'url' => get_term_link($term),
                'family' => $this->detect_category_family($term),
            );
        }
        return $objects;
    }

    private function sample_listing($status) {
        $query = get_posts(array(
            'post_type' => $this->listing_post_type(),
            'post_status' => $status,
            'posts_per_page' => 1,
            'orderby' => 'modified',
            'order' => 'DESC',
            'suppress_filters' => false,
        ));
        return $query ? $query[0] : null;
    }

    private function sample_category() {
        $taxonomy = $this->primary_category_taxonomy();
        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            return null;
        }
        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => true,
            'number' => 1,
            'orderby' => 'count',
            'order' => 'DESC',
        ));
        return (!is_wp_error($terms) && $terms) ? $terms[0] : null;
    }

    private function listing_primary_term($post_id) {
        $taxonomy = $this->primary_category_taxonomy();
        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            return null;
        }
        $terms = wp_get_post_terms($post_id, $taxonomy);
        return (!is_wp_error($terms) && $terms) ? $terms[0] : null;
    }

    private function find_term_by_id($term_id) {
        foreach ($this->listing_taxonomies() as $taxonomy) {
            $term = get_term($term_id, $taxonomy);
            if ($term && !is_wp_error($term)) {
                return $term;
            }
        }
        return null;
    }

    private function listing_post_type() {
        if (null !== $this->listing_post_type) {
            return $this->listing_post_type;
        }
        $preferred = array('p24_listing', 'p24m_listing', 'listing');
        foreach ($preferred as $post_type) {
            if (post_type_exists($post_type)) {
                $this->listing_post_type = $post_type;
                return $post_type;
            }
        }
        $objects = get_post_types(array('_builtin' => false), 'objects');
        foreach ($objects as $post_type => $object) {
            $haystack = strtolower($post_type . ' ' . $object->label . ' ' . $object->labels->singular_name);
            if (false !== strpos($haystack, 'listing') || false !== strpos($haystack, 'объяв')) {
                $this->listing_post_type = $post_type;
                return $post_type;
            }
        }
        $this->listing_post_type = 'p24_listing';
        return $this->listing_post_type;
    }

    private function listing_taxonomies() {
        $taxonomies = get_object_taxonomies($this->listing_post_type(), 'objects');
        return is_array($taxonomies) ? $taxonomies : array();
    }

    private function primary_category_taxonomy() {
        if (null !== $this->primary_taxonomy) {
            return $this->primary_taxonomy;
        }
        $taxonomies = $this->listing_taxonomies();
        $preferred = array('p24_listing_category', 'p24m_listing_category', 'p24_category', 'listing_category');
        foreach ($preferred as $taxonomy) {
            if (isset($taxonomies[$taxonomy])) {
                $this->primary_taxonomy = $taxonomy;
                return $taxonomy;
            }
        }
        foreach ($taxonomies as $taxonomy => $object) {
            $haystack = strtolower($taxonomy . ' ' . $object->label . ' ' . $object->labels->singular_name);
            if ($object->hierarchical && (false !== strpos($haystack, 'categor') || false !== strpos($haystack, 'рубр') || false !== strpos($haystack, 'катег'))) {
                $this->primary_taxonomy = $taxonomy;
                return $taxonomy;
            }
        }
        foreach ($taxonomies as $taxonomy => $object) {
            if ($object->hierarchical) {
                $this->primary_taxonomy = $taxonomy;
                return $taxonomy;
            }
        }
        $this->primary_taxonomy = '';
        return '';
    }

    public function register_admin_page() {
        $parent = $this->detect_marketplace_parent_menu();
        if ($parent) {
            add_submenu_page(
                $parent,
                'Карта системы Маркета',
                'Карта системы',
                'manage_options',
                'p24-marketplace-system-contract',
                array($this, 'render_admin_page')
            );
        } else {
            add_management_page(
                'Карта системы Маркета',
                'P24 Карта системы',
                'manage_options',
                'p24-marketplace-system-contract',
                array($this, 'render_admin_page')
            );
        }
    }

    private function detect_marketplace_parent_menu() {
        global $menu;
        $preferred = array('p24-marketplace', 'p24-marketplace-manager', 'p24-marketplace-workspace');
        if (is_array($menu)) {
            foreach ($preferred as $slug) {
                foreach ($menu as $item) {
                    if (isset($item[2]) && $item[2] === $slug) {
                        return $slug;
                    }
                }
            }
            foreach ($menu as $item) {
                if (!isset($item[0], $item[2])) {
                    continue;
                }
                $label = wp_strip_all_tags((string) $item[0]);
                if (false !== stripos($label, 'Шахты24 Маркет') || false !== stripos((string) $item[2], 'p24-marketplace')) {
                    return (string) $item[2];
                }
            }
        }
        return '';
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав.');
        }
        $map = $this->system_map(true);
        $routes = $map['routes'];
        $schemas = $map['category_schemas'];
        $test_objects = $map['test_objects'];
        ?>
        <div class="wrap p24msc-wrap">
            <header class="p24msc-hero">
                <div>
                    <span class="p24msc-kicker">P24 MARKETPLACE OS · SYSTEM CONTRACT <?php echo esc_html(P24MSC_SCHEMA_VERSION); ?></span>
                    <h1>Карта системы Маркета</h1>
                    <p>Один машинно-читаемый источник для кода, менеджера, аудитора и следующего патча.</p>
                </div>
                <div class="p24msc-version"><?php echo esc_html(P24MSC_VERSION); ?></div>
            </header>

            <section class="p24msc-stats">
                <article><strong><?php echo esc_html($map['product']['marketplace_core_version'] ?: 'не определено'); ?></strong><span>ядро Маркета</span></article>
                <article><strong><?php echo esc_html(count($routes)); ?></strong><span>маршрутов</span></article>
                <article><strong><?php echo esc_html(count($map['entities'])); ?></strong><span>сущностей</span></article>
                <article><strong><?php echo esc_html(count($schemas)); ?></strong><span>семантических схем</span></article>
            </section>

            <section class="p24msc-panel">
                <div class="p24msc-panel__head"><div><span class="p24msc-kicker">МАШИННЫЙ ПАСПОРТ</span><h2>Точка подключения для NOVA</h2></div><a class="button button-primary" href="<?php echo esc_url(rest_url('p24-market/v1/system-map')); ?>" target="_blank" rel="noopener">Открыть JSON ↗</a></div>
                <code class="p24msc-endpoint"><?php echo esc_html(rest_url('p24-market/v1/system-map')); ?></code>
                <p>Аудитору больше не требуется вручную угадывать URL карточки, рубрики, редактора или рабочего стола.</p>
            </section>

            <section class="p24msc-panel">
                <div class="p24msc-panel__head"><div><span class="p24msc-kicker">РЕЕСТР МАРШРУТОВ</span><h2>Кто, куда и зачем входит</h2></div></div>
                <div class="p24msc-table-wrap"><table class="widefat striped p24msc-table"><thead><tr><th>Маршрут</th><th>Назначение</th><th>Роль</th><th>Контроль</th><th>Переход</th></tr></thead><tbody>
                <?php foreach ($routes as $route) : ?>
                    <tr>
                        <td><code><?php echo esc_html($route['key']); ?></code></td>
                        <td><?php echo esc_html($route['label']); ?></td>
                        <td><span class="p24msc-chip"><?php echo esc_html($route['expected_role']); ?></span></td>
                        <td><code><?php echo esc_html($route['control_marker']); ?></code></td>
                        <td><?php if (!empty($route['url'])) : ?><a href="<?php echo esc_url($route['url']); ?>" target="_blank" rel="noopener">Открыть ↗</a><?php else : ?><span class="p24msc-muted">не найден</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </section>

            <div class="p24msc-grid">
                <section class="p24msc-panel">
                    <div class="p24msc-panel__head"><div><span class="p24msc-kicker">ТЕСТОВЫЕ ОБЪЕКТЫ</span><h2>Выбраны автоматически</h2></div></div>
                    <?php if ($test_objects) : ?>
                        <div class="p24msc-list">
                            <?php foreach ($test_objects as $key => $object) : ?>
                                <article><div><strong><?php echo esc_html($key); ?></strong><span><?php echo esc_html(isset($object['title']) ? $object['title'] : (isset($object['name']) ? $object['name'] : '')); ?></span></div><code>#<?php echo esc_html(isset($object['id']) ? $object['id'] : ''); ?></code></article>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?><p>Подходящие объекты пока не найдены.</p><?php endif; ?>
                </section>

                <section class="p24msc-panel">
                    <div class="p24msc-panel__head"><div><span class="p24msc-kicker">СЕМАНТИКА РУБРИК</span><h2>Состояние имеет разный смысл</h2></div></div>
                    <div class="p24msc-schema-list">
                        <?php foreach ($schemas as $key => $schema) : ?>
                            <details <?php echo 'clothing' === $key ? 'open' : ''; ?>><summary><strong><?php echo esc_html($schema['label']); ?></strong><code><?php echo esc_html($key); ?></code></summary>
                                <?php if (!empty($schema['condition_options'])) : ?><ul><?php foreach ($schema['condition_options'] as $value => $label) : ?><li><code><?php echo esc_html($value); ?></code><span><?php echo esc_html($label); ?></span></li><?php endforeach; ?></ul><?php else : ?><p>Поле состояния не используется.</p><?php endif; ?>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <section class="p24msc-panel p24msc-safe">
                <div><span class="dashicons dashicons-shield"></span></div>
                <div><h2>Безопасный полевой мост</h2><p>Патч не создаёт таблиц, не переносит платежи и не меняет авторизацию. Несовместимые характеристики очищаются только после явного подтверждения смены рубрики в редакторе.</p></div>
            </section>
        </div>
        <?php
    }
}

P24MSC_System_Contract::instance();
