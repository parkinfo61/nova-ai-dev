<?php

defined('ABSPATH') || exit;

final class P24MSC_System_Registry {
    private static $instance = null;
    private $post_type = '';
    private $taxonomy = '';

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'discover_runtime'), 99);
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('wp_head', array($this, 'render_route_marker'), 1);
        add_action('admin_head', array($this, 'render_route_marker'), 1);
        add_filter('p24m_system_map', array($this, 'merge_existing_system_map'), 5);
    }

    public function discover_runtime() {
        $this->post_type = $this->detect_listing_post_type();
        $this->taxonomy = $this->detect_listing_taxonomy($this->post_type);
    }

    public function register_rest_routes() {
        register_rest_route('p24-market/v1', '/system-map', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'rest_system_map'),
            'permission_callback' => array($this, 'can_read_system_map'),
        ));
    }

    public function can_read_system_map() {
        return current_user_can('manage_options')
            || current_user_can('edit_others_posts')
            || current_user_can('p24m_manage_marketplace')
            || current_user_can('p24m_moderate_listings');
    }

    public function rest_system_map(WP_REST_Request $request) {
        return rest_ensure_response($this->get_system_map());
    }

    public function get_system_map() {
        if (!$this->post_type) {
            $this->discover_runtime();
        }

        $map = array(
            'schema' => 'p24-market-system-map/v1',
            'generated_at' => current_time('c'),
            'project' => array(
                'code' => 'P24-MARKET',
                'name' => 'Шахты24 Маркет',
                'core_version' => defined('P24M_VERSION') ? (string) P24M_VERSION : null,
                'bridge_version' => P24MSC_VERSION,
                'wordpress_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'home_url' => home_url('/'),
            ),
            'runtime' => array(
                'listing_post_type' => $this->post_type,
                'listing_taxonomy' => $this->taxonomy,
                'multisite' => is_multisite(),
                'rest_url' => rest_url('p24-market/v1/system-map'),
            ),
            'entities' => $this->get_entities(),
            'routes' => $this->get_routes(),
            'actions' => $this->get_actions(),
            'roles' => $this->get_roles_contract(),
            'category_schemas' => class_exists('P24MSC_Category_Semantics')
                ? P24MSC_Category_Semantics::instance()->get_schemas()
                : array(),
            'test_objects' => $this->get_test_objects(),
            'current_request' => array(
                'route_key' => $this->detect_current_route_key(),
                'object_id' => $this->detect_current_object_id(),
                'authenticated' => is_user_logged_in(),
                'user_role' => $this->get_current_role(),
            ),
        );

        return apply_filters('p24msc_system_map', $map);
    }

    public function merge_existing_system_map($map) {
        if (!is_array($map)) {
            $map = array();
        }
        return array_replace_recursive($map, $this->get_system_map());
    }

    public function get_entities() {
        $entities = array(
            'listing' => array(
                'label' => 'Объявление',
                'storage' => $this->post_type ?: 'p24_listing',
                'owner' => 'post_author',
                'statuses' => array('draft', 'pending', 'publish', 'trash'),
                'relations' => array('seller', 'category', 'dialog', 'publication', 'promotion', 'payment'),
            ),
            'seller' => array(
                'label' => 'Продавец',
                'storage' => 'wp_user',
                'owner' => 'self',
                'relations' => array('listing', 'dialog', 'payment'),
            ),
            'buyer' => array(
                'label' => 'Покупатель',
                'storage' => 'wp_user_or_guest',
                'owner' => 'self',
                'relations' => array('dialog', 'favorite', 'listing'),
            ),
            'category' => array(
                'label' => 'Рубрика',
                'storage' => $this->taxonomy ?: 'taxonomy',
                'owner' => 'system',
                'relations' => array('listing', 'category_schema'),
            ),
            'dialog' => array(
                'label' => 'Диалог',
                'storage' => 'p24_marketplace_messages',
                'owner' => 'participants',
                'relations' => array('listing', 'buyer', 'seller'),
            ),
            'publication' => array(
                'label' => 'Публикация',
                'storage' => 'listing_meta_or_table',
                'owner' => 'listing',
                'relations' => array('listing', 'channel'),
            ),
            'promotion' => array(
                'label' => 'Продвижение',
                'storage' => 'listing_meta_or_table',
                'owner' => 'listing',
                'relations' => array('listing', 'payment'),
            ),
            'payment' => array(
                'label' => 'Платёж',
                'storage' => 'payment_provider_or_table',
                'owner' => 'payer',
                'relations' => array('listing', 'promotion', 'seller'),
            ),
            'print_issue' => array(
                'label' => 'Выпуск Помогайки',
                'storage' => 'external_or_custom_entity',
                'owner' => 'publisher',
                'relations' => array('listing', 'coupon'),
            ),
        );
        return apply_filters('p24msc_entities', $entities);
    }

    public function get_actions() {
        $actions = array(
            'listing.create' => array('label' => 'Подать объявление', 'roles' => array('seller', 'manager', 'administrator')),
            'listing.edit' => array('label' => 'Редактировать объявление', 'roles' => array('owner', 'manager', 'administrator')),
            'listing.submit' => array('label' => 'Отправить на модерацию', 'roles' => array('owner', 'manager', 'administrator')),
            'listing.approve' => array('label' => 'Одобрить объявление', 'roles' => array('manager', 'administrator')),
            'listing.publish' => array('label' => 'Опубликовать объявление', 'roles' => array('manager', 'administrator')),
            'listing.promote' => array('label' => 'Запустить продвижение', 'roles' => array('owner', 'manager', 'administrator')),
            'dialog.reply' => array('label' => 'Ответить клиенту', 'roles' => array('buyer', 'seller', 'manager', 'administrator')),
            'listing.result' => array('label' => 'Указать результат', 'roles' => array('owner', 'manager', 'administrator')),
        );
        return apply_filters('p24msc_actions', $actions);
    }

    public function get_roles_contract() {
        return apply_filters('p24msc_roles_contract', array(
            'guest' => array('label' => 'Гость', 'capabilities' => array('listing.view', 'category.view', 'search')),
            'seller' => array('label' => 'Продавец', 'capabilities' => array('listing.create', 'own_listing.edit', 'dialog.reply', 'listing.promote')),
            'manager' => array('label' => 'Менеджер', 'capabilities' => array('listing.moderate', 'dialog.manage', 'listing.publish', 'promotion.manage')),
            'administrator' => array('label' => 'Администратор', 'capabilities' => array('*')),
        ));
    }

    public function get_routes() {
        $objects = $this->select_test_objects();
        $listing_id = isset($objects['published_listing']['id']) ? (int) $objects['published_listing']['id'] : 0;
        $term_id = isset($objects['category']['id']) ? (int) $objects['category']['id'] : 0;
        $cabinet = $this->find_page_url(array('kabinet', 'cabinet'), home_url('/kabinet/'));
        $catalog = $this->find_page_url(array('market', 'obyavleniya', 'catalog'), home_url('/'));
        $submit = $this->find_page_url(array('podat-obyavlenie', 'add-listing', 'submit-listing'), add_query_arg('tab', 'add', $cabinet));
        $listing_url = $listing_id ? get_permalink($listing_id) : $catalog;
        $category_url = $term_id && $this->taxonomy ? get_term_link($term_id, $this->taxonomy) : $catalog;
        if (is_wp_error($category_url)) {
            $category_url = $catalog;
        }

        $routes = array(
            'home' => $this->route('Главная витрина', home_url('/'), 'guest', 'p24m-home', 'public'),
            'catalog.search' => $this->route('Каталог и поиск', $catalog, 'guest', 'p24m-catalog', 'public'),
            'category.view' => $this->route('Страница рубрики', $category_url, 'guest', 'p24m-category', 'public', array('term_id' => $term_id)),
            'listing.view' => $this->route('Карточка объявления', $listing_url, 'guest', 'p24m-listing', 'public', array('listing_id' => $listing_id)),
            'listing.submit' => $this->route('Подача объявления', $submit, 'seller', 'p24m-submit', 'cabinet'),
            'seller.listings' => $this->route('Мои объявления', add_query_arg('tab', 'listings', $cabinet), 'seller', 'p24m-cabinet-listings', 'cabinet'),
            'listing.edit' => $this->route('Редактор объявления', add_query_arg(array('tab' => 'edit', 'listing_id' => $listing_id), $cabinet), 'seller', 'p24m-listing-edit', 'cabinet', array('listing_id' => $listing_id)),
            'seller.messages' => $this->route('Сообщения', add_query_arg('tab', 'messages', $cabinet), 'seller', 'p24m-messages', 'cabinet'),
            'seller.promotion' => $this->route('Продвижение и статистика', add_query_arg('tab', 'promotion', $cabinet), 'seller', 'p24m-promotion', 'cabinet'),
            'manager.dashboard' => $this->route('Рабочий стол менеджера', admin_url('admin.php?page=p24-marketplace-manager'), 'manager', 'p24m-manager', 'admin'),
            'manager.moderation' => $this->route('Модерация', admin_url('admin.php?page=p24-marketplace-moderation'), 'manager', 'p24m-moderation', 'admin'),
        );

        return apply_filters('p24msc_routes', $routes, $objects);
    }

    private function route($label, $url, $role, $marker, $surface, $object = array()) {
        return array(
            'label' => $label,
            'url' => esc_url_raw((string) $url),
            'expected_role' => $role,
            'marker' => $marker,
            'surface' => $surface,
            'object' => $object,
            'enabled' => !empty($url),
        );
    }

    public function get_test_objects() {
        return apply_filters('p24msc_test_objects', $this->select_test_objects());
    }

    private function select_test_objects() {
        $result = array();
        if (!$this->post_type) {
            return $result;
        }

        $result['published_listing'] = $this->query_listing_object(array('post_status' => 'publish', 'meta_query' => array(array('key' => '_thumbnail_id', 'compare' => 'EXISTS'))));
        if (empty($result['published_listing'])) {
            $result['published_listing'] = $this->query_listing_object(array('post_status' => 'publish'));
        }
        $result['draft_listing'] = $this->query_listing_object(array('post_status' => array('draft', 'pending')));
        $result['listing_without_photo'] = $this->query_listing_object(array('post_status' => array('publish', 'pending', 'draft'), 'meta_query' => array(array('key' => '_thumbnail_id', 'compare' => 'NOT EXISTS'))));

        if ($this->taxonomy) {
            $terms = get_terms(array('taxonomy' => $this->taxonomy, 'hide_empty' => true, 'number' => 1));
            if (!is_wp_error($terms) && !empty($terms)) {
                $term = reset($terms);
                $result['category'] = array(
                    'id' => (int) $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'url' => get_term_link($term),
                );
            }
        }

        return array_filter($result);
    }

    private function query_listing_object($extra_args) {
        $args = array_merge(array(
            'post_type' => $this->post_type,
            'posts_per_page' => 1,
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => false,
        ), $extra_args);
        $ids = get_posts($args);
        if (!$ids) {
            return array();
        }
        $id = (int) reset($ids);
        return array(
            'id' => $id,
            'title' => get_the_title($id),
            'status' => get_post_status($id),
            'url' => get_permalink($id),
            'edit_url' => get_edit_post_link($id, 'raw'),
        );
    }

    public function render_route_marker() {
        $key = $this->detect_current_route_key();
        $object_id = $this->detect_current_object_id();
        echo "\n<meta name=\"p24m-system-route\" content=\"" . esc_attr($key) . "\">\n";
        echo '<meta name="p24m-system-marker" content="' . esc_attr($this->route_key_to_marker($key)) . '">' . "\n";
        if ($object_id) {
            echo '<meta name="p24m-system-object-id" content="' . esc_attr((string) $object_id) . '">' . "\n";
        }
    }

    public function detect_current_route_key() {
        if (is_admin()) {
            $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
            if ('p24-marketplace-manager' === $page) {
                return 'manager.dashboard';
            }
            if ('p24-marketplace-moderation' === $page) {
                return 'manager.moderation';
            }
            if ('p24-marketplace-listing-integrity' === $page || 'p24-marketplace-system-map' === $page) {
                return 'developer.system-map';
            }
            return 'wp-admin';
        }

        if ($this->post_type && is_singular($this->post_type)) {
            return 'listing.view';
        }
        if ($this->taxonomy && is_tax($this->taxonomy)) {
            return 'category.view';
        }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if ($tab) {
            $tabs = array(
                'listings' => 'seller.listings',
                'edit' => 'listing.edit',
                'add' => 'listing.submit',
                'messages' => 'seller.messages',
                'promotion' => 'seller.promotion',
                'statistics' => 'seller.promotion',
            );
            if (isset($tabs[$tab])) {
                return $tabs[$tab];
            }
        }

        $path = trim((string) wp_parse_url(home_url(add_query_arg(array())), PHP_URL_PATH), '/');
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if (false !== strpos($request_uri, '/kabinet')) {
            return 'seller.dashboard';
        }
        if (is_front_page() || is_home() || '' === $path) {
            return 'home';
        }
        return 'catalog.search';
    }

    private function route_key_to_marker($key) {
        $map = array(
            'home' => 'p24m-home',
            'catalog.search' => 'p24m-catalog',
            'category.view' => 'p24m-category',
            'listing.view' => 'p24m-listing',
            'listing.submit' => 'p24m-submit',
            'seller.listings' => 'p24m-cabinet-listings',
            'listing.edit' => 'p24m-listing-edit',
            'seller.messages' => 'p24m-messages',
            'seller.promotion' => 'p24m-promotion',
            'manager.dashboard' => 'p24m-manager',
            'manager.moderation' => 'p24m-moderation',
            'developer.system-map' => 'p24m-system-map',
        );
        return isset($map[$key]) ? $map[$key] : 'p24m-generic';
    }

    private function detect_current_object_id() {
        if (isset($_GET['listing_id'])) {
            return absint($_GET['listing_id']);
        }
        if ($this->post_type && is_singular($this->post_type)) {
            return get_queried_object_id();
        }
        return 0;
    }

    private function get_current_role() {
        if (!is_user_logged_in()) {
            return 'guest';
        }
        $user = wp_get_current_user();
        if (user_can($user, 'manage_options')) {
            return 'administrator';
        }
        if (user_can($user, 'edit_others_posts') || user_can($user, 'p24m_moderate_listings')) {
            return 'manager';
        }
        return 'seller';
    }

    private function detect_listing_post_type() {
        $candidates = array('p24_listing', 'p24m_listing', 'listing', 'classified');
        foreach ($candidates as $candidate) {
            if (post_type_exists($candidate)) {
                return $candidate;
            }
        }
        foreach (get_post_types(array('_builtin' => false), 'objects') as $object) {
            $haystack = strtolower($object->name . ' ' . $object->label);
            if (false !== strpos($haystack, 'listing') || false !== strpos($haystack, 'объяв')) {
                return $object->name;
            }
        }
        return '';
    }

    private function detect_listing_taxonomy($post_type) {
        $candidates = array('p24_listing_category', 'p24m_listing_category', 'p24_category', 'listing_category');
        foreach ($candidates as $candidate) {
            if (taxonomy_exists($candidate)) {
                return $candidate;
            }
        }
        if ($post_type) {
            foreach (get_object_taxonomies($post_type, 'objects') as $taxonomy) {
                if (!$taxonomy->hierarchical) {
                    continue;
                }
                $haystack = strtolower($taxonomy->name . ' ' . $taxonomy->label);
                if (false !== strpos($haystack, 'categor') || false !== strpos($haystack, 'рубр') || false !== strpos($haystack, 'катег')) {
                    return $taxonomy->name;
                }
            }
        }
        return '';
    }

    private function find_page_url($slugs, $fallback) {
        foreach ((array) $slugs as $slug) {
            $page = get_page_by_path($slug);
            if ($page instanceof WP_Post && 'publish' === $page->post_status) {
                return get_permalink($page);
            }
        }
        return $fallback;
    }
}
