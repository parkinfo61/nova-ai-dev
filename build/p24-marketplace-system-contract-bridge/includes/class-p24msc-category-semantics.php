<?php

defined('ABSPATH') || exit;

final class P24MSC_Category_Semantics {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_editor_assets'), 30);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_editor_assets'), 30);
        add_action('save_post', array($this, 'synchronize_listing_family'), 25, 3);
        add_filter('the_content', array($this, 'clean_public_description'), 25);
        add_filter('get_the_excerpt', array($this, 'clean_public_description'), 25);
        add_filter('p24m_listing_description', array($this, 'clean_public_description'), 25, 2);
        add_filter('p24m_condition_label', array($this, 'filter_condition_label'), 20, 3);
        add_action('admin_notices', array($this, 'render_pending_notice'));
    }

    public function get_schemas() {
        $schemas = array(
            'vehicle' => array(
                'label' => 'Транспорт',
                'match' => array('авто', 'автомоб', 'машин', 'транспорт', 'мото', 'грузов', 'спецтех'),
                'condition_enabled' => true,
                'conditions' => array(
                    'new' => 'Новое',
                    'excellent' => 'Отличное',
                    'good' => 'Хорошее',
                    'fair' => 'Удовлетворительное',
                    'needs_repair' => 'Требует ремонта',
                    'not_running' => 'Не на ходу',
                ),
                'required_fields' => array('make', 'model', 'year'),
                'meta_keys' => array(
                    '_p24m_make', '_p24m_brand', '_p24m_model', '_p24m_year', '_p24m_mileage',
                    '_p24m_body', '_p24m_transmission', '_p24m_fuel', '_p24m_engine',
                    '_p24m_drive', '_p24m_steering', '_p24m_vehicle_condition',
                ),
            ),
            'clothing' => array(
                'label' => 'Личные вещи и одежда',
                'match' => array('личн', 'одежд', 'обув', 'сумк', 'аксессуар', 'джинс', 'гардероб'),
                'condition_enabled' => true,
                'conditions' => array(
                    'new_with_tags' => 'Новое с биркой',
                    'new_without_tags' => 'Новое без бирки',
                    'excellent' => 'Отличное',
                    'good' => 'Хорошее',
                    'fair' => 'Удовлетворительное',
                    'has_defects' => 'Есть дефекты',
                ),
                'required_fields' => array('item_type', 'size'),
                'meta_keys' => array(
                    '_p24m_item_group', '_p24m_item_type', '_p24m_gender', '_p24m_size',
                    '_p24m_brand', '_p24m_season', '_p24m_color', '_p24m_material',
                    '_p24m_purchased_at', '_p24m_defect_note', '_p24m_item_condition',
                ),
            ),
            'realty' => array(
                'label' => 'Недвижимость',
                'match' => array('недвиж', 'квартир', 'дом', 'участ', 'комнат', 'гараж', 'коммерческ'),
                'condition_enabled' => true,
                'conditions' => array(
                    'new_build' => 'Новостройка',
                    'excellent' => 'Отличное',
                    'good' => 'Хорошее',
                    'needs_cosmetic_repair' => 'Требует косметического ремонта',
                    'needs_major_repair' => 'Требует капитального ремонта',
                ),
                'required_fields' => array('property_type'),
                'meta_keys' => array(
                    '_p24m_property_type', '_p24m_deal_type', '_p24m_rooms', '_p24m_area',
                    '_p24m_floor', '_p24m_floors_total', '_p24m_realty_condition',
                ),
            ),
            'service' => array(
                'label' => 'Услуги и работа',
                'match' => array('услуг', 'работ', 'ваканс', 'мастер', 'ремонт услуг', 'специалист'),
                'condition_enabled' => false,
                'conditions' => array(),
                'required_fields' => array('service_type'),
                'meta_keys' => array(
                    '_p24m_service_type', '_p24m_service_area', '_p24m_service_schedule',
                    '_p24m_service_price_type', '_p24m_experience',
                ),
            ),
            'generic' => array(
                'label' => 'Товар или предложение',
                'match' => array(),
                'condition_enabled' => true,
                'conditions' => array(
                    'new' => 'Новое',
                    'excellent' => 'Отличное',
                    'good' => 'Хорошее',
                    'fair' => 'Удовлетворительное',
                    'has_defects' => 'Есть дефекты',
                ),
                'required_fields' => array(),
                'meta_keys' => array(),
            ),
        );
        return apply_filters('p24msc_category_schemas', $schemas);
    }

    public function enqueue_editor_assets() {
        if (!$this->is_front_editor_request()) {
            return;
        }
        $this->enqueue_assets();
    }

    public function enqueue_admin_editor_assets($hook_suffix) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $is_listing_post = in_array($hook_suffix, array('post.php', 'post-new.php'), true)
            && isset($_GET['post'])
            && $this->is_listing_post(absint($_GET['post']));
        $is_market_editor = false !== strpos($page, 'p24-marketplace')
            && (false !== strpos($page, 'listing') || false !== strpos($page, 'moderation'));
        if (!$is_listing_post && !$is_market_editor) {
            return;
        }
        $this->enqueue_assets();
    }

    private function enqueue_assets() {
        wp_enqueue_script(
            'p24msc-category-semantics',
            P24MSC_URL . 'assets/p24msc-category-semantics.js',
            array(),
            P24MSC_VERSION,
            true
        );
        wp_localize_script('p24msc-category-semantics', 'P24MSCCategorySemantics', array(
            'schemas' => $this->get_schemas(),
            'confirmText' => 'Вы меняете тип объявления. Общие данные, цена, фотографии, контакты и местоположение сохранятся. Несовместимые характеристики прежней рубрики будут очищены после сохранения. Продолжить?',
            'conditionLabel' => 'Состояние',
            'disabledConditionText' => 'Для этой рубрики состояние товара не используется.',
            'confirmedField' => 'p24m_confirm_category_change',
            'familyField' => 'p24m_category_family_client',
        ));
    }

    public function synchronize_listing_family($post_id, $post, $update) {
        if (!$post instanceof WP_Post || !$this->is_listing_post($post_id)) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $family = $this->detect_post_family($post_id);
        $previous = (string) get_post_meta($post_id, '_p24m_category_family', true);
        $confirmed = isset($_POST['p24m_confirm_category_change'])
            && '1' === sanitize_text_field(wp_unslash($_POST['p24m_confirm_category_change']));

        if ('' === $previous) {
            update_post_meta($post_id, '_p24m_category_family', $family);
            update_post_meta($post_id, '_p24m_category_schema_version', P24MSC_VERSION);
            $this->normalize_condition($post_id, $family);
            return;
        }

        if ($previous !== $family) {
            if ($confirmed) {
                $this->clear_incompatible_meta($post_id, $previous, $family);
                delete_post_meta($post_id, '_p24m_category_change_pending');
                update_post_meta($post_id, '_p24m_category_family', $family);
                update_post_meta($post_id, '_p24m_category_schema_version', P24MSC_VERSION);
                update_post_meta($post_id, '_p24m_last_reclassified_at', current_time('mysql'));
                update_post_meta($post_id, '_p24m_last_reclassified_by', get_current_user_id());
                do_action('p24msc_listing_reclassified', $post_id, $previous, $family);
            } else {
                update_post_meta($post_id, '_p24m_category_change_pending', array(
                    'from' => $previous,
                    'to' => $family,
                    'detected_at' => current_time('mysql'),
                ));
            }
        }

        $this->normalize_condition($post_id, $family);
    }

    private function clear_incompatible_meta($post_id, $old_family, $new_family) {
        $schemas = $this->get_schemas();
        $old_keys = isset($schemas[$old_family]['meta_keys']) ? (array) $schemas[$old_family]['meta_keys'] : array();
        $new_keys = isset($schemas[$new_family]['meta_keys']) ? (array) $schemas[$new_family]['meta_keys'] : array();
        $shared = array('_p24m_brand');
        foreach (array_diff($old_keys, $new_keys, $shared) as $meta_key) {
            delete_post_meta($post_id, $meta_key);
        }
    }

    private function normalize_condition($post_id, $family) {
        $condition_keys = array(
            '_p24m_condition', '_p24m_state', '_p24m_item_condition',
            '_p24m_vehicle_condition', '_p24m_realty_condition', 'condition',
        );
        $schemas = $this->get_schemas();
        $schema = isset($schemas[$family]) ? $schemas[$family] : $schemas['generic'];

        if (empty($schema['condition_enabled'])) {
            foreach ($condition_keys as $key) {
                delete_post_meta($post_id, $key);
            }
            return;
        }

        foreach ($condition_keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            if ('' === (string) $value) {
                continue;
            }
            $normalized = $this->normalize_condition_value($value, $family);
            if ($normalized !== $value) {
                update_post_meta($post_id, $key, $normalized);
            }
        }
    }

    private function normalize_condition_value($value, $family) {
        $raw = strtolower(trim(wp_strip_all_tags((string) $value)));
        $repair_values = array('требует ремонта', 'repair', 'needs_repair', 'requires_repair', 'repair_needed');
        if ('clothing' === $family && in_array($raw, $repair_values, true)) {
            return 'has_defects';
        }
        if ('generic' === $family && in_array($raw, $repair_values, true)) {
            return 'has_defects';
        }
        return $value;
    }

    public function filter_condition_label($label, $condition = '', $post_id = 0) {
        $post_id = $post_id ? absint($post_id) : get_the_ID();
        $family = $post_id ? $this->detect_post_family($post_id) : 'generic';
        $normalized = $this->normalize_condition_value($condition ?: $label, $family);
        $schemas = $this->get_schemas();
        if (isset($schemas[$family]['conditions'][$normalized])) {
            return $schemas[$family]['conditions'][$normalized];
        }
        if ('has_defects' === $normalized) {
            return 'Есть дефекты';
        }
        return $label;
    }

    public function clean_public_description($content, $post_id = 0) {
        $post_id = $post_id ? absint($post_id) : get_the_ID();
        if (!$post_id || !$this->is_listing_post($post_id)) {
            return $content;
        }
        $family = $this->detect_post_family($post_id);
        $clean = (string) $content;
        $clean = preg_replace('/(?:^|[•·|]\s*)Главное\s*(?:[•·|]|$)/ui', ' • ', $clean);
        $clean = preg_replace('/\s*•\s*•\s*/u', ' • ', $clean);
        if ('clothing' === $family || 'generic' === $family) {
            $clean = preg_replace('/Состояние\s*:\s*Требует ремонта/ui', 'Состояние: Есть дефекты', $clean);
        }
        $clean = trim((string) $clean, " \t\n\r\0\x0B•·|");
        return apply_filters('p24msc_clean_public_description', $clean, $post_id, $family);
    }

    public function render_pending_notice() {
        if (!current_user_can('edit_posts') || empty($_GET['post'])) {
            return;
        }
        $post_id = absint($_GET['post']);
        if (!$post_id || !$this->is_listing_post($post_id)) {
            return;
        }
        $pending = get_post_meta($post_id, '_p24m_category_change_pending', true);
        if (!is_array($pending) || empty($pending['from']) || empty($pending['to'])) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>Смена рубрики требует подтверждения.</strong> Обнаружен переход «' . esc_html($pending['from']) . '» → «' . esc_html($pending['to']) . '». Откройте редактор Маркета и подтвердите смену, чтобы безопасно очистить несовместимые характеристики.</p></div>';
    }

    public function detect_post_family($post_id) {
        $taxonomy = $this->detect_taxonomy($post_id);
        if ($taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy);
            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $term) {
                    $family = $this->detect_family_from_text($term->slug . ' ' . $term->name);
                    if ('generic' !== $family) {
                        return $family;
                    }
                }
            }
        }
        $saved = (string) get_post_meta($post_id, '_p24m_category_family', true);
        return $saved ?: 'generic';
    }

    public function detect_family_from_text($text) {
        $text = strtolower(wp_strip_all_tags((string) $text));
        foreach ($this->get_schemas() as $family => $schema) {
            if ('generic' === $family) {
                continue;
            }
            foreach ((array) $schema['match'] as $needle) {
                if ('' !== $needle && false !== strpos($text, strtolower($needle))) {
                    return $family;
                }
            }
        }
        return 'generic';
    }

    private function detect_taxonomy($post_id) {
        $post_type = get_post_type($post_id);
        $candidates = array('p24_listing_category', 'p24m_listing_category', 'p24_category', 'listing_category');
        foreach ($candidates as $candidate) {
            if (taxonomy_exists($candidate) && is_object_in_taxonomy($post_type, $candidate)) {
                return $candidate;
            }
        }
        foreach (get_object_taxonomies($post_type, 'objects') as $taxonomy) {
            if ($taxonomy->hierarchical) {
                return $taxonomy->name;
            }
        }
        return '';
    }

    private function is_front_editor_request() {
        if (is_admin()) {
            return false;
        }
        $request_uri = isset($_SERVER['REQUEST_URI']) ? strtolower((string) wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if (false === strpos($request_uri, '/kabinet') && false === strpos($request_uri, 'listing_id=')) {
            return false;
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        return in_array($tab, array('edit', 'add', 'submit'), true) || false !== strpos($request_uri, 'listing_id=');
    }

    private function is_listing_post($post_id) {
        $post_type = get_post_type($post_id);
        if (!$post_type) {
            return false;
        }
        if (in_array($post_type, array('p24_listing', 'p24m_listing', 'listing', 'classified'), true)) {
            return true;
        }
        $object = get_post_type_object($post_type);
        $haystack = strtolower($post_type . ' ' . ($object ? $object->label : ''));
        return false !== strpos($haystack, 'listing') || false !== strpos($haystack, 'объяв');
    }
}
