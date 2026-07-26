<?php

defined('ABSPATH') || exit;

final class P24MSC_Admin {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'register_menu'), 99);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    public function register_menu() {
        $capability = $this->capability();
        $parent = $this->detect_parent_slug();
        if ($parent) {
            add_submenu_page(
                $parent,
                'Карта системы',
                'Карта системы',
                $capability,
                'p24-marketplace-system-map',
                array($this, 'render_page')
            );
        } else {
            add_management_page(
                'P24 Marketplace — Карта системы',
                'P24 Карта системы',
                $capability,
                'p24-marketplace-system-map',
                array($this, 'render_page')
            );
        }
    }

    public function enqueue_styles($hook) {
        if (false === strpos((string) $hook, 'p24-marketplace-system-map')) {
            return;
        }
        wp_register_style('p24msc-admin', false, array(), P24MSC_VERSION);
        wp_enqueue_style('p24msc-admin');
        wp_add_inline_style('p24msc-admin', $this->styles());
    }

    public function render_page() {
        if (!current_user_can($this->capability())) {
            wp_die(esc_html__('У вас нет доступа к карте системы.', 'p24-market-system-contract'));
        }
        $map = P24MSC_System_Registry::instance()->get_system_map();
        $routes = isset($map['routes']) ? (array) $map['routes'] : array();
        $entities = isset($map['entities']) ? (array) $map['entities'] : array();
        $objects = isset($map['test_objects']) ? (array) $map['test_objects'] : array();
        $schemas = isset($map['category_schemas']) ? (array) $map['category_schemas'] : array();
        ?>
        <div class="wrap p24msc-wrap">
            <section class="p24msc-hero">
                <div>
                    <span class="p24msc-kicker">P24 MARKETPLACE OS · SYSTEM CONTRACT</span>
                    <h1>Карта системы</h1>
                    <p>Единый паспорт сущностей, маршрутов, ролей, тестовых объектов и семантики рубрик.</p>
                </div>
                <span class="p24msc-version">v<?php echo esc_html(P24MSC_VERSION); ?></span>
            </section>

            <div class="p24msc-stats">
                <article><strong><?php echo esc_html((string) count($entities)); ?></strong><span>сущностей</span></article>
                <article><strong><?php echo esc_html((string) count($routes)); ?></strong><span>маршрутов</span></article>
                <article><strong><?php echo esc_html((string) count($objects)); ?></strong><span>тестовых объектов</span></article>
                <article><strong><?php echo esc_html((string) count($schemas)); ?></strong><span>схем рубрик</span></article>
            </div>

            <section class="p24msc-panel">
                <div class="p24msc-panel-head">
                    <div><span class="p24msc-kicker">МАШИННЫЙ ПАСПОРТ</span><h2>Подключение инструментов NOVA</h2></div>
                    <a class="button button-primary" href="<?php echo esc_url(rest_url('p24-market/v1/system-map')); ?>" target="_blank" rel="noopener">Открыть JSON</a>
                </div>
                <p><code><?php echo esc_html(rest_url('p24-market/v1/system-map')); ?></code></p>
                <p class="description">Доступ защищён правами WordPress. Аудитор получает маршруты и контрольные объекты автоматически — без ручного поиска URL.</p>
            </section>

            <section class="p24msc-panel">
                <div class="p24msc-panel-head"><div><span class="p24msc-kicker">МАРШРУТЫ</span><h2>Что система умеет открыть и проверить</h2></div></div>
                <div class="p24msc-table-wrap">
                    <table class="widefat striped p24msc-table">
                        <thead><tr><th>Код</th><th>Назначение</th><th>Роль</th><th>Контроль</th><th>Действие</th></tr></thead>
                        <tbody>
                        <?php foreach ($routes as $key => $route) : ?>
                            <tr>
                                <td><code><?php echo esc_html($key); ?></code></td>
                                <td><?php echo esc_html(isset($route['label']) ? $route['label'] : ''); ?></td>
                                <td><?php echo esc_html(isset($route['expected_role']) ? $route['expected_role'] : ''); ?></td>
                                <td><code><?php echo esc_html(isset($route['marker']) ? $route['marker'] : ''); ?></code></td>
                                <td><?php if (!empty($route['url'])) : ?><a href="<?php echo esc_url($route['url']); ?>" target="_blank" rel="noopener">Открыть ↗</a><?php else : ?>—<?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="p24msc-grid">
                <section class="p24msc-panel">
                    <div class="p24msc-panel-head"><div><span class="p24msc-kicker">СУЩНОСТИ</span><h2>Владельцы данных</h2></div></div>
                    <?php foreach ($entities as $key => $entity) : ?>
                        <article class="p24msc-row">
                            <div><strong><?php echo esc_html(isset($entity['label']) ? $entity['label'] : $key); ?></strong><code><?php echo esc_html($key); ?></code></div>
                            <span><?php echo esc_html(isset($entity['storage']) ? $entity['storage'] : ''); ?></span>
                        </article>
                    <?php endforeach; ?>
                </section>

                <section class="p24msc-panel">
                    <div class="p24msc-panel-head"><div><span class="p24msc-kicker">АВТОВЫБОР</span><h2>Тестовые объекты</h2></div></div>
                    <?php if (!$objects) : ?><p>Подходящие объявления пока не найдены.</p><?php endif; ?>
                    <?php foreach ($objects as $key => $object) : ?>
                        <article class="p24msc-row">
                            <div><strong><?php echo esc_html(isset($object['title']) ? $object['title'] : (isset($object['name']) ? $object['name'] : $key)); ?></strong><code><?php echo esc_html($key); ?></code></div>
                            <?php if (!empty($object['url']) && !is_wp_error($object['url'])) : ?><a href="<?php echo esc_url($object['url']); ?>" target="_blank" rel="noopener">Проверить ↗</a><?php else : ?><span>#<?php echo esc_html(isset($object['id']) ? (string) $object['id'] : ''); ?></span><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </section>
            </div>

            <section class="p24msc-panel">
                <div class="p24msc-panel-head"><div><span class="p24msc-kicker">СЕМАНТИКА</span><h2>Состояния по типам рубрик</h2></div></div>
                <div class="p24msc-schema-grid">
                    <?php foreach ($schemas as $key => $schema) : ?>
                        <article>
                            <h3><?php echo esc_html(isset($schema['label']) ? $schema['label'] : $key); ?></h3>
                            <?php if (empty($schema['condition_enabled'])) : ?>
                                <p>Поле состояния не используется.</p>
                            <?php else : ?>
                                <ul><?php foreach ((array) $schema['conditions'] as $label) : ?><li><?php echo esc_html($label); ?></li><?php endforeach; ?></ul>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
        <?php
    }

    private function capability() {
        if (current_user_can('manage_options')) {
            return 'manage_options';
        }
        if (current_user_can('edit_others_posts')) {
            return 'edit_others_posts';
        }
        return 'manage_options';
    }

    private function detect_parent_slug() {
        global $menu, $submenu;
        $candidates = array('p24-marketplace', 'p24-marketplace-manager', 'edit.php?post_type=p24_listing');
        foreach ($candidates as $candidate) {
            if (isset($submenu[$candidate])) {
                return $candidate;
            }
        }
        if (is_array($menu)) {
            foreach ($menu as $item) {
                $label = isset($item[0]) ? wp_strip_all_tags((string) $item[0]) : '';
                $slug = isset($item[2]) ? (string) $item[2] : '';
                if (false !== mb_stripos($label, 'Шахты24 Маркет') || false !== mb_stripos($label, 'P24 Marketplace')) {
                    return $slug;
                }
            }
        }
        return '';
    }

    private function styles() {
        return '.p24msc-wrap{max-width:1240px}.p24msc-hero{display:flex;justify-content:space-between;gap:24px;align-items:center;margin:18px 0;padding:30px 34px;border-radius:24px;background:linear-gradient(120deg,#092d75,#2259c6 64%,#5b2d91);color:#fff;box-shadow:0 18px 45px rgba(19,46,91,.18)}.p24msc-hero h1{margin:6px 0 8px;font-size:36px;color:#fff}.p24msc-hero p{margin:0;color:#dce8ff;font-size:15px}.p24msc-kicker{font-size:11px;font-weight:800;letter-spacing:.13em;color:#1761d3}.p24msc-hero .p24msc-kicker{color:#ffd637}.p24msc-version{padding:10px 16px;border:1px solid rgba(255,255,255,.3);border-radius:999px;font-weight:800}.p24msc-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:16px 0}.p24msc-stats article,.p24msc-panel{border:1px solid #dce4ef;border-radius:20px;background:#fff;box-shadow:0 12px 30px rgba(21,43,74,.07)}.p24msc-stats article{padding:20px}.p24msc-stats strong{display:block;font-size:30px;color:#10213a}.p24msc-stats span{color:#6b7789}.p24msc-panel{padding:22px;margin:16px 0}.p24msc-panel-head{display:flex;justify-content:space-between;align-items:center;gap:16px}.p24msc-panel h2{margin:4px 0 10px;font-size:23px}.p24msc-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.p24msc-row{display:flex;justify-content:space-between;gap:16px;align-items:center;padding:13px 0;border-top:1px solid #edf1f5}.p24msc-row:first-of-type{border-top:0}.p24msc-row strong,.p24msc-row code{display:block}.p24msc-row code{margin-top:4px;color:#667085}.p24msc-table-wrap{overflow:auto}.p24msc-table td{vertical-align:middle}.p24msc-schema-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.p24msc-schema-grid article{padding:16px;border:1px solid #e1e8f1;border-radius:16px;background:#f8faff}.p24msc-schema-grid h3{margin:0 0 10px}.p24msc-schema-grid ul{margin:0;padding-left:20px}.p24msc-schema-grid li{margin:5px 0}@media(max-width:900px){.p24msc-stats,.p24msc-schema-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.p24msc-grid{grid-template-columns:1fr}}@media(max-width:600px){.p24msc-hero{align-items:flex-start;flex-direction:column;padding:24px}.p24msc-hero h1{font-size:29px}.p24msc-stats,.p24msc-schema-grid{grid-template-columns:1fr}.p24msc-panel-head{align-items:flex-start;flex-direction:column}}';
    }
}
